<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupRegistry;
use app\library\Billing\BackupUsage;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\SqliteFixture;
use Webman\Config;

/**
 * Test `BackupUsage` — basis biaya backup volume (GB ditagih per app) dari
 * `BackupRegistry`, termasuk floor minimum per app & tarif per bulan.
 *
 * Store `backup` memakai berkas SQLite temp (larangan #15: tanpa menyentuh data
 * runtime). `app_id`/`bytes`/`snapshots` disemai lewat `SqliteFixture`.
 */
class BackupUsageTest extends TestCase
{
    private string $tmp;
    private string $db;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backupusage_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->db = $this->tmp . '/rames.sqlite';
        $this->configState = $this->snapshotConfigState();
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        $this->removeTree($this->tmp);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Seed koleksi `registry` store `backup`.
     *
     * @param array<string,array<string,mixed>> $registry
     */
    private function seed(array $registry): BackupUsage
    {
        SqliteFixture::backup($this->db, ['registry' => $registry]);

        return new BackupUsage(new BackupRegistry($this->db));
    }

    /** Byte untuk `$gb` GB (1024^3), dibulatkan ke integer. */
    private static function bytes(float $gb): int
    {
        return (int) round($gb * BackupUsage::BYTES_PER_GB);
    }

    public function testEmptyRegistryYieldsEmptyMaps(): void
    {
        $usage = $this->seed([]);

        $this->assertSame([], $usage->rawGigabytesByApp());
        $this->assertSame([], $usage->chargedGigabytesByApp());
        $this->assertSame(0.0, $usage->chargedGigabytes('app1'));
        $this->assertSame(0.0, $usage->chargedGigabytes(''));
    }

    public function testRawGigabytesSumsVolumesPerApp(): void
    {
        $usage = $this->seed([
            'app1_data' => ['app_id' => 'app1', 'bytes' => self::bytes(0.5), 'snapshots' => 2],
            'app1_cache' => ['app_id' => 'app1', 'bytes' => self::bytes(0.25), 'snapshots' => 1],
            'app2_data' => ['app_id' => 'app2', 'bytes' => self::bytes(4.0), 'snapshots' => 1],
        ]);

        $raw = $usage->rawGigabytesByApp();

        $this->assertCount(2, $raw);
        $this->assertEqualsWithDelta(0.75, $raw['app1'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $raw['app2'], 0.0001);
    }

    /**
     * Floor 1 GB berlaku **per app**: app 0,3 GB tetap ditagih 1 GB, sedangkan
     * app > 1 GB ditagih apa adanya (raw dipertahankan khusus untuk app besar).
     */
    public function testFloorApplies(): void
    {
        $usage = $this->seed([
            'small_data' => ['app_id' => 'app1', 'bytes' => self::bytes(0.3), 'snapshots' => 1],
            'big_data' => ['app_id' => 'app2', 'bytes' => self::bytes(2.5), 'snapshots' => 1],
        ]);

        $charged = $usage->chargedGigabytesByApp();

        $this->assertEqualsWithDelta(1.0, $charged['app1'], 0.0001, '0,3 GB → ditagih 1 GB');
        $this->assertEqualsWithDelta(2.5, $charged['app2'], 0.0001, 'di atas floor → apa adanya');
        $this->assertEqualsWithDelta(1.0, $usage->chargedGigabytes('app1'), 0.0001);
        $this->assertEqualsWithDelta(2.5, $usage->chargedGigabytes('app2'), 0.0001);
        // Raw tidak ikut dibulatkan ke floor.
        $this->assertEqualsWithDelta(0.3, $usage->rawGigabytesByApp()['app1'], 0.0001);
    }

    public function testThreeAppsEachUnderOneGbChargeOneGbEach(): void
    {
        $usage = $this->seed([
            'a1' => ['app_id' => 'app1', 'bytes' => self::bytes(0.3), 'snapshots' => 1],
            'a2' => ['app_id' => 'app2', 'bytes' => self::bytes(0.3), 'snapshots' => 1],
            'a3' => ['app_id' => 'app3', 'bytes' => self::bytes(0.3), 'snapshots' => 1],
        ]);

        $charged = $usage->chargedGigabytesByApp();

        $this->assertCount(3, $charged);
        $this->assertEqualsWithDelta(1.0, $charged['app1'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $charged['app2'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $charged['app3'], 0.0001);
        $this->assertEqualsWithDelta(3.0, array_sum($charged), 0.0001, 'floor per app, bukan per host');
    }

    public function testAppWithoutSnapshotIsNotCharged(): void
    {
        $usage = $this->seed([
            'zero_bytes' => ['app_id' => 'app1', 'bytes' => 0, 'snapshots' => 1],
            'zero_snapshots' => ['app_id' => 'app2', 'bytes' => self::bytes(2.0), 'snapshots' => 0],
        ]);

        $this->assertSame([], $usage->chargedGigabytesByApp());
        $this->assertSame(0.0, $usage->chargedGigabytes('app1'));
        $this->assertSame(0.0, $usage->chargedGigabytes('app2'));
    }

    public function testOrphanAndNonNumericEntriesAreIgnored(): void
    {
        $usage = $this->seed([
            'no_app' => ['app_id' => '', 'bytes' => self::bytes(1.0), 'snapshots' => 1],
            'null_app' => ['app_id' => null, 'bytes' => self::bytes(1.0), 'snapshots' => 1],
            'garbage' => ['app_id' => 'app1', 'bytes' => 'bukan-angka', 'snapshots' => 1],
            'valid' => ['app_id' => 'app1', 'bytes' => self::bytes(0.4), 'snapshots' => 1],
        ]);

        $raw = $usage->rawGigabytesByApp();

        $this->assertCount(1, $raw);
        $this->assertEqualsWithDelta(0.4, $raw['app1'], 0.0001, 'hanya entri valid yang dihitung');
    }

    public function testMissingSnapshotsFieldDefaultsToOne(): void
    {
        $usage = $this->seed([
            'no_snapshots_field' => ['app_id' => 'app1', 'bytes' => self::bytes(0.2)],
        ]);

        $this->assertEqualsWithDelta(1.0, $usage->chargedGigabytes('app1'), 0.0001);
    }

    public function testMonthlyCreditsAndRequiredDeposit(): void
    {
        $usage = $this->seed([]);

        $this->assertSame(2000.0, $usage->monthlyCredits(2.0), 'tarif default 1000/GB/bulan');
        // Deposit minimum = estimasi BILLING_MIN_DEPOSIT_DAYS (default 30) ⇒ 1 bulan.
        $this->assertSame(2000.0, $usage->requiredDeposit(2.0));
        $this->assertSame(1000.0, $usage->requiredDeposit(1.0));
        $this->assertSame(0.0, $usage->requiredDeposit(-3.0), 'GB negatif tidak menambah deposit');
        $this->assertSame(0.0, $usage->requiredDeposit(0.0));
    }

    public function testMinGigabytesDefaultIsOne(): void
    {
        $this->assertSame(1.0, $this->seed([])->minGigabytes());
    }

    /**
     * Config `billing_backup_min_gb = 0` mematikan floor → app ditagih raw.
     */
    public function testMinGigabytesZeroDisablesFloor(): void
    {
        $this->useConfig(['billing_backup_min_gb' => 0.0]);

        $usage = $this->seed([
            'small' => ['app_id' => 'app1', 'bytes' => self::bytes(0.3), 'snapshots' => 1],
        ]);

        $this->assertSame(0.0, $usage->minGigabytes());
        $this->assertEqualsWithDelta(0.3, $usage->chargedGigabytes('app1'), 0.0001);
    }

    /**
     * Kegagalan baca registry **tidak** menggagalkan meteran → map kosong.
     */
    public function testRegistryReadFailureYieldsEmptyMap(): void
    {
        $usage = $this->seed([
            'app1_data' => ['app_id' => 'app1', 'bytes' => self::bytes(1.0), 'snapshots' => 1],
        ]);

        // Ganti berkas basis data dengan direktori (identitas berkas berubah) →
        // pembukaan basis data saat `read()` gagal.
        foreach ([$this->db, $this->db . '-wal', $this->db . '-shm'] as $file) {
            @unlink($file);
        }
        mkdir($this->db, 0777, true);

        $this->assertSame([], $usage->rawGigabytesByApp());
        $this->assertSame([], $usage->chargedGigabytesByApp());
        $this->assertSame(0.0, $usage->chargedGigabytes('app1'));
    }

    /**
     * Muat config temp untuk menguji pembacaan `deploy.*` (pola BillingRunnerTest).
     *
     * @param array<string,mixed> $overrides
     */
    private function useConfig(array $overrides): void
    {
        Config::clear();
        mkdir($this->tmp . '/config', 0777, true);
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export(['sqlite_file' => $this->db, 'database_path' => $this->tmp] + $overrides, true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
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
}
