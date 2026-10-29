<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployerInterface;
use app\library\Docker\DockerClient;
use app\library\Monitor\AppMetrics;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `Monitor\AppMetrics` — snapshot CPU/RAM & storage **satu app**.
 *
 * Diuji tanpa Docker Engine/jaringan:
 *  - jalur lokal: `DockerClient` tiruan (list + stats + `/system/df`);
 *  - jalur agent: `AgentDeployer` + `AgentClient` dengan handler Guzzle tiruan
 *    (tanpa TLS nyata — sertifikat hanya ditulis ke direktori temp).
 *
 * Kontrak yang dikunci: container app lain tidak ikut, `mem_limit` null bila
 * ada container tanpa limit, dan kegagalan agent/Engine menjadi blok
 * `available:false`/`error` — bukan exception.
 */
class AppMetricsTest extends TestCase
{
    private const APP = [
        'id' => 'app-1',
        'name' => 'demo',
        'server_id' => 'local',
    ];

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-appmetrics-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp . '/*') as $file) {
            if (is_dir($file)) {
                foreach ((array) glob($file . '/*') as $inner) {
                    @unlink((string) $inner);
                }
                @rmdir($file);
                continue;
            }
            @unlink((string) $file);
        }
        @rmdir($this->tmp);
    }

    // ==================================================================
    // Jalur lokal
    // ==================================================================

    public function testLocalUsageOnlyAggregatesContainersOfTheApp(): void
    {
        $docker = new AppMetricsFakeDocker();
        $docker->containers = [
            $this->container('aaaa1111', 'demo-web-1', 'demo', 'web', 'running'),
            $this->container('bbbb2222', 'demo-db-1', 'demo', 'db', 'exited'),
            $this->container('cccc3333', 'other-web-1', 'other', 'web', 'running'),
        ];
        $docker->overview = [
            'aaaa1111' => [
                'stats' => $this->stats(cpuDelta: 100, systemDelta: 1000, online: 2, memUsed: 1000, memLimit: 10000),
                'inspect' => $this->inspect('running', 120),
                'error' => null,
            ],
            'bbbb2222' => [
                'stats' => $this->stats(cpuDelta: 0, systemDelta: 1000, online: 2, memUsed: 500, memLimit: 0),
                'inspect' => ['State' => ['Status' => 'exited', 'Running' => false]],
                'error' => null,
            ],
            'cccc3333' => [
                'stats' => $this->stats(cpuDelta: 900, systemDelta: 1000, online: 2, memUsed: 9000, memLimit: 9000),
                'inspect' => $this->inspect('running', 10),
                'error' => null,
            ],
        ];

        $usage = (new AppMetrics($docker))->usage(self::APP, $this->localDeployer());

        $this->assertNull($usage['error']);
        $this->assertSame(20.0, $usage['cpu_percent'], 'CPU = jumlah % container app');
        $this->assertSame(1500, $usage['mem_used']);
        $this->assertNull($usage['mem_limit'], 'ada container tanpa limit ⇒ total limit tidak bermakna');
        $this->assertNull($usage['mem_percent']);
        $this->assertSame(2, $usage['containers']);
        $this->assertSame(1, $usage['running']);
        $this->assertCount(2, $usage['containers_detail'], 'container app lain tidak ikut');

        $names = array_column($usage['containers_detail'], 'name');
        sort($names);
        $this->assertSame(['demo-db-1', 'demo-web-1'], $names);

        $web = $this->detail($usage['containers_detail'], 'demo-web-1');
        $this->assertSame('web', $web['service']);
        $this->assertSame('running', $web['state']);
        $this->assertSame(20.0, $web['cpu_percent']);
        $this->assertSame(1000, $web['mem_used']);
        $this->assertSame(10000, $web['mem_limit']);
        $this->assertSame(10.0, $web['mem_percent']);
        $this->assertNotNull($web['uptime_seconds']);
        $this->assertNull($web['health']);
    }

    public function testLocalUsageWithoutContainersReportsNullMetricsNotZero(): void
    {
        $docker = new AppMetricsFakeDocker();

        $usage = (new AppMetrics($docker))->usage(self::APP, $this->localDeployer());

        $this->assertNull($usage['error']);
        $this->assertNull($usage['cpu_percent']);
        $this->assertNull($usage['mem_used']);
        $this->assertNull($usage['mem_limit']);
        $this->assertNull($usage['mem_percent']);
        $this->assertSame(0, $usage['containers']);
        $this->assertSame(0, $usage['running']);
        $this->assertSame([], $usage['containers_detail']);
    }

    public function testStoppedContainersReportNullCpuAndMemoryNotZero(): void
    {
        $docker = new AppMetricsFakeDocker();
        $docker->containers = [
            $this->container('aaaa1111', 'demo-web-1', 'demo', 'web', 'exited'),
        ];
        $docker->overview = [
            'aaaa1111' => [
                'stats' => null,
                'inspect' => ['State' => ['Status' => 'exited', 'Running' => false]],
                'error' => null,
            ],
        ];

        $usage = (new AppMetrics($docker))->usage(self::APP, $this->localDeployer());

        $this->assertNull($usage['cpu_percent'], 'container berhenti ⇒ CPU tidak diketahui, bukan 0');
        $this->assertNull($usage['mem_used'], 'container berhenti ⇒ RAM tidak diketahui, bukan 0');
        $this->assertNull($usage['mem_limit']);
        $this->assertNull($usage['mem_percent']);
        $this->assertSame(1, $usage['containers'], 'jumlah container tetap dilaporkan');
        $this->assertSame(0, $usage['running']);
        $this->assertCount(1, $usage['containers_detail']);
        $this->assertSame('exited', $usage['containers_detail'][0]['state']);
        $this->assertNull($usage['containers_detail'][0]['cpu_percent']);
    }

    public function testMixedRunningAndStoppedContainersOnlyCountRunningMetrics(): void
    {
        $docker = new AppMetricsFakeDocker();
        $docker->containers = [
            $this->container('aaaa1111', 'demo-web-1', 'demo', 'web', 'running'),
            $this->container('bbbb2222', 'demo-worker-1', 'demo', 'worker', 'exited'),
        ];
        $docker->overview = [
            'aaaa1111' => [
                'stats' => $this->stats(cpuDelta: 100, systemDelta: 1000, online: 2, memUsed: 100, memLimit: 10000),
                'inspect' => $this->inspect('running', 60),
                'error' => null,
            ],
            'bbbb2222' => [
                'stats' => null,
                'inspect' => ['State' => ['Status' => 'exited', 'Running' => false]],
                'error' => null,
            ],
        ];

        $usage = (new AppMetrics($docker))->usage(self::APP, $this->localDeployer());

        $this->assertSame(20.0, $usage['cpu_percent'], 'hanya container running yang dihitung');
        $this->assertSame(100, $usage['mem_used']);
        $this->assertSame(2, $usage['containers']);
        $this->assertSame(1, $usage['running']);
    }

    public function testLocalUsageReportsEngineFailureInsideThePayload(): void
    {
        $docker = new AppMetricsFakeDocker();
        $docker->failure = 'daemon tidak terjangkau';

        $usage = (new AppMetrics($docker))->usage(self::APP, $this->localDeployer());

        $this->assertStringContainsString('daemon tidak terjangkau', (string) $usage['error']);
        $this->assertNull($usage['cpu_percent']);
        $this->assertSame(0, $usage['containers']);
        $this->assertSame([], $usage['containers_detail']);
    }

    public function testLocalStorageSumsProjectVolumesAndContainers(): void
    {
        $docker = new AppMetricsFakeDocker();
        $docker->df = [
            'Volumes' => [
                ['Name' => 'demo_data', 'Labels' => ['com.docker.compose.project' => 'demo'], 'UsageData' => ['Size' => 1000]],
                ['Name' => 'other_data', 'Labels' => ['com.docker.compose.project' => 'other'], 'UsageData' => ['Size' => 9000]],
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

        $storage = (new AppMetrics($docker))->storage(self::APP, $this->localDeployer());

        $this->assertTrue($storage['available']);
        $this->assertSame(1000, $storage['volumes_bytes']);
        $this->assertSame(2048, $storage['containers_bytes']);
        $this->assertSame(3048, $storage['total_bytes']);
        $this->assertSame(['volume', 'container'], $docker->lastDiskUsageTypes, 'image bersama tidak dihitung');
        $this->assertSame(1, $docker->dfCalls);
    }

    public function testLocalStorageFailureBecomesUnavailableBlock(): void
    {
        $docker = new AppMetricsFakeDocker();
        $docker->dfFailure = 'Engine sibuk';

        $storage = (new AppMetrics($docker))->storage(self::APP, $this->localDeployer());

        $this->assertFalse($storage['available']);
        $this->assertSame(0, $storage['total_bytes']);
        $this->assertSame('Ukuran storage tidak tersedia.', $storage['error']);
    }

    // ==================================================================
    // Jalur agent
    // ==================================================================

    public function testAgentUsageFiltersRowsByAppIdAndForwardsAgentError(): void
    {
        $handler = new AppMetricsFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => [
            'containers' => [
                [
                    'app_id' => 'app-1',
                    'name' => 'demo-web-1',
                    'service' => 'web',
                    'state' => 'running',
                    'cpu_percent' => 12.5,
                    'mem_used' => 100,
                    'mem_limit' => 1000,
                    'mem_percent' => 10.0,
                    'uptime_seconds' => 60,
                    'health' => 'healthy',
                ],
                [
                    'app_id' => 'app-2',
                    'name' => 'other-web-1',
                    'service' => 'web',
                    'state' => 'running',
                    'cpu_percent' => 99.0,
                    'mem_used' => 999,
                    'mem_limit' => 1000,
                    'mem_percent' => 99.9,
                    'uptime_seconds' => 5,
                    'health' => null,
                ],
            ],
            'totals' => [],
            'error' => 'Statistik sebagian gagal di server agent.',
        ]]);

        $usage = (new AppMetrics())->usage(self::APP, $this->agentDeployer($handler));

        $this->assertSame('Statistik sebagian gagal di server agent.', $usage['error']);
        $this->assertSame(12.5, $usage['cpu_percent']);
        $this->assertSame(100, $usage['mem_used']);
        $this->assertSame(1000, $usage['mem_limit']);
        $this->assertSame(10.0, $usage['mem_percent']);
        $this->assertSame(1, $usage['containers']);
        $this->assertSame(1, $usage['running']);
        $this->assertCount(1, $usage['containers_detail']);
        $this->assertSame('demo-web-1', $usage['containers_detail'][0]['name']);
        $this->assertSame('healthy', $usage['containers_detail'][0]['health']);
        $this->assertSame(['/monitor/containers'], $handler->paths);
    }

    public function testAgentUsageTransportFailureBecomesUsageError(): void
    {
        $handler = new AppMetricsFakeHandler();
        $handler->replyJson(['code' => 1, 'error' => 'gagal'], 500);

        $usage = (new AppMetrics())->usage(self::APP, $this->agentDeployer($handler));

        $this->assertNotNull($usage['error']);
        $this->assertNull($usage['cpu_percent']);
        $this->assertSame([], $usage['containers_detail']);
    }

    public function testAgentStorageReturnsBlocksFromTheAgent(): void
    {
        $handler = new AppMetricsFakeHandler();
        $handler->replyJson(['code' => 0, 'data' => [
            'available' => true,
            'volumes_bytes' => 1000,
            'containers_bytes' => 2048,
            'total_bytes' => 3048,
            'total_human' => '3.048kB',
            'volumes' => [['name' => 'demo_data', 'size' => 1000, 'size_human' => '1.000kB']],
            'containers' => [['name' => 'demo-web-1', 'size' => 2048, 'size_human' => '2.048kB']],
            'error' => null,
            'at' => '10:00:00',
        ]]);

        $storage = (new AppMetrics())->storage(self::APP, $this->agentDeployer($handler));

        $this->assertTrue($storage['available']);
        $this->assertSame(3048, $storage['total_bytes']);
        $this->assertSame('3.048kB', $storage['total_human']);
        $this->assertSame([['name' => 'demo_data', 'size' => 1000, 'size_human' => '1.000kB']], $storage['volumes']);
        $this->assertArrayNotHasKey('at', $storage, 'blok storage tidak membawa field khusus respons agent');
    }

    public function testAgentStorageOnOldAgentReportsNotSupported(): void
    {
        $handler = new AppMetricsFakeHandler();
        $handler->replyJson(['code' => 1, 'error' => 'Not Found'], 404);

        $storage = (new AppMetrics())->storage(self::APP, $this->agentDeployer($handler));

        $this->assertFalse($storage['available']);
        $this->assertSame(0, $storage['total_bytes']);
        $this->assertSame('Versi agent belum mendukung ukuran storage.', $storage['error']);
    }

    public function testAgentStorageOnServerErrorReportsGenericMessage(): void
    {
        $handler = new AppMetricsFakeHandler();
        $handler->replyJson(['code' => 1, 'error' => 'Engine gagal'], 500);

        $storage = (new AppMetrics())->storage(self::APP, $this->agentDeployer($handler));

        $this->assertFalse($storage['available']);
        $this->assertSame('Ukuran storage tidak tersedia.', $storage['error']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** Deployer lokal tiruan — `AppMetrics` hanya membedakan agent vs bukan. */
    private function localDeployer(): DeployerInterface
    {
        return new AppMetricsLocalDeployer();
    }

    private function agentDeployer(AppMetricsFakeHandler $handler): AgentDeployer
    {
        $server = [
            'id' => 'srv-x',
            'name' => 'agent-x',
            'kind' => 'agent',
            'base_url' => 'https://srv-x.internal:8443',
            'token' => 'agt_uji_rahasia',
            'tls_cert_pem' => AppMetricsPem::selfSigned(),
        ];

        return new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 10, new TlsPinMaterializer($this->tmp . '/tls')),
            10
        );
    }

    /** @return array<string,mixed> */
    private function container(string $id, string $name, string $project, string $service, string $state): array
    {
        return [
            'Id' => $id,
            'Names' => ['/' . $name],
            'Image' => 'nginx:alpine',
            'State' => $state,
            'Labels' => [
                'com.docker.compose.project' => $project,
                'com.docker.compose.service' => $service,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function stats(int $cpuDelta, int $systemDelta, int $online, int $memUsed, int $memLimit): array
    {
        return [
            'cpu_stats' => [
                'cpu_usage' => ['total_usage' => 1000 + $cpuDelta, 'percpu_usage' => array_fill(0, $online, 1)],
                'system_cpu_usage' => 5000 + $systemDelta,
                'online_cpus' => $online,
            ],
            'precpu_stats' => [
                'cpu_usage' => ['total_usage' => 1000],
                'system_cpu_usage' => 5000,
            ],
            'memory_stats' => [
                'usage' => $memUsed,
                'limit' => $memLimit,
                'stats' => ['inactive_file' => 0],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function inspect(string $status, int $uptime): array
    {
        return ['State' => [
            'Status' => $status,
            'Running' => $status === 'running',
            'StartedAt' => gmdate('Y-m-d\TH:i:s\Z', time() - $uptime),
        ]];
    }

    /**
     * @param array<int,array> $detail
     * @return array<string,mixed>
     */
    private function detail(array $detail, string $name): array
    {
        foreach ($detail as $row) {
            if (($row['name'] ?? '') === $name) {
                return $row;
            }
        }

        $this->fail("detail container {$name} tidak ditemukan");
    }
}

/** `DockerClient` tiruan jalur lokal (tanpa Guzzle/socket). */
final class AppMetricsFakeDocker extends DockerClient
{
    /** @var array<int,array> */
    public array $containers = [];

    /** @var array<string,array> */
    public array $overview = [];

    /** @var array<string,mixed> */
    public array $df = ['Volumes' => [], 'Containers' => []];

    public ?string $failure = null;

    public ?string $dfFailure = null;

    public int $dfCalls = 0;

    /** @var array<int,string> */
    public array $lastDiskUsageTypes = [];

    /** Sengaja tidak memanggil konstruktor induk. */
    public function __construct()
    {
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->containers;
    }

    public function containersOverview(array $ids, int $timeout = 20): array
    {
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->overview;
    }

    public function getDiskUsage(array $types = ['volume']): array
    {
        $this->dfCalls++;
        $this->lastDiskUsageTypes = $types;
        if ($this->dfFailure !== null) {
            throw new RuntimeException($this->dfFailure);
        }

        return $this->df;
    }
}

/** Deployer lokal minimal untuk membedakan jalur agent vs lokal. */
final class AppMetricsLocalDeployer implements DeployerInterface
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

    public function applyLimits(array $app, callable $logger): array
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

/** Handler Guzzle tiruan: membalas respons dari antrean, merekam path. */
final class AppMetricsFakeHandler
{
    /** @var array<int,string> */
    public array $paths = [];

    /** @var array<int,GuzzleResponse> */
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

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('AppMetricsFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->paths[] = $request->getUri()->getPath();
        $next = array_shift($this->queue);

        return Create::promiseFor($next);
    }
}

/** Sertifikat self-signed untuk `TlsPinMaterializer` (tanpa jaringan). */
final class AppMetricsPem
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
