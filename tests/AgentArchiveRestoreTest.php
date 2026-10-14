<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentJobController;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentJobStore;
use app\library\Agent\TlsPinMaterializer;
use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupRegistry;
use app\library\Backup\VolumeArchiveRestore;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeStateGuard;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Config;
use Webman\Http\Response;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/** Handler Guzzle tiruan (tanpa jaringan) untuk op `restore_archived`. */
final class AgentArchiveRestoreFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string,body:string}> */
    public array $calls = [];

    /** @var list<Psr7Response|\Throwable> */
    private array $queue = [];

    public function reply(int $status, string $body = ''): self
    {
        $this->queue[] = new Psr7Response($status, ['Content-Type' => 'application/json'], $body);

        return $this;
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('AgentArchiveRestoreFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }

    /** @return array<string,mixed> */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->calls[$index]['body'] ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }
}

/** `DockerClient` tiruan: nama volume target belum dipakai. */
final class AgentArchiveRestoreFakeDocker extends DockerClient
{
    public function __construct()
    {
    }

    public function listVolumes(array $filters = []): array
    {
        return [];
    }
}

/** `DockerClient` tiruan: nama volume target **sudah** dipakai Engine. */
final class AgentArchiveRestoreExistingTargetDocker extends DockerClient
{
    public function __construct()
    {
    }

    public function listVolumes(array $filters = []): array
    {
        return [['Name' => 'demo_data_restored', 'Labels' => []]];
    }
}

/** `VolumeStateGuard` tiruan: `assertStopped()` menolak (container masih berjalan). */
final class AgentArchiveRestoreFakeGuard extends VolumeStateGuard
{
    public bool $assertStoppedCalled = false;

    public function __construct()
    {
    }

    public function containersForVolume(string $volume): array
    {
        return [[
            'id' => 'c1',
            'name' => 'demo-db-1',
            'project' => 'demo',
            'state' => 'exited',
            'running' => false,
            'is_db' => false,
        ]];
    }

    public function assertStopped(string $volume): void
    {
        $this->assertStoppedCalled = true;
        throw new RuntimeException('container masih berjalan (uji)');
    }

    public function stopProject(string $project): void
    {
    }

    public function startProject(string $project): void
    {
    }
}

/** `ProcessRunner` tiruan: merekam argv, tidak pernah menjalankan proses nyata. */
final class AgentArchiveRestoreFakeProcess extends ProcessRunner
{
    /** @var array<int,array<int,string>> */
    public array $commands = [];

    public function __construct()
    {
    }

    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->commands[] = array_map('strval', $command);

        if (in_array('snapshots', $this->commands[count($this->commands) - 1], true)) {
            return [
                'code' => 0,
                'stdout' => (string) json_encode([[
                    'id' => 'abcdef12',
                    'short_id' => 'abcdef12',
                    'time' => '2026-01-01T00:00:00Z',
                    'tags' => ['volume:demo_data', 'strategy:snapshot'],
                ]]),
                'stderr' => '',
                'timedOut' => false,
            ];
        }

        // `docker volume create` sukses; restic/apa pun setelahnya tidak pernah
        // tercapai karena penjaga menolak lebih dulu.
        if (in_array('volume', $this->commands[count($this->commands) - 1], true)) {
            return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
        }

        return ['code' => 1, 'stdout' => '', 'stderr' => 'proses tidak dijalankan di uji', 'timedOut' => false];
    }
}

/**
 * Restore **volume arsip** server agent (rencana multi-server F4 sisa #3) —
 * kontrak yang dikunci:
 *  1. `restore_archived` adalah op **nyata** di agent: ada di
 *     `MODES ≡ OPS ≡ capabilities ≡ cabang eksekusi cli/deploy.php` (tidak ada op
 *     senyap), dijalankan lewat job tingkat server (tanpa proyeksi app karena
 *     volume arsip sudah kehilangan app-nya);
 *  2. control plane mengirimnya ke **server pemilik arsip** (job agent) — bukan
 *     dijalankan di host control plane dengan repo/volume yang salah;
 *  3. `VolumeStateGuard` **tetap** ditegakkan di jalur itu (snapshot filesystem
 *     hanya sah saat container berhenti), dan volume target yang sudah dibuat
 *     dibersihkan bila restore gagal;
 *  4. payload job hanya memuat penanda (`volume`/`snapshot`/`target_name`) —
 *     kredensial apa pun dari klien tidak pernah masuk rekaman job.
 *
 * Seluruh uji: `/tmp`, HTTP/Docker/Proses tiruan, tanpa restic/jaringan nyata.
 */
class AgentArchiveRestoreTest extends TestCase
{
    private const VOLUME = 'demo_data';

    private const TARGET = 'demo_data_restored';

    private const SNAPSHOT = 'abcdef12';

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    /** @var array<int,array<int,string>> */
    private array $spawned = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-arch-restore-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/apps', 0777, true);

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        // Kredensial restic tiruan: spec restic divalidasi `normalizeSpec()`
        // sebelum argv disusun — nilainya tidak pernah dipakai proses nyata di uji.
        file_put_contents($this->tmp . '/password', 'passphrase-uji');
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->tmp . '/rames.sqlite',
                'restic_repository' => 's3:https://s3.invalid.test/bucket/rames',
                'restic_password_file' => $this->tmp . '/password',
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
        $this->spawned = [];
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1) op nyata: MODES ≡ capabilities ≡ cabang cli/deploy.php
    // ==================================================================

    public function testRestoreArchivedIsNotASilentOp(): void
    {
        $this->assertContains('restore_archived', DeployWorkerLauncher::MODES);
        $this->assertContains('restore_archived', AgentJobController::OPS);
        $this->assertContains('restore_archived', DeployWorkerLauncher::SERVER_OPS, 'tanpa app: op tingkat server');

        $version = json_decode((new \app\controller\AgentController())->version()->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('restore_archived', $version['capabilities'], 'capabilities agent ikut otomatis');

        // Cabang eksekusi nyata di worker agent (bukan op yang diiklankan lalu 501).
        $source = (string) file_get_contents(dirname(__DIR__) . '/cli/deploy.php');
        $this->assertMatchesRegularExpression("/\\\$mode === 'restore_archived'/", $source);
        $this->assertStringContainsString('VolumeArchiveRestore', $source);
        $this->assertStringContainsString('->run($target, $snapshot, $targetName)', $source);
    }

    public function testControllerAcceptsRestoreArchivedWithoutAppAndKeepsOnlyMarkers(): void
    {
        $controller = $this->controller();

        [$status, , $body, $raw] = $this->decode($controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'restore_archived',
            'target' => self::VOLUME,
            'snapshot' => self::SNAPSHOT,
            'target_name' => self::TARGET,
            'actor_id' => 'user-7',
            // Klien jahat: kredensial diselipkan di payload.
            's3_secret' => 'AKIA-SUPER-SECRET',
            'restic_password' => 'passphrase-rahasia',
        ]))));

        $this->assertSame(200, $status, 'op tingkat server (tanpa app) wajib diterima');
        $this->assertSame(0, $body['code']);

        $record = (new AgentJobStore($this->tmp . '/jobs'))->find((string) $body['job_id']);
        $this->assertNotNull($record);
        $this->assertSame('restore_archived', (string) $record['op']);
        $this->assertSame(self::VOLUME, (string) $record['app_id'], 'target = nama volume arsip');
        $this->assertSame(
            ['target' => self::VOLUME, 'snapshot' => self::SNAPSHOT, 'target_name' => self::TARGET],
            $record['payload']
        );

        $onDisk = (string) file_get_contents($this->tmp . '/jobs/' . $body['job_id'] . '.json');
        foreach (['AKIA-SUPER-SECRET', 'passphrase-rahasia'] as $secret) {
            $this->assertStringNotContainsString($secret, $onDisk, 'kredensial tidak boleh tersimpan');
            $this->assertStringNotContainsString($secret, $raw, 'kredensial tidak boleh di respons');
        }

        // Worker di server itu di-spawn dengan target = nama volume.
        $this->assertSame(
            [PHP_BINARY, $this->tmp . '/cli/deploy.php', self::VOLUME, 'restore_archived', '', 'user-7'],
            $this->spawned[0]
        );
    }

    public function testControllerRejectsInvalidArchiveMarkersBeforeSpawning(): void
    {
        $controller = $this->controller();

        $cases = [
            ['/etc/passwd', self::SNAPSHOT, self::TARGET],
            [self::VOLUME, 'zz', self::TARGET],
            [self::VOLUME, self::SNAPSHOT, '../x'],
        ];

        foreach ($cases as [$volume, $snapshot, $target]) {
            [$status, , $body] = $this->decode($controller->create($this->request('POST', '/jobs', json_encode([
                'op' => 'restore_archived',
                'target' => $volume,
                'snapshot' => $snapshot,
                'target_name' => $target,
            ]))));

            $this->assertSame(400, $status, "penanda tak sah wajib ditolak ({$volume}/{$snapshot}/{$target})");
            $this->assertSame('invalid_request', $body['reason']);
        }

        $this->assertSame([], $this->spawned, 'tidak boleh meluncurkan worker');
    }

    // ==================================================================
    // (2) control plane mengirim job ke server pemilik arsip
    // ==================================================================

    public function testDispatcherSendsTheJobToTheAgentServer(): void
    {
        $handler = new AgentArchiveRestoreFakeHandler();
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-9', 'op' => 'restore_archived', 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done', 'message' => 'restore arsip ok di agent']]))
            ->reply(200, '')
            ->reply(200, '');

        $result = $this->dispatcher($handler)->restoreArchived(
            'srv-arch',
            self::VOLUME,
            self::SNAPSHOT,
            self::TARGET
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame('srv-arch', $result['server_id']);
        $this->assertSame('POST', $handler->calls[0]['method']);
        $this->assertSame('/jobs', $handler->calls[0]['path']);

        $sent = $handler->jsonBody(0);
        $this->assertSame('restore_archived', $sent['op']);
        $this->assertSame(self::VOLUME, $sent['target']);
        $this->assertSame(self::SNAPSHOT, $sent['snapshot']);
        $this->assertSame(self::TARGET, $sent['target_name']);
        $this->assertArrayNotHasKey('app', $sent, 'volume arsip sudah tidak punya app');
    }

    public function testDeployerValidatesMarkersBeforeAnyHttpCall(): void
    {
        $handler = new AgentArchiveRestoreFakeHandler();
        $deployer = $this->deployer($handler);

        foreach ([
            fn () => $deployer->restoreArchivedVolume('../etc', self::SNAPSHOT, self::TARGET),
            fn () => $deployer->restoreArchivedVolume(self::VOLUME, 'zz', self::TARGET),
            fn () => $deployer->restoreArchivedVolume(self::VOLUME, self::SNAPSHOT, 'a/b'),
        ] as $call) {
            try {
                $call();
                $this->fail('penanda tak sah harus ditolak sebelum HTTP');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame([], $handler->calls, 'tidak boleh ada panggilan HTTP');
    }

    // ==================================================================
    // (3) VolumeStateGuard ditegakkan di jalur arsip
    // ==================================================================

    public function testArchiveRestoreEnforcesStateGuardAndCleansCreatedVolume(): void
    {
        // Registry arsip volume asal (strategi snapshot) — satu-satunya entri yang
        // boleh direstore ke volume baru.
        (new BackupRegistry($this->tmp . '/rames.sqlite'))->upsertMany([
            self::VOLUME => [
                'project' => 'demo',
                'app_id' => null,
                'app_name' => null,
                'strategy' => 'snapshot',
                'last_snapshot' => self::SNAPSHOT,
                'snapshots' => 1,
                'bytes' => 10,
            ],
        ]);

        $guard = new AgentArchiveRestoreFakeGuard();
        $process = new AgentArchiveRestoreFakeProcess();

        $restorer = new VolumeArchiveRestore(
            new AgentArchiveRestoreFakeDocker(),
            $process,
            new VolumeRestoreService(
                new AgentArchiveRestoreFakeDocker(),
                $guard,
                null,
                $process,
                $this->tmp . '/work',
                // Override spec: tidak menyentuh Engine (image/env-file/dns eksplisit).
                ['image' => 'helper:test', 'env_file' => $this->tmp . '/creds.env', 'dns' => ['1.1.1.1']]
            ),
            new BackupRegistry($this->tmp . '/rames.sqlite'),
            'docker'
        );

        try {
            $restorer->run(self::VOLUME, self::SNAPSHOT, self::TARGET);
            $this->fail('penjaga yang menolak harus menggagalkan restore (bukan sukses palsu)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('masih berjalan', $e->getMessage());
        }

        $this->assertTrue($guard->assertStoppedCalled, 'assertStopped() wajib dipanggil jalur snapshot arsip');

        // Volume target dibersihkan (best-effort) & restic restore TIDAK dijalankan.
        $flat = array_map(static fn (array $argv): string => implode(' ', $argv), $process->commands);
        $this->assertNotEmpty(
            array_filter($flat, static fn (string $line): bool => str_contains($line, 'volume rm ' . self::TARGET)),
            'volume target yang gagal direstore wajib dihapus lagi'
        );
        $this->assertSame(
            [],
            array_filter($flat, static fn (string $line): bool => str_contains($line, 'restic restore') || str_contains($line, ' restore ')),
            'restic restore tidak boleh dijalankan setelah penjaga menolak'
        );
    }

    public function testLocalArchiveRestoreRejectsAnExistingTargetVolume(): void
    {
        // Regresi jalur lokal: gate `planArchiveRestore()` (strategi snapshot +
        // nama target belum dipakai) tetap ditegakkan SEBELUM efek samping apa pun
        // (volume dibuat / restic dijalankan).
        (new BackupRegistry($this->tmp . '/rames.sqlite'))->upsertMany([
            self::VOLUME => [
                'project' => 'demo',
                'app_id' => null,
                'app_name' => null,
                'strategy' => 'snapshot',
                'last_snapshot' => self::SNAPSHOT,
                'snapshots' => 1,
                'bytes' => 10,
            ],
        ]);

        $process = new AgentArchiveRestoreFakeProcess();
        $restorer = new VolumeArchiveRestore(
            new AgentArchiveRestoreExistingTargetDocker(),
            $process,
            null,
            new BackupRegistry($this->tmp . '/rames.sqlite'),
            'docker'
        );

        try {
            $restorer->run(self::VOLUME, self::SNAPSHOT, self::TARGET);
            $this->fail('nama volume target yang sudah dipakai harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Volume target sudah ada', $e->getMessage());
        }

        $this->assertSame([], $process->commands, 'tidak boleh ada efek samping (create/restic)');
    }

    // ==================================================================
    // (4) CLI: dispatch ke server agent (dan jalur lokal tidak menyentuh agent)
    // ==================================================================

    public function testCliDispatchesArchiveRestoreToTheAgentServer(): void
    {
        $dispatchLog = $this->tmp . '/dispatch.log';
        $result = $this->runCli(['restore-archived', self::VOLUME, self::SNAPSHOT, self::TARGET, 'srv-arch'], [
            'RAMES_BACKUP_DISPATCHER_CLASS' => FakeBackupDispatcher::class,
            'FAKE_BACKUP_DISPATCH_LOG' => $dispatchLog,
        ]);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(
            'restoreArchived:srv-arch:' . self::VOLUME . ':' . self::SNAPSHOT . ':' . self::TARGET . "\n",
            (string) @file_get_contents($dispatchLog),
            'restore arsip server agent wajib jadi job di server itu'
        );
    }

    public function testLocalArchiveRestoreNeverCallsTheAgentDispatcher(): void
    {
        $dispatchLog = $this->tmp . '/dispatch-local.log';
        // Tanpa `serverId`: jalur lokal. Registry kosong ⇒ ditolak jujur, dan
        // dispatcher agent TIDAK boleh dipanggil.
        $result = $this->runCli(['restore-archived', self::VOLUME, self::SNAPSHOT, self::TARGET], [
            'RAMES_BACKUP_DISPATCHER_CLASS' => FakeBackupDispatcher::class,
            'FAKE_BACKUP_DISPATCH_LOG' => $dispatchLog,
        ]);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Volume arsip tidak ditemukan', (string) $result['stderr']);
        $this->assertFileDoesNotExist($dispatchLog, 'jalur lokal tidak boleh memicu job server agent');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function controller(): AgentJobController
    {
        $launcher = new DeployWorkerLauncher(
            function (array $argv, ?int &$pid = null, array $env = []): bool {
                $this->spawned[] = $argv;
                $pid = 4242;

                return true;
            },
            $this->tmp . '/cli/deploy.php',
            $this->tmp . '/logs/deploy'
        );

        return new AgentJobController(new AgentJobStore($this->tmp . '/jobs'), $launcher);
    }

    private function dispatcher(AgentArchiveRestoreFakeHandler $handler): BackupDispatcher
    {
        $deployer = $this->deployer($handler);

        return new BackupDispatcher(
            static fn (array $server): ?AgentDeployer => $deployer,
            $this->resolver(),
            new AppStore($this->tmp . '/rames.sqlite')
        );
    }

    private function deployer(AgentArchiveRestoreFakeHandler $handler): AgentDeployer
    {
        $server = [
            'id' => 'srv-arch',
            'name' => 'agent-arch',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://agent-arch.test',
            'token' => 'tok-uji-arch-9876',
            'tls_cert_pem' => AgentArchiveRestorePem::selfSigned(),
        ];

        return new AgentDeployer(
            $server,
            new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls')),
            30,
            AgentDeployer::MIN_POLL_INTERVAL_MS,
            static function (int $microseconds): void {
            }
        );
    }

    private function resolver(): \app\library\Deploy\ServerResolver
    {
        $path = $this->tmp . '/servers.sqlite';
        $store = new \app\library\Storage\ServerStore($path);
        $store->create([
            'id' => 'srv-arch',
            'name' => 'agent-arch',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://agent-arch.test',
            'token' => 'tok-uji-arch-9876',
            'tls_cert_pem' => AgentArchiveRestorePem::selfSigned(),
        ]);
        \app\library\Deploy\ServerResolver::forgetMemo();

        return new \app\library\Deploy\ServerResolver($store);
    }

    /**
     * @param array<int,string>    $args
     * @param array<string,string> $extra
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runCli(array $args, array $extra = []): array
    {
        $env = array_merge([
            'DATABASE_PATH' => $this->tmp,
            'RAMES_DB_DIR' => $this->tmp,
            'RAMES_DB_FILE' => $this->tmp . '/rames.sqlite',
            'APPS_PATH' => $this->tmp . '/apps',
            'DOCKER_SOCKET' => '/nonexistent/docker.sock',
        ], $extra);

        return (new ProcessRunner())->run(
            array_merge([PHP_BINARY, dirname(__DIR__) . '/cli/backup.php'], $args),
            dirname(__DIR__),
            120,
            $env
        );
    }

    private function request(string $method, string $path, string $body = ''): Request
    {
        $lines = ["{$method} {$path} HTTP/1.1", 'Host: agent.test'];
        if ($body !== '') {
            $lines[] = 'Content-Type: application/json';
        }
        $lines[] = 'Content-Length: ' . strlen($body);

        return new Request(implode("\r\n", $lines) . "\r\n\r\n" . $body);
    }

    /**
     * @return array{0:int,1:array<string,string>,2:array<string,mixed>,3:string}
     */
    private function decode(Response $response): array
    {
        $raw = $response->rawBody();
        $body = $raw === '' ? [] : (json_decode($raw, true) ?? []);

        return [$response->getStatusCode(), $response->getHeaders(), is_array($body) ? $body : [], $raw];
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new \ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($dir . '/' . $entry);
        }
        @rmdir($dir);
    }
}

/** Sertifikat self-signed kecil untuk pinning `AgentClient` di uji. */
final class AgentArchiveRestorePem
{
    public static function selfSigned(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            throw new RuntimeException('gagal membuat kunci uji');
        }
        $csr = openssl_csr_new(['commonName' => 'agent-arch.test'], $resource, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $resource, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $out);

        return $pem = (string) $out;
    }
}
