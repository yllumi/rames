<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\EnvManager;
use app\library\Deploy\LocalDeployer;
use app\library\Deploy\NetworkManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Fake DockerClient untuk teardown — mengembalikan daftar volume project.
 * (Menghindari kebutuhan ext-curl / socket daemon.)
 */
class TeardownFakeDockerClient extends DockerClient
{
    /** @var array<int,array> */
    public array $volumes = [];
    /** @var array<int,array> */
    public array $containers = [];
    /** @var array<int,array> */
    public array $networks = [];
    /** @var array<int,string> */
    public array $stopped = [];
    /** @var array<int,string> */
    public array $removedContainers = [];
    /** @var array<int,string> */
    public array $removedNetworks = [];

    /** @var array<string,array> network Id => detail hasil inspectNetwork */
    public array $networkDetails = [];
    /** @var array<string,array<string,string>> container Id => Labels (inspectContainer) */
    public array $containerLabels = [];
    /** @var array<string,string> container Id => pesan error disconnect (mis. 403) */
    public array $disconnectErrors = [];
    /** @var array<string,string> network Id => pesan error removeNetwork (mis. 403) */
    public array $removeNetworkErrors = [];
    /** @var array<int,array{network:string,container:string}> */
    public array $disconnected = [];
    /** @var array<int,string> urutan operasi: disconnect:<id> / removeNetwork:<id> */
    public array $ops = [];
    /** @var bool simulasi inspectNetwork gagal (mis. 404/403) */
    public bool $inspectNetworkThrows = false;

    public function __construct()
    {
        // sengaja tidak memanggil parent
    }

    public function listVolumesForProject(string $project): array
    {
        return $this->volumes;
    }

    public function listContainersForProject(string $project): array
    {
        return $this->containers;
    }

    public function listNetworksForProject(string $project): array
    {
        return $this->networks;
    }

    public function inspectNetwork(string $id): array
    {
        if ($this->inspectNetworkThrows) {
            throw new \RuntimeException('gagal inspect network: HTTP 404 Not Found');
        }
        return $this->networkDetails[$id] ?? ['Id' => $id, 'Name' => '', 'Containers' => []];
    }

    public function inspectContainer(string $id): array
    {
        return ['Config' => ['Labels' => $this->containerLabels[$id] ?? []]];
    }

    public function stopContainer(string $id): void
    {
        $this->stopped[] = $id;
    }

    public function removeContainer(string $id, bool $force = true, bool $removeVolumes = true): void
    {
        $this->removedContainers[] = $id;
    }

    public function disconnectContainerFromNetwork(string $networkId, string $containerId, bool $force = false): void
    {
        $this->ops[] = 'disconnect:' . $containerId;
        if (isset($this->disconnectErrors[$containerId])) {
            throw new \RuntimeException($this->disconnectErrors[$containerId]);
        }
        $this->disconnected[] = ['network' => $networkId, 'container' => $containerId];

        // Simulasi efek nyata: attachment hilang setelah diputus (detach idempoten);
        // pada kasus gagal (403) attachment TETAP menempel.
        foreach ($this->networkDetails as $nid => $detail) {
            unset($this->networkDetails[$nid]['Containers'][$containerId]);
        }
    }

    public function removeNetwork(string $id): void
    {
        $this->ops[] = 'removeNetwork:' . $id;
        if (isset($this->removeNetworkErrors[$id])) {
            throw new \RuntimeException($this->removeNetworkErrors[$id]);
        }
        $this->removedNetworks[] = $id;
    }
}

/**
 * Fake DockerComposeRunner — merekam pemanggilan down (apakah -v) & volume rm.
 */
class TeardownFakeComposeRunner extends DockerComposeRunner
{
    /** @var array<int,bool> true = down -v (hapus semua volume) */
    public array $downCalls = [];
    /** @var array<int,string> */
    public array $removedVolumes = [];
    /** @var bool simulasikan down gagal (compose project tak bisa dimuat) */
    public bool $downThrows = false;
    /** @var TeardownFakeDockerClient|null referensi untuk simulasi down -v */
    public ?TeardownFakeDockerClient $docker = null;

    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 10);
    }

    public function down(string $project, string $dir, array $files, bool $volumes = true, ?string $envFile = null): void
    {
        $this->downCalls[] = $volumes;
        if ($this->downThrows) {
            throw new \RuntimeException('service "mariadb" has neither an image nor a build context specified: invalid compose project');
        }
        if ($volumes && $this->docker !== null) {
            // down -v menghapus semua volume (simulasi perilaku nyata)
            $this->docker->volumes = [];
        }
    }

    public function removeVolumes(array $names): void
    {
        $this->removedVolumes = array_merge($this->removedVolumes, $names);
    }
}

/**
 * Fake NginxConfigGenerator — no-op (hindari pemanggilan config() pada test).
 */
class TeardownFakeNginxGenerator extends NginxConfigGenerator
{
    public function __construct()
    {
        parent::__construct('/tmp/rames-nginx', '/tmp/rames-nginx');
    }

    public function ensureWritable(): void
    {
    }

    public function render(int $hostPort, array $servers, array $routes = []): string
    {
        return 'mock';
    }

    public function write(string $name, string $content): void
    {
    }

    public function remove(string $name): void
    {
    }
}

/**
 * LocalDeployer dengan fake docker client + fake compose untuk test teardown.
 */
class TeardownTestDeployer extends LocalDeployer
{
    public function __construct(DockerComposeRunner $compose, TeardownFakeDockerClient $docker)
    {
        parent::__construct($compose, $docker, new TeardownFakeNginxGenerator(), '/tmp/rames-apps', new EnvManager(sys_get_temp_dir() . '/rames-test-env'), new NetworkManager());
    }

    public function renderNginxConfig(array $app): string
    {
        return 'mock';
    }
}

/**
 * Test teardown selektif volume (preserve / purge) di LocalDeployer.
 */
class LocalDeployerTeardownTest extends TestCase
{
    private TeardownFakeComposeRunner $compose;
    private TeardownFakeDockerClient $docker;
    private TeardownTestDeployer $deployer;

    /** @var array<int,array{stage:string,message:string}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->compose = new TeardownFakeComposeRunner();
        $this->docker = new TeardownFakeDockerClient();
        $this->compose->docker = $this->docker;
        $this->deployer = new TeardownTestDeployer($this->compose, $this->docker);
        $this->logs = [];
    }

    /** Logger yang merekam seluruh peringatan non-fatal teardown. */
    private function logger(): callable
    {
        return function (string $stage, string $message): void {
            $this->logs[] = ['stage' => $stage, 'message' => $message];
        };
    }

    /** @return array<int,string> */
    private function logMessages(): array
    {
        return array_map(static fn (array $l): string => $l['message'], $this->logs);
    }

    private function makeApp(): array
    {
        return [
            'id' => 'app-1',
            'name' => 'myapp',
            'compose_files' => ['docker-compose.yml'],
            'status' => 'running',
        ];
    }

    public function testPurgeRunsDownWithVolumes(): void
    {
        $this->docker->volumes = [
            ['Name' => 'myapp_db', 'Labels' => ['com.docker.compose.project' => 'myapp']],
        ];
        $this->deployer->teardown($this->makeApp(), null);

        $this->assertSame([true], $this->compose->downCalls); // down -v
        $this->assertSame([], $this->compose->removedVolumes);
    }

    public function testPreserveAllKeepsAllVolumes(): void
    {
        $this->docker->volumes = [
            ['Name' => 'myapp_db', 'Labels' => ['com.docker.compose.project' => 'myapp']],
            ['Name' => 'myapp_cache', 'Labels' => ['com.docker.compose.project' => 'myapp']],
        ];
        $this->deployer->teardown($this->makeApp(), ['myapp_db', 'myapp_cache']);

        $this->assertSame([false], $this->compose->downCalls); // down TANPA -v
        $this->assertSame([], $this->compose->removedVolumes);
    }

    public function testPreserveSomeRemovesOnlyUnchecked(): void
    {
        $this->docker->volumes = [
            ['Name' => 'myapp_db', 'Labels' => ['com.docker.compose.project' => 'myapp']],
            ['Name' => 'myapp_cache', 'Labels' => ['com.docker.compose.project' => 'myapp']],
            ['Name' => 'myapp_logs', 'Labels' => ['com.docker.compose.project' => 'myapp']],
        ];
        $this->deployer->teardown($this->makeApp(), ['myapp_db']);

        $this->assertSame([false], $this->compose->downCalls);
        sort($this->compose->removedVolumes);
        $this->assertSame(['myapp_cache', 'myapp_logs'], $this->compose->removedVolumes);
    }

    public function testPreserveNoneRemovesAllProjectVolumes(): void
    {
        $this->docker->volumes = [
            ['Name' => 'myapp_db', 'Labels' => ['com.docker.compose.project' => 'myapp']],
            ['Name' => 'myapp_cache', 'Labels' => ['com.docker.compose.project' => 'myapp']],
        ];
        $this->deployer->teardown($this->makeApp(), []);

        $this->assertSame([false], $this->compose->downCalls);
        sort($this->compose->removedVolumes);
        $this->assertSame(['myapp_cache', 'myapp_db'], $this->compose->removedVolumes);
    }

    public function testGetProjectVolumesReturnsOnlyNamedVolumes(): void
    {
        $this->docker->volumes = [
            ['Name' => 'myapp_db', 'Labels' => ['com.docker.compose.project' => 'myapp']],
            ['Name' => '', 'Labels' => ['com.docker.compose.project' => 'myapp']],
        ];
        $this->assertSame(['myapp_db'], $this->deployer->getProjectVolumes('myapp'));
    }

    public function testDownFailureFallsBackToApiTeardownPurge(): void
    {
        // Simulasikan compose project tidak bisa dimuat (override stale) —
        // down melempar, teardown harus tetap berhasil via Engine API.
        $this->compose->downThrows = true;
        $this->docker->containers = [
            ['Id' => 'c1', 'State' => 'running', 'Names' => ['/testdocker-nginx']],
            ['Id' => 'c2', 'State' => 'exited', 'Names' => ['/testdocker-mariadb']],
        ];
        $this->docker->networks = [['Id' => 'n1', 'Name' => 'halo_default']];
        $this->docker->volumes = [
            ['Name' => 'halo_db', 'Labels' => ['com.docker.compose.project' => 'halo']],
        ];

        $app = $this->makeApp();
        $app['name'] = 'halo';
        $this->deployer->teardown($app, null); // purge

        $this->assertSame(['c1'], $this->docker->stopped); // hanya running yang distop
        sort($this->docker->removedContainers);
        $this->assertSame(['c1', 'c2'], $this->docker->removedContainers);
        $this->assertSame(['n1'], $this->docker->removedNetworks);
        $this->assertSame(['halo_db'], $this->compose->removedVolumes); // purge -> volume ikut dihapus
    }

    public function testDownFailureFallbackPreserveRemovesOnlyUncheckedVolumes(): void
    {
        $this->compose->downThrows = true;
        $this->docker->containers = [['Id' => 'c1', 'State' => 'running', 'Names' => ['/x']]];
        $this->docker->networks = [['Id' => 'n1', 'Name' => 'proj_default']];
        $this->docker->volumes = [
            ['Name' => 'proj_db', 'Labels' => ['com.docker.compose.project' => 'proj']],
            ['Name' => 'proj_cache', 'Labels' => ['com.docker.compose.project' => 'proj']],
        ];

        $app = $this->makeApp();
        $app['name'] = 'proj';
        $this->deployer->teardown($app, ['proj_db']);

        $this->assertSame(['c1'], $this->docker->removedContainers);
        $this->assertSame(['n1'], $this->docker->removedNetworks);
        // fallback tidak menghapus volume (preserve) — pemanggil hapus yang tak dipertahankan
        $this->assertSame(['proj_cache'], $this->compose->removedVolumes);
    }

    /**
     * Helper: network project dengan daftar container yang menempel.
     *
     * @param array<string,array{name:string}> $attached container id => nama
     */
    private function attachToProjectNetwork(string $networkId, array $attached): void
    {
        $this->docker->networks = [['Id' => $networkId, 'Name' => 'myapp_default']];
        $containers = [];
        foreach ($attached as $id => $meta) {
            $containers[$id] = ['Name' => $meta['name']];
        }
        $this->docker->networkDetails[$networkId] = [
            'Id' => $networkId,
            'Name' => 'myapp_default',
            'Containers' => $containers,
        ];
    }

    // ==================================================================
    // Container asing di network project (temuan TINGGI Fase 5b)
    // ==================================================================

    public function testContainerAsingDiputusSebelumNetworkDihapus(): void
    {
        $this->attachToProjectNetwork('n1', ['foreign1' => ['name' => '/rames-webman']]);
        $this->docker->containerLabels['foreign1'] = ['com.docker.compose.project' => 'rames'];

        $this->deployer->teardown($this->makeApp(), null, $this->logger());

        // Attachment dilepas TANPA menghapus container asing.
        $this->assertSame([['network' => 'n1', 'container' => 'foreign1']], $this->docker->disconnected);
        $this->assertSame([], $this->docker->removedContainers);
        $this->assertSame(['n1'], $this->docker->removedNetworks);

        // Urutan: disconnect HARUS mendahului removeNetwork.
        $disconnectAt = array_search('disconnect:foreign1', $this->docker->ops, true);
        $removeAt = array_search('removeNetwork:n1', $this->docker->ops, true);
        $this->assertIsInt($disconnectAt);
        $this->assertIsInt($removeAt);
        $this->assertTrue($disconnectAt < $removeAt, 'disconnect harus sebelum removeNetwork');
    }

    public function testContainerMilikProjectTidakDiputus(): void
    {
        // own1 terdaftar milik project (filter label) & own2 hanya dikenali dari
        // label hasil inspect — keduanya TIDAK boleh dilepas.
        $this->docker->containers = [
            ['Id' => 'own1', 'State' => 'running', 'Names' => ['/myapp-web'], 'Labels' => ['com.docker.compose.project' => 'myapp']],
        ];
        $this->attachToProjectNetwork('n1', [
            'own1' => ['name' => '/myapp-web'],
            'own2' => ['name' => '/myapp-db'],
            'foreign1' => ['name' => '/rames-adminer'],
        ]);
        $this->docker->containerLabels['own1'] = ['com.docker.compose.project' => 'myapp'];
        $this->docker->containerLabels['own2'] = ['com.docker.compose.project' => 'myapp'];
        $this->docker->containerLabels['foreign1'] = ['rames.role' => 'adminer-helper'];

        $this->deployer->teardown($this->makeApp(), null, $this->logger());

        $this->assertSame([['network' => 'n1', 'container' => 'foreign1']], $this->docker->disconnected);
    }

    public function testInspectNetworkGagalTidakMelepasApaPun(): void
    {
        // Konservatif: tanpa detail network, jangan menebak siapa yang menempel.
        $this->attachToProjectNetwork('n1', ['foreign1' => ['name' => '/rames-webman']]);
        $this->docker->inspectNetworkThrows = true;

        $this->deployer->teardown($this->makeApp(), null, $this->logger());

        $this->assertSame([], $this->docker->disconnected);
        $this->assertSame([true], $this->compose->downCalls);
        $this->assertStringContainsString('tidak dapat di-inspect', implode("\n", $this->logMessages()));
    }

    public function testDisconnectGagal403TidakMenggagalkanTeardown(): void
    {
        $this->attachToProjectNetwork('n1', ['foreign1' => ['name' => '/rames-webman']]);
        $this->docker->disconnectErrors['foreign1'] = 'Operasi Docker gagal: HTTP 403 Forbidden';

        $this->deployer->teardown($this->makeApp(), null, $this->logger());

        $this->assertSame([true], $this->compose->downCalls); // teardown lanjut
        $this->assertSame(['n1'], $this->docker->removedNetworks);
        $messages = implode("\n", $this->logMessages());
        $this->assertStringContainsString('rames-webman', $messages);
        $this->assertStringContainsString('403', $messages);
    }

    public function testNetworkGagalDihapus403TidakMembatalkanTeardownDanDicatat(): void
    {
        $this->docker->networks = [['Id' => 'n1', 'Name' => 'myapp_default']];
        $this->docker->removeNetworkErrors['n1'] = 'Operasi Docker gagal: HTTP 403 Forbidden';

        $this->deployer->teardown($this->makeApp(), null, $this->logger());

        // Teardown selesai (down tetap dijalankan) & TIDAK mengklaim network terhapus.
        $this->assertSame([true], $this->compose->downCalls);
        $this->assertSame([], $this->docker->removedNetworks);

        $messages = implode("\n", $this->logMessages());
        $this->assertStringContainsString('PERINGATAN', $messages);
        $this->assertStringContainsString('myapp_default', $messages);
        $this->assertStringContainsString('GAGAL dihapus', $messages);
        $this->assertStringContainsString('403', $messages);
    }
}
