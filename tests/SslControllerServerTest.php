<?php
declare(strict_types=1);

namespace Tests;

use app\controller\SslController;
use app\library\Deploy\ServerResolver;
use app\library\SSL\SslQueue;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use support\Request;
use Tests\Support\StoreDbFiles;
use Webman\Config;

// Helper webman (`app_subdomain*()` dkk.) hanya dimuat di proses anak lewat
// `installStubs()` — **setelah** stub global dipasang, supaya `current_user()`/
// `flash_set()` tidak menabrak versi asli (pola `AppControllerServerScopeTest`).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * SSL server-aware (multi-server F2, plan §5 + keputusan 4).
 *
 * Kontrak yang dikunci:
 *  1. `SslController` memakai **server pemilik app**: domain efektif baris tabel
 *     SSL diambil dari `servers.domain` server itu (`{label}.{domain}`), dan app
 *     lokal tetap memakai `APP_DOMAIN` control plane (perilaku lama identik);
 *  2. `enable()` untuk app di server agent **tetap** menandai status `pending`
 *     dan memasukkan job ke antrean SSL global; drainer `ssl-queue` yang
 *     menjalankan `cli/ssl.php` (worker itu yang mengirim job `ssl_issue` ke
 *     server agent) — controller tidak menjalankan certbot;
 *  3. server agent tanpa `APP_DOMAIN` (`domain`) ⇒ **ditolak lebih awal** dengan
 *     pesan yang meminta admin mengisi domain server itu (kontrak F2 §5):
 *     tidak ada status `pending`, tidak ada worker yang diluncurkan;
 *  4. app tanpa port host / domain non-publik tetap ditolak seperti sebelumnya;
 *  5. `cli/ssl.php` untuk app di server agent ber-domain kosong **fail-fast**
 *     tanpa menyentuh certbot host ini (dibuktikan sebagai subproses).
 *
 * Seluruh data & config di `sys_get_temp_dir()`: `database/rames.sqlite`,
 * `apps/`, `nginx-status/`, Docker, dan certbot **tidak pernah** disentuh.
 */
class SslControllerServerTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const AGENT_ID = 'vps-jakarta-2';

    /**
     * Stub helper global (hanya dipakai di proses anak ber-`RunInSeparateProcess`):
     * `flash_set()` merekam pesan ke `$GLOBALS['RAMES_FLASH']` supaya jalur
     * penolakan bisa diperiksa tanpa session Webman.
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

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-ssl-server-' . bin2hex(random_bytes(5));
        foreach (['config', 'apps', 'nginx/conf', 'logs/ssl', 'sessions'] as $dir) {
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
    // (1) domain efektif per server
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSslTableUsesTheOwningServerDomain(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID, ['domain' => 'remote.example.com']);
        $remote = (new AppStore())->create([
            'name' => 'remote-app',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
            'containers' => [['host_port' => 31001, 'internal_port' => 80]],
        ]);
        $local = (new AppStore())->create([
            'name' => 'local-app',
            'status' => 'running',
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
        ]);

        $table = $this->sslRows([$remote, $local]);

        $this->assertTrue($table['sslSupported'], 'domain publik per server ⇒ baris bisa di-SSL');
        $this->assertSame(
            ['remote-app.remote.example.com', 'local-app.example.org'],
            array_column($table['rows'], 'domain'),
            'FQDN baris mengikuti server masing-masing (lokal tetap APP_DOMAIN)'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSslSupportFollowsTheServerDomainNotTheControlPlaneOne(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID, ['domain' => 'staging.internal.test']);
        $remote = (new AppStore())->create([
            'name' => 'remote-app',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
            'containers' => [['host_port' => 31001, 'internal_port' => 80]],
        ]);

        $table = $this->sslRows([$remote]);

        // APP_DOMAIN control plane publik (example.org) tapi server app tidak.
        $this->assertFalse($table['sslSupported'], 'domain server non-publik ⇒ tidak bisa di-SSL');
        $this->assertSame('remote-app.staging.internal.test', $table['rows'][0]['domain']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppsWithoutHostPortAreExcludedFromTheSslTable(): void
    {
        $this->installStubs(self::ADMIN);
        $app = (new AppStore())->create(['name' => 'tanpa-port', 'status' => 'running', 'containers' => []]);

        $table = $this->sslRows([$app]);

        $this->assertSame([], $table['rows']);
        $this->assertTrue($table['sslSupported'], 'fallback lama: APP_DOMAIN publik dipakai saat tidak ada baris');
    }

    // ==================================================================
    // (2)/(4) enable(): remote memakai server app, lokal tidak berubah
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnableForRemoteAppEnqueuesTheJobWithTheServerDomain(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID, ['domain' => 'remote.example.com']);
        $app = (new AppStore())->create([
            'name' => 'remote-app',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
            'containers' => [['host_port' => 31001, 'internal_port' => 80]],
        ]);

        $response = $this->controller(true)->enable($this->post(), $app['id']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/domains', $response->getHeader('Location'), 'tabel SSL menyatu di /domains');
        $job = $this->queue()->findActive('app:' . $app['id'] . ':remote-app.remote.example.com:ssl');
        $this->assertIsArray($job, 'job antrean memakai domain server app');
        $this->assertSame('remote-app.remote.example.com', $job['domain']);
        $this->assertSame(self::AGENT_ID, $job['server_id']);

        $saved = (new AppStore())->find($app['id']);
        $this->assertSame('pending', $saved['ssl_status']);
        $this->assertSame('queued', $saved['ssl_stage']);
        $this->assertTrue($saved['needs_ssl']);
        $this->assertSame(
            [[
                'type' => 'success',
                'message' => 'SSL untuk remote-app.remote.example.com masuk antrean (posisi 1 dari 1).',
            ]],
            $GLOBALS['RAMES_FLASH']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnableForRemoteAppWithoutServerDomainIsRejectedBeforeAnySideEffect(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID, ['domain' => '']);
        $app = (new AppStore())->create([
            'name' => 'remote-app',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
            'containers' => [['host_port' => 31001, 'internal_port' => 80]],
        ]);

        $response = $this->controller(true)->enable($this->post(), $app['id']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->queue()->queueSize(), 'tidak boleh ada job SSL untuk domain yang salah');
        $this->assertSame('error', $GLOBALS['RAMES_FLASH'][0]['type'] ?? '');
        $this->assertStringContainsString('APP_DOMAIN', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
        $this->assertStringContainsString('halaman Servers', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');

        $saved = (new AppStore())->find($app['id']);
        $this->assertArrayNotHasKey('ssl_status', $saved, 'status tidak boleh diubah (fail-fast sebelum efek samping)');
    }

    /**
     * Regresi eksplisit: app di server lokal berperilaku persis seperti sebelum
     * multi-server — domain dari `APP_DOMAIN`, worker dijalankan, status pending.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnableForLocalAppKeepsLegacyBehaviour(): void
    {
        $this->installStubs(self::ADMIN);
        $app = (new AppStore())->create([
            'name' => 'local-app',
            'status' => 'running',
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
        ]);

        $response = $this->controller(true)->enable($this->post(), $app['id']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertIsArray($this->queue()->findActive('app:' . $app['id'] . ':local-app.example.org:ssl'));
        $this->assertSame('pending', (new AppStore())->find($app['id'])['ssl_status']);
        $this->assertSame('success', $GLOBALS['RAMES_FLASH'][0]['type'] ?? '');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnableEnqueueFailureMarksTheSlotFailed(): void
    {
        $this->installStubs(self::ADMIN);
        $app = (new AppStore())->create([
            'name' => 'local-app',
            'status' => 'running',
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
        ]);

        $this->controller(false)->enable($this->post(), $app['id']);

        $saved = (new AppStore())->find($app['id']);
        $this->assertSame('failed', $saved['ssl_status']);
        $this->assertStringContainsString('Gagal memasukkan permintaan SSL ke antrean', (string) $saved['ssl_error']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnableRejectsNonPublicDomainAndAppWithoutHostPort(): void
    {
        $this->installStubs(self::ADMIN);
        $this->agent(self::AGENT_ID, ['domain' => 'staging.internal.test']);
        $noPort = (new AppStore())->create(['name' => 'tanpa-port', 'status' => 'running', 'containers' => []]);

        $this->controller(true)->enable($this->post(), $noPort['id']);
        $this->assertStringContainsString('tidak mem-publish port host', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');

        $app = (new AppStore())->create([
            'name' => 'remote-app',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
            'containers' => [['host_port' => 31001, 'internal_port' => 80]],
        ]);
        $GLOBALS['RAMES_FLASH'] = [];
        // Domain baris = FQDN server yang tidak publik ⇒ tombol tidak berguna.
        $this->controller(true)->enable($this->post(), $app['id']);

        $this->assertSame(0, $this->queue()->queueSize(), 'domain non-publik tidak boleh masuk antrean');
        $this->assertStringContainsString('bukan domain publik', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
    }

    // ==================================================================
    // (5) cli/ssl.php — fail-fast tanpa certbot host ini
    // ==================================================================

    public function testSslCliForRemoteAppWithoutServerDomainFailsFastWithoutCertbot(): void
    {
        $dbFile = $this->tmp . '/cli.sqlite';
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();

        $servers = new ServerStore($dbFile);
        $servers->create([
            'id' => self::AGENT_ID,
            'name' => 'vps-tanpa-domain',
            'kind' => 'agent',
            'enabled' => true,
            'domain' => '',
            'base_url' => 'https://agent.invalid.test:8443',
            'token' => 'tok-uji',
            'tls_cert_pem' => self::selfSignedPem(),
            'status' => 'online',
        ]);
        $appId = 'app-cli-ssl-1';
        (new AppStore($dbFile))->create([
            'id' => $appId,
            'name' => 'cli-app',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
            // Tanpa custom domain: subdomain bawaan app ⇒ wajib punya domain
            // server (kontrak F2 §5). Domain argumen sengaja dikosongkan supaya
            // worker menghitung subdomainnya sendiri (tidak bergantung nilai
            // APP_DOMAIN yang di-inject `.env` runtime).
            'containers' => [['host_port' => 31001, 'internal_port' => 80]],
        ]);
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();

        $env = [
            'RAMES_DB_DIR' => $this->tmp,
            'RAMES_DB_FILE' => $dbFile,
            'APPS_PATH' => $this->tmp . '/apps',
            'DATABASE_PATH' => $this->tmp . '/database',
        ];

        $result = $this->runCli(['cli/ssl.php', $appId], $env);

        $this->assertSame(1, $result['code'], 'worker SSL harus keluar non-zero');
        $output = $result['stdout'] . $result['stderr'];
        // Anak memuat `.env` runtime (Dotenv mutable), jadi APP_DOMAIN efektif
        // bisa non-publik (mis. `.local`) — keduanya fail-fast SEBELUM certbot:
        // domain server kosong (`APP_DOMAIN` server itu belum diisi) atau domain
        // dasar non-publik. Yang penting tidak ada permintaan sertifikat.
        $this->assertMatchesRegularExpression('/(APP_DOMAIN|domain publik)/', $output);
        $this->assertStringNotContainsString('certbot', strtolower($output));

        $saved = (new AppStore($dbFile))->find($appId);
        $this->assertSame('failed', $saved['ssl_status'] ?? '', 'slot ssl ditandai gagal');
        $this->assertMatchesRegularExpression(
            '/(APP_DOMAIN|domain publik)/',
            (string) ($saved['ssl_error'] ?? ''),
            'alasan penolakan tercatat untuk user'
        );

        // Log worker (runtime/logs/ssl/{appId}.log) tidak memuat token agent —
        // higienitas kredensial. App id unik ⇒ aman dihapus setelah diperiksa.
        $logFile = dirname(__DIR__) . '/runtime/logs/ssl/' . $appId . '.log';
        if (is_file($logFile)) {
            $log = (string) file_get_contents($logFile);
            $this->assertStringNotContainsString('tok-uji', $log);
            $this->assertStringNotContainsString('certbot', strtolower($log), 'certbot tidak boleh dicoba sama sekali');
            @unlink($logFile);
        }
    }

    public function testSslCliRejectsDomainThatDoesNotBelongToTheApp(): void
    {
        $dbFile = $this->tmp . '/cli2.sqlite';
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();

        $appId = 'app-cli-ssl-2';
        (new AppStore($dbFile))->create([
            'id' => $appId,
            'name' => 'cli-app',
            'status' => 'running',
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
        ]);
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();

        $result = $this->runCli(['cli/ssl.php', $appId, 'domain-orang-lain.example.com'], [
            'RAMES_DB_DIR' => $this->tmp,
            'RAMES_DB_FILE' => $dbFile,
            'APPS_PATH' => $this->tmp . '/apps',
            'DATABASE_PATH' => $this->tmp . '/database',
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('bukan subdomain/custom domain', $result['stdout'] . $result['stderr']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Controller dengan antrean SSL ber-basis data temp.
     *
     * `$queueOk = false` menyuntikkan antrean yang **tidak bisa** membuka basis
     * data (`/dev/null/...`) untuk membuktikan jalur gagal-enqueue.
     */
    private function controller(bool $queueOk): SslController
    {
        return new SslController($queueOk ? $this->queue() : $this->brokenQueue());
    }

    private function queue(): SslQueue
    {
        return new SslQueue(new SqliteDatabase($this->tmp . '/rames.sqlite'));
    }

    private function brokenQueue(): SslQueue
    {
        return new SslQueue(new SqliteDatabase('/dev/null/rames-ssl/rames.sqlite'));
    }

    private function post(string $domain = ''): Request
    {
        $body = $domain === '' ? '' : 'domain=' . rawurlencode($domain);

        return new Request(
            "POST /ssl/x/enable HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body)
            . "\r\n\r\n" . $body
        );
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

    private function agent(string $id, array $extra = []): array
    {
        $server = (new ServerStore())->create(array_merge([
            'id' => $id,
            'name' => $id,
            'kind' => 'agent',
            'enabled' => true,
            'domain' => $id . '.example.test',
            'base_url' => 'https://' . $id . '.internal.test:8443',
            'token' => 'tok-uji-' . $id,
            'tls_cert_pem' => self::selfSignedPem(),
            'status' => 'online',
            'port_range' => ['start' => 31000, 'end' => 31999],
        ], $extra));

        ServerResolver::forgetMemo();

        return $server;
    }

    /**
     * Jalankan skrip CLI di subproses dengan env temp (tanpa certbot/Docker nyata).
     *
     * @param array<int,string>    $args argumen relatif `base_path()`
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runCli(array $args, array $env): array
    {
        $command = array_merge([PHP_BINARY], array_merge([dirname(__DIR__) . '/' . array_shift($args)], $args));
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $env + $this->childEnv());
        if (!is_resource($proc)) {
            throw new \RuntimeException('gagal menjalankan CLI');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = (int) proc_close($proc);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string,string> */
    private function childEnv(): array
    {
        return [
            'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $this->tmp,
        ];
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
     * Config temp: store SQLite & direktori app/Nginx diarahkan ke `/tmp` supaya
     * controller (`new AppStore()`/`new ServerStore()`) tidak menyentuh data nyata.
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

    /** Sertifikat self-signed sekali pakai (pola `AgentClientTest`). */
    private static function selfSignedPem(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $exported);

        return $pem = (string) $exported;
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
