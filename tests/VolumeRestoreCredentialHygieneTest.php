<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\VolumeRestoreService;
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
 * Fake DockerClient — tanpa koneksi socket.
 */
class RestoreCredHygieneFakeDockerClient extends DockerClient
{
    /**
     * @param array<int,array> $containers
     */
    public function __construct(private array $containers = [])
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
        return ['Config' => ['Image' => 'nginx:alpine']];
    }
}

/**
 * Fake detektor DB — volume uji bukan DB (strategi = `snapshot`).
 */
class RestoreCredHygieneFakeDbDetector extends DbContainerDetector
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
 * Fake compose runner — tidak boleh dipanggil (container sudah berhenti).
 */
class RestoreCredHygieneFakeComposeRunner extends DockerComposeRunner
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
 * ProcessRunner palsu: merekam argv lengkap + cuplikan env-file **saat helper
 * "dijalankan"** (membuktikan kredensial ada di FILE, bukan argv). Perintah
 * `snapshots` mengembalikan JSON; `restore` mengembalikan `restoreCode`.
 */
class RestoreCredHygieneRecordingRunner extends ProcessRunner
{
    /** @var array<int,array<int,string>> */
    public array $argvCalls = [];

    /** @var array<int,array{path:string,exists:bool,mode:int,content:string,command:string}> */
    public array $envFileSnapshots = [];

    /** @var string JSON keluaran `restic snapshots`. */
    public string $snapshotsJson = '[]';

    /** @var int exit code untuk perintah `restore`. */
    public int $restoreCode = 0;

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->argvCalls[] = $command;
        $isRestore = in_array('restore', $command, true);
        $label = $isRestore ? 'restore' : 'snapshots';

        foreach ($command as $index => $arg) {
            if ($arg === '--env-file' && isset($command[$index + 1])) {
                $path = (string) $command[$index + 1];
                $exists = is_file($path);
                $this->envFileSnapshots[] = [
                    'path' => $path,
                    'exists' => $exists,
                    'mode' => $exists ? (fileperms($path) & 0777) : 0,
                    'content' => $exists ? (string) file_get_contents($path) : '',
                    'command' => $label,
                ];
            }
        }

        if ($isRestore) {
            return [
                'code' => $this->restoreCode,
                'stdout' => $this->restoreCode === 0 ? 'restored' : '',
                'stderr' => $this->restoreCode === 0 ? '' : 'restic restore gagal',
                'timedOut' => false,
            ];
        }

        return ['code' => 0, 'stdout' => $this->snapshotsJson, 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Higiene kredensial jalur **restore** volume (temuan: `VolumeRestoreService`
 * tidak meneruskan kredensial S3 ke helper restic → restore dari repo S3 gagal
 * autentikasi).
 *
 * Yang dibuktikan:
 *   - kredensial diteruskan lewat `--env-file <path>` (bukan `-e KEY=VALUE`),
 *     dan image helper ter-resolve, pada jalur `snapshots` maupun `restore`;
 *   - env-file dibuat (0600) lalu **selalu dihapus** — sukses maupun gagal
 *     (restic error / snapshot tidak ditemukan);
 *   - tidak ada nilai kredensial di argv/log/laporan.
 *
 * Tanpa Engine/Docker/restic nyata; seluruh state di direktori temp
 * (larangan #15: jangan sentuh data runtime nyata).
 */
class VolumeRestoreCredentialHygieneTest extends TestCase
{
    private const SECRET_KEY = 'AKIARESTORETEST123';
    private const SECRET_VALUE = 's3-secret-restore-xyz';
    private const SNAPSHOT_ID = '1a2b3c4d5e6f7890';

    private string $tmp;
    private string $envDir;
    private string $workRoot;
    private string $passwordFile;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/restorecred_' . bin2hex(random_bytes(4));
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

    private function snapshotsJson(): string
    {
        return json_encode([[
            'id' => self::SNAPSHOT_ID,
            'short_id' => substr(self::SNAPSHOT_ID, 0, 8),
            'time' => '2026-09-30T00:00:00Z',
            'tags' => [
                'volume:tonidata_data',
                'project:tonidata',
                'app:app1',
                'strategy:snapshot',
            ],
            'paths' => ['/data'],
        ]], JSON_PRETTY_PRINT) ?: '[]';
    }

    /**
     * @param callable(string,string):void|null $logger
     */
    private function makeService(RestoreCredHygieneRecordingRunner $runner, ?callable $logger = null): VolumeRestoreService
    {
        $docker = new RestoreCredHygieneFakeDockerClient([[
            'Id' => 'c1',
            'Names' => ['/tonidata-web-1'],
            'State' => 'exited',
            'Labels' => [VolumeTargetMap::LABEL_PROJECT => 'tonidata'],
        ]]);
        $apps = new AppStore($this->tmp . '/apps.json');

        return new VolumeRestoreService(
            $docker,
            new VolumeStateGuard(
                $docker,
                new RestoreCredHygieneFakeComposeRunner(),
                $apps,
                $this->tmp . '/apps',
                new EnvManager($this->tmp . '/env-app'),
                new RestoreCredHygieneFakeDbDetector(),
            ),
            null,
            $runner,
            $this->workRoot,
            $this->resticOverrides(),
            $logger,
            null,
            $this->envDir,
            3600,
            $this->credentials(),
        );
    }

    /**
     * @param array<int,string> $argv
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

    private function assertNoSecretsInArgv(array $argv): void
    {
        $this->assertNotContains(self::SECRET_VALUE, $argv, 'nilai secret tidak boleh masuk argv');
        $this->assertNotContains(self::SECRET_KEY, $argv, 'access key tidak boleh masuk argv');
        $this->assertNotContains('-e', $argv, 'kredensial tidak boleh lewat `-e KEY=VALUE` (bocor ke ps)');
        foreach ($argv as $arg) {
            $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID=', $arg);
            $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY=', $arg);
        }
    }

    public function testSnapshotsArgvUsesEnvFileWithResolvedImage(): void
    {
        $runner = new RestoreCredHygieneRecordingRunner();
        $runner->snapshotsJson = $this->snapshotsJson();
        $service = $this->makeService($runner);

        $service->restore($this->target(), ['id' => 'app1', 'name' => 'tonidata'], self::SNAPSHOT_ID);

        $argv = self::findArgv($runner->argvCalls, 'snapshots');
        $this->assertNotSame([], $argv, 'restic snapshots harus dipanggil');
        $this->assertContains('--env-file', $argv, 'snapshots wajib memakai --env-file');
        $this->assertContains('rames:test', $argv, 'image helper harus ter-resolve di argv');
        $this->assertNoSecretsInArgv($argv);

        $envIndex = array_search('--env-file', $argv, true);
        $this->assertNotFalse($envIndex);
        $this->assertStringStartsWith($this->envDir . '/restic.env.', (string) $argv[$envIndex + 1]);

        $snapshot = $runner->envFileSnapshots[0];
        $this->assertSame('snapshots', $snapshot['command']);
        $this->assertTrue($snapshot['exists'], 'env-file harus ada saat restic snapshots dijalankan');
        $this->assertSame(0600, $snapshot['mode'], 'env-file kredensial wajib 0600');
        // Nilai mentah TANPA kutip: `docker run --env-file` tidak mengupas kutip.
        $this->assertStringContainsString('AWS_ACCESS_KEY_ID=' . self::SECRET_KEY, $snapshot['content']);
        $this->assertStringContainsString('AWS_SECRET_ACCESS_KEY=' . self::SECRET_VALUE, $snapshot['content']);
        $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID="', $snapshot['content']);
        $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY="', $snapshot['content']);
    }

    public function testRestoreArgvUsesEnvFileAndRemovesItOnSuccess(): void
    {
        $runner = new RestoreCredHygieneRecordingRunner();
        $runner->snapshotsJson = $this->snapshotsJson();
        $service = $this->makeService($runner);

        $result = $service->restore($this->target(), ['id' => 'app1', 'name' => 'tonidata'], self::SNAPSHOT_ID);

        $this->assertTrue($result['ok']);
        $this->assertSame('snapshot', $result['strategy']);

        $argv = self::findArgv($runner->argvCalls, 'restore');
        $this->assertNotSame([], $argv, 'restic restore harus dipanggil');
        $this->assertContains('--env-file', $argv, 'restore wajib memakai --env-file');
        $this->assertContains('rames:test', $argv, 'image helper harus ter-resolve di argv');
        $this->assertContains('--target', $argv);
        $this->assertNoSecretsInArgv($argv);

        $restoreSnapshots = array_values(array_filter(
            $runner->envFileSnapshots,
            static fn (array $s): bool => $s['command'] === 'restore'
        ));
        $this->assertNotEmpty($restoreSnapshots, 'restore harus dijalankan dengan env-file');
        $this->assertTrue($restoreSnapshots[0]['exists']);
        $this->assertSame(0600, $restoreSnapshots[0]['mode']);

        $this->assertSame([], glob($this->envDir . '/restic.env.*') ?: [], 'env-file harus dihapus setelah restore sukses');
        $this->assertStringNotContainsString(self::SECRET_VALUE, json_encode($result) ?: '');
        $this->assertStringNotContainsString(self::SECRET_KEY, json_encode($result) ?: '');
    }

    public function testEnvFileIsRemovedWhenRestoreFails(): void
    {
        $runner = new RestoreCredHygieneRecordingRunner();
        $runner->snapshotsJson = $this->snapshotsJson();
        $runner->restoreCode = 1;
        $service = $this->makeService($runner);

        $threw = false;
        try {
            $service->restore($this->target(), ['id' => 'app1', 'name' => 'tonidata'], self::SNAPSHOT_ID);
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringNotContainsString(self::SECRET_VALUE, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET_KEY, $e->getMessage());
        }

        $this->assertTrue($threw, 'restic restore non-zero harus melempar RuntimeException');
        $restoreSnapshots = array_values(array_filter(
            $runner->envFileSnapshots,
            static fn (array $s): bool => $s['command'] === 'restore'
        ));
        $this->assertNotEmpty($restoreSnapshots, 'env-file sempat dibuat sebelum gagal');
        $this->assertTrue($restoreSnapshots[0]['exists']);
        $this->assertSame([], glob($this->envDir . '/restic.env.*') ?: [], 'env-file harus tetap dihapus saat restore gagal');
    }

    public function testEnvFileIsRemovedWhenSnapshotNotFound(): void
    {
        $runner = new RestoreCredHygieneRecordingRunner();
        $runner->snapshotsJson = '[]'; // tidak ada snapshot → resolveStrategy melempar
        $service = $this->makeService($runner);

        $threw = false;
        try {
            $service->restore($this->target(), ['id' => 'app1', 'name' => 'tonidata'], self::SNAPSHOT_ID);
        } catch (RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertNotEmpty($runner->envFileSnapshots, 'env-file harus dibuat untuk panggilan snapshots');
        $this->assertSame([], glob($this->envDir . '/restic.env.*') ?: [], 'env-file harus dihapus meski snapshot tidak ditemukan');
    }

    public function testLoggerNeverReceivesCredentials(): void
    {
        $messages = [];
        $logger = static function (string $project, string $message) use (&$messages): void {
            $messages[] = $project . ' | ' . $message;
        };

        $runner = new RestoreCredHygieneRecordingRunner();
        $runner->snapshotsJson = $this->snapshotsJson();
        $service = $this->makeService($runner, $logger);

        $service->restore($this->target(), ['id' => 'app1', 'name' => 'tonidata'], self::SNAPSHOT_ID);

        $this->assertNotEmpty($messages, 'restore harus mencatat satu baris log');
        foreach ($messages as $line) {
            $this->assertStringNotContainsString(self::SECRET_VALUE, $line);
            $this->assertStringNotContainsString(self::SECRET_KEY, $line);
            $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID', $line);
        }
    }
}
