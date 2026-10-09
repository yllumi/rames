<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\DbBackup;
use app\library\Storage\SqliteDatabase;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

// Helper `runtime_path()`/`base_path()` webman membaca konstanta BASE_PATH yang
// tidak ada di luar runtime; didefinisikan di sini tanpa menyentuh data runtime.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test {@see DbBackup} — snapshot, retensi, isDue, restore defensif, state.
 * Semua path diarahkan ke direktori temp; `state.json` lewat env
 * `RAMES_DB_BACKUP_STATE` sehingga `runtime/` nyata tidak pernah tersentuh.
 */
class DbBackupTest extends TestCase
{
    private string $tmp;
    private string $dbFile;
    private string $backupDir;
    private string $stateFile;

    /** @var string|false nilai env sebelum test */
    private string|false $savedStateEnv;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/dbbackup_' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/db', 0777, true);
        mkdir($this->tmp . '/backup', 0777, true);
        mkdir($this->tmp . '/runtime', 0777, true);
        mkdir($this->tmp . '/config', 0777, true);

        $this->dbFile = $this->tmp . '/db/rames.sqlite';
        $this->backupDir = $this->tmp . '/backup';
        $this->stateFile = $this->tmp . '/runtime/db-backup/state.json';

        $this->configState = $this->snapshotConfigState();

        $this->savedStateEnv = getenv('RAMES_DB_BACKUP_STATE');
        putenv('RAMES_DB_BACKUP_STATE=' . $this->stateFile);

        $this->loadConfig($this->defaults());
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        if ($this->savedStateEnv === false) {
            putenv('RAMES_DB_BACKUP_STATE');
        } else {
            putenv('RAMES_DB_BACKUP_STATE=' . $this->savedStateEnv);
        }

        SqliteDatabase::reset();
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // Snapshot + state
    // ==================================================================

    public function testRunCreatesValidSnapshotAndWritesState(): void
    {
        $this->seedApps(2);

        $backup = $this->newBackup();
        $entry = $backup->run('manual');

        $this->assertMatchesRegularExpression('/^rames-\d{8}-\d{6}\.sqlite$/', $entry['file']);
        $this->assertFileExists($this->backupDir . '/' . $entry['file']);
        foreach (['file', 'path', 'bytes', 'at', 'mtime'] as $key) {
            $this->assertArrayHasKey($key, $entry);
        }

        // Snapshot = basis data valid dengan jumlah baris tabel inti yang sama.
        $snapshot = new PDO('sqlite:' . $entry['path']);
        $snapshot->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->assertSame('ok', strtolower((string) $snapshot->query('PRAGMA integrity_check')->fetchColumn()));
        $this->assertSame(2, (int) $snapshot->query('SELECT COUNT(*) FROM "apps_apps"')->fetchColumn());

        // state.json terisi di path override (bukan runtime nyata).
        $this->assertFileExists($this->stateFile);
        $state = $backup->state();
        $this->assertSame($entry['file'], $state['last_file']);
        $this->assertSame('manual', $state['last_reason']);
        $this->assertNull($state['error']);
        $this->assertCount(1, $state['runs']);
        $this->assertSame('manual', $state['runs'][0]['reason']);
    }

    public function testRunFailureRecordsErrorWithoutCreatingSnapshot(): void
    {
        file_put_contents($this->tmp . '/blocked', 'x');
        $backup = new DbBackup($this->dbFile, $this->tmp . '/blocked');

        try {
            $backup->run('manual');
            $this->fail('run() harus melempar saat direktori backup tidak bisa dibuat.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $state = $backup->state();
        $this->assertNotNull($state['error'], 'kegagalan dicatat di state');
        $this->assertCount(1, $state['runs']);
        $this->assertNull($state['runs'][0]['file']);
    }

    public function testListNewestFirstAndLatest(): void
    {
        $this->touchSnapshot('rames-20260101-030000.sqlite');
        $this->touchSnapshot('frames-ignored.sqlite');
        $this->touchSnapshot('rames-20260103-030000.sqlite');
        $this->touchSnapshot('rames-20260102-030000.sqlite');

        $backup = $this->newBackup();
        $list = $backup->list();

        $this->assertCount(3, $list, 'hanya rames-* yang dikelola');
        $this->assertSame('rames-20260103-030000.sqlite', $list[0]['file']);
        $this->assertSame('rames-20260101-030000.sqlite', $list[2]['file']);
        $this->assertSame($list[0]['file'], $backup->latest()['file'] ?? null);
    }

    public function testUniqueSnapshotNameDoesNotOverwrite(): void
    {
        $method = new ReflectionMethod(DbBackup::class, 'uniquePath');
        $method->setAccessible(true);

        $backup = $this->newBackup();
        $first = (string) $method->invoke($backup, 'rames-');
        file_put_contents($first, 'x');
        $second = (string) $method->invoke($backup, 'rames-');

        $this->assertNotSame($first, $second, 'nama bentrok harus diberi sufiks unik');
        $this->assertFileExists($first);
    }

    public function testRunIsSkippedWhenLockIsHeld(): void
    {
        $lock = fopen($this->backupDir . '/.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'kunci uji harus didapat');

        try {
            $backup = $this->newBackup();
            $entry = $backup->run('manual');

            $this->assertTrue($entry['skipped'], 'run kedua dilewati');
            $this->assertSame('busy', $entry['reason']);
            $this->assertNull($entry['file']);
            $this->assertSame([], glob($this->backupDir . '/rames-*.sqlite') ?: [], 'tidak ada snapshot baru');

            // state melaporkan "dilewati", BUKAN error.
            $state = $backup->state();
            $this->assertSame('busy', $state['last_skip_reason']);
            $this->assertNotNull($state['last_skipped_at']);
            $this->assertNull($state['error']);

            // prune juga melewati saat kunci dipegang.
            $this->assertTrue((bool) ($backup->prune()['skipped'] ?? false));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // Setelah kunci dilepas, run normal lagi.
        $entry = $this->newBackup()->run('manual');
        $this->assertFalse($entry['skipped']);
        $this->assertFileExists($this->backupDir . '/' . $entry['file']);
    }

    public function testRestoreFailsWhenLockIsHeld(): void
    {
        $entry = $this->newBackup()->run('manual');

        $lock = fopen($this->backupDir . '/.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->assertRuntimeException(fn () => $this->newBackup()->restore($entry['file']));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ==================================================================
    // Retensi
    // ==================================================================

    public function testPruneHonorsDailyPolicyAndIsIdempotent(): void
    {
        $this->loadConfig($this->defaults([
            'db_backup_keep_daily' => 2,
            'db_backup_keep_weekly' => 0,
            'db_backup_keep_monthly' => 0,
        ]));

        foreach ([
            'rames-20260105-030000.sqlite',
            'rames-20260105-150000.sqlite',
            'rames-20260106-030000.sqlite',
            'rames-20260106-150000.sqlite',
            'rames-20260107-030000.sqlite',
            'rames-20260107-150000.sqlite',
        ] as $name) {
            $this->touchSnapshot($name);
        }

        $backup = $this->newBackup();
        $this->assertSame(['removed' => 4, 'kept' => 2], $backup->prune());

        // Simpan snapshot TERBARU per hari untuk 2 hari terbaru.
        $this->assertFileExists($this->backupDir . '/rames-20260107-150000.sqlite');
        $this->assertFileExists($this->backupDir . '/rames-20260106-150000.sqlite');
        $this->assertFileDoesNotExist($this->backupDir . '/rames-20260105-150000.sqlite');
        $this->assertFileDoesNotExist($this->backupDir . '/rames-20260107-030000.sqlite');

        $this->assertSame(['removed' => 0, 'kept' => 2], $backup->prune(), 'idempoten');
    }

    public function testPruneHonorsWeeklyAndMonthlyPolicies(): void
    {
        $this->loadConfig($this->defaults([
            'db_backup_keep_daily' => 0,
            'db_backup_keep_weekly' => 1,
            'db_backup_keep_monthly' => 0,
        ]));

        // 2026-01-06 & 2026-01-07 = ISO week W02; 2026-01-13 = W03.
        foreach ([
            'rames-20260106-030000.sqlite',
            'rames-20260107-030000.sqlite',
            'rames-20260113-030000.sqlite',
        ] as $name) {
            $this->touchSnapshot($name);
        }
        $this->assertSame(['removed' => 2, 'kept' => 1], $this->newBackup()->prune());
        $this->assertFileExists($this->backupDir . '/rames-20260113-030000.sqlite');

        // Ganti ke kebijakan bulanan saja: sisakan bulan terbaru.
        $this->loadConfig($this->defaults([
            'db_backup_keep_daily' => 0,
            'db_backup_keep_weekly' => 0,
            'db_backup_keep_monthly' => 1,
        ]));
        $this->touchSnapshot('rames-20260205-030000.sqlite');
        $this->touchSnapshot('rames-20260220-030000.sqlite');
        // Tersisa 3 berkas (20260113, 20260205, 20260220) → hanya terbaru di bulan 2026-02.
        $this->assertSame(['removed' => 2, 'kept' => 1], $this->newBackup()->prune());
        $this->assertFileExists($this->backupDir . '/rames-20260220-030000.sqlite');
        $this->assertFileDoesNotExist($this->backupDir . '/rames-20260205-030000.sqlite');
    }

    public function testPruneWithZeroPoliciesKeepsNewestOnly(): void
    {
        $this->loadConfig($this->defaults([
            'db_backup_keep_daily' => 0,
            'db_backup_keep_weekly' => 0,
            'db_backup_keep_monthly' => 0,
        ]));

        foreach ([
            'rames-20260101-030000.sqlite',
            'rames-20260102-030000.sqlite',
            'rames-20260103-030000.sqlite',
        ] as $name) {
            $this->touchSnapshot($name);
        }

        $this->assertSame(['removed' => 2, 'kept' => 1], $this->newBackup()->prune());
        $this->assertFileExists($this->backupDir . '/rames-20260103-030000.sqlite');
    }

    public function testPruneNeverDeletesSafetySnapshots(): void
    {
        $this->touchSnapshot('rames-20260101-030000.sqlite');
        $this->touchSnapshot('pre-restore-20260101-040000.sqlite');

        $this->newBackup()->prune();

        $this->assertFileExists($this->backupDir . '/pre-restore-20260101-040000.sqlite');
        $this->assertFileExists($this->backupDir . '/rames-20260101-030000.sqlite');
    }

    // ==================================================================
    // isDue
    // ==================================================================

    public function testIsDueRespectsEnabledHourAndAlreadyRun(): void
    {
        $tz = new DateTimeZone('Asia/Jakarta');
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

        $backup = $this->newBackup();
        $this->assertFalse($backup->isDue($today . ' 02:59:00'), 'sebelum jam jadwal');
        $this->assertTrue($backup->isDue($today . ' 03:00:00'), 'belum jalan hari ini');

        $backup->run('manual');
        $this->assertFalse($backup->isDue($today . ' 05:00:00'), 'sudah jalan hari ini');
        $this->assertTrue($backup->isDue('2099-01-01 03:30:00'), 'hari berbeda → due lagi');

        $this->loadConfig($this->defaults(['db_backup_enabled' => false]));
        $this->assertFalse($this->newBackup()->isDue('2099-01-01 03:30:00'), 'fitur dimatikan');
    }

    public function testIsDueFalseWhenSnapshotExistsToday(): void
    {
        $tz = new DateTimeZone('Asia/Jakarta');
        $today = (new DateTimeImmutable('now', $tz))->format('Ymd');
        $this->touchSnapshot('rames-' . $today . '-010101.sqlite');

        $this->assertFalse(
            $this->newBackup()->isDue((new DateTimeImmutable('now', $tz))->format('Y-m-d') . ' 05:00:00')
        );
    }

    // ==================================================================
    // Restore — penolakan (fail-fast)
    // ==================================================================

    public function testRestoreRejectsUnsafeNamesAndMissingFiles(): void
    {
        $backup = $this->newBackup();
        $this->touchSnapshot('rames-20260101-030000.sqlite');

        foreach ([
            '../rames-20260101-030000.sqlite',
            'sub/rames-20260101-030000.sqlite',
            'rames-20260101-030000.sqlite/../x.sqlite',
            'a..sqlite',
            'missing.sqlite',
            'no-extension',
        ] as $bad) {
            $this->assertRuntimeException(static fn () => $backup->restore($bad), $bad);
        }
    }

    public function testRestoreRejectsNonSqliteAndMissingCoreTables(): void
    {
        $backup = $this->newBackup();

        $this->touchSnapshot('rames-20260101-030000.sqlite', 'bukan basis data');
        $this->assertRuntimeException(fn () => $backup->restore('rames-20260101-030000.sqlite'));

        // SQLite valid tapi tanpa tabel inti.
        $plain = $this->backupDir . '/rames-20260102-030000.sqlite';
        $pdo = new PDO('sqlite:' . $plain);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE "foo" ("id" INTEGER)');
        $pdo = null;
        $this->assertRuntimeException(fn () => $backup->restore('rames-20260102-030000.sqlite'));
    }

    // ==================================================================
    // Restore — sukses
    // ==================================================================

    public function testRestoreReplacesDatabaseAndKeepsSafetySnapshot(): void
    {
        $this->seedApps(1);
        $backup = $this->newBackup();
        $entry = $backup->run('manual');

        $this->seedApps(3); // DB aktif kini 3 baris, snapshot masih 1 baris
        $this->assertSame(3, $this->rowCount());

        $result = $backup->restore($entry['file']);
        $this->assertTrue($result['restored']);
        $this->assertSame($entry['file'], $result['file']);
        $this->assertGreaterThan(0, $result['bytes']);

        SqliteDatabase::reset();
        $this->assertSame(1, $this->rowCount(), 'DB terpulihkan dari snapshot');

        // Snapshot sumber tidak dihapus; safety snapshot dibuat.
        $this->assertFileExists($entry['path']);
        $this->assertCount(1, glob($this->backupDir . '/pre-restore-*.sqlite') ?: []);

        $state = $backup->state();
        $this->assertSame($entry['file'], $state['last_restore_from']);
        $this->assertNotNull($state['last_restore_at']);
    }

    // ==================================================================
    // download
    // ==================================================================

    public function testDownloadReturnsValidatedAbsolutePath(): void
    {
        $path = $this->touchSnapshot('rames-20260101-030000.sqlite');
        $backup = $this->newBackup();

        $this->assertSame(realpath($path), $backup->download('rames-20260101-030000.sqlite'));
        $this->assertRuntimeException(fn () => $backup->download('../secret.sqlite'));
        $this->assertRuntimeException(fn () => $backup->download('nope.sqlite'));
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function newBackup(): DbBackup
    {
        return new DbBackup($this->dbFile, $this->backupDir);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function defaults(array $overrides = []): array
    {
        return array_merge([
            'db_backup_enabled' => true,
            'db_backup_hour' => 3,
            'db_backup_keep_daily' => 7,
            'db_backup_keep_weekly' => 4,
            'db_backup_keep_monthly' => 3,
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $deploy
     */
    private function loadConfig(array $deploy): void
    {
        $app = ['runtime_path' => $this->tmp . '/runtime'];
        file_put_contents($this->tmp . '/config/app.php', "<?php\n\nreturn " . var_export($app, true) . ";\n");
        file_put_contents($this->tmp . '/config/deploy.php', "<?php\n\nreturn " . var_export($deploy, true) . ";\n");
        Config::load($this->tmp . '/config');
    }

    private function seedApps(int $count): void
    {
        $pdo = (new SqliteDatabase($this->dbFile))->pdo();
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO "apps_apps" ("id","data","updated_at") VALUES (?,?,?)');
        for ($i = 1; $i <= $count; $i++) {
            $stmt->execute(['app-' . $i, json_encode(['id' => 'app-' . $i]), date('c')]);
        }
    }

    private function rowCount(): int
    {
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return (int) $pdo->query('SELECT COUNT(*) FROM "apps_apps"')->fetchColumn();
    }

    private function touchSnapshot(string $name, string $content = 'snapshot'): string
    {
        $path = $this->backupDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function assertRuntimeException(callable $fn, string $hint = ''): void
    {
        try {
            $fn();
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Diharapkan RuntimeException' . ($hint !== '' ? " untuk {$hint}" : '') . '.');
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
}
