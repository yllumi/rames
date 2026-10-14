<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentJobStore;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Support\ProcessRunner;
use app\library\Template\TemplateCatalog;
use app\library\Template\TemplatePullState;
use app\library\Update\AgentVersionGate;
use app\library\Update\UpdateService;
use PHPUnit\Framework\TestCase;

/**
 * Op **tingkat server** (F4 lanjutan) di worker `cli/deploy.php`:
 * `template_pull` (tarik image template di server tempat worker berjalan) dan
 * `self_update` (self-update agen lewat helper container — **di agen**, bukan di
 * control plane).
 *
 * Yang dikunci:
 *  1. `template_pull` menjalankan mekanisme pull yang **sama** dengan jalur
 *     lokal (`cli/pull.php`: `TemplateCatalog` + `docker pull` per image) secara
 *     sinkron di server ini, menandai job `done`, dan mencerminkan log pull ke
 *     log job; slug tak dikenal ⇒ fail-fast, job `error`, **tanpa** `docker pull`;
 *  2. `self_update` hanya berlaku di mode runtime `agent` (di dashboard ada jalur
 *     Config → Update) dan menolak control plane yang terlalu tua
 *     ({@see AgentVersionGate::assertControlPlaneCompatibleWithAgent()});
 *  3. update dijalankan oleh runner **di server ini** (`RAMES_SELF_UPDATE_CLASS`
 *     palsu di uji) ⇒ control plane tidak pernah menjalankan update untuk agen.
 *
 * Worker dijalankan sebagai subproses dengan env path temp & runner palsu —
 * tanpa Docker, tanpa jaringan, tanpa helper container nyata.
 */
class CliDeployServerOpsTest extends TestCase
{
    private const SLUG = 'outline';

    private string $root;

    private string $workDir;

    /** @var array<string,string> */
    private array $env;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->workDir = sys_get_temp_dir() . '/rames-cli-srvops-' . bin2hex(random_bytes(6));
        foreach (['database', 'jobs', 'pull', 'update', 'logs'] as $dir) {
            mkdir($this->workDir . '/' . $dir, 0777, true);
        }

        $this->env = [
            'DATABASE_PATH' => $this->workDir . '/database',
            'RAMES_DB_DIR' => $this->workDir . '/database',
            'RAMES_JOB_DIR' => $this->workDir . '/jobs',
            'TEMPLATES_PATH' => $this->root . '/templates',
            'TEMPLATE_PULL_RUN_DIR' => $this->workDir . '/pull',
            'TEMPLATE_PULL_TIMEOUT' => '5',
            'TEMPLATE_PULL_RUNNER_CLASS' => FakeTemplatePullRunner::class,
            'FAKE_TEMPLATE_PULL_CALLS' => $this->workDir . '/pull-calls.json',
            'DOCKER_BINARY' => 'docker-test',
            // Mode runtime `agent` menuntut bind + TLS (`config/process.php`
            // fail-closed) — disiapkan di temp; tidak ada listener yang benar-benar
            // dijalankan oleh worker CLI.
            'RAMES_MODE' => 'agent',
            'AGENT_BIND' => '0.0.0.0:9443',
            'UPDATE_PATH' => $this->workDir,
            'UPDATE_CHECK_FILE' => $this->workDir . '/update/check.json',
            'UPDATE_RUN_DIR' => $this->workDir . '/update/run',
            'RAMES_SELF_UPDATE_CLASS' => FakeSelfUpdateRunner::class,
            'FAKE_SELF_UPDATE_LOG' => $this->workDir . '/self-update-calls.json',
        ];

        [$cert, $key] = $this->makeCertPair();
        $this->env['AGENT_TLS_CERT'] = $cert;
        $this->env['AGENT_TLS_KEY'] = $key;
    }

    /**
     * Pasangan sertifikat/kunci self-signed di temp (prasyarat mode agent di
     * `config/process.php`) — tidak dipakai untuk koneksi apa pun.
     *
     * @return array{0:string,1:string}
     */
    private function makeCertPair(): array
    {
        $certPath = $this->workDir . '/agent.crt.pem';
        $keyPath = $this->workDir . '/agent.key.pem';

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'rames-agent'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 825, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents($certPath, (string) $certPem);
        file_put_contents($keyPath, (string) $keyPem);
        chmod($keyPath, 0600);

        return [$certPath, $keyPath];
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->workDir);
        // Log worker ditulis ke runtime/logs/deploy/{target}.log (runtime_path tidak
        // bisa di-override lewat env) — bersihkan seperti tes CLI yang lain.
        @unlink($this->root . '/runtime/logs/deploy/' . self::SLUG . '.log');
        @unlink($this->root . '/runtime/logs/deploy/' . DeployWorkerLauncher::AGENT_UPDATE_TARGET . '.log');
    }

    // ==================================================================
    // template_pull
    // ==================================================================

    public function testTemplatePullRunsHereAndMarksJobDone(): void
    {
        $job = $this->jobStore()->create('template_pull', self::SLUG, ['target' => self::SLUG]);

        $result = $this->worker(self::SLUG, DeployWorkerLauncher::SERVER_OPS[0], '', ['RAMES_JOB_ID' => (string) $job['id']]);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);

        // Image ditarik di **server ini** lewat mekanisme `cli/pull.php` yang sama
        // (runner palsu menggantikan `docker pull`).
        $images = array_column($this->pullCalls(), 'command');
        $this->assertSame(
            array_map(static fn (string $ref): array => ['docker-test', 'pull', $ref], TemplateCatalog::images(
                (new TemplateCatalog($this->root . '/templates'))->find(self::SLUG) ?? []
            )),
            $images,
            'setiap image template ditarik lewat [docker_binary, pull, <ref>]'
        );

        // State pull server ini (jalur lokal yang sama) + job ditutup.
        $run = (new TemplatePullState($this->workDir . '/pull'))->run();
        $this->assertSame('success', $run['result']);
        $this->assertSame('finished', $run['stage']);

        $record = $this->jobStore()->find((string) $job['id']);
        $this->assertSame('done', $record['status']);
        $this->assertNotSame('', (string) $record['finished_at']);

        // Log job = log deploy `{target}.log` dan memuat keluaran pull (dicerminkan).
        $log = (string) file_get_contents($this->root . '/runtime/logs/deploy/' . self::SLUG . '.log');
        $this->assertStringContainsString('mode=template_pull', $log);
        $this->assertStringContainsString('docker pull ' . TemplateCatalog::images((new TemplateCatalog($this->root . '/templates'))->find(self::SLUG) ?? [])[0], $log);
    }

    public function testUnknownSlugFailsFastWithoutPullingAnything(): void
    {
        $job = $this->jobStore()->create('template_pull', 'tidak-ada', ['target' => 'tidak-ada']);

        $result = $this->worker('tidak-ada', 'template_pull', '', ['RAMES_JOB_ID' => (string) $job['id']]);

        $this->assertSame(1, $result['code']);
        $this->assertFileDoesNotExist($this->env['FAKE_TEMPLATE_PULL_CALLS'], 'slug tak dikenal tidak boleh menarik apa pun');

        $record = $this->jobStore()->find((string) $job['id']);
        $this->assertSame('error', $record['status']);
        $this->assertStringContainsString('tidak ditemukan', strtolower((string) $record['error']));

        @unlink($this->root . '/runtime/logs/deploy/tidak-ada.log');
    }

    // ==================================================================
    // self_update
    // ==================================================================

    public function testSelfUpdateRunsTheUpdaterOnThisServer(): void
    {
        $job = $this->jobStore()->create('self_update', DeployWorkerLauncher::AGENT_UPDATE_TARGET, [
            'target' => DeployWorkerLauncher::AGENT_UPDATE_TARGET,
            'update_mode' => 'update',
            'control_plane_version' => AgentVersionGate::CODEBASE_VERSION,
        ]);

        $result = $this->worker(
            DeployWorkerLauncher::AGENT_UPDATE_TARGET,
            DeployWorkerLauncher::AGENT_UPDATE_OP,
            '',
            ['RAMES_JOB_ID' => (string) $job['id']]
        );

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(
            [['preflight'], ['start', 'update', 'tester']],
            $this->selfUpdateCalls(),
            'update dijalankan runner DI server ini (helper container di sana), bukan di control plane'
        );

        $record = $this->jobStore()->find((string) $job['id']);
        $this->assertSame('done', $record['status']);
        $this->assertStringContainsString('self-update', strtolower((string) $record['message']));
    }

    public function testSelfUpdateRollbackModeIsForwarded(): void
    {
        $job = $this->jobStore()->create('self_update', DeployWorkerLauncher::AGENT_UPDATE_TARGET, [
            'update_mode' => 'rollback',
            'control_plane_version' => AgentVersionGate::CODEBASE_VERSION,
        ]);

        $result = $this->worker(
            DeployWorkerLauncher::AGENT_UPDATE_TARGET,
            DeployWorkerLauncher::AGENT_UPDATE_OP,
            '',
            ['RAMES_JOB_ID' => (string) $job['id']]
        );

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame([['preflight'], ['start', 'rollback', 'tester']], $this->selfUpdateCalls());
    }

    public function testSelfUpdateRefusesOldControlPlane(): void
    {
        $job = $this->jobStore()->create('self_update', DeployWorkerLauncher::AGENT_UPDATE_TARGET, [
            'update_mode' => 'update',
            'control_plane_version' => '0.0.1',
        ]);

        $result = $this->worker(
            DeployWorkerLauncher::AGENT_UPDATE_TARGET,
            DeployWorkerLauncher::AGENT_UPDATE_OP,
            '',
            ['RAMES_JOB_ID' => (string) $job['id']]
        );

        $this->assertSame(1, $result['code']);
        $this->assertSame([], $this->selfUpdateCalls(), 'control plane terlalu tua ⇒ update tidak dijalankan');

        $record = $this->jobStore()->find((string) $job['id']);
        $this->assertSame('error', $record['status']);
        $this->assertStringContainsString('control plane', strtolower((string) $record['error']));
        $this->assertStringContainsString('0.0.1', (string) $record['error']);
    }

    public function testSelfUpdateIsRefusedOutsideAgentMode(): void
    {
        $job = $this->jobStore()->create('self_update', DeployWorkerLauncher::AGENT_UPDATE_TARGET, [
            'update_mode' => 'update',
            'control_plane_version' => AgentVersionGate::CODEBASE_VERSION,
        ]);

        $result = $this->worker(
            DeployWorkerLauncher::AGENT_UPDATE_TARGET,
            DeployWorkerLauncher::AGENT_UPDATE_OP,
            '',
            ['RAMES_JOB_ID' => (string) $job['id'], 'RAMES_MODE' => 'dashboard']
        );

        $this->assertSame(1, $result['code']);
        $this->assertSame([], $this->selfUpdateCalls(), 'dashboard TIDAK boleh di-update lewat op agen');
        $this->assertStringContainsString('Config', $result['stderr'] . (string) $this->jobStore()->find((string) $job['id'])['error']);
    }

    public function testSelfUpdatePreflightFailureIsReported(): void
    {
        $job = $this->jobStore()->create('self_update', DeployWorkerLauncher::AGENT_UPDATE_TARGET, [
            'update_mode' => 'update',
            'control_plane_version' => AgentVersionGate::CODEBASE_VERSION,
        ]);

        $result = $this->worker(
            DeployWorkerLauncher::AGENT_UPDATE_TARGET,
            DeployWorkerLauncher::AGENT_UPDATE_OP,
            '',
            ['RAMES_JOB_ID' => (string) $job['id'], 'FAKE_SELF_UPDATE_FAIL' => '1']
        );

        $this->assertSame(1, $result['code']);
        $this->assertSame([['preflight']], $this->selfUpdateCalls(), 'preflight gagal ⇒ start() tidak dipanggil');
        $this->assertStringContainsString('Prasyarat', (string) $this->jobStore()->find((string) $job['id'])['error']);
    }

    /** Daftar mode self-update = `UpdateService::MODES` (bukan salinan di worker). */
    public function testSelfUpdateModesMirrorTheService(): void
    {
        $this->assertSame(UpdateService::MODES, ['update', 'rollback']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<string,string> $extra
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function worker(string $target, string $mode, string $ref = '', array $extra = []): array
    {
        $argv = [PHP_BINARY, $this->root . '/cli/deploy.php', $target, $mode];
        if ($ref !== '') {
            $argv[] = $ref;
        }
        // Aktor di slot argv[4] (ref selalu terisi lebih dulu) — pola launcher.
        $argv[] = '';
        $argv[] = 'tester';

        $result = (new ProcessRunner())->run($argv, $this->root, 60, array_merge($this->env, $extra));

        return [
            'code' => (int) $result['code'],
            'stdout' => (string) $result['stdout'],
            'stderr' => (string) $result['stderr'],
        ];
    }

    private function jobStore(): AgentJobStore
    {
        return new AgentJobStore($this->workDir . '/jobs');
    }

    /**
     * @return array<int,array{command:array<int,string>,cwd:?string,timeout:int}>
     */
    private function pullCalls(): array
    {
        $path = $this->env['FAKE_TEMPLATE_PULL_CALLS'];
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<int,array<int,string>>
     */
    private function selfUpdateCalls(): array
    {
        $path = $this->env['FAKE_SELF_UPDATE_LOG'];
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
