<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Backup\ResticRunner;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeStateGuard;
use app\library\Backup\VolumeTargetMap;
use app\library\Db\DbContainerDetector;
use app\library\Deploy\EnvManager;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Fake DockerClient — tanpa koneksi socket. `inspectContainer` mengembalikan
 * image tanpa DNS supaya `HelperImageResolver` tidak menyentuh Engine.
 */
class ArchiveActionsFakeDockerClient extends DockerClient
{
    public function __construct()
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
        return ['Config' => ['Image' => 'nginx:alpine']];
    }
}

/**
 * Fake detektor DB (tak dipakai jalur ini).
 */
class ArchiveActionsFakeDbDetector extends DbContainerDetector
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
 * ProcessRunner palsu yang menulis berkas `.sql` ke bind `/restore` saat
 * perintah `restore` dijalankan (mensimulasikan restic membuka snapshot).
 */
class ArchiveActionsRecordingRunner extends ProcessRunner
{
    /** @var array<int,array<int,string>> */
    public array $argvCalls = [];

    /** @var string|null konten `.sql` yang ditulis; null = tidak menulis apa pun. */
    public ?string $sqlContent = "SELECT 1;\n";

    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->argvCalls[] = $command;

        if (in_array('restore', $command, true) && $this->sqlContent !== null) {
            foreach ($command as $arg) {
                if (str_ends_with($arg, ':/restore:rw')) {
                    $host = substr($arg, 0, -strlen(':/restore:rw'));
                    if ($host !== '' && is_dir($host)) {
                        file_put_contents($host . '/dump.sql', $this->sqlContent);
                    }
                }
            }
        }

        return ['code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Aksi arsip (bagian 2): gate keputusan restore, penemuan berkas `.sql`, dan
 * sanitasi nama unduhan.
 *
 * Semua state di direktori temp — tanpa Docker/restic/HTTP nyata (larangan #15).
 */
class VolumeArchiveActionsTest extends TestCase
{
    private string $tmp;
    private string $workRoot;
    private string $envDir;
    private string $passwordFile;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/archiveact_' . bin2hex(random_bytes(4));
        $this->workRoot = $this->tmp . '/work';
        $this->envDir = $this->tmp . '/env';
        mkdir($this->workRoot, 0777, true);
        mkdir($this->envDir, 0777, true);
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

    // ------------------------------------------------------------------
    // planArchiveRestore — murni data
    // ------------------------------------------------------------------

    public function testPlanArchiveRestoreMissingEntryIs404(): void
    {
        $plan = VolumeRestoreService::planArchiveRestore(null, false);

        $this->assertFalse($plan['ok']);
        $this->assertSame(404, $plan['code']);
        $this->assertSame('Volume arsip tidak ditemukan.', $plan['msg']);
    }

    public function testPlanArchiveRestoreDumpIs422(): void
    {
        $plan = VolumeRestoreService::planArchiveRestore([
            'project' => 'shop',
            'strategy' => 'dump',
        ], false);

        $this->assertFalse($plan['ok']);
        $this->assertSame(422, $plan['code']);
        $this->assertStringContainsString('dump DB', $plan['msg']);
        $this->assertStringContainsString('Unduh SQL', $plan['msg']);
    }

    public function testPlanArchiveRestoreExistingTargetIs422(): void
    {
        $plan = VolumeRestoreService::planArchiveRestore([
            'project' => 'shop',
            'strategy' => 'snapshot',
        ], true);

        $this->assertFalse($plan['ok']);
        $this->assertSame(422, $plan['code']);
        $this->assertSame('Volume target sudah ada — pilih nama lain.', $plan['msg']);
    }

    public function testPlanArchiveRestoreSnapshotSucceeds(): void
    {
        $plan = VolumeRestoreService::planArchiveRestore([
            'project' => 'shop',
            'strategy' => 'snapshot',
        ], false);

        $this->assertTrue($plan['ok']);
        $this->assertSame(0, $plan['code']);
        $this->assertSame('shop', $plan['project']);
    }

    // ------------------------------------------------------------------
    // Argv docker volume (restore arsip) — statik murni, tanpa eksekusi
    // ------------------------------------------------------------------

    public function testArchiveVolumeCreateArgvUsesComposeProjectLabel(): void
    {
        $argv = VolumeRestoreService::archiveVolumeCreateArgv('docker', 'shop', 'shop_data_bak');

        $this->assertSame([
            'docker', 'volume', 'create',
            '--label', 'com.docker.compose.project=shop',
            'shop_data_bak',
        ], $argv);
        $this->assertSame(
            VolumeTargetMap::LABEL_PROJECT . '=shop',
            $argv[4],
            'label memakai VolumeTargetMap::LABEL_PROJECT (satu sumber kebenaran)'
        );
        $this->assertNotContains('-c', $argv, 'argv array + bypass_shell, bukan string shell');
    }

    public function testArchiveVolumeRemoveArgv(): void
    {
        $this->assertSame(
            ['docker', 'volume', 'rm', 'shop_data_bak'],
            VolumeRestoreService::archiveVolumeRemoveArgv('docker', 'shop_data_bak')
        );
    }

    // ------------------------------------------------------------------
    // Helper statik controller
    // ------------------------------------------------------------------

    public function testSafeDownloadNameSanitizesTraversal(): void
    {
        $name = BackupController::safeDownloadName('../../etc/passwd', 'abc"def');

        $this->assertSame('passwd-abc_def.sql', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString('"', $name);
    }

    public function testSafeDownloadNameFallsBackWhenEmpty(): void
    {
        $this->assertSame('backup-backup.sql', BackupController::safeDownloadName('///', '&&'));
    }

    /**
     * Pembentuk nama `<volume>-<snapshot>.sql` untuk input yang sudah valid.
     */
    public function testSafeDownloadNameBuildsVolumeSnapshotSuffix(): void
    {
        $this->assertSame(
            'shop_data-1a2b3c4d5e6f.sql',
            BackupController::safeDownloadName('shop_data', '1a2b3c4d5e6f')
        );
    }

    /**
     * Karakter kontrol (CR/LF/NUL), garis miring, dan kutip harus ternetralkan
     * agar header `Content-Disposition` tidak bisa disuntik.
     */
    public function testSafeDownloadNameStripsControlAndPathChars(): void
    {
        $name = BackupController::safeDownloadName("vol\r\n", "snap\0id");

        $this->assertSame('vol-snap_id.sql', $name);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $name, 'tak boleh ada karakter kontrol');
        $this->assertSame(1, preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name), 'bentuk aman + suffix .sql');

        $slashed = BackupController::safeDownloadName('foo/bar', 'a"b');
        $this->assertSame('bar-a_b.sql', $slashed);
        $this->assertStringNotContainsString('/', $slashed);
        $this->assertStringNotContainsString('"', $slashed);
    }

    public function testVolumeListedRequiresExactMatch(): void
    {
        $volumes = [
            ['Name' => 'myapp_data_extra'],
            ['Name' => 'myapp_data'],
        ];

        $this->assertTrue(BackupController::volumeListed($volumes, 'myapp_data'));
        $this->assertFalse(BackupController::volumeListed($volumes, 'myapp_data_missing'));
        $this->assertFalse(BackupController::volumeListed([], 'myapp_data'));
        $this->assertFalse(BackupController::volumeListed([['Driver' => 'local']], 'myapp_data'));
    }

    public function testVolumeNameGateRejectsTraversal(): void
    {
        foreach (['', '../etc', 'a/b', '-bad', 'bad name'] as $invalid) {
            $threw = false;
            try {
                VolumeStateGuard::assertVolumeName($invalid);
            } catch (\Throwable $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "nama volume \"{$invalid}\" harus ditolak (→ 422)");
        }

        VolumeStateGuard::assertVolumeName('shop_data-bak.1');
        $this->addToAssertionCount(1);
    }

    public function testSnapshotIdGateRejectsNonHex(): void
    {
        foreach (['', 'zzzz', 'abc', '../x'] as $invalid) {
            $threw = false;
            try {
                ResticRunner::assertSnapshotId($invalid);
            } catch (\Throwable $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "id snapshot \"{$invalid}\" harus ditolak (→ 422)");
        }

        $this->assertSame('1a2b3c4d', ResticRunner::assertSnapshotId('1a2b3c4d'));
    }

    // ------------------------------------------------------------------
    // Penemuan berkas .sql
    // ------------------------------------------------------------------

    public function testFindSqlFileReturnsFirstDeterministic(): void
    {
        $root = $this->tmp . '/snap';
        mkdir($root . '/sub', 0777, true);
        file_put_contents($root . '/a.txt', 'x');
        file_put_contents($root . '/b.sql', 'B');
        file_put_contents($root . '/sub/c.sql', 'C');

        $this->assertSame($root . '/b.sql', VolumeRestoreService::findSqlFile($root));
        $this->assertNull(VolumeRestoreService::findSqlFile($this->tmp . '/does-not-exist'));
    }

    // ------------------------------------------------------------------
    // extractSnapshotSql — seam dengan ProcessRunner palsu
    // ------------------------------------------------------------------

    private function makeService(ArchiveActionsRecordingRunner $runner): VolumeRestoreService
    {
        $docker = new ArchiveActionsFakeDockerClient();
        $guard = new VolumeStateGuard(
            $docker,
            null,
            new AppStore($this->tmp . '/apps.sqlite'),
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env-app'),
            new ArchiveActionsFakeDbDetector(),
        );

        return new VolumeRestoreService(
            $docker,
            $guard,
            null,
            $runner,
            $this->workRoot,
            [
                'docker' => 'docker',
                'image' => 'rames:test',
                'repository' => 's3:https://s3.example.com/bucket/rames',
                'password_file' => $this->passwordFile,
                // DNS non-kosong → HelperImageResolver tidak memanggil Engine.
                'dns' => ['127.0.0.1'],
            ],
            null,
            null,
            $this->envDir,
            3600,
            ['AWS_ACCESS_KEY_ID' => 'key', 'AWS_SECRET_ACCESS_KEY' => 'secret'],
        );
    }

    public function testExtractSnapshotSqlFindsFileAndCleansEnvFile(): void
    {
        $runner = new ArchiveActionsRecordingRunner();
        $service = $this->makeService($runner);

        $result = $service->extractSnapshotSql('shop_data', '1a2b3c4d5e6f7890');

        $this->assertSame('dump.sql', basename($result['path']));
        $this->assertFileExists($result['path']);
        $this->assertStringContainsString("SELECT 1;", (string) file_get_contents($result['path']));
        $this->assertDirectoryExists($result['dir']);
        $this->assertStringStartsWith($this->workRoot . '/', $result['dir']);

        // Env-file kredensial dibersihkan setelah restic "selesai".
        $this->assertSame([], glob($this->envDir . '/restic.env.*') ?: []);

        // Pemanggil yang menghapus direktori.
        VolumeRestoreService::removeWorkDir($result['dir']);
        $this->assertDirectoryDoesNotExist($result['dir']);
    }

    public function testExtractSnapshotSqlWithoutSqlThrowsAndCleansDir(): void
    {
        $runner = new ArchiveActionsRecordingRunner();
        $runner->sqlContent = null;
        $service = $this->makeService($runner);

        $threw = false;
        try {
            $service->extractSnapshotSql('shop_data', '1a2b3c4d5e6f7890');
        } catch (InvalidArgumentException $e) {
            $threw = true;
            $this->assertStringContainsString('.sql', $e->getMessage());
        }

        $this->assertTrue($threw, 'snapshot tanpa .sql harus melempar InvalidArgumentException');
        // Direktori temp dibersihkan sendiri saat gagal.
        $leftover = array_values(array_diff(scandir($this->workRoot) ?: [], ['.', '..']));
        $this->assertSame([], $leftover, 'direktori kerja harus dibersihkan saat gagal');
    }
}
