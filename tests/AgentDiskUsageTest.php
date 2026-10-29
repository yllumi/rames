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
 * Endpoint agent `GET /system/disk-usage?project=<app-name>` (metrik per-app).
 *
 * Dikunci di sini: validasi bentuk nama project **sebelum** Engine disentuh
 * (invalid ⇒ 400 tanpa menggemakan nilai), filter label compose project pada
 * `/system/df`, galat Engine ⇒ 502 (pola `hostUsage()`), dan respons tidak
 * membawa data mentah Engine (label/id/ukuran per tipe lain).
 */
class AgentDiskUsageTest extends TestCase
{
    public function testInvalidProjectIsRejectedWithoutTouchingTheEngine(): void
    {
        $docker = new AgentDiskUsageFakeDocker();
        $controller = new AgentDockerController(null, $docker);

        foreach (['', '  ', 'Demo', 'demo/web', 'demo web', str_repeat('a', 64), '../etc'] as $invalid) {
            $response = $controller->diskUsage($this->get('/system/disk-usage?project=' . rawurlencode($invalid)));
            $body = $this->payload($response);

            $this->assertSame(400, $response->getStatusCode(), 'project tidak sah harus 400');
            $this->assertSame(1, $body['code']);
            $this->assertSame('Parameter project tidak sah.', $body['error']);
            $this->assertStringNotContainsString($invalid === '' ? '###' : $invalid, (string) $body['error'], 'nilai tidak digemakan');
        }

        $this->assertSame(0, $docker->calls, 'Engine tidak boleh disentuh untuk permintaan tidak sah');
    }

    public function testArrayProjectParameterIsRejectedWithoutTouchingTheEngine(): void
    {
        $docker = new AgentDiskUsageFakeDocker();
        $controller = new AgentDockerController(null, $docker);

        $response = $controller->diskUsage($this->get('/system/disk-usage?project[]=demo'));
        $body = $this->payload($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $body['code']);
        $this->assertSame('Parameter tidak valid.', $body['error']);
        $this->assertSame(0, $docker->calls);
    }

    public function testObjectProjectParameterIsRejectedWithoutTouchingTheEngine(): void
    {
        $docker = new AgentDiskUsageFakeDocker();
        $controller = new AgentDockerController(null, $docker);
        $request = new AgentDiskUsageObjectParamRequest(
            "GET /system/disk-usage HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        $response = $controller->diskUsage($request);
        $body = $this->payload($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $body['code']);
        $this->assertSame('Parameter tidak valid.', $body['error']);
        $this->assertSame(0, $docker->calls);
    }

    public function testValidProjectFiltersDiskUsageByComposeLabel(): void
    {
        $docker = new AgentDiskUsageFakeDocker();
        $docker->df = [
            'Volumes' => [
                ['Name' => 'demo_data', 'Labels' => ['com.docker.compose.project' => 'demo'], 'UsageData' => ['Size' => 1000]],
                ['Name' => 'other_data', 'Labels' => ['com.docker.compose.project' => 'other'], 'UsageData' => ['Size' => 9999]],
                ['Name' => 'shared', 'Labels' => [], 'UsageData' => ['Size' => 7777]],
            ],
            'Containers' => [
                [
                    'Id' => str_repeat('a', 64),
                    'Names' => ['/demo-web-1'],
                    'Labels' => ['com.docker.compose.project' => 'demo'],
                    'SizeRw' => 2048,
                ],
                [
                    'Id' => str_repeat('b', 64),
                    'Names' => ['/other-web-1'],
                    'Labels' => ['com.docker.compose.project' => 'other'],
                    'SizeRw' => 4096,
                ],
            ],
            'Images' => [
                ['Id' => 'sha256:abc', 'Size' => 500000, 'Labels' => []],
            ],
        ];

        $response = (new AgentDockerController(null, $docker))->diskUsage($this->get('/system/disk-usage?project=demo'));
        $body = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame(['volume', 'container'], $docker->types, 'hanya volume + container; image bersama tidak dihitung');
        $this->assertSame(1000, $body['data']['volumes_bytes']);
        $this->assertSame(2048, $body['data']['containers_bytes']);
        $this->assertSame(3048, $body['data']['total_bytes']);
        $this->assertSame('3.048kB', $body['data']['total_human']);
        $this->assertSame([['name' => 'demo_data', 'size' => 1000, 'size_human' => '1.000kB']], $body['data']['volumes']);
        $this->assertSame([['name' => 'demo-web-1', 'size' => 2048, 'size_human' => '2.048kB']], $body['data']['containers']);
        $this->assertNull($body['data']['error']);
        $this->assertIsString($body['data']['at']);
    }

    public function testEngineFailureBecomesMachineFriendlyError(): void
    {
        $docker = new AgentDiskUsageFakeDocker();
        $docker->failure = 'daemon tidak terjangkau';

        $response = (new AgentDockerController(null, $docker))->diskUsage($this->get('/system/disk-usage?project=demo'));
        $body = $this->payload($response);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(1, $body['code']);
        $this->assertSame('disk_failed', $body['reason']);
        $this->assertStringContainsString('daemon tidak terjangkau', (string) $body['error']);
    }

    public function testResponseCarriesNoRawEnginePayload(): void
    {
        $docker = new AgentDiskUsageFakeDocker();
        $docker->df = [
            'Volumes' => [[
                'Name' => 'demo_data',
                'Labels' => ['com.docker.compose.project' => 'demo', 'com.docker.compose.volume' => 'data'],
                'UsageData' => ['Size' => 10, 'RefCount' => 1],
            ]],
            'Containers' => [[
                'Id' => str_repeat('c', 64),
                'Names' => ['/demo-web-1'],
                'Labels' => ['com.docker.compose.project' => 'demo'],
                'SizeRw' => 20,
                'SizeRootFs' => 123456,
            ]],
        ];

        $response = (new AgentDockerController(null, $docker))->diskUsage($this->get('/system/disk-usage?project=demo'));
        $raw = $response->rawBody();

        $this->assertStringNotContainsString('Labels', $raw);
        $this->assertStringNotContainsString('com.docker.compose.project', $raw);
        $this->assertStringNotContainsString('SizeRootFs', $raw);
        $this->assertStringNotContainsString(str_repeat('c', 64), $raw);
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
final class AgentDiskUsageFakeDocker extends DockerClient
{
    /** @var array<string,mixed> */
    public array $df = ['Volumes' => [], 'Containers' => []];

    public ?string $failure = null;

    public int $calls = 0;

    /** @var array<int,string> */
    public array $types = [];

    /** Sengaja tidak memanggil konstruktor induk. */
    public function __construct()
    {
    }

    public function getDiskUsage(array $types = ['volume']): array
    {
        $this->calls++;
        $this->types = $types;
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->df;
    }
}

/**
 * Request dengan parameter `project` berbentuk **objek** (query string hanya
 * bisa menghasilkan array; bentuk objek diuji langsung di lapisan controller).
 */
final class AgentDiskUsageObjectParamRequest extends Request
{
    public function get(?string $name = null, mixed $default = null): mixed
    {
        if ($name === 'project') {
            return new \stdClass();
        }

        return parent::get($name, $default);
    }
}
