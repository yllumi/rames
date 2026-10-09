<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\DuitkuClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * Test katalog metode pembayaran Duitku: normalisasi, allowlist + hard-exclude,
 * cache file (TTL), pemakaian cache kedaluwarsa, dan fallback statis.
 * Tanpa jaringan.
 */
class DuitkuMethodCatalogTest extends TestCase
{
    private string $tmp;
    private string $runtime;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/duitkucatalog_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->runtime = $this->tmp . '/runtime';
        mkdir($this->runtime, 0777, true);
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
     */
    private function client(FakeDuitkuHandler $handler, array $overrides = []): DuitkuClient
    {
        $spec = array_merge([
            'merchant_code' => 'DS12345',
            'api_key' => 'secret-key',
            'callback_url' => 'https://dashboard.test/payments/duitku/callback',
            'return_url' => 'https://dashboard.test/credits/topup/return',
            'methods' => 'BC,VA,VC,T1,OL,QR',
            'method_ttl' => 3600,
            'runtime_path' => $this->runtime,
        ], $overrides);

        return new DuitkuClient($spec, new Client(['handler' => $handler, 'http_errors' => false]));
    }

    public function testNormalizesAndFiltersByAllowlistAndHardExclude(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->reply(200, [
            'responseCode' => '00',
            'paymentFee' => [
                ['paymentMethod' => 'BC', 'paymentName' => 'BCA Virtual Account', 'paymentImage' => 'bc.png', 'totalFee' => '4000'],
                ['paymentMethod' => 'VA', 'paymentName' => 'Virtual Account', 'paymentImage' => 'va.png', 'totalFee' => 0],
                // Kanal kartu/paylater/account-link WAJIB dikecualikan walau di allowlist.
                ['paymentMethod' => 'VC', 'paymentName' => 'Credit Card', 'paymentImage' => 'vc.png', 'totalFee' => 100],
                ['paymentMethod' => 'T1', 'paymentName' => 'Paylater', 'paymentImage' => 't1.png', 'totalFee' => 0],
                ['paymentMethod' => 'OL', 'paymentName' => 'Account Link', 'paymentImage' => 'ol.png', 'totalFee' => 0],
                // Di luar allowlist.
                ['paymentMethod' => 'XX', 'paymentName' => 'Unknown', 'paymentImage' => '', 'totalFee' => 0],
                // Duplikat harus dedupe.
                ['paymentMethod' => 'QR', 'paymentName' => 'QRIS', 'paymentImage' => 'qr.png', 'totalFee' => 0],
                ['paymentMethod' => 'qr', 'paymentName' => 'QRIS dup', 'paymentImage' => 'qr2.png', 'totalFee' => 0],
            ],
        ]);

        $client = $this->client($handler);
        $methods = $client->paymentMethods(50000);

        $this->assertSame(['BC', 'VA', 'QR'], array_column($methods, 'code'));
        $this->assertSame('BCA Virtual Account', $methods[0]['name']);
        $this->assertSame('bc.png', $methods[0]['image']);
        $this->assertSame(4000, $methods[0]['fee']);
        $this->assertSame('QRIS', $methods[2]['name'], 'duplikat: entri pertama yang menang');
        // 1 panggilan saja + cache ditulis.
        $this->assertSame(1, $handler->count());
        $this->assertFileExists($this->runtime . '/duitku-methods.json');
    }

    public function testFreshCacheAvoidsSecondRequest(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->reply(200, [
            'responseCode' => '00',
            'paymentFee' => [['paymentMethod' => 'BC', 'paymentName' => 'BCA', 'paymentImage' => '', 'totalFee' => 0]],
        ]);

        $client = $this->client($handler);
        $first = $client->paymentMethods(50000);
        $second = $client->paymentMethods(50000);

        $this->assertSame($first, $second);
        $this->assertSame(1, $handler->count(), 'cache segar tidak memanggil jaringan lagi');
    }

    public function testStaleCacheIsUsedOnFailure(): void
    {
        file_put_contents($this->runtime . '/duitku-methods.json', (string) json_encode([
            'at' => 1,
            'methods' => [
                ['code' => 'BC', 'name' => 'BCA (cached)', 'image' => 'c.png', 'fee' => 5],
                ['code' => 'VC', 'name' => 'Credit Card', 'image' => '', 'fee' => 0],
            ],
        ]));

        $handler = new FakeDuitkuHandler();
        $handler->fail(new ConnectException('timeout', new Request('POST', 'https://sandbox.duitku.com')));

        // TTL 0 memaksa refresh → gagal → kembali ke cache kedaluwarsa.
        $client = $this->client($handler, ['method_ttl' => 0]);
        $methods = $client->paymentMethods(50000);

        $this->assertSame(['BC'], array_column($methods, 'code'));
        $this->assertSame('BCA (cached)', $methods[0]['name']);
        $this->assertSame(1, $handler->count());
    }

    public function testFallbackStaticListWhenNoCache(): void
    {
        $handler = new FakeDuitkuHandler();
        $handler->fail(new ConnectException('timeout', new Request('POST', 'https://sandbox.duitku.com')));

        $client = $this->client($handler, ['methods' => 'BC,VA,VC,OL']);
        $methods = $client->paymentMethods(50000);

        $this->assertSame(['BC', 'VA'], array_column($methods, 'code'));
        foreach ($methods as $method) {
            $this->assertSame($method['code'], $method['name']);
            $this->assertSame('', $method['image']);
            $this->assertSame(0, $method['fee']);
        }
    }

    public function testEmptyWhenNotConfigured(): void
    {
        $handler = new FakeDuitkuHandler();
        $client = $this->client($handler, ['api_key' => '']);

        $this->assertSame([], $client->paymentMethods(50000));
        $this->assertSame(0, $handler->count(), 'tanpa konfigurasi tidak boleh memanggil jaringan');
    }

    public function testIsAllowedMethod(): void
    {
        $handler = new FakeDuitkuHandler();
        $client = $this->client($handler);

        $this->assertTrue($client->isAllowedMethod('bc'));
        $this->assertTrue($client->isAllowedMethod('QR'));
        $this->assertFalse($client->isAllowedMethod('VC'), 'hard-exclude walau di allowlist');
        $this->assertFalse($client->isAllowedMethod('OL'));
        $this->assertFalse($client->isAllowedMethod('XX'));
    }
}
