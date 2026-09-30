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
 * Fake NginxConfigGenerator — merekam pemanggilan supaya test bisa memastikan
 * direktori Nginx TIDAK disentuh untuk app tanpa port.
 */
class NoPortFakeNginxGenerator extends NginxConfigGenerator
{
    /** @var array<int,string> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('/tmp/rames-nginx-tidak-ada', '/tmp/rames-nginx-tidak-ada');
    }

    public function ensureWritable(): void
    {
        $this->calls[] = 'ensureWritable';
    }

    public function render(int $hostPort, array $servers): string
    {
        return 'mock';
    }

    public function write(string $name, string $content): void
    {
        $this->calls[] = 'write';
    }

    public function remove(string $name): void
    {
        $this->calls[] = 'remove';
    }
}

/**
 * Fake DockerClient — daftar container app dikendalikan test.
 */
class NoPortFakeDockerClient extends DockerClient
{
    /** @var array<int,array> */
    public array $containers = [];

    public function __construct()
    {
        // sengaja tidak memanggil parent (menghindari ext-curl / socket daemon)
    }

    public function listContainersForProject(string $project): array
    {
        return $this->containers;
    }
}

/**
 * Fake DockerComposeRunner — merekam `up` tanpa menjalankan docker.
 */
class NoPortFakeComposeRunner extends DockerComposeRunner
{
    /** @var array<int,array{project:string,dir:string,files:array,build:bool}> */
    public array $upCalls = [];

    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 10);
    }

    public function up(string $project, string $dir, array $files, bool $build = true, ?string $envFile = null): void
    {
        $this->upCalls[] = ['project' => $project, 'dir' => $dir, 'files' => $files, 'build' => $build];
    }
}

/**
 * LocalDeployer dengan pemain I/O palsu. `renderNginxConfig()` dioverride agar
 * test tidak memanggil helper webman (`app_subdomain()`, `config()`).
 */
class NoPortTestDeployer extends LocalDeployer
{
    public function __construct(
        NoPortFakeComposeRunner $compose,
        NoPortFakeDockerClient $docker,
        NoPortFakeNginxGenerator $nginx,
        string $appsPath
    ) {
        parent::__construct(
            $compose,
            $docker,
            $nginx,
            $appsPath,
            new EnvManager(sys_get_temp_dir() . '/rames-test-env'),
            new NetworkManager()
        );
    }

    public function renderNginxConfig(array $app): string
    {
        return 'mock';
    }
}

/**
 * Deploy app TANPA host port (SPECS §7.2): tidak dibuatkan vhost/subdomain dan
 * direktori Nginx tidak disentuh sama sekali — deploy tetap sukses. Berlaku juga
 * saat app sebelumnya punya vhost (config lama wajib dibuang).
 */
class LocalDeployerNoPortTest extends TestCase
{
    private string $appsPath;
    private NoPortFakeComposeRunner $compose;
    private NoPortFakeDockerClient $docker;
    private NoPortFakeNginxGenerator $nginx;
    private NoPortTestDeployer $deployer;
    /** @var array<int,array{stage:string,message:string}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->appsPath = sys_get_temp_dir() . '/rames-noport-' . bin2hex(random_bytes(4));
        mkdir($this->appsPath . '/dbsaya', 0777, true);

        $this->compose = new NoPortFakeComposeRunner();
        $this->docker = new NoPortFakeDockerClient();
        $this->nginx = new NoPortFakeNginxGenerator();
        $this->deployer = new NoPortTestDeployer($this->compose, $this->docker, $this->nginx, $this->appsPath);
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->appsPath);
    }

    public function testDeployTanpaPortMelewatiVhostDanDirektoriNginx(): void
    {
        file_put_contents(
            $this->appsPath . '/dbsaya/docker-compose.yml',
            "services:\n  mariadb:\n    image: mariadb:11.4\n"
        );
        // Container jalan tapi tidak mem-publish port (mis. service database).
        $this->docker->containers = [$this->container('mariadb', [])];

        $app = $this->deployer->deploy($this->app(), $this->logger());

        $this->assertSame('running', $app['status']);
        $this->assertSame('dbsaya', $this->compose->upCalls[0]['project']);
        $this->assertSame([], $app['containers'][0]['ports']);

        // Direktori Nginx tidak dicek (tidak butuh), vhost lama dibuang, tidak menulis config.
        $this->assertNotContains('ensureWritable', $this->nginx->calls);
        $this->assertNotContains('write', $this->nginx->calls);
        $this->assertContains('remove', $this->nginx->calls);
        $this->assertStringContainsString('vhost/subdomain dilewati', $this->stageMessage('nginx'));
    }

    public function testDeployDenganPortTetapMenulisVhost(): void
    {
        file_put_contents(
            $this->appsPath . '/dbsaya/docker-compose.yml',
            "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"8123:80\"\n"
        );
        $this->docker->containers = [$this->container('web', [['host' => 8123, 'container' => 80]])];

        $app = $this->deployer->deploy($this->app(), $this->logger());

        $this->assertSame('running', $app['status']);
        $this->assertContains('ensureWritable', $this->nginx->calls);
        $this->assertContains('write', $this->nginx->calls);
        $this->assertStringContainsString('Menulis config Nginx', $this->stageMessage('nginx'));
    }

    public function testDeployPortHilangMembuangVhostLama(): void
    {
        // App pernah punya vhost (subdomain aktif), lalu `ports:` dihapus dari
        // compose → deploy berikutnya tidak boleh gagal & config lama dibuang.
        file_put_contents(
            $this->appsPath . '/dbsaya/docker-compose.yml',
            "services:\n  db:\n    image: mariadb:11.4\n"
        );
        $app = $this->app([
            'primary_service' => 'db',
            'primary_port' => 3306,
            // data container lama masih memuat port (sebelum re-create)
            'containers' => [$this->container('db', [['host' => 13306, 'container' => 3306]])],
        ]);
        // Setelah `up`, container tidak lagi mem-publish port.
        $this->docker->containers = [$this->container('db', [])];

        $result = $this->deployer->deploy($app, $this->logger());

        $this->assertSame('running', $result['status']);
        $this->assertContains('remove', $this->nginx->calls);
        $this->assertNotContains('write', $this->nginx->calls);
    }

    /**
     * @param array<int,array{host:int,container:int}> $ports
     * @return array<string,mixed>
     */
    private function container(string $service, array $ports): array
    {
        $enginePorts = [];
        foreach ($ports as $port) {
            $enginePorts[] = ['PublicPort' => $port['host'], 'PrivatePort' => $port['container']];
        }

        return [
            'Id' => 'cid-' . $service,
            'Names' => ['/dbsaya-' . $service . '-1'],
            'Image' => 'image-' . $service,
            'Labels' => ['com.docker.compose.service' => $service],
            'State' => 'running',
            'Ports' => $enginePorts,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function app(array $overrides = []): array
    {
        return array_merge([
            'id' => 'app-1',
            'name' => 'dbsaya',
            'source' => 'compose',
            'compose_files' => ['docker-compose.yml'],
            'primary_service' => '',
            'primary_port' => 0,
            'env' => [],
            'containers' => [],
        ], $overrides);
    }

    private function logger(): callable
    {
        return function (string $stage, string $message): void {
            $this->logs[] = ['stage' => $stage, 'message' => $message];
        };
    }

    private function stageMessage(string $stage): string
    {
        foreach ($this->logs as $log) {
            if ($log['stage'] === $stage) {
                return $log['message'];
            }
        }

        return '';
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
