<?php
declare(strict_types=1);

namespace app\library\Storage;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Backup berkala basis data SQLite dashboard (SPECS §8h-nya data JSON, ini
 * untuk `rames.sqlite`).
 *
 * Tanggung jawab:
 *  - `run()`      : snapshot konsisten (`VACUUM INTO`) + retensi + status;
 *  - `prune()`    : retensi harian/pekanan/bulanan (pola `forget --keep-*`);
 *  - `restore()`  : ganti DB dari snapshot — defensif & fail-fast SEBELUM efek
 *                   samping (validasi nama/path/SQLite/tabel inti dulu);
 *  - `isDue()`    : apakah jadwal harian (`db_backup_hour`) sudah waktunya;
 *  - `state()`    : status run terakhir (dibaca dari `state.json` runtime).
 *
 * Semua status disimpan lewat {@see JsonStore} di `runtime/db-backup/state.json`
 * (bukan di `database/`), mutasi worker tetap dijalankan lewat CLI proses
 * terpisah — kelas ini tidak pernah menutup/mengganti handle proses lain,
 * hanya memberi tahu bahwa worker perlu reload.
 */
final class DbBackup
{
    /** Prefiks snapshot terjadwal/manual: `rames-YYYYmmdd-HHMMSS.sqlite`. */
    private const SNAPSHOT_PREFIX = 'rames-';

    /** Prefiks safety snapshot sebelum restore: `pre-restore-YYYYmmdd-HHMMSS.sqlite`. */
    private const SAFETY_PREFIX = 'pre-restore-';

    /** Panjang riwayat run di `state.json`. */
    private const RUN_HISTORY = 20;

    /** Tabel inti yang WAJIB ada di snapshot yang boleh dipulihkan. */
    private const CORE_TABLES = ['apps_apps', 'auth_users', 'billing_users'];

    private readonly SqliteDatabase $db;
    private readonly string $dbFile;
    private readonly string $backupDir;

    /**
     * @param string|null $dbFile    path .sqlite; null = `config('deploy.sqlite_file')`
     * @param string|null $backupDir direktori snapshot; null = `config('deploy.db_backup_dir')`
     */
    public function __construct(?string $dbFile = null, ?string $backupDir = null)
    {
        $this->db = new SqliteDatabase($dbFile);
        $this->dbFile = $this->db->file();

        $dir = $backupDir !== null ? trim($backupDir) : '';
        if ($dir === '') {
            $dir = trim((string) config('deploy.db_backup_dir', ''));
        }
        if ($dir === '') {
            $dir = dirname($this->dbFile) . '/backup';
        }
        $this->backupDir = rtrim($dir, '/');
    }

    /**
     * Path berkas DB yang di-backup.
     */
    public function dbFile(): string
    {
        return $this->dbFile;
    }

    /**
     * Direktori penyimpanan snapshot.
     */
    public function backupDir(): string
    {
        return $this->backupDir;
    }

    /**
     * Jalankan backup lengkap: snapshot baru + retensi + catat status.
     *
     * Dilindungi kunci berkas non-blocking (`<backup_dir>/.lock`): bila backup
     * lain (tick terjadwal vs CLI manual) sedang berjalan, run ini DILEWATI
     * (bukan kegagalan) dan mengembalikan `['skipped' => true, 'reason' => 'busy']`.
     *
     * @return array{file:?string,path:?string,bytes:int,at:?string,mtime:int,skipped:bool,reason:?string}
     */
    public function run(string $reason = 'manual'): array
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'manual';

        try {
            $lock = $this->acquireLock();
        } catch (Throwable $e) {
            // Kegagalan nyata (mis. direktori backup tak bisa dibuat) — catat & teruskan.
            $this->safeUpdateState(['error' => $e->getMessage()], [
                'at' => $this->nowIso(),
                'reason' => $reason,
                'file' => null,
                'bytes' => 0,
                'ms' => 0,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($lock === null) {
            $at = $this->nowIso();
            $this->safeUpdateState(
                [
                    'last_skipped_at' => $at,
                    'last_skip_reason' => 'busy',
                ],
                [
                    'at' => $at,
                    'reason' => 'skipped:busy',
                    'file' => null,
                    'bytes' => 0,
                    'ms' => 0,
                    'error' => null,
                ]
            );

            return [
                'file' => null,
                'path' => null,
                'bytes' => 0,
                'at' => null,
                'mtime' => 0,
                'skipped' => true,
                'reason' => 'busy',
            ];
        }

        $t0 = microtime(true);
        $target = null;
        $entry = null;

        try {
            $target = $this->uniquePath(self::SNAPSHOT_PREFIX);
            $this->db->snapshot($target);
            @chmod($target, 0640);
            $entry = $this->entryFor($target);

            $this->pruneInternal();

            $this->updateState([
                'last_run_at' => $entry['at'],
                'last_reason' => $reason,
                'last_file' => $entry['file'],
                'last_bytes' => $entry['bytes'],
                'last_duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'error' => null,
            ], [
                'at' => $entry['at'],
                'reason' => $reason,
                'file' => $entry['file'],
                'bytes' => $entry['bytes'],
                'ms' => (int) round((microtime(true) - $t0) * 1000),
                'error' => null,
            ]);

            return ['skipped' => false, 'reason' => null] + $entry;
        } catch (Throwable $e) {
            // Snapshot gagal ⇒ jangan tinggalkan berkas parsial; tapi bila
            // snapshot sudah sah (kegagalan di prune/state), pertahankan.
            if ($entry === null && $target !== null && is_file($target)) {
                @unlink($target);
            }
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $this->safeUpdateState([
                'last_duration_ms' => $ms,
                'error' => $e->getMessage(),
            ], [
                'at' => $this->nowIso(),
                'reason' => $reason,
                'file' => null,
                'bytes' => 0,
                'ms' => $ms,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Daftar snapshot (terbaru dulu).
     *
     * @return array<int,array{file:string,path:string,bytes:int,at:string,mtime:int}>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->snapshots() as $row) {
            unset($row['stamp']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Snapshot terbaru (atau null bila belum ada).
     *
     * @return array{file:string,path:string,bytes:int,at:string,mtime:int}|null
     */
    public function latest(): ?array
    {
        $list = $this->list();

        return $list[0] ?? null;
    }

    /**
     * Retensi: pertahankan snapshot terbaru per hari (`keep_daily`), per pekan
     * ISO (`keep_weekly`), per bulan (`keep_monthly`); hapus sisanya. Snapshot
     * terbaru SELALU dipertahankan walau semua kebijakan 0. Idempoten.
     *
     * Hanya berkas berprefiks `rames-` yang dikelola; safety snapshot
     * `pre-restore-*` tidak pernah dihapus otomatis.
     *
     * Dilindungi kunci berkas yang sama dengan {@see run()}; bila backup lain
     * sedang berjalan, retensi DILEWATI (`skipped => true`).
     *
     * @return array{removed:int,kept:int,skipped?:bool}
     */
    public function prune(): array
    {
        $lock = $this->acquireLock();
        if ($lock === null) {
            return ['removed' => 0, 'kept' => count($this->snapshots()), 'skipped' => true];
        }

        try {
            return $this->pruneInternal();
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Inti retensi — TANPA kunci (dipanggil {@see run()}/{@see prune()} yang
     * sudah memegang kunci).
     *
     * @return array{removed:int,kept:int}
     */
    private function pruneInternal(): array
    {
        $snapshots = $this->snapshots();
        if ($snapshots === []) {
            return ['removed' => 0, 'kept' => 0];
        }

        $keep = array_filter([
            'daily' => (int) config('deploy.db_backup_keep_daily', 7),
            'weekly' => (int) config('deploy.db_backup_keep_weekly', 4),
            'monthly' => (int) config('deploy.db_backup_keep_monthly', 3),
        ], static fn (int $value): bool => $value > 0);

        // Snapshot terbaru selalu dipertahankan.
        $keepPaths = [$snapshots[0]['path'] => true];

        if ($keep !== []) {
            $formats = ['daily' => 'Y-m-d', 'weekly' => 'o-W', 'monthly' => 'Y-m'];
            /** @var array<string,array<string,bool>> $seen bucket yang sudah terisi per kebijakan */
            $seen = ['daily' => [], 'weekly' => [], 'monthly' => []];
            foreach ($snapshots as $row) {
                /** @var DateTimeImmutable $stamp */
                $stamp = $row['stamp'];
                foreach ($formats as $policy => $format) {
                    $limit = (int) ($keep[$policy] ?? 0);
                    if ($limit <= 0) {
                        continue;
                    }
                    $bucket = $stamp->format($format);
                    if (isset($seen[$policy][$bucket])) {
                        continue;
                    }
                    if (count($seen[$policy]) >= $limit) {
                        continue;
                    }
                    $seen[$policy][$bucket] = true;
                    $keepPaths[$row['path']] = true;
                }
            }
        }

        $removed = 0;
        foreach ($snapshots as $row) {
            if (isset($keepPaths[$row['path']])) {
                continue;
            }
            if (@unlink($row['path'])) {
                $removed++;
            }
        }

        return ['removed' => $removed, 'kept' => count($snapshots) - $removed];
    }

    /**
     * Pulihkan DB dari snapshot. Semua validasi dilakukan SEBELUM efek samping
     * apa pun. Worker perlu reload setelah restore (`php start.php reload`) —
     * kelas ini tidak pernah me-restart worker sendiri.
     *
     * @return array{restored:bool,file:string,bytes:int}
     */
    public function restore(string $file): array
    {
        $name = $this->sanitizeName($file);
        $source = $this->backupDir . '/' . $name;
        $real = realpath($source);
        $dirReal = realpath($this->backupDir);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException("Snapshot \"{$name}\" tidak ditemukan di {$this->backupDir}.");
        }
        if ($dirReal === false || !str_starts_with($real, $dirReal . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Snapshot \"{$name}\" berada di luar direktori backup.");
        }

        $this->assertRestorable($real, $name);

        $size = (int) (filesize($real) ?: 0);

        // Restore tidak boleh berjalan saat snapshot/prune sedang dibuat.
        $lock = $this->acquireLock();
        if ($lock === null) {
            throw new RuntimeException('Tidak bisa restore: backup lain sedang berjalan (kunci sibuk).');
        }

        try {
            // 1) Safety snapshot DB saat ini lebih dulu (kalau ada).
            if (is_file($this->dbFile)) {
                $safety = $this->uniquePath(self::SAFETY_PREFIX);
                $this->db->snapshot($safety);
                @chmod($safety, 0640);
            }

            // 2) Ganti berkas DB secara atomik: copy ke tmp → rename.
            $tmp = $this->dbFile . '.restore-tmp';
            if (!@copy($real, $tmp)) {
                @unlink($tmp);
                throw new RuntimeException("Gagal menyalin snapshot ke {$tmp}.");
            }
            @chmod($tmp, 0640);
            if (!@rename($tmp, $this->dbFile)) {
                @unlink($tmp);
                throw new RuntimeException("Gagal mengganti berkas DB dengan snapshot \"{$name}\".");
            }

            // 3) Buang sidecar WAL/SHM lama (milik inode DB sebelumnya) & handle
            //    proses ini; worker lain tetap harus reload.
            $this->dropSidecars();
            SqliteDatabase::reset();

            $this->updateState([
                'last_restore_at' => $this->nowIso(),
                'last_restore_from' => $name,
                'last_restore_note' => 'worker perlu reload (php start.php reload)',
                'error' => null,
            ]);

            return ['restored' => true, 'file' => $name, 'bytes' => $size];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Status run terakhir (default bila belum pernah ada).
     *
     * @return array<string,mixed>
     */
    public function state(): array
    {
        $file = $this->stateFile();
        if (!is_file($file)) {
            return $this->emptyState();
        }

        try {
            $data = (new JsonStore($file))->read();
        } catch (Throwable) {
            return $this->emptyState();
        }

        return array_merge($this->emptyState(), $data);
    }

    /**
     * Apakah jadwal backup harian sudah waktunya (enabled + jam jadwal terlewati
     * + belum ada run/snapshot hari ini).
     */
    public function isDue(?string $now = null): bool
    {
        if (!(bool) config('deploy.db_backup_enabled', true)) {
            return false;
        }

        $tz = $this->timezone();
        try {
            $at = new DateTimeImmutable($now ?? 'now', $tz);
        } catch (Throwable) {
            $at = new DateTimeImmutable('now', $tz);
        }

        $hour = (int) config('deploy.db_backup_hour', 3);
        if ((int) $at->format('G') < $hour) {
            return false; // jadwal hari ini belum tiba
        }

        $today = $at->format('Y-m-d');

        $last = $this->state()['last_run_at'] ?? null;
        if (is_string($last) && $last !== '') {
            try {
                if ((new DateTimeImmutable($last))->setTimezone($tz)->format('Y-m-d') === $today) {
                    return false;
                }
            } catch (Throwable) {
                // timestamp rusak → abaikan, cek snapshot di bawah
            }
        }

        foreach ($this->snapshots() as $row) {
            /** @var DateTimeImmutable $stamp */
            $stamp = $row['stamp'];
            if ($stamp->format('Y-m-d') === $today) {
                return false;
            }
        }

        return true;
    }

    /**
     * Path absolut snapshot tervalidasi (untuk streaming controller).
     *
     * @throws RuntimeException nama/path tidak sah atau berkas tidak ada
     */
    public function download(string $file): string
    {
        $name = $this->sanitizeName($file);
        $source = $this->backupDir . '/' . $name;
        $real = realpath($source);
        $dirReal = realpath($this->backupDir);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException("Snapshot \"{$name}\" tidak ditemukan.");
        }
        if ($dirReal === false || !str_starts_with($real, $dirReal . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Snapshot \"{$name}\" berada di luar direktori backup.");
        }

        return $real;
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Semua snapshot `rames-*` di direktori backup, terbaru dulu.
     *
     * @return array<int,array{file:string,path:string,bytes:int,at:string,mtime:int,stamp:DateTimeImmutable}>
     */
    private function snapshots(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $tz = $this->timezone();
        $rows = [];
        foreach (scandir($this->backupDir) ?: [] as $entry) {
            if (!$this->isSnapshotName($entry)) {
                continue;
            }
            $path = $this->backupDir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $rows[] = $this->entryFor($path, $tz);
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = $b['stamp'] <=> $a['stamp'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b['mtime'] <=> $a['mtime'];

            return $cmp !== 0 ? $cmp : strcmp($b['file'], $a['file']);
        });

        return $rows;
    }

    /**
     * Bangun entri snapshot dari path berkas.
     *
     * @return array{file:string,path:string,bytes:int,at:string,mtime:int,stamp:DateTimeImmutable}
     */
    private function entryFor(string $path, ?DateTimeZone $tz = null): array
    {
        $tz ??= $this->timezone();
        $mtime = (int) (@filemtime($path) ?: time());
        $stamp = $this->parseStamp(basename($path), $tz) ?? (new DateTimeImmutable('@' . $mtime))->setTimezone($tz);

        return [
            'file' => basename($path),
            'path' => $path,
            'bytes' => (int) (@filesize($path) ?: 0),
            'at' => $stamp->format('c'),
            'mtime' => $mtime,
            'stamp' => $stamp,
        ];
    }

    /**
     * Ambil waktu dari nama berkas (`rames-`/`pre-restore-` + `YYYYmmdd-HHMMSS`).
     */
    private function parseStamp(string $name, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (!preg_match('/^[a-z-]+-(\d{8})-(\d{6})/', $name, $m)) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!Ymd His', $m[1] . ' ' . $m[2], $tz);

        return $at === false ? null : $at;
    }

    /**
     * Nama snapshot terjadwal/manual (bukan safety snapshot).
     */
    private function isSnapshotName(string $name): bool
    {
        return (bool) preg_match('/^rames-\d{8}-\d{6}(?:-[0-9a-f]{1,8})?\.sqlite$/', $name);
    }

    /**
     * Path unik di direktori backup (tanpa menimpa berkas yang sudah ada).
     */
    private function uniquePath(string $prefix): string
    {
        $this->ensureDir($this->backupDir);

        $base = $prefix . $this->now()->format('Ymd-His');
        $candidate = $this->backupDir . '/' . $base . '.sqlite';
        $guard = 0;
        while (is_file($candidate)) {
            $candidate = $this->backupDir . '/' . $base . '-' . bin2hex(random_bytes(2)) . '.sqlite';
            if (++$guard > 20) {
                throw new RuntimeException("Tidak bisa membuat nama snapshot unik di {$this->backupDir}.");
            }
        }

        return $candidate;
    }

    /**
     * Validasi berkas = SQLite utuh + memuat tabel inti. Tidak ada efek samping.
     */
    private function assertRestorable(string $path, string $name): void
    {
        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $check = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        } catch (Throwable $e) {
            throw new RuntimeException("Snapshot \"{$name}\" bukan basis data SQLite valid: " . $e->getMessage());
        }

        if (!is_string($check) || strtolower(trim($check)) !== 'ok') {
            throw new RuntimeException("Snapshot \"{$name}\" gagal integrity_check.");
        }

        $tables = $pdo->query(
            "SELECT \"name\" FROM \"sqlite_master\" WHERE \"type\" = 'table'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_map('strval', $tables);
        foreach (self::CORE_TABLES as $required) {
            if (!in_array($required, $tables, true)) {
                throw new RuntimeException(
                    "Snapshot \"{$name}\" bukan basis data Rames (tabel \"{$required}\" tidak ada)."
                );
            }
        }
    }

    /**
     * Nama berkas harus basename murni, tanpa traversal.
     */
    private function sanitizeName(string $file): string
    {
        $name = trim($file);
        if ($name === ''
            || $name !== basename($name)
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, '..')
            || !str_ends_with($name, '.sqlite')) {
            throw new RuntimeException('Nama snapshot tidak sah (harus basename *.sqlite tanpa path).');
        }

        return $name;
    }

    /**
     * Buang sidecar WAL/SHM milik inode DB lama (best-effort).
     */
    private function dropSidecars(): void
    {
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($this->dbFile . $suffix)) {
                @unlink($this->dbFile . $suffix);
            }
        }
    }

    /**
     * Ambil kunci berkas backup (non-blocking).
     *
     * @return resource|null handle kunci, atau null bila kunci sedang dipegang
     *                       proses lain (busy)
     * @throws RuntimeException bila berkas kunci tidak bisa dibuat/dibuka
     *                          (kegagalan nyata, bukan busy)
     */
    private function acquireLock(): mixed
    {
        $this->ensureDir($this->backupDir);

        $fp = @fopen($this->backupDir . '/.lock', 'c');
        if ($fp === false) {
            throw new RuntimeException("Tidak bisa membuka berkas kunci backup di {$this->backupDir}/.lock.");
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);

            return null; // dipegang proses lain
        }

        return $fp;
    }

    /**
     * @param resource|null $fp
     */
    private function releaseLock(mixed $fp): void
    {
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori {$dir}.");
        }
    }

    /**
     * Path `state.json` — `runtime/db-backup/state.json`.
     *
     * Override `RAMES_DB_BACKUP_STATE` (env) dipakai uji/demo agar tidak
     * menyentuh runtime nyata; produksi tetap memakai `app.runtime_path`.
     */
    private function stateFile(): string
    {
        $override = getenv('RAMES_DB_BACKUP_STATE');
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $runtime = trim((string) config('app.runtime_path', ''));
        if ($runtime === '') {
            $runtime = function_exists('runtime_path')
                ? runtime_path()
                : dirname($this->dbFile) . '/../runtime';
        }

        return rtrim($runtime, '/') . '/db-backup/state.json';
    }

    /**
     * Bentuk state kosong/default.
     *
     * @return array<string,mixed>
     */
    private function emptyState(): array
    {
        return [
            'last_run_at' => null,
            'last_reason' => null,
            'last_file' => null,
            'last_bytes' => 0,
            'last_duration_ms' => 0,
            'runs' => [],
            'error' => null,
            'last_skipped_at' => null,
            'last_skip_reason' => null,
            'last_restore_at' => null,
            'last_restore_from' => null,
            'last_restore_note' => null,
        ];
    }

    /**
     * Tulis perubahan state + (opsional) tambahkan satu entri riwayat run.
     *
     * @param array<string,mixed>      $changes
     * @param array<string,mixed>|null $run
     */
    private function updateState(array $changes, ?array $run = null): void
    {
        (new JsonStore($this->stateFile()))->update(function (array &$data) use ($changes, $run): void {
            $data = array_merge($this->emptyState(), $data);
            foreach ($changes as $key => $value) {
                $data[$key] = $value;
            }
            if ($run !== null) {
                $runs = is_array($data['runs'] ?? null) ? $data['runs'] : [];
                $runs[] = $run;
                if (count($runs) > self::RUN_HISTORY) {
                    $runs = array_slice($runs, -self::RUN_HISTORY);
                }
                $data['runs'] = array_values($runs);
            }
        });
    }

    /**
     * Varian tahan-gagal dari {@see updateState()} — dipakai saat run gagal dan
     * kegagalan penulisan status tidak boleh menutupi error aslinya.
     *
     * @param array<string,mixed>      $changes
     * @param array<string,mixed>|null $run
     */
    private function safeUpdateState(array $changes, ?array $run = null): void
    {
        try {
            $this->updateState($changes, $run);
        } catch (Throwable) {
            // diabaikan: error asli lebih penting
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone());
    }

    private function nowIso(): string
    {
        return $this->now()->format('c');
    }

    private function timezone(): DateTimeZone
    {
        $name = trim((string) config('app.default_timezone', 'Asia/Jakarta'));
        if ($name === '') {
            $name = 'Asia/Jakarta';
        }
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return new DateTimeZone('Asia/Jakarta');
        }
    }
}
