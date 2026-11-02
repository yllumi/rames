<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ServerAddress;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\StoreDbFiles;
use Webman\Config;

// Helper webman (`config()`) hanya dimuat di runtime webman: definisikan
// konstanta + fungsi sebelum kelas dipakai (pola `tests/AppFilterTest.php`).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('config')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Test {@see ServerAddress} — satu sumber alamat server pemilik app (target DNS
 * record custom domain) yang **murni komputasi, tanpa I/O jaringan**.
 *
 * Latar belakang temuan verifier: host-lookup DNS tanpa batas waktu (±4 s di
 * container pada instalasi default tanpa `base_url`) memblokir worker Webman
 * persistent setiap `GET /apps/{id}`. Kelas ini kini tidak boleh menyentuh
 * resolver DNS sama sekali; tidak ada test di sini yang memanggil DNS nyata —
 * seluruh keputusan berasal dari registry (store di path temp).
 *
 * Yang dikunci:
 *  - `base_url` IP publik ⇒ IP itu; IP privat ⇒ `''` (GOTCHA no. 25);
 *  - `base_url` hostname publik ⇒ hostname dikembalikan **apa adanya** (tanpa
 *    resolusi), hostname internal ⇒ `''`;
 *  - field eksplisit `public_ip`/`address`/`ip` dipakai lebih dulu & hanya bila
 *    publik (privat ⇒ dilewati, jatuh ke `base_url` atau `''`);
 *  - server lokal tanpa `base_url`/field ⇒ `''` (jalur ini dulu yang memicu
 *    blocking DNS);
 *  - {@see ServerAddress::recordType()} memilih A/AAAA.
 *
 * Semua store di path temp (`sys_get_temp_dir()`); `database/`, `apps/`,
 * `nginx-status/` nyata tidak pernah disentuh.
 */
class ServerAddressTest extends TestCase
{
    private const PUBLIC_IP = '203.0.113.10';
    private const PRIVATE_IP = '192.168.1.20';

    private string $tmp = '';

    private string $file = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->configState = $this->snapshotConfigState();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        StoreDbFiles::remove($this->file);
        self::removeTree($this->tmp);
        $this->tmp = '';
        $this->file = '';
    }

    // ------------------------------------------------------------------
    // Agent: base_url
    // ------------------------------------------------------------------

    public function testAgentWithPublicIpBaseUrlReturnsThatIp(): void
    {
        $server = $this->store()->create([
            'name' => 'vps-publik',
            'kind' => 'agent',
            'base_url' => 'https://' . self::PUBLIC_IP . ':8443',
        ]);

        $this->assertSame(self::PUBLIC_IP, ServerAddress::forApp($this->app($server['id']), $this->resolver()));
    }

    public function testAgentWithPublicHostnameIsReturnedAsIsWithoutResolving(): void
    {
        $server = $this->store()->create([
            'name' => 'vps-nama',
            'kind' => 'agent',
            'base_url' => 'https://agent.rames-publik.id:8443',
        ]);

        $this->assertSame(
            'agent.rames-publik.id',
            ServerAddress::forApp($this->app($server['id']), $this->resolver()),
            'hostname publik boleh tampil apa adanya — UI menyebutnya "alamat", bukan selalu IPv4'
        );
    }

    public function testAgentWithPrivateIpBaseUrlReturnsEmptyString(): void
    {
        $server = $this->store()->create([
            'name' => 'vps-privat',
            'kind' => 'agent',
            'base_url' => 'https://10.0.0.5:8443',
        ]);

        $this->assertSame(
            '',
            ServerAddress::forApp($this->app($server['id']), $this->resolver()),
            'IP privat tidak boleh dikembalikan (GOTCHA no. 25)'
        );
    }

    public function testAgentWithInternalHostnameIsRejected(): void
    {
        $server = $this->store()->create([
            'name' => 'vps-internal',
            'kind' => 'agent',
            'base_url' => 'https://agent.rames.internal:8443',
        ]);

        $this->assertSame('', ServerAddress::forApp($this->app($server['id']), $this->resolver()));
    }

    // ------------------------------------------------------------------
    // Field eksplisit pada baris server
    // ------------------------------------------------------------------

    /**
     * Instalasi default (server lokal, tanpa `base_url`) dulu memicu
     * host-lookup DNS ±4 s di jalur render. Sekarang: tanpa data ⇒ `''`,
     * tanpa menunggu resolver apa pun.
     */
    public function testLocalServerWithoutBaseUrlOrExplicitFieldReturnsEmptyString(): void
    {
        $this->store()->local();
        ServerResolver::forgetMemo();

        $this->assertSame('', ServerAddress::forApp(['name' => 'blog', 'subdomain' => 'blog-x7k2'], $this->resolver()));
    }

    public function testExplicitPublicIpIsUsedEvenWhenBaseUrlIsEmpty(): void
    {
        $this->store()->local();
        $this->store()->update(ServerStore::LOCAL_ID, static function (array &$server): void {
            $server['public_ip'] = self::PUBLIC_IP;
        });
        ServerResolver::forgetMemo();

        $this->assertSame(self::PUBLIC_IP, ServerAddress::forApp(['name' => 'blog'], $this->resolver()));
    }

    public function testExplicitAddressFieldWinsOverOtherFields(): void
    {
        $this->store()->local();
        $this->store()->update(ServerStore::LOCAL_ID, static function (array &$server): void {
            $server['address'] = '2001:db8::1';
            $server['public_ip'] = self::PRIVATE_IP;
            $server['ip'] = '10.1.2.3';
        });
        ServerResolver::forgetMemo();

        $this->assertSame('2001:db8::1', ServerAddress::forApp(['name' => 'blog'], $this->resolver()));
    }

    public function testPrivateExplicitIpIsIgnoredAndFallsBackToBaseUrl(): void
    {
        $this->store()->local();
        $this->store()->update(ServerStore::LOCAL_ID, static function (array &$server): void {
            $server['public_ip'] = self::PRIVATE_IP;
            $server['base_url'] = 'https://' . self::PUBLIC_IP . ':8443';
        });
        ServerResolver::forgetMemo();

        $this->assertSame(
            self::PUBLIC_IP,
            ServerAddress::forApp(['name' => 'blog'], $this->resolver()),
            'field privat diabaikan; kandidat berikutnya (host base_url) yang dipakai'
        );
    }

    public function testPrivateExplicitIpWithoutBaseUrlReturnsEmptyString(): void
    {
        $this->store()->local();
        $this->store()->update(ServerStore::LOCAL_ID, static function (array &$server): void {
            $server['public_ip'] = self::PRIVATE_IP;
        });
        ServerResolver::forgetMemo();

        $this->assertSame('', ServerAddress::forApp(['name' => 'blog'], $this->resolver()));
    }

    public function testLocalServerWithPublicIpBaseUrlReturnsTheIp(): void
    {
        $this->store()->local();
        $this->store()->update(ServerStore::LOCAL_ID, static function (array &$server): void {
            $server['base_url'] = 'https://' . self::PUBLIC_IP . ':8443';
        });
        ServerResolver::forgetMemo();

        $this->assertSame(self::PUBLIC_IP, ServerAddress::forApp(['name' => 'blog'], $this->resolver()));
    }

    // ------------------------------------------------------------------
    // Tidak ada yang bisa dipastikan
    // ------------------------------------------------------------------

    public function testAppOnRemovedServerReturnsEmptyString(): void
    {
        $this->assertSame(
            '',
            ServerAddress::forApp(['name' => 'yatim', 'server_id' => 'server-sudah-dihapus'], $this->resolver())
        );
    }

    // ------------------------------------------------------------------
    // recordType()
    // ------------------------------------------------------------------

    public function testRecordTypeOfIpv4IsA(): void
    {
        $this->assertSame('A', ServerAddress::recordType('203.0.113.10'));
    }

    public function testRecordTypeOfIpv6IsAaaa(): void
    {
        $this->assertSame('AAAA', ServerAddress::recordType('2001:db8::1'));
        $this->assertSame('AAAA', ServerAddress::recordType('[2001:db8::1]'));
    }

    public function testRecordTypeOfHostnameIsAmbiguous(): void
    {
        // Hostname tidak bisa dipastikan A/AAAA tanpa resolusi DNS (dilarang di
        // jalur render), jadi UI diberi penanda ambigu — bukan tebakan `A`.
        $this->assertSame('A/AAAA', ServerAddress::recordType('app.example.org'));
        $this->assertSame('A/AAAA', ServerAddress::recordType(''));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function app(string $serverId): array
    {
        return ['name' => 'toko', 'subdomain' => 'toko-a', 'server_id' => $serverId];
    }

    private function store(): ServerStore
    {
        return new ServerStore($this->file);
    }

    private function resolver(): ServerResolver
    {
        return new ServerResolver($this->store());
    }

    private function useConfig(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-server-address-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';

        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->file,
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'app_domain' => 'contoh.example.org',
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

    private static function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
