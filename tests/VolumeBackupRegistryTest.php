<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupCatalog;
use app\library\Backup\BackupRegistry;
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
use RuntimeException;

/**
 * Fake DockerClient — satu volume ber-label compose, tanpa container (strategi
 * `snapshot`, tanpa stop/start). State di memori; tanpa socket.
 */
class VolumeRegistryFakeDockerClient extends DockerClient
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
 * Fake detektor DB — volume uji bukan DB (strategi = `snapshot`).
 */
class VolumeRegistryFakeDbDetector extends DbContainerDetector
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
 * Fake compose runner — merekam stop/start; tidak menjalankan `docker compose`.
 */
class VolumeRegistryFakeComposeRunner extends DockerComposeRunner
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
 * Fake ProcessRunner restic — `backup` → sukses; `snapshots` → payload yang
 * dapat diatur (`snapshotRows`), sehingga jumlah snapshot "live" dapat
 * disimulasikan. `throwOnSnapshots` menirukan repo tak terjangkau.
 */
class VolumeRegistryFakeRunner extends ProcessRunner
{
    /** @var array<int,array<string,mixed>> */
    public array $snapshotRows = [];

    public bool $throwOnSnapshots = false;

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        if (in_array('snapshots', $command, true)) {
            if ($this->throwOnSnapshots) {
                throw new RuntimeException('restic tidak terjangkau (uji)');
            }
            return ['code' => 0, 'stdout' => (string) json_encode($this->snapshotRows), 'stderr' => '', 'timedOut' => false];
        }
        if (in_array('backup', $command, true)) {
            return ['code' => 0, 'stdout' => 'snapshot facefeed saved', 'stderr' => '', 'timedOut' => false];
        }

        return ['code' => 0, 'stdout' => '{}', 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Test pencatatan riwayat volume di `VolumeBackupService`:
 *  - baris `ok` akhir run → entri registry (best-effort);
 *  - counts non-kosong tanpa volume → entri ter-prune (keputusan 3b);
 *  - jumlah tak diketahui (repo tak terjangkau) atau peta counts kosong
 *    (repo kosong/salah bucket) → **jangan** prune;
 *  - backfill registry dari snapshot yang sudah ada (refresh/akhir run) →
 *    volume pra-fitur tetap dapat direstore dari tab Arsip;
 *  - `archived()` = registry ∩ **bukan** volume katalog (cache, tanpa Engine).
 *
 * Tanpa Docker/restic nyata; seluruh state di direktori temp (larangan #15).
 */
class VolumeBackupRegistryTest extends TestCase
{
    private string $tmp;
    private string $registryPath;
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/volreg_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/apps', 0777, true);
        file_put_contents($this->tmp . '/password', "passphrase\n");
        SqliteFixture::apps($this->tmp . '/apps.sqlite', [
            ['id' => 'app1', 'name' => 'tonidata', 'compose_files' => ['docker-compose.yml']],
        ]);

        $this->registryPath = $this->tmp . '/registry.sqlite';
        $this->catalogPath = $this->tmp . '/catalog.json';
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

    /**
     * @return array<string,mixed>
     */
    private function resticOverrides(): array
    {
        return [
            'docker' => 'docker',
            'image' => 'rames:test',
            'repository' => 's3:https://s3.example.com/bucket/rames',
            'password_file' => $this->tmp . '/password',
        ];
    }

    /**
     * @param array<string,mixed> $resticOverrides
     */
    private function makeService(
        BackupRegistry $registry,
        BackupCatalog $catalog,
        VolumeRegistryFakeRunner $runner,
        array $resticOverrides
    ): VolumeBackupService {
        $docker = new VolumeRegistryFakeDockerClient([
            ['Name' => 'tonidata_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata']],
        ]);
        $apps = new AppStore($this->tmp . '/apps.sqlite');
        $guard = new VolumeStateGuard(
            $docker,
            new VolumeRegistryFakeComposeRunner(),
            $apps,
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env-app'),
            new VolumeRegistryFakeDbDetector(),
        );

        return new VolumeBackupService(
            $docker,
            $apps,
            $guard,
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            $runner,
            new BackupRunLock($this->tmp . '/run.lock'),
            new BackupReport($this->tmp . '/report'),
            $resticOverrides,
            null,
            null,
            'skip',
            $this->tmp . '/env-creds',
            3600,
            [],
            new BackupSelection($this->tmp . '/selection.sqlite'),
            $catalog,
            $registry,
        );
    }

    public function testSuccessfulRunUpsertsRegistryEntry(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $catalog = new BackupCatalog($this->catalogPath);
        $runner = new VolumeRegistryFakeRunner();
        // Snapshot "live" ber-tag volume → counts non-nol → entri tidak ter-prune.
        $runner->snapshotRows = [[
            'short_id' => 'facefeed',
            'time' => '2026-10-05T00:00:00Z',
            'tags' => ['volume:tonidata_data'],
            'summary' => ['total_bytes' => 42],
        ]];

        $service = $this->makeService($registry, $catalog, $runner, $this->resticOverrides());
        $run = $service->run(['trigger' => 'manual', 'volumes' => ['tonidata_data']]);

        $this->assertSame(1, $run['totals']['ok']);

        $entry = $registry->read()['tonidata_data'] ?? null;
        $this->assertIsArray($entry, 'baris ok harus tercatat di registry');
        $this->assertSame('tonidata', $entry['project']);
        $this->assertSame('app1', $entry['app_id']);
        $this->assertSame('tonidata', $entry['app_name']);
        $this->assertSame('snapshot', $entry['strategy']);
        $this->assertSame('facefeed', $entry['last_snapshot']);
        $this->assertSame(1, $entry['snapshots'], 'syncCounts mengisi jumlah snapshot live');
    }

    public function testRunWithNoMatchingVolumeRecordsNothing(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $catalog = new BackupCatalog($this->catalogPath);

        $service = $this->makeService($registry, $catalog, new VolumeRegistryFakeRunner(), $this->resticOverrides());

        // Tidak ada volume cocok → tidak ada baris → registry tetap kosong.
        $run = $service->run(['trigger' => 'manual', 'volumes' => ['tidak_ada_data']]);

        $this->assertSame(0, $run['totals']['volumes']);
        $this->assertSame([], $registry->read(), 'run tanpa baris ok tidak mencatat apa pun');
    }

    public function testRefreshCatalogPrunesRegistryWhenSnapshotsExhausted(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $registry->upsertMany(['tonidata_data' => ['project' => 'tonidata', 'strategy' => 'snapshot']]);

        $catalog = new BackupCatalog($this->catalogPath);
        $runner = new VolumeRegistryFakeRunner();
        // Peta counts **non-kosong** (ada snapshot volume lain) → prune sah;
        // `tonidata_data` absen dari peta → count 0 → dibuang (keputusan 3b).
        $runner->snapshotRows = [[
            'short_id' => 'deadbeef',
            'time' => '2026-10-05T00:00:00Z',
            'tags' => ['volume:other_data'],
        ]];

        $service = $this->makeService($registry, $catalog, $runner, $this->resticOverrides());
        $service->refreshCatalog();

        $this->assertSame([], $registry->read(), 'counts non-kosong tanpa volume → entri ter-prune (keputusan 3b)');
    }

    /**
     * Peta counts **kosong** (repo terjangkau tetapi kosong / salah bucket) →
     * guard riwayat: JANGAN prune. Repo kosong tak boleh menghapus riwayat.
     */
    public function testRefreshCatalogKeepsRegistryWhenCountsEmptyMap(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $registry->upsertMany(['tonidata_data' => ['project' => 'tonidata', 'strategy' => 'snapshot']]);

        $catalog = new BackupCatalog($this->catalogPath);
        $runner = new VolumeRegistryFakeRunner(); // snapshotRows = [] → counts [] (kosong)

        $service = $this->makeService($registry, $catalog, $runner, $this->resticOverrides());
        $service->refreshCatalog();

        $this->assertArrayHasKey(
            'tonidata_data',
            $registry->read(),
            'peta counts kosong → jangan prune (riwayat bisa hilang keliru)'
        );
    }

    public function testRefreshCatalogKeepsRegistryWhenCountsUnknown(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $registry->upsertMany(['tonidata_data' => ['project' => 'tonidata', 'strategy' => 'snapshot']]);

        $catalog = new BackupCatalog($this->catalogPath);
        $runner = new VolumeRegistryFakeRunner();
        $runner->throwOnSnapshots = true; // repo tak terjangkau → counts null

        $service = $this->makeService($registry, $catalog, $runner, $this->resticOverrides());
        $service->refreshCatalog();

        $this->assertArrayHasKey(
            'tonidata_data',
            $registry->read(),
            'jumlah tidak diketahui → jangan prune (riwayat bisa hilang keliru)'
        );
    }

    public function testArchivedIsRegistryMinusActiveCatalogVolumes(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $registry->upsertMany([
            'live_data' => ['project' => 'live', 'app_id' => 'app1', 'app_name' => 'live', 'strategy' => 'snapshot'],
            'gone_data' => ['project' => 'gone', 'app_id' => null, 'app_name' => null, 'strategy' => 'snapshot'],
        ]);

        $catalog = new BackupCatalog($this->catalogPath);
        $catalog->write([[
            'name' => 'live_data', 'project' => 'live', 'strategy' => 'snapshot', 'snapshots' => 5,
        ]]);

        $service = $this->makeService($registry, $catalog, new VolumeRegistryFakeRunner(), $this->resticOverrides());

        $archived = $service->archived();

        $this->assertCount(1, $archived, 'hanya volume yang TIDAK ada di katalog aktif');
        $this->assertSame('gone_data', $archived[0]['name']);
        $this->assertSame('gone', $archived[0]['project']);
    }

    public function testArchivedEmptyWhenAllRegistryVolumesAreActive(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $registry->upsertMany(['live_data' => ['project' => 'live', 'strategy' => 'snapshot']]);

        $catalog = new BackupCatalog($this->catalogPath);
        $catalog->write([['name' => 'live_data', 'project' => 'live', 'strategy' => 'snapshot', 'snapshots' => 1]]);

        $service = $this->makeService($registry, $catalog, new VolumeRegistryFakeRunner(), $this->resticOverrides());

        $this->assertSame([], $service->archived());
    }

    /**
     * Celah yang ditutup: volume yang ter-backup SEBELUM fitur registry ada
     * (punya snapshot restic) tetapi belum pernah tercatat lewat `recordRegistry()`
     * (run) harus di-backfill oleh `refreshCatalog()` — tanpa run apa pun.
     */
    public function testRefreshCatalogBackfillsRegistryForPreExistingSnapshots(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $catalog = new BackupCatalog($this->catalogPath);
        $runner = new VolumeRegistryFakeRunner();
        $runner->snapshotRows = [[
            'short_id' => 'cafebabe',
            'time' => '2026-09-01T00:00:00Z',
            'tags' => ['volume:tonidata_data'],
        ]];

        $service = $this->makeService($registry, $catalog, $runner, $this->resticOverrides());
        // Hanya refresh (bukan run) → `recordRegistry()` tidak pernah dipanggil.
        $service->refreshCatalog();

        $entry = $registry->read()['tonidata_data'] ?? null;
        $this->assertIsArray($entry, 'snapshot pra-fitur harus di-backfill saat refresh');
        $this->assertSame('tonidata', $entry['project']);
        $this->assertSame('app1', $entry['app_id']);
        $this->assertSame('tonidata', $entry['app_name']);
        $this->assertSame('snapshot', $entry['strategy']);
        $this->assertSame(1, $entry['snapshots']);
        $this->assertNull($entry['last_snapshot'], 'backfill tidak mengarang id snapshot');
        $this->assertSame(0, $entry['bytes']);
    }

    /**
     * Akhir run (`writeCatalog()`) juga mem-backfill: snapshot lama volume yang
     * **tidak** diproses run ini (mis. di luar filter / tidak `ok`) tetap
     * tercatat, sehingga bisa direstore dari tab Arsip.
     */
    public function testRunBackfillsRegistryFromExistingSnapshotsOutsideFilter(): void
    {
        $registry = new BackupRegistry($this->registryPath);
        $catalog = new BackupCatalog($this->catalogPath);
        $runner = new VolumeRegistryFakeRunner();
        $runner->snapshotRows = [[
            'short_id' => 'oldcafe',
            'time' => '2026-09-01T00:00:00Z',
            'tags' => ['volume:tonidata_data'],
        ]];

        $service = $this->makeService($registry, $catalog, $runner, $this->resticOverrides());
        // Filter tak cocok → tidak ada baris `ok` (recordRegistry tak menulis),
        // tetapi `writeCatalog()` tetap mem-backfill dari snapshot live.
        $service->run(['trigger' => 'manual', 'volumes' => ['tidak_ada_data']]);

        $entry = $registry->read()['tonidata_data'] ?? null;
        $this->assertIsArray($entry, 'snapshot lama harus di-backfill saat akhir run');
        $this->assertSame(1, $entry['snapshots']);
        $this->assertNull($entry['last_snapshot']);
    }
}
