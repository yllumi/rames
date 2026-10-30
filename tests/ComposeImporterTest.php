<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ComposeImporter;
use app\library\Deploy\ComposeSource;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Engine tiruan: daftar container global + per project, tanpa socket nyata.
 */
final class ComposeImporterFakeDocker extends DockerClient
{
    /** @var array<int,array<string,mixed>> */
    public array $containers = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $byProject = [];

    /** @var array<int,string> */
    public array $calls = [];

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket/curl. */
    public function __construct()
    {
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        $this->calls[] = 'listContainers';

        return $this->containers;
    }

    public function listContainersForProject(string $project): array
    {
        $this->calls[] = 'listContainersForProject:' . $project;

        return $this->byProject[$project] ?? [];
    }
}

/**
 * Impor project compose yang sudah berjalan menjadi App Rames.
 *
 * Tidak menyentuh Docker, database runtime, maupun direktori `apps/` nyata.
 */
class ComposeImporterTest extends TestCase
{
    private string $tmp;

    private ComposeImporterFakeDocker $docker;

    private AppStore $store;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-importer-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);

        $this->docker = new ComposeImporterFakeDocker();
        $this->store = new AppStore($this->tmp . '/rames.sqlite');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
    }

    public function testImportCreatesRecordAccordingToContract(): void
    {
        $this->seedProject('blog', [
            $this->container('blog', 'web', 'running', [['host' => 8080, 'container' => 80]], 'nginx:alpine'),
            $this->container('blog', 'db', 'running', [], 'mariadb:11'),
        ], '/srv/blog', ['/srv/blog/docker-compose.yml', '/srv/blog/docker-compose.override.yml']);

        $app = (new ComposeImporter($this->docker, $this->store))->import('blog', 'web:80', 'u-admin');

        $this->assertSame('blog', $app['name']);
        $this->assertSame(ComposeSource::ORIGIN_IMPORTED, $app['origin']);
        $this->assertSame('u-admin', $app['imported_by']);
        $this->assertSame('u-admin', $app['owner_id']);
        $this->assertSame([], $app['members']);
        $this->assertNotSame('', (string) $app['imported_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $app['imported_at']);

        $this->assertSame('compose', $app['source']);
        $this->assertSame('/srv/blog', $app['compose_dir']);
        $this->assertSame('/srv/blog', $app['local_path'], 'local_path = direktori proyek asli');
        $this->assertSame(
            ['/srv/blog/docker-compose.yml', '/srv/blog/docker-compose.override.yml'],
            $app['compose_paths']
        );
        $this->assertSame(['docker-compose.yml', 'docker-compose.override.yml'], $app['compose_files']);
        $this->assertNull($app['repo_url']);
        $this->assertNull($app['branch']);
        $this->assertSame('none', $app['auth_method']);
        $this->assertNull($app['ssh_key']);
        $this->assertNull($app['container_prefix']);
        $this->assertSame([], $app['env']);
        $this->assertNull($app['limits']);
        $this->assertNull($app['template']);
        $this->assertFalse($app['needs_ssl']);
        $this->assertNull($app['ssl_status']);
        $this->assertSame('web', $app['primary_service']);
        $this->assertSame(80, $app['primary_port']);
        $this->assertSame('running', $app['status']);
        $this->assertNull($app['stage']);
        $this->assertSame(
            'Diimpor dari project compose yang sudah berjalan (dikelola di luar Rames).',
            $app['message']
        );

        $this->assertCount(2, $app['containers']);
        $this->assertSame('web', $app['containers'][0]['service_name']);
        $this->assertSame('blog-web-1', $app['containers'][0]['container_name']);
        $this->assertSame('nginx:alpine', $app['containers'][0]['image']);
        $this->assertSame(80, $app['containers'][0]['internal_port']);
        $this->assertSame(8080, $app['containers'][0]['host_port']);
        $this->assertSame([['host' => 8080, 'container' => 80]], $app['containers'][0]['ports']);
        $this->assertSame('running', $app['containers'][0]['status']);

        $this->assertCount(1, $app['deploy_history']);
        $history = $app['deploy_history'][0];
        $this->assertSame('', $history['sha']);
        $this->assertSame('', $history['short']);
        $this->assertSame('import', $history['action']);
        $this->assertSame('success', $history['status']);
        $this->assertSame($app['message'], $history['message']);
        $this->assertNotSame('', (string) $history['created_at']);

        // Tidak ada I/O disk: hanya berkas SQLite store yang dibuat.
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps');
        $this->assertSame([], glob($this->tmp . '/apps/*') ?: []);

        // Hanya pembacaan Engine — tidak ada orkestrasi/mutasi.
        $this->assertSame(['listContainers', 'listContainersForProject:blog'], $this->docker->calls);
    }

    public function testPrimaryFallsBackToFirstChoiceWhenInvalid(): void
    {
        $this->seedProject('shop', [
            $this->container('shop', 'db', 'running', [], 'mariadb:11'),
            $this->container('shop', 'web', 'running', [['host' => 8080, 'container' => 80]], 'nginx:alpine'),
        ]);

        $app = (new ComposeImporter($this->docker, $this->store))->import('shop', 'tidak:ada', 'u-admin');

        $this->assertSame('web', $app['primary_service']);
        $this->assertSame(80, $app['primary_port']);
    }

    public function testProjectWithoutHostPortGetsEmptyPrimary(): void
    {
        $this->seedProject('dbonly', [
            $this->container('dbonly', 'db', 'running', [], 'mariadb:11'),
        ]);

        $app = (new ComposeImporter($this->docker, $this->store))->import('dbonly', '', 'u-admin');

        $this->assertSame('', $app['primary_service']);
        $this->assertSame(0, $app['primary_port']);
        $this->assertSame('running', $app['status']);
    }

    public function testStoppedProjectIsRecordedAsStopped(): void
    {
        $this->seedProject('offline', [
            $this->container('offline', 'db', 'exited', [['host' => 3307, 'container' => 3306]], 'mariadb:11'),
        ]);

        $app = (new ComposeImporter($this->docker, $this->store))->import('offline', '', 'u-admin');

        $this->assertSame('stopped', $app['status']);
        $this->assertSame('db', $app['primary_service']);
        $this->assertSame(3306, $app['primary_port']);
    }

    public function testUnknownProjectIsRejected(): void
    {
        $this->seedProject('known', [
            $this->container('known', 'web', 'running', [['host' => 8080, 'container' => 80]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Project compose "tidak-ada" tidak ditemukan di Docker Engine.');

        (new ComposeImporter($this->docker, $this->store))->import('tidak-ada', '', 'u-admin');
    }

    public function testDashboardProjectIsRejectedWithClearMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/project dashboard Rames/');

        (new ComposeImporter($this->docker, $this->store))->import('rames', '', 'u-admin');
    }

    public function testNameConflictWithLocalAppIsRejected(): void
    {
        $this->store->create(['name' => 'blog', 'server_id' => 'local', 'status' => 'running']);
        $this->seedProject('blog', [
            $this->container('blog', 'web', 'running', [['host' => 8080, 'container' => 80]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama app "blog" sudah dipakai.');

        (new ComposeImporter($this->docker, $this->store))->import('blog', '', 'u-admin');
    }

    public function testInspectReturnsDiscoveryRow(): void
    {
        $this->seedProject('blog', [
            $this->container('blog', 'web', 'running', [['host' => 8080, 'container' => 80]]),
        ]);

        $row = (new ComposeImporter($this->docker, $this->store))->inspect('blog');

        $this->assertSame('blog', $row['project']);
        $this->assertTrue($row['importable']);
    }

    public function testInspectThrowsForUnknownProject(): void
    {
        $this->expectException(RuntimeException::class);

        (new ComposeImporter($this->docker, $this->store))->inspect('tidak-ada');
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    /**
     * @param array<int,array<string,mixed>> $containers
     * @param array<int,string>              $configFiles
     */
    private function seedProject(
        string $project,
        array $containers,
        string $workingDir = '/srv/' . 'x',
        array $configFiles = []
    ): void {
        // working_dir/config unik per project bila tidak diberikan.
        $workingDir = $workingDir === '/srv/x' ? '/srv/' . $project : $workingDir;
        $configFiles = $configFiles !== [] ? $configFiles : ['/srv/' . $project . '/docker-compose.yml'];

        foreach ($containers as $container) {
            $this->docker->byProject[$project][] = $container;
        }

        // Tambahkan label working_dir/config pada setiap container project itu.
        foreach ($this->docker->byProject[$project] as &$container) {
            $container['Labels']['com.docker.compose.project.working_dir'] = $workingDir;
            $container['Labels']['com.docker.compose.project.config_files'] = implode(',', $configFiles);
        }
        unset($container);

        // Daftar global: seluruh container semua project.
        $this->docker->containers = array_merge(
            $this->docker->containers,
            $this->docker->byProject[$project]
        );
    }

    /**
     * @param array<int,array{host:int,container:int}> $ports
     * @return array<string,mixed>
     */
    private function container(
        string $project,
        string $service,
        string $state,
        array $ports,
        string $image = 'busybox'
    ): array {
        return [
            'Id' => $project . '-' . $service . '-' . bin2hex(random_bytes(2)),
            'Names' => ['/' . $project . '-' . $service . '-1'],
            'Image' => $image,
            'State' => $state,
            'Labels' => [
                'com.docker.compose.project' => $project,
                'com.docker.compose.service' => $service,
            ],
            'Ports' => array_map(
                static fn (array $p): array => [
                    'IP' => '0.0.0.0',
                    'PrivatePort' => $p['container'],
                    'PublicPort' => $p['host'],
                    'Type' => 'tcp',
                ],
                $ports
            ),
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
