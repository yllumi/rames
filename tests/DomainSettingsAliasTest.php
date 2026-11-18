<?php
declare(strict_types=1);

namespace Tests;

use app\controller\app\DomainSettingsController;
use app\library\Deploy\ServerResolver;
use app\library\Nginx\NginxReloader;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;

// Helper webman (`config()`, `app_subdomain*()`, `flash_*`) hanya tersedia bila
// `app/functions.php` dimuat; stubs global dipasang di proses anak.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/** Reloader lokal tiruan: tidak menyentuh Docker/nginx nyata. */
final class DomainSettingsAliasReloader extends NginxReloader
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
 * Domain tambahan (alias) lewat controller — `POST /apps/{id}/domains` dan
 * `POST /apps/{id}/domains/remove`.
 *
 * Yang dikunci:
 *  1. tambah alias menyimpan entri `custom_domains[]` (status `disabled`),
 *     menulis ulang config Nginx, me-reload Nginx, dan flash sukses + hint DNS;
 *  2. penolakan: duplikat, domain milik app lain (subdomain **dan** alias),
 *     domain parkir, serta app tanpa host port — flash `error`, store utuh;
 *  3. hapus alias hanya membuang entri yang diminta (alias lain tetap), lalu
 *     menulis ulang config + reload.
 *
 * Seluruh data/config di `sys_get_temp_dir()`: `database/`, `apps/`,
 * `nginx-status/`, Docker, dan certbot nyata **tidak** disentuh.
 */
class DomainSettingsAliasTest extends TestCase
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
        $this->tmp = sys_get_temp_dir() . '/rames-alias-controller-' . bin2hex(random_bytes(5));
        foreach (['config', 'apps', 'nginx/conf', 'nginx-status', 'sessions', 'letsencrypt'] as $dir) {
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
    // addDomain — sukses
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddDomainStoresAliasAndRewritesNginxConfig(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias');
        $reloader = new DomainSettingsAliasReloader();

        $response = (new DomainSettingsController())->addDomain(
            $this->post('domain=Multi.Example.ORG.'),
            $app['id'],
            $reloader
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $reloader->calls, 'reload Nginx di server app');

        $saved = (new AppStore())->find($app['id']);
        $this->assertCount(1, $saved['custom_domains']);
        $entry = $saved['custom_domains'][0];
        $this->assertSame('multi.example.org', $entry['domain'], 'domain dinormalisasi');
        $this->assertSame('disabled', $entry['ssl_status']);
        $this->assertNull($entry['ssl_stage']);
        $this->assertNull($entry['ssl_error']);
        $this->assertNull($entry['ssl_expires_at']);
        $this->assertNotSame('', (string) $entry['created_at']);
        $this->assertArrayNotHasKey('custom_domain', $saved, 'domain utama tidak berubah dibuat');

        $config = $this->tmp . '/nginx/conf/' . $app['name'] . '.conf';
        $this->assertFileExists($config, 'config Nginx ditulis ulang setelah alias ditambah');

        $flash = $this->lastFlash('success');
        $message = (string) ($flash['message'] ?? '');
        $this->assertStringContainsString('multi.example.org', $message);
        $this->assertStringContainsString('tanpa redirect', $message);
        $this->assertStringContainsString('DNS', $message, 'hint DNS disertakan seperti setDomain');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddDomainKeepsExistingAliases(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-2');

        $controller = new DomainSettingsController();
        $controller->addDomain($this->post('domain=satu.example.org'), $app['id'], new DomainSettingsAliasReloader());
        $controller->addDomain($this->post('domain=dua.example.org'), $app['id'], new DomainSettingsAliasReloader());

        $saved = (new AppStore())->find($app['id']);
        $this->assertSame(
            ['satu.example.org', 'dua.example.org'],
            array_column($saved['custom_domains'], 'domain')
        );
    }

    // ==================================================================
    // addDomain — penolakan
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddDomainRejectsDuplicate(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-dup');
        $store = new AppStore();
        $store->update($app['id'], static function (array &$s): void {
            $s['custom_domains'] = [['domain' => 'multi.example.org', 'ssl_status' => 'disabled']];
        });
        $reloader = new DomainSettingsAliasReloader();

        $response = (new DomainSettingsController())->addDomain(
            $this->post('domain=MULTI.example.org'),
            $app['id'],
            $reloader
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $reloader->calls, 'tidak ada efek samping pada penolakan');
        $this->assertStringContainsString(
            'sudah terdaftar sebagai domain tambahan app ini',
            (string) ($this->lastFlash('error')['message'] ?? '')
        );
        $this->assertCount(1, (new AppStore())->find($app['id'])['custom_domains']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddDomainRejectsDomainOwnedByAnotherApp(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-own');
        $other = $this->localApp('app-alias-other');
        (new AppStore())->update($other['id'], static function (array &$s): void {
            $s['custom_domains'] = [['domain' => 'multi.example.org', 'ssl_status' => 'disabled']];
        });
        $reloader = new DomainSettingsAliasReloader();

        (new DomainSettingsController())->addDomain($this->post('domain=multi.example.org'), $app['id'], $reloader);

        $this->assertSame(0, $reloader->calls);
        $this->assertSame(
            'Domain multi.example.org sudah dipakai sebagai domain tambahan app "app-alias-other".',
            (string) ($this->lastFlash('error')['message'] ?? '')
        );
        $this->assertArrayNotHasKey('custom_domains', (new AppStore())->find($app['id']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddDomainRejectsAnotherAppsSubdomain(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-owner');
        $this->localApp('tetangga');
        $reloader = new DomainSettingsAliasReloader();

        (new DomainSettingsController())->addDomain($this->post('domain=tetangga.example.org'), $app['id'], $reloader);

        $this->assertSame(0, $reloader->calls);
        $this->assertSame(
            'Domain tetangga.example.org sudah dipakai sebagai subdomain app "tetangga".',
            (string) ($this->lastFlash('error')['message'] ?? '')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddDomainRejectsParkedDomain(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-parked');
        (new ParkedDomainStore())->create(['domain' => 'parkir.example.org', 'mode' => 'html']);
        $reloader = new DomainSettingsAliasReloader();

        (new DomainSettingsController())->addDomain($this->post('domain=parkir.example.org'), $app['id'], $reloader);

        $this->assertSame(0, $reloader->calls);
        $this->assertStringContainsString(
            'sudah dipakai sebagai domain parkir',
            (string) ($this->lastFlash('error')['message'] ?? '')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddDomainRejectsAppWithoutHostPort(): void
    {
        $this->installStubs(self::ADMIN);
        $app = (new AppStore())->create(['name' => 'app-tanpa-port', 'containers' => []]);
        $reloader = new DomainSettingsAliasReloader();

        (new DomainSettingsController())->addDomain($this->post('domain=multi.example.org'), $app['id'], $reloader);

        $this->assertSame(0, $reloader->calls);
        $this->assertStringContainsString(
            'tidak mem-publish port host',
            (string) ($this->lastFlash('error')['message'] ?? '')
        );
        $this->assertArrayNotHasKey('custom_domains', (new AppStore())->find($app['id']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddDomainRejectsInvalidFqdn(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-invalid');
        $reloader = new DomainSettingsAliasReloader();

        (new DomainSettingsController())->addDomain($this->post('domain=bukan-fqdn'), $app['id'], $reloader);

        $this->assertSame(0, $reloader->calls);
        $this->assertStringContainsString(
            'FQDN publik yang valid',
            (string) ($this->lastFlash('error')['message'] ?? '')
        );
    }

    // ==================================================================
    // removeDomainAlias
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSetDomainRejectsOwnAlias(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-primary');
        (new AppStore())->update($app['id'], static function (array &$s): void {
            $s['custom_domains'] = [['domain' => 'dobel.example.org', 'ssl_status' => 'disabled']];
        });
        $reloader = new DomainSettingsAliasReloader();

        $response = (new DomainSettingsController())->setDomain(
            $this->post('domain=dobel.example.org'),
            $app['id'],
            $reloader
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $reloader->calls, 'penolakan tidak menulis/memuat Nginx');
        $this->assertStringContainsString(
            'sudah terdaftar sebagai domain tambahan app ini',
            (string) ($this->lastFlash('error')['message'] ?? '')
        );

        $saved = (new AppStore())->find($app['id']);
        $this->assertArrayNotHasKey('custom_domain', $saved, 'domain utama tidak di-set');
        $this->assertSame(['dobel.example.org'], array_column($saved['custom_domains'], 'domain'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRemoveDomainAliasDeletesOnlyThatEntryAndReloads(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-remove');
        (new AppStore())->update($app['id'], static function (array &$s): void {
            $s['custom_domains'] = [
                ['domain' => 'satu.example.org', 'ssl_status' => 'active'],
                ['domain' => 'dua.example.org', 'ssl_status' => 'disabled'],
            ];
        });
        $reloader = new DomainSettingsAliasReloader();

        $response = (new DomainSettingsController())->removeDomainAlias(
            $this->post('domain=satu.example.org'),
            $app['id'],
            $reloader
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $reloader->calls);

        $saved = (new AppStore())->find($app['id']);
        $this->assertSame(['dua.example.org'], array_column($saved['custom_domains'], 'domain'));
        $this->assertFileExists($this->tmp . '/nginx/conf/' . $app['name'] . '.conf');

        $flash = $this->lastFlash('success');
        $this->assertStringContainsString('satu.example.org', (string) ($flash['message'] ?? ''));
        $this->assertStringContainsString('dihapus', (string) ($flash['message'] ?? ''));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRemoveDomainAliasForUnknownDomainIsInfoOnly(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-unknown');
        (new AppStore())->update($app['id'], static function (array &$s): void {
            $s['custom_domains'] = [['domain' => 'satu.example.org', 'ssl_status' => 'disabled']];
        });
        $reloader = new DomainSettingsAliasReloader();

        (new DomainSettingsController())->removeDomainAlias($this->post('domain=lain.example.org'), $app['id'], $reloader);

        $this->assertSame(0, $reloader->calls, 'tidak ada perubahan = tidak menulis config');
        $this->assertStringContainsString(
            'tidak ditemukan',
            (string) ($this->lastFlash('info')['message'] ?? '')
        );
        $this->assertCount(1, (new AppStore())->find($app['id'])['custom_domains']);
    }

    /**
     * Revoke gagal (sertifikat ada, certbot tidak tersedia) ⇒ peringatan `info`,
     * tetapi entri tetap dihapus dan config ditulis ulang.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRemoveDomainAliasKeepsRemovalWhenRevokeFails(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp('app-alias-revoke');
        (new AppStore())->update($app['id'], static function (array &$s): void {
            $s['custom_domains'] = [['domain' => 'satu.example.org', 'ssl_status' => 'active']];
        });
        // Sertifikat "ada" supaya jalur revoke benar-benar dijalankan; certbot
        // di config tidak ada → revoke melempar.
        mkdir($this->tmp . '/letsencrypt/live/satu.example.org', 0777, true);
        file_put_contents($this->tmp . '/letsencrypt/live/satu.example.org/fullchain.pem', 'pem');
        file_put_contents($this->tmp . '/letsencrypt/live/satu.example.org/privkey.pem', 'pem');

        $reloader = new DomainSettingsAliasReloader();

        (new DomainSettingsController())->removeDomainAlias($this->post('domain=satu.example.org'), $app['id'], $reloader);

        $this->assertSame(1, $reloader->calls);
        $this->assertSame([], (new AppStore())->find($app['id'])['custom_domains'], 'hapus tetap dilanjutkan');

        $message = (string) ($this->lastFlash('info')['message'] ?? '');
        $this->assertStringContainsString('gagal revoke', $message);
        $this->assertStringContainsString('dihapus', $message);
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
            "POST /apps/x/domains HTTP/1.1\r\nHost: localhost\r\n"
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
            'app_domain' => 'example.org',
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
