<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\LocalDeployer;
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

    public function __construct()
    {
        // sengaja tidak memanggil parent
    }

    public function listVolumesForProject(string $project): array
    {
        return $this->volumes;
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

    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 10);
    }

    public function down(string $project, string $dir, array $files, bool $volumes = true): void
    {
        $this->downCalls[] = $volumes;
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
        parent::__construct($compose, $docker, new TeardownFakeNginxGenerator(), '/tmp/rames-sites');
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
}
