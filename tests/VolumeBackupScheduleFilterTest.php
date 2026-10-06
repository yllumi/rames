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
use RuntimeException;

/**
 * Fake DockerClient — dua volume ber-label compose, tanpa container (strategi
 * `snapshot`, tidak butuh stop/start). Semua state di memori.
 */
class VolumeScheduleFakeDockerClient extends DockerClient
{
    /**
     * @param array<int,array> $volumes
     * @param array<int,array> $containers
     */
    public function __construct(private array $volumes = [], private array $containers = [])
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }

    public function listVolumes(array $filters = []): array
    {
        return $this->volumes;
    }

    public function listContainers(array $filters = []): array
    {
        return $this->containers;
    }

    public function inspectContainer(string $id): array
    {
        return ['Config' => ['Image' => 'nginx:alpine']];
    }
}

/**
 * Fake DockerClient yang melempar bila ada operasi Engine — dipakai membuktikan
 * jalur baca cache (`catalog()`) & probe lock (`isRunning()`) tidak menyentuh
 * Docker.
 */
class VolumeScheduleThrowingDockerClient extends DockerClient
{
    public function __construct()
    {
        // sengaja tidak memanggil parent.
    }

    public function listVolumes(array $filters = []): array
    {
        throw new RuntimeException('Docker Engine tidak boleh disentuh di jalur cache');
    }

    public function listContainers(array $filters = []): array
    {
        throw new RuntimeException('Docker Engine tidak boleh disentuh di jalur cache');
    }

    public function inspectContainer(string $id): array
    {
        throw new RuntimeException('Docker Engine tidak boleh disentuh di jalur cache');
    }
}

/**
 * Fake detektor DB — volume uji bukan DB (strategi = `snapshot`).
 */
class VolumeScheduleFakeDbDetector extends DbContainerDetector
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
class VolumeScheduleFakeComposeRunner extends DockerComposeRunner
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
 * Fake ProcessRunner — `restic snapshots` → `[]`, `restic backup` → sukses.
 */
class VolumeScheduleFakeRunner extends ProcessRunner
{
    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        if (in_array('snapshots', $command, true)) {
            return ['code' => 0, 'stdout' => '[]', 'stderr' => '', 'timedOut' => false];
        }
        if (in_array('backup', $command, true)) {
            return ['code' => 0, 'stdout' => 'snapshot deadbeef saved', 'stderr' => '', 'timedOut' => false];
        }

        return ['code' => 0, 'stdout' => '{}', 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Test penyaringan seleksi backup berkala di `VolumeBackupService`:
 *  - run `trigger=schedule` tanpa daftar volume → hanya volume ber-flag ON
 *    (opt-in; tanpa entri = OFF) yang diproses;
 *  - daftar volume eksplisit (manual) tetap diproses walau tanpa entri;
 *  - jalur baca cache (`catalog()`) & probe (`isRunning()`) tidak menyentuh Engine.
 *
 * Tanpa Docker/restic nyata; seluruh state di direktori temp (larangan #15).
 */
class VolumeBackupScheduleFilterTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/volsched_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/apps', 0777, true);
        file_put_contents($this->tmp . '/password', "passphrase\n");
        file_put_contents($this->tmp . '/apps.json', json_encode([
            ['id' => 'app1', 'name' => 'tonidata', 'compose_files' => ['docker-compose.yml']],
            ['id' => 'app2', 'name' => 'waha', 'compose_files' => ['docker-compose.yml']],
        ], JSON_PRETTY_PRINT));
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

    private function makeService(BackupSelection $selection): VolumeBackupService
    {
        $docker = new VolumeScheduleFakeDockerClient([
            ['Name' => 'tonidata_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata']],
            ['Name' => 'waha_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'waha']],
        ]);
        $apps = new AppStore($this->tmp . '/apps.json');
        $guard = new VolumeStateGuard(
            $docker,
            new VolumeScheduleFakeComposeRunner(),
            $apps,
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env-app'),
            new VolumeScheduleFakeDbDetector(),
        );

        return new VolumeBackupService(
            $docker,
            $apps,
            $guard,
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            new VolumeScheduleFakeRunner(),
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

    public function testScheduleRunOnlyProcessesScheduledVolumes(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.json');
        $selection->setScheduled('waha_data', true); // tonidata_data tanpa entri → default OFF

        $run = $this->makeService($selection)->run(['trigger' => 'schedule', 'volumes' => null]);

        $this->assertSame(1, $run['totals']['volumes'], 'volume tanpa entri TIDAK diproses saat run berkala');
        $this->assertSame('waha_data', $run['volumes'][0]['name']);
        $this->assertSame(1, $run['totals']['ok']);
    }

    public function testScheduleRunSkipsExplicitlyDisabledVolume(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.json');
        $selection->setScheduled('tonidata_data', true);
        $selection->setScheduled('waha_data', false);

        $run = $this->makeService($selection)->run(['trigger' => 'schedule', 'volumes' => null]);

        $this->assertSame(1, $run['totals']['volumes'], 'flag OFF eksplisit dilewati');
        $this->assertSame('tonidata_data', $run['volumes'][0]['name']);
    }

    public function testExplicitVolumesAreProcessedEvenWithoutEntry(): void
    {
        $selection = new BackupSelection($this->tmp . '/selection.json');

        $run = $this->makeService($selection)->run(['trigger' => 'manual', 'volumes' => ['tonidata_data']]);

        $this->assertSame(1, $run['totals']['volumes'], 'daftar volume eksplisit tidak disaring seleksi');
        $this->assertSame('tonidata_data', $run['volumes'][0]['name']);
        $this->assertSame(1, $run['totals']['ok']);
    }

    public function testCatalogReadAndRunningProbeDoNotTouchEngine(): void
    {
        $docker = new VolumeScheduleThrowingDockerClient();
        $apps = new AppStore($this->tmp . '/apps.json');
        $service = new VolumeBackupService(
            $docker,
            $apps,
            new VolumeStateGuard(
                $docker,
                new VolumeScheduleFakeComposeRunner(),
                $apps,
                $this->tmp . '/apps',
                new EnvManager($this->tmp . '/env-app'),
                new VolumeScheduleFakeDbDetector(),
            ),
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            new VolumeScheduleFakeRunner(),
            new BackupRunLock($this->tmp . '/run.lock'),
            new BackupReport($this->tmp . '/report'),
            [],
            null,
            null,
            null,
            $this->tmp . '/env-creds',
            3600,
            [],
            new BackupSelection($this->tmp . '/selection.json'),
            new BackupCatalog($this->tmp . '/catalog.json'),
        );

        // Bila jalur ini menyentuh Engine, fake akan melempar RuntimeException.
        $catalog = $service->catalog();
        $this->assertSame([], $catalog['volumes']);
        $this->assertNull($catalog['cached_at']);
        $this->assertFalse($service->isRunning());
    }
}
