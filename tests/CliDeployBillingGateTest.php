<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * Test gerbang kredit (pertahanan berlapis) di `cli/deploy.php` — worker harus
 * menolak menjalankan compose bila saldo pemilik (member) kurang, sedangkan
 * admin / billing mati / owner tak ditemukan tetap dilewati.
 *
 * Dijalankan sebagai subproses dengan env override (path temp) sehingga tidak
 * menyentuh `database/*.json` nyata & tidak memakai Docker (FakeCliDeployer).
 */
class CliDeployBillingGateTest extends TestCase
{
    private const APP_ID = 'app-bill';

    private string $root;
    private string $workDir;
    private array $env;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->workDir = sys_get_temp_dir() . '/rames-cli-billing-' . bin2hex(random_bytes(6));
        foreach (['database', 'apps', 'nginx', 'nginx-status'] as $dir) {
            mkdir($this->workDir . '/' . $dir, 0777, true);
        }

        // Owner member (saldo awal 0) + admin bebas.
        file_put_contents($this->workDir . '/database/auth.json', json_encode([
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->env = [
            'DATABASE_PATH' => $this->workDir . '/database',
            'APPS_PATH' => $this->workDir . '/apps',
            'NGINX_CONF_PATH' => $this->workDir . '/nginx',
            'NGINX_ENABLED_PATH' => $this->workDir . '/nginx',
            'NGINX_RELOAD_STATUS_FILE' => $this->workDir . '/nginx-status/last-reload.json',
            'DOCKER_SOCKET' => '/nonexistent/docker.sock',
            'DEPLOYER_CLASS' => FakeCliDeployer::class,
        ];

        $this->writeApp('u2');
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->workDir);
        @unlink($this->root . '/runtime/logs/deploy/' . self::APP_ID . '.log');
    }

    /**
     * @param array<string,array{cpus:float,memory_mb:int}>|null $limits
     */
    private function writeApp(?string $ownerId, array $limits = ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]): void
    {
        file_put_contents($this->workDir . '/database/apps.json', json_encode([
            [
                'id' => self::APP_ID,
                'name' => 'billapp',
                'owner_id' => $ownerId,
                'branch' => 'main',
                'repo_url' => 'https://example.com/repo.git',
                'local_path' => 'apps/billapp',
                'primary_service' => 'web',
                'status' => 'running',
                'auth_method' => 'none',
                'compose_files' => ['docker-compose.yml'],
                'limits' => $limits,
                'containers' => [],
                'deploy_history' => [],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeBalance(string $userId, float $balance): void
    {
        file_put_contents($this->workDir . '/database/billing.json', json_encode([
            'version' => 1,
            'users' => [$userId => ['balance' => $balance, 'updated_at' => '', 'ledger' => []]],
            'usage' => [],
            'orders' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function runApply(array $envExtra = []): array
    {
        return (new ProcessRunner())->run(
            [PHP_BINARY, $this->root . '/cli/deploy.php', self::APP_ID, 'apply'],
            $this->root,
            60,
            $envExtra === [] ? $this->env : array_merge($this->env, $envExtra)
        );
    }

    private function storedApp(): array
    {
        $apps = json_decode((string) file_get_contents($this->workDir . '/database/apps.json'), true);

        return $apps[0];
    }

    private function logContents(): string
    {
        $file = $this->root . '/runtime/logs/deploy/' . self::APP_ID . '.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function testMemberWithoutCreditsIsBlockedBeforeCompose(): void
    {
        // required = (1×100 + 1024/1024×20) × 24 × 30 = 86400 > 0.
        $result = $this->runApply();

        $this->assertSame(1, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);

        $app = $this->storedApp();
        $this->assertSame('error', $app['status']);
        $this->assertNull($app['stage']);
        $this->assertStringContainsString('Saldo kredit', (string) $app['message']);
        $this->assertSame($app['message'], $app['error']);

        // Worker tidak pernah menjalankan deployer (FakeCliDeployer akan menulis
        // status running + history kosong) — bukti: status tetap error.
        $this->assertSame([], $app['containers']);
        $this->assertSame('error', $app['status']);

        $this->assertStringContainsString('BILLING:', $this->logContents());
    }

    public function testMemberWithEnoughCreditsIsAllowed(): void
    {
        $this->writeBalance('u2', 200000.0);

        $result = $this->runApply();

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
    }

    public function testAdminOwnerIsExemptEvenWithZeroBalance(): void
    {
        $this->writeApp('u1');

        $result = $this->runApply();

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
    }

    public function testBillingDisabledSkipsTheGate(): void
    {
        $result = $this->runApply(['BILLING_ENABLED' => '0']);

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
    }

    public function testUnknownOwnerDoesNotLockTheApp(): void
    {
        $this->writeApp('ghost');

        $result = $this->runApply();

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
    }
}
