<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ServerResolver;
use app\library\SSL\SslDomainRows;
use app\library\SSL\SslQueue;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Webman\Config;

// Helper webman (`config()`, `app_subdomain_of()`) hanya dimuat di runtime
// webman: definisikan konstanta + fungsi sebelum kelas dipakai.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain_of')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Test {@see SslDomainRows} — pemetaan store → baris tabel "Semua Domain".
 *
 * Kelas yang diuji statik murni (tanpa otorisasi/current_user). Namun
 * `app_subdomain_of()` membaca registry server lewat config, jadi config
 * diarahkan ke direktori temp dan `database/` nyata **tidak** disentuh.
 *
 * Yang dikunci:
 *  - app tanpa host port **tidak** menghasilkan baris;
 *  - tanpa `custom_domain` ⇒ satu baris subdomain `serve` slot `ssl`;
 *  - dengan `custom_domain` ⇒ dua baris berurutan: custom (`serve`,
 *    `custom_ssl`) lalu subdomain (`redirect`, `ssl`) — keduanya status dari
 *    slot yang berbeda;
 *  - baris domain parkir `kind='parked'`, tanpa app, `ssl_status` default
 *    `disabled`;
 *  - setiap baris memuat `ssl_queue_position`/`ssl_queue_size` (null kecuali
 *    baris `pending` yang punya job aktif di antrean SSL).
 */
class SslDomainRowsTest extends TestCase
{
    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
        ServerResolver::forgetMemo();
        SqliteDatabase::reset();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        $this->removeTree($this->tmp);
        $this->tmp = '';
    }

    public function testAppWithoutHostPortIsSkipped(): void
    {
        $app = [
            'id' => 'app_tanpa_port',
            'name' => 'tanpa-port',
            'containers' => [],
        ];

        $this->assertSame([], SslDomainRows::appRows([$app]));
    }

    public function testSubdomainOnlyProducesOneServeRow(): void
    {
        $app = [
            'id' => 'app_satu',
            'name' => 'blog',
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
            'ssl_status' => 'active',
            'ssl_message' => 'Aktif',
            'ssl_expires_at' => '2026-06-01T00:00:00+00:00',
            'ssl_error' => null,
        ];

        $rows = SslDomainRows::appRows([$app]);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('app', $row['kind']);
        $this->assertSame('app_satu', $row['id']);
        $this->assertSame('app_satu', $row['app_id']);
        $this->assertSame('blog', $row['app_name']);
        $this->assertSame('blog.example.org', $row['domain']);
        $this->assertFalse($row['is_custom']);
        $this->assertSame('serve', $row['role']);
        $this->assertSame('ssl', $row['ssl_slot']);
        $this->assertSame('active', $row['ssl_status']);
        $this->assertSame('Aktif', $row['ssl_message']);
        $this->assertSame('2026-06-01T00:00:00+00:00', $row['ssl_expires_at']);
        $this->assertNull($row['ssl_error']);
        $this->assertSame($app, $row['app'], 'objek app dipertahankan untuk view lama');
    }

    public function testCustomDomainProducesServeThenRedirectRows(): void
    {
        $app = [
            'id' => 'app_dua',
            'name' => 'toko',
            'containers' => [['host_port' => 30002, 'internal_port' => 80]],
            'custom_domain' => 'toko.example.net',
            'custom_ssl_status' => 'active',
            'custom_ssl_message' => 'Sertifikat custom aktif',
            'custom_ssl_expires_at' => '2026-07-01T00:00:00+00:00',
            'ssl_status' => 'failed',
            'ssl_error' => 'redirect belum siap',
        ];

        $rows = SslDomainRows::appRows([$app]);

        $this->assertCount(2, $rows, 'custom + subdomain = dua baris');

        $custom = $rows[0];
        $this->assertSame('toko.example.net', $custom['domain']);
        $this->assertTrue($custom['is_custom']);
        $this->assertSame('serve', $custom['role']);
        $this->assertSame('custom_ssl', $custom['ssl_slot']);
        $this->assertSame('active', $custom['ssl_status']);
        $this->assertSame('Sertifikat custom aktif', $custom['ssl_message']);
        $this->assertSame('2026-07-01T00:00:00+00:00', $custom['ssl_expires_at']);
        $this->assertNull($custom['ssl_error'], 'status custom tidak boleh membaca slot ssl');

        $subdomain = $rows[1];
        $this->assertSame('toko.example.org', $subdomain['domain']);
        $this->assertFalse($subdomain['is_custom']);
        $this->assertSame('redirect', $subdomain['role']);
        $this->assertSame('ssl', $subdomain['ssl_slot']);
        $this->assertSame('failed', $subdomain['ssl_status']);
        $this->assertSame('redirect belum siap', $subdomain['ssl_error']);
    }

    public function testParkedRowsMapSslFieldsAndDefaultDisabled(): void
    {
        $parked = [
            [
                'id' => 'pd_aaaaaaaaaaaa',
                'domain' => 'parkir.example.com',
                'mode' => 'redirect',
                'ssl_status' => 'active',
                'ssl_stage' => null,
                'ssl_message' => 'Aktif',
                'ssl_error' => null,
                'ssl_expires_at' => '2026-08-01T00:00:00+00:00',
            ],
            [
                'id' => 'pd_bbbbbbbbbbbb',
                'domain' => 'statis.example.com',
                'mode' => 'html',
            ],
        ];

        $rows = SslDomainRows::parkedRows($parked);

        $this->assertCount(2, $rows);

        $first = $rows[0];
        $this->assertSame('parked', $first['kind']);
        $this->assertSame('pd_aaaaaaaaaaaa', $first['id']);
        $this->assertSame('parkir.example.com', $first['domain']);
        $this->assertSame('', $first['app_id']);
        $this->assertSame('', $first['app_name']);
        $this->assertFalse($first['is_custom']);
        $this->assertSame('parkir', $first['role']);
        $this->assertSame('redirect', $first['mode']);
        $this->assertSame('active', $first['ssl_status']);
        $this->assertArrayNotHasKey('app', $first, 'baris parkir bukan app');

        $second = $rows[1];
        $this->assertSame('disabled', $second['ssl_status'], 'entri tanpa status SSL = disabled');
        $this->assertNull($second['ssl_message']);
        $this->assertNull($second['ssl_error']);
        $this->assertNull($second['ssl_expires_at']);
    }

    public function testEveryRowCarriesQueueFieldsAsNullWithoutActiveJob(): void
    {
        $rows = SslDomainRows::appRows([[
            'id' => 'app_tanpa_antre',
            'name' => 'tanpa-antre',
            'containers' => [['host_port' => 30010, 'internal_port' => 80]],
            'ssl_status' => 'pending',
        ]]);

        $this->assertNull($rows[0]['ssl_queue_position'], 'pending tanpa job aktif = tanpa posisi');
        $this->assertNull($rows[0]['ssl_queue_size']);
    }

    public function testPendingRowWithActiveJobCarriesQueuePosition(): void
    {
        $app = [
            'id' => 'app_antre',
            'name' => 'antre',
            'containers' => [['host_port' => 30011, 'internal_port' => 80]],
            'ssl_status' => 'pending',
        ];
        (new SslQueue(new SqliteDatabase($this->tmp . '/rames.sqlite')))
            ->enqueueApp($app, 'antre.example.org', 'ssl');

        $rows = SslDomainRows::appRows([$app]);

        $this->assertSame(1, $rows[0]['ssl_queue_position']);
        $this->assertSame(1, $rows[0]['ssl_queue_size']);
    }

    public function testParkedPendingRowIsDecoratedFromItsOwnDedupeKey(): void
    {
        (new SslQueue(new SqliteDatabase($this->tmp . '/rames.sqlite')))
            ->enqueueParked(['id' => 'pd_aaaaaaaaaaaa', 'domain' => 'parkir-queued.example.com']);

        $rows = SslDomainRows::parkedRows([[
            'id' => 'pd_aaaaaaaaaaaa',
            'domain' => 'parkir-queued.example.com',
            'mode' => 'html',
            'ssl_status' => 'pending',
        ]]);

        $this->assertSame(1, $rows[0]['ssl_queue_position']);
        $this->assertSame(1, $rows[0]['ssl_queue_size']);
    }

    // ==================================================================
    // Config temp (registry server dibaca `app_subdomain_of()`)
    // ==================================================================

    private function useConfig(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-ssldomainrows-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);

        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'app_domain' => 'example.org',
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
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

    private function removeTree(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
