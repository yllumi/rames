<?php
declare(strict_types=1);

namespace app\library\Storage;

use PDO;
use Throwable;

/**
 * Skema & migrasi basis data SQLite (fondasi migrasi JSON → SQLite).
 *
 * Menyimpan dua hal:
 *  1. daftar migrasi berversi (`schema_migrations`), dijalankan otomatis oleh
 *     {@see SqliteDatabase} sekali per proses per berkas;
 *  2. definisi kanonik koleksi tiap store logis — satu sumber untuk pembuatan
 *     tabel (DDL) maupun penamaan tabel fisik.
 *
 * Nama tabel fisik diberi prefiks store (`{store}_{table}`) supaya store
 * berbeda yang memakai nama koleksi sama (mis. `users` di store `auth` dan
 * `billing`) tidak bertabrakan di satu berkas .sqlite.
 */
final class SchemaMigrations
{
    /** Versi skema terakhir yang dikenal. */
    public const LATEST = 1;

    /**
     * Daftar migrasi berversi. Kunci = nomor versi (menaik), nilai = callable.
     *
     * @return array<int,callable(PDO):void>
     */
    public static function migrations(): array
    {
        return [
            1 => static function (PDO $pdo): void {
                self::createMetaTables($pdo);
                foreach (self::storeDefinitions() as $store => $collections) {
                    foreach ($collections as $collection) {
                        self::ensure($pdo, $store, $collection);
                    }
                }
            },
        ];
    }

    /**
     * Jalankan migrasi yang tertunda. Idempoten & aman multi-proses
     * (`BEGIN IMMEDIATE` menserialkan penulis).
     *
     * @return int jumlah migrasi yang dijalankan
     */
    public static function migrate(PDO $pdo): int
    {
        $pending = [];

        // Seluruh langkah (buat tabel meta → baca versi → terapkan) dilakukan
        // DI DALAM satu `BEGIN IMMEDIATE`. Penting: pembacaan versi HARUS di
        // dalam kunci tulis — kalau dibaca sebelum BEGIN, dua proses paralel
        // sama-sama menganggap migrasi belum jalan lalu bentrok
        // `UNIQUE constraint failed: schema_migrations.version`.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS "schema_migrations" '
                . '("version" INTEGER PRIMARY KEY, "applied_at" TEXT NOT NULL)'
            );

            $applied = array_map(
                static fn ($v): int => (int) $v,
                $pdo->query('SELECT "version" FROM "schema_migrations"')->fetchAll(PDO::FETCH_COLUMN)
            );

            foreach (self::migrations() as $version => $migration) {
                if (!in_array($version, $applied, true)) {
                    $pending[$version] = $migration;
                }
            }

            foreach ($pending as $version => $migration) {
                $migration($pdo);
                $stmt = $pdo->prepare('INSERT INTO "schema_migrations" ("version", "applied_at") VALUES (?, ?)');
                $stmt->execute([$version, date('c')]);
            }
            if ($pending !== []) {
                $pdo->exec('PRAGMA user_version = ' . self::LATEST);
            }

            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // koneksi sudah tidak dalam transaksi — abaikan
            }
            throw $e;
        }

        return count($pending);
    }

    /**
     * Definisi kanonik store → koleksi. Bentuknya WAJIB dipatuhi pemakai lain.
     *
     * @return array<string,array<int,array{table:string,path:string,idField:?string,columns:array<int,string>}>>
     */
    public static function storeDefinitions(): array
    {
        return [
            'apps' => [
                ['table' => 'apps', 'path' => '', 'idField' => 'id', 'columns' => ['name', 'owner_id', 'status', 'source', 'subdomain']],
            ],
            'auth' => [
                ['table' => 'users', 'path' => '', 'idField' => 'id', 'columns' => ['username', 'role', 'email']],
            ],
            'billing' => [
                ['table' => 'users', 'path' => 'users', 'idField' => null, 'columns' => []],
                ['table' => 'usage', 'path' => 'usage', 'idField' => null, 'columns' => ['owner_id', 'period']],
                ['table' => 'orders', 'path' => 'orders', 'idField' => null, 'columns' => ['user_id', 'status']],
            ],
            'backup' => [
                ['table' => 'volumes', 'path' => 'volumes', 'idField' => null, 'columns' => ['scheduled']],
                ['table' => 'registry', 'path' => 'registry', 'idField' => null, 'columns' => ['project', 'app_id']],
            ],
        ];
    }

    /**
     * Buat (idempoten) tabel + indeks kolom turunan untuk satu koleksi.
     *
     * @param array{table:string,path?:string,idField?:?string,columns?:array<int,string>} $collection
     */
    public static function ensure(PDO $pdo, string $store, array $collection): void
    {
        $pdo->exec(self::createTableSql($store, $collection));
        foreach (self::indexSql($store, $collection) as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * Nama tabel fisik: `{store}_{table}`.
     */
    public static function tableName(string $store, string $table): string
    {
        return $store . '_' . $table;
    }

    /**
     * Nama kolom turunan dari dot-path dokumen (`.` → `_`).
     */
    public static function columnName(string $path): string
    {
        return str_replace('.', '_', $path);
    }

    /**
     * @param array{table:string,columns?:array<int,string>} $collection
     */
    public static function createTableSql(string $store, array $collection): string
    {
        $table = self::tableName($store, (string) $collection['table']);
        $columns = [
            '"id" TEXT PRIMARY KEY',
            '"ord" INTEGER',
            '"data" TEXT NOT NULL',
            '"updated_at" TEXT NOT NULL',
        ];
        foreach (self::columnNames($collection) as $column) {
            $columns[] = '"' . $column . '" TEXT';
        }

        return 'CREATE TABLE IF NOT EXISTS "' . $table . '" (' . implode(', ', $columns) . ')';
    }

    /**
     * @param array{table:string,columns?:array<int,string>} $collection
     * @return array<int,string>
     */
    public static function indexSql(string $store, array $collection): array
    {
        $table = self::tableName($store, (string) $collection['table']);
        $sql = [];
        foreach (self::columnNames($collection) as $column) {
            $sql[] = 'CREATE INDEX IF NOT EXISTS "idx_' . $table . '_' . $column . '" '
                . 'ON "' . $table . '" ("' . $column . '")';
        }
        return $sql;
    }

    /**
     * Tabel meta internal (bukan koleksi store).
     */
    public static function createMetaTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS "kv" ("key" TEXT PRIMARY KEY, "value" TEXT NOT NULL)');
    }

    /**
     * @param array{table:string,columns?:array<int,string>} $collection
     * @return array<int,string>
     */
    private static function columnNames(array $collection): array
    {
        $names = [];
        foreach ($collection['columns'] ?? [] as $path) {
            $names[] = self::columnName((string) $path);
        }
        return $names;
    }
}
