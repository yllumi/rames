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
    public const LATEST = 4;

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
            2 => static function (PDO $pdo): void {
                // Registry server (multi-server): dibuat migrasi 1 untuk DB baru,
                // di sini dipastikan ada untuk DB lama.
                foreach (self::storeDefinitions()['servers'] as $collection) {
                    self::ensure($pdo, 'servers', $collection);
                }
                // Kolom `server_id` pada apps. `ensure()` tidak menambah kolom ke
                // tabel yang sudah ada ⇒ kolom dulu (ALTER), indeks kemudian.
                foreach (self::storeDefinitions()['apps'] as $collection) {
                    self::ensureDerivedColumns($pdo, 'apps', $collection);
                }
                self::backfillAppServerId($pdo);
                self::seedLocalServer($pdo);
            },
            3 => static function (PDO $pdo): void {
                // Domain parking (entitas global di luar app): tabel parkir
                // dibuat migrasi 1 untuk DB baru, di sini dipastikan ada untuk
                // DB lama (v1/v2). Idempoten — `CREATE ... IF NOT EXISTS`.
                foreach (self::storeDefinitions()['parked'] as $collection) {
                    self::ensure($pdo, 'parked', $collection);
                }
            },
            4 => static function (PDO $pdo): void {
                // "Rekam Error" (error klien + exception server 5xx): dua tabel
                // khusus di luar `storeDefinitions()` — event bersifat
                // append-only dan ditulis lewat prepared statement langsung,
                // sehingga tidak boleh memakai `SqliteStore` (yang menulis ulang
                // seluruh koleksi per mutasi).
                //
                // `error_groups` = satu baris per fingerprint; `error_events` =
                // sampel terbatas per grup (lihat `ErrorStore::record()`).
                // Idempoten agar DB v1..v3 yang sudah ada ikut mendapat tabel.
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS "error_groups" ('
                    . '"id" TEXT PRIMARY KEY,'
                    . '"fingerprint" TEXT NOT NULL UNIQUE,'
                    . '"kind" TEXT NOT NULL,'
                    . '"status" TEXT NOT NULL DEFAULT \'open\','
                    . '"message" TEXT NOT NULL,'
                    . '"location" TEXT NOT NULL DEFAULT \'\','
                    . '"route" TEXT NOT NULL DEFAULT \'\','
                    . '"http_status" INTEGER,'
                    . '"app_id" TEXT,'
                    . '"server_id" TEXT,'
                    . '"user_id" TEXT,'
                    . '"username" TEXT,'
                    . '"first_seen" TEXT NOT NULL,'
                    . '"last_seen" TEXT NOT NULL,'
                    . '"count" INTEGER NOT NULL DEFAULT 1,'
                    . '"samples" INTEGER NOT NULL DEFAULT 0,'
                    . '"resolved_at" TEXT,'
                    . '"resolved_by" TEXT'
                    . ')'
                );
                $pdo->exec(
                    'CREATE INDEX IF NOT EXISTS "idx_error_groups_status_seen"'
                    . ' ON "error_groups" ("status","last_seen")'
                );
                $pdo->exec(
                    'CREATE INDEX IF NOT EXISTS "idx_error_groups_last_seen"'
                    . ' ON "error_groups" ("last_seen")'
                );
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS "error_events" ('
                    . '"id" INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . '"group_id" TEXT NOT NULL,'
                    . '"occurred_at" TEXT NOT NULL,'
                    . '"kind" TEXT NOT NULL,'
                    . '"message" TEXT NOT NULL,'
                    . '"stack" TEXT NOT NULL DEFAULT \'\','
                    . '"context" TEXT NOT NULL DEFAULT \'{}\','
                    . '"user_agent" TEXT NOT NULL DEFAULT \'\''
                    . ')'
                );
                $pdo->exec(
                    'CREATE INDEX IF NOT EXISTS "idx_error_events_group_time"'
                    . ' ON "error_events" ("group_id","occurred_at")'
                );
                $pdo->exec(
                    'CREATE INDEX IF NOT EXISTS "idx_error_events_time"'
                    . ' ON "error_events" ("occurred_at")'
                );
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
                ['table' => 'apps', 'path' => '', 'idField' => 'id', 'columns' => ['name', 'owner_id', 'status', 'source', 'subdomain', 'server_id']],
            ],
            'servers' => [
                ['table' => 'servers', 'path' => '', 'idField' => 'id', 'columns' => ['name', 'kind', 'enabled']],
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
            // Domain parking: entitas global (tidak terikat app). Tabel fisik
            // `parked_domains`; `server_id` disimpan demi multi-server nanti.
            'parked' => [
                ['table' => 'domains', 'path' => '', 'idField' => 'id', 'columns' => ['domain', 'server_id', 'mode']],
            ],
            // Identitas brand dashboard (rebranding): satu dokumen tunggal
            // ber-id `branding`; hanya nama berkas logo yang disimpan.
            'branding' => [
                ['table' => 'settings', 'path' => '', 'idField' => 'id', 'columns' => ['name']],
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
     * Pastikan seluruh kolom turunan koleksi ada, baru indeksnya.
     *
     * {@see ensure()} menjalankan `CREATE TABLE IF NOT EXISTS` **lalu**
     * `indexSql()`. Pada tabel yang sudah ada, `CREATE TABLE IF NOT EXISTS`
     * tidak menambah kolom baru sehingga `CREATE INDEX ("server_id")` gagal
     * `no such column: server_id`. Karena itu kolom yang hilang ditambahkan
     * lebih dulu (`ALTER TABLE`, dideteksi `PRAGMA table_info`).
     *
     * @param array{table:string,path?:string,idField?:?string,columns?:array<int,string>} $collection
     */
    private static function ensureDerivedColumns(PDO $pdo, string $store, array $collection): void
    {
        $table = self::tableName($store, (string) $collection['table']);
        if (!self::tableExists($pdo, $table)) {
            self::ensure($pdo, $store, $collection);
            return;
        }

        $present = self::existingColumns($pdo, $table);
        foreach (self::columnNames($collection) as $column) {
            if (!in_array($column, $present, true)) {
                $pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN "' . $column . '" TEXT');
            }
        }
        foreach (self::indexSql($store, $collection) as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * Backfill `server_id` apps lama: baris tanpa nilai di kolom turunan diberi
     * server lokal. Nilai ditulis ke kolom turunan **dan** ke dokumen JSON
     * (`data`) — JSON adalah sumber kebenaran, jadi keduanya tidak boleh
     * divergen. Dokumen yang bukan objek dibiarkan apa adanya.
     */
    private static function backfillAppServerId(PDO $pdo): void
    {
        $table = self::tableName('apps', 'apps');
        $rows = $pdo->query(
            'SELECT "id", "data" FROM "' . $table . '" '
            . 'WHERE "server_id" IS NULL OR "server_id" = \'\''
        )->fetchAll(PDO::FETCH_ASSOC);

        $update = $pdo->prepare(
            'UPDATE "' . $table . '" SET "server_id" = ?, "data" = ? WHERE "id" = ?'
        );
        foreach ($rows as $row) {
            $doc = json_decode((string) $row['data']);
            if (!$doc instanceof \stdClass) {
                continue; // dokumen bukan objek (atau korup) → jangan dirusak
            }

            // Nilai yang sudah ada di JSON dipertahankan (kebenaran ada di JSON).
            $existing = isset($doc->server_id) && is_scalar($doc->server_id) ? (string) $doc->server_id : '';
            $serverId = $existing !== '' ? $existing : ServerStore::LOCAL_ID;
            $doc->server_id = $serverId;

            $encoded = json_encode(
                $doc,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
            if ($encoded === false) {
                continue; // tak bisa di-encode → biarkan baris apa adanya
            }

            $update->execute([$serverId, $encoded, (string) $row['id']]);
        }
    }

    /**
     * Seed baris server lokal (`local`) — control plane selalu punya dirinya
     * sendiri sebagai server #1. Idempoten (`INSERT OR IGNORE`); kolom turunan
     * diisi sekalian karena `SqliteStore` menurunkannya dari dokumen JSON.
     * Sengaja **tanpa** `config()` — nilai `domain` diisi lapisan resolver.
     */
    private static function seedLocalServer(PDO $pdo): void
    {
        $table = self::tableName('servers', 'servers');
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO "' . $table . '" '
            . '("id", "ord", "data", "updated_at", "name", "kind", "enabled") '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            ServerStore::LOCAL_ID,
            0,
            '{"id":"local","name":"local","kind":"local","enabled":true,"domain":""}',
            date('c'),
            'local',
            'local',
            1,
        ]);
    }

    /**
     * Apakah tabel fisik ada.
     */
    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM "sqlite_master" WHERE "type" = \'table\' AND "name" = ?');
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Nama kolom fisik tabel (mode fetch eksplisit agar tidak bergantung pada
     * mode bawaan PDO pemanggil).
     *
     * @return array<int,string>
     */
    private static function existingColumns(PDO $pdo, string $table): array
    {
        $names = [];
        foreach ($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }

        return $names;
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
