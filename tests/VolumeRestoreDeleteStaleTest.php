<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\ResticRunner;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeStateGuard;
use app\library\Backup\VolumeTargetMap;
use app\library\Db\DbContainerDetector;
use app\library\Db\DbDump;
use app\library\Deploy\EnvManager;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Fake DockerClient — tanpa koneksi socket (`listVolumes` kosong).
 */
class RestoreDeleteFakeDockerClient extends DockerClient
{
    /**
     * @param array<int,array>          $containers
     * @param array<string,array>       $inspect
     */
    public function __construct(private array $containers = [], private array $inspect = [])
    {
        // sengaja tidak memanggil parent (tanpa socket).
    }

    public function listVolumes(array $filters = []): array
    {
        return [];
    }

    public function listContainers(array $filters = []): array
    {
        return $this->containers;
    }

    public function inspectContainer(string $id): array
    {
        return $this->inspect[$id] ?? ['Config' => ['Image' => 'nginx:alpine']];
    }
}

/**
 * Fake detektor DB — hasil `isDbContainer` dapat diatur.
 */
class RestoreDeleteFakeDbDetector extends DbContainerDetector
{
    public function __construct(private bool $isDb = false)
    {
        // sengaja tidak memanggil parent.
    }

    public function isDbContainer(array $inspect): bool
    {
        return $this->isDb;
    }
}

/**
 * Fake compose runner — mencatat stop/start (snapshot path boleh memicu stop).
 */
class RestoreDeleteFakeComposeRunner extends DockerComposeRunner
{
    /** @var array<int,array{0:string,1:string}> */
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
 * ProcessRunner palsu: merekam argv; `snapshots` mengembalikan JSON; `restore`
 * jalur **dump** menulis berkas `.sql` ke bind `/restore` (agar `findFirst`
 * menemukannya) lalu sukses.
 */
class RestoreDeleteRecordingRunner extends ProcessRunner
{
    /** @var array<int,array<int,string>> */
    public array $argvCalls = [];

    public string $snapshotsJson = '[]';

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->argvCalls[] = $command;

        if (in_array('snapshots', $command, true)) {
            return ['code' => 0, 'stdout' => $this->snapshotsJson, 'stderr' => '', 'timedOut' => false];
        }

        if (in_array('restore', $command, true)) {
            // Jalur dump: bind `<hostdir>:/restore:rw` → tulis dump.sql untuk di-import.
            foreach ($command as $arg) {
                if (str_ends_with($arg, ':/restore:rw')) {
                    $host = substr($arg, 0, -strlen(':/restore:rw'));
                    if ($host !== '' && is_dir($host)) {
                        file_put_contents($host . '/dump.sql', "SELECT 1;\n");
                    }
                }
            }
            return ['code' => 0, 'stdout' => 'restored', 'stderr' => '', 'timedOut' => false];
        }

        return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }

    /**
     * @return array<int,string> argv yang memuat token `$token`
     */
    public function argvWith(string $token): array
    {
        foreach ($this->argvCalls as $argv) {
            if (in_array($token, $argv, true)) {
                return $argv;
            }
        }
        return [];
    }
}

/**
 * Fake dump — tidak meng-import apa pun, hanya mencatat pemanggilan.
 */
class RestoreDeleteFakeDbDump extends DbDump
{
    /** @var array<int,array{container:string,db:string}> */
    public array $imports = [];

    public function __construct()
    {
        // sengaja tidak memanggil parent.
    }

    public function import(string $container, array $profile, string $db, string $sql): array
    {
        $this->imports[] = ['container' => $container, 'db' => $db];
        return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Temuan verifier: `restic restore` **tidak** menghapus berkas basi di volume →
 * volume tidak kembali ke keadaan snapshot. Perbaikan: jalur restore **snapshot**
 * memakai `--delete --include /data`, sedangkan jalur restore **dump**
 * (`--target /restore`) tidak boleh menghapus apa pun.
 *
 * Tanpa Engine/Docker/restic nyata; seluruh state di direktori temp
 * (larangan #15: jangan sentuh data runtime nyata).
 */
class VolumeRestoreDeleteStaleTest extends TestCase
{
    private const SNAPSHOT_ID = '1a2b3c4d5e6f7890';
    private const VOLUME = 'tonidata_data';
    private const PROJECT = 'tonidata';

    private string $tmp;
    private string $envDir;
    private string $workRoot;
    private string $passwordFile;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/restoredelete_' . bin2hex(random_bytes(4));
        $this->envDir = $this->tmp . '/env';
        $this->workRoot = $this->tmp . '/work';
        mkdir($this->envDir, 0777, true);
        mkdir($this->workRoot, 0777, true);
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
     * @return array<string,mixed>
     */
    private function resticOverrides(): array
    {
        return [
            'docker' => 'docker',
            'image' => 'rames:test',
            'repository' => 's3:https://s3.example.com/bucket/rames',
            'password_file' => $this->passwordFile,
        ];
    }

    /**
     * @return array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}
     */
    private function target(): array
    {
        return [
            'name' => self::VOLUME,
            'project' => self::PROJECT,
            'app_id' => 'app1',
            'app_name' => self::PROJECT,
            'orphaned' => false,
        ];
    }

    private function snapshotsJson(string $strategy): string
    {
        return json_encode([[
            'id' => self::SNAPSHOT_ID,
            'short_id' => substr(self::SNAPSHOT_ID, 0, 8),
            'time' => '2026-09-30T00:00:00Z',
            'tags' => ['volume:' . self::VOLUME, 'project:' . self::PROJECT, 'strategy:' . $strategy],
            'paths' => ['/data'],
        ]], JSON_PRETTY_PRINT) ?: '[]';
    }

    /**
     * @param array<int,array>    $containers
     * @param array<string,array> $inspect
     */
    private function makeService(
        RestoreDeleteRecordingRunner $runner,
        bool $isDb,
        array $containers,
        array $inspect = [],
        ?DbDump $dbDump = null,
    ): VolumeRestoreService {
        $docker = new RestoreDeleteFakeDockerClient($containers, $inspect);

        return new VolumeRestoreService(
            $docker,
            new VolumeStateGuard(
                $docker,
                new RestoreDeleteFakeComposeRunner(),
                new AppStore($this->tmp . '/apps.json'),
                $this->tmp . '/apps',
                new EnvManager($this->tmp . '/env-app'),
                new RestoreDeleteFakeDbDetector($isDb),
            ),
            $dbDump,
            $runner,
            $this->workRoot,
            $this->resticOverrides(),
            null,
            null,
            $this->envDir,
            3600,
            ['AWS_ACCESS_KEY_ID' => 'AKIAX', 'AWS_SECRET_ACCESS_KEY' => 'secret'],
        );
    }

    // ==================================================================
    // Strategi B — snapshot: WAJIB menghapus berkas basi
    // ==================================================================

    public function testSnapshotRestoreDeletesStaleFilesWithinVolume(): void
    {
        $runner = new RestoreDeleteRecordingRunner();
        $runner->snapshotsJson = $this->snapshotsJson('snapshot');
        $service = $this->makeService($runner, false, [[
            'Id' => 'c1',
            'Names' => ['/tonidata-web-1'],
            'State' => 'exited',
            'Labels' => [VolumeTargetMap::LABEL_PROJECT => self::PROJECT],
        ]]);

        $result = $service->restore($this->target(), ['id' => 'app1', 'name' => self::PROJECT], self::SNAPSHOT_ID);

        $this->assertTrue($result['ok']);
        $this->assertSame('snapshot', $result['strategy']);

        $argv = $runner->argvWith('restore');
        $this->assertNotSame([], $argv, 'restic restore harus dipanggil');

        // Kontrak §2/§5.3: "isi volume ditimpa" → berkas basi dihapus.
        $this->assertContains('--delete', $argv, 'restore snapshot wajib menghapus berkas basi');
        $this->assertContains('--include', $argv, '--delete tanpa filter ditolak restic → --include wajib');
        $this->assertSame(
            ResticRunner::DATA_PATH,
            $argv[array_search('--include', $argv, true) + 1],
            'filter hapus wajib path mount volume (`/data`)'
        );
        $this->assertSame('/', $argv[array_search('--target', $argv, true) + 1]);
        $this->assertContains(self::VOLUME . ':' . ResticRunner::DATA_PATH . ':rw', $argv);

        // Hanya boleh ada SATU `--delete`/`--include`, dan `--delete` mendahului filter.
        $this->assertCount(1, array_keys($argv, '--delete', true));
        $this->assertCount(1, array_keys($argv, '--include', true));
        $this->assertLessThan(
            array_search('--include', $argv, true),
            array_search('--delete', $argv, true)
        );

        // Perintah lain tidak boleh ikut memakai flag destruktif.
        $snapshots = $runner->argvWith('snapshots');
        $this->assertNotSame([], $snapshots);
        $this->assertNotContains('--delete', $snapshots);
        $this->assertNotContains('--include', $snapshots);
    }

    // ==================================================================
    // Strategi A — dump: TIDAK boleh menghapus
    // ==================================================================

    public function testDumpRestoreDoesNotDeleteAnything(): void
    {
        $runner = new RestoreDeleteRecordingRunner();
        $runner->snapshotsJson = $this->snapshotsJson('dump');
        $dbDump = new RestoreDeleteFakeDbDump();
        $service = $this->makeService(
            $runner,
            true,
            [[
                'Id' => 'db1',
                'Names' => ['/tonidata-db-1'],
                'State' => 'running',
                'Labels' => [VolumeTargetMap::LABEL_PROJECT => self::PROJECT],
            ]],
            ['db1' => ['Config' => ['Image' => 'mariadb:11', 'Env' => [
                'MYSQL_USER=app',
                'MYSQL_PASSWORD=pw',
                'MYSQL_DATABASE=dump',
            ]]]],
            $dbDump,
        );

        $result = $service->restore(
            $this->target(),
            ['id' => 'app1', 'name' => self::PROJECT, 'env' => ['MYSQL_USER' => 'app', 'MYSQL_PASSWORD' => 'pw']],
            self::SNAPSHOT_ID
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('dump', $result['strategy']);

        $argv = $runner->argvWith('restore');
        $this->assertNotSame([], $argv, 'restic restore (jalur dump) harus dipanggil');
        $this->assertSame('/restore', $argv[array_search('--target', $argv, true) + 1]);
        $this->assertNotContains('--delete', $argv, 'jalur dump TIDAK boleh menghapus berkas');
        $this->assertNotContains('--include', $argv, 'jalur dump tidak memakai filter hapus');
        $this->assertNotEmpty($dbDump->imports, 'dump wajib di-import ke container DB');
        $this->assertSame('tonidata-db-1', $dbDump->imports[0]['container']);
    }
}
