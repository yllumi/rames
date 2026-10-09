<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteDatabase;
use app\library\Storage\SqliteStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test {@see SqliteDatabase} — PRAGMA, migrasi, transaksi, snapshot, integrity.
 * Semua di path temp (`sys_get_temp_dir()`), tidak menyentuh database/ nyata.
 */
class SqliteDatabaseTest extends TestCase
{
    private string $tmp;
    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/sqlitedb_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
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

    public function testPragmasAppliedOnConnect(): void
    {
        $db = new SqliteDatabase($this->file);
        $pdo = $db->pdo();

        $this->assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        $this->assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));

        $this->assertSame($this->file, $db->file());
        $this->assertFileExists($this->file);
    }

    public function testPdoIsCachedPerFilePerProcess(): void
    {
        $a = new SqliteDatabase($this->file);
        $b = new SqliteDatabase($this->file);
        $this->assertSame($a->pdo(), $b->pdo(), 'satu handle per berkas per proses');

        $first = $a->pdo();
        SqliteDatabase::reset();
        $c = new SqliteDatabase($this->file);
        $this->assertNotSame($first, $c->pdo(), 'reset() memaksa koneksi baru');
    }

    public function testMigrationsIdempotentAndRecordedOnce(): void
    {
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $expected = count(SchemaMigrations::migrations());
        $this->assertSame($expected, SchemaMigrations::migrate($pdo), 'run pertama menerapkan semua migrasi');
        $this->assertSame(0, SchemaMigrations::migrate($pdo), 'run kedua tidak menerapkan apa pun');

        $rows = $pdo->query('SELECT "version" FROM "schema_migrations"')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount($expected, $rows);
        $this->assertSame([1], array_map('intval', $rows));
        $this->assertSame(SchemaMigrations::LATEST, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
    }

    public function testDerivedColumnsAndIndexesExist(): void
    {
        $pdo = (new SqliteDatabase($this->file))->pdo();

        $table = SqliteStore::tableName('apps', 'apps');
        $columns = array_column($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['id', 'ord', 'data', 'updated_at', 'name', 'owner_id', 'status', 'source', 'subdomain'] as $column) {
            $this->assertContains($column, $columns, "kolom {$column} ada");
        }

        $indexes = array_column($pdo->query('PRAGMA index_list("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['idx_apps_apps_name', 'idx_apps_apps_owner_id', 'idx_apps_apps_subdomain'] as $index) {
            $this->assertContains($index, $indexes, "indeks {$index} ada");
        }

        // Tabel store lain juga dibuat oleh migrasi.
        $billing = SqliteStore::tableName('billing', 'orders');
        $orderCols = array_column($pdo->query('PRAGMA table_info("' . $billing . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertContains('user_id', $orderCols);
        $this->assertContains('status', $orderCols);
    }

    public function testTransactionCommitAndRollback(): void
    {
        $db = new SqliteDatabase($this->file);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS "probe" ("id" INTEGER PRIMARY KEY, "v" TEXT)');

        $db->transaction(function () use ($pdo): void {
            $pdo->exec("INSERT INTO \"probe\" (\"id\",\"v\") VALUES (1,'a')");
        });
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM "probe"')->fetchColumn());

        try {
            $db->transaction(function () use ($pdo): void {
                $pdo->exec("INSERT INTO \"probe\" (\"id\",\"v\") VALUES (2,'b')");
                throw new RuntimeException('gagal');
            });
            $this->fail('exception harus diteruskan');
        } catch (RuntimeException) {
            // diharapkan
        }
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM "probe"')->fetchColumn(), 'rollback penuh');
    }

    public function testNestedTransactionReusesOuter(): void
    {
        $db = new SqliteDatabase($this->file);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS "probe" ("id" INTEGER PRIMARY KEY)');

        $db->transaction(function () use ($db, $pdo): void {
            $this->assertTrue($db->inTransaction());
            $pdo->exec('INSERT INTO "probe" ("id") VALUES (1)');
            // nested: memakai transaksi luar (tanpa BEGIN kedua)
            $db->transaction(function () use ($pdo): void {
                $pdo->exec('INSERT INTO "probe" ("id") VALUES (2)');
            });
            $this->assertTrue($db->inTransaction());
        });

        $this->assertFalse($db->inTransaction());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM "probe"')->fetchColumn());
    }

    public function testSnapshotProducesValidDatabase(): void
    {
        $db = new SqliteDatabase($this->file);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS "probe" ("id" INTEGER PRIMARY KEY, "v" TEXT)');
        $pdo->exec("INSERT INTO \"probe\" VALUES (1,'a')");

        $to = $this->tmp . '/nested/snapshot.sqlite';
        $db->snapshot($to);

        $this->assertFileExists($to);
        $copy = new PDO('sqlite:' . $to);
        $copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->assertSame('ok', $copy->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('a', (string) $copy->query('SELECT "v" FROM "probe" WHERE "id" = 1')->fetchColumn());
    }

    public function testIntegrityCheck(): void
    {
        $db = new SqliteDatabase($this->file);
        $this->assertTrue($db->integrityCheck());
    }

    public function testTwoHandlesWriteSequentiallyWithoutBusy(): void
    {
        $db1 = new SqliteDatabase($this->file);
        $pdo1 = $db1->pdo();
        $pdo1->exec('CREATE TABLE IF NOT EXISTS "probe" ("id" INTEGER PRIMARY KEY, "v" TEXT)');
        $pdo1->exec("INSERT INTO \"probe\" VALUES (1,'a')");

        // handle kedua ke berkas yang sama (setelah cache dibuang)
        SqliteDatabase::reset();
        $db2 = new SqliteDatabase($this->file);
        $pdo2 = $db2->pdo();
        $this->assertNotSame($pdo1, $pdo2);
        $pdo2->exec("INSERT INTO \"probe\" VALUES (2,'b')");

        $count = (int) $pdo2->query('SELECT COUNT(*) FROM "probe"')->fetchColumn();
        $this->assertSame(2, $count, 'tidak ada lost update');
    }

    public function testNewDatabaseFileIsChmodded(): void
    {
        (new SqliteDatabase($this->file))->pdo();
        $mode = substr(sprintf('%o', fileperms($this->file)), -3);
        $this->assertSame('640', $mode, 'berkas DB baru di-chmod 0640');
    }
}
