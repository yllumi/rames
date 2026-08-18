<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Test end-to-end cli/deploy.php mode rollback dengan fake deployer
 * (tanpa daemon Docker) — verifikasi:
 *   - dispatch argumen (siteId, mode, ref)
 *   - persistensi status + deploy_history ke sites.json
 *   - jalur error (status site = error)
 *   - ref wajib untuk mode rollback
 *
 * Worker dijalankan sebagai subproses dengan env override agar tidak menyentuh
 * database/ & direktori produksi (DATABASE_PATH, SITES_PATH, dst).
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
        foreach (['database', 'sites', 'nginx', 'nginx-status'] as $d) {
            mkdir($this->workDir . '/' . $d, 0777, true);
        }
        $this->ref = 'a' . str_repeat('0', 39); // SHA-1 40 hex
        $this->env = [
            'DATABASE_PATH' => $this->workDir . '/database',
            'SITES_PATH' => $this->workDir . '/sites',
            'NGINX_CONF_PATH' => $this->workDir . '/nginx',
            'NGINX_ENABLED_PATH' => $this->workDir . '/nginx',
            'NGINX_RELOAD_STATUS_FILE' => $this->workDir . '/nginx-status/last-reload.json',
            'DOCKER_SOCKET' => '/nonexistent/docker.sock',
            'DEPLOYER_CLASS' => FakeCliDeployer::class,
        ];

        file_put_contents($this->workDir . '/database/sites.json', json_encode([
            [
                'id' => 'site-1',
                'name' => 'myapp',
                'branch' => 'main',
                'repo_url' => 'https://example.com/repo.git',
                'local_path' => 'sites/myapp',
                'primary_service' => 'web',
                'status' => 'running',
                'auth_method' => 'none',
                'compose_files' => ['docker-compose.yml'],
                'containers' => [],
                'deploy_history' => [],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->workDir);
    }

    public function testRollbackModePersistsStatusAndHistory(): void
    {
        $result = (new ProcessRunner())->run(
            [PHP_BINARY, $this->root . '/cli/deploy.php', 'site-1', 'rollback', $this->ref],
            $this->root,
            60,
            $this->env
        );

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);

        $sites = json_decode((string) file_get_contents($this->workDir . '/database/sites.json'), true);
        $site = $sites[0];
        $this->assertSame('running', $site['status']);
        $this->assertCount(1, $site['deploy_history']);
        $this->assertSame($this->ref, $site['deploy_history'][0]['sha']);
        $this->assertSame('rollback', $site['deploy_history'][0]['action']);
        $this->assertSame('success', $site['deploy_history'][0]['status']);
    }

    public function testRollbackErrorSetsSiteErrorStatus(): void
    {
        $env = $this->env;
        $env['FAKE_DEPLOYER_FAIL'] = '1';

        $result = (new ProcessRunner())->run(
            [PHP_BINARY, $this->root . '/cli/deploy.php', 'site-1', 'rollback', $this->ref],
            $this->root,
            60,
            $env
        );

        $this->assertSame(1, $result['code']);

        $sites = json_decode((string) file_get_contents($this->workDir . '/database/sites.json'), true);
        $this->assertSame('error', $sites[0]['status']);
        $this->assertStringContainsString('fake deployer gagal (test)', (string) $sites[0]['error']);
    }

    public function testMissingRefForRollbackIsRejected(): void
    {
        $result = (new ProcessRunner())->run(
            [PHP_BINARY, $this->root . '/cli/deploy.php', 'site-1', 'rollback'],
            $this->root,
            60,
            $this->env
        );

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Usage:', $result['stderr']);
    }
}
