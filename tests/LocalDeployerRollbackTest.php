<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ComposeSource;
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
 * Fake DockerClient dengan publish dual-stack — Docker Engine mengembalikan satu
 * entri `Ports[]` per alamat IP (IPv4 0.0.0.0 + IPv6 ::) dengan PublicPort yang
 * sama, sehingga tanpa dedupe port tersimpan tampil dua kali.
 */
class DualStackDockerClient extends DockerClient
{
    public function __construct()
    {
        // sengaja tidak memanggil parent
    }

    public function listContainersForProject(string $project): array
    {
        return [[
            'Id' => 'hermesan-id',
            'Image' => 'nousresearch/hermes-agent:latest',
            'State' => 'running',
            'Names' => ['/hermesan'],
            'Labels' => ['com.docker.compose.service' => 'hermes'],
            'Ports' => [
                ['IP' => '0.0.0.0', 'PrivatePort' => 8642, 'PublicPort' => 8642, 'Type' => 'tcp'],
                ['IP' => '::', 'PrivatePort' => 8642, 'PublicPort' => 8642, 'Type' => 'tcp'],
                ['IP' => '0.0.0.0', 'PrivatePort' => 9119, 'PublicPort' => 9119, 'Type' => 'tcp'],
                ['IP' => '::', 'PrivatePort' => 9119, 'PublicPort' => 9119, 'Type' => 'tcp'],
            ],
        ]];
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

    public function __construct(DockerComposeRunner $compose, NginxConfigGenerator $nginx, string $appsPath)
    {
        parent::__construct($compose, new FakeDockerClient(), $nginx, $appsPath, new EnvManager(sys_get_temp_dir() . '/rames-test-env'), new NetworkManager());
    }

    public function getContainers(string $project): array
    {
        return $this->containers;
    }

    public function writeNginxConfig(array $app): void
    {
    }

    public function renderNginxConfig(array $app): string
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
    private string $appDir;
    private array $containers;

    protected function setUp(): void
    {
        $this->fx = GitTestFixture::create();
        $this->appDir = $this->fx->workDir . '/apps/myapp';
        $this->fx->cloneShallow($this->appDir); // clone shallow seperti sistem create
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
        $deployer = new TestLocalDeployer($compose, $nginx, $this->fx->workDir . '/apps');
        $deployer->containers = $this->containers;
        return $deployer;
    }

    private function makeApp(): array
    {
        return [
            'id' => 'app-1',
            'name' => 'myapp',
            'branch' => 'main',
            'repo_url' => $this->fx->origin,
            'local_path' => 'apps/myapp',
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

        $app = $deployer->rollback($this->makeApp(), $this->fx->v1, fn () => null);

        $this->assertSame('running', $app['status']);
        // source benar-benar pindah ke v1
        $this->assertSame($this->fx->v1, (new GitService())->revParse($this->appDir));
        $this->assertFileExists($this->appDir . '/v1.txt');
        $this->assertFileDoesNotExist($this->appDir . '/v2.txt');
        // compose up dijalankan dengan build
        $this->assertSame([['up', 'myapp', true]], $compose->calls);
        // history tercatat
        $this->assertCount(1, $app['deploy_history']);
        $this->assertSame('rollback', $app['deploy_history'][0]['action']);
        $this->assertSame('success', $app['deploy_history'][0]['status']);
        $this->assertSame($this->fx->v1, $app['deploy_history'][0]['sha']);
    }

    public function testRollbackRestoresPreviousVersionWhenBuildFails(): void
    {
        $compose = new FakeComposeRunner();
        $compose->upFailRemaining = 1; // up rollback gagal, up restore sukses
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $app = $deployer->rollback($this->makeApp(), $this->fx->v1, fn () => null);

        $this->assertSame('running', $app['status']);
        // source dikembalikan ke v2 (versi yang tadinya aktif)
        $this->assertSame($this->fx->v2, (new GitService())->revParse($this->appDir));
        $this->assertCount(2, $compose->calls); // rollback + restore
        $last = $app['deploy_history'][0];
        $this->assertSame('rollback', $last['action']);
        $this->assertSame('restored', $last['status']);
        $this->assertSame($this->fx->v2, $last['sha']);
    }

    public function testRollbackToActiveVersionIsRejected(): void
    {
        $deployer = $this->makeDeployer(new FakeComposeRunner(), new FakeNginxGenerator());

        $this->expectException(RuntimeException::class);
        $deployer->rollback($this->makeApp(), $this->fx->v2, fn () => null);
    }

    public function testRollbackThrowsWhenRestoreAlsoFails(): void
    {
        $compose = new FakeComposeRunner();
        $compose->upFailRemaining = 2; // rollback & restore sama-sama gagal
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('docker compose up gagal (simulasi)');
        $deployer->rollback($this->makeApp(), $this->fx->v1, fn () => null);
    }

    public function testRecordHistoryKeepsLastTwentyEntries(): void
    {
        $deployer = $this->makeDeployer(new FakeComposeRunner(), new FakeNginxGenerator());
        $app = $this->makeApp();

        $method = new \ReflectionMethod(LocalDeployer::class, 'recordHistory');
        for ($i = 0; $i < 25; $i++) {
            $sha = str_pad((string) $i, 40, '0', STR_PAD_LEFT);
            $app = $method->invoke($deployer, $app, $sha, 'rebuild', 'success');
        }

        $this->assertCount(20, $app['deploy_history']);
        $this->assertSame(str_pad('5', 40, '0', STR_PAD_LEFT), $app['deploy_history'][0]['sha']);
        $this->assertSame(str_pad('24', 40, '0', STR_PAD_LEFT), $app['deploy_history'][19]['sha']);
    }

    // ==================================================================
    // Mode compose (tanpa repo Git) — lihat ComposeSource
    // ==================================================================

    /**
     * App mode compose: direktori app berisi compose + file pendukung, TANPA
     * `.git` — membuktikan rebuild/apply tidak pernah menyentuh git.
     */
    private function makeComposeApp(): array
    {
        $this->rrmdir($this->appDir . '/.git');
        file_put_contents(
            $this->appDir . '/docker-compose.yml',
            "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"8080:80\"\n"
        );

        return [
            'id' => 'app-compose',
            'name' => 'myapp',
            'source' => ComposeSource::SOURCE_COMPOSE,
            'repo_url' => null,
            'branch' => null,
            'local_path' => 'apps/myapp',
            'primary_service' => 'web',
            'status' => 'running',
            'auth_method' => 'none',
            'compose_files' => ['docker-compose.yml'],
            'containers' => [],
        ];
    }

    public function testComposeRebuildRunsUpWithoutBuildAndWithoutGit(): void
    {
        $compose = new FakeComposeRunner();
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $app = $deployer->rebuild($this->makeComposeApp(), fn () => null);

        $this->assertSame('running', $app['status']);
        // up -d tanpa build (image prebuilt), tanpa git pull
        $this->assertSame([['up', 'myapp', false]], $compose->calls);
        $this->assertCount(1, $app['deploy_history']);
        $this->assertSame('rebuild', $app['deploy_history'][0]['action']);
        $this->assertSame('', $app['deploy_history'][0]['sha']);
    }

    public function testComposeApplyRunsUpWithoutBuild(): void
    {
        $compose = new FakeComposeRunner();
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $app = $deployer->apply($this->makeComposeApp(), fn () => null);

        $this->assertSame('running', $app['status']);
        $this->assertSame([['up', 'myapp', false]], $compose->calls);
        $this->assertSame('apply', $app['deploy_history'][0]['action']);
    }

    /**
     * Source bind mount yang belum ada dibuat otomatis (mis. `${PWD}/.hermes`
     * atau `./data`) supaya `docker compose up` tidak gagal dengan
     * "failed to populate volume: ... no such file or directory".
     */
    public function testComposeApplyCreatesMissingBindMountDirectories(): void
    {
        $app = $this->makeComposeApp();
        // tulis SETELAH makeComposeApp() (fixture menulis ulang docker-compose.yml)
        file_put_contents($this->appDir . '/docker-compose.yml', <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - ./data:/var/lib/data

volumes:
  hermes-data:
    driver: local
    driver_opts:
      type: none
      device: ${PWD}/.hermes
      o: bind
YAML);

        $compose = new FakeComposeRunner();
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());
        $deployer->apply($app, fn () => null);

        $this->assertDirectoryExists($this->appDir . '/data');
        $this->assertDirectoryExists($this->appDir . '/.hermes');
    }

    /**
     * Bind source berupa file yang belum ada tidak dibuat, tetapi pesan error
     * `docker compose up` diberi petunjuk perbaikan yang jelas.
     */
    public function testComposeUpFailureAddsBindMountHint(): void
    {
        $app = $this->makeComposeApp();
        // tulis SETELAH makeComposeApp() (fixture menulis ulang docker-compose.yml)
        file_put_contents($this->appDir . '/docker-compose.yml', <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - ./.env:/app/.env
YAML);

        $compose = new FakeComposeRunner();
        $compose->upFailRemaining = 1;
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Bind mount di compose belum siap/');
        $this->expectExceptionMessageMatches('/\.env/');
        $deployer->apply($app, fn () => null);
    }

    public function testComposeDeployRecordsHistoryWithoutSha(): void
    {
        $compose = new FakeComposeRunner();
        $deployer = $this->makeDeployer($compose, new FakeNginxGenerator());

        $app = $deployer->deploy($this->makeComposeApp(), fn () => null);

        // deploy build image lokal (tanpa --build? deploy selalu build=true)
        $this->assertSame([['up', 'myapp', true]], $compose->calls);
        $this->assertSame('deploy', $app['deploy_history'][0]['action']);
        $this->assertSame('', $app['deploy_history'][0]['sha']);
    }

    public function testComposeRebuildFailsFastWhenComposeFileMissing(): void
    {
        $deployer = $this->makeDeployer(new FakeComposeRunner(), new FakeNginxGenerator());
        $app = $this->makeComposeApp();
        unlink($this->appDir . '/docker-compose.yml');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/docker-compose\.yml/');
        $deployer->rebuild($app, fn () => null);
    }

    public function testApplyRejectedForGitApp(): void
    {
        $deployer = $this->makeDeployer(new FakeComposeRunner(), new FakeNginxGenerator());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/mode Compose/');
        $deployer->apply($this->makeApp(), fn () => null);
    }

    public function testRollbackRejectedForComposeApp(): void
    {
        $deployer = $this->makeDeployer(new FakeComposeRunner(), new FakeNginxGenerator());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/repo Git/');
        $deployer->rollback($this->makeComposeApp(), $this->fx->v1, fn () => null);
    }

    /**
     * Port hasil collect harus bebas duplikat (IPv4 + IPv6 dari Engine dianggap
     * satu port) dan `host_port`/`internal_port` mengikuti entri pertama.
     */
    public function testGetContainersDedupesDualStackPorts(): void
    {
        $deployer = new LocalDeployer(
            new FakeComposeRunner(),
            new DualStackDockerClient(),
            new FakeNginxGenerator(),
            $this->fx->workDir . '/apps',
            new EnvManager(sys_get_temp_dir() . '/rames-test-env'),
            new NetworkManager()
        );

        $containers = $deployer->getContainers('hermes');

        $this->assertCount(1, $containers);
        $this->assertSame('hermes', $containers[0]['service_name']);
        $this->assertSame('hermesan', $containers[0]['container_name']);
        $this->assertSame([
            ['host' => 8642, 'container' => 8642],
            ['host' => 9119, 'container' => 9119],
        ], $containers[0]['ports']);
        $this->assertSame(8642, $containers[0]['host_port']);
        $this->assertSame(8642, $containers[0]['internal_port']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
