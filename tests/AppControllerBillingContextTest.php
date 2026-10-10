<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Auth\UserStore;
use app\library\Billing\BillingGate;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\SqliteFixture;

/**
 * Unit test konteks billing **baca-saja** di AppController (misi Frontend UI #8):
 *  - `billingContext()` — payload kartu limit create/confirm/template
 *    (member: plafon + estimasi + saldo + deposit minimum; admin: bebas),
 *  - `billingEstimateFor()` — estimasi tab "Sumber Daya" dari limit app,
 *  - audit statik view: kartu limit digerbang `canManage` (bukan `is_admin()`).
 *
 * Tanpa HTTP, tanpa Docker, tanpa menyentuh data runtime nyata — store
 * memakai berkas SQLite temp unik. `billingContext()` menerima `$user` eksplisit
 * supaya tidak perlu session.
 */
class AppControllerBillingContextTest extends TestCase
{
    private const MEMBER = ['id' => 'u2', 'username' => 'member', 'role' => 'member'];
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];

    private string $tmp;
    private CreditAccount $account;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-billing-ctx-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);

        $db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->account = new CreditAccount(
            new BillingStore($db),
            new UserStore($db)
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(AppController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new AppController(), ...$args);
    }

    /** @return array<string,mixed> */
    private function context(array $limits, ?array $user): array
    {
        return $this->invoke('billingContext', [
            $limits,
            $user,
            $this->account,
            new BillingGate($this->account),
        ]);
    }

    public function testMemberContextComputesCapsRatesEstimateBalanceAndRequired(): void
    {
        $this->account->deposit('u2', 5000.0, 'u1', 'seed');

        // limits: 1 core + 1024 MB = 100 + 20 = 120/jam.
        $ctx = $this->context(['web' => ['cpus' => 1.0, 'memory_mb' => 1024]], self::MEMBER);

        $this->assertTrue($ctx['enabled']);
        $this->assertTrue($ctx['member']);
        $this->assertTrue($ctx['canManage']);
        $this->assertSame(4.0, $ctx['caps']['cpus']);
        $this->assertSame(8192, $ctx['caps']['memory_mb']);
        $this->assertSame(100.0, $ctx['rates']['cpu']);
        $this->assertSame(20.0, $ctx['rates']['ram']);
        $this->assertSame(0.5, $ctx['defaults']['cpus']);
        $this->assertSame(512, $ctx['defaults']['memory_mb']);
        $this->assertSame(120.0, $ctx['estimate_hourly']);
        $this->assertSame(86400.0, $ctx['estimate_month']);
        $this->assertSame('120.00', $ctx['estimate_hourly_text']);
        $this->assertSame('86400.00', $ctx['estimate_month_text']);
        $this->assertSame(86400.0, $ctx['required']);
        $this->assertSame('86400.00', $ctx['required_text']);
        $this->assertSame(5000.0, $ctx['balance']);
        $this->assertSame('5000.00', $ctx['balance_text']);
        $this->assertFalse($ctx['sufficient']);
        $this->assertSame(30, $ctx['days']);
        $this->assertIsBool($ctx['topup_enabled']);
    }

    public function testMemberWithEnoughBalanceIsSufficient(): void
    {
        $this->account->deposit('u2', 90000.0, 'u1', 'seed');

        $ctx = $this->context(['web' => ['cpus' => 1.0, 'memory_mb' => 1024]], self::MEMBER);

        $this->assertTrue($ctx['sufficient']);
        $this->assertSame('90000.00', $ctx['balance_text']);
    }

    public function testAdminContextIsExemptFromCapsAndCost(): void
    {
        $ctx = $this->context(['web' => ['cpus' => 2.0, 'memory_mb' => 2048]], self::ADMIN);

        $this->assertTrue($ctx['enabled']);
        $this->assertFalse($ctx['member']);
        $this->assertTrue($ctx['canManage']);
        $this->assertSame(0.0, $ctx['required']);
        $this->assertSame(0.0, $ctx['balance']);
        $this->assertSame(0.0, $ctx['estimate_hourly']);
        $this->assertSame(0.0, $ctx['estimate_month']);
        $this->assertTrue($ctx['sufficient']);
    }

    public function testNullUserHasNoCanManage(): void
    {
        $ctx = $this->context(['web' => ['cpus' => 1.0, 'memory_mb' => 1024]], null);

        $this->assertFalse($ctx['canManage']);
        $this->assertFalse($ctx['member']);
        $this->assertTrue($ctx['sufficient']);
    }

    public function testEstimateForAppLimitsReadOnly(): void
    {
        $limits = ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]];

        // Argumen kedua = **penanggung biaya app** (pemilik), bukan penonton.
        $member = $this->invoke('billingEstimateFor', [$limits, self::MEMBER]);
        $this->assertTrue($member['enabled']);
        $this->assertTrue($member['applies']);
        $this->assertSame(120.0, $member['estimate_hourly']);
        $this->assertSame(86400.0, $member['estimate_month']);
        $this->assertSame('120.00', $member['hourly_text']);
        $this->assertSame('86400.00', $member['month_text']);
        $this->assertSame(['hourly' => '120.00', 'month' => '86400.00'], $member['format']);
        $this->assertSame(30, $member['days']);

        $admin = $this->invoke('billingEstimateFor', [$limits, self::ADMIN]);
        $this->assertFalse($admin['applies']);
        $this->assertSame(86400.0, $admin['estimate_month']); // angka tetap dihitung (read-only info)
    }

    /**
     * Kontrak C: `applies` di tab "Sumber Daya" mengikuti **pemilik app**
     * (penanggung biaya), bukan penonton. Pemanggil menyerahkan hasil
     * `billingPayerFor($app, current_user())` — app milik admin tetap bebas
     * kredit bagi penonton mana pun; app milik member tetap ditagih.
     */
    public function testEstimateAppliesFollowsAppOwnerNotViewer(): void
    {
        $users = new UserStore($this->tmp . '/rames.sqlite');
        $limits = ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]];

        $adminOwned = ['id' => 'a1', 'owner_id' => 'u1', 'limits' => $limits];
        $memberOwned = ['id' => 'a2', 'owner_id' => 'u2', 'limits' => $limits];

        // Penonton member melihat app milik admin ⇒ bebas tagihan ⇒ applies false.
        $payer = $this->invoke('billingPayerFor', [$adminOwned, self::MEMBER, $users]);
        $adminView = $this->invoke('billingEstimateFor', [$limits, $payer]);
        $this->assertFalse($adminView['applies'], 'app milik admin bebas ⇒ applies false untuk penonton mana pun');

        // Penonton admin melihat app milik member ⇒ tetap ditagih ⇒ applies true.
        $payer2 = $this->invoke('billingPayerFor', [$memberOwned, self::ADMIN, $users]);
        $memberView = $this->invoke('billingEstimateFor', [$limits, $payer2]);
        $this->assertTrue($memberView['applies'], 'app milik member ⇒ applies true apa pun penontonnya');
    }

    public function testEstimateForEmptyLimitsFallsBackToDefaultUnit(): void
    {
        // App tanpa `limits`: 0.5 core + 512 MB = 50 + 10 = 60/jam.
        $ctx = $this->invoke('billingEstimateFor', [[], self::MEMBER]);

        $this->assertSame(60.0, $ctx['estimate_hourly']);
        $this->assertSame('43200.00', $ctx['month_text']);
    }

    public function testCreateViewsGateLimitCardByCanManageNotIsAdmin(): void
    {
        foreach (['app/view/app/confirm.php', 'app/view/app/template.php'] as $rel) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/' . $rel);

            $this->assertStringNotContainsString(
                'if (is_admin() && $limitsServices !== [])',
                $src,
                $rel . ': kartu limit tidak boleh digerbang is_admin() langsung'
            );
            $this->assertStringContainsString(
                "\$limitsCtx['canManage']",
                $src,
                $rel . ': kartu limit wajib memakai flag canManage'
            );
            $this->assertStringContainsString('id="limit-estimate"', $src, $rel . ': panel estimasi hilang');
            $this->assertStringContainsString("\$billingCtx['member']", $src, $rel . ': panel estimasi wajib khusus member');
            // Input number + atribut `max=` diganti slider (type=range): plafon kini
            // menjadi batas atas **skala slider** — dikirim controller sebagai
            // `limitsScaleMax` dan dipakai view bersama skala stop `data-limit-stops`.
            // Assertion lama pada `max="` (indeks stop, bukan plafon) tak bermakna lagi.
            $this->assertStringContainsString(
                'limitsScaleMax',
                $src,
                $rel . ': batas atas skala slider dari controller hilang'
            );
            $this->assertStringContainsString(
                'data-limit-stops',
                $src,
                $rel . ': skala stop slider (data-limit-stops) hilang'
            );
        }
    }
}
