<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupReport;
use app\library\Backup\BackupRunLock;
use app\library\Backup\BackupSelection;
use app\library\Backup\CredentialEnvFile;
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
 * Fake DockerClient — tanpa koneksi socket.
 */
class CredHygieneFakeDockerClient extends DockerClient
{
    /**
     * @param array<int,array> $volumes
     * @param array<int,array> $containers
     */
    public function __construct(private array $volumes = [], private array $containers = [])
    {
        // sengaja tidak memanggil parent (tanpa socket).
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
class CredHygieneFakeDbDetector extends DbContainerDetector
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
 * Fake compose runner — cukup untuk membuat `VolumeStateGuard` tanpa docker.
 */
class CredHygieneFakeComposeRunner extends DockerComposeRunner
{
    public function __construct()
    {
        parent::__construct(new ProcessRunner(), 'docker', 5);
    }
}

/**
 * ProcessRunner palsu: merekam argv lengkap + cuplikan env-file **pada saat
 * helper "dijalankan"** (membuktikan kredensial ada di FILE, bukan argv).
 */
class CredHygieneRecordingRunner extends ProcessRunner
{
    /** @var array<int,array<int,string>> */
    public array $argvCalls = [];

    /** @var array<int,array{path:string,exists:bool,mode:int,content:string}> */
    public array $envFileSnapshots = [];

    public string $stdout = '[]';
    public string $stderr = '';
    public int $code = 0;

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->argvCalls[] = $command;
        foreach ($command as $index => $arg) {
            if ($arg === '--env-file' && isset($command[$index + 1])) {
                $path = (string) $command[$index + 1];
                $exists = is_file($path);
                $this->envFileSnapshots[] = [
                    'path' => $path,
                    'exists' => $exists,
                    'mode' => $exists ? (fileperms($path) & 0777) : 0,
                    'content' => $exists ? (string) file_get_contents($path) : '',
                ];
            }
        }

        return [
            'code' => $this->code,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'timedOut' => false,
        ];
    }
}

/**
 * Higiene kredensial backup volume (temuan verifier: `runtime/backup/restic.env`
 * tidak pernah dihapus → kredensial S3 bertahan di disk tanpa batas).
 *
 * Yang dibuktikan:
 *   - env-file kredensial ditulis **per-run** (0600) di direktori sementara dan
 *     **selalu dihapus** — sukses maupun gagal;
 *   - kredensial **tidak pernah** masuk argv proses (hanya lewat `--env-file`);
 *   - sisa env-file dari run yang crash dibersihkan best-effort, tanpa menyentuh
 *     berkas lain;
 *   - kredensial tidak bocor ke `status.json`/riwayat run.
 *
 * Semua state di direktori temp; tanpa Engine/Docker/restic nyata
 * (larangan #15: jangan sentuh data runtime nyata).
 */
class VolumeBackupCredentialHygieneTest extends TestCase
{
    private const SECRET_KEY = 'AKIAHYGIENETEST123';
    private const SECRET_VALUE = 's3-secret-value-xyz';

    private string $tmp;
    private string $envDir;
    private string $reportDir;
    private string $passwordFile;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/credhygiene_' . bin2hex(random_bytes(4));
        $this->envDir = $this->tmp . '/env';
        $this->reportDir = $this->tmp . '/report';
        mkdir($this->envDir, 0777, true);
        mkdir($this->reportDir, 0777, true);
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
     * @return array<string,string>
     */
    private function credentials(): array
    {
        return [
            'AWS_ACCESS_KEY_ID' => self::SECRET_KEY,
            'AWS_SECRET_ACCESS_KEY' => self::SECRET_VALUE,
            'AWS_DEFAULT_REGION' => 'us-east-1',
        ];
    }

    private function makeService(CredHygieneRecordingRunner $runner): VolumeBackupService
    {
        $docker = new CredHygieneFakeDockerClient();
        $apps = new AppStore($this->tmp . '/apps.sqlite');

        return new VolumeBackupService(
            $docker,
            $apps,
            new VolumeStateGuard(
                $docker,
                new CredHygieneFakeComposeRunner(),
                $apps,
                $this->tmp . '/apps',
                new EnvManager($this->tmp . '/env-app'),
                new CredHygieneFakeDbDetector(),
            ),
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            $runner,
            new BackupRunLock($this->tmp . '/run.lock'),
            new BackupReport($this->reportDir),
            $this->resticOverrides(),
            null,
            null,
            null,
            $this->envDir,
            3600,
            $this->credentials(),
            // Isolasi larangan #15: seleksi (backfill) tidak boleh menyentuh
            // `database/backup.json` nyata — arahkan ke path temp tes.
            selection: new BackupSelection($this->tmp . '/backup.sqlite'),
        );
    }

    public function testEnvFileIsCreatedThenRemovedOnSuccess(): void
    {
        $runner = new CredHygieneRecordingRunner();
        $runner->stdout = '[]';
        $service = $this->makeService($runner);

        $result = $service->snapshotsFor('tonidata_data');

        $this->assertSame([], $result);
        $this->assertNotEmpty($runner->envFileSnapshots, 'restic harus dipanggil dengan --env-file');

        $snapshot = $runner->envFileSnapshots[0];
        $this->assertTrue($snapshot['exists'], 'env-file harus ada saat restic dijalankan');
        $this->assertStringStartsWith($this->envDir . '/restic.env.', $snapshot['path']);
        $this->assertSame(0600, $snapshot['mode'], 'env-file kredensial wajib 0600');
        // Nilai mentah TANPA kutip: `docker run --env-file` tidak mengupas kutip,
        // jadi `"…"` akan merusak nilai bagi restic/AWS (S3 Access Denied).
        $this->assertStringContainsString('AWS_ACCESS_KEY_ID=' . self::SECRET_KEY, $snapshot['content']);
        $this->assertStringContainsString('AWS_SECRET_ACCESS_KEY=' . self::SECRET_VALUE, $snapshot['content']);
        $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID="', $snapshot['content']);
        $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY="', $snapshot['content']);

        foreach ($runner->argvCalls as $argv) {
            $this->assertNotContains(self::SECRET_VALUE, $argv, 'nilai secret tidak boleh masuk argv');
            $this->assertNotContains(self::SECRET_KEY, $argv, 'access key tidak boleh masuk argv');
        }

        $this->assertSame([], glob($this->envDir . '/restic.env.*') ?: [], 'env-file harus dihapus setelah run sukses');
    }

    /**
     * Guard injeksi env-file: nilai kredensial dengan `\n`/`\r` tidak boleh
     * menambah baris variabel baru (`docker run --env-file` memperlakukan tiap
     * baris sebagai satu variabel). Nilai wajib ditulis mentah TANPA kutip.
     */
    public function testEnvFileWritesRawUnquotedValuesAndStripsNewlines(): void
    {
        $envFile = new CredentialEnvFile(
            [
                'AWS_ACCESS_KEY_ID' => self::SECRET_KEY,
                'AWS_SECRET_ACCESS_KEY' => "secret-a\r\nAWS_FAKE_INJECTED=evil",
                'AWS_DEFAULT_REGION' => 'us-east-1',
            ],
            $this->envDir,
        );

        $path = $envFile->create();
        try {
            $this->assertNotSame('', $path, 'env-file harus tertulis');
            $this->assertSame(0600, fileperms($path) & 0777, 'env-file wajib 0600');
            $content = (string) file_get_contents($path);

            // (a) mentah tanpa kutip — restic/AWS menerima nilai apa adanya.
            $this->assertStringContainsString('AWS_ACCESS_KEY_ID=' . self::SECRET_KEY . "\n", $content);
            $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID="', $content);
            $this->assertStringNotContainsString('"', $content, 'env-file tidak boleh memuat kutip sama sekali');

            // (b) `\r`/`\n` dibuang → tidak ada baris variabel palsu yang tersuntik.
            $this->assertStringNotContainsString("\r", $content);
            foreach (explode("\n", $content) as $line) {
                $this->assertStringStartsNotWith('AWS_FAKE_INJECTED=', $line, 'tidak boleh ada baris env palsu');
            }
            $this->assertStringContainsString('AWS_SECRET_ACCESS_KEY=secret-aAWS_FAKE_INJECTED=evil' . "\n", $content);

            // satu baris per kredensial.
            $this->assertCount(3, array_filter(explode("\n", $content), static fn (string $l): bool => $l !== ''));
        } finally {
            $envFile->remove($path);
        }
        $this->assertFileDoesNotExist($path, 'remove() harus menghapus env-file (idempotent)');
    }

    public function testEnvFileIsRemovedWhenResticFails(): void
    {
        $runner = new CredHygieneRecordingRunner();
        $runner->stdout = 'bukan-json'; // memaksa `restic snapshots` melempar
        $service = $this->makeService($runner);

        $threw = false;
        try {
            $service->snapshotsFor('tonidata_data');
        } catch (RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'keluaran non-JSON harus melempar RuntimeException');
        $this->assertNotEmpty($runner->envFileSnapshots);
        $this->assertTrue($runner->envFileSnapshots[0]['exists'], 'env-file sempat dibuat sebelum gagal');
        $this->assertSame([], glob($this->envDir . '/restic.env.*') ?: [], 'env-file harus tetap dihapus saat run gagal');
    }

    public function testStaleEnvFilesAreSweptButForeignFilesAreKept(): void
    {
        $stale = $this->envDir . '/restic.env.abcdef12';
        file_put_contents($stale, 'AWS_SECRET_ACCESS_KEY="lama"');
        touch($stale, time() - 7200);
        clearstatcache(true, $stale);

        $fresh = $this->envDir . '/restic.env.cafebabe';
        file_put_contents($fresh, 'fresh');
        touch($fresh, time());
        clearstatcache(true, $fresh);

        // Sisa implementasi lama: `<runtime>/backup/restic.env` (tanpa suffix).
        $legacy = $this->reportDir . '/restic.env';
        file_put_contents($legacy, 'AWS_SECRET_ACCESS_KEY="lama-legacy"');
        touch($legacy, time() - 7200);
        clearstatcache(true, $legacy);

        $foreign = $this->envDir . '/catatan.txt';
        file_put_contents($foreign, 'jangan dihapus');

        $runner = new CredHygieneRecordingRunner();
        $runner->stdout = '[]';
        $service = $this->makeService($runner);
        $service->snapshotsFor('tonidata_data');

        $this->assertFileDoesNotExist($stale, 'sisa env-file lama harus dibersihkan');
        $this->assertFileDoesNotExist($legacy, 'sisa restic.env implementasi lama harus dibersihkan');
        $this->assertFileExists($fresh, 'env-file yang masih baru tidak boleh dihapus');
        $this->assertFileExists($foreign, 'berkas asing di direktori env tidak boleh dihapus');

        $remaining = glob($this->envDir . '/restic.env.*') ?: [];
        $this->assertCount(1, $remaining, 'hanya env-file baru (fresh) yang tersisa');
        $this->assertSame($fresh, $remaining[0]);
    }

    public function testRunCleansEnvFileAndNeverLeaksCredentialsToReportOrArgv(): void
    {
        SqliteFixture::apps($this->tmp . '/apps.sqlite', [[
            'id' => 'app1',
            'name' => 'tonidata',
            'compose_files' => ['docker-compose.yml'],
        ]]);

        $docker = new CredHygieneFakeDockerClient(
            [['Name' => 'tonidata_data', 'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata']]],
            [[
                'Id' => 'c1',
                'Names' => ['/tonidata-web-1'],
                'State' => 'exited',
                'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata'],
            ]]
        );
        $apps = new AppStore($this->tmp . '/apps.sqlite');
        $guard = new VolumeStateGuard(
            $docker,
            new CredHygieneFakeComposeRunner(),
            $apps,
            $this->tmp . '/apps',
            new EnvManager($this->tmp . '/env-app'),
            new CredHygieneFakeDbDetector(),
        );

        $runner = new CredHygieneRecordingRunner();
        $runner->stdout = 'snapshot 1a2b3c4d saved';

        $service = new VolumeBackupService(
            $docker,
            $apps,
            $guard,
            new DumpRunner($docker, null, null, null, $this->tmp . '/staging', 5),
            $runner,
            new BackupRunLock($this->tmp . '/run.lock'),
            new BackupReport($this->reportDir),
            $this->resticOverrides(),
            null,
            null,
            null,
            $this->envDir,
            3600,
            $this->credentials(),
            // Isolasi larangan #15: seleksi (backfill) tidak boleh menyentuh
            // `database/backup.json` nyata — arahkan ke path temp tes.
            selection: new BackupSelection($this->tmp . '/backup.sqlite'),
        );

        $run = $service->run(['trigger' => 'manual', 'volumes' => ['tonidata_data']]);

        $this->assertSame(1, $run['totals']['ok'], 'snapshot volume non-DB harus sukses');
        $this->assertNotEmpty($runner->envFileSnapshots);
        $this->assertTrue($runner->envFileSnapshots[0]['exists']);
        $this->assertSame(0600, $runner->envFileSnapshots[0]['mode']);

        foreach ($runner->argvCalls as $argv) {
            $this->assertNotContains(self::SECRET_VALUE, $argv);
            $this->assertNotContains(self::SECRET_KEY, $argv);
        }

        $this->assertSame([], glob($this->envDir . '/restic.env.*') ?: [], 'env-file harus bersih setelah run');

        $status = (string) file_get_contents($this->reportDir . '/status.json');
        $this->assertStringNotContainsString(self::SECRET_VALUE, $status);
        $this->assertStringNotContainsString(self::SECRET_KEY, $status);

        $runFiles = glob($this->reportDir . '/runs/*.json') ?: [];
        $this->assertNotEmpty($runFiles, 'riwayat run harus ditulis');
        foreach ($runFiles as $file) {
            $content = (string) file_get_contents($file);
            $this->assertStringNotContainsString(self::SECRET_VALUE, $content);
            $this->assertStringNotContainsString(self::SECRET_KEY, $content);
        }
    }
}
