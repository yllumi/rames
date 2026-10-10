<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Test gerbang kredit (pertahanan berlapis) di `cli/deploy.php` — worker harus
 * menolak menjalankan compose bila saldo pemilik (member) kurang, sedangkan
 * admin / billing mati / owner tak ditemukan tetap dilewati.
 *
 * Dijalankan sebagai subproses dengan env override (path temp) sehingga tidak
 * menyentuh data nyata & tidak memakai Docker (FakeCliDeployer).
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

        // Owner member (saldo awal 0) + admin bebas + member kedua (uji langkah
        // dua subjek: aktor ≠ owner).
        SqliteFixture::users($this->db(), [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            ['id' => 'u3', 'username' => 'member-2', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

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
        SqliteFixture::apps($this->db(), [
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
        ]);
    }

    private function writeBalance(string $userId, float $balance): void
    {
        $this->writeBalances([$userId => $balance]);
    }

    /**
     * Tulis saldo beberapa user sekaligus (uji dua subjek: aktor & owner).
     *
     * @param array<string,float> $balances
     */
    private function writeBalances(array $balances): void
    {
        $users = [];
        foreach ($balances as $id => $balance) {
            $users[(string) $id] = ['balance' => $balance, 'updated_at' => '', 'ledger' => []];
        }

        SqliteFixture::billing($this->db(), ['users' => $users]);
    }

    /**
     * Berkas basis data SQLite temp (store `apps`/`auth`/`billing`) subproses.
     */
    private function db(): string
    {
        return $this->workDir . '/database/rames.sqlite';
    }

    private function runApply(array $envExtra = [], string $actorId = ''): array
    {
        $argv = [PHP_BINARY, $this->root . '/cli/deploy.php', self::APP_ID, 'apply'];
        if ($actorId !== '') {
            // Posisi argv: [3]=ref (kosong untuk non-rollback), [4]=aktor.
            $argv[] = '';
            $argv[] = $actorId;
        }

        return (new ProcessRunner())->run(
            $argv,
            $this->root,
            60,
            $envExtra === [] ? $this->env : array_merge($this->env, $envExtra)
        );
    }

    private function storedApp(): array
    {
        $apps = SqliteFixture::readAll($this->db(), 'apps');

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

    /**
     * Aktor admin (argv[4]) + owner member saldo 0 ⇒ worker **tidak** memblokir
     * kredit — gerbang dilewati karena aktor exempt, selaras dengan controller
     * (kebijakan "admin bebas deploy tanpa kredit"). Dibedakan tegas dari blokir
     * kredit: exit 0, status `running`, dan **tidak ada** `BILLING:` di log.
     */
    public function testAdminActorIsNotBlockedOnMemberOwnedApp(): void
    {
        $result = $this->runApply([], 'u1');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
        $this->assertStringNotContainsString('BILLING:', $this->logContents());
    }

    /**
     * Tanpa aktor (argv[4] kosong) + owner member saldo 0 ⇒ tetap diblokir
     * (regresi perilaku lama untuk pemanggil yang belum mengirim aktor).
     */
    public function testMissingActorStillBlocksMemberOwnerWithoutCredits(): void
    {
        $result = $this->runApply();

        $this->assertSame(1, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $app = $this->storedApp();
        $this->assertSame('error', $app['status']);
        $this->assertStringContainsString('Saldo kredit', (string) $app['message']);
        $this->assertStringContainsString('BILLING:', $this->logContents());
    }

    /** Owner admin + aktor member ⇒ app milik admin bebas kredit untuk siapa pun. */
    public function testMemberActorIsNotBlockedOnAdminOwnedApp(): void
    {
        $this->writeApp('u1');

        $result = $this->runApply([], 'u2');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
        $this->assertStringNotContainsString('BILLING:', $this->logContents());
    }

    /**
     * Aktor **admin legacy tanpa field `role`** (bentuk data nyata: user pertama
     * `{id,username,password_hash,created_at}`) + owner member saldo 0 ⇒ tidak
     * diblokir — resolusi role lewat `UserStore::isAdmin()`.
     */
    public function testLegacyAdminActorWithoutRoleIsNotBlocked(): void
    {
        SqliteFixture::users($this->db(), [
            ['id' => 'legacy-admin', 'username' => 'admin', 'password_hash' => 'x', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'created_at' => ''],
        ]);

        $result = $this->runApply([], 'legacy-admin');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
        $this->assertStringNotContainsString('BILLING:', $this->logContents());
    }

    public function testAdminOwnerIsExemptEvenWithZeroBalance(): void
    {
        $this->writeApp('u1');

        $result = $this->runApply();

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
    }

    // ------------------------------------------------------------------
    // Paritas dua subjek (aktor lalu pembayar/owner) dengan controller
    // `AppController::billingAssertCanStart()`.
    // ------------------------------------------------------------------

    /**
     * Asimetri yang diperbaiki verifikator: aktor **member saldo 0** + owner
     * **member bersaldo** ⇒ worker wajib **DIBLOKIR** (controller menilai aktor
     * lebih dulu). Owner kaya bukan alasan membiarkan aktor member tanpa saldo.
     */
    public function testMemberActorWithoutCreditsIsBlockedEvenWhenOwnerIsRich(): void
    {
        $this->writeApp('u2');            // owner kaya
        $this->writeBalances(['u2' => 200000.0]);

        $result = $this->runApply([], 'u3'); // aktor member saldo 0

        $this->assertSame(1, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $app = $this->storedApp();
        $this->assertSame('error', $app['status']);
        $this->assertStringContainsString('Saldo kredit', (string) $app['message']);
        $this->assertStringContainsString('BILLING:', $this->logContents());
    }

    /** Aktor member bersaldo + owner member bersaldo ⇒ lolos (kedua subjek aman). */
    public function testRichMemberActorAndRichOwnerAreAllowed(): void
    {
        $this->writeApp('u2');
        $this->writeBalances(['u2' => 200000.0, 'u3' => 200000.0]);

        $result = $this->runApply([], 'u3');

        $this->assertSame(0, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('running', $this->storedApp()['status']);
        $this->assertStringNotContainsString('BILLING:', $this->logContents());
    }

    /**
     * Aktor member saldo 0 + owner **tak diketahui** (`ghost`) ⇒ diblokir
     * (owner tak diketahui jatuh ke aktor, selaras `billingPayerFor()` controller).
     */
    public function testMemberActorWithoutCreditsIsBlockedWhenOwnerUnknown(): void
    {
        $this->writeApp('ghost');

        $result = $this->runApply([], 'u2');

        $this->assertSame(1, $result['code'], 'stderr: ' . $result['stderr'] . 'stdout: ' . $result['stdout']);
        $this->assertSame('error', $this->storedApp()['status']);
        $this->assertStringContainsString('BILLING:', $this->logContents());
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
