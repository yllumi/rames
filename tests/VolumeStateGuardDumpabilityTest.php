<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupStrategyResolver;
use app\library\Backup\VolumeStateGuard;
use app\library\Db\DbContainerDetector;
use app\library\Deploy\EnvManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerExec;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;
use RuntimeException;

/**
 * Fake DockerClient dengan peta inspect per-container id (bisa dipaksa melempar).
 */
class DumpableFakeDockerClient extends DockerClient
{
    /**
     * @param array<int,array>    $containers
     * @param array<string,array> $inspects
     */
    public function __construct(private array $containers = [], private array $inspects = [], private bool $throwOnInspect = false)
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }

    public function listContainers(array $filters = []): array
    {
        return $this->containers;
    }

    public function inspectContainer(string $id): array
    {
        if ($this->throwOnInspect) {
            throw new RuntimeException('inspect gagal (test)');
        }
        return $this->inspects[$id] ?? ['Config' => []];
    }
}

/**
 * DockerClient dummy untuk `DbContainerDetector` (metode deteksi baru tidak
 * memakai `$docker`).
 */
class DumpableDetectorDockerClient extends DockerClient
{
    public function __construct()
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }
}

/**
 * Regresi bug Ghost: container image non-DB yang kebetulan mewarisi env
 * `MYSQL_*` tidak boleh dipilih untuk strategi `dump` bila tak punya binary dump.
 * `is_db` = "container DB yang layak di-dump secara logis" → Ghost = false →
 * strategi `snapshot` (volume tetap ter-backup lewat stop→snapshot→start).
 */
class VolumeStateGuardDumpabilityTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/volguarddump_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/apps', 0777, true);
        SqliteFixture::apps($this->tmp . '/apps.sqlite', [[
            'id' => 'app1',
            'name' => 'myblog',
            'compose_files' => ['docker-compose.yml'],
        ]]);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    /**
     * @param array<int,array>    $containers
     * @param array<string,array> $inspects
     */
    private function guard(array $containers, array $inspects, DockerExec $exec, bool $throwOnInspect = false): VolumeStateGuard
    {
        return new VolumeStateGuard(
            new DumpableFakeDockerClient($containers, $inspects, $throwOnInspect),
            null,
            new AppStore($this->tmp . '/apps.sqlite'),
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env'),
            new DbContainerDetector(new DumpableDetectorDockerClient(), $exec),
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

    public function testGhostLikeEnvOnlyContainerWithoutDumpToolYieldsSnapshot(): void
    {
        $containers = [[
            'Id' => 'ghost1',
            'Names' => ['/myblog-ghost'],
            'State' => 'running',
            'Labels' => ['com.docker.compose.project' => 'myblog'],
        ]];
        $inspects = ['ghost1' => [
            'Config' => ['Image' => 'ghost:6-alpine', 'Env' => ['MYSQL_PASSWORD=secret', 'PATH=/usr/bin']],
        ]];
        $exec = new FakeRecordingDockerExec(1); // tidak ada mysqldump/mariadb-dump

        $rows = $this->guard($containers, $inspects, $exec)->containersForVolume('myblog_ghost-content');

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['is_db'], 'Ghost env-only tanpa tool dump bukan DB layak-dump');
        $this->assertSame(BackupStrategyResolver::STRATEGY_SNAPSHOT, BackupStrategyResolver::resolve($rows));
        $this->assertCount(1, $exec->calls, 'env-only wajib diverifikasi lewat exec');
    }

    public function testEnvOnlyContainerWithDumpToolYieldsDump(): void
    {
        $containers = [[
            'Id' => 'dbx',
            'Names' => ['/myblog-db'],
            'State' => 'running',
            'Labels' => ['com.docker.compose.project' => 'myblog'],
        ]];
        $inspects = ['dbx' => [
            'Config' => ['Image' => 'some/custom-app:latest', 'Env' => ['MYSQL_DATABASE=app']],
        ]];
        $exec = new FakeRecordingDockerExec(0); // tool ada

        $rows = $this->guard($containers, $inspects, $exec)->containersForVolume('myblog_db');

        $this->assertTrue($rows[0]['is_db']);
        $this->assertSame(BackupStrategyResolver::STRATEGY_DUMP, BackupStrategyResolver::resolve($rows));
    }

    public function testMysqlImageContainerYieldsDumpWithoutExec(): void
    {
        $containers = [[
            'Id' => 'db1',
            'Names' => ['/myblog-db'],
            'State' => 'running',
            'Labels' => ['com.docker.compose.project' => 'myblog'],
        ]];
        $inspects = ['db1' => [
            'Config' => ['Image' => 'mysql:8.0', 'Env' => ['MYSQL_ROOT_PASSWORD=x']],
        ]];
        $exec = new FakeRecordingDockerExec(1); // tidak dipakai untuk image DB

        $rows = $this->guard($containers, $inspects, $exec)->containersForVolume('myblog_db');

        $this->assertTrue($rows[0]['is_db']);
        $this->assertSame(BackupStrategyResolver::STRATEGY_DUMP, BackupStrategyResolver::resolve($rows));
        $this->assertSame([], $exec->calls, 'image DB tidak perlu exec');
    }

    public function testInspectFailureYieldsSnapshot(): void
    {
        $containers = [[
            'Id' => 'ghost1',
            'Names' => ['/myblog-ghost'],
            'State' => 'running',
            'Labels' => ['com.docker.compose.project' => 'myblog'],
        ]];
        $exec = new FakeRecordingDockerExec(0);

        $rows = $this->guard($containers, [], $exec, true)->containersForVolume('myblog_ghost-content');

        $this->assertFalse($rows[0]['is_db'], 'inspect gagal → jalur aman (snapshot)');
        $this->assertSame(BackupStrategyResolver::STRATEGY_SNAPSHOT, BackupStrategyResolver::resolve($rows));
        $this->assertSame([], $exec->calls);
    }
}
