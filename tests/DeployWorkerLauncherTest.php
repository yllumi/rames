<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentJobController;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Support\SigchldGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Helper webman `base_path()`/`runtime_path()` membaca konstanta BASE_PATH —
// definisikan seperti test lain agar kelas ini bisa dijalankan di luar runtime.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * `DeployWorkerLauncher` (misi F1 multi-server) — ekstraksi spawn worker
 * `cli/deploy.php` dari `AppController::spawnWorker()` agar dipakai bersama
 * dashboard **dan** `AgentJobController`.
 *
 * Yang dikunci:
 *  1. bentuk argv **persis** seperti kode lama (posisi `appId`/`mode` + slot
 *     `ref` selalu ada sebelum aktor — dibaca `cli/deploy.php` sebagai `argv[1..4]`);
 *  2. daftar mode = mode `cli/deploy.php` yang benar-benar ada (dibaca dari
 *     berkasnya, bukan disalin) dan sama dengan `AgentJobController::OPS`;
 *  3. mode tak dikenal / appId kosong ⇒ **tidak** diluncurkan (tanpa proses mubazir);
 *  4. SIGCHLD dijaga `SigchldGuard`: `SIG_IGN` (fire-and-forget) dilonggarkan
 *     selama fork supaya anak mewarisi disposisi normal, lalu dipulihkan;
 *  5. `AppController::spawnWorker()` hanya mendelegasi (tidak ada salinan kedua
 *     mekanisme spawn di controller).
 *
 * Semua uji memakai direktori `/tmp` dan seam spawn — **tidak pernah** membuat
 * proses nyata (`pcntl_fork` sungguhan) maupun menyentuh `runtime/` nyata.
 */
class DeployWorkerLauncherTest extends TestCase
{
    private string $tmp = '';

    /** @var array<int,array<int,string>> argv tiap panggilan spawn */
    private array $spawned = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_deploylauncher_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }
        $this->spawned = [];
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->tmp . '/logs/deploy',
            $this->tmp . '/logs',
            $this->tmp,
        ] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    is_dir($file) || @unlink($file);
                }
                @rmdir($dir);
            }
        }
    }

    /**
     * Launcher dengan seam spawn palsu (mencatat argv, tidak pernah membuat
     * proses nyata) + worker script & direktori log di dalam temp.
     */
    private function launcher(bool $spawnOk = true): DeployWorkerLauncher
    {
        return new DeployWorkerLauncher(
            function (array $argv, ?int &$pid = null) use ($spawnOk): bool {
                $this->spawned[] = $argv;
                $pid = 4242;

                return $spawnOk;
            },
            $this->tmp . '/cli/deploy.php',
            $this->tmp . '/logs/deploy'
        );
    }

    public function testLaunchBuildsLegacyArgvWithoutRefOrActor(): void    {
        $launcher = $this->launcher();

        $this->assertTrue($launcher->launch('app1', 'rebuild'));

        $this->assertSame([PHP_BINARY, $this->tmp . '/cli/deploy.php', 'app1', 'rebuild'], $this->spawned[0]);
        $this->assertDirectoryExists($launcher->logDir(), 'launcher menyiapkan direktori log deploy');
        $this->assertSame($this->tmp . '/logs/deploy/app1.log', $launcher->logPath('app1'));
    }

    public function testRefSlotIsAlwaysFilledBeforeActor(): void
    {
        $launcher = $this->launcher();
        $launcher->launch('app1', 'deploy', null, 'user-7');
        $launcher->launch('app2', 'rollback', 'abc1234', 'user-7');

        // argv[3] (`ref`) tidak bergeser walau ref kosong → argv[4] selalu aktor.
        $this->assertSame([PHP_BINARY, $this->tmp . '/cli/deploy.php', 'app1', 'deploy', '', 'user-7'], $this->spawned[0]);
        $this->assertSame([PHP_BINARY, $this->tmp . '/cli/deploy.php', 'app2', 'rollback', 'abc1234', 'user-7'], $this->spawned[1]);
    }

    public function testUnknownModeOrAppIsNeverLaunched(): void
    {
        $launcher = $this->launcher();

        foreach ([
            ['app1', 'save_compose'],                // op plan §4.1 yang belum ada di cli/deploy.php
            ['app1', 'deploy_env'],
            ['app1', '  '],
            ['', 'deploy'],
            ['   ', 'rebuild'],
        ] as [$appId, $mode]) {
            $this->assertFalse($launcher->launch($appId, $mode), "({$appId}/{$mode}) tidak boleh diluncurkan");
        }

        $this->assertSame([], $this->spawned, 'spawn tidak boleh dipanggil sama sekali');
    }

    public function testModesMirrorCliDeployUsage(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/cli/deploy.php');
        $this->assertSame(
            1,
            preg_match('/in_array\(\$mode,\s*\[(?<modes>[^\]]*)\]/', $source, $match),
            'daftar mode cli/deploy.php tidak ditemukan — perbarui test ini bila bentuknya berubah'
        );
        preg_match_all("/'([a-z_]+)'/", (string) $match['modes'], $modes);

        $this->assertSame($modes[1], DeployWorkerLauncher::MODES, 'MODES wajib mengikuti cli/deploy.php apa adanya');
        $this->assertSame(DeployWorkerLauncher::MODES, AgentJobController::OPS, 'allowlist op agent = mode worker');
    }

    public function testLaunchAcceptsEveryDeclaredMode(): void
    {
        $launcher = $this->launcher();

        foreach (DeployWorkerLauncher::MODES as $mode) {
            $this->assertTrue($launcher->launch('app1', $mode), "mode {$mode} (dari cli/deploy.php) harus diterima");
        }

        $this->assertCount(count(DeployWorkerLauncher::MODES), $this->spawned);
    }

    public function testSpawnFailureIsReportedAsFalse(): void
    {
        $launcher = $this->launcher(false);

        $this->assertFalse($launcher->launch('app1', 'deploy'), 'fork gagal ⇒ false (pemanggil menandai app/job error)');
    }

    /**
     * Bukti `SigchldGuard` benar-benar dipakai: di dalam fork SIGCHLD tidak lagi
     * di-ignore (anak mewarisi `SIG_DFL`, lihat `SigchldGuard`), dan disposisi
     * fire-and-forget dipulihkan setelahnya (tanpa zombie di HTTP worker).
     */
    public function testSigchldIsGuardedDuringForkAndRestoredAfter(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('pcntl tidak tersedia di lingkungan ini');
        }

        $ignoredInsideSeam = null;
        $launcher = new DeployWorkerLauncher(
            function (array $argv, ?int &$pid = null) use (&$ignoredInsideSeam): bool {
                $ignoredInsideSeam = SigchldGuard::isIgnored();
                $pid = 1;

                return true;
            },
            $this->tmp . '/cli/deploy.php',
            $this->tmp . '/logs/deploy'
        );

        $previous = @pcntl_signal_get_handler(SIGCHLD);
        @pcntl_signal(SIGCHLD, SIG_IGN);
        try {
            $this->assertTrue($launcher->launch('app1', 'deploy'));
            $this->assertFalse($ignoredInsideSeam, 'SIGCHLD harus SIG_DFL selama fork');
            $this->assertTrue(SigchldGuard::isIgnored(), 'pola fire-and-forget dipulihkan sesudah spawn');
        } finally {
            @pcntl_signal(SIGCHLD, $previous === false ? SIG_DFL : $previous);
        }
    }

    /**
     * Controller hanya boleh mendelegasi: tidak ada salinan kedua mekanisme
     * spawn (`pcntl_fork`/`pcntl_exec`) di `AppController`.
     */
    public function testAppControllerSpawnWorkerDelegatesToLauncher(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/controller/AppController.php');

        $start = strpos($source, 'private function spawnWorker(');
        $this->assertNotFalse($start, 'AppController::spawnWorker() tidak ditemukan');
        $body = substr($source, $start, (int) strpos($source, "\n    }", (int) $start) - (int) $start);

        $this->assertStringContainsString('DeployWorkerLauncher', $body, 'spawnWorker wajib memakai launcher bersama');
        foreach (['pcntl_fork', 'pcntl_exec', 'pcntl_signal', 'posix_setsid'] as $token) {
            $this->assertStringNotContainsString($token, $body, "mekanisme spawn ({$token}) tidak boleh disalin ke controller");
        }
    }
}
