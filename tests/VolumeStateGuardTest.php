<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\VolumeStateGuard;
use app\library\Db\DbContainerDetector;
use app\library\Deploy\EnvManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fake DockerClient — mengembalikan daftar container yang diberikan, tanpa
 * ext-curl / Engine nyata (pola `LocalDeployerRollbackTest`).
 */
class VolumeGuardFakeDockerClient extends DockerClient
{
    /** @param array<int,array> $containers */
    public function __construct(private array $containers = [])
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }

    public function listContainers(array $filters = []): array
    {
        return $this->containers;
    }

    public function inspectContainer(string $id): array
    {
        return ['Config' => ['Image' => str_contains($id, 'db') ? 'mysql:8' : 'nginx:alpine']];
    }
}

/**
 * Fake detektor DB — meniru heuristik image `mysql` tanpa Engine.
 */
class VolumeGuardFakeDbDetector extends DbContainerDetector
{
    public function __construct()
    {
        // sengaja tidak memanggil parent (tanpa DockerClient).
    }

    public function isDbContainer(array $inspect): bool
    {
        return str_contains(strtolower((string) ($inspect['Config']['Image'] ?? '')), 'mysql');
    }
}

/**
 * Fake compose runner — merekam pemanggilan stop/start, tidak menjalankan docker.
 */
class VolumeGuardFakeComposeRunner extends DockerComposeRunner
{
    /** @var array<int,array{action:string,project:string,files:array<int,string>,env:?string}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 5);
    }

    public function stop(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->calls[] = ['action' => 'stop', 'project' => $project, 'files' => $files, 'env' => $envFile];
    }

    public function start(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->calls[] = ['action' => 'start', 'project' => $project, 'files' => $files, 'env' => $envFile];
    }
}

/**
 * Test `VolumeStateGuard` — aturan D1 "snapshot hanya sah saat container mati"
 * (PLAN_VOLUME_BACKUP.md §2, §5.3, §7).
 *
 * Tanpa Engine/Docker nyata: DockerClient & DockerComposeRunner di-fake, dan
 * apps.json ditulis ke direktori temp (bukan data runtime nyata — larangan #15).
 */
class VolumeStateGuardTest extends TestCase
{
    private string $tmp;
    private VolumeGuardFakeComposeRunner $compose;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/volguard_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/apps', 0777, true);
        file_put_contents(
            $this->tmp . '/apps.json',
            json_encode([[
                'id' => 'app1',
                'name' => 'tonidata',
                'compose_files' => ['docker-compose.yml'],
            ]], JSON_PRETTY_PRINT)
        );
        $this->compose = new VolumeGuardFakeComposeRunner();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    /**
     * @param array<int,array> $containers
     */
    private function guard(array $containers): VolumeStateGuard
    {
        return new VolumeStateGuard(
            new VolumeGuardFakeDockerClient($containers),
            $this->compose,
            new AppStore($this->tmp . '/apps.json'),
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env'),
            new VolumeGuardFakeDbDetector(),
        );
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    // ==================================================================
    // assertStopped()
    // ==================================================================

    public function testAssertStoppedRejectsRunningContainerAndNamesVolumeAndContainer(): void
    {
        $guard = $this->guard([[
            'Id' => 'c1',
            'Names' => ['/tonidata-db-1'],
            'State' => 'running',
            'Labels' => ['com.docker.compose.project' => 'tonidata'],
        ]]);

        try {
            $guard->assertStopped('tonidata_data');
            $this->fail('snapshot seharusnya ditolak saat container berjalan');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tonidata_data', $e->getMessage());
            $this->assertStringContainsString('tonidata-db-1', $e->getMessage());
            $this->assertStringContainsString('tidak ada jalur paksa', $e->getMessage());
        }
    }

    public function testAssertStoppedRejectsWhenOnlyOneOfManyIsRunning(): void
    {
        $guard = $this->guard([
            ['Id' => 'c1', 'Names' => ['/tonidata-db-1'], 'State' => 'exited', 'Labels' => []],
            ['Id' => 'c2', 'Names' => ['/tonidata-web-1'], 'State' => 'running', 'Labels' => []],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tonidata-web-1');
        $guard->assertStopped('tonidata_data');
    }

    public function testAssertStoppedPassesWhenAllContainersStopped(): void
    {
        $guard = $this->guard([
            ['Id' => 'c1', 'Names' => ['/tonidata-db-1'], 'State' => 'exited', 'Labels' => []],
            ['Id' => 'c2', 'Names' => ['/tonidata-web-1'], 'State' => 'created', 'Labels' => []],
            ['Id' => 'c3', 'Names' => ['/tonidata-job-1'], 'State' => 'dead', 'Labels' => []],
        ]);

        // Bukti daftar container memang dibaca (bukan lolos karena daftar kosong)...
        $this->assertCount(3, $guard->containersForVolume('tonidata_data'));
        // ...lalu guard tidak melempar: ketiga container tidak ada yang `running`.
        $guard->assertStopped('tonidata_data');
        $this->assertTrue(true, 'assertStopped lolos saat semua container berhenti');
    }

    public function testAssertStoppedPassesForVolumeWithoutContainers(): void
    {
        $guard = $this->guard([]);
        $guard->assertStopped('tonidata_data');
        $this->assertSame([], $guard->containersForVolume('tonidata_data'));
    }

    public function testContainersForVolumePropagatesIsDbAndProject(): void
    {
        $guard = $this->guard([[
            'Id' => 'db-1',
            'Names' => ['/tonidata-db-1'],
            'State' => 'running',
            'Labels' => ['com.docker.compose.project' => 'tonidata'],
        ]]);

        $row = $guard->containersForVolume('tonidata_data')[0];
        $this->assertTrue($row['is_db']);
        $this->assertTrue($row['running']);
        $this->assertSame('tonidata', $row['project']);
        $this->assertSame('tonidata-db-1', $row['name']);
    }

    // ==================================================================
    // Validasi nama (sebelum nama masuk filter Engine / argv helper)
    // ==================================================================

    public function testAssertVolumeNameRejectsDangerousInput(): void
    {
        foreach (['', 'x;rm -rf /', 'bad name', 'vol/../etc', '-leading', 'a$(id)', 'a:b'] as $bad) {
            try {
                VolumeStateGuard::assertVolumeName($bad);
                $this->fail("nama volume \"{$bad}\" seharusnya ditolak");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($bad, $e->getMessage());
            }
        }

        VolumeStateGuard::assertVolumeName('tonidata_data');
        VolumeStateGuard::assertVolumeName('a1.b-c_d');
        $this->assertTrue(true, 'nama volume valid diterima');
    }

    public function testAssertVolumeNameIsEnforcedByContainersForVolume(): void
    {
        $this->expectException(RuntimeException::class);
        $this->guard([])->containersForVolume('x;rm -rf /');
    }

    public function testAssertProjectNameRejectsTraversalAndAcceptsSlug(): void
    {
        foreach (['', 'a/b', 'a\\b', '..', 'a..b'] as $bad) {
            try {
                VolumeStateGuard::assertProjectName($bad);
                $this->fail("nama project \"{$bad}\" seharusnya ditolak");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($bad, $e->getMessage());
            }
        }

        VolumeStateGuard::assertProjectName('tonidata');
        $this->assertTrue(true, 'slug project valid diterima');
    }

    // ==================================================================
    // stop/start project (mode stop→snapshot→start, tanpa hapus volume)
    // ==================================================================

    public function testStopAndStartProjectUseComposeFilesWithoutVolumeRemoval(): void
    {
        $guard = $this->guard([]);

        $guard->stopProject('tonidata');
        $guard->startProject('tonidata');

        $this->assertSame(['stop', 'start'], array_column($this->compose->calls, 'action'));
        foreach ($this->compose->calls as $call) {
            $this->assertSame('tonidata', $call['project']);
            $this->assertSame(['docker-compose.yml'], $call['files']);
            $this->assertNotContains('-v', $call['files'], 'stop/start tidak boleh menghapus volume');
        }
    }

    public function testStopProjectRejectsUnknownProject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak ditemukan');
        $this->guard([])->stopProject('ghost');
    }
}
