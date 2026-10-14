<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Docker\DockerClient;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslIssuer;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Webman\Config;

// Helper webman (`config()`, `app_subdomain*()`, `flash_*`) hanya tersedia bila
// `app/functions.php` dimuat — di proses anak stubs dipasang lebih dulu
// (`installStubs()`), jadi require di sini hanya menyiapkan konstanta.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Agent palsu yang **menjawab sendiri** siklus job (`POST /jobs` → status →
 * log) sehingga uji tidak perlu mengantre 11 respons manual, dan tetap merekam
 * setiap panggilan untuk diperiksa (op apa saja, berapa kali).
 *
 * `$failOps` = op yang jobnya berakhir `error`; `$unsupportedOps` = op yang
 * ditolak **501** (agent versi lama tanpa op itu).
 */
final class AppHostIsolationFakeAgent
{
    /** @var array<int,array{method:string,path:string,body:string}> */
    public array $calls = [];

    /** @var array<string,string> op => pesan error */
    public array $failOps = [];

    /** @var array<int,string> op yang belum didukung agent (501) */
    public array $unsupportedOps = [];

    private int $seq = 0;

    /** @var array<string,string> job_id => op */
    private array $jobOps = [];

    /** @return array<int,string> op job yang diterima, berurutan */
    public function ops(): array
    {
        $ops = [];
        foreach ($this->calls as $call) {
            if ($call['path'] !== '/jobs' || $call['method'] !== 'POST') {
                continue;
            }
            $decoded = json_decode($call['body'], true);
            $ops[] = is_array($decoded) ? (string) ($decoded['op'] ?? '') : '';
        }

        return $ops;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /** @return array<string,mixed> body JSON panggilan ke-`$index` */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->calls[$index]['body'] ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    public function __invoke(object $request, array $options): mixed
    {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $body = (string) $request->getBody();
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

        if ($method === 'POST' && $path === '/jobs') {
            $decoded = json_decode($body, true);
            $op = is_array($decoded) ? (string) ($decoded['op'] ?? '') : '';
            if (in_array($op, $this->unsupportedOps, true)) {
                return Create::promiseFor(new Response(501, ['Content-Type' => 'application/json'], (string) json_encode([
                    'code' => 1,
                    'error' => 'Op tidak didukung agent ini.',
                    'reason' => 'unsupported',
                ])));
            }

            $id = 'job' . (++$this->seq);
            $this->jobOps[$id] = $op;

            return Create::promiseFor($this->json(['code' => 0, 'job_id' => $id, 'op' => $op, 'log_offset' => 0]));
        }

        if ($method === 'GET' && preg_match('#^/jobs/([A-Za-z0-9_-]+)$#', $path, $match) === 1) {
            $op = $this->jobOps[$match[1]] ?? '';
            if (isset($this->failOps[$op])) {
                return Create::promiseFor($this->json(['code' => 0, 'job' => ['status' => 'error', 'error' => $this->failOps[$op]]]));
            }

            return Create::promiseFor($this->json(['code' => 0, 'job' => ['status' => 'done']]));
        }

        if ($method === 'GET' && str_ends_with($path, '/log')) {
            return Create::promiseFor(new Response(200, ['Content-Type' => 'text/plain'], ''));
        }

        return Create::promiseFor(new Response(404, ['Content-Type' => 'application/json'], '{"code":1,"error":"tidak ada"}'));
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }
}

/** Reloader lokal tiruan: menghitung panggilan, tanpa Docker/nginx nyata. */
final class AppHostIsolationLocalReloader extends NginxReloader
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

/** Penerbit lokal tiruan: menghitung revoke, tanpa certbot. */
final class AppHostIsolationLocalIssuer extends SslIssuer
{
    /** @var array<int,string> */
    public array $revoked = [];

    public function __construct()
    {
    }

    public function revoke(string $domain): void
    {
        $this->revoked[] = $domain;
    }
}

/** Klien Docker tiruan: menghitung panggilan daftar network, tanpa socket. */
final class AppHostIsolationLocalDocker extends DockerClient
{
    public int $listCalls = 0;

    /** @var array<int,array<string,mixed>> */
    public array $networks = [];

    public function __construct()
    {
        // Sengaja tidak memanggil parent::__construct() (tidak menyentuh socket).
    }

    public function listNetworks(array $filters = []): array
    {
        $this->listCalls++;

        return $this->networks;
    }
}

/**
 * Isolasi host untuk app di server agent (perbaikan bug Tinggi hasil verifikasi
 * Rames Assure): `setSubdomain`, `saveRoutes`, `setDomain`, `removeDomain`, dan
 * daftar network `detail()` **tidak boleh** menyentuh host control plane.
 *
 * Yang dikunci:
 *  1. `setSubdomain`/`saveRoutes` pada app agent ⇒ `NginxReloader` lokal **0**
 *     panggilan (spy) dan reload dikirim ke agent (op `nginx_reload`);
 *  2. jalur rollback guard (reload agent gagal) ⇒ tetap nol panggilan lokal;
 *  3. `setDomain`/`removeDomain` app agent ⇒ op `ssl_revoke` di agent,
 *     `SslIssuer` lokal **tidak** dipanggil;
 *  4. app lokal: keempat jalur berperilaku sama seperti sebelumnya (reloader &
 *     issuer lokal dipakai, tanpa panggilan agent);
 *  5. agent yang belum mendukung op ⇒ fail-fast dengan pesan jelas (tidak ada
 *     fallback lokal, tidak ada sukses palsu);
 *  6. `detail()`/`availableNetworksFor()` app agent ⇒ daftar network host lokal
 *     tidak pernah ditawarkan (dan klien Docker lokal tidak dipanggil).
 *
 * Data, config, & store 100% di `sys_get_temp_dir()`; tidak ada Docker, nginx,
 * certbot, `database/`, `apps/`, atau `nginx-status/` nyata yang disentuh.
 */
class AppControllerAgentHostIsolationTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const AGENT_ID = 'vps-agung';
    private const TOKEN = 'tok-uji-isolasi-77c1';

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
        $this->tmp = sys_get_temp_dir() . '/rames-host-isolation-' . bin2hex(random_bytes(5));
        foreach (['config', 'apps', 'nginx/conf', 'nginx-status', 'sessions', 'tls'] as $dir) {
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
    // (i)/(ii) subdomain & rute: reload di agent, bukan di host ini
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSetSubdomainOnAgentAppReloadsAtTheAgentAndNeverLocally(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->agentApp();
        $agent = new AppHostIsolationFakeAgent();
        $local = new AppHostIsolationLocalReloader();

        $response = $this->controller()->setSubdomain($this->post('subdomain=baru'), $app['id'], $local, $this->client($agent));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $local->calls, 'NginxReloader lokal tidak boleh dipanggil untuk app agent');
        $this->assertSame(['nginx_write', 'nginx_reload'], $agent->ops());
        $this->assertSame('success', $GLOBALS['RAMES_FLASH'][0]['type'] ?? '');
        $this->assertStringContainsString('baru.', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaveRoutesOnAgentAppReloadsAtTheAgentAndNeverLocally(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->agentApp();
        $agent = new AppHostIsolationFakeAgent();
        $local = new AppHostIsolationLocalReloader();

        $response = $this->controller()->saveRoutes(
            $this->post('routes=' . rawurlencode('/api/ http://127.0.0.1:3001')),
            $app['id'],
            $local,
            $this->client($agent)
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $local->calls);
        $this->assertSame(['nginx_write', 'nginx_reload'], $agent->ops());
        $this->assertStringContainsString('rute proxy disimpan', $GLOBALS['RAMES_FLASH'][0]['message'] ?? '');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentSubdomainRollbackNeverTouchesTheLocalHost(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->agentApp();
        $agent = new AppHostIsolationFakeAgent();
        $agent->failOps['nginx_reload'] = 'nginx -t gagal: versi ' . self::TOKEN;
        $local = new AppHostIsolationLocalReloader();

        $this->controller()->setSubdomain($this->post('subdomain=baru'), $app['id'], $local, $this->client($agent));

        $this->assertSame(0, $local->calls, 'jalur rollback pun tidak boleh menyentuh host lokal');
        // write → reload (gagal) → write ulang config lama (rollback) — semuanya di agent.
        $this->assertSame(['nginx_write', 'nginx_reload', 'nginx_write'], $agent->ops());

        $flash = $GLOBALS['RAMES_FLASH'][0] ?? [];
        $this->assertSame('error', $flash['type'] ?? '');
        $this->assertStringContainsString('nginx -t gagal', (string) ($flash['message'] ?? ''), 'pesan agent diteruskan ke user');
        $this->assertStringNotContainsString(self::TOKEN, (string) ($flash['message'] ?? ''), 'token agent tidak boleh bocor ke UI');
        // Rollback guard: field `subdomain` dikembalikan ke kondisi semula (absen).
        $this->assertArrayNotHasKey('subdomain', (new AppStore())->find($app['id']) ?? []);
    }

    // ==================================================================
    // (iii) custom domain: revoke di agent
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSetDomainOnAgentAppRevokesAtTheAgentAndNeverLocally(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->agentApp(['custom_domain' => 'lama.example.com']);
        $agent = new AppHostIsolationFakeAgent();
        $local = new AppHostIsolationLocalReloader();
        $issuer = new AppHostIsolationLocalIssuer();

        $response = $this->controller()->setDomain(
            $this->post('domain=baru.example.com'),
            $app['id'],
            $local,
            $this->client($agent),
            $issuer
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $issuer->revoked, 'SslIssuer lokal TIDAK boleh dipakai untuk app agent');
        $this->assertSame(0, $local->calls);
        $this->assertSame(['ssl_revoke', 'nginx_write', 'nginx_reload'], $agent->ops());
        $this->assertSame('lama.example.com', $agent->jsonBody(0)['domain'] ?? null);
        $this->assertSame('baru.example.com', (new AppStore())->find($app['id'])['custom_domain'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRemoveDomainOnAgentAppRevokesAtTheAgentAndNeverLocally(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->agentApp(['custom_domain' => 'lama.example.com']);
        $agent = new AppHostIsolationFakeAgent();
        $local = new AppHostIsolationLocalReloader();
        $issuer = new AppHostIsolationLocalIssuer();

        $response = $this->controller()->removeDomain($this->post(), $app['id'], $local, $this->client($agent), $issuer);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $issuer->revoked);
        $this->assertSame(0, $local->calls);
        $this->assertSame(['ssl_revoke', 'nginx_write', 'nginx_reload'], $agent->ops());
        $this->assertSame('lama.example.com', $agent->jsonBody(0)['domain'] ?? null);
        $this->assertNull((new AppStore())->find($app['id'])['custom_domain'] ?? null);
    }

    // ==================================================================
    // (iv) regresi eksplisit: app lokal tidak berubah
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLocalAppKeepsLegacyBehaviourOnAllFourPaths(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->localApp(['custom_domain' => 'lama.example.com']);
        $local = new AppHostIsolationLocalReloader();
        $issuer = new AppHostIsolationLocalIssuer();
        $controller = $this->controller();

        $controller->setSubdomain($this->post('subdomain=lokal-baru'), $app['id'], $local);
        $this->assertSame(1, $local->calls, 'setSubdomain lokal tetap reload di host ini');
        $this->assertSame([], $issuer->revoked);

        $controller->saveRoutes($this->post('routes=' . rawurlencode('/api/ http://127.0.0.1:3001')), $app['id'], $local);
        $this->assertSame(2, $local->calls, 'saveRoutes lokal tetap reload di host ini');

        $controller->setDomain($this->post('domain=baru.example.com'), $app['id'], $local, null, $issuer);
        $this->assertSame(3, $local->calls);
        $this->assertSame(['lama.example.com'], $issuer->revoked, 'revoke sertifikat lama tetap di host ini');

        $controller->removeDomain($this->post(), $app['id'], $local, null, $issuer);
        $this->assertSame(4, $local->calls);
        $this->assertSame(['lama.example.com', 'baru.example.com'], $issuer->revoked);

        // Sanity: tidak ada artefak agent yang dibuat (agent client memang tidak diberikan).
        $this->assertFileExists($this->tmp . '/nginx/conf/' . $app['name'] . '.conf', 'config Nginx lokal tetap ditulis seperti sebelumnya');
    }

    // ==================================================================
    // (v) agent tanpa dukungan ⇒ fail-fast
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAgentWithoutTheWriteOpFailsFastWithoutLocalFallback(): void
    {
        $this->installStubs(self::ADMIN);
        $app = $this->agentApp();
        $agent = new AppHostIsolationFakeAgent();
        $agent->unsupportedOps = ['nginx_write'];
        $local = new AppHostIsolationLocalReloader();

        $this->controller()->setSubdomain($this->post('subdomain=baru'), $app['id'], $local, $this->client($agent));

        $this->assertSame(0, $local->calls);
        $flash = $GLOBALS['RAMES_FLASH'][0] ?? [];
        $this->assertSame('error', $flash['type'] ?? '');
        $this->assertStringContainsString('tidak didukung', (string) ($flash['message'] ?? ''));
        // Subdomain tidak boleh "sukses" tersimpan bila config tak bisa diterapkan.
        $this->assertArrayNotHasKey('subdomain', (new AppStore())->find($app['id']) ?? [], 'rollback guard: field subdomain dipulihkan');
    }

    // ==================================================================
    // (vi) detail(): network host lokal tidak ditawarkan untuk app agent
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAvailableNetworksForAgentAppsNeverOffersLocalNetworks(): void
    {
        $this->installStubs(self::ADMIN);
        $docker = new AppHostIsolationLocalDocker();
        $docker->networks = [
            ['Name' => 'shared-net', 'Labels' => []],
        ];

        $method = new ReflectionMethod(AppController::class, 'availableNetworksFor');
        $method->setAccessible(true);
        $controller = $this->controller();

        $this->assertSame(
            [],
            $method->invoke($controller, [], true, $docker),
            'app agent tidak boleh ditawari network host control plane'
        );
        $this->assertSame(0, $docker->listCalls, 'Engine host control plane tidak boleh dibaca untuk app agent');

        // App lokal: perilaku lama (daftar network lokal tetap ditawarkan).
        $this->assertSame(['shared-net'], $method->invoke($controller, [], false, $docker));
        $this->assertSame(1, $docker->listCalls);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(): AppController
    {
        return new AppController();
    }

    private function post(string $body = ''): Request
    {
        return new Request(
            "POST /apps/x HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body)
            . "\r\n\r\n" . $body
        );
    }

    private function client(AppHostIsolationFakeAgent $handler): AgentClient
    {
        return new AgentClient($this->serverDoc(), $handler, 25, new TlsPinMaterializer($this->tmp . '/tls'));
    }

    /** @return array<string,mixed> */
    private function serverDoc(): array
    {
        return [
            'id' => self::AGENT_ID,
            'name' => self::AGENT_ID,
            'kind' => 'agent',
            'base_url' => 'https://agung.internal.test:8443',
            'token' => self::TOKEN,
            'domain' => 'agung.example.test',
            'tls_cert_pem' => self::selfSignedPem(),
        ];
    }

    /** App di server agent (punya host port agar vhost/rute berlaku). */
    private function agentApp(array $overrides = []): array
    {
        (new ServerStore())->create([
            'id' => self::AGENT_ID,
            'name' => self::AGENT_ID,
            'kind' => 'agent',
            'enabled' => true,
            'domain' => 'agung.example.test',
            'base_url' => 'https://agung.internal.test:8443',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::selfSignedPem(),
            'status' => 'online',
            'port_range' => ['start' => 31000, 'end' => 31999],
        ]);
        ServerResolver::forgetMemo();

        return (new AppStore())->create(array_merge([
            'name' => 'agen-app',
            'server_id' => self::AGENT_ID,
            'status' => 'running',
            'containers' => [['host_port' => 31001, 'internal_port' => 80]],
        ], $overrides));
    }

    /** App di server lokal. */
    private function localApp(array $overrides = []): array
    {
        return (new AppStore())->create(array_merge([
            'name' => 'lokal-app',
            'status' => 'running',
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
        ], $overrides));
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
