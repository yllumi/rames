<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentJobStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Test job-awareness & mode baru `cli/deploy.php` (misi `ms-f1-deploy`).
 *
 * Kontrak yang dikunci (plan §11 catatan 6):
 *  1. `RAMES_JOB_ID` **kosong** (jalur control plane/manual) ⇒ worker tidak
 *     menyentuh `AgentJobStore` sama sekali — rekaman job yang ada tetap
 *     `pending`, dan tidak ada berkas job baru yang dibuat;
 *  2. `RAMES_JOB_ID` terisi (worker di-spawn `AgentJobController`) ⇒ job ditandai
 *     `running` di awal dan `done` (atau `error` + pesan) di akhir;
 *  3. mode baru `stop|start|teardown|apply_env` memanggil method `LocalDeployer`
 *     yang sama, dan `teardown` memakai `preserve_volumes` dari **payload job**
 *     (satu-satunya jalur yang bisa membawa daftar volume yang dipertahankan).
 *
 * Worker dijalankan sebagai subproses dengan env path temp (`DATABASE_PATH`,
 * `RAMES_DB_DIR`, `APPS_PATH`, `RAMES_JOB_DIR`, …) dan deployer tiruan
 * (`FakeCliDeployer`) — tanpa Docker, tanpa data store nyata.
 */
class CliDeployJobAwarenessTest extends TestCase
{
    private const APP_ID = 'app-job-1';

    private string $root;

    private string $workDir;

    /** @var array<string,string> */
    private array $env;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->workDir = sys_get_temp_dir() . '/rames-cli-job-' . bin2hex(random_bytes(6));
        foreach (['database', 'apps', 'nginx', 'nginx-status', 'jobs'] as $dir) {
            mkdir($this->workDir . '/' . $dir, 0777, true);
        }

        $this->env = [
            'DATABASE_PATH' => $this->workDir . '/database',
            'RAMES_DB_DIR' => $this->workDir . '/database',
            'APPS_PATH' => $this->workDir . '/apps',
            'NGINX_CONF_PATH' => $this->workDir . '/nginx',
            'NGINX_ENABLED_PATH' => $this->workDir . '/nginx',
            'NGINX_RELOAD_STATUS_FILE' => $this->workDir . '/nginx-status/last-reload.json',
            // Reload Nginx dijalankan lewat helper container (`docker run …`).
            // Di uji: image & binary sengaja tidak ada supaya docker CLI gagal
            // cepat TANPA menjalankan `nginx -t`/reload host nyata (dan tanpa
            // menarik image dari jaringan).
            'NGINX_RELOAD_IMAGE' => 'rames-test.invalid/no-such-image:0',
            'NGINX_BIN' => '/nonexistent/nginx',
            'DOCKER_SOCKET' => '/nonexistent/docker.sock',
            'DEPLOYER_CLASS' => FakeCliDeployer::class,
            'RAMES_JOB_DIR' => $this->jobDir(),
            'FAKE_DEPLOYER_LOG' => $this->workDir . '/fake-deployer.log',
        ];

        SqliteFixture::apps($this->db(), [[
            'id' => self::APP_ID,
            'name' => 'jobapp',
            'branch' => 'main',
            'repo_url' => 'https://example.com/repo.git',
            'local_path' => 'apps/jobapp',
            'primary_service' => 'web',
            'status' => 'running',
            'auth_method' => 'none',
            'compose_files' => ['docker-compose.yml'],
            'containers' => [],
            'deploy_history' => [],
        ]]);
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->workDir);
        // Log worker ditulis ke runtime/logs/deploy/{appId}.log (runtime_path tidak
        // bisa di-override lewat env) — bersihkan seperti tes CLI yang lain.
        @unlink($this->root . '/runtime/logs/deploy/' . self::APP_ID . '.log');
    }

    // ==================================================================
    // Job-awareness
    // ==================================================================

    public function testWorkerWithoutJobIdNeverTouchesTheJobStore(): void
    {
        $jobs = $this->jobStore();
        $record = $jobs->create('deploy', self::APP_ID);

        $result = $this->worker('deploy');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(
            'pending',
            (string) $this->jobStore()->find((string) $record['id'])['status'],
            'tanpa RAMES_JOB_ID worker tidak boleh menandai job (kontrol plane bukan bagian job agent)'
        );
        $this->assertCount(1, glob($this->jobDir() . '/*.json') ?: [], 'tidak boleh ada rekaman job baru');
    }

    public function testWorkerMarksJobRunningThenDone(): void
    {
        $record = $this->jobStore()->create('deploy', self::APP_ID);
        $jobId = (string) $record['id'];

        $result = $this->worker('deploy', self::APP_ID, '', ['RAMES_JOB_ID' => $jobId]);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);

        $job = (array) $this->jobStore()->find($jobId);
        $this->assertSame('done', (string) $job['status']);
        $this->assertNotSame('', (string) $job['started_at']);
        $this->assertNotSame('', (string) $job['finished_at']);
        $this->assertSame('', (string) $job['error']);

        // Deploy tetap tersimpan di store app (jalur lama tidak berubah).
        $this->assertSame('running', (string) $this->storedApp()['status']);
    }

    public function testWorkerMarksJobErrorOnDeployFailure(): void
    {
        $record = $this->jobStore()->create('rollback', self::APP_ID, ['ref' => str_repeat('a', 40)]);
        $jobId = (string) $record['id'];

        $result = $this->worker('rollback', self::APP_ID, str_repeat('a', 40), [
            'RAMES_JOB_ID' => $jobId,
            'FAKE_DEPLOYER_FAIL' => '1',
        ]);

        $this->assertSame(1, $result['code']);

        $job = (array) $this->jobStore()->find($jobId);
        $this->assertSame('error', (string) $job['status']);
        $this->assertStringContainsString('fake deployer gagal', (string) $job['error']);
        $this->assertNotSame('', (string) $job['finished_at']);
        $this->assertSame('error', (string) $this->storedApp()['status']);
    }

    public function testWorkerMarksJobErrorWhenAppIsUnknown(): void
    {
        $record = $this->jobStore()->create('deploy', 'app-hantu');
        $jobId = (string) $record['id'];

        $result = $this->worker('deploy', 'app-hantu', '', ['RAMES_JOB_ID' => $jobId]);

        $this->assertSame(1, $result['code']);

        $job = (array) $this->jobStore()->find($jobId);
        $this->assertSame('error', (string) $job['status']);
        $this->assertStringContainsString('tidak ditemukan', (string) $job['error']);
    }

    // ==================================================================
    // Mode baru
    // ==================================================================

    public function testStopModeStopsAppAndMarksStatusStopped(): void
    {
        $result = $this->worker('stop');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(['stop'], $this->notes());
        $this->assertSame('stopped', (string) $this->storedApp()['status']);
    }

    public function testStartModeStartsAppAndMarksStatusRunning(): void
    {
        $result = $this->worker('start');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(['start'], $this->notes());
        $this->assertSame('running', (string) $this->storedApp()['status']);
    }

    public function testApplyEnvModeCallsApplyEnv(): void
    {
        $result = $this->worker('apply_env');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(['apply-env'], $this->notes());
        $this->assertSame('running', (string) $this->storedApp()['status']);
    }

    public function testTeardownModeUsesPreserveVolumesFromJobPayload(): void
    {
        $record = $this->jobStore()->create('teardown', self::APP_ID, [
            'app_id' => self::APP_ID,
            'preserve_volumes' => ['vol_data', 'vol_uploads'],
        ]);

        $result = $this->worker('teardown', self::APP_ID, '', ['RAMES_JOB_ID' => (string) $record['id']]);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(['teardown:["vol_data","vol_uploads"]'], $this->notes());
        $this->assertSame('done', (string) $this->jobStore()->find((string) $record['id'])['status']);
    }

    public function testTeardownWithoutJobPayloadIsPurge(): void
    {
        $result = $this->worker('teardown');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame(['teardown:null'], $this->notes(), 'tanpa payload job: hapus semua volume (down -v)');
    }

    public function testUnknownModeIsRejectedWithUsage(): void
    {
        $result = $this->worker('self_update');

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Usage:', $result['stderr']);
        $this->assertSame([], $this->notes());
    }

    // ==================================================================
    // Mode Nginx & SSL (multi-server F2) — dijalankan di server tempat worker
    // ini berjalan, tanpa menyentuh container (tidak melewati gerbang kredit)
    // ==================================================================

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function nginxModeCases(): array
    {
        return [
            'tulis' => ['nginx_write', 'nginx-write'],
            'hapus' => ['nginx_remove', 'nginx-remove'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nginxModeCases')]
    public function testNginxModesCallTheDeployerOfThisServer(string $mode, string $expectedNote): void
    {
        $result = $this->worker($mode);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame([$expectedNote], $this->notes(), 'config Nginx ditulis oleh library server ini');
    }

    public function testNginxReloadModeFailsWhenTheServerReloadFails(): void
    {
        $record = $this->jobStore()->create('nginx_reload', self::APP_ID, ['app_id' => self::APP_ID]);

        $result = $this->worker('nginx_reload', self::APP_ID, '', ['RAMES_JOB_ID' => (string) $record['id']]);

        // Reload memakai helper container; di uji image-nya tidak ada ⇒ gagal.
        // Mode `nginx_reload` memang *adalah* reload itu, jadi kegagalannya harus
        // terlihat sebagai error job (bukan diam-diam sukses).
        $job = (array) $this->jobStore()->find((string) $record['id']);
        $this->assertSame(1, $result['code'], 'stderr: ' . $result['stderr']);
        $this->assertSame('error', (string) $job['status']);
        $this->assertStringContainsString('Reload Nginx', (string) $job['error']);
        $this->assertSame([], $this->notes(), 'mode reload tidak menulis config');
    }

    /**
     * Mode tulis/hapus tetap sukses walau reload gagal: reload Nginx bersifat
     * best-effort & non-fatal (aturan repo #10) — config sudah ditulis di server
     * yang benar, kegagalan reload hanya dicatat.
     */
    public function testNginxWriteModeIsNotFailedByAReloadError(): void
    {
        $record = $this->jobStore()->create('nginx_write', self::APP_ID, ['app_id' => self::APP_ID]);

        $result = $this->worker('nginx_write', self::APP_ID, '', ['RAMES_JOB_ID' => (string) $record['id']]);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        $job = (array) $this->jobStore()->find((string) $record['id']);
        $this->assertSame('done', (string) $job['status']);
        $this->assertSame(['nginx-write'], $this->notes());
    }

    public function testSslIssueWithoutDomainFailsFastBeforeCertbot(): void
    {
        $record = $this->jobStore()->create('ssl_issue', self::APP_ID, ['app_id' => self::APP_ID]);

        $result = $this->worker('ssl_issue', self::APP_ID, '', ['RAMES_JOB_ID' => (string) $record['id']]);

        $this->assertSame(1, $result['code']);

        $job = (array) $this->jobStore()->find((string) $record['id']);
        $this->assertSame('error', (string) $job['status']);
        $this->assertStringContainsString('tanpa `domain`', (string) $job['error']);
        $this->assertSame([], $this->notes(), 'tidak ada config Nginx yang ditulis');
    }

    public function testSslIssueRejectsDomainThatDoesNotBelongToTheApp(): void
    {
        $record = $this->jobStore()->create('ssl_issue', self::APP_ID, [
            'app_id' => self::APP_ID,
            'domain' => 'domain-orang-lain.example.com',
        ]);

        $result = $this->worker('ssl_issue', self::APP_ID, '', ['RAMES_JOB_ID' => (string) $record['id']]);

        $this->assertSame(1, $result['code']);

        $job = (array) $this->jobStore()->find((string) $record['id']);
        $this->assertStringContainsString('bukan subdomain/custom domain', (string) $job['error']);
        $this->assertSame([], $this->notes(), 'certbot tidak boleh sampai berjalan');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Jalankan worker sebagai subproses.
     *
     * @param string               $mode  mode `cli/deploy.php`
     * @param string               $appId id app (`argv[1]`)
     * @param string               $ref   ref rollback (`argv[3]`, '' = kosong)
     * @param array<string,string> $extra env tambahan (`RAMES_JOB_ID`, …)
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function worker(string $mode, string $appId = self::APP_ID, string $ref = '', array $extra = []): array
    {
        $argv = [PHP_BINARY, $this->root . '/cli/deploy.php', $appId, $mode];
        if ($ref !== '') {
            $argv[] = $ref;
        }

        $result = (new ProcessRunner())->run($argv, $this->root, 60, $this->env + $extra);

        return [
            'code' => (int) $result['code'],
            'stdout' => (string) $result['stdout'],
            'stderr' => (string) $result['stderr'],
        ];
    }

    private function jobDir(): string
    {
        return $this->workDir . '/jobs';
    }

    private function jobStore(): AgentJobStore
    {
        return new AgentJobStore($this->jobDir());
    }

    private function db(): string
    {
        return $this->workDir . '/database/rames.sqlite';
    }

    /**
     * @return array<string,mixed>
     */
    private function storedApp(): array
    {
        $apps = SqliteFixture::readAll($this->db(), 'apps');

        return (array) ($apps[0] ?? []);
    }

    /**
     * Jejak pemanggilan deployer (berkas `FAKE_DEPLOYER_LOG`).
     *
     * @return array<int,string>
     */
    private function notes(): array
    {
        $path = $this->env['FAKE_DEPLOYER_LOG'];
        if (!is_file($path)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($path)), static fn (string $l): bool => $l !== ''));
    }
}
