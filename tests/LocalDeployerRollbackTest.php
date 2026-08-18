<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\EnvManager;
use app\library\Deploy\LocalDeployer;
use app\library\Deploy\NetworkManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Git\GitService;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fake DockerClient — menghindari kebutuhan ext-curl / socket daemon.
 */
class FakeDockerClient extends DockerClient
{
    public function __construct()
    {
        // sengaja tidak memanggil parent
    }

    public function listContainersForProject(string $project): array
    {
        return [];
    }
}

/**
 * Fake DockerComposeRunner — merekam pemanggilan, bisa diset gagal.
 */
class FakeComposeRunner extends DockerComposeRunner
{
    /** @var array<int,array> */
    public array $calls = [];
    public int $upFailRemaining = 0;

    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 10);
    }

    public function up(string $project, string $dir, array $files, bool $build = true, ?string $envFile = null): void
    {
        $this->calls[] = ['up', $project, $build];
        if ($this->upFailRemaining > 0) {
            $this->upFailRemaining--;
            throw new RuntimeException('docker compose up gagal (simulasi)');
        }
    }
}

/**
 * Fake NginxConfigGenerator — no-op (hindari pemanggilan config() pada test).
 */
class FakeNginxGenerator extends NginxConfigGenerator
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
 * LocalDeployer dengan container & tulis Nginx yang di-stub untuk test.
 */
class TestLocalDeployer extends LocalDeployer
{
    /** @var array<int,array> */
    public array $containers = [];

    public function __construct(DockerComposeRunner $compose, NginxConfigGenerator $nginx, string $sitesPath)
    {
        parent::__construct($compose, new FakeDockerClient(), $nginx, $sitesPath, new EnvManager(sys_get_temp_dir() . '/rames-test-env'), new NetworkManager());
    }

    public function getContainers(string $project): array
    {
        return $this->containers;
    }

    public function writeNginxConfig(array $site): void
    {
    }

    public function renderNginxConfig(array $site): string
    {
        return 'mock';
    }
}

/**
 * Test alur rollback LocalDeployer terhadap repo git lokal dengan
 * docker compose / nginx yang di-fake.
 */
class LocalDeployerRollbackTest extends TestCase
{
    private GitTestFixture $fx;
    private string $siteDir;
    private array $containers;

    protected function setUp(): void
    {
        $this->fx = GitTestFixture::create();
        $this->siteDir = $this->fx->workDir . '/sites/myapp';
        $this->fx->cloneShallow($this->siteDir); // clone shallow seperti sistem create
        $this->containers = [
            [
                'service_name' => 'web',
                'container_name' => 'myapp-web-1',
                'image' => 'myapp:latest',
                'internal_port' => 8080,
                'host_port' => 30001,
                'status' => 'running',
            ],
        ];
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    private function makeDeployer(FakeComposeRunner $compose, FakeNginxGenerator $nginx): TestLocalDeployer
    {
        $deployer = new TestLocalDeployer($compose, $nginx, $this->fx->workDir . '/sites');
        $deployer->containers = $this->containers;
        return $deployer;
    }

    private function makeSite(): array
    {
        return [
            'id' => 'site-1',
            'name' => 'myapp',
            'branch' => 'main',
            'repo_url' => $this->fx->origin,
            'local_path' => 'sites/myapp',
            'primary_service' => 'web',
            'status' => 'running',
            'auth_method' => 'none',
            'compose_files' => ['docker-compose.yml'],
            'containers' => [],
        ];
    }

    public function testRollbackSuccessMovesSourceAndRecordsHistory(): void
    {
        $compose = new FakeComposeRunner();
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $site = $deployer->rollback($this->makeSite(), $this->fx->v1, fn () => null);

        $this->assertSame('running', $site['status']);
        // source benar-benar pindah ke v1
        $this->assertSame($this->fx->v1, (new GitService())->revParse($this->siteDir));
        $this->assertFileExists($this->siteDir . '/v1.txt');
        $this->assertFileDoesNotExist($this->siteDir . '/v2.txt');
        // compose up dijalankan dengan build
        $this->assertSame([['up', 'myapp', true]], $compose->calls);
        // history tercatat
        $this->assertCount(1, $site['deploy_history']);
        $this->assertSame('rollback', $site['deploy_history'][0]['action']);
        $this->assertSame('success', $site['deploy_history'][0]['status']);
        $this->assertSame($this->fx->v1, $site['deploy_history'][0]['sha']);
    }

    public function testRollbackRestoresPreviousVersionWhenBuildFails(): void
    {
        $compose = new FakeComposeRunner();
        $compose->upFailRemaining = 1; // up rollback gagal, up restore sukses
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $site = $deployer->rollback($this->makeSite(), $this->fx->v1, fn () => null);

        $this->assertSame('running', $site['status']);
        // source dikembalikan ke v2 (versi yang tadinya aktif)
        $this->assertSame($this->fx->v2, (new GitService())->revParse($this->siteDir));
        $this->assertCount(2, $compose->calls); // rollback + restore
        $last = $site['deploy_history'][0];
        $this->assertSame('rollback', $last['action']);
        $this->assertSame('restored', $last['status']);
        $this->assertSame($this->fx->v2, $last['sha']);
    }

    public function testRollbackToActiveVersionIsRejected(): void
    {
        $deployer = $this->makeDeployer(new FakeComposeRunner(), new FakeNginxGenerator());

        $this->expectException(RuntimeException::class);
        $deployer->rollback($this->makeSite(), $this->fx->v2, fn () => null);
    }

    public function testRollbackThrowsWhenRestoreAlsoFails(): void
    {
        $compose = new FakeComposeRunner();
        $compose->upFailRemaining = 2; // rollback & restore sama-sama gagal
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('docker compose up gagal (simulasi)');
        $deployer->rollback($this->makeSite(), $this->fx->v1, fn () => null);
    }

    public function testRecordHistoryKeepsLastTwentyEntries(): void
    {
        $deployer = $this->makeDeployer(new FakeComposeRunner(), new FakeNginxGenerator());
        $site = $this->makeSite();

        $method = new \ReflectionMethod(LocalDeployer::class, 'recordHistory');
        for ($i = 0; $i < 25; $i++) {
            $sha = str_pad((string) $i, 40, '0', STR_PAD_LEFT);
            $site = $method->invoke($deployer, $site, $sha, 'rebuild', 'success');
        }

        $this->assertCount(20, $site['deploy_history']);
        $this->assertSame(str_pad('5', 40, '0', STR_PAD_LEFT), $site['deploy_history'][0]['sha']);
        $this->assertSame(str_pad('24', 40, '0', STR_PAD_LEFT), $site['deploy_history'][19]['sha']);
    }
}
