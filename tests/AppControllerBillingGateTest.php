<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Auth\UserStore;
use app\library\Billing\BillingGate;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\InsufficientCredits;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use support\Request;
use Tests\Support\SqliteFixture;

/**
 * Test gerbang kredit di AppController — helper mediator:
 *  - `billingAssertCanCreate()` / `billingAssertCanStart()` (admin & null-user
 *    bebas, member saldo kurang → InsufficientCredits),
 *  - `billingBlocked()` (bentuk respons 402 JSON vs flash+redirect `/credits`),
 *  - `defaultLimitsFor()` / `isBillingMember()` (anti-lubang harga),
 *  - audit statik bahwa SETIAP jalur yang menyalakan container memanggil gerbang.
 *
 * Tanpa HTTP & tanpa Docker; BillingGate di-inject dengan path temp unik.
 */
class AppControllerBillingGateTest extends TestCase
{
    private const RATES = ['cpu' => 100.0, 'ram' => 20.0];
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'member', 'role' => 'member'];

    private string $tmp;
    private CreditAccount $account;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-appctrl-gate-' . bin2hex(random_bytes(6));
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

    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(AppController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new AppController(), ...$args);
    }

    // ------------------------------------------------------------------
    // Gerbang
    // ------------------------------------------------------------------

    public function testCreateGateBlocksMemberWithInsufficientBalance(): void
    {
        // required untuk limits kosong = 60/jam × 24 × 30 = 43200.
        $this->expectException(InsufficientCredits::class);
        $this->invoke('billingAssertCanCreate', [[], self::MEMBER, $this->gate()]);
    }

    public function testCreateGatePassesMemberWithEnoughBalance(): void
    {
        $this->account->deposit('u2', 50000.0, 'u1', 'deposit');

        $this->invoke('billingAssertCanCreate', [[], self::MEMBER, $this->gate()]);
        $this->assertTrue(true, 'saldo cukup → gerbang lolos');
    }

    public function testCreateGateExemptsAdmin(): void
    {
        $this->invoke('billingAssertCanCreate', [[], self::ADMIN, $this->gate()]);
        $this->assertTrue(true, 'admin gratis');
    }

    public function testCreateGateExemptsNullUser(): void
    {
        $this->invoke('billingAssertCanCreate', [[], null, $this->gate()]);
        $this->assertTrue(true, 'tanpa user login — AuthMiddleware yang menolak');
    }

    public function testStartGateUsesAppLimits(): void
    {
        $this->account->deposit('u2', 50000.0, 'u1', 'deposit');
        $app = ['id' => 'a1', 'owner_id' => 'u2', 'limits' => ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]];

        // 120/jam × 24 × 30 = 86400 > 50000 → diblokir.
        $this->expectException(InsufficientCredits::class);
        $this->invoke('billingAssertCanStart', [$app, self::MEMBER, $this->gate()]);
    }

    public function testStartGateExemptsAdminApp(): void
    {
        $app = ['id' => 'a1', 'owner_id' => 'u1', 'limits' => ['web' => ['cpus' => 4.0, 'memory_mb' => 8192]]];

        $this->invoke('billingAssertCanStart', [$app, self::ADMIN, $this->gate()]);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // Bentuk respons blokir
    // ------------------------------------------------------------------

    public function testBlockedRenders402JsonForAjax(): void
    {
        $error = new InsufficientCredits(12.5, 43200.0);
        $request = new Request(
            "POST /apps/a1/rebuild HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n"
        );

        $response = $this->invoke('billingBlocked', [$request, $error]);

        $this->assertSame(402, $response->getStatusCode());
        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(402, $payload['code']);
        $this->assertSame($error->getMessage(), $payload['error']);
        $this->assertStringContainsString('12.50', $payload['error']);
        $this->assertStringContainsString('43200.00', $payload['error']);
    }

    public function testBlockedFormBranchFlashesAndRedirectsToCredits(): void
    {
        // flash_set()/session() butuh konteks HTTP → verifikasi sumber: cabang
        // non-JSON wajib menulis flash error lalu redirect ke /credits.
        $body = $this->methodBody('billingBlocked');

        $this->assertStringContainsString("flash_set('error'", $body);
        $this->assertStringContainsString("redirect('/credits')", $body);
    }

    // ------------------------------------------------------------------
    // Anti-lubang harga / klasifikasi user
    // ------------------------------------------------------------------

    public function testIsBillingMemberClassification(): void
    {
        $this->assertTrue((bool) $this->invoke('isBillingMember', [self::MEMBER]));
        $this->assertFalse((bool) $this->invoke('isBillingMember', [self::ADMIN]));
        $this->assertFalse((bool) $this->invoke('isBillingMember', [null]));
    }

    public function testDefaultLimitsForFillsEveryService(): void
    {
        $limits = $this->invoke('defaultLimitsFor', [['web', 'worker']]);

        $this->assertSame(['web', 'worker'], array_keys($limits));
        foreach ($limits as $limit) {
            $this->assertSame(0.5, $limit['cpus']);
            $this->assertSame(512, $limit['memory_mb']);
        }
    }

    public function testDefaultLimitsForSkipsBlankService(): void
    {
        $this->assertSame([], $this->invoke('defaultLimitsFor', [['', '   ']]));
    }

    // ------------------------------------------------------------------
    // Audit statik: setiap jalur yang menyalakan container punya gerbang
    // ------------------------------------------------------------------

    /**
     * Titik gerbang wajib (SPECS §5.3) → helper gerbang yang harus muncul di badan.
     *
     * @return array<string,string>
     */
    private static function requiredGatePoints(): array
    {
        return [
            'confirmCreate' => 'billingAssertCanCreate',
            'templateDeploy' => 'billingAssertCanCreate',
            'rebuild' => 'billingAssertCanStart',
            'rollback' => 'billingAssertCanStart',
            'start' => 'billingAssertCanStart',
            'saveCompose' => 'billingAssertCanStart',
            'saveEnv' => 'billingAssertCanStart',
            'saveNetworks' => 'billingAssertCanStart',
            'saveContainerNames' => 'billingAssertCanStart',
            'saveLimits' => 'billingAssertCanStart',
        ];
    }

    public function testEveryIgnitionPathCallsTheGate(): void
    {
        foreach (self::requiredGatePoints() as $method => $gateCall) {
            $body = $this->methodBody($method);
            self::assertStringContainsString(
                $gateCall,
                $body,
                "AppController::{$method}() tidak memanggil {$gateCall}()"
            );
            self::assertStringContainsString(
                'InsufficientCredits $e',
                $body,
                "AppController::{$method}() tidak menangani InsufficientCredits"
            );
        }
    }

    /**
     * Anti-lubang harga: jalur create member wajib menegakkan plafon billing &
     * memberi basis limit default — app member tidak boleh "tanpa limit".
     */
    public function testCreatePathsEnforceCapsAndDefaultLimits(): void
    {
        foreach (['confirmCreate', 'templateDeploy'] as $method) {
            $body = $this->methodBody($method);
            self::assertStringContainsString('Pricing::assertWithinCaps', $body, "{$method} tidak menegakkan plafon");
            self::assertStringContainsString('defaultLimitsFor', $body, "{$method} tidak memberi limit default");
            self::assertStringContainsString("isBillingMember(\$user)", $body, "{$method} tidak membatasi ke member");
        }
    }

    private function methodBody(string $method): string
    {
        $reflection = new ReflectionMethod(AppController::class, $method);
        $lines = file(dirname(__DIR__) . '/app/controller/AppController.php') ?: [];
        $length = $reflection->getEndLine() - $reflection->getStartLine() + 1;

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $length));
    }

    // ------------------------------------------------------------------
    // Penanggung biaya (owner) — selaras dengan worker cli/deploy.php
    // ------------------------------------------------------------------

    /**
     * Gerbang untuk app yang sudah ada menilai **owner** (penanggung biaya), bukan
     * aktor — app yang dibagikan tetap ditagih ke ownernya, dan app milik admin
     * tidak terblokir hanya karena operatornya member tanpa saldo.
     */
    public function testBillingPayerPrefersOwnerOverActor(): void
    {
        $users = new UserStore($this->tmp . '/rames.sqlite');

        $payer = $this->invoke('billingPayerFor', [['owner_id' => 'u2'], self::ADMIN, $users]);
        self::assertSame('u2', $payer['id'] ?? null, 'aktor bukan owner → owner yang dinilai');

        $same = $this->invoke('billingPayerFor', [['owner_id' => 'u2'], self::MEMBER, $users]);
        self::assertSame('u2', $same['id'] ?? null, 'aktor adalah owner → dipakai apa adanya');

        $missing = $this->invoke('billingPayerFor', [['owner_id' => 'u-tidak-ada'], self::MEMBER, $users]);
        self::assertSame('u2', $missing['id'] ?? null, 'owner tak dikenal → jatuh ke aktor, app tidak terkunci');

        $noOwner = $this->invoke('billingPayerFor', [['owner_id' => ''], self::MEMBER, $users]);
        self::assertSame('u2', $noOwner['id'] ?? null, 'tanpa owner_id → aktor');
    }

    public function testStartGateAssessesThePayer(): void
    {
        self::assertStringContainsString(
            'billingPayerFor',
            $this->methodBody('billingAssertCanStart'),
            'billingAssertCanStart() harus menilai owner (penanggung biaya), bukan aktor'
        );
    }

    // ------------------------------------------------------------------
    // Penutupan escape: transfer kepemilikan ke admin tidak membebaskan member
    // ------------------------------------------------------------------

    /**
     * Escape (temuan verifier N1): owner dipindah ke user **admin** (bebas
     * tagihan) sementara operator member-nya tidak punya saldo. Gerbang wajib
     * tetap menolak aktor member — bukan hanya menilai owner.
     */
    public function testMemberActorIsBlockedEvenWhenOwnerIsAdmin(): void
    {
        $app = ['id' => 'a1', 'owner_id' => 'u1', 'limits' => ['web' => ['cpus' => 1.0, 'memory_mb' => 512]]];

        $this->expectException(InsufficientCredits::class);
        $this->invoke('billingAssertCanStart', [$app, self::MEMBER, $this->gate()]);
    }

    public function testMemberActorWithBalanceCanOperateAdminOwnedApp(): void
    {
        $app = ['id' => 'a1', 'owner_id' => 'u1', 'limits' => []];
        $users = new UserStore($this->tmp . '/rames.sqlite');
        $this->account->deposit('u2', 100000.0, 'u1', 'topup');

        $this->invoke('billingAssertCanStart', [$app, self::MEMBER, $this->gate(), $users]);

        self::assertTrue(true, 'member bersaldo boleh mengoperasikan app milik admin (tidak diblokir palsu)');
    }

    /**
     * Biaya ditanggung owner: admin yang mengoperasikan app milik member tanpa
     * saldo tetap ditolak (konsisten dengan worker `cli/deploy.php`).
     */
    public function testOwnerBalanceIsRequiredForAdminActorToo(): void
    {
        $app = ['id' => 'a1', 'owner_id' => 'u2', 'limits' => []];
        $users = new UserStore($this->tmp . '/rames.sqlite');

        $this->expectException(InsufficientCredits::class);
        $this->invoke('billingAssertCanStart', [$app, self::ADMIN, $this->gate(), $users]);
    }

    /**
     * Pengalihan kepemilikan ke pemilik bebas tagihan (admin) pada app yang masih
     * hidup harus menghentikan app — menutup escape "bebas biaya" (temuan N6).
     */
    public function testTransferOwnerStopsAppOnBillingEscape(): void
    {
        $body = $this->methodBody('transferOwner');

        self::assertStringContainsString('shouldStopOnTransfer', $body);
        self::assertStringContainsString('stopForBillingEscape', $body);
    }

    /**
     * Penghapusan user (jalur admin) juga memindahkan app ke admin ⇒ app yang
     * masih hidup harus dihentikan (temuan verifier F1).
     */
    public function testUserDeletionStopsTransferredApps(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/controller/UserController.php');
        $body = '';
        if (preg_match('/public function delete\(.*?\n    \}/s', $source, $matches) === 1) {
            $body = $matches[0];
        }

        self::assertStringContainsString('stopTransferredToExemptOwner', $body);
        self::assertStringContainsString('ownedBy', $body, 'daftar app diambil SEBELUM transferAllFrom');
    }
}
