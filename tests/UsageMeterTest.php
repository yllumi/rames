<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Backup\BackupRegistry;
use app\library\Billing\BackupUsage;
use app\library\Billing\BillingStore;
use app\library\Billing\Pricing;
use app\library\Billing\UsageMeter;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Test UsageMeter — akrual tick per app (app running non-admin saja),
 * memakai berkas basis data SQLite temp (tanpa data runtime nyata).
 *
 * Sejak biaya backup: akrual CPU/RAM **dan** backup (GB ditagih dari
 * `BackupRegistry`, tarif per bulan, floor minimum per app) dihitung per tick.
 */
class UsageMeterTest extends TestCase
{
    private const NOW = '2026-10-14T09:30:00+07:00';
    private const OCTOBER_SECONDS = 31 * 86400;

    /** Bulan 30 hari (November 2026) — dasar perhitungan drift satu bulan penuh. */
    private const NOW_30D = '2026-11-14T09:30:00+07:00';

    private string $tmp;
    private AppStore $apps;
    private BillingStore $billing;
    private UsageMeter $meter;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/usagemeter_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        $db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->apps = new AppStore($db);
        $this->billing = new BillingStore($db);
        $this->meter = new UsageMeter(
            $this->apps,
            $this->billing,
            new UserStore($db),
            new BackupUsage(new BackupRegistry($db))
        );
    }

    /**
     * Semai koleksi `registry` store `backup` (bytes snapshot per volume).
     *
     * @param array<string,array<string,mixed>> $registry
     */
    private function seedSnapshots(array $registry): void
    {
        SqliteFixture::backup($this->tmp . '/rames.sqlite', ['registry' => $registry]);
    }

    private static function bytes(float $gb): int
    {
        return (int) round($gb * BackupUsage::BYTES_PER_GB);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function app(string $name, string $owner, string $status = 'running', array $limits = []): array
    {
        return $this->apps->create([
            'name' => $name,
            'owner_id' => $owner,
            'status' => $status,
            'limits' => $limits,
        ]);
    }

    public function testAccruesOnlyRunningMemberApps(): void
    {
        $member = $this->app('memberapp', 'u2');
        $this->app('adminapp', 'u1');
        $this->app('stoppedapp', 'u2', 'stopped');

        $accrued = $this->meter->sample(300, self::NOW);

        $this->assertSame([$member['id']], $accrued);
        $usage = $this->billing->usage();
        $this->assertArrayHasKey($member['id'], $usage);
        $this->assertCount(1, $usage, 'app admin & stopped tidak diakru');
    }

    public function testAccrualUsesDefaultUnitForEmptyLimits(): void
    {
        $app = $this->app('no-limits', 'u2');

        $this->meter->sample(300, self::NOW);
        $row = $this->billing->usage()[$app['id']];

        $this->assertSame('2026-10', $row['period']);
        $this->assertSame(300, $row['seconds_pending']);
        $this->assertEqualsWithDelta(5.0, $row['credits_pending'], 0.0001, '300s × 60/jam');
        $this->assertSame(self::NOW, $row['sampled_at']);
        $this->assertSame('u2', $row['owner_id']);
        $this->assertSame('no-limits', $row['name']);
    }

    public function testAccrualUsesAppLimits(): void
    {
        $app = $this->app('big', 'u2', 'running', ['web' => ['cpus' => 1.0, 'memory_mb' => 1024]]);

        $this->meter->sample(300, self::NOW);

        // 1 core + 1 GB → 120/jam → 300s = 10.0
        $this->assertEqualsWithDelta(10.0, $this->billing->usage()[$app['id']]['credits_pending'], 0.0001);
    }

    public function testSamplesAccumulate(): void
    {
        $app = $this->app('acc', 'u2');

        $this->meter->sample(300, self::NOW);
        $this->meter->sample(300, self::NOW);
        $row = $this->billing->usage()[$app['id']];

        $this->assertSame(600, $row['seconds_pending']);
        $this->assertEqualsWithDelta(10.0, $row['credits_pending'], 0.0001);
    }

    public function testNonPositiveSecondsIsNoOp(): void
    {
        $this->app('app', 'u2');

        $this->assertSame([], $this->meter->sample(0, self::NOW));
        $this->assertSame([], $this->meter->sample(-5, self::NOW));
        $this->assertSame([], $this->billing->usage());
    }

    public function testOwnerMissingFromAuthIsSkipped(): void
    {
        $this->app('orphan', 'ghost');

        $this->assertSame([], $this->meter->sample(300, self::NOW));
        $this->assertSame([], $this->billing->usage());
    }

    public function testSummaryShape(): void
    {
        $app = $this->app('sum', 'u2');
        $this->meter->sample(300, self::NOW);

        $summary = $this->meter->summary();
        $this->assertArrayHasKey($app['id'], $summary);
        $row = $summary[$app['id']];

        $this->assertSame('u2', $row['owner_id']);
        $this->assertSame('sum', $row['name']);
        $this->assertSame(300, $row['seconds_pending']);
        $this->assertEqualsWithDelta(5.0, $row['credits_pending'], 0.0001);
        $this->assertEqualsWithDelta(60.0, $row['hourly'], 0.0001);
        // Oktober = 31 hari → 60 × 24 × 31
        $this->assertEqualsWithDelta(44640.0, $row['estimate_month'], 0.0001);
        // Tanpa snapshot: rincian backup nol (bukan error).
        $this->assertSame(0.0, $row['backup_gb']);
        $this->assertSame(0.0, $row['backup_credits']);
        $this->assertSame(0.0, $row['backup_monthly']);
    }

    public function testBackupAccruedPerTickForFullMonth(): void
    {
        $app = $this->app('withsnap', 'u2');
        $this->seedSnapshots([
            'withsnap_data' => ['app_id' => $app['id'], 'bytes' => self::bytes(3.0), 'snapshots' => 1],
        ]);

        // Satu tick = satu bulan penuh (Oktober 31 hari) → 3 GB × 1000.
        $this->meter->sample(self::OCTOBER_SECONDS, self::NOW);
        $row = $this->billing->usage()[$app['id']];

        $this->assertSame(3.0, $row['backup_gb']);
        $this->assertEqualsWithDelta(3000.0, $row['backup_credits_pending'], 0.0001);
        // CPU/RAM tetap terakru terpisah (default 60/jam × 744 jam).
        $this->assertEqualsWithDelta(44640.0, $row['credits_pending'], 0.0001);
    }

    public function testBackupFloorOneGbPerApp(): void
    {
        $small = $this->app('small', 'u2');
        $this->seedSnapshots([
            'small_data' => ['app_id' => $small['id'], 'bytes' => self::bytes(0.3), 'snapshots' => 1],
        ]);

        $this->meter->sample(self::OCTOBER_SECONDS, self::NOW);
        $row = $this->billing->usage()[$small['id']];

        $this->assertSame(1.0, $row['backup_gb'], '0,3 GB ditagih 1 GB (floor per app)');
        $this->assertEqualsWithDelta(1000.0, $row['backup_credits_pending'], 0.0001);
    }

    public function testAppWithoutSnapshotHasNoBackupCharge(): void
    {
        $app = $this->app('nosnap', 'u2');

        $this->meter->sample(self::OCTOBER_SECONDS, self::NOW);
        $row = $this->billing->usage()[$app['id']];

        $this->assertSame(0.0, $row['backup_gb']);
        $this->assertSame(0.0, $row['backup_credits_pending']);
    }

    /**
     * Akumulasi backup disimpan **4 desimal** per tick (bukan 2) — nilai tick
     * tetap apa adanya dari `Pricing`, dan pembulatan 2 desimal lama terbukti
     * menaikkan akumulasi (0,2894 → 0,29).
     */
    public function testBackupAccrualStoresFourDecimalsPerTick(): void
    {
        $app = $this->app('precise', 'u2');
        $this->seedSnapshots([
            'precise_data' => ['app_id' => $app['id'], 'bytes' => self::bytes(2.5), 'snapshots' => 1],
        ]);

        // November 2026 = 30 hari ⇒ per tick = 2,5 GB × 1000 × 300/(30×86400).
        $this->meter->sample(300, self::NOW_30D);
        $row = $this->billing->usage()[$app['id']];

        $perTick = Pricing::backupCredits(2.5, 300.0, 30);
        $this->assertSame(round($perTick, 4), $row['backup_credits_pending'], 'akumulasi 4 desimal (0,2894)');
        $this->assertNotSame(round($perTick, 2), $row['backup_credits_pending'], 'bukan pembulatan 2 desimal (0,29)');
    }

    /**
     * Drift akumulasi satu bulan penuh (30 hari × tick 300 s = 8640 tick,
     * 2,5 GB): aturan **2 desimal lama** membiaskan tagihan naik **+5,60**
     * kredit; aturan **4 desimal** menyimpang **< 1** kredit dari 2500 dan
     * tagihan tetap dibulatkan 2 desimal.
     */
    public function testBackupAccrualDriftStaysBelowOneCreditPerMonth(): void
    {
        $perTick = Pricing::backupCredits(2.5, 300.0, 30);
        $ticks = (int) (30 * 86400 / 300);

        // Simulasi akumulasi persis seperti `UsageMeter::sample()`: total
        // dibulatkan tiap tick (bukan hanya di akhir).
        $twoDecimals = 0.0;
        $fourDecimals = 0.0;
        for ($i = 0; $i < $ticks; $i++) {
            $twoDecimals = round($twoDecimals + $perTick, 2);
            $fourDecimals = round($fourDecimals + $perTick, 4);
        }

        $this->assertEqualsWithDelta(2505.60, $twoDecimals, 0.0001, 'aturan 2 desimal lama: drift +5,60');
        $this->assertLessThan(1.0, abs($fourDecimals - 2500.0), 'aturan 4 desimal: drift < 1 kredit');
        $this->assertEqualsWithDelta(2500.42, round($fourDecimals, 2), 0.0001, 'penagihan tetap 2 desimal');
    }

    public function testAdminAndStoppedAppsNeverAccrueBackup(): void
    {
        $adminApp = $this->app('adminapp', 'u1');
        $stopped = $this->app('stoppedapp', 'u2', 'stopped');
        $member = $this->app('memberapp', 'u2');
        $this->seedSnapshots([
            'a' => ['app_id' => $adminApp['id'], 'bytes' => self::bytes(2.0), 'snapshots' => 1],
            'b' => ['app_id' => $stopped['id'], 'bytes' => self::bytes(2.0), 'snapshots' => 1],
            'c' => ['app_id' => $member['id'], 'bytes' => self::bytes(2.0), 'snapshots' => 1],
        ]);

        $accrued = $this->meter->sample(self::OCTOBER_SECONDS, self::NOW);

        $this->assertSame([$member['id']], $accrued);
        $usage = $this->billing->usage();
        $this->assertArrayNotHasKey($adminApp['id'], $usage, 'app admin gratis');
        $this->assertArrayNotHasKey($stopped['id'], $usage, 'app non-running tidak diakru');
    }

    public function testSummaryIncludesBackupBreakdown(): void
    {
        $app = $this->app('backupsum', 'u2');
        $this->seedSnapshots([
            'backupsum_data' => ['app_id' => $app['id'], 'bytes' => self::bytes(2.0), 'snapshots' => 2],
        ]);

        $this->meter->sample(self::OCTOBER_SECONDS, self::NOW);
        $row = $this->meter->summary()[$app['id']];

        $this->assertSame(2.0, $row['backup_gb']);
        $this->assertEqualsWithDelta(2000.0, $row['backup_credits'], 0.0001);
        $this->assertEqualsWithDelta(2000.0, $row['backup_monthly'], 0.0001);
        // estimate_month = CPU/RAM bulan (60 × 744) + backup bulan (2000).
        $this->assertEqualsWithDelta(44640.0 + 2000.0, $row['estimate_month'], 0.0001);
    }

    /**
     * Baris lama tanpa field backup (data sebelum fitur ini) tidak error dan
     * dianggap tanpa biaya backup.
     */
    public function testSummaryLegacyRowWithoutBackupFields(): void
    {
        $app = $this->app('legacy', 'u2');
        $this->billing->update(function (array &$data) use ($app): void {
            $data['usage'][$app['id']] = [
                'name' => 'legacy',
                'owner_id' => 'u2',
                'period' => '2026-10',
                'seconds_pending' => 3600,
                'credits_pending' => 60.0,
                'sampled_at' => self::NOW,
            ];
        });

        $row = $this->meter->summary()[$app['id']];

        $this->assertSame(0.0, $row['backup_gb']);
        $this->assertSame(0.0, $row['backup_credits']);
        $this->assertSame(0.0, $row['backup_monthly']);
        $this->assertEqualsWithDelta(44640.0, $row['estimate_month'], 0.0001);
    }
}
