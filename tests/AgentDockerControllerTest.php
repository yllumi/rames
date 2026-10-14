<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentDockerController;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

// Helper webman (`json()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * `DockerClient` tiruan sisi agent: merekam panggilan Engine **server ini** dan
 * bisa disuruh gagal keras (membuktikan metrik tidak pernah diambil dari Engine
 * lain, dan kegagalan Engine tidak menjatuhkan respons).
 */
final class AgentDockerFakeDocker extends DockerClient
{
    public int $listCalls = 0;

    public int $overviewCalls = 0;

    /** @var array<int,array> */
    public array $containers = [];

    /** @var array<string,array> */
    public array $overview = [];

    public ?string $failure = null;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada Guzzle/socket. */
    public function __construct()
    {
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        $this->listCalls++;
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->containers;
    }

    public function containersOverview(array $ids, int $timeout = 20): array
    {
        $this->overviewCalls++;
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->overview;
    }
}

/** Store proyeksi agent yang rusak: konstruksi aman, pembacaan gagal. */
final class AgentDockerBrokenStore extends AppStore
{
    /** Sengaja tidak memanggil konstruktor induk (tanpa sqlite). */
    public function __construct()
    {
    }

    public function all(): array
    {
        throw new RuntimeException('basis data proyeksi agent tidak terbaca');
    }
}

/** `AgentDockerController` dengan seam uji (`/proc` & Engine temp/tiruan). */
final class AgentDockerTestController extends AgentDockerController
{
    public function __construct(
        AppStore $store,
        DockerClient $docker,
        private string $procPath,
    ) {
        parent::__construct($store, $docker);
    }

    protected function hostProcPath(): string
    {
        return $this->procPath;
    }
}

/**
 * Endpoint monitoring **sisi agent** (F3c, plan §4.1/§5).
 *
 * Kontrak yang dikunci:
 *  1. pemakaian host dibaca dari `/proc` **server agent**
 *     (`deploy.host_proc_path` server itu) — bukan `/proc` control plane;
 *  2. statistik container dirakit `ResourceCollector` dari Engine **server ini**,
 *     dengan `managed`/`app_id` dari proyeksi app agent (penyaringan hak akses
 *     tetap milik control plane, jadi endpoint ini tidak menyaring);
 *  3. kegagalan Engine ⇒ respons tetap `code:0` dengan `error` terisi (satu
 *     server rusak tidak boleh mematikan halaman monitor agregat);
 *  4. basis data agent tidak terbaca ⇒ galat mesin JSON (bukan halaman HTML).
 *
 * Semua uji tanpa jaringan, tanpa Docker, tanpa `apps/`/`runtime/` nyata.
 */
class AgentDockerControllerTest extends TestCase
{
    private const APP_ID = 'app-a1';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agent-docker-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmp);
    }

    public function testHostUsageReadsTheAgentsOwnProcPath(): void
    {
        $proc = $this->tmp . '/proc';
        mkdir($proc, 0700);
        file_put_contents($proc . '/meminfo', "MemTotal: 4000 kB\nMemAvailable: 1000 kB\n");
        file_put_contents($proc . '/loadavg', "1.50 0.40 0.30 1/100 999\n");
        file_put_contents($proc . '/uptime', "120.5 10.0\n");
        file_put_contents($proc . '/stat', "cpu  10 0 10 80 0 0 0 0 0 0\n");

        $docker = new AgentDockerFakeDocker();
        $controller = new AgentDockerTestController($this->store(), $docker, $proc);

        $response = $controller->hostUsage($this->get('/system/host-usage'));
        $body = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame($proc, $body['data']['host']['proc_path'], 'host diambil dari /proc server agent');
        $this->assertSame(4000 * 1024, $body['data']['host']['mem_total']);
        $this->assertSame(3000 * 1024, $body['data']['host']['mem_used']);
        $this->assertSame(75.0, (float) $body['data']['host']['mem_percent']);
        $this->assertSame(1.5, $body['data']['host']['load_1']);
        $this->assertSame(120, $body['data']['host']['uptime_seconds']);
        $this->assertIsString($body['data']['at']);

        $this->assertSame(0, $docker->listCalls, 'metrik host tidak menyentuh Engine');
    }

    public function testContainersComeFromTheAgentsEngineAndCarryAppIdentity(): void
    {
        $docker = new AgentDockerFakeDocker();
        $docker->containers = [
            $this->container('managed-id-123456', 'demo-web-1', [
                'com.docker.compose.project' => 'demo',
                'com.docker.compose.service' => 'web',
            ]),
            $this->container('external-id-654321', 'redis', []),
        ];
        $docker->overview = [
            'managed-id-123456' => ['stats' => null, 'inspect' => null, 'error' => 'HTTP 409 Conflict'],
        ];

        $controller = new AgentDockerTestController($this->store(), $docker, $this->tmp . '/proc');
        $body = $this->payload($controller->containers($this->get('/monitor/containers')));

        $this->assertSame(0, $body['code']);
        $rows = $body['data']['containers'];
        $this->assertCount(2, $rows, 'agent mengirim semua container host-nya; penyaringan di control plane');
        $this->assertSame(1, $docker->listCalls);
        $this->assertSame(1, $docker->overviewCalls);

        $managed = $rows[0];
        $this->assertSame('demo-web-1', $managed['name']);
        $this->assertTrue($managed['managed'], 'project cocok app proyeksi agent');
        $this->assertSame(self::APP_ID, $managed['app_id']);
        $this->assertSame('demo', $managed['app_name']);

        $external = $rows[1];
        $this->assertFalse($external['managed']);
        $this->assertNull($external['app_id']);
        $this->assertSame('', $external['project']);

        $this->assertIsArray($body['data']['totals']);
        $this->assertNull($body['data']['error']);
    }

    public function testEngineFailureIsReportedInsideThePayloadInsteadOfFailing(): void
    {
        $docker = new AgentDockerFakeDocker();
        $docker->failure = 'daemon tidak terjangkau';

        $controller = new AgentDockerTestController($this->store(), $docker, $this->tmp . '/proc');
        $response = $controller->containers($this->get('/monitor/containers'));
        $body = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code'], 'kegagalan Engine bukan kegagalan endpoint');
        $this->assertStringContainsString('daemon tidak terjangkau', (string) $body['data']['error']);
        $this->assertSame([], $body['data']['containers']);
        $this->assertIsArray($body['data']['totals']);
    }

    public function testUnreadableAgentStoreBecomesAMachineError(): void
    {
        // Store proyeksi agent yang gagal dibaca (mis. basis data rusak): API
        // mesin tetap menjawab JSON, bukan halaman HTML/exception.
        $controller = new AgentDockerTestController(
            new AgentDockerBrokenStore(),
            new AgentDockerFakeDocker(),
            $this->tmp . '/proc'
        );

        $response = $controller->containers($this->get('/monitor/containers'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('store_failed', $this->payload($response)['reason']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** Store proyeksi agent (path temp) berisi satu app `demo`. */
    private function store(): AppStore
    {
        $store = new AppStore($this->tmp . '/rames.sqlite');
        $store->create([
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'local',
            'containers' => [['service_name' => 'web', 'container_name' => 'demo-web-1']],
        ]);

        return $store;
    }

    /** @param array<string,string> $labels */
    private function container(string $id, string $name, array $labels, string $image = 'nginx:alpine'): array
    {
        return [
            'Id' => $id,
            'Names' => ['/' . $name],
            'Image' => $image,
            'State' => 'running',
            'Labels' => $labels,
        ];
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n");
    }

    /** @return array<string,mixed> */
    private function payload(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
