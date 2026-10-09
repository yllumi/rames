<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\JsonImporter;
use app\library\Storage\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Webman\Config;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test safety net impor otomatis JSON lama → SQLite saat koneksi DB pertama
 * ({@see SqliteDatabase}). Semua path di temp: `runtime/` nyata tidak tersentuh
 * (log kegagalan diarahkan ke runtime temp lewat `app.runtime_path`).
 */
class LegacyAutoImportTest extends TestCase
{
    private string $tmp;
    private string $legacyDir;
    private string $dbFile;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/legacyauto_' . bin2hex(random_bytes(4));
        $this->legacyDir = $this->tmp . '/legacy';
        mkdir($this->legacyDir, 0777, true);
        mkdir($this->tmp . '/runtime', 0777, true);
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/db', 0777, true);
        $this->dbFile = $this->tmp . '/db/rames.sqlite';

        $this->configState = $this->snapshotConfigState();
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        SqliteDatabase::reset();
        self::removeTree($this->tmp);
    }

    public function testFirstConnectionImportsLegacyJson(): void
    {
        $this->putLegacy('apps.json', [
            ['id' => 'a1', 'name' => 'alfa', 'owner_id' => 'u1', 'status' => 'running'],
            ['id' => 'a2', 'name' => 'beta', 'owner_id' => 'u2', 'status' => 'stopped'],
        ]);
        $this->putLegacy('auth.json', [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin'],
            ['id' => 'u2', 'username' => 'bob', 'password_hash' => 'y', 'role' => 'member'],
        ]);

        $this->loadConfig(true);

        $pdo = (new SqliteDatabase($this->dbFile))->pdo();

        $this->assertSame(2, $this->countRows($pdo, 'apps_apps'));
        $this->assertSame(2, $this->countRows($pdo, 'auth_users'));
        $this->assertNotNull($this->importedFlag($pdo), 'flag legacy_imported_at terpasang');
    }

    public function testRepeatedConnectionsAreIdempotent(): void
    {
        $this->putLegacy('apps.json', [['id' => 'a1', 'name' => 'alfa']]);
        $this->loadConfig(true);

        $first = (new SqliteDatabase($this->dbFile))->pdo();
        $this->assertSame(1, $this->countRows($first, 'apps_apps'));

        // Koneksi baru (proses baru) → auto-impor dipanggil lagi tapi idempoten.
        SqliteDatabase::reset();
        $second = (new SqliteDatabase($this->dbFile))->pdo();
        $this->assertSame(1, $this->countRows($second, 'apps_apps'));
        $this->assertSame(
            1,
            $this->countRows($second, 'apps_apps'),
            'hitungan tidak bertambah setelah koneksi berulang'
        );

        // Impor eksplisit pun melaporkan skipped.
        $summary = (new JsonImporter($this->legacyDir, $this->dbFile))->importIfNeeded($this->dbFile);
        $this->assertTrue($summary['skipped']);
    }

    public function testBrokenLegacyJsonDoesNotBreakConnectionAndLogsFailure(): void
    {
        file_put_contents($this->legacyDir . '/apps.json', '{ini bukan json');
        $this->loadConfig(true);

        // Tidak boleh melempar keluar dari koneksi.
        $pdo = (new SqliteDatabase($this->dbFile))->pdo();
        $this->assertSame(0, $this->countRows($pdo, 'apps_apps'));

        // DB tetap bisa dipakai.
        $pdo->exec('CREATE TABLE IF NOT EXISTS "probe" ("id" INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO "probe" ("id") VALUES (1)');
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM "probe"')->fetchColumn());

        // Kegagalan tercatat di log runtime temp.
        $logs = glob($this->tmp . '/runtime/logs/storage/*.log') ?: [];
        $this->assertNotEmpty($logs, 'log kegagalan impor tertulis');
        $this->assertStringContainsString(
            'impor otomatis JSON lama gagal',
            (string) file_get_contents($logs[0])
        );
        $this->assertNull($this->importedFlag($pdo), 'flag tidak terpasang saat impor gagal');
    }

    public function testAutoImportDisabledDoesNothing(): void
    {
        $this->putLegacy('apps.json', [['id' => 'a1', 'name' => 'alfa']]);
        $this->loadConfig(false);

        $pdo = (new SqliteDatabase($this->dbFile))->pdo();

        $this->assertSame(0, $this->countRows($pdo, 'apps_apps'));
        $this->assertNull($this->importedFlag($pdo));
    }

    public function testAutoImportSkippedWhenKeyAbsentFromConfig(): void
    {
        $this->putLegacy('apps.json', [['id' => 'a1', 'name' => 'alfa']]);
        $this->loadConfig(null); // kunci tidak ada → default perilaku: jangan impor

        $pdo = (new SqliteDatabase($this->dbFile))->pdo();

        $this->assertSame(0, $this->countRows($pdo, 'apps_apps'));
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /** @param array<int,array<string,mixed>> $data */
    private function putLegacy(string $name, array $data): void
    {
        file_put_contents(
            $this->legacyDir . '/' . $name,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Muat config temp (app.php + deploy.php) seperti runtime.
     *
     * @param bool|null $autoImport nilai `db_import_legacy_json` (null = kunci tak ada)
     */
    private function loadConfig(?bool $autoImport): void
    {
        $app = ['runtime_path' => $this->tmp . '/runtime'];
        file_put_contents($this->tmp . '/config/app.php', "<?php\n\nreturn " . var_export($app, true) . ";\n");

        $deploy = [
            'database_path' => $this->legacyDir,
            'sqlite_file' => $this->dbFile,
        ];
        if ($autoImport !== null) {
            $deploy['db_import_legacy_json'] = $autoImport;
        }
        file_put_contents($this->tmp . '/config/deploy.php', "<?php\n\nreturn " . var_export($deploy, true) . ";\n");

        Config::load($this->tmp . '/config');
    }

    private function countRows(PDO $pdo, string $table): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
    }

    private function importedFlag(PDO $pdo): ?string
    {
        $stmt = $pdo->prepare('SELECT "value" FROM "kv" WHERE "key" = ?');
        $stmt->execute(['legacy_imported_at']);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
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
