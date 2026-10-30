<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\ComposeDiscovery;
use app\library\Docker\DockerClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Engine tiruan: daftar container dikendalikan test, tanpa socket nyata.
 */
final class ComposeDiscoveryFakeDocker extends DockerClient
{
    /** @var array<int,array<string,mixed>> */
    public array $containers = [];

    /** @var array<int,array<string,mixed>> */
    public array $filters = [];

    public bool $explode = false;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada socket/curl. */
    public function __construct()
    {
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        $this->filters[] = $filters;

        if ($this->explode) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: socket tidak ada');
        }

        return $this->containers;
    }
}

/**
 * Penemuan project docker compose yang sudah berjalan (impor app, admin-only).
 *
 * Tidak menyentuh Docker, database, atau filesystem nyata.
 */
class ComposeDiscoveryTest extends TestCase
{
    public function testGroupsProjectsAndStates(): void
    {
        $docker = new ComposeDiscoveryFakeDocker();
        $docker->containers = [
            // alpha: dua container, keduanya running.
            $this->container('alpha', 'web', 'running', [
                ['host' => 8080, 'container' => 80],
                ['host' => 8080, 'container' => 80], // publish dual-stack (duplikat)
            ], 'nginx:alpine', '/srv/alpha', ['/srv/alpha/docker-compose.yml', '/srv/alpha/docker-compose.override.yml']),
            $this->container('alpha', 'db', 'running', [], 'mariadb:11', '/srv/alpha', ['/srv/alpha/docker-compose.yml']),
            $this->container('alpha', 'db', 'running', [], 'mariadb:11', '/srv/alpha'), // replica: service & image dedupe

            // beta: satu running, satu exited ⇒ partial.
            $this->container('beta', 'app', 'running', [['host' => 9000, 'container' => 3000]], 'node:22', '/srv/beta'),
            $this->container('beta', 'worker', 'exited', [], 'node:22', '/srv/beta'),

            // gamma: seluruhnya berhenti ⇒ stopped.
            $this->container('gamma', 'db', 'exited', [['host' => 3307, 'container' => 3306]], 'mariadb:11', '/srv/gamma'),

            // Project dashboard: selalu dikecualikan.
            $this->container('rames', 'webman', 'running', [], 'rames:latest', '/srv/rames'),
            $this->container('rames-agent', 'agent', 'running', [], 'rames:latest', '/srv/rames'),

            // Container tanpa label project (docker run) tidak terdeteksi.
            ['Id' => 'raw', 'Names' => ['/raw-1'], 'Image' => 'busybox', 'State' => 'running', 'Labels' => [], 'Ports' => []],
        ];

        $apps = [
            // Nama bentrok hanya berlaku untuk app di server LOKAL.
            ['id' => 'a1', 'name' => 'beta', 'server_id' => 'local'],
            ['id' => 'a2', 'name' => 'alpha', 'server_id' => 'vps-jakarta'],
        ];

        $rows = (new ComposeDiscovery($docker))->projects($apps);

        $this->assertSame(['alpha', 'beta', 'gamma'], array_column($rows, 'project'));
        $this->assertSame([['label' => ['com.docker.compose.project']]], $docker->filters, 'satu panggilan Engine');

        $alpha = $this->row($rows, 'alpha');
        $this->assertSame('running', $alpha['state']);
        $this->assertSame(3, $alpha['containers']);
        $this->assertSame(3, $alpha['running']);
        $this->assertSame(['web', 'db'], $alpha['services'], 'service unik, urutan kemunculan');
        $this->assertSame(['nginx:alpine', 'mariadb:11'], $alpha['images'], 'image unik');
        $this->assertSame([['host' => 8080, 'container' => 80, 'service' => 'web']], $alpha['ports'], 'port didedupe + urut host');
        $this->assertSame('/srv/alpha', $alpha['working_dir']);
        $this->assertSame(
            ['/srv/alpha/docker-compose.yml', '/srv/alpha/docker-compose.override.yml'],
            $alpha['config_files']
        );
        $this->assertSame(['docker-compose.yml', 'docker-compose.override.yml'], $alpha['compose_files']);
        $this->assertTrue($alpha['name_valid']);
        $this->assertFalse($alpha['conflict']);
        $this->assertTrue($alpha['importable']);

        $beta = $this->row($rows, 'beta');
        $this->assertSame('partial', $beta['state']);
        $this->assertSame(2, $beta['containers']);
        $this->assertSame(1, $beta['running']);
        $this->assertTrue($beta['conflict'], 'nama dipakai app lokal');
        $this->assertFalse($beta['importable'], 'bentrok nama tidak bisa diimpor');

        $gamma = $this->row($rows, 'gamma');
        $this->assertSame('stopped', $gamma['state']);
        $this->assertSame(0, $gamma['running']);
        $this->assertTrue($gamma['importable']);
    }

    public function testPrimaryChoicesComeFromPublishedContainerPorts(): void
    {
        $docker = new ComposeDiscoveryFakeDocker();
        $docker->containers = [
            $this->container('shop', 'web', 'running', [['host' => 8080, 'container' => 80]], 'nginx:alpine'),
            $this->container('shop', 'api', 'running', [
                ['host' => 9090, 'container' => 9000],
                ['host' => 9191, 'container' => 9001],
            ], 'node:22'),
            $this->container('shop', 'db', 'running', [], 'mariadb:11'),
        ];

        $discovery = new ComposeDiscovery($docker);
        $row = $discovery->projectNamed('shop', []);

        $this->assertNotNull($row);
        $choices = $discovery->primaryChoices($row);

        $this->assertSame([
            ['key' => 'web:80', 'service' => 'web', 'port' => 80, 'host' => 8080],
            ['key' => 'api:9000', 'service' => 'api', 'port' => 9000, 'host' => 9090],
            ['key' => 'api:9001', 'service' => 'api', 'port' => 9001, 'host' => 9191],
        ], $choices, 'service tanpa host port tidak menjadi pilihan');
    }

    public function testInvalidProjectNameIsNotImportable(): void
    {
        $docker = new ComposeDiscoveryFakeDocker();
        $docker->containers = [
            $this->container('Rames_Bad/Name', 'web', 'running', [['host' => 8080, 'container' => 80]], 'nginx'),
        ];

        $rows = (new ComposeDiscovery($docker))->projects([]);

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['name_valid']);
        $this->assertFalse($rows[0]['importable']);

        $this->assertFalse(ComposeDiscovery::validName(''));
        $this->assertFalse(ComposeDiscovery::validName('.'));
        $this->assertFalse(ComposeDiscovery::validName('..'));
        $this->assertFalse(ComposeDiscovery::validName('a/b'));
        $this->assertFalse(ComposeDiscovery::validName('UPPER'));
        $this->assertTrue(ComposeDiscovery::validName('app_1.2-3'));
    }

    public function testEngineFailureIsNotSwallowed(): void
    {
        $docker = new ComposeDiscoveryFakeDocker();
        $docker->explode = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Docker Engine/');

        (new ComposeDiscovery($docker))->projects([]);
    }

    /**
     * @param array<int,array{host:int,container:int}> $ports
     * @param array<int,string>                        $configFiles
     * @return array<string,mixed>
     */
    private function container(
        string $project,
        string $service,
        string $state,
        array $ports,
        string $image = 'busybox',
        string $workingDir = '',
        array $configFiles = []
    ): array {
        $labels = [
            'com.docker.compose.project' => $project,
            'com.docker.compose.service' => $service,
        ];
        if ($workingDir !== '') {
            $labels['com.docker.compose.project.working_dir'] = $workingDir;
        }
        if ($configFiles !== []) {
            $labels['com.docker.compose.project.config_files'] = implode(',', $configFiles);
        }

        return [
            'Id' => $project . '-' . $service . '-' . bin2hex(random_bytes(2)),
            'Names' => ['/' . $project . '-' . $service . '-1'],
            'Image' => $image,
            'State' => $state,
            'Labels' => $labels,
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

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function row(array $rows, string $project): array
    {
        foreach ($rows as $row) {
            if ($row['project'] === $project) {
                return $row;
            }
        }

        $this->fail('baris project ' . $project . ' tidak ada');
    }
}
