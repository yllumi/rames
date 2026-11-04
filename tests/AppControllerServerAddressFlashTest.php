<?php
declare(strict_types=1);

namespace Tests;

use app\controller\app\DomainSettingsController;
use app\library\Deploy\ServerResolver;
use app\library\Nginx\NginxReloader;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;

// Helper webman (`config()`, `app_subdomain*()`, `flash_*`) hanya tersedia bila
// `app/functions.php` dimuat; stubs global dipasang lebih dulu di proses anak
// (`installStubs()`), jadi di sini cukup menyiapkan konstanta.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/** Reloader lokal tiruan: tidak menyentuh Docker/nginx nyata. */
final class AppServerAddressFlashReloader extends NginxReloader
{
    public int $calls = 0;

    public function __construct()
    {
        // reload() di-override ⇒ konstruktor induk (config/Docker) tidak dipakai.
    }

    public function reload(): array
    {
        $this->calls++;

        return ['ok' => true, 'message' => '', 'error' => null, 'output' => ''];
    }
}

/**
 * Flash `setDomain()` menyebut **target DNS konkret** (alamat publik server
 * pemilik app), bukan lagi kalimat generik "arahkan DNS ke server".
 *
 * Latar belakang: insiden nyata — custom domain diarahkan ke IP server
 * dashboard padahal app berjalan di server agent, sehingga Let's Encrypt
 * HTTP-01 selalu `404`. Yang dikunci di sini:
 *  1. alamat publik diketahui ⇒ flash memuat `<A|AAAA> record <domain> ke
 *     <alamat>` + label server yang sama dengan badge header (`lokal` untuk
 *     server lokal — bukan nama baris seed seperti `lokal-utama`);
 *  2. alamat tak bisa dipastikan ⇒ flash memuat kalimat generik
 *     "ke IP server tempat app ini berjalan" (tidak mengarang alamat);
 *  3. tidak ada rahasia registry (`base_url`, token) yang bocor ke pesan;
 *  4. **tanpa DNS**: `ServerAddress` hanya membaca registry, jadi tidak ada
 *     host-lookup yang memblokir worker persistent.
 *
 * Data, config, & store 100% di `sys_get_temp_dir()`; tidak ada Docker, nginx,
 * certbot, `database/`, `apps/`, atau `nginx-status/` nyata yang disentuh.
 */
class AppControllerServerAddressFlashTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];

    private const STUB_SOURCE = <<<'PHP'
        <?php
        namespace {
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return $GLOBALS['RAMES_TEST_USER'] ?? null;
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                    $GLOBALS['RAMES_FLASH'][] = ['type' => $type, 'message' => $message];
                }
            }
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
                }
            }
            if (!function_exists('session')) {
                function session(): object
                {
                    return new class {
                        public function get(?string $key = null): mixed
                        {
                            return $key === 'user' ? ($GLOBALS['RAMES_TEST_USER'] ?? null) : null;
                        }

                        public function set(string $key, mixed $value): void
                        {
                        }

                        public function delete(string $key): void
                        {
                        }

                        public function pull(string $key): mixed
                        {
                            return null;
                        }
                    };
                }
            }
        }
        PHP;

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-server-address-flash-' . bin2hex(random_bytes(5));
        foreach (['config', 'apps', 'nginx/conf', 'nginx-status', 'sessions'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0777, true);
        }

        $GLOBALS['RAMES_FLASH'] = [];
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
        self::removeTree($this->tmp);
        unset($GLOBALS['RAMES_FLASH'], $GLOBALS['RAMES_TEST_USER']);
    }

    // ==================================================================
    // (1) alamat publik diketahui
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFlashNamesThePublicAddressAndServerLabel(): void
    {
        $this->installStubs(self::ADMIN);

        // Server lokal ber-`base_url` IP publik: alamat bisa dipastikan **tanpa
        // DNS** (Registry-only). `name` sengaja `lokal-utama` untuk membuktikan
        // label flash mengikuti badge header (`lokal`), bukan nama baris seed.
        $servers = new ServerStore();
        $servers->local();
        $servers->update(ServerStore::LOCAL_ID, static function (array &$server): void {
            $server['name'] = 'lokal-utama';
            $server['base_url'] = 'https://203.0.113.10:8443';
        });
        ServerResolver::forgetMemo();

        $app = $this->localApp('app-flash-alamat');
        $reloader = new AppServerAddressFlashReloader();

        $response = (new DomainSettingsController())->setDomain(
            $this->post('domain=baru.example.com'),
            $app['id'],
            $reloader
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $reloader->calls);

        $flash = $this->lastFlash('success');
        $message = (string) ($flash['message'] ?? '');
        $this->assertStringContainsString('A record', $message);
        $this->assertStringContainsString('baru.example.com', $message);
        $this->assertStringContainsString('203.0.113.10', $message, 'alamat publik server app harus disebut konkret');
        $this->assertStringContainsString('server lokal', $message, 'label server lokal = "lokal" (samakan dengan badge header)');
        $this->assertStringNotContainsString('lokal-utama', $message, 'nama baris seed server lokal bukan label UI');
        $this->assertStringNotContainsString('https://', $message, 'base_url utuh tidak boleh tampil (GOTCHA no. 25)');
        $this->assertStringNotContainsString('IP server tempat app ini berjalan', $message);

        $this->assertSame('baru.example.com', (new AppStore())->find($app['id'])['custom_domain'] ?? null);
    }

    // ==================================================================
    // (2) alamat tidak bisa dipastikan
    // ==================================================================

    /**
     * Server lokal tanpa `base_url`/field alamat ⇒ tidak ada alamat yang bisa
     * dipastikan (dulu jalur ini memakai DNS subdomain app — penyebab blocking
     * ±4 s). Flash harus memakai kalimat generik yang jujur, dan `domain` dasar
     * server berakhiran `.internal` tidak boleh tampil sebagai alamat.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFlashFallsBackToGenericHintWhenAddressIsUnknown(): void
    {
        $this->installStubs(self::ADMIN);

        $servers = new ServerStore();
        $servers->local();
        $servers->update(ServerStore::LOCAL_ID, static function (array &$server): void {
            $server['name'] = 'lokal-utama';
            $server['domain'] = 'lokal.internal';
        });
        ServerResolver::forgetMemo();

        $app = $this->localApp('app-flash-tanpa-alamat');
        $reloader = new AppServerAddressFlashReloader();

        $response = (new DomainSettingsController())->setDomain(
            $this->post('domain=baru.example.com'),
            $app['id'],
            $reloader
        );

        $this->assertSame(302, $response->getStatusCode());

        $flash = $this->lastFlash('success');
        $message = (string) ($flash['message'] ?? '');
        $this->assertStringContainsString('A/AAAA record', $message);
        $this->assertStringContainsString('IP server tempat app ini berjalan', $message);
        $this->assertStringNotContainsString('203.0.113', $message, 'jangan mengarang alamat');
        $this->assertStringNotContainsString('lokal.internal', $message, 'hostname internal tidak boleh tampil');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** @return array<string,mixed> */
    private function localApp(string $name): array
    {
        return (new AppStore())->create([
            'name' => $name,
            'status' => 'running',
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
        ]);
    }

    private function post(string $body = ''): Request
    {
        return new Request(
            "POST /apps/x HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body)
            . "\r\n\r\n" . $body
        );
    }

    /** @return array<string,mixed> */
    private function lastFlash(string $type): array
    {
        foreach (array_reverse((array) ($GLOBALS['RAMES_FLASH'] ?? [])) as $flash) {
            if (($flash['type'] ?? '') === $type) {
                return $flash;
            }
        }

        $this->fail('flash ' . $type . ' tidak ditemukan: ' . var_export($GLOBALS['RAMES_FLASH'] ?? [], true));
    }

    /**
     * Pasang stub helper global + `app/functions.php` (hanya di proses anak).
     *
     * @param array<string,mixed>|null $user
     */
    private function installStubs(?array $user): void
    {
        $GLOBALS['RAMES_TEST_USER'] = $user;
        $GLOBALS['RAMES_FLASH'] = [];

        if (!function_exists('flash_set')) {
            $file = $this->tmp . '/stubs.php';
            file_put_contents($file, self::STUB_SOURCE);
            require $file;
        }

        if (!function_exists('app_subdomain_of')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }

        ini_set('session.save_path', $this->tmp . '/sessions');
    }

    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'nginx_conf_path' => $this->tmp . '/nginx/conf',
            'nginx_enabled_path' => '',
            'nginx_reload_image' => 'rames-test.invalid/no-such-image:0',
            'nginx_bin' => '/nonexistent/nginx',
            'nginx_reload_status_file' => $this->tmp . '/nginx-status/last-reload.json',
            'letsencrypt_path' => $this->tmp . '/letsencrypt',
            'ssl_webroot' => $this->tmp . '/webroot',
            'app_domain' => 'lokal.example.org',
            'port_range' => ['start' => 30000, 'end' => 30999],
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
            'deploy_timeout' => 5,
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
