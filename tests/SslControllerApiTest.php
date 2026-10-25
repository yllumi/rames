<?php
declare(strict_types=1);

namespace Tests;

use app\controller\SslController;
use app\library\Auth\AppAccessDenied;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use support\Request;
use Webman\Config;
use Webman\Http\Response;

// Helper webman (`json()`, `config()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Mode AJAX halaman SSL (SPECS.md §8a): `POST /api/ssl/{id}/enable`
 * (`apiEnable`) & `GET /api/ssl/status` (`apiStatus`).
 *
 * Kontrak yang dikunci:
 *  1. `apiEnable` menjalankan inti yang **sama** dengan `enable()` — validasi
 *     identik (server pemilik app, port host, domain milik app, domain publik,
 *     status sudah active/pending), efek samping identik (slot `*_status`
 *     `pending`/`failed`, `*_stage`, `*_message`, `*_error`, `needs_ssl`),
 *     worker diploncat dengan domain yang sama;
 *  2. sukses ⇒ HTTP 200 `{code:0,ok:true,status:"pending",domain,message}`;
 *     penolakan bisnis ⇒ HTTP **200** `{code:1,ok:false,error}` (bukan 4xx/5xx)
 *     dan state tersimpan tidak berubah (kecuali jalur gagal-spawn ⇒ `failed`);
 *  3. app tak ada / user tanpa ability `ssl` ⇒ `AppAccessDenied` ⇒ 404 JSON
 *     (`code:404`) — perilaku satu pintu `AppAccess` tidak dilonggarkan;
 *  4. `apiStatus` memakai `sslRows()` yang sama (urutan & isi baris), hanya
 *     membaca store (tanpa Docker/agent), dan menyaring app lewat
 *     `AppAccess::visible()` — app milik user lain tidak muncul untuk non-admin;
 *     `can_ssl` mengikuti `AppAccess::can('ssl', …)` (operator+).
 *
 * Seluruh data & config di `sys_get_temp_dir()`: `database/`, `apps/`,
 * `nginx-status/`, Docker, certbot, dan sesi nyata **tidak pernah** disentuh.
 * Spawn worker SSL selalu lewat seam `$spawn` (tidak pernah menjalankan certbot).
 */
class SslControllerApiTest extends TestCase
{
    private const OWNER = ['id' => 'u1', 'username' => 'owner', 'role' => 'member'];
    private const VIEWER = ['id' => 'u2', 'username' => 'viewer', 'role' => 'member'];
    private const STRANGER = ['id' => 'u3', 'username' => 'stranger', 'role' => 'member'];
    private const ADMIN = ['id' => 'adm', 'username' => 'admin', 'role' => 'admin'];

    /**
     * Stub helper global (hanya di proses anak): `flash_set()` merekam ke
     * `$GLOBALS['RAMES_FLASH']` supaya bisa dibuktikan bahwa jalur JSON **tidak**
     * menulis flash (halaman yang menampilkannya `enable()`).
     */
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

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    /** @var array<int,array{0:string,1:string}> daftar (appId, domain) yang diploncat */
    private array $spawned = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-ssl-api-' . bin2hex(random_bytes(5));
        foreach (['config', 'apps', 'nginx/conf', 'logs/ssl', 'sessions'] as $dir) {
            mkdir($this->tmp . '/' . $dir, 0777, true);
        }

        $this->spawned = [];
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
    // (1) apiEnable — sukses
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiEnableSpawnsWorkerAndReturnsPendingEnvelope(): void
    {
        $this->installStubs(self::OWNER);
        $app = $this->app('api-app', ['owner_id' => self::OWNER['id']]);

        $response = $this->controller(true)->apiEnable($this->enableRequest($app['id']), $app['id']);

        $this->assertSame(200, $response->getStatusCode(), 'penolakan/sukses bisnis bukan error HTTP');
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame([
            'code' => 0,
            'ok' => true,
            'status' => 'pending',
            'domain' => 'api-app.example.org',
            'message' => 'Penerbitan SSL untuk api-app.example.org dijalankan.',
        ], $this->envelope($response));

        $this->assertSame([[$app['id'], 'api-app.example.org']], $this->spawned, 'worker diploncat dengan domain app');
        $this->assertSame([], $GLOBALS['RAMES_FLASH'] ?? [], 'jalur JSON tidak menulis flash (itu milik jalur halaman)');

        $saved = (new AppStore())->find($app['id']);
        $this->assertSame('pending', $saved['ssl_status']);
        $this->assertSame('queued', $saved['ssl_stage']);
        $this->assertSame('Menunggu worker SSL ...', $saved['ssl_message']);
        $this->assertNull($saved['ssl_error']);
        $this->assertTrue($saved['needs_ssl']);
    }

    /**
     * Domain eksplisit di body (`domain`) tetap dipatuhi; custom domain memakai
     * slot `custom_ssl_*` seperti jalur halaman.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiEnableUsesTheCustomSslSlotWhenTheCustomDomainIsTheTarget(): void
    {
        $this->installStubs(self::OWNER);
        $app = $this->app('api-custom', [
            'owner_id' => self::OWNER['id'],
            'custom_domain' => 'app.example.net',
        ]);

        $response = $this->controller(true)->apiEnable($this->enableRequest($app['id'], 'app.example.net'), $app['id']);

        $this->assertSame(0, $this->envelope($response)['code']);
        $this->assertSame('app.example.net', $this->envelope($response)['domain']);
        $this->assertSame([[$app['id'], 'app.example.net']], $this->spawned);

        $saved = (new AppStore())->find($app['id']);
        $this->assertSame('pending', $saved['custom_ssl_status']);
        $this->assertTrue($saved['custom_needs_ssl']);
        $this->assertArrayNotHasKey('ssl_status', $saved, 'slot subdomain tidak boleh ikut tersentuh');
    }

    // ==================================================================
    // (2) apiEnable — penolakan bisnis (code=1, tanpa efek samping)
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiEnableRejectsAppWithoutHostPort(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->app('tanpa-port', ['owner_id' => self::OWNER['id'], 'containers' => []]);

        $response = $this->controller(true)->apiEnable($this->enableRequest($app['id']), $app['id']);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->envelope($response);
        $this->assertSame(1, $body['code']);
        $this->assertFalse($body['ok']);
        $this->assertStringContainsString('tidak mem-publish port host', (string) $body['error']);
        $this->assertStringContainsString('Deploy Ulang', (string) $body['error']);
        $this->assertSame([], $this->spawned, 'tidak ada worker untuk app tanpa vhost');
        $this->assertArrayNotHasKey('ssl_status', (new AppStore())->find($app['id']), 'store tidak boleh tersentuh');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiEnableRejectsNonPublicDomainAndForeignDomain(): void
    {
        $this->installStubs(self::ADMIN);
        // Custom domain non-publik: `.local` ditolak `SslIssuer::isPublicDomain`.
        $app = $this->app('api-local', [
            'owner_id' => self::OWNER['id'],
            'custom_domain' => 'api-local.local',
        ]);

        $response = $this->controller(true)->apiEnable($this->enableRequest($app['id']), $app['id']);

        $body = $this->envelope($response);
        $this->assertSame(1, $body['code']);
        $this->assertFalse($body['ok']);
        $this->assertSame('Domain api-local.local bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).', $body['error']);
        $this->assertSame([], $this->spawned);

        // Domain yang bukan milik app (subdomain maupun custom domain lain).
        $other = $this->app('api-other', ['owner_id' => self::OWNER['id']]);
        $response = $this->controller(true)->apiEnable($this->enableRequest($other['id'], 'domain-orang-lain.example.com'), $other['id']);

        $body = $this->envelope($response);
        $this->assertSame(1, $body['code']);
        $this->assertSame('Domain domain-orang-lain.example.com bukan milik app ini.', $body['error']);
        $this->assertSame([], $this->spawned, 'tidak ada spawn untuk domain non-publik/milik orang lain');
        $this->assertArrayNotHasKey('ssl_status', (new AppStore())->find($other['id']));
    }

    // ==================================================================
    // (3) apiEnable — status sudah pending/active
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiEnableRejectsAlreadyPendingOrActiveSlot(): void
    {
        $this->installStubs(self::ADMIN);
        $store = new AppStore();

        foreach (['pending', 'active'] as $status) {
            $app = $this->app('api-' . $status, ['owner_id' => self::OWNER['id'], 'ssl_status' => $status]);

            $response = $this->controller(true)->apiEnable($this->enableRequest($app['id']), $app['id']);

            $body = $this->envelope($response);
            $this->assertSame(1, $body['code'], "status {$status} harus ditolak");
            $this->assertFalse($body['ok']);
            $this->assertSame("SSL untuk api-{$status}.example.org sudah {$status}.", $body['error']);
            $this->assertSame($status, (string) $store->find($app['id'])['ssl_status'], 'status tersimpan tidak berubah');
        }

        $this->assertSame([], $this->spawned, 'tidak ada penerbitan ulang');
    }

    // ==================================================================
    // (4) apiEnable — spawn gagal
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiEnableSpawnFailureMarksTheSlotFailed(): void
    {
        $this->installStubs(self::OWNER);
        $app = $this->app('api-gagal', ['owner_id' => self::OWNER['id']]);

        $response = $this->controller(false)->apiEnable($this->enableRequest($app['id']), $app['id']);

        $body = $this->envelope($response);
        $this->assertSame(1, $body['code']);
        $this->assertSame('Gagal menjalankan worker SSL.', $body['error']);

        $saved = (new AppStore())->find($app['id']);
        $this->assertSame('failed', $saved['ssl_status']);
        $this->assertNull($saved['ssl_stage']);
        $this->assertNull($saved['ssl_message']);
        $this->assertStringContainsString('Gagal spawn worker SSL', (string) $saved['ssl_error']);
    }

    // ==================================================================
    // (5) apiEnable — app tak ada / bukan milik user ⇒ 404
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiEnableDeniesUnknownAndForeignAppAsNotFound(): void
    {
        $this->installStubs(self::STRANGER);
        $foreign = $this->app('api-asing', ['owner_id' => self::OWNER['id']]);

        foreach ([$foreign['id'], 'app-tidak-ada'] as $id) {
            $request = $this->enableRequest($id);

            try {
                $this->controller(true)->apiEnable($request, $id);
                $this->fail("akses app {$id} seharusnya ditolak");
            } catch (AppAccessDenied $e) {
                $this->assertSame('ssl', $e->ability);
                $this->assertSame($id, $e->appId);

                /** @var Response $render */
                $render = $e->render($request);
                $this->assertSame(404, $render->getStatusCode(), 'penolakan tetap 404 (bukan 403)');
                $this->assertSame(['code' => 404, 'msg' => 'Not Found'], $this->envelope($render));
            }
        }

        $this->assertSame([], $this->spawned);
        $this->assertArrayNotHasKey('ssl_status', (new AppStore())->find($foreign['id']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiEnableDeniesViewerWithoutTheSslAbility(): void
    {
        $this->installStubs(self::VIEWER);
        $app = $this->app('api-viewer', [
            'owner_id' => self::OWNER['id'],
            'members' => [self::VIEWER['id'] => ['role' => 'viewer']],
        ]);

        $this->expectException(AppAccessDenied::class);
        $this->controller(true)->apiEnable($this->enableRequest($app['id']), $app['id']);
    }

    // ==================================================================
    // (6) apiStatus — baris + visibilitas + ability
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiStatusReturnsTheSameRowsAsSslRows(): void
    {
        $this->installStubs(self::ADMIN);
        $plain = $this->app('status-biasa', [
            'owner_id' => self::OWNER['id'],
            'ssl_status' => 'pending',
            'ssl_stage' => 'queued',
            'ssl_message' => 'Menunggu worker SSL ...',
        ]);
        $custom = $this->app('status-custom', [
            'owner_id' => self::OWNER['id'],
            'custom_domain' => 'status.example.net',
            'custom_ssl_status' => 'active',
            'custom_ssl_message' => 'Aktif',
            'custom_ssl_expires_at' => '2026-03-01T00:00:00+00:00',
        ]);
        // Tanpa port host ⇒ tidak ada baris (logika `sslRows()` yang sama).
        $this->app('status-tanpa-port', ['owner_id' => self::OWNER['id'], 'containers' => []]);

        $response = $this->controller(true)->apiStatus($this->statusRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $body = $this->envelope($response);

        $this->assertSame(0, $body['code']);
        $this->assertTrue($body['ssl_supported'], 'APP_DOMAIN publik ⇒ SSL didukung');
        $this->assertTrue($body['pending'], 'ada baris ber-status pending');
        // Custom domain kini menghasilkan DUA baris: custom (serve) + subdomain
        // (redirect) — keduanya status dari slot berbeda.
        $this->assertSame(
            ['status-biasa.example.org', 'status.example.net', 'status-custom.example.org'],
            array_column($body['rows'], 'domain')
        );

        $this->assertSame([
            'app_id' => $plain['id'],
            'app_name' => 'status-biasa',
            'domain' => 'status-biasa.example.org',
            'is_custom' => false,
            'status' => 'pending',
            'message' => 'Menunggu worker SSL ...',
            'expires_at' => null,
            'error' => null,
            // Ringkasan pendek untuk kolom Aksi (`/domains`); `error` tetap utuh.
            'error_summary' => '',
            'can_ssl' => true,
            'kind' => 'app',
            'role' => 'serve',
        ], $body['rows'][0]);

        $this->assertSame([
            'app_id' => $custom['id'],
            'app_name' => 'status-custom',
            'domain' => 'status.example.net',
            'is_custom' => true,
            'status' => 'active',
            'message' => 'Aktif',
            'expires_at' => '2026-03-01T00:00:00+00:00',
            'error' => null,
            'error_summary' => '',
            'can_ssl' => true,
            'kind' => 'app',
            'role' => 'serve',
        ], $body['rows'][1]);

        $this->assertSame('status-custom.example.org', $body['rows'][2]['domain']);
        $this->assertFalse($body['rows'][2]['is_custom']);
        $this->assertSame('redirect', $body['rows'][2]['role']);
        $this->assertSame('disabled', $body['rows'][2]['status'], 'slot ssl subdomain belum diaktifkan');
    }

    /**
     * `sslRows()` untuk daftar app yang sama harus menghasilkan domain & urutan
     * yang sama seperti `apiStatus()` — tidak ada logika domain yang diduplikasi.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiStatusRowOrderMatchesSslRows(): void
    {
        $this->installStubs(self::ADMIN);
        $this->app('urut-a', ['owner_id' => self::OWNER['id'], 'ssl_status' => 'disabled']);
        $this->app('urut-b', ['owner_id' => self::OWNER['id'], 'custom_domain' => 'urut.example.net']);

        $body = $this->envelope($this->controller(true)->apiStatus($this->statusRequest()));

        $table = $this->sslRows((new AppStore())->all());
        $this->assertSame(array_column($table['rows'], 'domain'), array_column($body['rows'], 'domain'));
        $this->assertSame(array_column($table['rows'], 'ssl_status'), array_column($body['rows'], 'status'));
        $this->assertSame($table['sslSupported'], $body['ssl_supported']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiStatusHidesForeignAppsAndFollowsTheSslAbility(): void
    {
        $this->installStubs(self::VIEWER);
        // App milik user lain, TIDAK dibagikan ⇒ tidak muncul (bukan sekadar
        // tombol disembunyikan).
        $this->app('luar-jangkauan', ['owner_id' => self::STRANGER['id'], 'ssl_status' => 'pending']);
        // App yang dibagikan sebagai viewer: baris muncul, `can_ssl` false.
        $shared = $this->app('dibagikan', [
            'owner_id' => self::OWNER['id'],
            'members' => [self::VIEWER['id'] => ['role' => 'viewer']],
            'ssl_status' => 'failed',
            'ssl_error' => 'Timeout during connect',
        ]);

        $body = $this->envelope($this->controller(true)->apiStatus($this->statusRequest()));

        $this->assertSame([$shared['id']], array_column($body['rows'], 'app_id'), 'hanya app yang boleh dilihat (non-admin)');
        $this->assertFalse($body['rows'][0]['can_ssl'], 'viewer tidak punya ability `ssl`');
        $this->assertSame('failed', $body['rows'][0]['status']);
        $this->assertSame('Timeout during connect', $body['rows'][0]['error']);
        $this->assertFalse($body['pending'], 'app pending milik user lain tidak terhitung');

        // Operator (dibagikan) punya ability `ssl`.
        $this->installStubs(['id' => self::OWNER['id'], 'username' => 'op', 'role' => 'member']);
        $body = $this->envelope($this->controller(true)->apiStatus($this->statusRequest()));
        $this->assertTrue($body['rows'][0]['can_ssl'], 'owner/operator boleh mengaktifkan SSL');
    }

    /**
     * Halaman `/ssl` yang dimuat saat ada baris `pending` lalu di-refresh via
     * `apiStatus()`: `pending` mencegah polling tanpa henti begitu selesai.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiStatusPendingFlagFalseOnceTheSlotSettles(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->app('selesai', ['owner_id' => self::OWNER['id'], 'ssl_status' => 'pending']);

        $this->assertTrue($this->envelope($this->controller(true)->apiStatus($this->statusRequest()))['pending']);

        (new AppStore())->update($app['id'], function (array &$s): void {
            $s['ssl_status'] = 'active';
            $s['ssl_expires_at'] = '2026-06-01T00:00:00+00:00';
        });

        $body = $this->envelope($this->controller(true)->apiStatus($this->statusRequest()));
        $this->assertFalse($body['pending']);
        $this->assertSame('active', $body['rows'][0]['status']);
        $this->assertSame('2026-06-01T00:00:00+00:00', $body['rows'][0]['expires_at']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(bool $spawnOk): SslController
    {
        return new SslController(function (string $appId, string $domain) use ($spawnOk): bool {
            $this->spawned[] = [$appId, $domain];

            return $spawnOk;
        });
    }

    /** @param array<string,mixed> $extra */
    private function app(string $name, array $extra = []): array
    {
        return (new AppStore())->create(array_merge([
            'name' => $name,
            'status' => 'running',
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
        ], $extra));
    }

    private function enableRequest(string $id, string $domain = ''): Request
    {
        $body = $domain === '' ? '' : 'domain=' . rawurlencode($domain);

        return new Request(
            "POST /api/ssl/{$id}/enable HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body)
            . "\r\n\r\n" . $body
        );
    }

    private function statusRequest(): Request
    {
        return new Request("GET /api/ssl/status HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    /** @return array<string,mixed> envelope JSON respons */
    private function envelope(Response $response): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<int,array<string,mixed>> $apps
     * @return array{rows:array<int,array<string,mixed>>,sslSupported:bool}
     */
    private function sslRows(array $apps): array
    {
        $method = new ReflectionMethod(SslController::class, 'sslRows');
        $method->setAccessible(true);

        /** @var array{rows:array<int,array<string,mixed>>,sslSupported:bool} $table */
        $table = $method->invoke(new SslController(), $apps);

        return $table;
    }

    /**
     * Pasang stub helper global + `app/functions.php` (hanya di proses anak).
     *
     * @param array<string,mixed>|null $user user login yang dikembalikan `current_user()`
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

        // Baru setelah stub: `app/functions.php` mengisi sisanya
        // (`app_subdomain_of()`, `app_can()`, …) tanpa menimpa stub di atas.
        if (!function_exists('app_subdomain_of')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }

        ini_set('session.save_path', $this->tmp . '/sessions');
    }

    /**
     * Config temp: store SQLite & direktori app/Nginx di `/tmp` supaya controller
     * (`new AppStore()`) tidak menyentuh data nyata.
     */
    private function useConfig(): void
    {
        Config::clear();
        // `Config::load()` hanya memuat berkas di direktori yang juga punya app.php.
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'nginx_conf_path' => $this->tmp . '/nginx/conf',
            'nginx_enabled_path' => '',
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

    /**
     * @param array<string,mixed> $state
     */
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
            if (is_file($path)) {
                @unlink($path);
            }

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
