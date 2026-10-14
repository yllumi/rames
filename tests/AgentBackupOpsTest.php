<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentController;
use app\controller\AgentJobController;
use app\library\Agent\AgentAppProjection;
use app\library\Agent\AgentArtifactMaterializer;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Agent\AgentJobStore;
use app\library\Backup\BackupCatalog;
use app\library\Backup\BackupJobRunner;
use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupReport;
use app\library\Backup\BackupRunLock;
use app\library\Backup\BackupSelection;
use app\library\Backup\DumpRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeStateGuard;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Template\TemplateCatalog;
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

/**
 * Handler Guzzle tiruan (tanpa jaringan) untuk uji `AgentDeployer` op data F4.
 * Dideklarasikan di berkas ini supaya tetap ter-autoload saat `--filter`.
 */
final class AgentBackupOpsFakeHandler
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
            throw new RuntimeException('AgentBackupOpsFakeHandler: antrean respons habis');
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

/**
 * Op **data** F4 di server agent (plan §4.3/§5) — kontrak yang dikunci:
 *  1. setiap op baru (`backup_run`/`restore`/`db_dump`) benar-benar punya jalur
 *     eksekusi di `cli/deploy.php` **dan** dilaporkan sebagai `capabilities`;
 *  2. payload job hanya membawa penanda (`volume`/`snapshot`/`trigger`) — kunci
 *     kredensial apa pun dari klien **tidak** masuk rekaman job/ respons/log;
 *  3. worker agent menjalankannya **di server itu** (runner di-inject lewat seam
 *     uji `RAMES_BACKUP_RUNNER_CLASS`): job `done`/`error`, tanpa Docker nyata
 *     (socket diarahkan ke path mati);
 *  4. `AgentDeployer` memetakan op ke `POST /jobs` yang benar dan membaca daftar
 *     volume/snapshot dari server itu (`GET /backups/volumes|snapshots`);
 *  5. jalur snapshot **tetap** menegakkan `VolumeStateGuard` (`assertStopped`)
 *     lewat `VolumeBackupService` — tidak ada jalur snapshot tanpa penjaga.
 *
 * Seluruh uji memakai direktori `/tmp`, handler Guzzle palsu, dan subproses
 * `cli/deploy.php` dengan path temp — tidak ada Docker/restic/jaringan nyata.
 */
class AgentBackupOpsTest extends TestCase
{
    private const APP_ID = 'a1b2c3d4e5f60718';

    private const TOKEN = 'tok-agent-uji-f4-9c1d2e';

    private const DATA_OPS = ['backup_run', 'restore', 'db_dump'];

    private string $tmp = '';

    private AgentJobStore $jobs;

    private AgentAppProjection $projection;

    private AgentJobController $controller;

    /** @var array<int,array<int,string>> */
    private array $spawned = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_agentbackup_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }

        $this->spawned = [];
        $this->jobs = new AgentJobStore($this->tmp . '/jobs');
        $this->projection = new AgentAppProjection($this->tmp . '/frames.sqlite');
        $this->controller = new AgentJobController(
            $this->jobs,
            new DeployWorkerLauncher(
                function (array $argv, ?int &$pid = null, array $env = []): bool {
                    $this->spawned[] = $argv;
                    $pid = 4242;

                    return true;
                },
                $this->tmp . '/cli/deploy.php',
                $this->tmp . '/logs/deploy'
            ),
            $this->projection,
            new AgentArtifactMaterializer(
                $this->tmp . '/apps',
                $this->tmp . '/database',
                $this->tmp . '/database/keys',
                new TemplateCatalog($this->tmp . '/templates'),
                new AgentBackupOpsFakeGit()
            )
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1) op baru benar-benar punya jalur eksekusi
    // ==================================================================

    public function testEveryDataOpIsAdvertisedAndHasAnAgentExecutionPath(): void
    {
        $modes = DeployWorkerLauncher::MODES;
        foreach (self::DATA_OPS as $op) {
            $this->assertContains($op, $modes, "opsi `{$op}` wajib ada di MODES/capabilities");
            $this->assertContains($op, AgentJobController::OPS, "opsi `{$op}` wajib diterima endpoint job");
        }

        // capabilities agent = allowlist op (satu sumber).
        $version = json_decode((new AgentController())->version()->rawBody(), true);
        foreach (self::DATA_OPS as $op) {
            $this->assertContains($op, $version['capabilities']);
        }

        // Worker agent wajib punya cabang eksekusi nyata (bukan op senyap):
        // daftar mode + pemanggilan runner yang menjalankan pekerjaan di server itu.
        $source = (string) file_get_contents(dirname(__DIR__) . '/cli/deploy.php');
        $this->assertMatchesRegularExpression(
            "/in_array\(\\\$mode,\s*\['backup_run', 'restore', 'db_dump'\],\s*true\)/",
            $source,
            'cli/deploy.php wajib punya cabang khusus op data'
        );
        foreach (['runBackup', 'restoreVolume', 'dbDump'] as $method) {
            $this->assertStringContainsString(
                '->' . $method . '(',
                $source,
                "cli/deploy.php wajib memanggil `{$method}()` (jalur eksekusi di server agent)"
            );
        }
        $this->assertStringContainsString('BackupJobRunner', $source);
    }

    // ==================================================================
    // (2) validasi & higienitas payload
    // ==================================================================

    public function testDataOpWithoutVolumeIsRejectedAndNothingHappens(): void
    {
        [$status, , $body] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'backup_run', 'app' => $this->appPayload()]))
        ));

        $this->assertSame(400, $status);
        $this->assertSame('invalid_request', $body['reason']);
        $this->assertSame([], $this->spawned, 'tidak boleh meluncurkan worker');
        $this->assertSame([], $this->jobs->all(), 'tidak boleh mencatat job');
    }

    public function testRestoreWithoutValidSnapshotIsRejected(): void
    {
        foreach ([[''], ['zz'], ['../etc/passwd']] as $case) {
            [$status, , $body] = $this->decode($this->controller->create(
                $this->request('POST', '/jobs', json_encode([
                    'op' => 'restore',
                    'app' => $this->appPayload(),
                    'volume' => 'demo_data',
                    'snapshot' => $case[0],
                ]))
            ));

            $this->assertSame(400, $status, 'snapshot tak sah wajib ditolak');
            $this->assertSame('invalid_request', $body['reason']);
        }

        $this->assertSame([], $this->spawned);
        $this->assertSame([], $this->jobs->all());
    }

    public function testInvalidVolumeIsRejected(): void
    {
        foreach (['', '../data', 'data/../x', 'vol name'] as $volume) {
            [$status] = $this->decode($this->controller->create(
                $this->request('POST', '/jobs', json_encode([
                    'op' => 'backup_run',
                    'app' => $this->appPayload(),
                    'volume' => $volume,
                ]))
            ));

            $this->assertSame(400, $status, "volume \"{$volume}\" wajib ditolak");
        }

        $this->assertSame([], $this->spawned);
    }

    public function testDataOpKeepsOnlyMarkersAndNeverCredentials(): void
    {
        [$status, , $body, $raw] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode([
                'op' => 'backup_run',
                'app' => $this->appPayload(),
                'volume' => 'demo_data',
                'trigger' => 'manual',
                // Klien jahat: kredensial diselipkan di payload.
                's3_secret' => 'AKIA-SUPER-SECRET',
                'restic_password' => 'passphrase-rahasia',
                'cloudflare_creds' => 'dns-token-rahasia',
                'mysql_pwd' => 'db-root-password',
            ]))
        ));

        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code']);

        $record = $this->jobs->find((string) $body['job_id']);
        $this->assertNotNull($record);
        $payload = $record['payload'];
        $this->assertSame('demo_data', $payload['volume']);
        $this->assertSame('manual', $payload['trigger']);
        $this->assertSame(self::APP_ID, $payload['app_id']);

        // Hanya penanda yang boleh masuk rekaman job (whitelist), bukan kredensial.
        $this->assertSame(
            ['app_id', 'name', 'ref', 'actor_id', 'preserve_volumes', 'volume', 'trigger'],
            array_keys($payload)
        );

        $onDisk = (string) file_get_contents($this->jobs->dir() . '/' . $body['job_id'] . '.json');
        foreach (['AKIA-SUPER-SECRET', 'passphrase-rahasia', 'dns-token-rahasia', 'db-root-password'] as $secret) {
            $this->assertStringNotContainsString($secret, $onDisk, 'kredensial tidak boleh tersimpan');
            $this->assertStringNotContainsString($secret, $raw, 'kredensial tidak boleh di respons');
        }

        // Penanda volume diteruskan lewat payload job (bukan argv).
        $this->assertSame(
            [PHP_BINARY, $this->tmp . '/cli/deploy.php', self::APP_ID, 'backup_run'],
            $this->spawned[0]
        );
    }

    // ==================================================================
    // (3) worker di server agent benar-benar menjalankan pekerjaannya
    // ==================================================================

    public function testWorkerRunsBackupRestoreAndDumpAtThisHostWithTheJobPayload(): void
    {
        $cases = [
            ['backup_run', ['volume' => 'demo_data', 'trigger' => 'manual'], 'runBackup:demo_data:manual', 'Backup volume demo_data ok (fake).'],
            ['restore', ['volume' => 'demo_data', 'snapshot' => 'abcdef12'], 'restoreVolume:demo_data:abcdef12:' . self::APP_ID, 'Restore volume demo_data selesai (fake).'],
            ['db_dump', ['volume' => 'demo_data'], 'dbDump:demo_data:' . self::APP_ID, 'Dump volume demo_data selesai (fake).'],
        ];

        foreach ($cases as [$op, $payload, $expectedNote, $expectedMessage]) {
            $work = $this->workDir($op);
            $job = $this->seedJob($work, $op, $payload + ['app_id' => self::APP_ID, 'name' => 'demo']);

            $result = $this->runWorker($work, $op, (string) $job['id']);
            $this->assertSame(0, $result['code'], "worker {$op} harus sukses; stderr: " . $result['stderr']);

            $notes = (string) @file_get_contents($work . '/runner.log');
            $this->assertStringContainsString($expectedNote, $notes, "runner harus dipanggil dengan penanda payload untuk {$op}");

            $stored = (new AgentJobStore($work . '/jobs'))->find((string) $job['id']);
            $this->assertSame('done', (string) $stored['status']);
            $this->assertStringContainsString($expectedMessage, (string) $stored['message']);
            $this->assertSame('', (string) $stored['error']);

            // Status app TIDAK diubah menjadi `deploying` (op data bukan deploy).
            $this->assertSame('running', (string) (new AppStore($work . '/database/rames.sqlite'))->find(self::APP_ID)['status']);
        }
    }

    public function testWorkerMarksJobErrorWhenTheBackupFails(): void
    {
        $work = $this->workDir('fail');
        $job = $this->seedJob($work, 'backup_run', ['volume' => 'demo_data', 'app_id' => self::APP_ID, 'name' => 'demo']);

        $result = $this->runWorker($work, 'backup_run', (string) $job['id'], ['FAKE_BACKUP_RUNNER_FAIL' => '1']);
        $this->assertSame(1, $result['code']);

        $stored = (new AgentJobStore($work . '/jobs'))->find((string) $job['id']);
        $this->assertSame('error', (string) $stored['status']);
        $this->assertStringContainsString('fake runner', (string) $stored['error']);
        $this->assertNotSame('', (string) $stored['finished_at']);
    }

    public function testWorkerRefusesDataOpWithoutVolumeMarker(): void
    {
        $work = $this->workDir('novol');
        $job = $this->seedJob($work, 'backup_run', ['app_id' => self::APP_ID, 'name' => 'demo']);

        $result = $this->runWorker($work, 'backup_run', (string) $job['id']);
        $this->assertSame(1, $result['code']);

        $stored = (new AgentJobStore($work . '/jobs'))->find((string) $job['id']);
        $this->assertSame('error', (string) $stored['status']);
        $this->assertStringContainsString('volume', strtolower((string) $stored['error']));
        $this->assertSame('', (string) @file_get_contents($work . '/runner.log'), 'runner tidak boleh dipanggil');
    }

    // ==================================================================
    // (4) AgentDeployer — pemetaan op & pembacaan dari server itu
    // ==================================================================

    public function testDeployerMapsDataOpsToAgentJobs(): void
    {
        $cases = [
            ['backupRun', ['demo_data', 'schedule'], ['op' => 'backup_run', 'volume' => 'demo_data', 'trigger' => 'schedule']],
            ['restoreVolume', ['demo_data', 'abcdef12'], ['op' => 'restore', 'volume' => 'demo_data', 'snapshot' => 'abcdef12']],
            ['dbDump', ['demo_data'], ['op' => 'db_dump', 'volume' => 'demo_data']],
        ];

        foreach ($cases as [$method, $args, $expected]) {
            $handler = new AgentBackupOpsFakeHandler();
            $handler
                ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-1', 'op' => $expected['op'], 'log_offset' => 0]))
                ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done', 'message' => 'selesai di agent']]))
                ->reply(200, '')
                ->reply(200, '');

            $deployer = $this->deployer($handler);
            $result = $deployer->{$method}($this->app(), ...$args);

            $this->assertNotEmpty($handler->calls, "op {$method} wajib mengirim job ke agent");
            $first = $handler->calls[0];
            $this->assertSame('POST', $first['method']);
            $this->assertSame('/jobs', $first['path']);

            $body = $handler->jsonBody(0);
            foreach ($expected as $key => $value) {
                $this->assertSame($value, $body[$key] ?? null, "payload `{$key}` untuk {$method}");
            }
            $this->assertArrayNotHasKey('artifacts', $body, 'op data tidak mengirim artefak app');
            $this->assertSame(self::APP_ID, $body['app']['id'] ?? null);
            $this->assertSame('selesai di agent', $result['message']);
        }
    }

    public function testDeployerValidatesVolumeAndSnapshotBeforeAnyHttpCall(): void
    {
        $handler = new AgentBackupOpsFakeHandler();
        $deployer = $this->deployer($handler);

        foreach ([
            fn () => $deployer->backupRun($this->app(), '../etc'),
            fn () => $deployer->dbDump($this->app(), ''),
        ] as $call) {
            try {
                $call();
                $this->fail('volume tak sah harus ditolak sebelum HTTP');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('volume', strtolower($e->getMessage()));
            }
        }

        try {
            $deployer->restoreVolume($this->app(), 'demo_data', 'zz');
            $this->fail('snapshot tak sah harus ditolak sebelum HTTP');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('snapshot', strtolower($e->getMessage()));
        }

        $this->assertSame([], $handler->calls, 'tidak boleh ada panggilan HTTP');
    }

    public function testDeployerReadsVolumesAndSnapshotsFromTheAgent(): void
    {
        $handler = new AgentBackupOpsFakeHandler();
        $handler->reply(200, json_encode(['code' => 0, 'data' => [
            'volumes' => [['name' => 'demo_data', 'project' => 'demo', 'app_id' => self::APP_ID, 'orphaned' => false, 'strategy' => 'snapshot']],
            'status' => [],
        ]]));
        $handler->reply(200, json_encode(['code' => 0, 'data' => ['volume' => 'demo_data', 'snapshots' => [['id' => 'abcdef12']]]]));

        $deployer = $this->deployer($handler);

        $listing = $deployer->backupVolumes();
        $this->assertSame('demo_data', $listing['volumes'][0]['name']);
        $this->assertSame('GET', $handler->calls[0]['method']);
        $this->assertSame('/backups/volumes', $handler->calls[0]['path']);

        $snapshots = $deployer->backupSnapshots('demo_data');
        $this->assertSame('abcdef12', $snapshots[0]['id']);
        $this->assertSame('/backups/snapshots', $handler->calls[1]['path']);
        $this->assertStringContainsString('volume=demo_data', $handler->calls[1]['query']);
    }

    // ==================================================================
    // (5) VolumeStateGuard tetap ditegakkan di server agent
    // ==================================================================

    public function testFilesystemSnapshotGuardIsEnforcedOnTheAgentPath(): void
    {
        $tmp = $this->tmp . '/guard';
        mkdir($tmp . '/config', 0777, true);
        mkdir($tmp . '/apps', 0777, true);

        $configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $tmp . '/rames.sqlite'], true) . ';' . PHP_EOL
        );
        Config::load($tmp . '/config');
        SqliteFixture::apps($tmp . '/rames.sqlite', [[
            'id' => self::APP_ID,
            'name' => 'demo',
            'status' => 'running',
            'compose_files' => ['docker-compose.yml'],
            'containers' => [],
            'deploy_history' => [],
        ]]);

        try {
            $docker = new AgentBackupOpsFakeDocker();
            $guard = new AgentBackupOpsFakeGuard();
            $guard->refuse = 'container masih berjalan (uji)';

            $apps = new AppStore($tmp . '/rames.sqlite');
            $selection = new BackupSelection($tmp . '/frames.sqlite');

            // VolumeBackupService NYATA (satu-satunya jalur snapshot di server
            // agent) dengan penjaga tiruan: jalur ini WAJIB melewati
            // `assertStopped()` — tidak ada jalur pintas restic dari runner.
            $service = new VolumeBackupService(
                $docker,
                $apps,
                $guard,
                new DumpRunner($docker, null, null, null, $tmp . '/staging', 5),
                new AgentBackupOpsFakeProcess(),
                new BackupRunLock($tmp . '/run.lock'),
                new BackupReport($tmp . '/report'),
                [],
                null,
                null,
                'stop',
                $tmp . '/env-creds',
                3600,
                [],
                $selection,
                new BackupCatalog($tmp . '/catalog.json'),
                new BackupRegistry($tmp . '/rames.sqlite')
            );

            $runner = new BackupJobRunner($service, null, null, $apps, $docker);

            try {
                $runner->runBackup('demo_data', 'manual');
                $this->fail('penjaga yang menolak harus membuat run gagal (bukan sukses palsu)');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('masih berjalan', $e->getMessage(), 'pesan penjaga harus muncul');
            }

            $this->assertTrue($guard->assertStoppedCalled, 'assertStopped() wajib dipanggil jalur snapshot');
        } finally {
            $this->restoreConfigState($configState);
        }
    }

    public function testAgentRunnerNeverWritesBackupHistory(): void
    {
        $tmp = $this->tmp . '/history';
        mkdir($tmp . '/config', 0777, true);

        $configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $tmp . '/rames.sqlite'], true) . ';' . PHP_EOL
        );
        Config::load($tmp . '/config');

        try {
            $runner = new BackupJobRunner();

            $serviceProp = new \ReflectionProperty(BackupJobRunner::class, 'backup');
            $serviceProp->setAccessible(true);
            $flag = new \ReflectionProperty(VolumeBackupService::class, 'recordHistory');
            $flag->setAccessible(true);

            $this->assertFalse(
                $flag->getValue($serviceProp->getValue($runner)),
                'server agent TIDAK menulis riwayat (riwayat milik control plane, plan §4.3)'
            );
        } finally {
            $this->restoreConfigState($configState);
        }

        // Jalur lokal (control plane) tetap menulis riwayat (default true).
        $defaults = (new \ReflectionClass(VolumeBackupService::class))->getDefaultProperties();
        $this->assertTrue((bool) $defaults['recordHistory']);
    }

    public function testExplicitVolumeBypassesTheAgentSelectionStoreForScheduledRuns(): void
    {
        $tmp = $this->tmp . '/scheduled';
        mkdir($tmp . '/config', 0777, true);
        mkdir($tmp . '/apps', 0777, true);

        $configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $tmp . '/rames.sqlite'], true) . ';' . PHP_EOL
        );
        Config::load($tmp . '/config');
        SqliteFixture::apps($tmp . '/rames.sqlite', [[
            'id' => self::APP_ID, 'name' => 'demo', 'status' => 'running',
            'compose_files' => ['docker-compose.yml'], 'containers' => [], 'deploy_history' => [],
        ]]);

        try {
            $docker = new AgentBackupOpsFakeDocker();
            $guard = new AgentBackupOpsFakeGuard(); // tidak menolak: assertStopped lolos
            $apps = new AppStore($tmp . '/rames.sqlite');

            $service = new VolumeBackupService(
                $docker,
                $apps,
                $guard,
                new DumpRunner($docker, null, null, null, $tmp . '/staging', 5),
                new AgentBackupOpsFakeProcess(),
                new BackupRunLock($tmp . '/run.lock'),
                new BackupReport($tmp . '/report'),
                [],
                null,
                null,
                'stop',
                $tmp . '/env-creds',
                3600,
                [],
                new BackupSelection($tmp . '/frames.sqlite'),
                new BackupCatalog($tmp . '/catalog.json'),
                new BackupRegistry($tmp . '/rames.sqlite')
            );

            $runner = new BackupJobRunner($service, null, null, $apps, $docker);

            // Trigger `schedule` dari control plane membawa daftar volume eksplisit:
            // seleksi lokal server agent (default OFF) TIDAK boleh menyaring volume
            // itu — kegagalan yang muncul harus kegagalan restic, bukan "dilewati".
            try {
                $runner->runBackup('demo_data', 'schedule');
                $this->fail('restic tiruan selalu gagal — run harus melaporkan kegagalan');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('demo_data', $e->getMessage());
                $this->assertStringNotContainsString('tidak melaporkan volume', $e->getMessage());
            }
        } finally {
            $this->restoreConfigState($configState);
        }
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

    // ==================================================================
    // Helper
    // ==================================================================

    /** @return array<string,mixed> */
    private function app(): array
    {
        return ['id' => self::APP_ID, 'name' => 'demo', 'server_id' => 'srv-a'];
    }

    private function deployer(AgentBackupOpsFakeHandler $handler): AgentDeployer
    {
        $server = [
            'id' => 'srv-a',
            'name' => 'agent-a',
            'kind' => 'agent',
            'base_url' => 'https://agent-a.test',
            'token' => self::TOKEN,
            'tls_cert_pem' => AgentBackupOpsPem::selfSigned(),
        ];

        $client = new AgentClient(
            $server,
            $handler,
            25,
            new TlsPinMaterializer($this->tmp . '/tls')
        );

        return new AgentDeployer(
            $server,
            $client,
            30,
            AgentDeployer::MIN_POLL_INTERVAL_MS,
            static function (int $microseconds): void {
                // tanpa tidur nyata di uji
            }
        );
    }

    /** Direktori kerja subproses worker (DB + apps + job). */
    private function workDir(string $suffix): string
    {
        $dir = $this->tmp . '/work-' . $suffix . '-' . bin2hex(random_bytes(3));
        foreach (['database', 'apps', 'jobs'] as $sub) {
            mkdir($dir . '/' . $sub, 0777, true);
        }

        SqliteFixture::apps($dir . '/database/rames.sqlite', [[
            'id' => self::APP_ID,
            'name' => 'demo',
            'status' => 'running',
            'server_id' => 'local',
            'compose_files' => ['docker-compose.yml'],
            'containers' => [],
            'deploy_history' => [],
        ]]);

        return $dir;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function seedJob(string $work, string $op, array $payload): array
    {
        return (new AgentJobStore($work . '/jobs'))->create($op, self::APP_ID, $payload);
    }

    /**
     * Jalankan `cli/deploy.php` sebagai subproses nyata dengan path temp & runner
     * tiruan (tanpa Docker/restic): membuktikan cabang eksekusi di server ini.
     *
     * @param array<string,string> $extra
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runWorker(string $work, string $mode, string $jobId, array $extra = []): array
    {
        $env = array_merge([
            'DATABASE_PATH' => $work . '/database',
            'RAMES_DB_DIR' => $work . '/database',
            'APPS_PATH' => $work . '/apps',
            'DOCKER_SOCKET' => '/nonexistent/docker.sock',
            'RAMES_JOB_DIR' => $work . '/jobs',
            'RAMES_JOB_ID' => $jobId,
            'RAMES_BACKUP_RUNNER_CLASS' => FakeBackupJobRunner::class,
            'FAKE_BACKUP_RUNNER_LOG' => $work . '/runner.log',
        ], $extra);

        return (new ProcessRunner())->run(
            [PHP_BINARY, dirname(__DIR__) . '/cli/deploy.php', self::APP_ID, $mode],
            dirname(__DIR__),
            120,
            $env
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function appPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'abc123',
            'status' => 'running',
            'containers' => [],
        ], $overrides);
    }

    private function request(string $method, string $path, string $body = ''): Request
    {
        $lines = ["{$method} {$path} HTTP/1.1", 'Host: agent.example.test'];
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

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

/** GitService tiruan (tanpa proses). */
final class AgentBackupOpsFakeGit extends \app\library\Git\GitService
{
    public function __construct()
    {
    }

    public function clone(string $repoUrl, string $branch, string $dest, ?string $sshKeyPath = null): void
    {
        if (!is_dir($dest) && !mkdir($dest, 0777, true) && !is_dir($dest)) {
            throw new RuntimeException('gagal membuat direktori clone tiruan');
        }
    }
}

/** `DockerClient` tiruan: satu volume compose + satu container DB berjalan. */
final class AgentBackupOpsFakeDocker extends DockerClient
{
    public function __construct()
    {
    }

    public function listVolumes(array $filters = []): array
    {
        return [[
            'Name' => 'demo_data',
            'Labels' => ['com.docker.compose.project' => 'demo'],
        ]];
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        return [[
            'Id' => 'c1',
            'Names' => ['/demo-db-1'],
            'State' => 'running',
            'Labels' => ['com.docker.compose.project' => 'demo'],
            'Mounts' => [['Type' => 'volume', 'Name' => 'demo_data']],
        ]];
    }
}

/** `VolumeStateGuard` tiruan: `assertStopped()` menolak (container masih hidup). */
final class AgentBackupOpsFakeGuard extends VolumeStateGuard
{
    public string $refuse = '';

    public bool $assertStoppedCalled = false;

    public function __construct()
    {
    }

    public function containersForVolume(string $volume): array
    {
        // Non-running: policy `stop` tidak memanggil `docker compose stop`
        // (tidak ada perintah nyata di uji), tetapi `assertStopped()` tetap
        // dipanggil jalur snapshot.
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
        if ($this->refuse !== '') {
            throw new RuntimeException($this->refuse);
        }
    }
}

/** `ProcessRunner` tiruan: tidak pernah menjalankan proses nyata (docker/restic). */
final class AgentBackupOpsFakeProcess extends ProcessRunner
{
    public array $commands = [];

    public function __construct()
    {
    }

    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->commands[] = $command;

        return ['code' => 1, 'stdout' => '', 'stderr' => 'proses tidak dijalankan di uji'];
    }
}

/** Sertifikat self-signed kecil untuk pinning `AgentClient` di uji. */
final class AgentBackupOpsPem
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
        $csr = openssl_csr_new(['commonName' => 'agent-a.test'], $resource, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $resource, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $out);

        return $pem = (string) $out;
    }
}
