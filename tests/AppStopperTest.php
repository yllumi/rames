<?php
declare(strict_types=1);

namespace Tests;

use app\library\Billing\AppStopper;
use app\library\Storage\AppStore;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

/**
 * Test AppStopper — auto-stop app saldo negatif (SPECS.md §7.12).
 *
 * Tanpa Docker (`DeployerFactory`) & tanpa data runtime nyata: penghenti
 * di-inject palsu, seluruh store memakai path temp, dan direktori log
 * diarahkan lewat `billing_log_path` (config temp) supaya tidak menyentuh
 * `runtime/logs/billing` nyata.
 */
class AppStopperTest extends TestCase
{
    private string $tmp;
    private AppStore $apps;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-stopper-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();

        $this->apps = new AppStore($this->tmp . '/rames.sqlite');
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        $this->removeDir($this->tmp);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function useConfig(array $overrides = []): void
    {
        Config::clear();
        // loadFromDir() hanya memuat berkas di direktori yang juga punya app.php.
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(['billing_log_path' => $this->tmp . '/logs'] + $overrides, true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
    }

    private function app(string $name, string $owner, string $status): array
    {
        return $this->apps->create([
            'name' => $name,
            'owner_id' => $owner,
            'status' => $status,
            'stage' => 'deploy',
            'message' => 'sebelumnya',
        ]);
    }

    /**
     * @param callable(array):void $stopper
     */
    private function stopper(callable $stopper): AppStopper
    {
        return new AppStopper($this->apps, $stopper);
    }

    public function testStopsOnlyLiveAppsOfOwner(): void
    {
        $live = $this->app('live', 'u2', 'running');
        $deploying = $this->app('deploying', 'u2', 'deploying');
        $alreadyStopped = $this->app('already', 'u2', 'stopped');
        $errored = $this->app('err', 'u2', 'error');
        $otherOwner = $this->app('other', 'u3', 'running');

        $calls = [];
        $stopper = $this->stopper(function (array $app) use (&$calls): void {
            $calls[] = (string) $app['id'];
        });

        $this->assertSame(2, $stopper->stopOwnedBy('u2'));
        $this->assertSame([$live['id'], $deploying['id']], $calls);

        $stoppedRow = $this->apps->find($live['id']);
        $this->assertSame('stopped', $stoppedRow['status']);
        $this->assertSame(AppStopper::REASON, $stoppedRow['message']);
        $this->assertNull($stoppedRow['stage']);

        $this->assertSame('stopped', $this->apps->find($deploying['id'])['status']);
        // App yang sudah stopped / error tidak disentuh.
        $this->assertSame('sebelumnya', $this->apps->find($alreadyStopped['id'])['message']);
        $this->assertSame('error', $this->apps->find($errored['id'])['status']);
        // App milik user lain tidak disentuh.
        $this->assertSame('running', $this->apps->find($otherOwner['id'])['status']);

        $logFile = $this->tmp . '/logs/' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        $this->assertStringContainsString($live['id'], (string) file_get_contents($logFile));
    }

    public function testIdempotentSecondRunStopsNothing(): void
    {
        $this->app('a', 'u2', 'running');
        $stopper = $this->stopper(static function (array $app): void {
        });

        $this->assertSame(1, $stopper->stopOwnedBy('u2'));
        $this->assertSame(0, $stopper->stopOwnedBy('u2'));
    }

    public function testFailureDoesNotBlockOtherAppsAndIsLogged(): void
    {
        $bad = $this->app('bad', 'u2', 'running');
        $good = $this->app('good', 'u2', 'running');

        $stopper = $this->stopper(static function (array $app): void {
            if ((string) ($app['name'] ?? '') === 'bad') {
                throw new RuntimeException('docker tidak tersedia');
            }
        });

        $this->assertSame(1, $stopper->stopOwnedBy('u2'));

        // App yang gagal dihentikan dibiarkan apa adanya (bukan ditandai stopped).
        $this->assertSame('running', $this->apps->find($bad['id'])['status']);
        $this->assertSame('stopped', $this->apps->find($good['id'])['status']);

        $log = (string) file_get_contents($this->tmp . '/logs/' . date('Y-m-d') . '.log');
        $this->assertStringContainsString('GAGAL', $log);
        $this->assertStringContainsString($bad['id'], $log);
        $this->assertStringContainsString('docker tidak tersedia', $log);
    }

    public function testExplicitAppsSubsetIsHonored(): void
    {
        $first = $this->app('first', 'u2', 'running');
        $second = $this->app('second', 'u2', 'running');

        $calls = [];
        $stopper = $this->stopper(function (array $app) use (&$calls): void {
            $calls[] = (string) $app['id'];
        });

        $this->assertSame(1, $stopper->stopOwnedBy('u2', [$second]));
        $this->assertSame([$second['id']], $calls);
        $this->assertSame('running', $this->apps->find($first['id'])['status']);
    }

    public function testBlankUserAndOwnerWithoutAppsReturnZero(): void
    {
        $calls = [];
        $stopper = $this->stopper(function (array $app) use (&$calls): void {
            $calls[] = (string) $app['id'];
        });

        $this->assertSame(0, $stopper->stopOwnedBy('   '));
        $this->assertSame(0, $stopper->stopOwnedBy('nobody'));
        $this->assertSame([], $calls);
    }

    public function testMissingAppIdIsSkipped(): void
    {
        $stopper = $this->stopper(static function (array $app): void {
            throw new RuntimeException('tidak boleh dipanggil tanpa id');
        });

        $this->assertSame(0, $stopper->stopOwnedBy('u2', [['status' => 'running']]));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    // ------------------------------------------------------------------
    // Pengalihan kepemilikan ke pemilik bebas tagihan (temuan verifier N6)
    // ------------------------------------------------------------------

    public function testShouldStopOnTransferMatrix(): void
    {
        $running = ['id' => 'a1', 'status' => 'running'];

        self::assertTrue(AppStopper::shouldStopOnTransfer($running, true, true));
        self::assertTrue(AppStopper::shouldStopOnTransfer(['id' => 'a1', 'status' => 'deploying'], true, true));
        self::assertFalse(AppStopper::shouldStopOnTransfer(['id' => 'a1', 'status' => 'stopped'], true, true));
        self::assertFalse(AppStopper::shouldStopOnTransfer($running, true, false), 'member → member: tetap berjalan');
        self::assertFalse(AppStopper::shouldStopOnTransfer($running, false, true), 'admin → admin: tidak ada akrual yang hilang');
    }

    public function testStopsAppWhenTransferredToExemptOwner(): void
    {
        $app = $this->app('dipindah', 'u1', 'running'); // kondisi setelah transfer: owner admin
        $stopper = $this->stopper(static function (array $app): void {
        });

        self::assertTrue($stopper->stopForBillingEscape($app, true, true));
        self::assertSame('stopped', $this->apps->find($app['id'])['status']);
        self::assertSame(AppStopper::REASON_TRANSFER, $this->apps->find($app['id'])['message']);
    }

    /**
     * Penghapusan user: app milik member dialihkan ke admin (bebas tagihan) —
     * semuanya yang masih hidup harus dihentikan agar tidak berjalan gratis.
     */
    public function testStopsAppsTransferredInBulkOnUserDeletion(): void
    {
        $one = $this->app('satu', 'u2', 'running');
        $two = $this->app('dua', 'u2', 'running');
        $stoppedOne = $this->app('tiga', 'u2', 'stopped');
        $appsBefore = [$one, $two, $stoppedOne];

        // Simulasi transferAllFrom(): owner berpindah ke admin.
        foreach ($appsBefore as $app) {
            $this->apps->update($app['id'], static function (array &$row): void {
                $row['owner_id'] = 'u1';
            });
        }

        $stopper = $this->stopper(static function (array $app): void {
        });

        self::assertSame(2, $stopper->stopTransferredToExemptOwner($appsBefore, true, true));
        self::assertSame('stopped', $this->apps->find($one['id'])['status']);
        self::assertSame('stopped', $this->apps->find($two['id'])['status']);
        self::assertSame(AppStopper::REASON_TRANSFER, $this->apps->find($one['id'])['message']);

        // Pemilik baru tidak bebas tagihan ⇒ tidak ada yang dihentikan.
        self::assertSame(0, $stopper->stopTransferredToExemptOwner($appsBefore, true, false));
    }

    public function testDoesNotStopWhenOwnerStaysBillable(): void
    {
        $app = $this->app('tetap', 'u2', 'running');
        $stopper = $this->stopper(static function (array $app): void {
        });

        self::assertFalse($stopper->stopForBillingEscape($app, true, false));
        self::assertSame('running', $this->apps->find($app['id'])['status']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
