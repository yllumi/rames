<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentNetworkController;
use app\library\Docker\DockerClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

// Helper webman (`json()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Engine Docker server agent tiruan (tanpa socket): merekam mutasi & melayani
 * data network/volume.
 */
final class AgentNetworkFakeDocker extends DockerClient
{
    /** @var array<int,array<string,mixed>> */
    public array $networks = [
        ['Id' => 'net-1', 'Name' => 'agent-shared', 'Driver' => 'bridge', 'Scope' => 'local', 'Containers' => []],
        [
            'Id' => 'net-2',
            'Name' => 'demo_default',
            'Driver' => 'bridge',
            'Scope' => 'local',
            'Containers' => ['c-db' => ['Name' => 'demo-db-1']],
        ],
    ];

    /** @var array<int,array<string,mixed>> */
    public array $created = [];

    /** @var list<array{network:string,container:string,config:array<string,mixed>}> */
    public array $connected = [];

    /** @var list<array{network:string,container:string,force:bool}> */
    public array $disconnected = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var array<int,array<string,mixed>> */
    public array $volumes = [
        ['Name' => 'demo_db'],
        ['Name' => 'demo_uploads'],
        ['Name' => ''],
    ];

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket nyata. */
    public function __construct()
    {
    }

    public function listNetworks(array $filters = []): array
    {
        return $this->networks;
    }

    public function inspectNetwork(string $id): array
    {
        foreach ($this->networks as $network) {
            if ($network['Id'] === $id || $network['Name'] === $id) {
                return $network;
            }
        }

        throw new RuntimeException('HTTP 404: network tidak ditemukan');
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        return [
            ['Id' => 'c-db', 'Names' => ['/demo-db-1'], 'Image' => 'mysql:8', 'State' => 'running'],
            ['Id' => 'c-web', 'Names' => ['/demo-web-1'], 'Image' => 'nginx:alpine', 'State' => 'running'],
        ];
    }

    public function createNetwork(array $config): string
    {
        $this->created[] = $config;

        return 'net-baru';
    }

    public function connectContainerToNetwork(string $networkId, string $containerId, array $endpointConfig = []): void
    {
        $this->connected[] = ['network' => $networkId, 'container' => $containerId, 'config' => $endpointConfig];
    }

    public function disconnectContainerFromNetwork(string $networkId, string $containerId, bool $force = false): void
    {
        $this->disconnected[] = ['network' => $networkId, 'container' => $containerId, 'force' => $force];
    }

    public function removeNetwork(string $id): void
    {
        $this->removed[] = $id;
    }

    public function listVolumesForProject(string $project): array
    {
        return $this->volumes;
    }
}

/**
 * `AgentNetworkController` **sisi agent** (F3c multi-server).
 *
 * Yang dikunci: setiap operasi Engine dijalankan di **server ini** (controller
 * hanya mediator ke `DockerClient`), input divalidasi bentuknya sebelum masuk
 * Engine, dan galat selalu berbentuk JSON `{code:1,error,reason}` dengan status
 * HTTP nyata (kontrak `AgentClient`/`AgentDeployer`).
 */
final class AgentNetworkControllerTest extends TestCase
{
    public function testIndexReturnsNetworksOfThisServer(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $payload = $this->payload($this->controller($docker)->index($this->get('/networks')));

        $this->assertSame(0, $payload['code']);
        $this->assertSame(['agent-shared', 'demo_default'], array_column($payload['data']['networks'], 'Name'));
    }

    public function testDetailListsAttachedContainersAndCandidates(): void
    {
        $payload = $this->payload($this->controller(new AgentNetworkFakeDocker())->detail($this->get('/networks/net-2'), 'net-2'));

        $this->assertSame(0, $payload['code']);
        $this->assertSame('demo_default', $payload['data']['network']['Name']);
        $this->assertSame(['demo-db-1'], array_column($payload['data']['attached'], 'name'));
        // Kandidat = container yang belum terhubung (demo-db-1 sudah).
        $this->assertSame(['demo-web-1'], array_column($payload['data']['candidates'], 'name'));
    }

    public function testVolumesUsesComposeProjectLabelFilterOfThisServer(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $payload = $this->payload($this->controller($docker)->volumes($this->get('/volumes?project=demo')));

        $this->assertSame(0, $payload['code']);
        $this->assertSame(['demo_db', 'demo_uploads'], $payload['data']['volumes']);
    }

    public function testVolumesRejectsInvalidProjectWithoutTouchingEngine(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $response = $this->controller($docker)->volumes($this->get('/volumes?project=../etc'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_project', $this->payload($response)['reason']);
    }

    public function testCreateNetworkPassesSanitizedConfigToTheEngine(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $request = $this->post('/networks/create', [
            'config' => [
                'Name' => 'shared-net',
                'Driver' => 'bridge',
                'Internal' => true,
                'Attachable' => true,
                'CheckDuplicate' => false,
                'IPAM' => ['Driver' => 'default', 'Config' => [['Subnet' => '172.20.0.0/16']]],
                'Labels' => ['rames.role' => 'shared'],
                'Name2' => 'diabaikan',
            ],
        ]);

        $response = $this->controller($docker)->create($request);

        $this->assertSame(200, $response->getStatusCode());
        $config = $docker->created[0];
        $this->assertSame('shared-net', $config['Name']);
        $this->assertTrue($config['Internal']);
        $this->assertTrue($config['CheckDuplicate']);
        $this->assertArrayNotHasKey('Name2', $config);
        $this->assertSame('172.20.0.0/16', $config['IPAM']['Config'][0]['Subnet']);
    }

    public function testCreateRejectsInvalidNameWithoutTouchingEngine(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $response = $this->controller($docker)->create($this->post('/networks/create', ['config' => ['Name' => 'bad name']]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $docker->created);
    }

    public function testConnectAndDisconnectAreExecutedOnThisServer(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $controller = $this->controller($docker);

        $this->assertSame(200, $controller->connect($this->post('/networks/net-1/connect', ['container' => 'demo-web-1', 'alias' => 'web']), 'net-1')->getStatusCode());
        $this->assertSame(200, $controller->disconnect($this->post('/networks/net-1/disconnect', ['container' => 'demo-web-1']), 'net-1')->getStatusCode());

        $this->assertSame('net-1', $docker->connected[0]['network']);
        $this->assertSame('demo-web-1', $docker->connected[0]['container']);
        $this->assertSame(['web'], $docker->connected[0]['config']['Aliases']);
        $this->assertSame('demo-web-1', $docker->disconnected[0]['container']);
        $this->assertFalse($docker->disconnected[0]['force']);
    }

    public function testConnectRejectsInvalidAlias(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $response = $this->controller($docker)->connect(
            $this->post('/networks/net-1/connect', ['container' => 'demo-web-1', 'alias' => 'web; rm']),
            'net-1'
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $docker->connected);
    }

    public function testDeleteRefusesBuiltinNetworkAndRemovesOthers(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $controller = $this->controller($docker);

        $this->assertSame(400, $controller->delete($this->post('/networks/bridge/delete'), 'bridge')->getStatusCode());
        $this->assertSame([], $docker->removed);

        $this->assertSame(200, $controller->delete($this->post('/networks/agent-shared/delete'), 'agent-shared')->getStatusCode());
        $this->assertSame(['agent-shared'], $docker->removed);
    }

    public function testInvalidNetworkReferenceIsRejected(): void
    {
        $docker = new AgentNetworkFakeDocker();
        $response = $this->controller($docker)->detail($this->get('/networks/..%2Fetc'), '..%2Fetc');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_network', $this->payload($response)['reason']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(DockerClient $docker): AgentNetworkController
    {
        return new AgentNetworkController($docker);
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: agent.test\r\nAccept: application/json\r\n\r\n");
    }

    /** @param array<string,mixed> $fields */
    private function post(string $path, array $fields = []): Request
    {
        $body = json_encode($fields, JSON_THROW_ON_ERROR);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: agent.test\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /** @return array<string,mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string) $response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
