<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupReport;
use app\library\Backup\BackupRunLock;
use app\library\Backup\BackupSelection;
use app\library\Backup\DumpRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeStateGuard;
use app\library\Db\DbContainerDetector;
use app\library\Deploy\EnvManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Fake DockerClient — inspect mengembalikan image **dan** `HostConfig.Dns`
 * dashboard (satu panggilan), tanpa koneksi socket.
 */
class HelperDnsFakeDockerClient extends DockerClient
{
    /** @param array<string,mixed> $inspect */
    public function __construct(private array $inspect = [])
    {
        // sengaja tidak memanggil parent (tanpa socket).
    }

    public function listVolumes(array $filters = []): array
    {
        return [];
    }

    public function listContainers(array $filters = []): array
    {
        return [];
    }

    public function inspectContainer(string $id): array
    {
        return $this->inspect;
    }
}

/**
 * Fake detektor DB — volume uji bukan DB (strategi = `snapshot`).
 */
class HelperDnsFakeDbDetector extends DbContainerDetector
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
 * Fake compose runner — tidak dipanggil (container volume uji tidak berjalan).
 */
class HelperDnsFakeComposeRunner extends DockerComposeRunner
{
    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 5);
    }
}

/**
 * ProcessRunner palsu: merekam argv lengkap; `snapshots` mengembalikan JSON.
 */
class HelperDnsRecordingRunner extends ProcessRunner
{
    /** @var array<int,array<int,string>> */
    public array $argvCalls = [];

    /** @var array<int,int> timeout per panggilan (untuk membuktikan timeout status pendek). */
    public array $timeouts = [];

    public string $snapshotsJson = '[]';

    /** Simulasikan `restic snapshots` yang timeout (S3 tak terjangkau). */
    public bool $snapshotsTimedOut = false;

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->argvCalls[] = $command;
        $this->timeouts[] = $timeout;

        if (in_array('snapshots', $command, true)) {
            if ($this->snapshotsTimedOut) {
                return ['code' => -1, 'stdout' => '', 'stderr' => 'timeout', 'timedOut' => true];
            }
            return ['code' => 0, 'stdout' => $this->snapshotsJson, 'stderr' => '', 'timedOut' => false];
        }

        return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Bug terdiagnosis: helper restic (`docker run …`) **tidak** menerima `--dns`,
 * padahal `resolv.conf` host rusak → resolusi S3 gagal (`curl: (6) Could not
 * resolve host`). Perbaikan: DNS dashboard (`HostConfig.Dns`) diteruskan ke
 * helper di **kedua** jalur (backup & restore).
 *
 * Yang dibuktikan di sini: resolusi DNS nyata dari inspect dashboard mengalir ke
 * argv helper backup maupun restore, lewat service (bukan hanya resolver).
 *
 * Tanpa Engine/Docker/restic nyata; seluruh state di direktori temp
 * (larangan #15: jangan sentuh data runtime nyata).
 */
class VolumeHelperDnsTest extends TestCase
{
    private const SNAPSHOT_ID = '1a2b3c4d5e6f7890';

    private string $tmp;
    private string $passwordFile;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/helperdns_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/env', 0777, true);
        mkdir($this->tmp . '/env-app', 0777, true);
        mkdir($this->tmp . '/work', 0777, true);
        mkdir($this->tmp . '/report', 0777, true);
        $this->passwordFile = $this->tmp . '/password';
        file_put_contents($this->passwordFile, 'passphrase-restic');
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
     * Inspect dashboard: image + DNS eksplisit (pola `docker-compose.yml`).
     *
     * @return array<string,mixed>
     */
    private function dashboardInspect(): array
    {
        return [
            'Config' => ['Image' => 'rames:dashboard'],
            'HostConfig' => ['Dns' => ['8.8.8.8', '1.1.1.1']],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function overrides(): array
    {
        return [
            'docker' => 'docker',
            // Image sengaja kosong → di-resolve dari inspect dashboard.
            'image' => '',
            'repository' => 's3:https://s3.example.com/bucket/rames',
            'password_file' => $this->passwordFile,
            'env_file' => $this->tmp . '/run.env',
        ];
    }

    /**
     * @param array<int,string> $argv
     * @return array<int,string>
     */
    private static function dnsArgs(array $argv): array
    {
        $dns = [];
        foreach ($argv as $index => $arg) {
            if ($arg === '--dns' && isset($argv[$index + 1])) {
                $dns[] = (string) $argv[$index + 1];
            }
        }
        return $dns;
    }

    /**
     * @param array<int,array<int,string>> $calls
     * @return array<int,string>
     */
    private static function findArgv(array $calls, string $needle): array
    {
        foreach ($calls as $argv) {
            if (in_array($needle, $argv, true)) {
                return $argv;
            }
        }
        return [];
    }

    private function makeBackupService(DockerClient $docker, ProcessRunner $runner): VolumeBackupService
    {
        $apps = new AppStore($this->tmp . '/apps.sqlite');
        $guard = new VolumeStateGuard(
            $docker,
            new HelperDnsFakeComposeRunner(),
            $apps,
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env-app'),
            new HelperDnsFakeDbDetector(),
        );

        return new VolumeBackupService(
            $docker,
            $apps,
            $guard,
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            $runner,
            new BackupRunLock($this->tmp . '/run.lock'),
            new BackupReport($this->tmp . '/report'),
            $this->overrides(),
            null,
            null,
            null,
            $this->tmp . '/env',
            3600,
            [],
            // Isolasi larangan #15: seleksi tidak boleh menyentuh store nyata —
            // arahkan ke berkas SQLite temp tes.
            selection: new BackupSelection($this->tmp . '/backup.sqlite'),
        );
    }

    private function makeRestoreService(DockerClient $docker, ProcessRunner $runner): VolumeRestoreService
    {
        $guard = new VolumeStateGuard(
            $docker,
            new HelperDnsFakeComposeRunner(),
            new AppStore($this->tmp . '/apps.sqlite'),
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env-app'),
            new HelperDnsFakeDbDetector(),
        );

        return new VolumeRestoreService(
            $docker,
            $guard,
            null,
            $runner,
            $this->tmp . '/work',
            $this->overrides(),
            null,
            null,
            $this->tmp . '/env',
            3600,
            [],
        );
    }

    /**
     * @return array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}
     */
    private function target(): array
    {
        return [
            'name' => 'tonidata_data',
            'project' => 'tonidata',
            'app_id' => 'app1',
            'app_name' => 'tonidata',
            'orphaned' => false,
        ];
    }

    public function testBackupHelperArgvCarriesDashboardDns(): void
    {
        $runner = new HelperDnsRecordingRunner();
        $service = $this->makeBackupService(new HelperDnsFakeDockerClient($this->dashboardInspect()), $runner);

        $service->snapshotsFor('tonidata_data');

        $argv = $runner->argvCalls[0] ?? [];
        $this->assertNotSame([], $argv, 'restic snapshots harus dipanggil');
        $this->assertSame(['8.8.8.8', '1.1.1.1'], self::dnsArgs($argv), 'jalur backup wajib meneruskan --dns dashboard');
        $this->assertContains('rames:dashboard', $argv, 'image helper juga berasal dari inspect dashboard yang sama');
    }

    public function testRestoreHelperArgvCarriesDashboardDns(): void
    {
        $runner = new HelperDnsRecordingRunner();
        $runner->snapshotsJson = json_encode([[
            'id' => self::SNAPSHOT_ID,
            'short_id' => substr(self::SNAPSHOT_ID, 0, 8),
            'tags' => ['volume:tonidata_data', 'strategy:snapshot'],
        ]]);
        $service = $this->makeRestoreService(new HelperDnsFakeDockerClient($this->dashboardInspect()), $runner);

        $result = $service->restore($this->target(), ['id' => 'app1', 'name' => 'tonidata'], self::SNAPSHOT_ID);

        $this->assertTrue($result['ok']);
        $argv = self::findArgv($runner->argvCalls, 'restore');
        $this->assertNotSame([], $argv, 'restic restore harus dipanggil');
        $this->assertSame(['8.8.8.8', '1.1.1.1'], self::dnsArgs($argv), 'jalur restore wajib meneruskan --dns dashboard');
        $this->assertContains('rames:dashboard', $argv);
    }

    public function testOperationUnaffectedWhenInspectHasNoDns(): void
    {
        // Dashboard tanpa `HostConfig.Dns`: operasi tetap berjalan, tanpa --dns.
        $runner = new HelperDnsRecordingRunner();
        $service = $this->makeBackupService(
            new HelperDnsFakeDockerClient(['Config' => ['Image' => 'rames:dashboard']]),
            $runner
        );

        $service->snapshotsFor('tonidata_data');

        $argv = $runner->argvCalls[0] ?? [];
        $this->assertSame([], self::dnsArgs($argv));
        $this->assertContains('rames:dashboard', $argv);
    }

    /**
     * `GET /api/backups/status` memakai `overview()` → `snapshotCountsByVolume()`.
     * Panggilan hitung snapshot itu **wajib** memakai timeout pendek
     * (`SNAPSHOT_COUNT_TIMEOUT`), bukan `volume_backup_timeout` (3600 dtk) — jika
     * tidak, S3 lambat/tak terjangkau menggantung UI sampai 1 jam.
     */
    public function testStatusOverviewUsesShortSnapshotTimeout(): void
    {
        $runner = new HelperDnsRecordingRunner();
        $service = $this->makeBackupService(new HelperDnsFakeDockerClient($this->dashboardInspect()), $runner);

        $rows = $service->overview([$this->target()]);

        $this->assertCount(1, $rows);
        $this->assertNotSame([], $runner->timeouts, 'restic snapshots harus dipanggil untuk hitung snapshot status');
        $this->assertSame(15, $runner->timeouts[0], 'timeout hitung snapshot status harus pendek (SNAPSHOT_COUNT_TIMEOUT)');
        $this->assertNotSame(3600, $runner->timeouts[0], 'timeout status tidak boleh memakai volume_backup_timeout');
    }

    /**
     * Timeout pada panggilan hitung snapshot **tidak** merobohkan status: baris
     * volume tetap tampil dengan jumlah snapshot `0` (exception tertangkap).
     */
    public function testStatusOverviewSurvivesSnapshotTimeout(): void
    {
        $runner = new HelperDnsRecordingRunner();
        $runner->snapshotsTimedOut = true;
        $service = $this->makeBackupService(new HelperDnsFakeDockerClient($this->dashboardInspect()), $runner);

        $rows = $service->overview([$this->target()]);

        $this->assertCount(1, $rows, 'timeout hitung snapshot tidak boleh menghilangkan baris status');
        $this->assertSame(0, $rows[0]['snapshots'], 'timeout ⇒ jumlah snapshot 0 tanpa menggagalkan status');
    }
}
