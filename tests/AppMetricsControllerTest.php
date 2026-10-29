<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AppMetricsController;
use app\library\Auth\AppAccessDenied;
use app\library\Deploy\DeployerInterface;
use app\library\Docker\DockerClient;
use app\library\Monitor\AppMetrics;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * `AppMetricsController` — endpoint `GET /api/apps/{id}/metrics`.
 *
 * Kontrak yang dikunci:
 *  - app tak dikenal / user tanpa ability `view` ⇒ **404** (`AppAccessDenied`),
 *    bukan 403; bentuk 404 diperiksa lewat `render($request)` persis seperti
 *    `ExceptionHandler`;
 *  - `storage` hanya dihitung saat `?storage=1`;
 *  - app yatim (server hilang) tetap 200 dengan `usage.error` generik;
 *  - pesan galat agent disanitasi (token/host/PEM tidak muncul).
 *
 * Semua dependensi tiruan/di direktori temp — tanpa Docker/DB runtime nyata.
 */
class AppMetricsControllerTest extends TestCase
{
    private const APP_ID = 'app-1';

    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];
    private const VIEWER = ['id' => 'u2', 'username' => 'viewer', 'role' => 'member'];
    private const STRANGER = ['id' => 'u7', 'username' => 'stranger', 'role' => 'member'];

    private string $tmp = '';

    private AppStore $store;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-appmetrics-ctl-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);

        $this->store = new AppStore($this->tmp . '/rames.sqlite');
        $this->store->create([
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'local',
            'owner_id' => 'u1',
            'members' => ['u2' => 'viewer', 'u3' => 'operator'],
            'containers' => [['service_name' => 'web', 'container_name' => 'demo-web-1']],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmp);
    }

    // ==================================================================
    // Otorisasi: 404, bukan 403
    // ==================================================================

    public function testUnknownAppIsUnauthorizedAs404(): void
    {
        $controller = $this->controller(self::ADMIN);
        $request = $this->get('/api/apps/tidak-ada/metrics');

        $response = $this->asFramework($request, static fn (): Response => $controller->show($request, 'tidak-ada'));

        $this->assertSame(404, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame(404, $body['code']);
        $this->assertSame('Not Found', $body['msg']);
    }

    public function testStrangerIsDeniedWith404(): void
    {
        $controller = $this->controller(self::STRANGER);
        $request = $this->get('/api/apps/' . self::APP_ID . '/metrics');

        $this->assertThrowsAccessDenied(
            static fn (): Response => $controller->show($request, self::APP_ID),
            $request
        );
    }

    public function testViewerMayReadMetrics(): void
    {
        $controller = $this->controller(self::VIEWER);
        $response = $controller->show($this->get('/api/apps/' . self::APP_ID . '/metrics'), self::APP_ID);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->json($response)['code']);
    }

    // ==================================================================
    // Bentuk respons
    // ==================================================================

    public function testResponseShapeWithoutStorage(): void
    {
        $docker = new AppMetricsControllerTestDocker();
        $docker->containers = [$this->container('aaaa1111', 'demo-web-1', 'demo', 'web')];
        $docker->overview = [
            'aaaa1111' => [
                'stats' => [
                    'cpu_stats' => [
                        'cpu_usage' => ['total_usage' => 200, 'percpu_usage' => [1, 1]],
                        'system_cpu_usage' => 2000,
                        'online_cpus' => 2,
                    ],
                    'precpu_stats' => [
                        'cpu_usage' => ['total_usage' => 100],
                        'system_cpu_usage' => 1000,
                    ],
                    'memory_stats' => ['usage' => 500, 'limit' => 1000, 'stats' => ['inactive_file' => 0]],
                ],
                'inspect' => ['State' => ['Status' => 'running', 'Running' => true, 'StartedAt' => gmdate('c', time() - 30)]],
                'error' => null,
            ],
        ];

        $controller = $this->controller(self::VIEWER, $docker);
        $response = $controller->show($this->get('/api/apps/' . self::APP_ID . '/metrics'), self::APP_ID);
        $body = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', (string) $body['data']['at']);
        $this->assertNull($body['data']['storage'], 'tanpa ?storage=1 blok storage tidak dihitung');

        $usage = $body['data']['usage'];
        foreach (['cpu_percent', 'mem_used', 'mem_limit', 'mem_percent', 'containers', 'running', 'containers_detail', 'error'] as $key) {
            $this->assertArrayHasKey($key, $usage, "kunci usage `{$key}` hilang");
        }
        $this->assertSame(20.0, (float) $usage['cpu_percent']);
        $this->assertSame(500, (int) $usage['mem_used']);
        $this->assertSame(1000, (int) $usage['mem_limit']);
        $this->assertSame(50.0, (float) $usage['mem_percent']);
        $this->assertSame(1, $usage['containers']);
        $this->assertSame('demo-web-1', $usage['containers_detail'][0]['name']);
        $this->assertSame(0, $docker->dfCalls, 'Engine /system/df tidak disentuh tanpa permintaan storage');
    }

    public function testStorageBlockOnlyWithExplicitQuery(): void
    {
        $docker = new AppMetricsControllerTestDocker();
        $docker->df = [
            'Volumes' => [
                ['Name' => 'demo_data', 'Labels' => ['com.docker.compose.project' => 'demo'], 'UsageData' => ['Size' => 1000]],
            ],
            'Containers' => [
                [
                    'Id' => str_repeat('a', 64),
                    'Names' => ['/demo-web-1'],
                    'Labels' => ['com.docker.compose.project' => 'demo'],
                    'SizeRw' => 2048,
                ],
            ],
        ];

        $controller = $this->controller(self::VIEWER, $docker);
        $response = $controller->show(
            $this->get('/api/apps/' . self::APP_ID . '/metrics?storage=1'),
            self::APP_ID
        );
        $storage = $this->json($response)['data']['storage'];

        $this->assertIsArray($storage);
        $this->assertTrue($storage['available']);
        $this->assertSame(3048, $storage['total_bytes']);
        $this->assertSame(1, $docker->dfCalls);
    }

    // ==================================================================
    // Kegagalan tidak pernah 500
    // ==================================================================

    public function testOrphanAppStillOpensWithGenericUsageError(): void
    {
        $controller = $this->controller(
            self::VIEWER,
            new AppMetricsControllerTestDocker(),
            deployerError: new RuntimeException('Server "hilang" (srv-x) tidak ada di registry.')
        );

        $response = $controller->show(
            $this->get('/api/apps/' . self::APP_ID . '/metrics?storage=1'),
            self::APP_ID
        );
        $body = $this->json($response);

        $this->assertSame(200, $response->getStatusCode(), 'detail app yatim harus tetap terbuka');
        $this->assertSame(0, $body['code']);
        $this->assertNull($body['data']['usage']['cpu_percent']);
        $this->assertSame('Metrik app tidak tersedia.', $body['data']['usage']['error']);
        $this->assertFalse($body['data']['storage']['available']);
        $this->assertSame(0, $body['data']['storage']['total_bytes']);
    }

    public function testAgentErrorMessageIsSanitized(): void
    {
        $metrics = new AppMetricsControllerTestMetrics([
            'cpu_percent' => null,
            'mem_used' => null,
            'mem_limit' => null,
            'mem_percent' => null,
            'containers' => 0,
            'running' => 0,
            'containers_detail' => [],
            'error' => 'cURL error 7: Failed to connect to srv-x.internal port 8443 '
                . 'via https://srv-x.internal:8443 with token agt_uji_rahasia '
                . '-----BEGIN CERTIFICATE----- MIIB -----END CERTIFICATE-----',
        ]);

        $controller = $this->controller(
            self::VIEWER,
            new AppMetricsControllerTestDocker(),
            server: [
                'id' => 'srv-x',
                'name' => 'agent-x',
                'kind' => 'agent',
                'base_url' => 'https://srv-x.internal:8443',
                'token' => 'agt_uji_rahasia',
            ],
            metrics: $metrics
        );

        $response = $controller->show($this->get('/api/apps/' . self::APP_ID . '/metrics'), self::APP_ID);
        $error = (string) $this->json($response)['data']['usage']['error'];

        $this->assertNotSame('', $error);
        foreach (['agt_uji_rahasia', 'srv-x.internal', 'https://srv-x.internal:8443', 'BEGIN CERTIFICATE'] as $needle) {
            $this->assertStringNotContainsString($needle, $error, 'identitas/rahasia server bocor ke UI');
        }
        $this->assertStringContainsString('server agent', $error);
    }

    public function testAgentStorageErrorMessageIsSanitized(): void
    {
        $metrics = new AppMetricsControllerTestMetrics(
            $this->emptyUsage(),
            [
                'available' => false,
                'volumes_bytes' => 0,
                'containers_bytes' => 0,
                'total_bytes' => 0,
                'total_human' => '0B',
                'volumes' => [],
                'containers' => [],
                'error' => 'Agent https://srv-x.internal:8443 menolak token agt_uji_rahasia '
                    . '-----BEGIN CERTIFICATE----- MIIB -----END CERTIFICATE-----',
            ]
        );

        $controller = $this->controller(
            self::VIEWER,
            new AppMetricsControllerTestDocker(),
            server: [
                'id' => 'srv-x',
                'name' => 'agent-x',
                'kind' => 'agent',
                'base_url' => 'https://srv-x.internal:8443',
                'token' => 'agt_uji_rahasia',
            ],
            metrics: $metrics
        );

        $response = $controller->show(
            $this->get('/api/apps/' . self::APP_ID . '/metrics?storage=1'),
            self::APP_ID
        );
        $storage = $this->json($response)['data']['storage'];

        $this->assertFalse($storage['available']);
        $error = (string) $storage['error'];
        $this->assertNotSame('', $error);
        foreach (['agt_uji_rahasia', 'srv-x.internal', 'https://srv-x.internal:8443', 'BEGIN CERTIFICATE'] as $needle) {
            $this->assertStringNotContainsString($needle, $error, 'identitas/rahasia server bocor ke storage.error');
        }
        $this->assertStringContainsString('server agent', $error);
    }

    // ==================================================================
    // Bentuk parameter query
    // ==================================================================

    public function testArrayStorageParameterIsRejectedWith400(): void
    {
        $controller = $this->controller(self::VIEWER);
        $response = $controller->show(
            $this->get('/api/apps/' . self::APP_ID . '/metrics?storage[]=1'),
            self::APP_ID
        );
        $body = $this->json($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $body['code']);
        $this->assertSame('Parameter tidak valid.', $body['msg']);
        $this->assertStringNotContainsString('storage', (string) $body['msg']);
    }

    public function testObjectStorageParameterIsRejectedWith400(): void
    {
        $controller = $this->controller(self::VIEWER);
        $request = new AppMetricsObjectParamRequest(
            "GET /api/apps/" . self::APP_ID . "/metrics HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        $response = $controller->show($request, self::APP_ID);
        $body = $this->json($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $body['code']);
        $this->assertSame('Parameter tidak valid.', $body['msg']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(
        array $user,
        ?AppMetricsControllerTestDocker $docker = null,
        ?\Throwable $deployerError = null,
        array $server = [],
        ?AppMetrics $metrics = null,
    ): AppMetricsTestController {
        return new AppMetricsTestController(
            $this->store,
            $docker ?? new AppMetricsControllerTestDocker(),
            $user,
            $deployerError,
            $server,
            $metrics
        );
    }

    /** @return array<string,mixed> */
    private function emptyUsage(): array
    {
        return [
            'cpu_percent' => null,
            'mem_used' => null,
            'mem_limit' => null,
            'mem_percent' => null,
            'containers' => 0,
            'running' => 0,
            'containers_detail' => [],
            'error' => null,
        ];
    }

    private function assertThrowsAccessDenied(callable $action, Request $request): void
    {
        try {
            $action();
            $this->fail('AppAccessDenied seharusnya dilempar (404, bukan 403)');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode());
            $response = $e->render($request);
            $this->assertNotNull($response);
            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame(404, $this->json($response)['code']);
        }
    }

    /**
     * Jalankan seperti framework: `AppAccessDenied` dirender lewat
     * `render($request)` (persis `ExceptionHandler::render()`).
     */
    private function asFramework(Request $request, callable $action): Response
    {
        try {
            return $action();
        } catch (AppAccessDenied $e) {
            return $e->render($request) ?? new Response(404);
        }
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->rawBody(), true);
        if (!is_array($decoded)) {
            $this->fail('respons bukan JSON: ' . $response->rawBody());
        }

        return $decoded;
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n");
    }

    /** @return array<string,mixed> */
    private function container(string $id, string $name, string $project, string $service): array
    {
        return [
            'Id' => $id,
            'Names' => ['/' . $name],
            'Image' => 'nginx:alpine',
            'State' => 'running',
            'Labels' => [
                'com.docker.compose.project' => $project,
                'com.docker.compose.service' => $service,
            ],
        ];
    }
}

/** Controller dengan seam uji: user, deployer, server, dan perakit metrik. */
final class AppMetricsTestController extends AppMetricsController
{
    public function __construct(
        AppStore $store,
        DockerClient $docker,
        private ?array $user,
        private ?\Throwable $deployerError = null,
        private array $server = [],
        private ?AppMetrics $metricsOverride = null,
    ) {
        parent::__construct($store, $docker);
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function deployerFor(array $app): DeployerInterface
    {
        if ($this->deployerError !== null) {
            throw $this->deployerError;
        }

        return new AppMetricsControllerTestDeployer();
    }

    protected function serverForApp(array $app): array
    {
        return $this->server;
    }

    protected function metrics(): AppMetrics
    {
        return $this->metricsOverride ?? parent::metrics();
    }
}

/** `AppMetrics` tiruan (hanya untuk menguji sanitasi di controller). */
final class AppMetricsControllerTestMetrics extends AppMetrics
{
    /**
     * @param array<string,mixed>      $usage
     * @param array<string,mixed>|null $storage
     */
    public function __construct(private array $usage, private ?array $storage = null)
    {
        parent::__construct(null);
    }

    public function usage(array $app, DeployerInterface $deployer): array
    {
        return $this->usage;
    }

    public function storage(array $app, DeployerInterface $deployer): array
    {
        return $this->storage ?? parent::storage($app, $deployer);
    }
}

/**
 * Request dengan parameter `storage` berbentuk **objek** (query string hanya
 * bisa menghasilkan array; bentuk objek diuji langsung di lapisan controller).
 */
final class AppMetricsObjectParamRequest extends Request
{
    public function get(?string $name = null, mixed $default = null): mixed
    {
        if ($name === 'storage') {
            return new \stdClass();
        }

        return parent::get($name, $default);
    }
}

/** `DockerClient` tiruan (tanpa socket/Guzzle). */
final class AppMetricsControllerTestDocker extends DockerClient
{
    /** @var array<int,array> */
    public array $containers = [];

    /** @var array<string,array> */
    public array $overview = [];

    /** @var array<string,mixed> */
    public array $df = ['Volumes' => [], 'Containers' => []];

    public int $dfCalls = 0;

    /** Sengaja tidak memanggil konstruktor induk. */
    public function __construct()
    {
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        return $this->containers;
    }

    public function containersOverview(array $ids, int $timeout = 20): array
    {
        return $this->overview;
    }

    public function getDiskUsage(array $types = ['volume']): array
    {
        $this->dfCalls++;

        return $this->df;
    }
}

/** Deployer lokal minimal (membedakan jalur agent vs lokal di `AppMetrics`). */
final class AppMetricsControllerTestDeployer implements DeployerInterface
{
    public function deploy(array $app, callable $logger): array
    {
        return $app;
    }

    public function rebuild(array $app, callable $logger): array
    {
        return $app;
    }

    public function rollback(array $app, string $ref, callable $logger): array
    {
        return $app;
    }

    public function stop(array $app): void
    {
    }

    public function start(array $app): void
    {
    }

    public function apply(array $app, callable $logger): array
    {
        return $app;
    }

    public function applyEnv(array $app, callable $logger): array
    {
        return $app;
    }

    public function teardown(array $app, ?array $preserveVolumes = null): void
    {
    }

    public function getProjectVolumes(string $project): array
    {
        return [];
    }

    public function getContainers(string $project): array
    {
        return [];
    }

    public function ensureWritable(): void
    {
    }

    public function renderNginxConfig(array $app): string
    {
        return '';
    }

    public function writeNginxConfig(array $app): void
    {
    }

    public function removeNginxConfig(array $app): void
    {
    }
}
