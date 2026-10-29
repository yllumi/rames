<?php
declare(strict_types=1);

namespace Tests;

use app\process\SslQueueProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

// Helper webman (`config()`, `base_path()`) membaca konstanta BASE_PATH.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test proses drainer antrean SSL ({@see SslQueueProcess}).
 *
 * Dua kontrak yang dikunci:
 *  1. registrasi `config/process.php` — `ssl-queue` hanya dipasang di mode
 *     `dashboard` (satu worker, `count=1`, `reloadable`), tidak di mode `agent`
 *     (agent tidak punya store otoritatif/certbot lokal);
 *  2. `tick()` tidak pernah melempar: exception dicatat ke log antrean dan
 *     timer berikutnya tetap berjalan; tick bersarang dilewati (guard `$busy`).
 *
 * Tidak menyentuh data runtime: config & log di direktori temp, env dipulihkan
 * di `tearDown`.
 */
class SslQueueProcessTest extends TestCase
{
    private string $tmp = '';

    private string $dbFile = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    /** @var array<string,string|false> */
    private array $envState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-sslqueue-process-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp . '/config', 0777, true) && !is_dir($this->tmp . '/config')) {
            throw new RuntimeException('gagal membuat direktori temp: ' . $this->tmp);
        }
        if (!isset($GLOBALS['argv']) || !is_array($GLOBALS['argv'])) {
            $GLOBALS['argv'] = [];
        }
        $this->configState = $this->snapshotConfigState();
        foreach (['RAMES_MODE', 'AGENT_BIND', 'AGENT_TLS_CERT', 'AGENT_TLS_KEY'] as $name) {
            $this->envState[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envState as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->restoreConfigState($this->configState);
        if ($this->dbFile !== '') {
            foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (1) Registrasi proses
    // ==================================================================

    public function testProcessIsRegisteredInDashboardModeOnly(): void
    {
        $dashboard = $this->loadProcesses('dashboard');

        $this->assertArrayHasKey('ssl-queue', $dashboard);
        $this->assertSame(\app\process\SslQueueProcess::class, $dashboard['ssl-queue']['handler']);
        $this->assertSame(1, $dashboard['ssl-queue']['count'], 'satu drainer = satu job certbot pada satu waktu');
        $this->assertTrue($dashboard['ssl-queue']['reloadable']);
    }

    public function testProcessIsNotRegisteredInAgentMode(): void
    {
        $processes = $this->loadProcesses('agent');

        $this->assertArrayNotHasKey('ssl-queue', $processes, 'agent tidak menjalankan antrean SSL dashboard');
        $this->assertArrayHasKey('webman', $processes, 'worker HTTP tetap ada di agent');
    }

    // ==================================================================
    // (2) Tick tahan error
    // ==================================================================

    public function testTickSwallowsExceptionsAndLogsThem(): void
    {
        $log = $this->tmp . '/logs/queue.log';
        $process = new SslQueueProcess(static function (): void {
            throw new RuntimeException('drainer meledak');
        }, $log);

        $process->tick();

        $this->assertFileExists($log);
        $this->assertStringContainsString('drainer meledak', (string) file_get_contents($log));
    }

    public function testTickSkipsReentrantRuns(): void
    {
        $calls = 0;
        $process = new SslQueueProcess(function () use (&$calls, &$process): void {
            $calls++;
            $process->tick(); // timer bersarang: harus dilewati guard `$busy`
        }, $this->tmp . '/logs/queue.log');

        $process->tick();

        $this->assertSame(1, $calls, 'tick bersarang tidak boleh menjalankan drainer kedua');
    }

    public function testTickRunsTheQueueWorkerWithAnEmptyQueue(): void
    {
        $this->useQueueConfig();
        $log = $this->tmp . '/logs/queue.log';

        // Tanpa seam: `SslQueueWorker` asli dijalankan; antrean kosong ⇒ idle,
        // tidak ada exception/log yang ditulis.
        (new SslQueueProcess(null, $log))->tick();

        $this->assertFileDoesNotExist($log, 'antrean kosong tidak menghasilkan log error');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Muat `config/process.php` apa adanya untuk satu mode runtime.
     *
     * @return array<string,mixed>
     */
    private function loadProcesses(string $mode): array
    {
        if ($mode === 'agent') {
            [$cert, $key] = $this->makeCertPair();
            $this->applyEnv(['RAMES_MODE' => 'agent', 'AGENT_TLS_CERT' => $cert, 'AGENT_TLS_KEY' => $key]);
        } else {
            $this->applyEnv(['RAMES_MODE' => $mode]);
        }

        $dir = $this->tmp . '/config';
        // `Config::load()` hanya memuat direktori yang punya app.php, dan
        // `config/process.php` memanggil `Log::channel('default')`.
        file_put_contents($dir . '/app.php', "<?php return [];\n");
        file_put_contents($dir . '/log.php', "<?php return " . var_export(['default' => ['handlers' => [[]]]], true) . ";\n");
        Config::clear();
        Config::load($dir);

        return require BASE_PATH . '/config/process.php';
    }

    /** @param array<string,string|null> $vars */
    private function applyEnv(array $vars): void
    {
        foreach (['RAMES_MODE', 'AGENT_BIND', 'AGENT_TLS_CERT', 'AGENT_TLS_KEY'] as $name) {
            if (!array_key_exists($name, $vars) || $vars[$name] === null) {
                putenv($name);
                continue;
            }
            putenv($name . '=' . $vars[$name]);
        }
    }

    /** @return array{0:string,1:string} [certPath, keyPath] */
    private function makeCertPair(): array
    {
        $certPath = $this->tmp . '/agent.crt.pem';
        $keyPath = $this->tmp . '/agent.key.pem';
        file_put_contents($certPath, "-----BEGIN CERTIFICATE-----\ntemp\n-----END CERTIFICATE-----\n");
        file_put_contents($keyPath, "-----BEGIN PRIVATE KEY-----\ntemp\n-----END PRIVATE KEY-----\n");
        chmod($keyPath, 0600);

        return [$certPath, $keyPath];
    }

    /** Config temp dengan basis data antrean (seam `SslQueueWorker` asli). */
    private function useQueueConfig(): void
    {
        $dir = $this->tmp . '/queue-config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/app.php', "<?php return [];\n");
        file_put_contents($dir . '/deploy.php', '<?php return ' . var_export([
            'mode' => 'dashboard',
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'ssl_queue_timeout' => 5,
            'ssl_queue_stale_seconds' => 900,
        ], true) . ';' . PHP_EOL);
        Config::clear();
        Config::load($dir);
        $this->dbFile = $this->tmp . '/rames.sqlite';
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
