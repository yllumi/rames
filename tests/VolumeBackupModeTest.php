<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupCatalog;
use app\library\Backup\BackupReport;
use app\library\Backup\BackupRunLock;
use app\library\Backup\BackupSelection;
use app\library\Backup\DumpRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeStateGuard;
use app\library\Backup\VolumeTargetMap;
use app\library\Db\DbContainerDetector;
use app\library\Deploy\EnvManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Fake DockerClient — satu volume ber-label compose, **tanpa container**
 * (strategi `snapshot`, tidak butuh stop/start). Semua state di memori.
 */
class VolumeBackupModeFakeDockerClient extends DockerClient
{
    /**
     * @param array<int,array> $volumes
     */
    public function __construct(private array $volumes = [])
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }

    public function listVolumes(array $filters = []): array
    {
        return $this->volumes;
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        return [];
    }

    public function inspectContainer(string $id): array
    {
        return ['Config' => ['Image' => 'nginx:alpine']];
    }
}

/**
 * Fake detektor DB — volume uji bukan DB (strategi = `snapshot`).
 */
class VolumeBackupModeFakeDbDetector extends DbContainerDetector
{
    public function __construct()
    {
        // sengaja tidak memanggil parent.
    }

    public function isDbContainer(array $inspect): bool
    {
        return false;
    }
}

/**
 * Fake compose runner — merekam stop/start (seharusnya TIDAK terpanggil karena
 * tidak ada container berjalan). Tidak menjalankan `docker compose` nyata.
 */
class VolumeBackupModeFakeComposeRunner extends DockerComposeRunner
{
    /** @var array<int,array> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 5);
    }

    public function stop(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->calls[] = ['stop', $project];
    }

    public function start(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->calls[] = ['start', $project];
    }
}

/**
 * Fake ProcessRunner yang **merekam setiap argv** helper container — dipakai
 * membuktikan argv `restic forget` per mode. Tanpa Docker/restic nyata.
 */
class VolumeBackupModeRecordingRunner extends ProcessRunner
{
    /** @var array<int,array<int,string>> */
    public array $calls = [];

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->calls[] = $command;

        if (in_array('snapshots', $command, true)) {
            return ['code' => 0, 'stdout' => '[]', 'stderr' => '', 'timedOut' => false];
        }
        if (in_array('backup', $command, true)) {
            return ['code' => 0, 'stdout' => 'snapshot deadbeef saved', 'stderr' => '', 'timedOut' => false];
        }

        return ['code' => 0, 'stdout' => '{}', 'stderr' => '', 'timedOut' => false];
    }

    /**
     * Argv pemanggilan `restic forget` (helper container) — `[]` bila tak ada.
     *
     * @return array<int,string>
     */
    public function forgetArgv(): array
    {
        foreach (array_reverse($this->calls) as $argv) {
            if (in_array('forget', $argv, true)) {
                return $argv;
            }
        }

        return [];
    }

    /**
     * Nilai setelah `--keep-*` tertentu (mis. `--keep-daily` ⇒ "7"); null bila
     * kunci tidak ada di argv.
     */
    public function keepValue(string $keep): ?string
    {
        $argv = $this->forgetArgv();
        $index = array_search('--keep-' . $keep, $argv, true);

        return $index === false ? null : (string) ($argv[$index + 1] ?? '');
    }
}

/**
 * Test retensi **per mode** (`snapshot` versioning harian+pekanan, `sync` =
 * hanya 1 snapshot) di `VolumeBackupService`.
 *
 * Bukti diambil dari argv helper container yang direkam `ProcessRunner` palsu:
 * mode `sync` ⇒ `--keep-last 1` tanpa `--keep-daily`/`--keep-weekly`;
 * mode `snapshot` ⇒ `--keep-daily`/`--keep-weekly` tanpa `--keep-monthly`.
 * Override opsi `modes` pada `run()` (jalur agent) menang atas store seleksi.
 *
 * Tanpa Docker/restic nyata; seluruh state di direktori temp (larangan #15).
 */
class VolumeBackupModeTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/volmode_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/apps', 0777, true);
        file_put_contents($this->tmp . '/password', "passphrase\n");
        SqliteFixture::apps($this->tmp . '/apps.sqlite', [
            ['id' => 'app1', 'name' => 'tonidata', 'compose_files' => ['docker-compose.yml']],
        ]);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
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

    private function makeService(ProcessRunner $process, BackupSelection $selection): VolumeBackupService
    {
        $docker = new VolumeBackupModeFakeDockerClient([
            ['Name' => 'tonidata_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata']],
        ]);
        $apps = new AppStore($this->tmp . '/apps.sqlite');
        $guard = new VolumeStateGuard(
            $docker,
            new VolumeBackupModeFakeComposeRunner(),
            $apps,
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env-app'),
            new VolumeBackupModeFakeDbDetector(),
        );

        return new VolumeBackupService(
            $docker,
            $apps,
            $guard,
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            $process,
            new BackupRunLock($this->tmp . '/run.lock'),
            new BackupReport($this->tmp . '/report'),
            [
                'docker' => 'docker',
                'image' => 'rames:test',
                'repository' => 's3:https://s3.example.com/bucket/rames',
                'password_file' => $this->tmp . '/password',
            ],
            null,
            null,
            'skip',
            $this->tmp . '/env-creds',
            3600,
            [],
            $selection,
            new BackupCatalog($this->tmp . '/catalog.json'),
        );
    }

    public function testSnapshotModeForgetsDailyAndWeeklyWithoutMonthly(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.sqlite');
        $runner = new VolumeBackupModeRecordingRunner();

        $run = $this->makeService($runner, $selection)->run([
            'trigger' => 'manual',
            'volumes' => ['tonidata_data'],
        ]);

        $this->assertSame('ok', $run['volumes'][0]['status']);
        $this->assertNull($run['volumes'][0]['error'] ?? null, 'retensi sukses tanpa error');

        $argv = $runner->forgetArgv();
        $this->assertNotSame([], $argv, 'mode snapshot wajib memanggil forget');
        $this->assertSame('7', $runner->keepValue('daily'), 'default volume_backup_keep_daily');
        $this->assertSame('4', $runner->keepValue('weekly'), 'default volume_backup_keep_weekly');
        $this->assertNull($runner->keepValue('monthly'), 'tidak ada --keep-monthly');
        $this->assertNull($runner->keepValue('last'), 'mode snapshot bukan cermin');
        $this->assertContains('--tag', $argv);
        $this->assertContains('volume:tonidata_data', $argv);
    }

    public function testSyncModeForgetsKeepLastOneOnly(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.sqlite');
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1');
        $runner = new VolumeBackupModeRecordingRunner();

        $run = $this->makeService($runner, $selection)->run([
            'trigger' => 'manual',
            'volumes' => ['tonidata_data'],
        ]);

        $this->assertSame('ok', $run['volumes'][0]['status']);

        $argv = $runner->forgetArgv();
        $this->assertNotSame([], $argv, 'mode sync tetap memanggil forget');
        $this->assertSame('1', $runner->keepValue('last'), 'sync = --keep-last 1');
        $this->assertNull($runner->keepValue('daily'), 'sync TIDAK memakai --keep-daily');
        $this->assertNull($runner->keepValue('weekly'), 'sync TIDAK memakai --keep-weekly');
        $this->assertNull($runner->keepValue('monthly'), 'sync TIDAK memakai --keep-monthly');
        $this->assertContains('volume:tonidata_data', $argv);
    }

    /**
     * Jalur agent: opsi `modes` eksplisit menang atas store seleksi (arah
     * snapshot override → store sync).
     */
    public function testExplicitModeOverrideBeatsStoredMode(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.sqlite');
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1');
        $runner = new VolumeBackupModeRecordingRunner();

        $this->makeService($runner, $selection)->run([
            'trigger' => 'manual',
            'volumes' => ['tonidata_data'],
            'modes' => ['tonidata_data' => BackupSelection::MODE_SNAPSHOT],
        ]);

        $this->assertSame('7', $runner->keepValue('daily'), 'override snapshot menang atas store sync');
        $this->assertNull($runner->keepValue('last'));
    }

    /**
     * Arah sebaliknya: store default `snapshot`, override eksplisit `sync`.
     */
    public function testExplicitSyncOverrideBeatsSnapshotStore(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.sqlite');
        $runner = new VolumeBackupModeRecordingRunner();

        $this->makeService($runner, $selection)->run([
            'trigger' => 'manual',
            'volumes' => ['tonidata_data'],
            'modes' => ['tonidata_data' => BackupSelection::MODE_SYNC],
        ]);

        $this->assertSame('1', $runner->keepValue('last'));
        $this->assertNull($runner->keepValue('daily'));
    }

    /**
     * Nilai mode tak dikenal pada opsi `modes` dinormalisasi ke `snapshot`
     * (fail-safe, bukan mematikan retensi).
     */
    public function testUnknownOverrideModeFallsBackToSnapshot(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.sqlite');
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1');
        $runner = new VolumeBackupModeRecordingRunner();

        $this->makeService($runner, $selection)->run([
            'trigger' => 'manual',
            'volumes' => ['tonidata_data'],
            'modes' => ['tonidata_data' => 'bulanan'],
        ]);

        $this->assertSame('7', $runner->keepValue('daily'));
        $this->assertNull($runner->keepValue('last'));
    }

    /**
     * Opsi `modes` tanpa entri untuk volume ini ⇒ store dipakai (bukan default
     * buta): store sync tetap menghasilkan `--keep-last 1`.
     */
    public function testModesOptionWithoutEntryFallsBackToStore(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.sqlite');
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1');
        $runner = new VolumeBackupModeRecordingRunner();

        $this->makeService($runner, $selection)->run([
            'trigger' => 'manual',
            'volumes' => ['tonidata_data'],
            'modes' => ['volume_lain' => BackupSelection::MODE_SNAPSHOT],
        ]);

        $this->assertSame('1', $runner->keepValue('last'));
        $this->assertNull($runner->keepValue('daily'));
    }
}
