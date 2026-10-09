<?php
declare(strict_types=1);

namespace app\library\Storage;

use PDO;
use RuntimeException;

/**
 * Impor sekali dari berkas JSON lama (`apps.json`, `auth.json`, `billing.json`,
 * `backup.json`) ke SQLite, plus ekspor balik untuk jalur rollback.
 *
 * Aturan impor:
 *  - dilewati bila flag `legacy_imported_at` sudah ada di tabel meta `kv`;
 *  - store yang tabelnya sudah berisi baris tidak ditimpa;
 *  - berkas JSON lama **tidak** dihapus/diubah;
 *  - tiap store diimpor dalam satu transaksi.
 */
final class JsonImporter
{
    private const FLAG_KEY = 'legacy_imported_at';

    private readonly string $legacyDir;
    private readonly ?string $dbFile;

    /**
     * @param string|null $legacyDir direktori berkas JSON lama; null = `config('deploy.database_path')`
     * @param string|null $dbFile berkas .sqlite default; null = `config('deploy.sqlite_file')`
     */
    public function __construct(?string $legacyDir = null, ?string $dbFile = null)
    {
        $dir = $legacyDir ?? (string) config('deploy.database_path', '');
        if ($dir === '') {
            throw new RuntimeException('database_path belum dikonfigurasi.');
        }
        $this->legacyDir = rtrim($dir, '/');
        $this->dbFile = $dbFile;
    }

    /**
     * Impor legacy JSON bila belum pernah (idempoten).
     *
     * @param bool $force abaikan flag `legacy_imported_at` (tabel yang sudah
     *                    berisi baris tetap tidak ditimpa)
     * @return array{skipped:bool,apps:int,users:int,billing:bool,backup:bool}
     */
    public function importIfNeeded(?string $dbFile = null, bool $force = false): array
    {
        $db = new SqliteDatabase($dbFile ?? $this->dbFile);
        $file = $db->file();
        $pdo = $db->pdo();

        if (!$force && $this->importedFlag($pdo) !== null) {
            return [
                'skipped' => true,
                'apps' => $this->countRows($pdo, 'apps'),
                'users' => $this->countRows($pdo, 'auth'),
                'billing' => $this->storeHasRows($pdo, 'billing'),
                'backup' => $this->storeHasRows($pdo, 'backup'),
            ];
        }

        $apps = $this->countRows($pdo, 'apps');
        $users = $this->countRows($pdo, 'auth');
        $billing = $this->storeHasRows($pdo, 'billing');
        $backup = $this->storeHasRows($pdo, 'backup');

        // apps.json → store `apps` (list)
        $legacy = $this->readLegacy('apps.json');
        if ($legacy !== null && !$this->storeHasRows($pdo, 'apps')) {
            $list = $this->asList($legacy);
            $this->store('apps', $file)->write($list);
            $apps = count($list);
        }

        // auth.json → store `auth` (list)
        $legacy = $this->readLegacy('auth.json');
        if ($legacy !== null && !$this->storeHasRows($pdo, 'auth')) {
            $list = $this->asList($legacy);
            $this->store('auth', $file)->write($list);
            $users = count($list);
        }

        // billing.json → store `billing` (users/usage/orders)
        $legacy = $this->readLegacy('billing.json');
        if (is_array($legacy) && !$this->storeHasRows($pdo, 'billing')) {
            $this->store('billing', $file)->write([
                'users' => $this->asMap($legacy['users'] ?? []),
                'usage' => $this->asMap($legacy['usage'] ?? []),
                'orders' => $this->asMap($legacy['orders'] ?? []),
            ]);
            $billing = true;
        }

        // backup.json → store `backup` (volumes/registry)
        $legacy = $this->readLegacy('backup.json');
        if (is_array($legacy) && !$this->storeHasRows($pdo, 'backup')) {
            $this->store('backup', $file)->write([
                'volumes' => $this->asMap($legacy['volumes'] ?? []),
                'registry' => $this->asMap($legacy['registry'] ?? []),
            ]);
            $backup = true;
        }

        $this->setImportedFlag($pdo);

        return [
            'skipped' => false,
            'apps' => $apps,
            'users' => $users,
            'billing' => $billing,
            'backup' => $backup,
        ];
    }

    /**
     * Ekspor balik ke 4 berkas JSON (jalur rollback/migrasi balik).
     *
     * @param string|null $dir direktori tujuan; null = direktori legacy
     * @return array<int,string> daftar berkas yang ditulis
     */
    public function exportToJson(?string $dir = null): array
    {
        $dir = $dir ?? $this->legacyDir;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori {$dir}");
        }
        $dir = rtrim($dir, '/');

        $apps = $this->store('apps', $this->dbFile)->read();
        $auth = $this->store('auth', $this->dbFile)->read();
        $billing = $this->store('billing', $this->dbFile)->read();
        $backup = $this->store('backup', $this->dbFile)->read();

        $files = [];
        $files[] = $this->writeJson($dir . '/apps.json', $apps);
        $files[] = $this->writeJson($dir . '/auth.json', $auth);
        $files[] = $this->writeJson($dir . '/billing.json', [
            'version' => 1,
            'users' => $billing['users'] ?? [],
            'usage' => $billing['usage'] ?? [],
            'orders' => $billing['orders'] ?? [],
        ]);
        $files[] = $this->writeJson($dir . '/backup.json', [
            'version' => 1,
            'volumes' => $backup['volumes'] ?? [],
            'registry' => $backup['registry'] ?? [],
        ]);

        return $files;
    }

    private function store(string $store, ?string $file = null): SqliteStore
    {
        $definitions = SchemaMigrations::storeDefinitions();
        if (!isset($definitions[$store])) {
            throw new RuntimeException("Store tidak dikenal: {$store}");
        }
        return new SqliteStore($store, $definitions[$store], $file);
    }

    private function writeJson(string $path, mixed $data): string
    {
        (new JsonStore($path))->write(is_array($data) ? $data : []);
        return $path;
    }

    /**
     * @return mixed null bila berkas tidak ada
     */
    private function readLegacy(string $name): mixed
    {
        $path = $this->legacyDir . '/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("JSON lama korup: {$path}");
        }
        return $data;
    }

    /**
     * @return array<int,mixed>
     */
    private function asList(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function asMap(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function storeHasRows(PDO $pdo, string $store): bool
    {
        foreach (SchemaMigrations::storeDefinitions()[$store] as $collection) {
            $table = SchemaMigrations::tableName($store, (string) $collection['table']);
            $count = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
            if ($count > 0) {
                return true;
            }
        }
        return false;
    }

    private function countRows(PDO $pdo, string $store): int
    {
        $total = 0;
        foreach (SchemaMigrations::storeDefinitions()[$store] as $collection) {
            $table = SchemaMigrations::tableName($store, (string) $collection['table']);
            $total += (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        }
        return $total;
    }

    private function importedFlag(PDO $pdo): ?string
    {
        $stmt = $pdo->prepare('SELECT "value" FROM "kv" WHERE "key" = ?');
        $stmt->execute([self::FLAG_KEY]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function setImportedFlag(PDO $pdo): void
    {
        $stmt = $pdo->prepare('INSERT INTO "kv" ("key", "value") VALUES (?, ?) ON CONFLICT("key") DO UPDATE SET "value" = excluded."value"');
        $stmt->execute([self::FLAG_KEY, date('c')]);
    }
}
