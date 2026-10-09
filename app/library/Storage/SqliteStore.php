<?php
declare(strict_types=1);

namespace app\library\Storage;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pengganti {@see JsonStore} berbasis SQLite dengan API publik identik
 * (`path()`, `read()`, `write()`, `update()`), sehingga pemakai lama tidak
 * perlu berubah.
 *
 * Satu `SqliteStore` = satu store logis (mis. `apps`, `auth`, `billing`,
 * `backup`) berisi 1..N koleksi/tabel. Bentuk `read()`:
 *  - koleksi `path:''` + `idField` terisi ⇒ list dokumen (urutan dipertahankan
 *    lewat kolom `ord`);
 *  - koleksi `path:'users'` + `idField:null` ⇒ map `id => dokumen`;
 *  - store berisi beberapa koleksi ⇒ map per `path` (mis.
 *    `['users'=>…,'usage'=>…,'orders'=>…]`).
 *
 * Kolom `data` menyimpan dokumen JSON lengkap dan menjadi **sumber
 * kebenaran**; kolom turunan hanya indeks bantu untuk kueri. Tipe JSON
 * (float/int/string/bool/null/nested) dipertahankan apa adanya.
 *
 * Konstruksi pertama untuk (berkas, store) memastikan skema (migrasi + DDL
 * `IF NOT EXISTS`) sekali; sesudahnya dipakai memo per-proses sehingga
 * konstruksi berikutnya **tidak menyentuh basis data** (lihat
 * {@see self::$schemaReady}) — penting karena store dibuat per request.
 */
final class SqliteStore
{
    /**
     * Memo per-proses: skema (identitas berkas, store) sudah dipastikan siap.
     * Kunci = `realpath#dev:ino` berkas + nama store (lihat {@see self::schemaKey()}).
     *
     * @var array<string,true>
     */
    private static array $schemaReady = [];

    private readonly SqliteDatabase $db;
    private readonly string $store;

    /** @var array<int,array{table:string,path:string,idField:?string,columns:array<int,string>}> */
    private readonly array $collections;

    /**
     * @param array<int,array{table:string,path:string,idField:?string,columns?:array<int,string>}> $collections
     * @param string|null $file path berkas .sqlite; null = `config('deploy.sqlite_file')`
     */
    public function __construct(string $store, array $collections, ?string $file = null)
    {
        if ($store === '') {
            throw new InvalidArgumentException('Nama store tidak boleh kosong.');
        }
        if ($collections === []) {
            throw new InvalidArgumentException('Store harus punya minimal satu koleksi.');
        }

        $normalized = [];
        foreach ($collections as $collection) {
            $table = (string) ($collection['table'] ?? '');
            if ($table === '') {
                throw new InvalidArgumentException('Koleksi tanpa nama tabel.');
            }
            $idField = $collection['idField'] ?? null;
            $normalized[] = [
                'table' => $table,
                'path' => (string) ($collection['path'] ?? ''),
                'idField' => $idField === null ? null : (string) $idField,
                'columns' => array_values(array_map('strval', $collection['columns'] ?? [])),
            ];
        }

        $this->store = $store;
        $this->collections = $normalized;
        $this->db = new SqliteDatabase($file);

        // Jalur cepat: skema (berkas ini, store ini) sudah dipastikan di proses
        // ini — JANGAN sentuh basis data sama sekali (tanpa pdo()/BEGIN/DDL/SELECT).
        // Store dibuat per request di Webman sehingga mengambil kunci tulis tiap
        // request akan menyerialkan seluruh request di titik ini.
        if (self::isSchemaReady($this->db->file(), $store)) {
            return;
        }

        $this->ensureSchema();
        // Memo hanya diset SETELAH ensureSchema sukses (kegagalan tak dimoisasi).
        self::$schemaReady[self::schemaKey($this->db->file(), $store)] = true;
    }

    /**
     * Apakah skema (berkas, store) sudah dipastikan siap di proses ini.
     */
    public static function isSchemaReady(string $file, string $store): bool
    {
        return isset(self::$schemaReady[self::schemaKey($file, $store)]);
    }

    /**
     * Kosongkan memo skema per-proses (dipakai `SqliteDatabase::reset()` & tes).
     */
    public static function resetSchemaCache(): void
    {
        self::$schemaReady = [];
    }

    /**
     * Kunci memo skema: identitas berkas (`realpath#dev:ino`, lihat
     * `SqliteDatabase::identityOf()`) + nama store.
     *
     * Menyertakan inode menjamin kebenaran saat berkas DIGANTI (restore/rename
     * atomik): inode baru ⇒ kunci berbeda ⇒ skema diverifikasi ulang lewat
     * handle baru. Bila berkas belum ada, dipakai path apa adanya — tidak akan
     * salah cocok dengan kunci berkas yang eksis (selalu memuat inode).
     */
    private static function schemaKey(string $file, string $store): string
    {
        return SqliteDatabase::identityOf($file) . "\0" . $store;
    }

    /**
     * Nama tabel fisik untuk store/koleksi.
     */
    public static function tableName(string $store, string $table): string
    {
        return SchemaMigrations::tableName($store, $table);
    }

    /**
     * Path berkas .sqlite (setara `JsonStore::path()`).
     */
    public function path(): string
    {
        return $this->db->file();
    }

    /**
     * Baca seluruh store (setara `JsonStore::read()`).
     *
     * @return array
     */
    public function read(): array
    {
        return $this->readRaw();
    }

    /**
     * Tulis seluruh store (setara `JsonStore::write()`), dalam satu transaksi.
     */
    public function write(array $data): void
    {
        $this->db->transaction(function () use ($data): void {
            $this->writeRaw($data);
        });
    }

    /**
     * Update atomik (setara `JsonStore::update()`): baca → mutator(&$data) →
     * tulis balik, semuanya dalam **satu** transaksi. Exception dari mutator
     * ⇒ rollback penuh (tanpa perubahan parsial).
     */
    public function update(callable $mutator): void
    {
        $this->db->transaction(function () use ($mutator): void {
            $data = $this->readRaw();
            $mutator($data);
            $this->writeRaw($data);
        });
    }

    private function ensureSchema(): void
    {
        $pdo = $this->db->pdo();
        $this->db->transaction(function () use ($pdo): void {
            foreach ($this->collections as $collection) {
                SchemaMigrations::ensure($pdo, $this->store, $collection);
            }
        });
    }

    private function readRaw(): array
    {
        $result = [];
        foreach ($this->collections as $collection) {
            $value = $this->readCollection($collection);
            if ($collection['path'] === '') {
                $result = $value;
            } else {
                $result[$collection['path']] = $value;
            }
        }
        return $result;
    }

    /**
     * @param array{table:string,path:string,idField:?string,columns:array<int,string>} $collection
     */
    private function readCollection(array $collection): array
    {
        $table = self::tableName($this->store, $collection['table']);
        $stmt = $this->db->pdo()->query('SELECT "id", "data" FROM "' . $table . '" ORDER BY "ord" ASC');

        $out = [];
        while (($row = $stmt->fetch()) !== false) {
            $doc = json_decode((string) $row['data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("Dokumen JSON korup di tabel {$table} id={$row['id']}");
            }
            if ($collection['idField'] !== null) {
                $out[] = $doc;
            } else {
                $out[(string) $row['id']] = $doc;
            }
        }
        return $out;
    }

    private function writeRaw(array $data): void
    {
        foreach ($this->collections as $collection) {
            $value = $collection['path'] === '' ? $data : ($data[$collection['path']] ?? []);
            $this->writeCollection($collection, is_array($value) ? $value : []);
        }
    }

    /**
     * Tulis satu koleksi: upsert tiap dokumen lalu hapus baris yang hilang.
     *
     * @param array{table:string,path:string,idField:?string,columns:array<int,string>} $collection
     * @param array $value
     */
    private function writeCollection(array $collection, array $value): void
    {
        $pdo = $this->db->pdo();
        $table = self::tableName($this->store, $collection['table']);
        $idField = $collection['idField'];
        $isList = $idField !== null;
        $docs = $isList ? array_values($value) : $value;

        $existing = [];
        foreach ($pdo->query('SELECT "id" FROM "' . $table . '"')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $existing[(string) $id] = true;
        }

        $columnNames = array_map(
            static fn (string $path): string => SchemaMigrations::columnName($path),
            $collection['columns']
        );

        $insertColumns = ['"id"', '"ord"', '"data"', '"updated_at"'];
        foreach ($columnNames as $column) {
            $insertColumns[] = '"' . $column . '"';
        }
        $updates = ['"ord" = excluded."ord"', '"data" = excluded."data"', '"updated_at" = excluded."updated_at"'];
        foreach ($columnNames as $column) {
            $updates[] = '"' . $column . '" = excluded."' . $column . '"';
        }
        $placeholders = array_fill(0, count($insertColumns), '?');
        $sql = 'INSERT INTO "' . $table . '" (' . implode(', ', $insertColumns) . ') VALUES ('
            . implode(', ', $placeholders) . ') ON CONFLICT("id") DO UPDATE SET ' . implode(', ', $updates);
        $stmt = $pdo->prepare($sql);

        $seen = [];
        $ord = 0;
        foreach ($docs as $key => $doc) {
            $docArray = is_array($doc) ? $doc : [];
            $id = $isList ? (string) ($docArray[$idField] ?? '') : (string) $key;
            if ($id === '') {
                // Dokumen list tanpa id (mis. berkas lama) → id sintetis stabil
                // per urutan; tidak pernah disuntikkan ke isi dokumen.
                $id = '~ord:' . $ord;
            }
            $seen[$id] = true;

            $encoded = json_encode(
                $doc,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
            if ($encoded === false) {
                throw new RuntimeException('Gagal encode dokumen: ' . json_last_error_msg());
            }
            $updatedAt = is_array($doc) && isset($doc['updated_at']) && is_scalar($doc['updated_at'])
                ? (string) $doc['updated_at']
                : date('c');

            $params = [$id, $ord, $encoded, $updatedAt];
            foreach ($collection['columns'] as $path) {
                $params[] = self::derivedValue($doc, $path);
            }
            $stmt->execute($params);
            $ord++;
        }

        foreach (array_keys($existing) as $id) {
            if (!isset($seen[$id])) {
                $del = $pdo->prepare('DELETE FROM "' . $table . '" WHERE "id" = ?');
                $del->execute([$id]);
            }
        }
    }

    /**
     * Ambil nilai skalar dari dot-path dokumen; non-skalar ⇒ null.
     */
    private static function derivedValue(mixed $doc, string $path): mixed
    {
        if (!is_array($doc)) {
            return null;
        }
        $value = $doc;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return is_scalar($value) ? $value : null;
    }
}
