<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\DuitkuClient;
use app\library\Billing\DuitkuError;
use app\library\Billing\DuitkuSignature;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * Test DuitkuClient — base URL per mode, kelengkapan konfigurasi, cap timeout,
 * penandatanganan payload, pemetaan error HTTP, dan sanitasi kredensial.
 * Tanpa jaringan (handler tiruan).
 */
class DuitkuClientTest extends TestCase
{
    private string $tmp;
    private string $apiKey = 'secret-duitku-api-key-abc123';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/duitkuclient_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
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
     * @return array<string,mixed>
     */
    private function spec(array $overrides = []): array
    {
        return array_merge([
            'merchant_code' => 'DS12345',
            'api_key' => $this->apiKey,
            'callback_url' => 'https://dashboard.test/payments/duitku/callback',
            'return_url' => 'https://dashboard.test/credits/topup/return',
            'methods' => 'BC,VA,VC,T1,OL,QR',
            'method_ttl' => 3600,
            'runtime_path' => $this->tmp . '/runtime',
            'timeout' => 10,
        ], $overrides);
    }

    private function client(FakeDuitkuHandler $handler, array $spec): DuitkuClient
    {
        return new DuitkuClient($spec, new Client(['handler' => $handler, 'http_errors' => false]));
    }

    public function testBaseUrlFollowsMode(): void
    {
        $handler = new FakeDuitkuHandler();
        $sandbox = $this->client($handler, $this->spec(['mode' => 'sandbox']));
        $production = $this->client($handler, $this->spec(['mode' => 'production']));

        $this->assertSame('sandbox', $sandbox->mode());
        $this->assertSame(DuitkuClient::SANDBOX_BASE_URL, $sandbox->baseUrl());
        $this->assertSame(DuitkuClient::PRODUCTION_BASE_URL, $production->baseUrl());
        $this->assertSame('production', $production->mode());
        // Mode tak dikenal → sandbox.
        $this->assertSame('sandbox', $this->client($handler, $this->spec(['mode' => 'ngawur']))->mode());
    }

    public function testExplicitBaseUrlOverridesMode(): void
    {
        $handler = new FakeDuitkuHandler();
        $client = $this->client($handler, $this->spec(['mode' => 'sandbox', 'base_url' => 'https://proxy.test/api/']));

        $this->assertSame('https://proxy.test/api', $client->baseUrl());
    }

    public function testTimeoutIsCapped(): void
    {
        $handler = new FakeDuitkuHandler();
        $this->assertSame(30, $this->client($handler, $this->spec(['timeout' => 999]))->timeout());
        $this->assertSame(1, $this->client($handler, $this->spec(['timeout' => 0]))->timeout());
        $this->assertSame(15, $this->client($handler, $this->spec(['timeout' => 15]))->timeout());
    }

    public function testIsConfiguredMatrix(): void
    {
        $handler = new FakeDuitkuHandler();

        $this->assertTrue($this->client($handler, $this->spec())->isConfigured());
        $this->assertFalse($this->client($handler, $this->spec(['merchant_code' => '']))->isConfigured());
        $this->assertFalse($this->client($handler, $this->spec(['api_key' => '']))->isConfigured());
        // callback WAJIB https.
        $this->assertFalse($this->client($handler, $this->spec(['callback_url' => 'http://dashboard.test/cb']))->isConfigured());
        $this->assertFalse($this->client($handler, $this->spec(['callback_url' => '/relative']))->isConfigured());
        // return URL absen ⇒ diturunkan dari origin callback URL (bukan 404).
        $this->assertTrue($this->client($handler, $this->spec(['return_url' => '']))->isConfigured());
        $this->assertSame(
            'https://dashboard.test/credits/topup/return',
            $this->client($handler, $this->spec(['return_url' => '']))->returnUrl()
        );
        // return URL eksplisit yang tidak absolut tetap ditolak.
        $this->assertFalse($this->client($handler, $this->spec(['return_url' => 'not a url']))->isConfigured());
        // Callback tak absolut ⇒ return URL tidak bisa diturunkan ⇒ belum lengkap.
        $this->assertFalse($this->client($handler, $this->spec(['callback_url' => '/relative', 'return_url' => '']))->isConfigured());
    }

    public function testReturnUrlDerivesCallbackOriginIncludingPort(): void
    {
        $handler = new FakeDuitkuHandler();
        $client = $this->client($handler, $this->spec([
            'callback_url' => 'https://dashboard.test:8443/payments/duitku/callback',
            'return_url' => '',
        ]));

        $this->assertSame('https://dashboard.test:8443/credits/topup/return', $client->returnUrl());
    }

    public function testConfigurationIssuesMatrix(): void
    {
        $handler = new FakeDuitkuHandler();

        // Lengkap & https → tidak ada masalah.
        $this->assertSame([], $this->client($handler, $this->spec())->configurationIssues());

        // http:// ditolak secara default (produksi wajib https)…
        $http = $this->client($handler, $this->spec(['callback_url' => 'http://localhost:8123/cb']));
        $this->assertFalse($http->isConfigured());
        $this->assertCount(1, $http->configurationIssues());
        $this->assertStringContainsString('https', $http->configurationIssues()[0]);

        // …tetapi boleh bila opt-in eksplisit untuk uji lokal.
        $local = $this->client($handler, $this->spec([
            'callback_url' => 'http://localhost:8123/payments/duitku/callback',
            'allow_http' => true,
        ]));
        $this->assertTrue($local->allowsHttp());
        $this->assertTrue($local->isConfigured());
        $this->assertSame([], $local->configurationIssues());

        // Kredensial kosong disebut satu per satu.
        $empty = $this->client($handler, $this->spec(['merchant_code' => '', 'api_key' => '']));
        $issues = $empty->configurationIssues();
        $this->assertCount(2, $issues);
        $this->assertStringContainsString('MERCHANT_CODE', $issues[0]);
        $this->assertStringContainsString('API_KEY', $issues[1]);

        // Return URL eksplisit yang tidak absolut.
        $badReturn = $this->client($handler, $this->spec(['return_url' => 'bukan-url']));
        $this->assertFalse($badReturn->isConfigured());
        $this->assertStringContainsString('kembali', implode(' ', $badReturn->configurationIssues()));
    }

    public function testInquiryPostsSignedJsonWithoutSecrets(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->reply(200, [
            'statusCode' => '00',
            'statusMessage' => 'SUCCESS',
            'reference' => 'REF-1',
            'paymentUrl' => 'https://sandbox.duitku.com/pay/REF-1',
            'vaNumber' => '8808',
            'amount' => 50000,
        ]);

        $client = $this->client($handler, $this->spec());
        $payload = [
            'merchantCode' => 'DS12345',
            'paymentAmount' => 50000,
            'merchantOrderId' => 'RM-abcdef',
            'paymentMethod' => 'BC',
            'signature' => DuitkuSignature::inquiry('DS12345', 'RM-abcdef', 50000, $this->apiKey),
        ];
        $response = $client->inquiry($payload);

        $this->assertSame('00', $response['statusCode']);
        $this->assertSame(1, $handler->count());
        $this->assertSame('POST', $handler->calls()[0]['method']);
        $this->assertSame(DuitkuClient::SANDBOX_BASE_URL . '/v2/inquiry', $handler->uri());
        $body = $handler->body();
        $this->assertSame('DS12345', $body['merchantCode']);
        $this->assertSame(50000, $body['paymentAmount']);
        $this->assertSame($payload['signature'], $body['signature']);
        // API key tidak pernah masuk payload.
        $this->assertStringNotContainsString($this->apiKey, (string) json_encode($body));
    }

    public function testInquiryHttpErrorMapping(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->reply(400, ['statusMessage' => 'Bad Request']);
        $handler->reply(401, ['statusMessage' => 'Wrong signature']);
        $handler->reply(404, ['statusMessage' => 'Merchant not found']);
        $handler->reply(409, ['statusMessage' => 'Payment amount must be equal to all item price']);

        $client = $this->client($handler, $this->spec());

        foreach ([400, 401, 404, 409] as $status) {
            try {
                $client->inquiry(['merchantCode' => 'DS12345']);
                $this->fail('Semestinya melempar DuitkuError untuk HTTP ' . $status);
            } catch (DuitkuError $e) {
                $this->assertSame($status, $e->httpStatus);
                $this->assertStringContainsString((string) $status, $e->getMessage());
                $this->assertStringNotContainsString($this->apiKey, $e->getMessage());
            }
        }
    }

    public function testInquiryNonSuccessStatusCodeThrows(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->reply(200, ['statusCode' => '01', 'statusMessage' => 'Pending']);

        $client = $this->client($handler, $this->spec());

        $this->expectException(DuitkuError::class);
        $client->inquiry(['merchantCode' => 'DS12345']);
    }

    public function testInquiryRequiresConfiguration(): void
    {
        $handler = new FakeDuitkuHandler();
        $client = $this->client($handler, $this->spec(['api_key' => '']));

        try {
            $client->inquiry(['merchantCode' => 'DS12345']);
            $this->fail('Semestinya melempar');
        } catch (DuitkuError $e) {
            $this->assertSame(0, $e->httpStatus);
            $this->assertSame(0, $handler->count(), 'tanpa konfigurasi tidak boleh memanggil jaringan');
        }
    }

    public function testNetworkFailureIsSanitized(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->fail(new ConnectException('cURL error 28: timed out', new Request('POST', 'https://sandbox.duitku.com')));

        $client = $this->client($handler, $this->spec());

        try {
            $client->inquiry(['merchantCode' => 'DS12345']);
            $this->fail('Semestinya melempar');
        } catch (DuitkuError $e) {
            $this->assertSame(0, $e->httpStatus);
            $this->assertStringContainsString('Gagal menghubungi Duitku', $e->getMessage());
        }
    }

    public function testErrorMessageNeverLeaksApiKeyEvenIfResponseEchoesIt(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->reply(500, ['statusMessage' => 'internal error with key ' . $this->apiKey]);

        $client = $this->client($handler, $this->spec());

        try {
            $client->inquiry(['merchantCode' => 'DS12345']);
            $this->fail('Semestinya melempar');
        } catch (DuitkuError $e) {
            $this->assertSame(500, $e->httpStatus);
            $this->assertStringNotContainsString($this->apiKey, $e->getMessage());
            $this->assertStringNotContainsString($this->apiKey, $e->duitkuMessage);
        }
    }

    public function testTransactionStatusPostsSignedBody(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->reply(200, ['statusCode' => '00', 'reference' => 'REF-1', 'amount' => '50000']);

        $client = $this->client($handler, $this->spec());
        $response = $client->transactionStatus('RM-abcdef');

        $this->assertSame('00', $response['statusCode']);
        $this->assertSame(DuitkuClient::SANDBOX_BASE_URL . '/transactionStatus', $handler->uri());
        $body = $handler->body();
        $this->assertSame('DS12345', $body['merchantCode']);
        $this->assertSame('RM-abcdef', $body['merchantOrderId']);
        $this->assertSame(DuitkuSignature::status('DS12345', 'RM-abcdef', $this->apiKey), $body['signature']);
    }
}
