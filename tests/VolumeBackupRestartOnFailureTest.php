<?php
declare(strict_types=1);

namespace Tests;

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

/**
 * Fake DockerClient — volume ber-label compose + container non-DB yang
 * keadaan-nya dapat diubah oleh fake compose runner (simulasi stop→exited,
 * start→running) tanpa koneksi Engine nyata.
 */
class VolumeRestartFailureFakeDockerClient extends DockerClient
{
    /**
     * @param array<int,array> $volumes
     * @param array<int,array> $containers
     */
    public function __construct(private array $volumes = [], public array $containers = [])
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
class VolumeRestartFailureFakeDbDetector extends DbContainerDetector
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
 * Fake compose runner — merekam urutan stop/start dan mensimulasikan efeknya
 * pada keadaan container di fake DockerClient (stop→exited, start→running).
 * Tidak menjalankan `docker compose` nyata.
 */
class VolumeRestartFailureFakeComposeRunner extends DockerComposeRunner
{
    /** @var array<int,string> */
    public array $actions = [];

    public function __construct(private VolumeRestartFailureFakeDockerClient $docker)
    {
        parent::__construct(new ProcessRunner(), 'docker', 5);
    }

    public function stop(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->actions[] = 'stop';
        $this->setState('exited');
    }

    public function start(string $project, string $dir, array $files, ?string $envFile = null): void
    {
        $this->actions[] = 'start';
        $this->setState('running');
    }

    private function setState(string $state): void
    {
        foreach ($this->docker->containers as $index => $container) {
            $this->docker->containers[$index]['State'] = $state;
        }
    }
}

/**
 * ProcessRunner palsu — memaksa `restic backup` gagal (exit code 1), sehingga
 * kegagalan terjadi SETELAH container dihentikan. Command lain dianggap sukses.
 */
class VolumeRestartFailureFakeRunner extends ProcessRunner
{
    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        if (in_array('backup', $command, true)) {
            return ['code' => 1, 'stdout' => '', 'stderr' => 'restic palsu: gagal upload', 'timedOut' => false];
        }

        return ['code' => 0, 'stdout' => '{}', 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Regresi — strategi snapshot policy `stop`: **start ulang WAJIB** terjadi walau
 * tahap setelah stop gagal (restic error), dan kegagalan itu dilaporkan sebagai
 * `failed` (bukan senyap); app tidak boleh tertinggal mati
 * (PLAN_VOLUME_BACKUP.md §5.3 "start dijamin lewat `finally`").
 *
 * Tanpa Engine/Docker/restic nyata; seluruh state di direktori temp
 * (larangan #15: jangan sentuh data runtime nyata).
 */
class VolumeBackupRestartOnFailureTest extends TestCase
{
    private string $tmp;
    private VolumeRestartFailureFakeDockerClient $docker;
    private VolumeRestartFailureFakeComposeRunner $compose;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/volrestart_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/apps', 0777, true);
        file_put_contents(
            $this->tmp . '/apps.json',
            json_encode([[
                'id' => 'app1',
                'name' => 'tonidata',
                'compose_files' => ['docker-compose.yml'],
            ]], JSON_PRETTY_PRINT)
        );
        file_put_contents($this->tmp . '/password', 'passphrase-restic');

        $this->docker = new VolumeRestartFailureFakeDockerClient(
            [['Name' => 'tonidata_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata']]],
            [[
                'Id' => 'c1',
                'Names' => ['/tonidata-web-1'],
                'State' => 'running',
                'Labels' => ['com.docker.compose.project' => 'tonidata'],
            ]]
        );
        $this->compose = new VolumeRestartFailureFakeComposeRunner($this->docker);
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

    public function testStartProjectRunsEvenWhenSnapshotFailsAndResultIsFailed(): void
    {
        $apps = new AppStore($this->tmp . '/apps.json');
        $guard = new VolumeStateGuard(
            $this->docker,
            $this->compose,
            $apps,
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env'),
            new VolumeRestartFailureFakeDbDetector(),
        );

        $service = new VolumeBackupService(
            $this->docker,
            $apps,
            $guard,
            new DumpRunner($this->docker, null, null, null, $this->tmp . '/staging', 5),
            new VolumeRestartFailureFakeRunner(),
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
            'stop',
            $this->tmp . '/env-creds',
            // Isolasi larangan #15: seleksi (backfill) tidak boleh menyentuh
            // `database/backup.json` nyata — arahkan ke path temp tes.
            selection: new BackupSelection($this->tmp . '/backup.json'),
        );

        $run = $service->run(['trigger' => 'manual', 'volumes' => ['tonidata_data']]);

        // Urutan stop→snapshot(gagal)→start: start WAJIB terpanggil.
        $this->assertSame(['stop', 'start'], $this->compose->actions, 'container harus di-stop lalu di-start ulang walau snapshot gagal');

        // Kegagalan dilaporkan (bukan senyap).
        $this->assertSame(1, $run['totals']['failed']);
        $this->assertSame(0, $run['totals']['ok']);
        $this->assertSame('failed', $run['status']);
        $this->assertSame('failed', $run['volumes'][0]['status']);
        $this->assertStringContainsString('restic gagal', (string) $run['volumes'][0]['error']);

        // Bukti efek start: container kembali `running` (app tidak tertinggal mati).
        $this->assertSame('running', $this->docker->containers[0]['State']);
    }
}
