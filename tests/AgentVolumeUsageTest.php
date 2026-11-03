<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentDockerController;
use app\library\Docker\DockerClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Endpoint agent `GET /system/volume-usage` — ukuran tiap volume compose.
 *
 * Dikunci di sini: hanya volume ber-label `com.docker.compose.project` yang
 * dilaporkan (dengan `project` agar control plane dapat menyaring hak akses),
 * volume DB dashboard/agent (`rames.role=dashboard-db|agent-db`) dikecualikan,
 * galat Engine ⇒ 502, dan respons tidak membawa payload mentah Engine.
 */
class AgentVolumeUsageTest extends TestCase
{
    public function testComposeVolumesAreReportedWithProjectAndSize(): void
    {
        $docker = new AgentVolumeUsageFakeDocker();
        $docker->df = ['Volumes' => [
            [
                'Name' => 'demo_data',
                'Labels' => ['com.docker.compose.project' => 'demo', 'com.docker.compose.volume' => 'data'],
                'UsageData' => ['Size' => 1_235_000, 'RefCount' => 2],
                'Mountpoint' => '/var/lib/docker/volumes/demo_data/_data',
            ],
            [
                'Name' => 'other_db',
                'Labels' => ['com.docker.compose.project' => 'other'],
                'UsageData' => ['Size' => 0],
            ],
            [
                'Name' => 'bindless',
                'Labels' => [],
                'UsageData' => ['Size' => 999],
            ],
            [
                'Name' => 'unknown_size',
                'Labels' => ['com.docker.compose.project' => 'other'],
            ],
        ]];

        $response = (new AgentDockerController(null, $docker))->volumeUsage(
            $this->get('/system/volume-usage')
        );
        $body = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame(['volume'], $docker->types, 'cukup tipe volume');
        $this->assertSame([
            ['name' => 'demo_data', 'project' => 'demo', 'size' => 1_235_000, 'ref_count' => 2, 'size_human' => '1.235MB'],
            ['name' => 'other_db', 'project' => 'other', 'size' => 0, 'ref_count' => 0, 'size_human' => '0B'],
            // UsageData absen = Engine tidak melaporkan ukuran → N/A, bukan 0B.
            ['name' => 'unknown_size', 'project' => 'other', 'size' => -1, 'ref_count' => 0, 'size_human' => 'N/A'],
        ], $body['data']['volumes']);
        $this->assertIsString($body['data']['at']);
    }

    public function testDashboardAndAgentDatabaseVolumesAreExcluded(): void
    {
        $docker = new AgentVolumeUsageFakeDocker();
        $docker->df = ['Volumes' => [
            [
                'Name' => 'rames',
                'Labels' => ['com.docker.compose.project' => 'rames', 'rames.role' => 'dashboard-db'],
                'UsageData' => ['Size' => 10],
            ],
            [
                'Name' => 'rames-agent-data',
                'Labels' => ['com.docker.compose.project' => 'rames-agent', 'rames.role' => 'agent-db'],
                'UsageData' => ['Size' => 20],
            ],
            [
                'Name' => 'demo_data',
                'Labels' => ['com.docker.compose.project' => 'demo'],
                'UsageData' => ['Size' => 30],
            ],
        ]];

        $response = (new AgentDockerController(null, $docker))->volumeUsage(
            $this->get('/system/volume-usage')
        );
        $body = $this->payload($response);

        $this->assertSame(['demo_data'], array_column($body['data']['volumes'], 'name'));
    }

    public function testEngineFailureBecomesMachineFriendlyError(): void
    {
        $docker = new AgentVolumeUsageFakeDocker();
        $docker->failure = 'daemon tidak terjangkau';

        $response = (new AgentDockerController(null, $docker))->volumeUsage(
            $this->get('/system/volume-usage')
        );
        $body = $this->payload($response);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(1, $body['code']);
        $this->assertSame('volume_usage_failed', $body['reason']);
        $this->assertStringContainsString('daemon tidak terjangkau', (string) $body['error']);
    }

    public function testResponseCarriesNoRawEnginePayload(): void
    {
        $docker = new AgentVolumeUsageFakeDocker();
        $docker->df = ['Volumes' => [[
            'Name' => 'demo_data',
            'Labels' => ['com.docker.compose.project' => 'demo', 'com.docker.compose.volume' => 'data'],
            'UsageData' => ['Size' => 10, 'RefCount' => 1],
            'Mountpoint' => '/var/lib/docker/volumes/demo_data/_data',
            'Scope' => 'local',
        ]]];

        $response = (new AgentDockerController(null, $docker))->volumeUsage(
            $this->get('/system/volume-usage')
        );
        $raw = (string) $response->rawBody();

        $this->assertStringNotContainsString('Labels', $raw);
        $this->assertStringNotContainsString('com.docker.compose.project', $raw);
        $this->assertStringNotContainsString('Mountpoint', $raw);
        $this->assertStringNotContainsString('/var/lib/docker/volumes', $raw);
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

/** `DockerClient` tiruan sisi agent (tanpa socket/Guzzle). */
final class AgentVolumeUsageFakeDocker extends DockerClient
{
    /** @var array<string,mixed> */
    public array $df = ['Volumes' => []];

    public ?string $failure = null;

    /** @var array<int,string> */
    public array $types = [];

    /** Sengaja tidak memanggil konstruktor induk. */
    public function __construct()
    {
    }

    public function getDiskUsage(array $types = ['volume']): array
    {
        $this->types = $types;
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->df;
    }
}
