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

/**
 * Test BillingGate — gerbang kredit (lewati admin/billing mati; blokir saldo
 * kurang) + bentuk respons InsufficientCredits. Path temp; tanpa data runtime.
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

        file_put_contents($this->tmp . '/auth.json', json_encode([
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]));

        $this->account = new CreditAccount(
            new BillingStore($this->tmp . '/billing.json'),
            new UserStore($this->tmp . '/auth.json')
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
