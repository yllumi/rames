<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppController;
use app\controller\BackupController;
use app\controller\FileController;
use app\controller\LogController;
use app\controller\MonitorController;
use app\controller\NginxController;
use app\controller\TerminalController;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Files\ContainerFiles;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Template\AgentTemplatePull;
use app\library\Template\TemplatePullState;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Higienitas pesan galat jalur agent di **lima controller** (permintaan
 * verifikasi Auth & Security): pesan yang sampai ke UI/log tidak boleh memuat
 * token, blok PEM, atau identitas internal server (`base_url`/host).
 *
 * Yang diuji di sini adalah **lapisan controller**, bukan `AgentError` itu
 * sendiri (itu sudah punya `tests/AgentErrorTest.php`): buktinya adalah
 * `base_url`/host agent dan blok PEM hilang dari pesan — dua hal yang **hanya**
 * dibuang `AgentError::sanitizeForServer()` (pintu tunggal). Seluruh pemanggil
 * `AgentError::sanitize()` di controller sudah tidak ada lagi.
 *
 * Tanpa jaringan: handler Guzzle tiruan (gagal transport / membalas body).
 */
class AgentErrorRedactionControllersTest extends TestCase
{
    private const TOKEN = 'agt_uji_rahasia_9f7c1';
    private const BASE_URL = 'https://srv-x.internal:8443';
    private const HOST = 'srv-x.internal';
    private const APP_ID = 'a1b2c3d4e5f60718';
    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];

    /** Stub helper global untuk `AppController` (dimuat di proses anak). */
    private const APP_STUB_SOURCE = <<<'PHP'
        <?php
        namespace {
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                    $GLOBALS['RAMES_REDACT_FLASH'][] = ['type' => $type, 'message' => $message];
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
                            return null;
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
        $this->tmp = sys_get_temp_dir() . '/rames_agentredact_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        AgentRedactionApps::$tmp = $this->tmp;

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->tmp . '/rames.sqlite',
                'docker_socket' => $this->tmp . '/tidak-ada-docker.sock',
                'template_pull_run_dir' => $this->tmp . '/template-pull',
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        SqliteFixture::apps($this->tmp . '/rames.sqlite', [
            [
                'id' => self::APP_ID,
                'name' => 'demo',
                'server_id' => 'srv-x',
                'status' => 'running',
                'owner_id' => 'u1',
                'members' => [],
                'primary_service' => 'web',
                'containers' => [
                    ['service_name' => 'web', 'container_name' => 'demo-web-1', 'host_port' => 31001, 'internal_port' => 80],
                ],
            ],
        ]);

        $this->store()->create([
            'id' => 'srv-x',
            'name' => 'agent-x',
            'kind' => 'agent',
            'enabled' => true,
            'domain' => 'srv-x.test',
            'base_url' => self::BASE_URL,
            'token' => self::TOKEN,
            'tls_cert_pem' => AgentRedactionPem::selfSigned(),
            'port_range' => ['start' => 30000, 'end' => 30999],
        ]);
        ServerResolver::forgetMemo();
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        AgentRedactionApps::$tmp = '';
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // Log, Terminal, File (F3a)
    // ==================================================================

    public function testLogControllerHidesAgentIdentityInErrorMessage(): void
    {
        $controller = new AgentRedactionLogController($this->deployerWithTransportFailure());

        $body = $this->json($controller->index($this->get('/api/apps/' . self::APP_ID . '/logs'), self::APP_ID));

        $this->assertSame(502, $body['code']);
        $this->assertNoSecrets((string) $body['msg']);
    }

    public function testTerminalControllerHidesAgentIdentityInErrorMessage(): void
    {
        $controller = new AgentRedactionTerminalController($this->deployerWithTransportFailure());

        $body = $this->json($controller->open(
            $this->post(['container' => 'demo-web-1'], '/api/apps/' . self::APP_ID . '/terminal/open'),
            self::APP_ID
        ));

        $this->assertSame(502, $body['code']);
        $this->assertNoSecrets((string) $body['msg']);
    }

    public function testFileControllerHidesAgentIdentityInErrorMessage(): void
    {
        $controller = new AgentRedactionFileController($this->deployerWithTransportFailure());

        $body = $this->json($controller->list(
            $this->get('/api/apps/' . self::APP_ID . '/files?container=demo-web-1&path=/'),
            self::APP_ID
        ));

        $this->assertSame(502, $body['code']);
        $this->assertNoSecrets((string) $body['msg']);
    }

    // ==================================================================
    // Monitor (F3c)
    // ==================================================================

    public function testMonitorControllerHidesAgentIdentityInServerEntry(): void
    {
        $controller = new AgentRedactionMonitorController(
            $this->app(),
            $this->resolver(),
            new AgentRedactionDocker(),
            ['srv-x' => $this->deployerWithTransportFailure()]
        );

        $body = $this->json($controller->overview($this->get('/api/monitor/overview')));

        $entry = null;
        foreach ($body['data']['servers'] as $server) {
            if (($server['id'] ?? '') === 'srv-x') {
                $entry = $server;
            }
        }

        $this->assertIsArray($entry, 'server agent harus muncul sebagai baris offline');
        $this->assertSame('offline', $entry['status']);
        $this->assertNotSame('', (string) $entry['error']);
        $this->assertNoSecrets((string) $entry['error']);
    }

    // ==================================================================
    // Backup (F4): unduhan dump dari server agent
    // ==================================================================

    public function testBackupDumpDownloadHidesAgentIdentityInErrorMessage(): void
    {
        $controller = new AgentRedactionBackupController($this->app(), $this->deployerWithTransportFailure());

        $body = $this->json($controller->downloadDumpSql(
            $this->get('/backups/dump/sql?volume=demo_data&server_id=srv-x')
        ));

        $this->assertSame(502, $body['code']);
        $this->assertNoSecrets((string) $body['msg']);
    }

    // ==================================================================
    // F4 lanjutan: aksi app, tarik template agent, snapshot backup, panel pull
    // ==================================================================

    /**
     * `AppController::setSubdomain()` — galat `nginx_write` di agent memuat
     * `base_url`/host; flash ke user wajib sudah teredaksi.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppControllerHidesAgentIdentityInErrorMessage(): void
    {
        $this->installAppStubs();

        $response = (new AppController())->setSubdomain(
            $this->post(['subdomain' => 'baru'], '/apps/' . self::APP_ID . '/subdomain'),
            self::APP_ID,
            null,
            $this->agentClientWithTransportFailure()
        );

        $this->assertSame(302, $response->getStatusCode());
        $flash = $GLOBALS['RAMES_REDACT_FLASH'][0] ?? [];
        $this->assertSame('error', $flash['type'] ?? '');
        $this->assertStringContainsString('Gagal menulis config Nginx', (string) ($flash['message'] ?? ''));
        $this->assertNoSecrets((string) ($flash['message'] ?? ''));
    }

    /**
     * `NginxController::pullTemplates()` cabang agent — `AgentError` transport
     * dari `startServerJob()` tidak boleh membocorkan identitas server.
     */
    public function testNginxControllerTemplatePullHidesAgentIdentityInErrorMessage(): void
    {
        $controller = new AgentRedactionNginxController($this->deployerWithTransportFailure());

        $body = $this->json($controller->pullTemplates(
            $this->post(['slug' => 'n8n', 'server' => 'srv-x'], '/nginx/templates/pull')
        ));

        $this->assertSame(400, $body['code']);
        $this->assertNoSecrets((string) $body['msg']);
    }

    /**
     * `BackupController::snapshots()` cabang agent — galat
     * `AgentDeployer::backupSnapshots()` membawa `base_url`/host.
     */
    public function testBackupSnapshotsHidesAgentIdentityInErrorMessage(): void
    {
        $controller = new AgentRedactionBackupController($this->app(), $this->deployerWithTransportFailure());

        $body = $this->json($controller->snapshots(
            $this->get('/api/backups/snapshots?volume=demo_data&server_id=srv-x')
        ));

        $this->assertSame(500, $body['code']);
        $this->assertNoSecrets((string) $body['msg']);
    }

    /**
     * `AgentTemplatePull::panel()` menyimpan galat transport ke `run.message`
     * (dipoll UI) — pesan itu juga harus teredaksi.
     */
    public function testAgentTemplatePullPanelHidesAgentIdentityInRunMessage(): void
    {
        $handler = new AgentRedactionFakeHandler();
        $handler->fail(new ConnectException(
            'cURL error 7: Failed to connect to ' . self::HOST . ' port 8443',
            new GuzzleRequest('GET', self::BASE_URL . '/jobs/job1')
        ));

        $state = new TemplatePullState($this->tmp . '/template-pull', 'srv-x');
        $state->startRun([
            'id' => 'run1',
            'slug' => 'n8n',
            'stage' => 'starting',
            'message' => 'Menarik image …',
            'result' => null,
            'error' => null,
            'log' => 'run1.log',
            'job' => 'job1',
            'log_offset' => 0,
            'pid' => 0,
        ]);

        $pull = new AgentTemplatePull(
            $this->deployer($handler),
            $state,
            'srv-x',
            'agent-x',
            $this->serverDoc()
        );

        $panel = $pull->panel();
        $message = (string) ($panel['run']['message'] ?? '');

        $this->assertNotSame('', $message);
        $this->assertStringContainsString('Menunggu', $message);
        $this->assertNoSecrets($message);
    }

    // ==================================================================
    // Blok PEM yang dipantulkan agent
    // ==================================================================

    public function testPemBlockFromTheAgentNeverReachesTheUser(): void
    {
        $handler = new AgentRedactionFakeHandler();
        $handler->replyJson([
            'code' => 500,
            'msg' => 'sertifikat: -----BEGIN CERTIFICATE----- MIIB -----END CERTIFICATE----- '
                . 'token ' . self::TOKEN,
        ]);

        $controller = new AgentRedactionLogController($this->deployer($handler));

        $body = $this->json($controller->index($this->get('/api/apps/' . self::APP_ID . '/logs'), self::APP_ID));

        // Agent membalas 5xx ⇒ `AgentDeployer::failureCode()` = 502 (bukan 500:
        // yang gagal adalah server agent, bukan endpoint control plane ini).
        $this->assertSame(502, $body['code']);
        $this->assertStringContainsString('sertifikat', (string) $body['msg']);
        $this->assertNoSecrets((string) $body['msg']);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', (string) $body['msg']);
    }

    /** Bukti redaksi juga berlaku bila token/PEM datang lewat `AgentError` langsung. */
    public function testAgentErrorRaisedByThePipelineIsRedactedToo(): void
    {
        $handler = new AgentRedactionFakeHandler();
        $handler->fail(new ConnectException(
            'cURL error 60: sertifikat berubah (' . self::HOST . '), cert '
                . '-----BEGIN CERTIFICATE----- MIIB -----END CERTIFICATE-----',
            new GuzzleRequest('GET', self::BASE_URL . '/healthz')
        ));

        $controller = new AgentRedactionTerminalController($this->deployer($handler));

        $body = $this->json($controller->open(
            $this->post(['container' => 'demo-web-1'], '/api/apps/' . self::APP_ID . '/terminal/open'),
            self::APP_ID
        ));

        $this->assertSame(502, $body['code']);
        $this->assertNoSecrets((string) $body['msg']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** Handler yang selalu gagal di lapisan transport (tanpa jaringan). */
    private function deployerWithTransportFailure(): AgentDeployer
    {
        $handler = new AgentRedactionFakeHandler();
        $handler->fail(new ConnectException(
            'cURL error 7: Failed to connect to ' . self::HOST . ' port 8443',
            new GuzzleRequest('GET', self::BASE_URL . '/healthz')
        ));

        return $this->deployer($handler);
    }

    /** Klien agent yang selalu gagal transport (dipakai seam `$agentClient`). */
    private function agentClientWithTransportFailure(): AgentClient
    {
        $handler = new AgentRedactionFakeHandler();
        $handler->fail(new ConnectException(
            'cURL error 7: Failed to connect to ' . self::HOST . ' port 8443',
            new GuzzleRequest('POST', self::BASE_URL . '/jobs')
        ));

        return new AgentClient($this->serverDoc(), $handler, 20, new TlsPinMaterializer($this->tmp . '/tls'));
    }

    /**
     * Stub helper global untuk `AppController` (hanya dipakai di proses anak).
     * `app/functions.php` dimuat setelah stub agar `current_user`/`flash_set`
     * tidak menuntut session HTTP.
     */
    private function installAppStubs(): void
    {
        $GLOBALS['RAMES_REDACT_FLASH'] = [];

        if (!function_exists('current_user')) {
            $file = $this->tmp . '/app-stubs.php';
            file_put_contents($file, self::APP_STUB_SOURCE);
            require $file;
        }

        if (!function_exists('app_subdomain_of')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }
    }

    private function deployer(AgentRedactionFakeHandler $handler): AgentDeployer
    {
        $server = $this->serverDoc();

        return new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 20, new TlsPinMaterializer($this->tmp . '/tls')),
            30,
            AgentDeployer::MIN_POLL_INTERVAL_MS,
            static function (int $microseconds): void {
            }
        );
    }

    /** @return array<string,mixed> */
    private function serverDoc(): array
    {
        $server = $this->store()->find('srv-x');
        if ($server === null) {
            throw new RuntimeException('server uji tidak dibuat');
        }

        return $server;
    }

    private function resolver(): ServerResolver
    {
        return new ServerResolver($this->store());
    }

    private function store(): ServerStore
    {
        return new ServerStore($this->tmp . '/rames.sqlite');
    }

    /** @return array<string,mixed> */
    private function app(): array
    {
        foreach ((new AppStore($this->tmp . '/rames.sqlite'))->all() as $app) {
            if ((string) ($app['id'] ?? '') === self::APP_ID) {
                return $app;
            }
        }

        throw new RuntimeException('app uji tidak ada');
    }

    /**
     * Needle khas: token, `base_url`/host internal, dan penanda blok PEM. Dua
     * yang terakhir **hanya** dibuang `AgentError::sanitizeForServer()`.
     */
    private function assertNoSecrets(string $message): void
    {
        foreach ([self::TOKEN, self::BASE_URL, self::HOST, 'BEGIN CERTIFICATE'] as $needle) {
            $this->assertStringNotContainsString($needle, $message, 'rahasia/identitas server bocor ke pesan galat');
        }
    }

    /** @return array<string,mixed> */
    private function json(\Webman\Http\Response $response): array
    {
        $decoded = json_decode($response->rawBody(), true);

        if (!is_array($decoded)) {
            $this->fail('respons bukan JSON: ' . $response->rawBody());
        }

        return $decoded;
    }

    /** @param array<string,string> $body */
    private function post(array $body, string $path): Request
    {
        $encoded = http_build_query($body);

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: rames.test\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($encoded)
            . "\r\n\r\n" . $encoded
        );
    }

    private function get(string $path): Request
    {
        return new Request('GET ' . $path . " HTTP/1.1\r\nHost: rames.test\r\n\r\n");
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

/** Handler Guzzle tiruan: gagal transport atau membalas body dari antrean. */
final class AgentRedactionFakeHandler
{
    /** @var array<int,array{method:string,path:string}> */
    public array $calls = [];

    /** @var list<GuzzleResponse|\Throwable> */
    private array $queue = [];

    /** @param array<string,mixed> $payload */
    public function replyJson(array $payload, int $status = 200): self
    {
        $this->queue[] = new GuzzleResponse(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload)
        );

        return $this;
    }

    public function fail(\Throwable $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('AgentRedactionFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/** `LogController` dengan seam uji (tanpa `apps/` nyata, tanpa jaringan). */
final class AgentRedactionLogController extends LogController
{
    public function __construct(private ?AgentDeployer $deployer)
    {
    }

    protected function requireApp(string $id): array
    {
        return AgentRedactionApps::app();
    }

    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return $this->deployer;
    }

    protected function serverResolver(): ServerResolver
    {
        return AgentRedactionApps::resolver();
    }
}

/** `TerminalController` dengan seam uji (tanpa jaringan/sesi nyata). */
final class AgentRedactionTerminalController extends TerminalController
{
    public function __construct(private ?AgentDeployer $deployer)
    {
    }

    protected function requireApp(string $id): array
    {
        return AgentRedactionApps::app();
    }

    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return $this->deployer;
    }

    protected function serverResolver(): ServerResolver
    {
        return AgentRedactionApps::resolver();
    }

    protected function audit(array $app, string $container, string $action): void
    {
    }
}

/** `FileController` dengan seam uji (tanpa daemon lokal nyata). */
final class AgentRedactionFileController extends FileController
{
    public function __construct(private ?AgentDeployer $deployer)
    {
    }

    protected function requireApp(string $id): array
    {
        return AgentRedactionApps::app();
    }

    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return $this->deployer;
    }

    protected function serverResolver(): ServerResolver
    {
        return AgentRedactionApps::resolver();
    }

    protected function containerFiles(): ContainerFiles
    {
        throw new RuntimeException('jalur lokal tidak boleh tersentuh untuk app server agent');
    }

    protected function audit(array $app, string $container, string $action): void
    {
    }
}

/** `NginxController` dengan seam uji: admin tetap, deployer agent disuntik. */
final class AgentRedactionNginxController extends NginxController
{
    public function __construct(private ?AgentDeployer $deployer)
    {
    }

    protected function isAdmin(): bool
    {
        return true;
    }

    protected function actorName(): string
    {
        return 'admin';
    }

    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return $this->deployer;
    }
}

/** `MonitorController` dengan seam uji (tanpa session/Engine nyata). */
final class AgentRedactionMonitorController extends MonitorController
{
    protected function healthGate(): \app\library\Agent\ServerHealthGate
    {
        // Gate baru per panggilan di berkas temp unik: tanpa cooldown lintas uji,
        // tanpa menyentuh `runtime/` nyata.
        return new \app\library\Agent\ServerHealthGate(sys_get_temp_dir() . '/rames-health-' . uniqid('', true) . '.json');
    }

    protected function markServerOnline(array $server): void
    {
    }

    /**
     * @param array<int,array>            $apps   app yang "boleh dilihat" user
     * @param array<string,AgentDeployer> $agents deployer per server id
     */
    public function __construct(
        private array $apps,
        private ServerResolver $resolver,
        private DockerClient $docker,
        private array $agents,
    ) {
    }

    protected function currentUser(): ?array
    {
        return AgentRedactionApps::ADMIN;
    }

    protected function visibleApps(): array
    {
        return $this->apps;
    }

    protected function serverResolver(): ServerResolver
    {
        return $this->resolver;
    }

    protected function dockerClient(): DockerClient
    {
        return $this->docker;
    }

    protected function agentDeployerFor(array $server): ?AgentDeployer
    {
        return $this->agents[(string) ($server['id'] ?? '')] ?? null;
    }
}

/** `BackupController` dengan seam uji: target agent tetap, tanpa Docker lokal. */
final class AgentRedactionBackupController extends BackupController
{
    public function __construct(private array $appRow, private ?AgentDeployer $deployer)
    {
    }

    protected function currentUser(): ?array
    {
        return AgentRedactionApps::ADMIN;
    }

    protected function isAdmin(): bool
    {
        return true;
    }

    protected function volumeTargets(): array
    {
        return [[
            'name' => 'demo_data',
            'project' => 'demo',
            'app_id' => (string) $this->appRow['id'],
            'app_name' => 'demo',
            'orphaned' => false,
            'server_id' => 'srv-x',
        ]];
    }

    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return $this->deployer;
    }

    protected function serverResolver(): ServerResolver
    {
        return AgentRedactionApps::resolver();
    }
}

/** Docker Engine tiruan (tanpa socket). */
final class AgentRedactionDocker extends DockerClient
{
    /** Sengaja tidak memanggil konstruktor induk (tanpa Guzzle/socket). */
    public function __construct()
    {
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        return [];
    }
}

/** Titik akses bersama untuk fake controller (store temp dari uji ini). */
final class AgentRedactionApps
{
    /** User admin uji (satu bentuk untuk semua fake controller). */
    public const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];

    /** Direktori temp uji (diisi `setUp()`). */
    public static string $tmp = '';

    /** @return array<string,mixed> */
    public static function app(): array
    {
        foreach ((new AppStore(self::$tmp . '/rames.sqlite'))->all() as $app) {
            if ((string) ($app['id'] ?? '') === 'a1b2c3d4e5f60718') {
                return $app;
            }
        }

        throw new RuntimeException('app uji tidak ada');
    }

    public static function resolver(): ServerResolver
    {
        return new ServerResolver(new ServerStore(self::$tmp . '/rames.sqlite'));
    }
}

/** Sertifikat self-signed kecil untuk pinning `AgentClient` di uji. */
final class AgentRedactionPem
{
    public static function selfSigned(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent-x.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $out);

        return $pem = (string) $out;
    }
}
