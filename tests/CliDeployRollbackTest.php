<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Test end-to-end cli/deploy.php mode rollback dengan fake deployer
 * (tanpa daemon Docker) — verifikasi:
 *   - dispatch argumen (appId, mode, ref)
 *   - persistensi status + deploy_history ke store `apps` (SQLite)
 *   - jalur error (status app = error)
 *   - ref wajib untuk mode rollback
 *
 * Worker dijalankan sebagai subproses dengan env override agar tidak menyentuh
 * data & direktori produksi (DATABASE_PATH, RAMES_DB_DIR, APPS_PATH, dst).
 */
class CliDeployRollbackTest extends TestCase
{
    private string $root;
    private string $workDir;
    private array $env;
    private string $ref;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->workDir = sys_get_temp_dir() . '/rames-cli-' . bin2hex(random_bytes(4));
        foreach (['database', 'apps', 'nginx', 'nginx-status'] as $d) {
            mkdir($this->workDir . '/' . $d, 0777, true);
        }
        $this->ref = 'a' . str_repeat('0', 39); // SHA-1 40 hex
        $this->env = [
            'DATABASE_PATH' => $this->workDir . '/database',
            'RAMES_DB_DIR' => $this->workDir . '/database',
            'APPS_PATH' => $this->workDir . '/apps',
            'NGINX_CONF_PATH' => $this->workDir . '/nginx',
            'NGINX_ENABLED_PATH' => $this->workDir . '/nginx',
            'NGINX_RELOAD_STATUS_FILE' => $this->workDir . '/nginx-status/last-reload.json',
            'DOCKER_SOCKET' => '/nonexistent/docker.sock',
            'DEPLOYER_CLASS' => FakeCliDeployer::class,
        ];

        SqliteFixture::apps($this->db(), [
            [
                'id' => 'app-1',
                'name' => 'myapp',
                'branch' => 'main',
                'repo_url' => 'https://example.com/repo.git',
                'local_path' => 'apps/myapp',
                'primary_service' => 'web',
                'status' => 'running',
                'auth_method' => 'none',
                'compose_files' => ['docker-compose.yml'],
                'containers' => [],
                'deploy_history' => [],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->workDir);
    }

    /**
     * Berkas basis data SQLite temp untuk store `apps` milik subproses.
     */
    private function db(): string
    {
        return $this->workDir . '/database/rames.sqlite';
    }

    /**
     * Isi store `apps` setelah worker dijalankan.
     *
     * @return array<int,array>
     */
    private function storedApps(): array
    {
        return SqliteFixture::readAll($this->db(), 'apps');
    }

    public function testRollbackModePersistsStatusAndHistory(): void
    {
        $result = (new ProcessRunner())->run(
            [PHP_BINARY, $this->root . '/cli/deploy.php', 'app-1', 'rollback', $this->ref],
            $this->root,
            60,
            $this->env
        );

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);

        $app = $this->storedApps()[0];
        $this->assertSame('running', $app['status']);
        $this->assertCount(1, $app['deploy_history']);
        $this->assertSame($this->ref, $app['deploy_history'][0]['sha']);
        $this->assertSame('rollback', $app['deploy_history'][0]['action']);
        $this->assertSame('success', $app['deploy_history'][0]['status']);
    }

    public function testRollbackErrorSetsAppErrorStatus(): void
    {
        $env = $this->env;
        $env['FAKE_DEPLOYER_FAIL'] = '1';

        $result = (new ProcessRunner())->run(
            [PHP_BINARY, $this->root . '/cli/deploy.php', 'app-1', 'rollback', $this->ref],
            $this->root,
            60,
            $env
        );

        $this->assertSame(1, $result['code']);

        $apps = $this->storedApps();
        $this->assertSame('error', $apps[0]['status']);
        $this->assertStringContainsString('fake deployer gagal (test)', (string) $apps[0]['error']);
    }

    public function testMissingRefForRollbackIsRejected(): void
    {
        $result = (new ProcessRunner())->run(
            [PHP_BINARY, $this->root . '/cli/deploy.php', 'app-1', 'rollback'],
            $this->root,
            60,
            $this->env
        );

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Usage:', $result['stderr']);
    }
}
