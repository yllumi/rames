<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Billing\BillingGate;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\InsufficientCredits;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\SqliteFixture;

/**
 * Test BillingGate — gerbang kredit (lewati admin/billing mati; blokir saldo
 * kurang) + bentuk respons InsufficientCredits. Store di berkas SQLite temp;
 * tanpa data runtime.
 */
class BillingGateTest extends TestCase
{
    private const RATES = ['cpu' => 100.0, 'ram' => 20.0];
    private const MEMBER = ['id' => 'u2', 'username' => 'member', 'role' => 'member'];
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];

    private string $tmp;
    private CreditAccount $account;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/billinggate_' . bin2hex(random_bytes(4));
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

    private function gate(): BillingGate
    {
        return new BillingGate($this->account, self::RATES);
    }

    public function testEnabledAndRequiredForEmptyLimits(): void
    {
        $gate = $this->gate();

        $this->assertTrue($gate->isEnabled());
        // Satu unit default = 60/jam → 30 hari = 43200.
        $this->assertSame(43200.0, $gate->requiredFor([]));
    }

    public function testAdminIsExempt(): void
    {
        $this->gate()->assertCanCreate([], self::ADMIN);
        $this->gate()->assertCanCreate(['web' => ['cpus' => 4.0, 'memory_mb' => 8192]], self::ADMIN);
        $this->assertTrue(true);
    }

    public function testIsExemptUsesResolvedRole(): void
    {
        $gate = $this->gate();

        $this->assertTrue($gate->isExempt(self::ADMIN));
        $this->assertFalse($gate->isExempt(self::MEMBER));
        $this->assertFalse($gate->isExempt(null), 'tanpa konteks user bukan pengecualian di sini');
    }

    /**
     * Admin legacy tanpa field `role` (bentuk data nyata: user pertama
     * `{id,username,password_hash,created_at}`) ⇒ `isExempt()` true lewat
     * resolusi `UserStore`, dan gate tidak melempar walau saldo 0.
     */
    public function testLegacyAdminWithoutRoleFieldIsExempt(): void
    {
        $db = $this->tmp . '/legacy.sqlite';
        SqliteFixture::users($db, [
            ['id' => 'legacy-admin', 'username' => 'admin', 'password_hash' => 'x', 'created_at' => ''],
            ['id' => 'legacy-member', 'username' => 'member', 'password_hash' => 'x', 'created_at' => ''],
        ]);

        $users = new UserStore($db);
        $legacyAdmin = ['id' => 'legacy-admin', 'username' => 'admin'];
        $gate = new BillingGate($this->account, self::RATES, $users);

        $this->assertTrue($gate->isExempt($legacyAdmin));
        $this->assertFalse($gate->isExempt(['id' => 'legacy-member', 'username' => 'member']));
        $gate->assertCanCreate([], $legacyAdmin);
        $this->assertTrue(true, 'admin legacy tanpa role tidak diblokir kredit');
    }

    public function testNullUserIsNotBlockedHere(): void
    {
        $this->gate()->assertCanCreate([], null);
        $this->assertTrue(true, 'autentikasi bukan tanggung jawab gerbang kredit');
    }

    public function testMemberWithEnoughBalancePasses(): void
    {
        $this->account->deposit('u2', 50000.0, 'u1', 'deposit');

        $this->gate()->assertCanCreate([], self::MEMBER);
        $this->assertTrue(true);
    }

    public function testMemberWithInsufficientBalanceIsBlocked(): void
    {
        $this->account->deposit('u2', 100.0, 'u1', 'deposit');

        try {
            $this->gate()->assertCanCreate([], self::MEMBER);
            $this->fail('Gerbang seharusnya melempar InsufficientCredits');
        } catch (InsufficientCredits $e) {
            $this->assertSame(100.0, $e->balance);
            $this->assertSame(43200.0, $e->required);
            $this->assertStringContainsString('100.00', $e->getMessage());
            $this->assertStringContainsString('43200.00', $e->getMessage());
            $this->assertSame(402, $e->getCode());
        }
    }

    public function testAssertCanStartUsesAppLimits(): void
    {
        $this->account->deposit('u2', 50000.0, 'u1', 'deposit');
        $app = ['id' => 'a1', 'owner_id' => 'u2', 'limits' => ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]];

        // 120/jam × 24 × 30 = 86400 > 50000 → diblokir.
        $this->expectException(InsufficientCredits::class);
        try {
            $this->gate()->assertCanStart($app, self::MEMBER);
        } finally {
            $this->assertSame(86400.0, $this->gate()->requiredFor(['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]));
        }
    }

    public function testInsufficientCreditsRenders402Json(): void
    {
        $error = new InsufficientCredits(1.5, 99.0);
        $request = new Request(
            "GET /api/apps HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n"
        );

        $response = $error->render($request);

        $this->assertNotNull($response);
        $this->assertSame(402, $response->getStatusCode());
        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(402, $payload['code']);
        $this->assertSame($error->getMessage(), $payload['msg']);
    }

    public function testDefaultMessageMentionsBalanceAndRequirement(): void
    {
        $error = new InsufficientCredits(12.5, 43200.0);

        $this->assertStringContainsString('12.50', $error->getMessage());
        $this->assertStringContainsString('43200.00', $error->getMessage());
        $this->assertStringContainsString('Kredit', $error->getMessage());
    }
}
