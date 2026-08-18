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

    public function stopContainer(string $id): void
    {
        $this->stopped[] = $id;
    }

    public function removeContainer(string $id, bool $force = true, bool $removeVolumes = true): void
    {
        $this->removedContainers[] = $id;
    }

    public function removeNetwork(string $id): void
    {
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

    public function render(int $hostPort, array $servers): string
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
        parent::__construct($compose, $docker, new TeardownFakeNginxGenerator(), '/tmp/rames-sites', new EnvManager(sys_get_temp_dir() . '/rames-test-env'), new NetworkManager());
    }

    public function renderNginxConfig(array $site): string
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

    protected function setUp(): void
    {
        $this->compose = new TeardownFakeComposeRunner();
        $this->docker = new TeardownFakeDockerClient();
        $this->compose->docker = $this->docker;
        $this->deployer = new TeardownTestDeployer($this->compose, $this->docker);
    }

    private function makeSite(): array
    {
        return [
            'id' => 'site-1',
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
        $this->deployer->teardown($this->makeSite(), null);

        $this->assertSame([true], $this->compose->downCalls); // down -v
        $this->assertSame([], $this->compose->removedVolumes);
    }

    public function testPreserveAllKeepsAllVolumes(): void
    {
        $this->docker->volumes = [
            ['Name' => 'myapp_db', 'Labels' => ['com.docker.compose.project' => 'myapp']],
            ['Name' => 'myapp_cache', 'Labels' => ['com.docker.compose.project' => 'myapp']],
        ];
        $this->deployer->teardown($this->makeSite(), ['myapp_db', 'myapp_cache']);

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
        $this->deployer->teardown($this->makeSite(), ['myapp_db']);

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
        $this->deployer->teardown($this->makeSite(), []);

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

        $site = $this->makeSite();
        $site['name'] = 'halo';
        $this->deployer->teardown($site, null); // purge

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

        $site = $this->makeSite();
        $site['name'] = 'proj';
        $this->deployer->teardown($site, ['proj_db']);

        $this->assertSame(['c1'], $this->docker->removedContainers);
        $this->assertSame(['n1'], $this->docker->removedNetworks);
        // fallback tidak menghapus volume (preserve) — pemanggil hapus yang tak dipertahankan
        $this->assertSame(['proj_cache'], $this->compose->removedVolumes);
    }
}
