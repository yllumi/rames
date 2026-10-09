<?php
declare(strict_types=1);

namespace Tests;

use app\controller\PaymentController;
use app\library\Auth\UserStore;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\DuitkuClient;
use app\library\Billing\DuitkuSignature;
use app\library\Billing\TopUpOrder;
use app\library\Billing\TopUpService;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;

/**
 * Test end-to-end jalur callback Duitku (`POST /payments/duitku/callback`).
 *
 * Tanpa jaringan & tanpa menyentuh `database/*.json` nyata: store poin ke path
 * temp (dan dibersihkan), klien Duitku memakai handler tiruan, config diarahkan
 * ke direktori temp. Membuktikan: 200 `OK` sekali, idempotensi saldo, tolak
 * signature salah & amount tidak cocok, 404 saat fitur/kredensial mati, dan
 * respons tidak memuat data sensitif.
 */
class PaymentCallbackTest extends TestCase
{
    private const API_KEY = 'secret-duitku-key-xyz';
    private const MERCHANT = 'DS12345';

    private string $tmp;
    private BillingStore $billing;
    private CreditAccount $accounts;
    private UserStore $users;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/paycb_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/runtime', 0777, true);
        mkdir($this->tmp . '/logs', 0777, true);

        $this->configState = $this->snapshotConfigState();
        $this->useConfig([]);

        file_put_contents($this->tmp . '/auth.json', (string) json_encode([
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => '', 'email' => 'admin@example.com'],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => '', 'email' => 'budi@example.com'],
        ]));

        $this->billing = new BillingStore($this->tmp . '/billing.json');
        $this->users = new UserStore($this->tmp . '/auth.json');
        $this->accounts = new CreditAccount($this->billing, $this->users);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // (a) signature valid + amount cocok + resultCode=00 → 200 OK, saldo +1×
    // ------------------------------------------------------------------

    public function testValidCallbackCreditsOnceAndReturns200Ok(): void
    {
        $order = $this->order();
        $controller = $this->controller();

        $response = $controller->callback($this->post($this->payload((string) $order['id'], 50000)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->rawBody());
        $this->assertSame(5000.0, $this->accounts->balance('u2'), 'saldo bertambah sekali');
        $this->assertSame('paid', $this->billing->orders()[(string) $order['id']]['status']);
    }

    /**
     * (b) callback dua kali → saldo hanya bertambah sekali (idempotensi).
     */
    public function testDuplicateCallbackIsIdempotent(): void
    {
        $order = $this->order();
        $controller = $this->controller();
        $payload = $this->payload((string) $order['id'], 50000);

        $first = $controller->callback($this->post($payload));
        $second = $controller->callback($this->post($payload));

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode(), 'retry Duitku tetap dibalas 200 (tanpa retry lanjutan)');
        $this->assertSame('OK', $first->rawBody());
        $this->assertSame('OK', $second->rawBody());
        $this->assertSame(5000.0, $this->accounts->balance('u2'), 'saldo TIDAK bertambah dua kali');
    }

    /**
     * (c) signature salah → 403 & saldo tidak berubah.
     */
    public function testInvalidSignatureIsRejectedAndCreditsNothing(): void
    {
        $order = $this->order();
        $controller = $this->controller();

        $payload = $this->payload((string) $order['id'], 50000, '00', 'deadbeef');
        $response = $controller->callback($this->post($payload));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0.0, $this->accounts->balance('u2'));
        $this->assertSame('pending', $this->billing->orders()[(string) $order['id']]['status']);
    }

    /**
     * (d) amount callback ≠ order → ditolak, saldo tidak berubah.
     */
    public function testAmountMismatchIsRejectedAndCreditsNothing(): void
    {
        $order = $this->order(50000);
        $controller = $this->controller();

        // Signature sah untuk 60000, tetapi order tersimpan 50000 → amount_mismatch.
        $response = $controller->callback($this->post($this->payload((string) $order['id'], 60000)));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0.0, $this->accounts->balance('u2'));
        $this->assertSame('pending', $this->billing->orders()[(string) $order['id']]['status']);
    }

    /**
     * merchantCode salah → ditolak (403), saldo tidak berubah.
     */
    public function testWrongMerchantCodeIsRejected(): void
    {
        $order = $this->order();
        $controller = $this->controller();

        $payload = $this->payload((string) $order['id'], 50000);
        $payload['merchantCode'] = 'DS99999';
        $response = $controller->callback($this->post($payload));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0.0, $this->accounts->balance('u2'));
    }

    /**
     * resultCode=01 → 200 OK, order failed, tanpa kredit.
     */
    public function testFailedResultCodeReturns200WithoutCredit(): void
    {
        $order = $this->order();
        $controller = $this->controller();

        $response = $controller->callback($this->post($this->payload((string) $order['id'], 50000, '01')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->rawBody());
        $this->assertSame(0.0, $this->accounts->balance('u2'));
        $this->assertSame('failed', $this->billing->orders()[(string) $order['id']]['status']);
    }

    // ------------------------------------------------------------------
    // (e) fitur mati / kredensial tidak lengkap → 404, service tidak dipakai
    // ------------------------------------------------------------------

    public function testDisabledFeatureReturns404WithoutTouchingService(): void
    {
        $this->useConfig(['billing_topup_enabled' => false]);
        $service = $this->countingService();
        $controller = new PaymentController($service);

        $response = $controller->callback($this->post($this->payload('RM-whatever', 50000)));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $service->calls, 'endpoint mati tidak boleh memuat/memakai service');
        $this->assertSame(0.0, $this->accounts->balance('u2'));
    }

    public function testUnconfiguredCredentialsReturn404WithoutTouchingService(): void
    {
        $this->useConfig([
            'billing_topup_enabled' => true,
            'billing_duitku_merchant_code' => '',
            'billing_duitku_api_key' => '',
        ]);
        $service = $this->countingService();
        $controller = new PaymentController($service);

        $response = $controller->callback($this->post($this->payload('RM-whatever', 50000)));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $service->calls, 'kredensial tidak lengkap tidak boleh memakai service');
    }

    // ------------------------------------------------------------------
    // (f) respons tidak memuat data sensitif
    // ------------------------------------------------------------------

    public function testResponseLeaksNoSensitiveData(): void
    {
        $order = $this->order();
        $controller = $this->controller();

        $ok = $controller->callback($this->post($this->payload((string) $order['id'], 50000)));
        $bad = $controller->callback($this->post($this->payload((string) $order['id'], 60000, '00', 'deadbeef')));

        foreach ([$ok, $bad] as $response) {
            $body = $response->rawBody();
            $this->assertStringNotContainsString(self::API_KEY, $body);
            $this->assertStringNotContainsString('budi@example.com', $body);
            $this->assertStringNotContainsString((string) $order['id'], $body);
            $this->assertStringNotContainsString('5000', $body, 'saldo/kredit tidak boleh muncul di respons');
        }
        $this->assertSame('OK', $ok->rawBody());
        $this->assertSame('Forbidden', $bad->rawBody());
    }

    // ------------------------------------------------------------------
    // Payload cacat → 400
    // ------------------------------------------------------------------

    public function testMalformedPayloadReturns400(): void
    {
        $controller = $this->controller();

        $missingOrder = $controller->callback($this->post(['resultCode' => '00']));
        $missingResult = $controller->callback($this->post(['merchantOrderId' => 'RM-1']));

        $this->assertSame(400, $missingOrder->getStatusCode());
        $this->assertSame(400, $missingResult->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Payload cacat (field array) dari endpoint publik → **400**, bukan 500.
     */
    public function testArrayValuedFieldsAreRejectedWith400NotServerError(): void
    {
        $this->order();
        $controller = $this->controller();

        $response = $controller->callback($this->post([
            'merchantOrderId' => ['x', 'y'],
            'resultCode' => '00',
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Bad Request', $response->rawBody());
        $this->assertSame(0.0, $this->accounts->balance('u2'));
    }

    private function controller(): PaymentController
    {
        return new PaymentController($this->service());
    }

    private function service(): TopUpService
    {
        return new TopUpService(
            $this->billing,
            new CreditAccount($this->billing, $this->users),
            $this->client(),
            $this->users
        );
    }

    private function countingService(): CountingTopUpService
    {
        return new CountingTopUpService(
            $this->billing,
            new CreditAccount($this->billing, $this->users),
            $this->client(),
            $this->users
        );
    }

    private function client(): DuitkuClient
    {
        // Handler tiruan: menjamin tidak ada panggilan jaringan nyata (callback
        // sendiri tidak memanggil network, tetapi ini sabuk pengaman).
        return new DuitkuClient([
            'mode' => 'sandbox',
            'merchant_code' => self::MERCHANT,
            'api_key' => self::API_KEY,
            'callback_url' => 'https://dashboard.test/payments/duitku/callback',
            'return_url' => 'https://dashboard.test/credits/topup/return',
            'methods' => 'BC,VA,QR',
            'method_ttl' => 3600,
            'runtime_path' => $this->tmp . '/runtime',
        ], new Client(['handler' => new FakeDuitkuHandler(), 'http_errors' => false]));
    }

    /**
     * @return array<string,mixed>
     */
    private function order(int $amount = 50000): array
    {
        return (new TopUpOrder($this->billing))->create('u2', $amount, 'BC');
    }

    /**
     * @return array<string,string>
     */
    private function payload(string $orderId, int $amount, string $resultCode = '00', ?string $signature = null): array
    {
        return [
            'merchantCode' => self::MERCHANT,
            'amount' => (string) $amount,
            'merchantOrderId' => $orderId,
            'resultCode' => $resultCode,
            'reference' => 'REF-DUITKU-1',
            'signature' => $signature ?? DuitkuSignature::callback(self::MERCHANT, (string) $amount, $orderId, self::API_KEY),
        ];
    }

    /**
     * @param array<string,string> $fields
     */
    private function post(array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST /payments/duitku/callback HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    // ------------------------------------------------------------------
    // Config temp (mirip TopUpServiceTest) — tanpa menyentuh config nyata
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $overrides
     */
    private function useConfig(array $overrides): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(array_merge([
                'billing_enabled' => true,
                'billing_topup_enabled' => true,
                'billing_topup_idr_per_credit' => 10.0,
                'billing_topup_min_idr' => 10000,
                'billing_topup_max_idr' => 5000000,
                'billing_topup_max_pending' => 3,
                'billing_topup_expiry_minutes' => 0,
                'billing_ledger_keep' => 200,
                'billing_log_path' => $this->tmp . '/logs',
                'billing_duitku_mode' => 'sandbox',
                'billing_duitku_merchant_code' => self::MERCHANT,
                'billing_duitku_api_key' => self::API_KEY,
                'billing_duitku_callback_url' => 'https://dashboard.test/payments/duitku/callback',
                'billing_duitku_return_url' => 'https://dashboard.test/credits/topup/return',
                'billing_duitku_methods' => 'BC,VA,QR',
                'billing_duitku_method_ttl' => 3600,
                'billing_duitku_timeout' => 15,
                'billing_duitku_status_min_interval' => 900,
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
 * Subclass penghitung: membuktikan controller 404 **tidak** memakai service
 * (baik ia tidak dibangun maupun tidak dipanggil).
 */
class CountingTopUpService extends TopUpService
{
    public int $calls = 0;

    public function handleCallback(array $payload): array
    {
        $this->calls++;

        return parent::handleCallback($payload);
    }
}
