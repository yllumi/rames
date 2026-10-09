<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\DuitkuClient;
use app\library\Billing\DuitkuError;
use app\library\Billing\TopUpService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Higienitas kredensial Duitku: `billing_duitku_api_key` **tidak pernah** bocor ke
 * pesan exception, berkas log/state (JSON) yang ditulis, maupun berkas cache.
 *
 * Meniru pola `VolumeBackupCredentialHygieneTest`.
 */
class DuitkuCredentialHygieneTest extends TestCase
{
    private const API_KEY = 'super-secret-duitku-key-9f8e7d6c5b4a';
    private const MERCHANT = 'DS-HYGIENE';

    private string $tmp;
    private BillingStore $billing;
    private UserStore $users;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/duitkuhygiene_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/runtime', 0777, true);

        file_put_contents($this->tmp . '/auth.json', (string) json_encode([
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => '', 'email' => 'budi@example.com'],
        ]));

        $this->billing = new BillingStore($this->tmp . '/billing.json');
        $this->users = new UserStore($this->tmp . '/auth.json');
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

    private function service(FakeDuitkuHandler $handler): TopUpService
    {
        $client = new DuitkuClient([
            'mode' => 'sandbox',
            'merchant_code' => self::MERCHANT,
            'api_key' => self::API_KEY,
            'callback_url' => 'https://dashboard.test/payments/duitku/callback',
            'return_url' => 'https://dashboard.test/credits/topup/return',
            'methods' => 'BC,VA',
            'method_ttl' => 3600,
            'runtime_path' => $this->tmp . '/runtime',
        ], new Client(['handler' => $handler, 'http_errors' => false]));

        return new TopUpService($this->billing, new CreditAccount($this->billing, $this->users), $client, $this->users);
    }

    /**
     * Pastikan string rahasia tidak muncul di berkas mana pun di bawah `$dir`.
     */
    private function assertKeyAbsentFromTree(string $dir): void
    {
        $this->assertDirectoryExists($dir);
        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (str_contains($content, self::API_KEY)) {
                $found[] = $file->getPathname();
            }
        }
        $this->assertSame([], $found, 'API key bocor ke berkas: ' . implode(', ', $found));
    }

    public function testApiKeyNeverLeaksInErrorMessages(): void
    {
        $cases = [
            'http 401' => static fn (FakeDuitkuHandler $h) => $h->reply(401, ['statusMessage' => 'Wrong signature ' . self::API_KEY]),
            'http 500' => static fn (FakeDuitkuHandler $h) => $h->reply(500, ['statusMessage' => 'boom ' . self::API_KEY]),
            'timeout' => static fn (FakeDuitkuHandler $h) => $h->fail(
                new ConnectException('cURL error 28 ' . self::API_KEY, new Request('POST', 'https://sandbox.duitku.com'))
            ),
        ];

        foreach ($cases as $label => $prime) {
            $handler = new FakeDuitkuHandler();
            $prime($handler);
            $service = $this->service($handler);

            try {
                $service->start('u2', 50000, 'BC');
                $this->fail('Semestinya melempar DuitkuError (' . $label . ')');
            } catch (DuitkuError $e) {
                $this->assertStringNotContainsString(self::API_KEY, $e->getMessage(), $label);
                $this->assertStringNotContainsString(self::API_KEY, $e->duitkuMessage, $label);
                $this->assertStringNotContainsString(self::API_KEY, (string) $e, $label);
            }

            // State & berkas apa pun yang ditulis tidak memuat rahasia.
            $this->assertKeyAbsentFromTree($this->tmp);
            $this->assertStringNotContainsString(self::API_KEY, (string) json_encode($this->billing->orders()));
        }
    }

    public function testApiKeyAbsentFromStateAndCacheOnSuccess(): void
    {
        $handler = new FakeDuitkuHandler();
        $client = new DuitkuClient([
            'mode' => 'sandbox',
            'merchant_code' => self::MERCHANT,
            'api_key' => self::API_KEY,
            'callback_url' => 'https://dashboard.test/payments/duitku/callback',
            'return_url' => 'https://dashboard.test/credits/topup/return',
            'methods' => 'BC,VA',
            'method_ttl' => 3600,
            'runtime_path' => $this->tmp . '/runtime',
        ], new Client(['handler' => $handler, 'http_errors' => false]));
        $service = new TopUpService(
            $this->billing,
            new CreditAccount($this->billing, $this->users),
            $client,
            $this->users
        );

        // inquiry sukses + katalog metode sukses (menulis cache).
        $handler->reply(200, ['statusCode' => '00', 'paymentUrl' => 'https://sandbox.duitku.com/pay/hyg']);
        $handler->reply(200, [
            'responseCode' => '00',
            'paymentFee' => [['paymentMethod' => 'BC', 'paymentName' => 'BCA', 'paymentImage' => 'bc.png', 'totalFee' => 4000]],
        ]);

        $result = $service->start('u2', 50000, 'BC');
        $methods = $client->paymentMethods(50000);
        $this->assertSame(['BC'], array_column($methods, 'code'));

        $order = $service->findForUser((string) $result['order']['id'], 'u2');
        $this->assertNotNull($order);
        $this->assertStringNotContainsString(self::API_KEY, (string) json_encode($order));

        // Berkas state + cache ada, tetapi tanpa rahasia.
        $this->assertFileExists($this->tmp . '/billing.json');
        $this->assertFileExists($this->tmp . '/runtime/duitku-methods.json');
        $this->assertKeyAbsentFromTree($this->tmp);
    }
}
