<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentController;
use app\controller\AgentJobController;
use app\library\Agent\AgentAppProjection;
use app\library\Agent\AgentArtifactMaterializer;
use app\library\Agent\AgentJobStore;
use app\library\Deploy\DeployCanceller;
use app\library\Deploy\DeployLog;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Git\GitService;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Support\ProcessRunner;
use app\library\Template\TemplateCatalog;
use app\library\Template\TemplatePullService;
use app\library\Template\TemplatePullState;
use app\library\Update\AgentVersionGate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

// Helper webman (`base_path()`, `json()`) memakai konstanta BASE_PATH —
// definisikan seperti test lain agar kelas ini bisa dijalankan di luar runtime.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * `GitService` tiruan untuk endpoint job agent: merekam panggilan `clone()` dan
 * **tidak** menjalankan git nyata (payload app repo di uji ini memuat URL palsu
 * `https://example.test/a.git`). `$fail = true` ⇒ clone gagal.
 *
 * Dideklarasikan di berkas uji ini supaya tetap dapat di-autoload saat dijalankan
 * dengan `--filter` (kelas di berkas uji lain tidak ikut PSR-4).
 */
final class AgentJobControllerFakeGit extends GitService
{
    /** @var array<int,array{repo:string,branch:string,dest:string,sshKey:?string}> */
    public array $clones = [];

    public bool $fail = false;

    /** Sengaja tidak memanggil konstruktor induk: tidak ada proses yang dijalankan. */
    public function __construct()
    {
    }

    public function clone(string $repoUrl, string $branch, string $dest, ?string $sshKeyPath = null): void
    {
        if ($this->fail) {
            throw new RuntimeException('Gagal clone repo: fatal: repository not found (uji).');
        }

        $this->clones[] = [
            'repo' => $repoUrl,
            'branch' => $branch,
            'dest' => $dest,
            'sshKey' => $sshKeyPath,
        ];

        // Direktori hasil clone hanya dibuat (tanpa `.git`): cukup untuk menguji
        // urutan materialisasi → spawn. Clone git sungguhan diuji di
        // `AgentArtifactMaterializerTest` dengan repo fixture lokal.
        if (!is_dir($dest) && !mkdir($dest, 0777, true) && !is_dir($dest)) {
            throw new RuntimeException('gagal membuat direktori clone tiruan');
        }
    }
}

/**
 * `ProcessRunner` tiruan untuk probe read-only `git remote get-url origin` di
 * endpoint preflight (`POST /apps/check`): tanpa proses nyata, remote per
 * direktori diatur lewat {@see self::$remotes}.
 *
 * Dideklarasikan di berkas uji ini supaya tetap dapat di-autoload saat dijalankan
 * dengan `--filter` (kelas di berkas uji lain tidak ikut PSR-4).
 */
final class AgentJobControllerFakeRunner extends ProcessRunner
{
    /** @var array<string,string> direktori ⇒ URL remote `origin` */
    public array $remotes = [];

    /** @var array<int,array<int,string>> */
    public array $commands = [];

    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->commands[] = $command;
        $dir = (string) ($command[2] ?? '');
        $remote = $this->remotes[$dir] ?? null;

        return $remote === null
            ? ['code' => 128, 'stdout' => '', 'stderr' => 'fatal: not a git repository', 'timedOut' => false]
            : ['code' => 0, 'stdout' => $remote . "\n", 'stderr' => '', 'timedOut' => false];
    }
}

/**
 * Endpoint job async server agent (`AgentJobController`, plan §4.1/§4.3 — F1).
 *
 * Yang dikunci:
 *  1. `op` di luar allowlist ⇒ **501** `{reason:'unsupported'}` dan **tanpa**
 *     spawn/job (jangan diamkan fitur yang belum ada);
 *  2. `POST /jobs` valid ⇒ app diproyeksikan (`server_id` → `local`), job
 *     `pending` tercatat, worker diluncurkan dengan argumen mode yang sama, dan
 *     respons memuat `job_id`;
 *  3. spawn gagal ⇒ job `error` + respons `{code:1}` (tidak pernah mengaku sukses);
 *  4. `GET /jobs/{id}` & `/log` — bentuk respons stabil, id tak dikenal ⇒ 404,
 *     offset bekerja dengan header `X-Rames-Offset`, plafon 256 KiB ditegakkan;
 *  5. `capabilities` di `GET /version` = allowlist op yang benar-benar diterima
 *     `POST /jobs` (satu sumber, bukan daftar terpisah);
 *  6. tidak ada token/PEM/nilai `env` yang bocor ke respons maupun rekaman job.
 *
 * Spawn **selalu** palsu (closure pencatat) dan seluruh state ada di `/tmp` —
 * tidak ada proses, `runtime/`, `apps/`, atau `database/rames.sqlite` nyata yang
 * tersentuh.
 */
class AgentJobControllerTest extends TestCase
{
    private const APP_ID = 'a1b2c3d4e5f60718';

    /** Slug template nyata (repo `templates/`) untuk op tingkat server `template_pull`. */
    private const TEMPLATE_SLUG = 'outline';

    private string $tmp = '';
    private string $logDir = '';
    private AgentJobStore $jobs;
    private AgentAppProjection $projection;
    private AgentJobController $controller;
    private AgentJobControllerFakeGit $git;

    /** Seam uji probe remote (`git remote get-url origin`) di preflight. */
    private AgentJobControllerFakeRunner $runner;

    /** @var array<int,array<int,string>> */
    private array $spawned = [];

    /** @var array<int,array<string,string>> env yang diterima tiap spawn */
    private array $spawnedEnv = [];

    /**
     * Apakah file artefak sudah ada **saat** spawn dijalankan (bukti urutan
     * "materialisasi dulu, baru spawn").
     *
     * @var array<int,bool>
     */
    private array $artifactAtSpawn = [];

    private bool $spawnOk = true;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_agentjob_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }

        $this->logDir = $this->tmp . '/logs/deploy';
        $this->spawned = [];
        $this->spawnedEnv = [];
        $this->artifactAtSpawn = [];
        $this->spawnOk = true;

        $this->jobs = new AgentJobStore($this->tmp . '/jobs');
        $this->projection = new AgentAppProjection($this->tmp . '/rames.sqlite');
        $this->git = new AgentJobControllerFakeGit();
        $this->runner = new AgentJobControllerFakeRunner();
        $this->controller = new AgentJobController(
            $this->jobs,
            $this->launcher(),
            $this->projection,
            $this->materializer(),
            null,
            // Preflight tarik image diarahkan ke temp (katalog: template repo,
            // hanya dibaca) supaya uji tidak menulis `runtime/logs/template-pull`.
            new TemplatePullService(
                new TemplateCatalog(dirname(__DIR__) . '/templates'),
                new TemplatePullState($this->tmp . '/pull')
            )
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // POST /jobs
    // ==================================================================

    public function testUnsupportedOpIsRejectedWith501AndNeverSpawns(): void
    {
        [$status, , $body, $raw] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'save_compose', 'app' => $this->appPayload()]))
        ));

        $this->assertSame(501, $status);
        $this->assertSame(1, $body['code']);
        $this->assertSame('unsupported', $body['reason']);
        $this->assertStringContainsString('unsupported', $raw);
        $this->assertSame([], $this->spawned, 'op tak didukung tidak boleh meluncurkan worker');
        $this->assertSame([], $this->jobs->all(), 'op tak didukung tidak boleh mencatat job');
        $this->assertSame([], (new AppStore($this->tmp . '/rames.sqlite'))->all(), 'tanpa proyeksi juga');
    }

    /** Op tak dikenal tidak boleh menggemakan nilai yang dikirim klien. */
    public function testUnsupportedOpErrorDoesNotEchoClientInput(): void
    {
        [, , , $raw] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'token=agt_rahasia', 'app' => $this->appPayload()]))
        ));

        $this->assertStringNotContainsString('agt_rahasia', $raw);
    }

    public function testCreateProjectsAppRecordsPendingJobAndLaunchesWorker(): void
    {
        [$status, , $body] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'deploy', 'app' => $this->appPayload()]))
        ));

        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code']);
        $this->assertSame('deploy', $body['op']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $body['job_id']);

        // Worker diluncurkan dengan bentuk argv yang sama seperti dashboard.
        $this->assertSame(
            [PHP_BINARY, $this->tmp . '/cli/deploy.php', self::APP_ID, 'deploy'],
            $this->spawned[0]
        );

        $record = $this->jobs->find((string) $body['job_id']);
        $this->assertSame('pending', $record['status']);
        $this->assertSame('deploy', $record['op']);
        $this->assertSame(self::APP_ID, $record['app_id']);

        // Proyeksi app ada di store agent, dengan server_id dipetakan ke local.
        $projected = (new AppStore($this->tmp . '/rames.sqlite'))->find(self::APP_ID);
        $this->assertNotNull($projected);
        $this->assertSame(ServerStore::LOCAL_ID, $projected['server_id']);
        $this->assertSame('demo', $projected['name']);
    }

    public function testCreateForwardsRefAndActorToWorker(): void
    {
        [$status, , , ] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'rollback',
            'app' => $this->appPayload(),
            'ref' => 'abc1234',
            'actor_id' => 'user-9',
        ]))));

        $this->assertSame(200, $status);
        $this->assertSame(
            [PHP_BINARY, $this->tmp . '/cli/deploy.php', self::APP_ID, 'rollback', 'abc1234', 'user-9'],
            $this->spawned[0]
        );
    }

    public function testCreateReturnsLogOffsetAndPassesJobIdToWorkerEnv(): void
    {
        // Log deploy app sudah punya isi (job sebelumnya) — offset awal WAJIB
        // menunjuk ke ujung berkas supaya baris lama tidak terulang.
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
        file_put_contents($this->logDir . '/' . self::APP_ID . '.log', str_repeat('baris lama' . "\n", 4));
        $size = (int) filesize($this->logDir . '/' . self::APP_ID . '.log');

        [$status, , $body] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'deploy', 'app' => $this->appPayload()]))
        ));

        $this->assertSame(200, $status);
        $this->assertSame($size, $body['log_offset'] ?? null);

        // Worker menerima id job lewat env (RAMES_JOB_ID) untuk menandai status.
        $this->assertSame(['RAMES_JOB_ID' => (string) $body['job_id']], $this->spawnedEnv[0]);
    }

    public function testCreateForwardsTeardownPreserveVolumesIntoTheJobRecord(): void
    {
        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'teardown',
            'app' => $this->appPayload(),
            'preserve_volumes' => ['vol_data', 42],
        ]))));

        $this->assertSame(200, $status);

        $record = (array) $this->jobs->find((string) $body['job_id']);
        $this->assertSame(['vol_data', '42'], $record['payload']['preserve_volumes'] ?? null);
    }

    /** `forget` (app dihapus di control plane) hanya bermakna & hanya direkam untuk `teardown`. */
    public function testCreateForwardsForgetOnlyForTeardown(): void
    {
        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'teardown',
            'app' => $this->appPayload(),
            'forget' => true,
        ]))));

        $this->assertSame(200, $status);
        $record = (array) $this->jobs->find((string) $body['job_id']);
        $this->assertTrue($record['payload']['forget'] ?? null, 'penanda dibaca worker dari rekaman job');

        // Op lain tidak menghapus app ⇒ penanda diabaikan (bukan disimpan,
        // bukan pula error).
        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->appPayload(),
            'forget' => true,
        ]))));

        $this->assertSame(200, $status);
        $record = (array) $this->jobs->find((string) $body['job_id']);
        $this->assertArrayNotHasKey('forget', $record['payload']);
    }

    // ==================================================================
    // Artefak app (misi ms-f1-artifacts) — materialisasi SEBELUM spawn
    // ==================================================================

    public function testArtifactsAreWrittenBeforeWorkerSpawns(): void
    {
        $compose = "services:\n  web:\n    image: nginx:alpine\n";

        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->composeAppPayload(),
            'artifacts' => ['compose' => $compose, 'compose_name' => 'docker-compose.yml'],
        ]))));

        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code']);
        $this->assertSame($compose, file_get_contents($this->tmp . '/apps/demo/docker-compose.yml'));
        $this->assertSame([true], $this->artifactAtSpawn, 'artefak wajib sudah ada saat worker di-spawn');
        $this->assertCount(1, $this->spawned);
    }

    /** Gagal materialisasi ⇒ job `error`, respons jelas, dan **tanpa** spawn. */
    public function testMaterializeFailureMarksJobErrorAndNeverSpawns(): void
    {
        [$status, , $body, $raw] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->composeAppPayload(),
            // Compose tanpa `services:` ditolak jalur penyimpanan compose.
            'artifacts' => ['compose' => "version: '3'\n", 'compose_name' => 'docker-compose.yml'],
        ]))));

        $this->assertSame(500, $status);
        $this->assertSame(1, $body['code']);
        $this->assertSame('materialize_failed', $body['reason']);
        $this->assertNotSame('', (string) $body['error']);
        $this->assertSame([], $this->spawned, 'worker tidak boleh di-spawn sebelum artefak siap');
        $this->assertStringNotContainsString('version:', $raw, 'pesan galat tidak menggemakan isi payload');

        $record = $this->jobs->find((string) $body['job_id']);
        $this->assertSame('error', $record['status']);
    }

    /**
     * App **repo**: checkout di-clone **di agent** (bukan oleh control plane)
     * sebelum worker di-spawn — inilah yang membuat `LocalDeployer` agent bisa
     * `revParse` direktori app (misi clone-in-agent, plan §4.3/§5 F1).
     */
    public function testRepoAppIsClonedOnTheAgentBeforeWorkerSpawns(): void
    {
        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->appPayload(),
        ]))));

        $this->assertSame(200, $status);
        $this->assertCount(1, $this->git->clones, 'repo app wajib di-clone di agent');
        $this->assertSame('https://example.test/a.git', $this->git->clones[0]['repo']);
        $this->assertSame('main', $this->git->clones[0]['branch']);
        // Dest clone = direktori **sementara** di dalam `apps_path` (di-`rename()`
        // ke `<apps_path>/demo` setelah clone sukses).
        $this->assertMatchesRegularExpression(
            '#^' . preg_quote($this->tmp . '/apps/', '#') . preg_quote(AgentArtifactMaterializer::CLONE_TMP_PREFIX, '#') . 'demo-[0-9a-f]+$#',
            $this->git->clones[0]['dest']
        );
        $this->assertNull($this->git->clones[0]['sshKey'], 'repo publik ⇒ tanpa kunci');
        $this->assertDirectoryExists($this->tmp . '/apps/demo', 'hasil clone dipasang ke direktori app');
        $this->assertCount(1, $this->spawned, 'worker tetap diluncurkan setelah clone');
        $this->assertSame('pending', $this->jobs->find((string) $body['job_id'])['status']);
    }

    /**
     * Clone gagal ⇒ job `error` `materialize_failed`, **tanpa** spawn, dan tidak
     * ada direktori setengah jadi yang ditinggalkan di `apps_path`.
     */
    public function testRepoCloneFailureMarksJobErrorAndNeverSpawns(): void
    {
        $this->git->fail = true;

        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->appPayload(),
        ]))));

        $this->assertSame(500, $status);
        $this->assertSame('materialize_failed', $body['reason']);
        $this->assertStringContainsString('gagal di server agent ini', (string) $body['error']);
        $this->assertSame([], $this->spawned, 'worker tidak boleh di-spawn bila clone gagal');
        $this->assertSame('error', $this->jobs->find((string) $body['job_id'])['status']);

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo');
        $this->assertSame(
            [],
            glob($this->tmp . '/apps/' . AgentArtifactMaterializer::CLONE_TMP_PREFIX . '*') ?: [],
            'direktori clone sementara wajib dibersihkan'
        );
    }

    /** App mode compose tanpa file compose ⇒ ditolak sebelum menyentuh disk. */
    public function testComposeAppWithoutComposeArtifactIsRejected(): void
    {
        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->composeAppPayload(),
        ]))));

        $this->assertSame(500, $status);
        $this->assertSame('materialize_failed', $body['reason']);
        $this->assertStringContainsString('tidak punya file compose', (string) $body['error']);
        $this->assertSame([], $this->spawned);
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo');
    }

    public function testMalformedArtifactsAreRejectedBeforeAnyJobIsRecorded(): void
    {
        foreach ([
            ['token' => 'agt_rahasia'],
            [0 => 'compose'],
            ['compose' => ['bukan' => 'string']],
            ['compose_name' => 'docker-compose.yml'],
        ] as $artifacts) {
            [$status, , $body, $raw] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
                'op' => 'deploy',
                'app' => $this->composeAppPayload(),
                'artifacts' => $artifacts,
            ]))));

            $this->assertSame(400, $status);
            $this->assertSame('invalid_request', $body['reason']);
            $this->assertStringNotContainsString('agt_rahasia', $raw);
        }

        [$status] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->composeAppPayload(),
            'artifacts' => 'bukan-objek',
        ]))));
        $this->assertSame(400, $status);

        $this->assertSame([], $this->spawned);
        $this->assertSame([], $this->jobs->all());
    }

    /** Deploy key ditulis 0600 dan **tidak pernah** masuk rekaman job/respons. */
    public function testDeployKeyIsMaterializedAndNeverRecordedOrEchoed(): void
    {
        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\ncmFoYXNpYS1kZXBsb3kta2V5\n-----END OPENSSH PRIVATE KEY-----\n";

        [$status, , $body, $raw] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->appPayload(['auth_method' => 'ssh', 'ssh_key' => 'keys/demo']),
            'artifacts' => ['ssh_key' => $pem],
        ]))));

        $this->assertSame(200, $status);

        $keyPath = $this->tmp . '/database/keys/demo';
        $this->assertSame($pem, file_get_contents($keyPath));
        $this->assertSame(0600, fileperms($keyPath) & 0777);

        $raws = [$raw];
        $raws[] = $this->decode($this->controller->show($this->request('GET', '/jobs/' . (string) $body['job_id']), (string) $body['job_id']))[3];
        $raws[] = $this->decode($this->controller->showApp($this->request('GET', '/apps/' . self::APP_ID), self::APP_ID))[3];
        foreach (glob($this->tmp . '/jobs/*') ?: [] as $file) {
            $raws[] = (string) file_get_contents($file);
        }
        foreach ($raws as $raw) {
            $this->assertStringNotContainsString('cmFoYXNpYS1kZXBsb3kta2V5', $raw, 'isi deploy key bocor');
        }
    }

    /** Job ulang tidak menghapus artefak (idempoten). */
    public function testSecondJobKeepsExistingArtifacts(): void
    {
        $payload = [
            'op' => 'deploy',
            'app' => $this->composeAppPayload(),
            'artifacts' => ['compose' => "services:\n  web:\n    image: nginx:alpine\n", 'compose_name' => 'docker-compose.yml'],
        ];

        [$first] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode($payload))));
        [$second] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode($payload))));

        $this->assertSame(200, $first);
        $this->assertSame(200, $second);
        $this->assertFileExists($this->tmp . '/apps/demo/docker-compose.yml');
        $this->assertFileExists($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE);
        $this->assertCount(2, $this->spawned);
    }

    // ==================================================================
    // Op tingkat server (F4 lanjutan): template_pull / self_update
    // ==================================================================

    public function testServerOpRecordsTargetAndSpawnsWorker(): void
    {
        [$status, , $body] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'template_pull', 'target' => self::TEMPLATE_SLUG]))
        ));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(0, $body['code']);
        $this->assertSame('template_pull', $body['op']);
        $this->assertArrayHasKey('log_offset', $body);

        // Worker menerima target di slot argv[1] (posisi yang sama dengan id app).
        $this->assertSame(
            [PHP_BINARY, $this->tmp . '/cli/deploy.php', self::TEMPLATE_SLUG, 'template_pull'],
            $this->spawned[0]
        );

        $record = $this->jobs->find((string) $body['job_id']);
        $this->assertSame('template_pull', $record['op']);
        $this->assertSame(self::TEMPLATE_SLUG, $record['app_id'], 'target dipakai sebagai kunci log job');

        // Op tingkat server tidak memproyeksikan app & tidak menulis artefak.
        $this->assertSame([], (new AppStore($this->tmp . '/rames.sqlite'))->all());
        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/' . self::TEMPLATE_SLUG);
    }

    public function testSelfUpdateCarriesModeAndControlPlaneVersionOnly(): void
    {
        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'self_update',
            'target' => 'agent',
            'update_mode' => 'rollback',
            'control_plane_version' => AgentVersionGate::CODEBASE_VERSION,
            'token' => 'agt_rahasia',
        ]))));

        $this->assertSame(200, $status, json_encode($body));

        $record = $this->jobs->find((string) $body['job_id']);
        $this->assertSame('rollback', $record['payload']['update_mode']);
        $this->assertSame(AgentVersionGate::CODEBASE_VERSION, $record['payload']['control_plane_version']);
        $this->assertArrayNotHasKey('token', $record['payload'], 'kunci asing tidak masuk rekaman job');
    }

    public function testServerOpsAreRejectedWhenPayloadIsIncomplete(): void
    {
        $cases = [
            'template_pull tanpa target' => ['op' => 'template_pull'],
            'template_pull slug tak ada' => ['op' => 'template_pull', 'target' => 'tidak-ada'],
            'self_update target salah' => ['op' => 'self_update', 'target' => 'bukan-agent'],
            'self_update mode tak dikenal' => ['op' => 'self_update', 'target' => 'agent', 'update_mode' => 'pwn', 'control_plane_version' => AgentVersionGate::CODEBASE_VERSION],
        ];

        foreach ($cases as $label => $payload) {
            [$status, , $body, $raw] = $this->decode($this->controller->create(
                $this->request('POST', '/jobs', json_encode($payload))
            ));

            $this->assertSame(400, $status, $label);
            $this->assertSame(1, $body['code'], $label);
            $this->assertSame('invalid_request', $body['reason'], $label);
            $this->assertStringNotContainsString('agt_rahasia', $raw);
        }

        // Slug tak dikenal: pesan menyebut template itu tidak ditemukan.
        [, , $body] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'template_pull', 'target' => 'tidak-ada']))
        ));
        $this->assertStringContainsString('tidak ditemukan', (string) $body['error']);

        $this->assertSame([], $this->spawned, 'payload cacat tidak boleh meluncurkan worker');
        $this->assertSame([], $this->jobs->all(), 'payload cacat tidak boleh mencatat job');
    }

    public function testSelfUpdateIsRefusedWhenControlPlaneIsTooOld(): void
    {
        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'self_update',
            'target' => 'agent',
            'update_mode' => 'update',
            'control_plane_version' => '0.0.1',
        ]))));

        $this->assertSame(501, $status);
        $this->assertSame('unsupported', $body['reason']);
        $this->assertStringContainsString('control plane', strtolower((string) $body['error']));
        $this->assertSame([], $this->spawned);
        $this->assertSame([], $this->jobs->all());
    }

    // ==================================================================
    // GET /apps/{id} — proyeksi app (F1)
    // ==================================================================

    public function testShowAppReturnsProjectionWithoutSecrets(): void
    {
        $this->projection->upsert($this->appPayload([
            'ssh_key' => 'deploy_key_demo',
            'compose_files' => ['docker-compose.yml'],
            'status' => 'running',
            'containers' => [['container_name' => 'demo-web-1']],
            'deploy_history' => [['action' => 'deploy', 'status' => 'success']],
            'ssl_status' => 'active',
        ]));

        [$status, , $body] = $this->decode($this->controller->showApp(
            $this->request('GET', '/apps/' . self::APP_ID),
            self::APP_ID
        ));

        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code']);

        $app = $body['app'];
        $this->assertSame(self::APP_ID, $app['id']);
        $this->assertSame('demo', $app['name']);
        $this->assertSame(ServerStore::LOCAL_ID, $app['server_id'], 'proyeksi agent memetakan server_id ke local');
        $this->assertSame('running', $app['status']);
        $this->assertSame([['container_name' => 'demo-web-1']], $app['containers']);
        $this->assertSame([['action' => 'deploy', 'status' => 'success']], $app['deploy_history']);
        $this->assertSame('active', $app['ssl_status']);

        // Whitelist: nilai env (rahasia app) & deploy key tidak pernah keluar.
        $this->assertArrayNotHasKey('env', $app);
        $this->assertArrayNotHasKey('ssh_key', $app);
        $this->assertStringNotContainsString('deploy_key_demo', (string) json_encode($app));
    }

    public function testShowAppUnknownIs404(): void
    {
        [$status, , $body] = $this->decode($this->controller->showApp(
            $this->request('GET', '/apps/none'),
            'none'
        ));

        $this->assertSame(404, $status);
        $this->assertSame('not_found', $body['reason']);
    }

    public function testSpawnFailureMarksJobErrorAndNeverClaimsSuccess(): void
    {
        $this->spawnOk = false;
        // Launcher baru dengan seam yang gagal (controller dibangun ulang).
        $this->controller = new AgentJobController($this->jobs, $this->launcher(), $this->projection);

        [$status, , $body] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'deploy', 'app' => $this->appPayload()]))
        ));

        $this->assertSame(500, $status);
        $this->assertSame(1, $body['code']);
        $this->assertArrayNotHasKey('op', $body, 'respons gagal tidak boleh berisi klaim sukses');

        $record = $this->jobs->find((string) $body['job_id']);
        $this->assertSame('error', $record['status']);
        $this->assertNotSame('', $record['error']);
        $this->assertNotSame('', $record['finished_at']);
    }

    public function testInvalidJsonAndMissingAppIdentityAreRejected(): void
    {
        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', 'bukan-json')));
        $this->assertSame(400, $status);
        $this->assertSame('invalid_json', $body['reason']);

        foreach ([
            ['op' => 'deploy'],
            ['op' => 'deploy', 'app' => ['name' => 'demo']],
            ['op' => 'deploy', 'app' => ['id' => self::APP_ID]],
            ['op' => 'deploy', 'app' => ['id' => '../x', 'name' => 'demo']],
        ] as $payload) {
            [$status, , $body] = $this->decode($this->controller->create(
                $this->request('POST', '/jobs', json_encode($payload))
            ));
            $this->assertSame(400, $status, json_encode($payload) . ' harus ditolak');
            $this->assertSame('invalid_request', $body['reason']);
        }

        $this->assertSame([], $this->spawned);
        $this->assertSame([], $this->jobs->all());
    }

    /**
     * Nama app yang masih dipegang baris **id lain** (sisa app yang sudah
     * dihapus di control plane) TIDAK boleh memblokir job: baris basi itu
     * dibuang dan proyeksi dilanjutkan — kalau tidak, app tidak bisa dibuat
     * maupun dihapus (job `teardown` pun ditolak di proyeksi).
     */
    public function testStaleProjectionWithTheSameNameIsReplacedInsteadOfFailing(): void
    {
        $store = new AppStore($this->tmp . '/rames.sqlite');
        $store->create(['id' => 'lain1234567890ab', 'name' => 'demo']);

        [$status, , $body] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'teardown', 'app' => $this->appPayload()]))
        ));

        $this->assertSame(200, $status, 'baris basi tidak boleh menggagalkan job');
        $this->assertSame(0, $body['code']);
        $this->assertCount(1, $this->spawned);

        $this->assertNull($store->find('lain1234567890ab'), 'baris basi dibuang');
        $projected = $store->find(self::APP_ID);
        $this->assertNotNull($projected);
        $this->assertSame('demo', $projected['name']);
    }

    /**
     * Direktori `apps/<nama>` sisa app lain (id berbeda) di server agent dibuang
     * **sebelum** materialisasi, sehingga create app dengan nama itu tidak lagi
     * ditolak penanda kepemilikan ("penimpaan diam-diam dilarang") — kegagalan
     * setelah record app dibuat di control plane (deploy error).
     */
    public function testStaleAppDirectoryOfADeletedAppIsReclaimedBeforeMaterialize(): void
    {
        mkdir($this->tmp . '/apps/demo', 0777, true);
        file_put_contents(
            $this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE,
            '{"app_id":"app-lama","name":"demo"}'
        );
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  lama:\n    image: nginx:alpine\n");

        [$status, , $body] = $this->decode($this->controller->create($this->request('POST', '/jobs', json_encode([
            'op' => 'deploy',
            'app' => $this->composeAppPayload(),
            'artifacts' => [
                'compose' => "services:\n  web:\n    image: nginx:alpine\n",
                'compose_name' => 'docker-compose.yml',
            ],
        ]))));

        $this->assertSame(200, $status, 'direktori sisa app lama tidak boleh menggagalkan job');
        $this->assertSame(0, $body['code']);
        $this->assertCount(1, $this->spawned);

        $marker = json_decode(
            (string) file_get_contents($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE),
            true
        );
        $this->assertSame(self::APP_ID, $marker['app_id'] ?? null, 'penanda kini milik app ini');
        $this->assertStringContainsString(
            'web:',
            (string) file_get_contents($this->tmp . '/apps/demo/docker-compose.yml')
        );
    }

    /**
     * Gagal proyeksi (mis. store agent rusak: baris tanpa id memegang nama yang
     * sama) tetap dijawab JSON, bukan halaman HTML — API mesin tidak boleh
     * menjatuhkan request menjadi halaman galat.
     */
    public function testProjectionFailureIsAnsweredAsJsonWithoutJobOrSpawn(): void
    {
        (new AppStore($this->tmp . '/rames.sqlite'))->create(['id' => '', 'name' => 'demo']);

        [$status, , $body, $raw] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'deploy', 'app' => $this->appPayload()]))
        ));

        $this->assertSame(500, $status);
        $this->assertSame(1, $body['code']);
        $this->assertSame('projection_failed', $body['reason']);
        $this->assertJson($raw);
        $this->assertSame([], $this->spawned);
        $this->assertSame([], $this->jobs->all());
    }

    // ==================================================================
    // GET /jobs/{id}
    // ==================================================================

    public function testShowReturnsStableJobShapeAnd404ForUnknownId(): void
    {
        $jobId = (string) $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'apply', 'app' => $this->appPayload()]))
        ))[2]['job_id'];

        [$status, , $body] = $this->decode($this->controller->show($this->request('GET', '/jobs/' . $jobId), $jobId));

        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code']);
        $this->assertSame(
            ['id', 'op', 'app_id', 'status', 'stage', 'message', 'error', 'created_at', 'started_at', 'finished_at'],
            array_keys($body['job'])
        );
        $this->assertSame($jobId, $body['job']['id']);
        $this->assertSame('apply', $body['job']['op']);
        $this->assertSame(self::APP_ID, $body['job']['app_id']);
        $this->assertSame('pending', $body['job']['status']);
        $this->assertNotSame('', $body['job']['created_at']);

        [$status, , $body] = $this->decode($this->controller->show($this->request('GET', '/jobs/tidakada'), 'tidakada'));
        $this->assertSame(404, $status);
        $this->assertSame(1, $body['code']);
        $this->assertSame('not_found', $body['reason']);
    }

    // ==================================================================
    // GET /jobs/{id}/log
    // ==================================================================

    public function testLogPollsDeployLogWithOffsetAndEnforces256KiBLimit(): void
    {
        $jobId = (string) $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'deploy', 'app' => $this->appPayload()]))
        ))[2]['job_id'];

        // Worker menulis log deploy sendiri; endpoint job membaca berkas itu.
        $contents = '';
        for ($i = 1; $i <= 12000; $i++) {
            $contents .= '[' . date('c') . '] baris log deploy ' . str_pad((string) $i, 5, '0', STR_PAD_LEFT)
                . ' ' . str_repeat('x', 60) . "\n";
        }
        $this->assertGreaterThan(AgentJobStore::MAX_LOG_READ * 2, strlen($contents));
        file_put_contents($this->logDir . '/' . self::APP_ID . '.log', $contents);

        $collected = '';
        $offset = 0;
        $reads = 0;
        do {
            [$status, $headers, , $raw] = $this->decode(
                $this->controller->log($this->request('GET', '/jobs/' . $jobId . '/log?offset=' . $offset), $jobId)
            );
            $this->assertSame(200, $status);
            $this->assertSame('text/plain; charset=utf-8', $headers['Content-Type'] ?? null);
            $this->assertLessThanOrEqual(AgentJobStore::MAX_LOG_READ, strlen($raw), 'plafon 256 KiB per panggilan');
            if ($raw !== '') {
                $this->assertStringEndsWith("\n", $raw, 'potongan selalu berakhir di batas baris');
            }
            // Header offset lanjutan = offset + panjang potongan (klien polling).
            $this->assertSame((string) ($offset + strlen($raw)), $headers['X-Rames-Offset'] ?? null);

            $collected .= $raw;
            $offset += strlen($raw);
            $reads++;
            $this->assertLessThan(20, $reads, 'polling tidak boleh berputar tanpa kemajuan');
        } while ($raw !== '');

        $this->assertSame($contents, $collected, 'gabungan polling = isi log apa adanya');
        $this->assertGreaterThan(1, $reads);

        // Idle: tidak ada byte baru ⇒ body kosong, offset tidak bergerak.
        [, $headers, , $raw] = $this->decode(
            $this->controller->log($this->request('GET', '/jobs/' . $jobId . '/log?offset=' . $offset), $jobId)
        );
        $this->assertSame('', $raw);
        $this->assertSame((string) $offset, $headers['X-Rames-Offset'] ?? null);
    }

    public function testLogIsEmptyBeforeWorkerWritesAnything(): void
    {
        $jobId = (string) $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'rebuild', 'app' => $this->appPayload()]))
        ))[2]['job_id'];

        [$status, $headers, , $raw] = $this->decode(
            $this->controller->log($this->request('GET', '/jobs/' . $jobId . '/log?offset=99999999'), $jobId)
        );

        $this->assertSame(200, $status);
        $this->assertSame('', $raw);
        $this->assertSame('99999999', $headers['X-Rames-Offset'] ?? null);

        [$status, , $body] = $this->decode($this->controller->log($this->request('GET', '/jobs/tidakada/log'), 'tidakada'));
        $this->assertSame(404, $status);
        $this->assertSame(1, $body['code']);
    }

    // ==================================================================
    // Kontrak lintas controller
    // ==================================================================

    /** `capabilities` = allowlist op yang benar-benar diterima `POST /jobs`. */
    public function testVersionCapabilitiesMatchAcceptedOps(): void
    {
        $response = (new AgentController())->version();
        $body = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(AgentJobController::OPS, $body['capabilities']);
        $this->assertSame(DeployWorkerLauncher::MODES, $body['capabilities']);

        foreach ($body['capabilities'] as $op) {
            if (in_array($op, AgentJobController::SERVER_OPS, true)) {
                // Op tingkat server (F4 lanjutan): `target` menggantikan `app`
                // (tidak ada proyeksi app/artefak).
                $payload = [
                    'op' => $op,
                    'target' => $op === 'template_pull' ? self::TEMPLATE_SLUG : ($op === 'restore_archived' ? 'demo_data' : 'agent'),
                ];
                if ($op === 'self_update') {
                    $payload['update_mode'] = 'update';
                    $payload['control_plane_version'] = AgentVersionGate::CODEBASE_VERSION;
                }
                if ($op === 'restore_archived') {
                    $payload['snapshot'] = 'abcdef12';
                    $payload['target_name'] = 'demo_data_restored';
                }
            } else {
                $payload = [
                    'op' => $op,
                    'app' => $this->appPayload(),
                    // Op SSL (F2) wajib membawa domain — kontrak `SSL_OPS`.
                    'domain' => 'demo.example.test',
                ];
                // Op data F4 (backup/restore/dump) wajib membawa penanda volume
                // (dan id snapshot untuk `restore`) — kontrak `DATA_OPS`.
                if (in_array($op, AgentJobController::DATA_OPS, true)) {
                    $payload['volume'] = 'demo_data';
                    if ($op === 'restore') {
                        $payload['snapshot'] = 'abcdef12';
                    }
                }
            }

            [$status, , $created] = $this->decode($this->controller->create(
                $this->request('POST', '/jobs', json_encode($payload))
            ));
            $this->assertSame(200, $status, "op {$op} dilaporkan sebagai capability ⇒ harus diterima");
            $this->assertSame($op, $created['op']);
        }

        $this->assertCount(count($body['capabilities']), $this->spawned);

        [$status, , $rejected] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode(['op' => 'save_compose', 'app' => $this->appPayload()]))
        ));
        $this->assertSame(501, $status, 'op di luar capabilities wajib ditolak, bukan didiamkan');
        $this->assertSame('unsupported', $rejected['reason']);
    }

    /** Token/PEM/nilai env tidak pernah muncul di respons maupun rekaman job. */
    public function testSecretsNeverLeakIntoResponsesOrJobRecords(): void
    {
        $secret = 's3cr3t-env-value';
        $pem = '-----BEGIN OPENSSH PRIVATE KEY-----fixture-key-material-----END';
        $payload = [
            'op' => 'deploy',
            'app' => $this->appPayload([
                'env' => ['APP_KEY' => $secret, 'DB_PASSWORD' => 'p@ss'],
                'ssh_key' => $pem,
                'auth_method' => 'deploy_key',
            ]),
        ];

        $raws = [];
        [$status, , $body, $raw] = $this->decode($this->controller->create(
            $this->request('POST', '/jobs', json_encode($payload))
        ));
        $raws[] = $raw;
        $raws[] = $this->decode($this->controller->show(
            $this->request('GET', '/jobs/' . (string) $body['job_id']),
            (string) $body['job_id']
        ))[3];
        $raws[] = $this->decode($this->controller->log(
            $this->request('GET', '/jobs/' . (string) $body['job_id'] . '/log'),
            (string) $body['job_id']
        ))[3];

        // Rekaman job (berkas JSON di /tmp) + pemulihannya via controller.
        foreach (glob($this->tmp . '/jobs/*') ?: [] as $file) {
            $raws[] = (string) file_get_contents($file);
        }

        $this->assertSame(200, $status);
        foreach ($raws as $raw) {
            $this->assertStringNotContainsString($secret, $raw, 'nilai env bocor');
            $this->assertStringNotContainsString('p@ss', $raw, 'kredensial bocor');
            $this->assertStringNotContainsString($pem, $raw, 'PEM bocor');
        }
    }

    /** Mediator tipis: dependensi hanya seam uji, tanpa state lintas-request. */
    public function testControllerIsStatelessMediator(): void
    {
        $reflection = new ReflectionClass(AgentJobController::class);

        $this->assertNotNull($reflection->getConstructor());
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isPrivate(), "properti {$property->getName()} harus privat");
            $this->assertTrue(
                $property->getType()?->allowsNull() ?? false,
                "properti {$property->getName()} hanya boleh berisi seam uji (nullable)"
            );
        }

        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $type = (string) $parameter->getType();
            $this->assertStringNotContainsString('Request', $type);
            $this->assertStringNotContainsString('Session', $type);
        }

        $this->assertEqualsCanonicalizing(
            ['__construct', 'create', 'show', 'log', 'cancel', 'showApp', 'renderNginx', 'services', 'checkNginx', 'checkApp', 'backupVolumes', 'backupSnapshots', 'backupDump'],
            get_class_methods(AgentJobController::class)
        );

        $source = (string) file_get_contents(dirname(__DIR__) . '/config/route.php');
        $this->assertStringContainsString("Route::post('/jobs'", $source);
        $this->assertStringContainsString("Route::get('/jobs/{id}'", $source);
        $this->assertStringContainsString("Route::get('/jobs/{id}/log'", $source);
        $this->assertStringContainsString("Route::post('/jobs/{id}/cancel'", $source);
        $this->assertStringContainsString("Route::get('/apps/{id}'", $source);
        $this->assertStringContainsString("Route::get('/apps/{id}/nginx'", $source);
        $this->assertStringContainsString("Route::get('/apps/{id}/services'", $source);
        $this->assertStringContainsString("Route::post('/nginx/check'", $source);
        $this->assertStringContainsString("Route::post('/apps/check'", $source);
    }

    // ==================================================================
    // POST /apps/check (preflight nama app — read-only, bukan job)
    // ==================================================================

    /**
     * Direktori yang aman bagi app ini — `free` (belum ada/kosong), `owned`
     * (milik app ini), `stale` (sisa app yang sudah dihapus) — dijawab
     * `available:true`: create app tidak boleh dihalangi oleh ketiganya.
     */
    public function testCheckAppAnswersAvailableForFreeOwnedAndStaleDirectories(): void
    {
        $app = ['id' => self::APP_ID, 'name' => 'demo'];

        [$status, , $body] = $this->decode($this->controller->checkApp(
            $this->request('POST', '/apps/check', (string) json_encode(['app' => $app]))
        ));
        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code']);
        $this->assertSame('app_check', $body['op']);
        $this->assertTrue($body['available']);
        $this->assertSame('free', $body['state']);
        $this->assertSame('', $body['message']);

        // `owned`: penanda kepemilikan milik app ini sendiri.
        mkdir($this->tmp . '/apps/demo', 0777, true);
        file_put_contents($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE, (string) json_encode([
            'app_id' => self::APP_ID,
            'name' => 'demo',
        ]));
        [, , $body] = $this->decode($this->controller->checkApp(
            $this->request('POST', '/apps/check', (string) json_encode(['app' => $app]))
        ));
        $this->assertTrue($body['available']);
        $this->assertSame('owned', $body['state']);
        $this->assertSame('', $body['message']);

        // `stale`: penanda milik id app lain (sisa app yang sudah dihapus).
        file_put_contents($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE, (string) json_encode([
            'app_id' => 'app-lama',
            'name' => 'demo',
        ]));
        [, , $body] = $this->decode($this->controller->checkApp(
            $this->request('POST', '/apps/check', (string) json_encode(['app' => $app]))
        ));
        $this->assertTrue($body['available']);
        $this->assertSame('stale', $body['state']);
        $this->assertSame('', $body['message']);

        // Read-only mutlak: tanpa spawn, tanpa rekaman job, tanpa mutasi direktori.
        $this->assertSame([], $this->spawned, 'preflight tidak pernah meluncurkan worker');
        $this->assertSame([], $this->jobs->all(), 'preflight bukan job');
        $this->assertFileExists($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE);
    }

    /**
     * Direktori non-kosong tanpa penanda = data tak dikenal ⇒ `available:false`
     * beserta `state`/`message` yang bisa ditindaklanjuti operator (bukan pesan
     * kosong, dan bukan penghapusan diam-diam).
     */
    public function testCheckAppReportsBlockedDirectoryWithStateAndMessage(): void
    {
        mkdir($this->tmp . '/apps/demo', 0777, true);
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  lain:\n    image: nginx:alpine\n");

        [$status, , $body] = $this->decode($this->controller->checkApp(
            $this->request('POST', '/apps/check', (string) json_encode(['app' => ['id' => self::APP_ID, 'name' => 'demo']]))
        ));

        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code']);
        $this->assertFalse($body['available']);
        $this->assertSame('blocked', $body['state']);
        $this->assertNotSame('', (string) $body['message']);
        $this->assertStringContainsString('apps/demo', (string) $body['message']);
        $this->assertStringContainsString('deploy ulang', (string) $body['message']);

        $this->assertFileExists($this->tmp . '/apps/demo/docker-compose.yml', 'data tak dikenal tidak dihapus');
        $this->assertFileDoesNotExist($this->tmp . '/apps/demo/' . AgentArtifactMaterializer::MARKER_FILE);
        $this->assertSame([], $this->jobs->all());
        $this->assertSame([], $this->spawned);
    }

    /** Payload tidak sah ⇒ 400 `invalid_request` tanpa menggemakan isinya. */
    public function testCheckAppRejectsInvalidPayloadWithoutEchoingIt(): void
    {
        $cases = [
            'bukan-json' => ['bukan-json', 'invalid_json'],
            'tanpa app' => [(string) json_encode(['op' => 'deploy']), 'invalid_request'],
            'id kosong' => [(string) json_encode(['app' => ['name' => 'demo']]), 'invalid_request'],
            'id tak sah' => [(string) json_encode(['app' => ['id' => '../etc/passwd', 'name' => 'demo']]), 'invalid_request'],
            'name kosong' => [(string) json_encode(['app' => ['id' => self::APP_ID]]), 'invalid_request'],
            'name spasi' => [(string) json_encode(['app' => ['id' => self::APP_ID, 'name' => '   ']]), 'invalid_request'],
            'name traversal' => [(string) json_encode(['app' => ['id' => self::APP_ID, 'name' => '../demo']]), 'invalid_request'],
        ];

        foreach ($cases as $label => [$payload, $reason]) {
            [$status, , $body, $raw] = $this->decode($this->controller->checkApp(
                $this->request('POST', '/apps/check', $payload)
            ));

            $this->assertSame(400, $status, $label . ' harus ditolak 400');
            $this->assertSame(1, $body['code'], $label);
            $this->assertSame($reason, $body['reason'], $label);
            $this->assertStringNotContainsString('etc/passwd', $raw, $label . ': pesan tidak boleh menggemakan payload');
            $this->assertStringNotContainsString('../demo', $raw, $label . ': pesan tidak boleh menggemakan payload');
        }

        $this->assertSame([], $this->jobs->all());
        $this->assertSame([], $this->spawned);
    }

    /** Path direktori app yang seharusnya bebas tetap tidak berubah karena preflight. */
    public function testCheckAppDoesNotCreateAnythingForAFreeName(): void
    {
        $this->decode($this->controller->checkApp(
            $this->request('POST', '/apps/check', (string) json_encode(['app' => ['id' => self::APP_ID, 'name' => 'demo']]))
        ));

        $this->assertDirectoryDoesNotExist($this->tmp . '/apps/demo', 'preflight tidak boleh membuat direktori app');
        $this->assertSame([], (new AppStore($this->tmp . '/rames.sqlite'))->all(), 'tanpa proyeksi app');
    }

    /**
     * Fix R-2 lewat endpoint: `repo_url` diteruskan ke library, sehingga direktori
     * tanpa penanda yang berisi checkout git **remote sama** dijawab
     * `available:true`/`owned` — bukan `blocked` yang membuat create gagal padahal
     * jalur job akan melanjutkan.
     */
    public function testCheckAppForwardsRepoUrlSoSameRemoteCheckoutIsAvailable(): void
    {
        $dir = $this->tmp . '/apps/demo';
        mkdir($dir . '/.git', 0777, true);
        file_put_contents($dir . '/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        $this->runner->remotes[$dir] = 'https://example.test/app.git';

        [$status, , $body] = $this->decode($this->controller->checkApp($this->request('POST', '/apps/check', (string) json_encode([
            'app' => ['id' => self::APP_ID, 'name' => 'demo', 'repo_url' => 'https://example.test/app.git'],
        ]))));

        $this->assertSame(200, $status);
        $this->assertTrue($body['available']);
        $this->assertSame('owned', $body['state']);
        $this->assertSame([['git', '-C', $dir, 'remote', 'get-url', 'origin']], $this->runner->commands);

        // Remote berbeda ⇒ `blocked` (create harus berhenti di sini).
        $this->runner->remotes[$dir] = 'https://example.test/repo-lain.git';
        [, , $body] = $this->decode($this->controller->checkApp($this->request('POST', '/apps/check', (string) json_encode([
            'app' => ['id' => self::APP_ID, 'name' => 'demo', 'repo_url' => 'https://example.test/app.git'],
        ]))));
        $this->assertFalse($body['available']);
        $this->assertSame('blocked', $body['state']);
        $this->assertStringContainsString('repo-lain', (string) $body['message']);
    }

    /**
     * `repo_url` absurd (di luar plafon) diabaikan — hanya dipakai sebagai
     * pembanding remote, jadi ia tidak boleh menggagalkan create **maupun**
     * memicu proses git.
     */
    public function testCheckAppIgnoresAbsurdRepoUrlWithoutProbingGit(): void
    {
        $dir = $this->tmp . '/apps/demo';
        mkdir($dir . '/.git', 0777, true);
        file_put_contents($dir . '/penting.yml', 'jangan hilang');
        $this->runner->remotes[$dir] = 'https://example.test/app.git';

        [$status, , $body] = $this->decode($this->controller->checkApp($this->request('POST', '/apps/check', (string) json_encode([
            'app' => [
                'id' => self::APP_ID,
                'name' => 'demo',
                'repo_url' => 'https://example.test/' . str_repeat('a', 4096) . '.git',
            ],
        ]))));

        $this->assertSame(200, $status);
        $this->assertFalse($body['available']);
        $this->assertSame('blocked', $body['state']);
        $this->assertSame([], $this->runner->commands, 'URL di luar plafon tidak boleh memicu probe git');
    }

    // ==================================================================
    // POST /jobs/{id}/cancel
    // ==================================================================

    public function testCancelStopsWorkerAndRestoresProjectedAppStatus(): void
    {
        $job = $this->jobs->create('deploy', self::APP_ID, ['app_id' => self::APP_ID]);
        $jobId = (string) $job['id'];
        $this->jobs->update($jobId, static function (array &$record): void {
            $record['status'] = 'running';
            $record['worker_pid'] = 4242;
        });
        $this->projection->upsert($this->appPayload(['status' => 'deploying', 'stage' => 'build']));

        $proc = $this->tmp . '/proc';
        mkdir($proc . '/4242', 0775, true);
        file_put_contents(
            $proc . '/4242/cmdline',
            implode("\0", [PHP_BINARY, dirname(__DIR__) . '/cli/deploy.php', self::APP_ID, 'deploy']) . "\0"
        );

        $kills = [];
        $alive = [4242 => true];
        $canceller = new DeployCanceller(
            new AppStore($this->projection->path()),
            new DeployCancellerFakeDocker(['running']),
            $proc,
            function (int $pid, int $signal) use (&$kills, &$alive): bool {
                $kills[] = [$pid, $signal];
                if ($signal === 0) {
                    return $alive[abs($pid)] ?? false;
                }
                if (in_array($signal, [SIGTERM, SIGKILL], true)) {
                    $alive[abs($pid)] = false;
                }

                return true;
            },
            function (int $pid) use (&$alive): bool {
                return $alive[$pid] ?? false;
            },
            new DeployLog($this->logDir)
        );

        $controller = new AgentJobController(
            $this->jobs,
            $this->launcher(),
            $this->projection,
            $this->materializer(),
            null,
            null,
            $canceller
        );

        [$status, , $body] = $this->decode($controller->cancel(
            $this->request('POST', '/jobs/' . $jobId . '/cancel', ''),
            $jobId
        ));

        $this->assertSame(200, $status);
        $this->assertSame(0, $body['code'] ?? null);
        $this->assertTrue(
            in_array([4242, SIGTERM], $kills, true) || in_array([-4242, SIGTERM], $kills, true),
            'worker harus menerima SIGTERM (langsung atau lewat grup proses)'
        );

        $record = (array) $this->jobs->find($jobId);
        $this->assertSame('error', (string) $record['status']);
        $this->assertSame('cancelled', (string) $record['stage']);
        $this->assertArrayNotHasKey('worker_pid', $record);

        $projected = (array) (new AppStore($this->projection->path()))->find(self::APP_ID);
        $this->assertSame('running', (string) $projected['status'], 'container lama masih hidup');
        $this->assertSame('Deploy dibatalkan.', (string) $projected['message']);
        $this->assertStringContainsString(
            '[cancelled] Deploy dibatalkan',
            (string) file_get_contents($this->logDir . '/' . self::APP_ID . '.log')
        );
    }

    public function testCancelRefusesFinishedJob(): void
    {
        $job = $this->jobs->create('deploy', self::APP_ID);
        $jobId = (string) $job['id'];
        $this->jobs->update($jobId, static function (array &$record): void {
            $record['status'] = 'done';
        });

        $controller = new AgentJobController($this->jobs, $this->launcher(), $this->projection, $this->materializer());

        [$status, , $body] = $this->decode($controller->cancel(
            $this->request('POST', '/jobs/' . $jobId . '/cancel', ''),
            $jobId
        ));

        $this->assertSame(409, $status);
        $this->assertSame('not_running', (string) ($body['reason'] ?? ''));
        $this->assertSame('done', (string) $this->jobs->find($jobId)['status'], 'rekaman final tidak ditimpa');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function launcher(): DeployWorkerLauncher
    {
        return new DeployWorkerLauncher(
            function (array $argv, ?int &$pid = null, array $env = []): bool {
                if (!$this->spawnOk) {
                    return false;
                }
                $this->spawned[] = $argv;
                $this->spawnedEnv[] = $env;
                // Artefak harus sudah ada SEBELUM spawn (urutan yang dikunci).
                $this->artifactAtSpawn[] = is_file($this->tmp . '/apps/demo/docker-compose.yml');
                $pid = 4242;

                return true;
            },
            $this->tmp . '/cli/deploy.php',
            $this->logDir
        );
    }

    /** Materializer dengan seluruh path di `$tmp` (tanpa menyentuh disk nyata). */
    private function materializer(): AgentArtifactMaterializer
    {
        return new AgentArtifactMaterializer(
            $this->tmp . '/apps',
            $this->tmp . '/database',
            $this->tmp . '/database/keys',
            new TemplateCatalog($this->tmp . '/templates'),
            $this->git,
            $this->runner
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
            'repo_url' => 'https://example.test/a.git',
            'branch' => 'main',
            'env' => ['APP_ENV' => 'prod'],
            'status' => 'running',
            'containers' => [['name' => 'demo-web-1']],
        ], $overrides);
    }

    /**
     * App mode compose (paste/upload) — tanpa repo Git.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function composeAppPayload(array $overrides = []): array
    {
        return $this->appPayload(array_merge([
            'source' => 'compose',
            'repo_url' => null,
            'branch' => null,
            'compose_files' => ['docker-compose.yml'],
        ], $overrides));
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
