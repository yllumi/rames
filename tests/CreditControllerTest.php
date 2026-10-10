<?php
declare(strict_types=1);

namespace Tests;

use app\controller\CreditController;
use app\library\Auth\UserStore;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\DuitkuClient;
use app\library\Billing\Invoicer;
use app\library\Billing\TopUpOrder;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;

/**
 * Test mediator `CreditController` (SPECS.md §7.12, plan §5.4/§5.7).
 *
 * Tanpa HTTP nyata, tanpa jaringan, tanpa Docker. Store & config diarahkan ke
 * direktori temp unik; user login disuntik lewat seam `currentUser()` (session
 * Webman butuh konteks HTTP, jadi tidak dipakai di sini) dan flash ditangkap
 * lewat seam `flash()`.
 *
 * Membuktikan: admin-only → 404 tanpa mutasi, deposit admin menaikkan saldo +
 * ledger, order user lain → 404, top-up mati → 404, email hanya untuk diri
 * sendiri (anti IDOR), dan `methods` mati → `{code:0,data:[]}`.
 */
class CreditControllerTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];
    private const OTHER = ['id' => 'u3', 'username' => 'citra', 'role' => 'member'];

    private string $tmp;
    private BillingStore $billing;
    private UserStore $users;
    private CreditAccount $accounts;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-creditctrl-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();

        $db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            ['id' => 'u3', 'username' => 'citra', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);
        SqliteFixture::apps($db, []);

        $this->billing = new BillingStore($db);
        $this->users = new UserStore($db);
        $this->accounts = new CreditAccount($this->billing, $this->users);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // (a) admin-only → 404 & tanpa mutasi
    // ------------------------------------------------------------------

    public function testDepositByMemberReturns404AndDoesNotMutate(): void
    {
        $controller = $this->controller(self::MEMBER);

        $response = $controller->deposit($this->post('/credits/deposit', [
            'user_id' => 'u2',
            'amount' => '500',
            'note' => 'coba',
        ]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0.0, $this->accounts->balance('u2'));
        $this->assertSame([], $this->accounts->ledger('u2'));
    }

    public function testChargeByMemberReturns404WithoutRunningInvoicer(): void
    {
        $invoicer = new CountingInvoicer(null, $this->billing, $this->users);
        $controller = $this->controller(self::MEMBER, $invoicer);

        $response = $controller->charge($this->post('/credits/charge', ['period' => '']));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $invoicer->calls, 'invoicer tidak boleh dipanggil untuk non-admin');
    }

    // ------------------------------------------------------------------
    // (b) deposit admin → saldo naik + ledger deposit
    // ------------------------------------------------------------------

    public function testDepositByAdminIncreasesBalanceAndWritesLedger(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->deposit($this->post('/credits/deposit', [
            'user_id' => 'u2',
            'amount' => '150.5',
            'note' => 'transfer bank',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/credits', $response->getHeader('Location'));
        $this->assertSame(150.5, $this->accounts->balance('u2'));

        $ledger = $this->accounts->ledger('u2');
        $this->assertCount(1, $ledger);
        $this->assertSame('deposit', $ledger[0]['type']);
        $this->assertSame(150.5, $ledger[0]['amount']);
        $this->assertSame('u1', $ledger[0]['by']);
        $this->assertSame('transfer bank', $ledger[0]['note']);
        $this->assertCount(1, $controller->flashes, 'flash sukses dipasang');
        $this->assertSame('success', $controller->flashes[0]['type']);
    }

    public function testDepositWithInvalidAmountDoesNotMutate(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->deposit($this->post('/credits/deposit', [
            'user_id' => 'u2',
            'amount' => 'bukan-angka',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0.0, $this->accounts->balance('u2'));
        $this->assertSame('error', $controller->flashes[0]['type']);
    }

    /**
     * Input berbentuk array (`user_id[]=…`) tidak boleh menjadi error 500 —
     * diperlakukan sebagai nilai tidak valid (flash error + redirect).
     */
    public function testArrayValuedPostFieldsAreTreatedAsInvalidNotServerError(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->deposit($this->post('/credits/deposit', [
            'user_id' => ['u2'],
            'amount' => ['500'],
            'note' => ['x'],
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/credits', $response->getHeader('Location'));
        $this->assertSame(0.0, $this->accounts->balance('u2'));
        $this->assertSame('error', $controller->flashes[0]['type']);
    }

    /**
     * `email[]=x` bukan berarti "hapus email": input non-skalar ditolak sebagai
     * tidak valid dan email lama dipertahankan.
     */
    public function testArrayValuedEmailDoesNotClearExistingEmail(): void
    {
        $this->users->setEmail('u2', 'budi@example.com');
        $controller = $this->controller(self::MEMBER);

        $response = $controller->setEmail($this->post('/credits/email', ['email' => ['x']]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('budi@example.com', $this->users->emailOf($this->users->findById('u2') ?? []));
        $this->assertSame('error', $controller->flashes[0]['type']);
    }

    /**
     * Nominal negatif = pengurangan manual (`adjust`) — bagian dari kontrak
     * "tambah/kurangi saldo manual" (plan §5.4).
     */
    public function testNegativeAmountReducesBalanceAsAdjustment(): void    {
        $this->accounts->deposit('u2', 1000.0, 'u1', 'saldo awal');

        $controller = $this->controller(self::ADMIN);
        $response = $controller->deposit($this->post('/credits/deposit', [
            'user_id' => 'u2',
            'amount' => '-250',
            'note' => 'koreksi',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(750.0, $this->accounts->balance('u2'));

        $ledger = $this->accounts->ledger('u2');
        $this->assertSame('adjust', $ledger[0]['type']);
        $this->assertSame(-250.0, $ledger[0]['amount']);
        $this->assertSame('success', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // (c) topupReturn order user lain → 404
    // ------------------------------------------------------------------

    public function testTopupReturnForOtherUsersOrderReturns404(): void
    {
        $order = (new TopUpOrder($this->billing))->create('u2', 50000, 'BC');
        $controller = $this->controller(self::OTHER);

        $response = $controller->topupReturn($this->get('/credits/topup/return?order=' . $order['id']));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTopupReturnWithoutOrderParamReturns404(): void
    {
        $controller = $this->controller(self::MEMBER);

        $response = $controller->topupReturn($this->get('/credits/topup/return'));

        $this->assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // (d) topup saat fitur mati → 404
    // ------------------------------------------------------------------

    public function testTopupWhenFeatureDisabledReturns404(): void
    {
        $this->useConfig(['billing_topup_enabled' => false]);
        $controller = $this->controller(self::MEMBER);

        $response = $controller->topup($this->post('/credits/topup', [
            'amount_idr' => '50000',
            'method' => 'BC',
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // (e) setEmail hanya mengubah user login sendiri (anti IDOR)
    // ------------------------------------------------------------------

    public function testSetEmailOnlyChangesLoggedInUser(): void
    {
        $controller = $this->controller(self::MEMBER);

        $response = $controller->setEmail($this->post('/credits/email', [
            'email' => 'budi@example.com',
            // Percobaan menargetkan user lain — harus DIABAIKAN.
            'user_id' => 'u1',
            'id' => 'u1',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/credits', $response->getHeader('Location'));

        $bud = $this->users->findById('u2');
        $admin = $this->users->findById('u1');
        $this->assertNotNull($bud);
        $this->assertNotNull($admin);
        $this->assertSame('budi@example.com', $this->users->emailOf($bud));
        $this->assertSame('', $this->users->emailOf($admin), 'email user lain tidak boleh berubah');
        $this->assertSame('success', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // Diagnostik top-up untuk admin (mengapa form top-up tidak muncul)
    // ------------------------------------------------------------------

    public function testTopupIssuesMentionsDisabledFlag(): void
    {
        $this->useConfig(['billing_topup_enabled' => false]);

        $issues = $this->topupIssues($this->controller(self::ADMIN));

        self::assertCount(1, $issues);
        self::assertStringContainsString('BILLING_TOPUP_ENABLED', $issues[0]);
    }

    public function testTopupIssuesRequireHttpsCallbackByDefault(): void
    {
        $this->useConfig([
            'billing_topup_enabled' => true,
            'billing_duitku_merchant_code' => 'DS36268',
            'billing_duitku_api_key' => 'rahasia',
            'billing_duitku_callback_url' => 'http://localhost:8123/payments/duitku/callback',
            'billing_duitku_allow_http' => false,
        ]);

        $issues = $this->topupIssues($this->controller(self::ADMIN));

        self::assertCount(1, $issues);
        self::assertStringContainsString('https', $issues[0]);
        self::assertStringContainsString('BILLING_DUITKU_CALLBACK_URL', $issues[0]);
    }

    public function testTopupIssuesEmptyWhenAllowHttpOptedInForLocalTesting(): void
    {
        $this->useConfig([
            'billing_topup_enabled' => true,
            'billing_duitku_merchant_code' => 'DS36268',
            'billing_duitku_api_key' => 'rahasia',
            'billing_duitku_callback_url' => 'http://localhost:8123/payments/duitku/callback',
            'billing_duitku_allow_http' => true,
        ]);

        self::assertSame([], $this->topupIssues($this->controller(self::ADMIN)));
    }

    public function testTopupIssuesMentionMissingCredentials(): void
    {
        $this->useConfig([
            'billing_topup_enabled' => true,
            'billing_duitku_merchant_code' => '',
            'billing_duitku_api_key' => '',
            'billing_duitku_callback_url' => 'https://dash.test/payments/duitku/callback',
        ]);

        $issues = $this->topupIssues($this->controller(self::ADMIN));

        self::assertCount(2, $issues);
        self::assertStringContainsString('BILLING_DUITKU_MERCHANT_CODE', $issues[0]);
        self::assertStringContainsString('BILLING_DUITKU_API_KEY', $issues[1]);
    }

    /**
     * @return array<int,string>
     */
    private function topupIssues(CreditController $controller): array
    {
        $method = new ReflectionMethod(CreditController::class, 'topupIssues');
        $method->setAccessible(true);

        /** @var array<int,string> $issues */
        $issues = $method->invoke($controller);

        return $issues;
    }

    public function testSetEmailRejectsInvalidAddress(): void
    {
        $controller = $this->controller(self::MEMBER);

        $controller->setEmail($this->post('/credits/email', ['email' => 'bukan-email']));

        $bud = $this->users->findById('u2');
        $this->assertNotNull($bud);
        $this->assertSame('', $this->users->emailOf($bud));
        $this->assertSame('error', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // (f) methods saat fitur mati → {code:0,data:[]}
    // ------------------------------------------------------------------

    public function testMethodsWhenFeatureDisabledReturnsEmptyData(): void
    {
        $this->useConfig(['billing_topup_enabled' => false]);
        $controller = $this->controller(self::MEMBER);

        $response = $controller->methods($this->get('/api/credits/methods?amount=50000'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(['code' => 0, 'data' => []], $payload);
    }

    public function testMethodsWhenGatewayNotConfiguredReturnsEmptyData(): void    {
        // Fitur menyala, tapi kredensial kosong → tetap tanpa jaringan.
        $this->useConfig(['billing_topup_enabled' => true]);
        $controller = $this->controller(self::MEMBER);

        $response = $controller->methods($this->get('/api/credits/methods?amount=50000'));

        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(0, $payload['code']);
        $this->assertSame([], $payload['data']);
    }

    // ------------------------------------------------------------------
    // (g) /api/credits/methods → group + urutan kanonik
    // ------------------------------------------------------------------

    public function testMethodsJsonAddsGroupAndOrdersByCanonicalGroup(): void
    {
        $client = $this->duitkuClient([
            ['code' => 'BC', 'name' => 'BCA Virtual Account', 'image' => 'bc.png', 'fee' => 4000],
            ['code' => 'OV', 'name' => 'OVO', 'image' => 'ovo.png', 'fee' => 0],
            ['code' => 'ZZ', 'name' => 'Misterius', 'image' => '', 'fee' => 0],
            ['code' => 'VA', 'name' => 'Virtual Account', 'image' => 'va.png', 'fee' => 0],
            ['code' => 'NQ', 'name' => 'QRIS', 'image' => 'nq.png', 'fee' => 0],
            ['code' => 'IR', 'name' => 'Indomaret', 'image' => 'ir.png', 'fee' => 0],
        ]);
        $this->useConfig(['billing_topup_enabled' => true]);
        $controller = $this->controller(self::MEMBER, null, $client);

        $response = $controller->methods($this->get('/api/credits/methods?amount=50000'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(0, $payload['code']);
        $this->assertSame(
            ['BC', 'VA', 'OV', 'NQ', 'IR', 'ZZ'],
            array_column($payload['data'], 'code'),
            'urut per grup kanonik (stabil di dalam grup)'
        );
        $this->assertSame(
            ['Virtual Account', 'Virtual Account', 'E-Wallet', 'QRIS', 'Retail', 'Lainnya'],
            array_column($payload['data'], 'group')
        );
        // Key lain tidak berubah.
        $this->assertSame('BCA Virtual Account', $payload['data'][0]['name']);
        $this->assertSame('bc.png', $payload['data'][0]['image']);
        $this->assertSame(4000, $payload['data'][0]['fee']);
    }

    // ------------------------------------------------------------------
    // (g2) defense-in-depth: kanal di luar allowlist tak pernah tampil
    // ------------------------------------------------------------------

    public function testMethodsDropsCodesOutsideAllowlistEvenFromUnfilteredClient(): void
    {
        // Klien fake sengaja mengembalikan kanal di luar allowlist (`QQ`) dan
        // kanal hard-exclude (`VC`) — controller wajib menyaringnya sendiri.
        $client = $this->duitkuClient([
            ['code' => 'BC', 'name' => 'BCA VA', 'image' => '', 'fee' => 4000],
            ['code' => 'QQ', 'name' => 'Kanal Terlarang', 'image' => '', 'fee' => 0],
            ['code' => 'VC', 'name' => 'Virtual Card', 'image' => '', 'fee' => 0],
            ['code' => 'FT', 'name' => 'RETAIL', 'image' => '', 'fee' => 0],
            ['code' => 'SP', 'name' => 'SHOPEEPAY QRIS', 'image' => '', 'fee' => 0],
        ]);
        $this->useConfig(['billing_topup_enabled' => true]);
        $controller = $this->controller(self::MEMBER, null, $client);

        $response = $controller->methods($this->get('/api/credits/methods?amount=50000'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(0, $payload['code']);

        $codes = array_column($payload['data'], 'code');
        $this->assertNotContains('QQ', $codes, 'kode di luar allowlist tidak boleh muncul');
        $this->assertNotContains('VC', $codes, 'kode hard-exclude tidak boleh muncul');
        // Kode allowlist tetap muncul lengkap dengan `group` (urut kanonik).
        $this->assertSame(['BC', 'SP', 'FT'], $codes);
        $this->assertSame(['Virtual Account', 'QRIS', 'Retail'], array_column($payload['data'], 'group'));
    }

    // ------------------------------------------------------------------
    // (h) payload view → methodGroups (+ methods lama tetap ada)
    // ------------------------------------------------------------------

    public function testViewMethodGroupsForDefaultAllowlist(): void
    {
        $default = 'BC,BT,I1,M2,VA,B1,DM,BV,BR,NC,A1,AG,S1,FT,IR,OV,DA,SA,LF,LA,SP,NQ,SQ';
        $this->useConfig([
            'billing_topup_enabled' => true,
            'billing_duitku_methods' => $default,
        ]);
        $controller = $this->controller(self::MEMBER, null, $this->duitkuClient([], $default));

        $method = new ReflectionMethod(CreditController::class, 'viewData');
        $method->setAccessible(true);
        /** @var array<string,mixed> $vars */
        $vars = $method->invoke($controller, null);

        $this->assertSame([
            ['label' => 'Virtual Account', 'codes' => ['BC', 'BT', 'I1', 'M2', 'VA', 'B1', 'DM', 'BV', 'BR', 'NC', 'A1', 'AG', 'S1']],
            ['label' => 'E-Wallet', 'codes' => ['OV', 'DA', 'SA', 'LF', 'LA']],
            ['label' => 'QRIS', 'codes' => ['SP', 'NQ', 'SQ']],
            ['label' => 'Retail', 'codes' => ['FT', 'IR']],
        ], $vars['methodGroups'], 'allowlist default: FT Retail, SP QRIS, Lainnya kosong');
    }

    public function testViewPayloadIncludesMethodGroupsAndLegacyMethods(): void
    {
        $this->useConfig([
            'billing_topup_enabled' => true,
            'billing_duitku_methods' => 'BC,BT,I1,VA,OV,DA,NQ,SQ,IR,FT',
        ]);
        $controller = $this->controller(self::MEMBER, null, $this->duitkuClient([]));

        $method = new ReflectionMethod(CreditController::class, 'viewData');
        $method->setAccessible(true);
        /** @var array<string,mixed> $vars */
        $vars = $method->invoke($controller, null);

        $this->assertSame(
            ['BC', 'BT', 'I1', 'VA', 'OV', 'DA', 'NQ', 'SQ', 'IR', 'FT'],
            $vars['methods'],
            'kompatibilitas mundur: methods (kode datar) tetap ada'
        );
        $this->assertSame([
            ['label' => 'Virtual Account', 'codes' => ['BC', 'BT', 'I1', 'VA']],
            ['label' => 'E-Wallet', 'codes' => ['OV', 'DA']],
            ['label' => 'QRIS', 'codes' => ['NQ', 'SQ']],
            ['label' => 'Retail', 'codes' => ['IR', 'FT']],
        ], $vars['methodGroups'], 'FT kini Retail (bukan Lainnya); grup Lainnya kosong');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Klien Duitku nyata (agar `isAllowedMethod()`/`isConfigured()` bekerja)
     * dengan katalog di-inject — tanpa jaringan.
     *
     * @param array<int,array<string,mixed>> $catalog
     * @param string|null $methods allowlist kode (default: daftar fake bawaan)
     */
    private function duitkuClient(array $catalog, ?string $methods = null): FakeCatalogDuitkuClient
    {
        $client = new FakeCatalogDuitkuClient([
            'merchant_code' => 'DS12345',
            'api_key' => 'secret-key',
            'callback_url' => 'https://dashboard.test/payments/duitku/callback',
            'return_url' => 'https://dashboard.test/credits/topup/return',
            'methods' => $methods ?? 'BC,BT,I1,M2,VA,OV,DA,SA,LF,LA,SP,NQ,SQ,IR,FT,ZZ',
            'method_ttl' => 3600,
            'runtime_path' => $this->tmp . '/runtime',
        ]);
        $client->catalog = $catalog;

        return $client;
    }

    private function controller(?array $user, ?Invoicer $invoicer = null, ?DuitkuClient $duitku = null): FakeCreditController
    {
        $controller = new FakeCreditController(
            $this->billing,
            $this->users,
            new AppStore($this->tmp . '/rames.sqlite'),
            $duitku,
            $invoicer
        );
        $controller->user = $user;

        return $controller;
    }

    /**
     * @param array<string,string> $fields
     */
    private function post(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    private function get(string $path): Request
    {
        return new Request('GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function useConfig(array $overrides = []): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(array_merge([
                'sqlite_file' => $this->tmp . '/rames.sqlite',
                'database_path' => $this->tmp,
                'billing_enabled' => true,
                'billing_topup_enabled' => false,
                'billing_topup_idr_per_credit' => 10.0,
                'billing_topup_min_idr' => 10000,
                'billing_topup_max_idr' => 5000000,
                'billing_topup_max_pending' => 3,
                'billing_topup_expiry_minutes' => 0,
                'billing_ledger_keep' => 200,
                'billing_duitku_methods' => 'BC,VA,QR',
                'billing_duitku_method_ttl' => 3600,
                'billing_duitku_timeout' => 15,
            ], $overrides), true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

/**
 * Subclass seam: user login & flash disuntik tanpa session HTTP, dan penanda
 * apakah `Invoicer` pernah dipanggil (membuktikan 404 tidak berefek samping).
 */
class FakeCreditController extends CreditController
{
    public ?array $user = null;

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function flash(string $type, string $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => $message];
    }
}

/**
 * Klien Duitku seam: `paymentMethods()` diganti katalog tetap (tanpa jaringan),
 * sementara allowlist/isConfigured bawaan tetap dipakai `methodCodes()`.
 */
class FakeCatalogDuitkuClient extends DuitkuClient
{
    /** @var array<int,array<string,mixed>> */
    public array $catalog = [];

    public function paymentMethods(int $amount): array
    {
        return $this->catalog;
    }
}

/**
 * Penghitung pemanggilan penagihan (tidak menagih apa pun).
 */
class CountingInvoicer extends Invoicer
{
    public int $calls = 0;

    public function runDue(?string $now = null): array
    {
        $this->calls++;

        return [];
    }

    public function runNow(string $period, ?string $now = null): array
    {
        $this->calls++;

        return [];
    }
}
