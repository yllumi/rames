<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Billing\AppStopper;
use app\library\Billing\BillingRunner;
use app\library\Billing\BillingStore;
use app\library\Storage\AppStore;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

/**
 * Test BillingRunner — orkestrator satu tick penjadwal billing (SPECS.md §7.12):
 * urutan langkah, bentuk ringkasan (kontrak tetap), penghormatan
 * `BILLING_ENABLED`/`BILLING_TOPUP_ENABLED`, serta penagihan + auto-stop.
 *
 * Tanpa Docker & tanpa jaringan: penghenti di-inject palsu, konsiliasi/kedaluwarsa
 * order memakai callable palsu, dan seluruh store memakai path temp.
 */
class BillingRunnerTest extends TestCase
{
    private const NOW = '2026-10-14T09:30:00+07:00';

    private string $tmp;
    private AppStore $apps;
    private BillingStore $billing;
    private UserStore $users;

    /** @var array<int,string> */
    public array $stoppedApps = [];

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-runner-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        $this->useConfig([]);

        file_put_contents($this->tmp . '/auth.json', json_encode([
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]));

        $this->apps = new AppStore($this->tmp . '/apps.json');
        $this->billing = new BillingStore($this->tmp . '/billing.json');
        $this->users = new UserStore($this->tmp . '/auth.json');
        $this->stoppedApps = [];
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        $this->removeDir($this->tmp);
    }

    /**
     * Muat config temp (log billing selalu diarahkan ke path temp).
     *
     * @param array<string,mixed> $overrides
     */
    private function useConfig(array $overrides): void
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

    private function runningApp(string $name, string $owner, array $limits = []): array
    {
        return $this->apps->create([
            'name' => $name,
            'owner_id' => $owner,
            'status' => 'running',
            'limits' => $limits,
        ]);
    }

    private function runner(
        ?callable $logger = null,
        ?callable $expireOrders = null,
        ?callable $reconcileOrders = null
    ): BillingRunner {
        $self = $this;
        $stopper = new AppStopper($this->apps, function (array $app) use ($self): void {
            $self->stoppedApps[] = (string) $app['id'];
        });

        return new BillingRunner(
            $this->billing,
            $this->apps,
            $this->users,
            $stopper,
            $reconcileOrders,
            $expireOrders,
            $logger
        );
    }

    public function testTickShapeAndSampling(): void
    {
        $this->useConfig(['billing_sample_seconds' => 60]);
        $this->runningApp('web', 'u2', ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]);

        $summary = $this->runner()->tick(self::NOW);

        $this->assertSame(['sampled', 'expired', 'invoiced', 'reconciled'], array_keys($summary));
        $this->assertSame(1, $summary['sampled']);
        $this->assertSame(0, $summary['expired']);
        $this->assertSame([], $summary['invoiced']);
        $this->assertSame(0, $summary['reconciled']);

        $usage = $this->billing->usage();
        $appId = (string) $this->apps->all()[0]['id'];
        $this->assertArrayHasKey($appId, $usage);
        $this->assertGreaterThan(0.0, (float) $usage[$appId]['credits_pending']);
        $this->assertSame(60, (int) $usage[$appId]['seconds_pending']);
    }

    public function testTopUpDisabledNeverCallsOrderHooks(): void
    {
        $fail = static function (): int {
            throw new RuntimeException('hook top-up tidak boleh dipanggil saat fitur mati');
        };

        $summary = $this->runner(null, $fail, $fail)->tick(self::NOW);

        $this->assertSame(0, $summary['expired']);
        $this->assertSame(0, $summary['reconciled']);
    }

    public function testTopUpEnabledPassesMaxAndNow(): void
    {
        $this->useConfig([
            'billing_topup_enabled' => true,
            'billing_duitku_status_max_per_tick' => 5,
            'billing_sample_seconds' => 0,
        ]);

        $expireCalls = [];
        $reconcileCalls = [];
        $expire = function (?string $now = null) use (&$expireCalls): int {
            $expireCalls[] = $now;

            return 2;
        };
        $reconcile = function (int $max, ?string $now = null) use (&$reconcileCalls): int {
            $reconcileCalls[] = [$max, $now];

            return 4;
        };

        $summary = $this->runner(null, $expire, $reconcile)->tick(self::NOW);

        $this->assertSame(2, $summary['expired']);
        $this->assertSame(4, $summary['reconciled']);
        $this->assertSame([self::NOW], $expireCalls);
        $this->assertSame([[5, self::NOW]], $reconcileCalls);
    }

    public function testBillingDisabledReturnsZerosWithoutSideEffects(): void
    {
        $this->useConfig(['billing_enabled' => false, 'billing_topup_enabled' => true]);
        $this->runningApp('web', 'u2');

        $fail = static function (): int {
            throw new RuntimeException('billing mati → tidak ada hook yang boleh jalan');
        };

        $summary = $this->runner(null, $fail, $fail)->tick(self::NOW);

        $this->assertSame(
            ['sampled' => 0, 'expired' => 0, 'invoiced' => [], 'reconciled' => 0],
            $summary
        );
        $this->assertSame([], $this->billing->usage());
        $this->assertSame([], $this->stoppedApps);
    }

    public function testMeterIntervalFollowsConfig(): void
    {
        $this->useConfig(['billing_sample_seconds' => 120]);
        $this->assertSame(120, $this->runner()->meterInterval());

        $this->useConfig(['billing_sample_seconds' => 0]);
        $this->assertSame(0, $this->runner()->meterInterval());
    }

    public function testIntervalZeroSkipsSampling(): void
    {
        $this->useConfig(['billing_sample_seconds' => 0]);
        $this->runningApp('web', 'u2');

        $summary = $this->runner()->tick(self::NOW);

        $this->assertSame(0, $summary['sampled']);
        $this->assertSame([], $this->billing->usage());
    }

    public function testInvoicesDueRowAndAutoStopsNegativeOwner(): void
    {
        $this->useConfig(['billing_sample_seconds' => 0]);
        $app = $this->runningApp('web', 'u2', ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]);
        $this->seedUsage($app['id'], 'u2', '2026-09', 3600, 60.0);

        $summary = $this->runner()->tick(self::NOW);

        $this->assertCount(1, $summary['invoiced']);
        $this->assertSame('u2', $summary['invoiced'][0]['user_id']);
        $this->assertSame('2026-09', $summary['invoiced'][0]['period']);
        $this->assertSame(60.0, $summary['invoiced'][0]['amount']);
        $this->assertTrue($summary['invoiced'][0]['stopped']);
        $this->assertSame(1, $summary['invoiced'][0]['apps_stopped']);

        $this->assertSame([$app['id']], $this->stoppedApps);
        $this->assertSame('stopped', $this->apps->find($app['id'])['status']);

        $row = $this->billing->usage()[$app['id']];
        $this->assertSame('2026-10', (string) $row['period']);
        $this->assertSame(0, (int) $row['seconds_pending']);
        $this->assertSame(0.0, (float) $row['credits_pending']);
    }

    /**
     * Penagihan menunggu `BILLING_INVOICE_DAY` (default 1) dan tetap mengejar
     * setelah tanggal itu terlewat (catch-up) — periode tertunggak tidak hilang.
     */
    public function testInvoicingWaitsForInvoiceDayThenCatchesUp(): void
    {
        $this->useConfig(['billing_sample_seconds' => 0, 'billing_invoice_day' => 5]);
        $app = $this->runningApp('web', 'u2', ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]);
        $this->seedUsage($app['id'], 'u2', '2026-09', 3600, 60.0);

        // Tanggal 2 (< 5): belum menagih.
        $early = $this->runner()->tick('2026-10-02T10:00:00+07:00');
        $this->assertSame([], $early['invoiced']);
        $this->assertArrayNotHasKey('u2', $this->billing->users(), 'belum ada entri kredit user');

        // Tanggal 5: menagih periode 2026-09.
        $due = $this->runner()->tick('2026-10-05T00:30:00+07:00');
        $this->assertCount(1, $due['invoiced']);
        $this->assertSame('2026-09', $due['invoiced'][0]['period']);
        $this->assertSame(-60.0, (float) $this->billing->users()['u2']['balance']);
    }

    public function testPaymentPolicyBlockKeepsAppsRunning(): void    {
        $this->useConfig(['billing_sample_seconds' => 0, 'billing_payment_policy' => 'block']);
        $app = $this->runningApp('web', 'u2');
        $this->seedUsage($app['id'], 'u2', '2026-09', 3600, 60.0);

        $summary = $this->runner()->tick(self::NOW);

        $this->assertCount(1, $summary['invoiced']);
        $this->assertSame(60.0, $summary['invoiced'][0]['amount']);
        $this->assertFalse($summary['invoiced'][0]['stopped']);
        $this->assertSame([], $this->stoppedApps);
        $this->assertSame('running', $this->apps->find($app['id'])['status']);
    }

    public function testLoggerReceivesSummaryOnActivity(): void
    {
        $this->useConfig(['billing_sample_seconds' => 60]);
        $this->runningApp('web', 'u2');

        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $this->runner($logger)->tick(self::NOW);

        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('sampled=1', $logs[0]);
    }

    private function seedUsage(string $appId, string $owner, string $period, int $seconds, float $credits): void
    {
        $this->billing->update(function (array &$data) use ($appId, $owner, $period, $seconds, $credits): void {
            $data['usage'][$appId] = [
                'name' => 'web',
                'owner_id' => $owner,
                'period' => $period,
                'seconds_pending' => $seconds,
                'credits_pending' => $credits,
                'sampled_at' => '2026-09-30T00:00:00+07:00',
            ];
        });
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
