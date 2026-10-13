<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupSelection;
use app\library\Billing\BackupUsage;
use app\library\Billing\Pricing;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\SqliteFixture;
use PDO;
use PDOStatement;
use app\library\Storage\SqliteDatabase;
use Webman\Config;

/**
 * Test pemetaan baris volume respons `/backups` (status & refresh) —
 * `BackupController::withScheduled()`.
 *
 * Murni data (tanpa Docker/restic/berkas runtime nyata): `BackupSelection` &
 * `BackupRegistry` diarahkan ke basis data SQLite temp, dan config dimuat dari
 * direktori temp (tarif/floor backup eksplisit) supaya hasil tidak bergantung
 * config global proses tes.
 *
 * Kontrak yang dibuktikan: `scheduled` segar per baris + `can_schedule`
 * (hak `backup` per baris) + biaya (`backup_gb`, `backup_credits_month`).
 */
class BackupControllerScheduledTest extends TestCase
{
    private const OWNER = ['id' => 'u1', 'username' => 'owner', 'role' => 'member'];
    private const OPERATOR = ['id' => 'u2', 'username' => 'operator', 'role' => 'member'];
    private const VIEWER = ['id' => 'u3', 'username' => 'viewer', 'role' => 'member'];
    private const STRANGER = ['id' => 'u8', 'username' => 'stranger', 'role' => 'member'];
    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];

    private string $tmp;
    private string $path;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backupctl_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        $this->path = $this->tmp . '/rames.sqlite';

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->path,
                'billing_enabled' => true,
                'billing_rate_backup_per_gb_month' => 1000.0,
                'billing_backup_min_gb' => 1.0,
                'billing_min_deposit_days' => 30,
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        // Registry temp (koleksi `registry` store `backup`): app1 punya snapshot
        // 2,5 GB (di atas floor), app2 0,3 GB (di-floor-kan 1 GB), volume yatim
        // tanpa `app_id` tidak dihitung.
        SqliteFixture::backup($this->path, ['registry' => [
            'tonidata_data' => ['app_id' => 'app1', 'bytes' => (int) round(2.5 * BackupUsage::BYTES_PER_GB), 'snapshots' => 3],
            'waha_data' => ['app_id' => 'app2', 'bytes' => (int) round(0.3 * BackupUsage::BYTES_PER_GB), 'snapshots' => 1],
            'ghost_data' => ['app_id' => null, 'bytes' => (int) round(9.0 * BackupUsage::BYTES_PER_GB), 'snapshots' => 1],
        ]]);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
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

    /** @return array<string,mixed> */
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

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(): array
    {
        return [
            ['name' => 'tonidata_data', 'project' => 'tonidata', 'app_id' => 'app1'],
            ['name' => 'waha_data', 'project' => 'waha', 'app_id' => 'app2'],
            ['name' => 'ghost_data', 'project' => 'ghost', 'app_id' => null, 'orphaned' => true],
        ];
    }

    /**
     * Daftar app (resolusi peran per baris): app1 milik u1 (+ u2 operator,
     * u3 viewer), app2 milik u7 tanpa share.
     *
     * @return array<int,array<string,mixed>>
     */
    private function apps(): array
    {
        return [
            [
                'id' => 'app1',
                'name' => 'tonidata',
                'owner_id' => 'u1',
                'members' => [
                    'u2' => ['role' => 'operator'],
                    'u3' => ['role' => 'viewer'],
                ],
            ],
            ['id' => 'app2', 'name' => 'waha', 'owner_id' => 'u7', 'members' => []],
        ];
    }

    private function usage(): BackupUsage
    {
        return new BackupUsage(new BackupRegistry($this->path));
    }

    public function testDefaultsOffWhenNoExplicitEntry(): void
    {
        $selection = new BackupSelection($this->path);

        $out = BackupController::withScheduled($this->rows(), $selection, $this->apps(), self::STRANGER, $this->usage());

        $this->assertCount(3, $out);
        foreach ($out as $row) {
            $this->assertFalse($row['scheduled'], 'default OFF bila tak ada entri eksplisit');
        }
    }

    public function testMergesFreshFlagAndPreservesKeysAndOrder(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', false, 'u1');
        $selection->setScheduled('waha_data', true, 'u1');

        $out = BackupController::withScheduled($this->rows(), $selection, $this->apps(), self::STRANGER, $this->usage());

        $this->assertSame('tonidata_data', $out[0]['name']);
        $this->assertSame('waha_data', $out[1]['name']);
        $this->assertSame('ghost_data', $out[2]['name']);

        $this->assertFalse($out[0]['scheduled'], 'flag OFF eksplisit terbaca');
        $this->assertTrue($out[1]['scheduled'], 'flag ON eksplisit terbaca');
        $this->assertFalse($out[2]['scheduled'], 'volume yatim tanpa entri → default OFF');

        // Kunci asli tidak hilang/berubah.
        $this->assertSame('tonidata', $out[0]['project']);
        $this->assertSame('app1', $out[0]['app_id']);
        $this->assertTrue($out[2]['orphaned']);
    }

    public function testReflectsLatestSelectionWithoutCaching(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', true, 'u1');

        $first = BackupController::withScheduled($this->rows(), $selection, $this->apps(), self::STRANGER, $this->usage());
        $this->assertTrue($first[0]['scheduled']);

        // Mutasi setelah pemetaan pertama harus terbaca pada pemetaan berikutnya
        // (tidak ada salinan basi di controller).
        $selection->setScheduled('tonidata_data', false, 'u1');
        $second = BackupController::withScheduled($this->rows(), $selection, $this->apps(), self::STRANGER, $this->usage());
        $this->assertFalse($second[0]['scheduled']);

        // Instance baru (reload dari berkas) juga konsisten.
        $reloaded = new BackupSelection($this->path);
        $third = BackupController::withScheduled($this->rows(), $reloaded, $this->apps(), self::STRANGER, $this->usage());
        $this->assertFalse($third[0]['scheduled']);
    }

    public function testEmptyRowsYieldEmptyArray(): void
    {
        $selection = new BackupSelection($this->path);

        $this->assertSame([], BackupController::withScheduled([], $selection, $this->apps(), null, $this->usage()));
    }

    /**
     * `can_schedule` = hak `backup` per baris: owner & operator boleh, viewer
     * tidak, app orang lain tidak, dan volume yatim hanya admin.
     */
    public function testCanScheduleFollowsPerRowRole(): void
    {
        $selection = new BackupSelection($this->path);
        $rows = $this->rows();
        $apps = $this->apps();
        $usage = $this->usage();

        $owner = BackupController::withScheduled($rows, $selection, $apps, self::OWNER, $usage);
        $this->assertTrue($owner[0]['can_schedule'], 'owner app1 boleh');
        $this->assertFalse($owner[1]['can_schedule'], 'app2 bukan miliknya');
        $this->assertFalse($owner[2]['can_schedule'], 'volume yatim bukan admin');

        $operator = BackupController::withScheduled($rows, $selection, $apps, self::OPERATOR, $usage);
        $this->assertTrue($operator[0]['can_schedule'], 'operator (share) boleh');

        $viewer = BackupController::withScheduled($rows, $selection, $apps, self::VIEWER, $usage);
        $this->assertFalse($viewer[0]['can_schedule'], 'viewer tidak boleh');

        $stranger = BackupController::withScheduled($rows, $selection, $apps, self::STRANGER, $usage);
        $this->assertFalse($stranger[0]['can_schedule'], 'member lain tidak boleh');

        $admin = BackupController::withScheduled($rows, $selection, $apps, self::ADMIN, $usage);
        $this->assertTrue($admin[0]['can_schedule']);
        $this->assertTrue($admin[1]['can_schedule']);
        $this->assertTrue($admin[2]['can_schedule'], 'admin boleh volume yatim');
    }

    /**
     * Biaya per baris: GB ditagih (floor) + estimasi kredit per bulan; baris
     * tanpa snapshot/yatim = 0.
     */
    public function testBackupCostFieldsPerRow(): void
    {
        $selection = new BackupSelection($this->path);

        $out = BackupController::withScheduled($this->rows(), $selection, $this->apps(), self::ADMIN, $this->usage());

        $this->assertEqualsWithDelta(2.5, $out[0]['backup_gb'], 0.0001, 'app 2,5 GB ditagih apa adanya');
        $this->assertEqualsWithDelta(1.0, $out[1]['backup_gb'], 0.0001, 'app 0,3 GB di-floor-kan 1 GB');
        $this->assertSame(0.0, $out[2]['backup_gb'], 'volume yatim (tanpa app) tanpa biaya');

        $this->assertSame(Pricing::backupMonthlyEstimate(2.5), $out[0]['backup_credits_month']);
        $this->assertSame(Pricing::backupMonthlyEstimate(1.0), $out[1]['backup_credits_month']);
        $this->assertSame(0.0, $out[2]['backup_credits_month']);
    }

    /**
     * Tanpa konteks user (`null`, pemanggil lama) `can_schedule` = false (server
     * tetap sumber kebenaran), tetapi kunci tetap ada & `scheduled` tetap diisi.
     */
    public function testUserlessCallYieldsFalseCanScheduleButKeepsKeys(): void
    {
        $selection = new BackupSelection($this->path);

        $out = BackupController::withScheduled($this->rows(), $selection, $this->apps(), null, $this->usage());

        foreach ($out as $row) {
            $this->assertArrayHasKey('can_schedule', $row);
            $this->assertArrayHasKey('backup_gb', $row);
            $this->assertArrayHasKey('backup_credits_month', $row);
            $this->assertArrayHasKey('scheduled', $row);
            $this->assertFalse($row['can_schedule']);
        }
    }

    /**
     * N+1: peta GB dibaca **sekali** per pemanggilan, bukan sekali per baris.
     *
     * Bukti deterministik (tanpa benchmark waktu): PDO uji dipasang pada handle
     * berkas temp ini dan menghitung setiap `SELECT … FROM "backup_registry"`.
     * Sebelum perbaikan (`chargedGigabytes()` per baris) 400 baris ⇒ 400
     * pembacaan; sesudah perbaikan ⇒ 1.
     */
    public function testRegistryIsReadOnceForManyRows(): void
    {
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $rows[] = [
                'name' => 'vol' . $i,
                'project' => $i % 2 === 0 ? 'tonidata' : 'waha',
                'app_id' => $i % 2 === 0 ? 'app1' : 'app2',
            ];
        }

        $selection = new BackupSelection($this->path);
        // Panaskan memo `BackupSelection` sebelum PDO penghitung dipasang:
        // seleksi memakai store `backup` yang **sama** (baca koleksi `volumes`
        // + `registry` sekaligus), jadi pembacaan pertamanya ikut menyentuh
        // tabel registry dan akan mengaburkan angka. Setelah dipanaskan, tiap
        // pembacaan registry berasal dari perhitungan GB saja.
        $selection->isScheduled('__warmup__');

        $apps = $this->apps();
        $usage = $this->usage();

        [$out, $reads] = $this->countRegistryReads(
            fn (): array => BackupController::withScheduled($rows, $selection, $apps, self::ADMIN, $usage)
        );

        $this->assertSame(1, $reads, 'registry dibaca SEKALI untuk 400 baris');
        $this->assertCount(400, $out);

        foreach ($out as $index => $row) {
            // app1 = 2,5 GB apa adanya; app2 = 0,3 GB di-floor-kan 1 GB.
            $expected = $index % 2 === 0 ? 2.5 : 1.0;
            $this->assertEqualsWithDelta($expected, $row['backup_gb'], 0.0001, "biaya baris {$index} tetap benar");
            $this->assertSame(Pricing::backupMonthlyEstimate($expected), $row['backup_credits_month']);
        }
    }

    /**
     * Tanpa baris, registry tidak disentuh sama sekali (pembacaan lazy) —
     * perilaku sebelum & sesudah perbaikan tetap sama.
     */
    public function testRegistryIsUntouchedForEmptyRows(): void
    {
        $selection = new BackupSelection($this->path);
        $apps = $this->apps();
        $usage = $this->usage();

        [$out, $reads] = $this->countRegistryReads(
            fn (): array => BackupController::withScheduled([], $selection, $apps, self::ADMIN, $usage)
        );

        $this->assertSame([], $out);
        $this->assertSame(0, $reads, 'daftar baris kosong tidak menyentuh registry');
    }

    /**
     * Jalankan `$fn` dengan PDO penghitung terpasang pada handle berkas temp ini
     * (kunci handle = identitas berkas, sama dengan `SqliteDatabase::pdo()`),
     * lalu kembalikan `[hasil, jumlah pembacaan tabel registry]`.
     *
     * Handle lama dipasang kembali setelah `$fn` selesai agar tidak bocor ke
     * pengujian berikutnya dalam proses yang sama.
     *
     * @param callable():mixed $fn
     * @return array{0:mixed,1:int}
     */
    private function countRegistryReads(callable $fn): array
    {
        $prop = new ReflectionProperty(SqliteDatabase::class, 'handles');
        $prop->setAccessible(true);

        $key = SqliteDatabase::identityOf($this->path);
        $handles = (array) $prop->getValue();
        $previous = $handles[$key] ?? null;

        $spy = new RegistryCountingPdo('sqlite:' . $this->path);
        $spy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $spy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $spy->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $spy->exec('PRAGMA busy_timeout = 5000');

        $handles[$key] = $spy;
        $prop->setValue(null, $handles);

        try {
            $result = $fn();
        } finally {
            $handles = (array) $prop->getValue();
            if ($previous !== null) {
                $handles[$key] = $previous;
            } else {
                unset($handles[$key]);
            }
            $prop->setValue(null, $handles);
        }

        return [$result, $spy->registryReads];
    }
}

/**
 * PDO uji yang menghitung pembacaan tabel registry backup (SQL identik dengan
 * `SqliteStore::readCollection()`), tanpa mengubah koneksi lain. Dipakai sebagai
 * bukti jumlah pembacaan `BackupRegistry::read()` pada `withScheduled()`.
 */
final class RegistryCountingPdo extends PDO
{
    public int $registryReads = 0;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'FROM "backup_registry"')) {
            $this->registryReads++;
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
