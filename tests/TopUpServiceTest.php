<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\DuitkuClient;
use app\library\Billing\DuitkuError;
use app\library\Billing\DuitkuSignature;
use app\library\Billing\TopUpService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\SqliteFixture;
use Webman\Config;

/**
 * Test TopUpService — kredit, customerVaName, alur start (payload inquiry),
 * callback idempoten (kredit sekali), dan reconcile ber-throttle. Tanpa jaringan;
 * config diarahkan ke direktori temp agar deterministik.
 */
class TopUpServiceTest extends TestCase
{
    private const API_KEY = 'secret-duitku-key-xyz';
    private const MERCHANT = 'DS12345';

    private string $tmp;
    private BillingStore $billing;
    private UserStore $users;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/topupsvc_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/runtime', 0777, true);

        $this->configState = $this->snapshotConfigState();
        $this->useConfig([]);

        $db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => '', 'email' => 'admin@example.com'],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => '', 'email' => 'budi@example.com'],
            ['id' => 'u3', 'username' => 'nomail', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->billing = new BillingStore($db);
        $this->users = new UserStore($db);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
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
                'sqlite_file' => $this->tmp . '/rames.sqlite',
                'database_path' => $this->tmp,
                'billing_topup_idr_per_credit' => 10.0,
                'billing_topup_min_idr' => 10000,
                'billing_topup_max_idr' => 5000000,
                'billing_topup_max_pending' => 3,
                'billing_topup_expiry_minutes' => 0,
                'billing_duitku_status_min_interval' => 900,
                'billing_ledger_keep' => 200,
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

    private function service(FakeDuitkuHandler $handler): TopUpService
    {
        $client = new DuitkuClient([
            'mode' => 'sandbox',
            'merchant_code' => self::MERCHANT,
            'api_key' => self::API_KEY,
            'callback_url' => 'https://dashboard.test/payments/duitku/callback',
            'return_url' => 'https://dashboard.test/credits/topup/return',
            'methods' => 'BC,VA,QR,VC',
            'method_ttl' => 3600,
            'runtime_path' => $this->tmp . '/runtime',
        ], new Client(['handler' => $handler, 'http_errors' => false]));

        return new TopUpService($this->billing, new CreditAccount($this->billing, $this->users), $client, $this->users);
    }

    /** Menyiapkan order pending sukses inquiry. */
    private function pendingOrder(TopUpService $service, FakeDuitkuHandler $handler, int $amount = 50000): array
    {
        $handler->reply(200, [
            'statusCode' => '00',
            'reference' => 'REF-DUITKU-1',
            'paymentUrl' => 'https://sandbox.duitku.com/pay/1',
        ]);

        return $service->start('u2', $amount, 'BC')['order'];
    }

    /** @return array<string,mixed> */
    private function callbackPayload(string $orderId, string|int $amount, string $resultCode = '00', string $reference = 'REF-DUITKU-1'): array
    {
        return [
            'merchantCode' => self::MERCHANT,
            'amount' => (string) $amount,
            'merchantOrderId' => $orderId,
            'resultCode' => $resultCode,
            'reference' => $reference,
            'signature' => DuitkuSignature::callback(self::MERCHANT, (string) $amount, $orderId, self::API_KEY),
        ];
    }

    public function testCreditsFor(): void
    {
        $service = $this->service(new FakeDuitkuHandler());

        $this->assertSame(5000.0, $service->creditsFor(50000));
        $this->assertSame(1000.0, $service->creditsFor(10000));
        $this->assertSame(0.1, $service->creditsFor(1));
    }

    public function testCustomerVaName(): void
    {
        $service = $this->service(new FakeDuitkuHandler());

        $this->assertSame('budi santoso', $service->customerVaName('budi santoso'));
        $this->assertSame('Rames User', $service->customerVaName(''));
        $this->assertSame('Rames User', $service->customerVaName('   '));
        $this->assertSame('Rames User', $service->customerVaName('@@@ ###'));

        $long = $service->customerVaName(str_repeat('a', 64));
        $this->assertSame(20, strlen($long));

        $emailish = $service->customerVaName('budi@example.com');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 ]+$/', $emailish);
        $this->assertLessThanOrEqual(20, strlen($emailish));
    }

    public function testStartBuildsInquiryPayloadAndAttachesPayment(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $handler->reply(200, [
            'statusCode' => '00',
            'reference' => 'REF-DUITKU-1',
            'paymentUrl' => 'https://sandbox.duitku.com/pay/1',
            'vaNumber' => '8808',
        ]);

        $result = $service->start('u2', 50000, 'bc');

        $this->assertSame('https://sandbox.duitku.com/pay/1', $result['payment_url']);
        $this->assertSame('pending', $result['order']['status']);
        $this->assertSame('REF-DUITKU-1', $result['order']['reference']);
        $this->assertSame('8808', $result['order']['va_number']);

        $body = $handler->body();
        $this->assertSame(self::MERCHANT, $body['merchantCode']);
        $this->assertSame(50000, $body['paymentAmount']);
        $this->assertSame($result['order']['id'], $body['merchantOrderId']);
        $this->assertSame('BC', $body['paymentMethod']);
        $this->assertSame('budi@example.com', $body['email']);
        $this->assertSame('budi', $body['customerVaName']);
        $this->assertSame('https://dashboard.test/payments/duitku/callback', $body['callbackUrl']);
        $this->assertSame('https://dashboard.test/credits/topup/return', $body['returnUrl']);
        $this->assertSame('budi', $body['merchantUserInfo']);
        $this->assertLessThanOrEqual(255, strlen((string) $body['productDetails']));
        $this->assertSame(
            DuitkuSignature::inquiry(self::MERCHANT, $result['order']['id'], 50000, self::API_KEY),
            $body['signature']
        );
        // PII & opsi yang tidak dipakai tidak boleh dikirim.
        $this->assertArrayNotHasKey('phoneNumber', $body);
        $this->assertArrayNotHasKey('expiryPeriod', $body, 'expiry 0 = field tidak dikirim');
        $this->assertArrayNotHasKey('itemDetails', $body);
        $this->assertArrayNotHasKey('customerDetail', $body);
        $this->assertStringNotContainsString(self::API_KEY, (string) json_encode($body));
    }

    public function testStartSendsExpiryWhenConfigured(): void
    {
        $this->useConfig(['billing_topup_expiry_minutes' => 30]);
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $handler->reply(200, ['statusCode' => '00', 'paymentUrl' => 'https://sandbox.duitku.com/pay/2']);

        $result = $service->start('u2', 50000, 'BC');

        $this->assertSame(30, $handler->body()['expiryPeriod']);
        $this->assertArrayHasKey('expires_at', $result['order']);
    }

    public function testStartRequiresValidEmail(): void
    {
        $service = $this->service(new FakeDuitkuHandler());

        try {
            $service->start('u3', 50000, 'BC');
            $this->fail('Semestinya melempar karena email kosong');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Email', $e->getMessage());
        }
    }

    public function testStartRejectsUnknownUser(): void
    {
        $service = $this->service(new FakeDuitkuHandler());
        $this->expectException(RuntimeException::class);
        $service->start('u-ghost', 50000, 'BC');
    }

    public function testStartRejectsDisallowedMethod(): void
    {
        $service = $this->service(new FakeDuitkuHandler());
        $this->expectException(InvalidArgumentException::class);
        $service->start('u2', 50000, 'VC');
    }

    public function testStartMarksOrderFailedOnInquiryError(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $handler->reply(401, ['statusMessage' => 'Wrong signature']);

        try {
            $service->start('u2', 50000, 'BC');
            $this->fail('Semestinya melempar DuitkuError');
        } catch (DuitkuError $e) {
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }

        $orders = $this->billing->orders();
        $this->assertCount(1, $orders);
        $order = array_values($orders)[0];
        $this->assertSame('failed', $order['status']);
        $this->assertStringNotContainsString(self::API_KEY, (string) json_encode($order));
    }

    public function testHandleCallbackRejectsInvalidInputs(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);
        $orderId = (string) $order['id'];

        $mismatchMerchant = $this->callbackPayload($orderId, 50000);
        $mismatchMerchant['merchantCode'] = 'OTHER';
        $this->assertSame('merchant_code_mismatch', $service->handleCallback($mismatchMerchant)['reason']);

        $badSig = $this->callbackPayload($orderId, 50000);
        $badSig['signature'] = 'deadbeef';
        $this->assertSame('invalid_signature', $service->handleCallback($badSig)['reason']);

        $unknown = $this->callbackPayload('RM-0000000000000000', 50000);
        $this->assertSame('unknown_order', $service->handleCallback($unknown)['reason']);

        $mismatchAmount = $this->callbackPayload($orderId, 60000);
        $this->assertSame('amount_mismatch', $service->handleCallback($mismatchAmount)['reason']);

        $unknownResult = $this->callbackPayload($orderId, 50000, '99');
        $this->assertSame('unknown_result_code', $service->handleCallback($unknownResult)['reason']);

        // Order tidak berubah (masih pending), saldo tetap 0.
        $this->assertSame('pending', $service->findForUser($orderId, 'u2')['status']);
        $this->assertSame(0.0, $service->balance('u2'));
    }

    public function testRateChangeAfterOrderKeepsCreditsFrozenAndRecordsPaidIdr(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);
        $orderId = (string) $order['id'];
        $this->assertSame(5000.0, (float) $order['credits'], 'order dibuat saat kurs 10');

        // Kurs diubah menjadi 1:1 sebelum pembayaran diselesaikan.
        $this->useConfig(['billing_topup_idr_per_credit' => 1.0]);

        $result = $service->handleCallback($this->callbackPayload($orderId, 50000));
        $this->assertTrue($result['ok']);
        $this->assertSame(5000.0, $result['credits'], 'kredit beku di order — TIDAK dihitung ulang dengan kurs baru');
        $this->assertSame(5000.0, $service->balance('u2'));

        $ledger = $service->ledger('u2', 5);
        $this->assertSame(5000.0, $ledger[0]['amount']);
        $this->assertSame(50000, $ledger[0]['idr'], 'nominal rupiah nyata tetap tercatat agar tampilan riwayat tidak menebak');
    }

    public function testHandleCallbackSuccessCreditsExactlyOnce(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);
        $orderId = (string) $order['id'];

        $first = $service->handleCallback($this->callbackPayload($orderId, 50000));
        $this->assertTrue($first['ok']);
        $this->assertSame('paid', $first['status']);
        $this->assertSame('u2', $first['user_id']);
        $this->assertSame(5000.0, $first['credits']);

        $this->assertSame(5000.0, $service->balance('u2'));
        $this->assertSame('paid', $service->findForUser($orderId, 'u2')['status']);

        $ledger = $service->ledger('u2', 5);
        $this->assertCount(1, $ledger);
        $this->assertSame('topup', $ledger[0]['type']);
        $this->assertSame(5000.0, $ledger[0]['amount']);
        $this->assertSame('u2', $ledger[0]['by']);
        $this->assertSame('Top-up Duitku', $ledger[0]['note']);
        $this->assertSame('REF-DUITKU-1', $ledger[0]['reference']);
        $this->assertSame(50000, $ledger[0]['idr'], 'nominal rupiah asli dicatat agar tak bergantung kurs saat ini');

        // Callback ganda (retry Duitku) = no-op.
        $second = $service->handleCallback($this->callbackPayload($orderId, 50000));
        $this->assertTrue($second['ok']);
        $this->assertSame('ignored', $second['status']);
        $this->assertSame(5000.0, $service->balance('u2'), 'saldo tidak bertambah dua kali');
        $this->assertCount(1, $service->ledger('u2', 5));
    }

    public function testHandleCallbackFailureMarksFailed(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);
        $orderId = (string) $order['id'];

        $failed = $service->handleCallback($this->callbackPayload($orderId, 50000, '01'));
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('failed', $service->findForUser($orderId, 'u2')['status']);
        $this->assertSame(0.0, $service->balance('u2'));
    }

    public function testHandleCallbackFailOnPaidOrderIsIgnored(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);
        $orderId = (string) $order['id'];

        $service->handleCallback($this->callbackPayload($orderId, 50000));
        $ignored = $service->handleCallback($this->callbackPayload($orderId, 50000, '01'));

        $this->assertSame('ignored', $ignored['status']);
        $this->assertSame('paid', $service->findForUser($orderId, 'u2')['status']);
        $this->assertSame(5000.0, $service->balance('u2'));
    }

    public function testHandleCallbackLateSuccessAfterFailureCreditsOnce(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);
        $orderId = (string) $order['id'];

        $service->handleCallback($this->callbackPayload($orderId, 50000, '01'));
        $late = $service->handleCallback($this->callbackPayload($orderId, 50000));

        $this->assertSame('paid', $late['status']);
        $this->assertSame(5000.0, $service->balance('u2'), 'uang nyata tetap dikreditkan sekali');
    }

    public function testReconcileSettlesAndCreditsThenThrottles(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);
        $orderId = (string) $order['id'];

        $handler->reply(200, ['statusCode' => '00', 'reference' => 'REF-DUITKU-1', 'amount' => '50000']);
        $result = $service->reconcile($orderId);

        $this->assertTrue($result['checked']);
        $this->assertTrue($result['changed']);
        $this->assertSame('paid', $result['status']);
        $this->assertSame(5000.0, $service->balance('u2'));

        // Order sudah paid → tidak dicek lagi.
        $again = $service->reconcile($orderId);
        $this->assertFalse($again['checked']);
        $this->assertFalse($again['changed']);
    }

    public function testReconcileHonorsThrottle(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);
        $orderId = (string) $order['id'];
        $callsAfterStart = $handler->count();

        // Catat pengecekan baru saja → reconcile berikutnya harus di-skip.
        $orders = new \app\library\Billing\TopUpOrder($this->billing);
        $orders->touchChecked($orderId, date('c'));

        $result = $service->reconcile($orderId);
        $this->assertFalse($result['checked']);
        $this->assertFalse($result['changed']);
        $this->assertSame($callsAfterStart, $handler->count(), 'throttle mencegah panggilan jaringan');
    }

    public function testReconcilePendingAndCanceled(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);

        $pending = $this->pendingOrder($service, $handler);
        $handler->reply(200, ['statusCode' => '01']);
        $result = $service->reconcile((string) $pending['id']);
        $this->assertTrue($result['checked']);
        $this->assertFalse($result['changed']);
        $this->assertSame('pending', $result['status']);

        $canceled = $this->pendingOrder($service, $handler);
        $handler->reply(200, ['statusCode' => '02']);
        $result2 = $service->reconcile((string) $canceled['id']);
        $this->assertTrue($result2['changed']);
        $this->assertSame('failed', $result2['status']);
    }

    public function testReconcileSwallowsNetworkError(): void
    {
        $handler = new FakeDuitkuHandler();
        $service = $this->service($handler);
        $order = $this->pendingOrder($service, $handler);

        $handler->fail(new ConnectException('timeout', new Request('POST', 'https://sandbox.duitku.com')));
        $result = $service->reconcile((string) $order['id']);

        $this->assertFalse($result['checked']);
        $this->assertFalse($result['changed']);
        $this->assertSame('pending', $result['status']);
    }

    public function testReconcileUnknownOrderThrows(): void
    {
        $service = $this->service(new FakeDuitkuHandler());
        $this->expectException(RuntimeException::class);
        $service->reconcile('RM-0000000000000000');
    }
}
