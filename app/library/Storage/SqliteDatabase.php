<?php
declare(strict_types=1);

namespace app\library\Storage;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Handle tunggal (per berkas, per proses) untuk basis data SQLite Rames.
 *
 * Menyediakan koneksi PDO terkonfigurasi (WAL + busy_timeout + foreign_keys),
 * transaksi `BEGIN IMMEDIATE` yang re-entrant, snapshot konsisten, dan migrasi
 * otomatis saat koneksi pertama. Handle di-cache statik agar satu proses
 * worker hanya membuka satu koneksi per berkas.
 *
 * Kunci cache memakai **identitas berkas** (`realpath#dev:ino`), bukan path
 * saja, sehingga berkas yang DIGANTI (restore/rename atomik, inode baru)
 * otomatis dianggap berbeda: koneksi dibuka ulang & migrasi/skema diverifikasi
 * ulang untuk berkas baru. Sebaliknya, berkas yang sama cukup di-stat sekali
 * per instance (hasilnya di-cache) — biaya syscall, bukan kueri DB.
 */
final class SqliteDatabase
{
    /** @var array<string,PDO> */
    private static array $handles = [];

    /** @var array<string,int> kedalaman transaksi aktif per berkas */
    private static array $txDepth = [];

    /** @var array<string,bool> migrasi sudah dijalankan per berkas di proses ini */
    private static array $migrated = [];

    /** Guard re-entrancy impor otomatis (JsonImporter memakai SqliteStore/Database). */
    private static bool $importing = false;

    private readonly string $file;

    /** Identitas berkas ter-cache (lihat {@see self::identityKey()}). */
    private ?string $identity = null;

    /**
     * @param string|null $file path berkas .sqlite; null = `config('deploy.sqlite_file')`
     */
    public function __construct(?string $file = null)
    {
        $resolved = $file !== null && $file !== '' ? $file : (string) config('deploy.sqlite_file', '');
        if ($resolved === '') {
            throw new RuntimeException('sqlite_file belum dikonfigurasi (config deploy.sqlite_file / RAMES_DB_FILE).');
        }
        $this->file = $resolved;
    }

    /**
     * Path absolut berkas .sqlite.
     */
    public function file(): string
    {
        return $this->file;
    }

    /**
     * Koneksi PDO (dibuat & dimigrasi sekali per berkas per proses).
     */
    public function pdo(): PDO
    {
        $key = $this->identityKey();
        if (isset(self::$handles[$key])) {
            return self::$handles[$key];
        }

        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori {$dir}");
        }

        $isNew = !is_file($this->file);
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // PRAGMA tidak boleh berada di dalam transaksi. busy_timeout diset
        // PALING AWAL (transisi WAL & DDL paralel bisa menabrak kunci tulis
        // proses lain). Transisi ke WAL TIDAK menghormati busy_timeout, jadi
        // dipakai retry singkat (lihat enableWal()).
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::enableWal($pdo);
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        // PDO baru saja membuat berkas → identitas (realpath+inode) kini
        // tersedia; pakai sebagai kunci final agar panggilan pdo() berikutnya
        // menemukan handle yang sama.
        $key = $this->identityKey(true);
        self::$handles[$key] = $pdo;
        self::$txDepth[$key] = 0;

        if (!isset(self::$migrated[$key])) {
            try {
                SchemaMigrations::migrate($pdo);
                self::$migrated[$key] = true;
                self::maybeImportLegacy($pdo, $this->file);
            } catch (\Throwable $e) {
                // Jangan tinggalkan handle yang belum berskema: panggilan pdo()
                // berikutnya harus mencoba migrasi lagi, bukan early-return
                // tanpa tabel (auto-impor pun tidak boleh terlewat diam-diam).
                unset(self::$handles[$key], self::$txDepth[$key]);

                throw $e;
            }
        }

        if ($isNew) {
            @chmod($this->file, 0640); // best-effort: batasi akses berkas DB baru
        }

        return $pdo;
    }

    /**
     * Identitas berkas untuk kunci cache: `realpath#dev:ino`, atau path apa
     * adanya bila berkas belum ada.
     */
    public static function identityOf(string $file): string
    {
        $stat = @stat($file);
        if ($stat === false) {
            return $file;
        }
        $real = realpath($file);
        return ($real !== false ? $real : $file) . '#' . $stat['dev'] . ':' . $stat['ino'];
    }

    /**
     * Nyalakan WAL dengan retry singkat.
     *
     * `PRAGMA journal_mode = WAL` membutuhkan kunci eksklusif sesaat dan
     * **tidak** memakai busy_timeout; saat beberapa proses membuka berkas baru
     * bersamaan (mis. worker restart) transisi ini bisa gagal
     * "database is locked". Retry kecil (≤5 s) membuatnya konvergen.
     *
     * Best-effort: bila tetap bukan WAL (mis. FS tak mendukung), lanjut saja —
     * kebenaran data tetap dijamin `BEGIN IMMEDIATE` + busy_timeout.
     */
    private static function enableWal(PDO $pdo): void
    {
        $deadline = microtime(true) + 5.0;
        do {
            try {
                if (strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal') {
                    return;
                }
                $mode = strtolower((string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn());
                if ($mode === 'wal') {
                    return;
                }
            } catch (Throwable) {
                // SQLITE_BUSY: proses lain sedang bertransisi/memegang kunci → retry
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
    }

    /**
     * Identitas berkas instance ini (di-cache bila berkas sudah ada).
     *
     * Saat berkas belum ada, path mentah dikembalikan TANPA di-cache agar
     * begitu berkas dibuat identitas inode-nya langsung terpakai.
     */
    private function identityKey(bool $refresh = false): string
    {
        if (!$refresh && $this->identity !== null) {
            return $this->identity;
        }
        $identity = self::identityOf($this->file);
        if ($identity === $this->file) {
            return $this->file;
        }
        $this->identity = $identity;
        return $identity;
    }

    /**
     * Jalankan `$fn` dalam satu transaksi `BEGIN IMMEDIATE`.
     *
     * Re-entrant: pemanggilan bersarang memakai transaksi luar (tanpa BEGIN
     * kedua). Exception ⇒ rollback penuh lalu diteruskan.
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $key = $this->identityKey();

        if ((self::$txDepth[$key] ?? 0) > 0) {
            return $fn($pdo);
        }

        $pdo->exec('BEGIN IMMEDIATE');
        self::$txDepth[$key] = 1;
        try {
            $result = $fn($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // koneksi sudah tidak dalam transaksi — abaikan
            }
            throw $e;
        } finally {
            self::$txDepth[$key] = 0;
        }
    }

    /**
     * Apakah koneksi berkas ini sedang dalam transaksi.
     */
    public function inTransaction(): bool
    {
        return (self::$txDepth[$this->identityKey()] ?? 0) > 0;
    }

    /**
     * Snapshot konsisten ke berkas lain via `VACUUM INTO`.
     */
    public function snapshot(string $to): void
    {
        if ($this->inTransaction()) {
            throw new RuntimeException('snapshot() tidak boleh dijalankan di dalam transaksi.');
        }
        $dir = dirname($to);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori {$dir}");
        }
        if (is_file($to)) {
            @unlink($to);
        }
        $pdo = $this->pdo();
        $pdo->exec('VACUUM INTO ' . $pdo->quote($to));
        @chmod($to, 0640);
    }

    /**
     * `PRAGMA integrity_check` — true bila hasilnya `ok`.
     */
    public function integrityCheck(): bool
    {
        $result = $this->pdo()->query('PRAGMA integrity_check')->fetchColumn();
        return is_string($result) && strtolower(trim($result)) === 'ok';
    }

    /**
     * Buang cache handle/migrasi/memo skema (untuk tes — memaksa koneksi baru).
     */
    public static function reset(): void
    {
        self::$handles = [];
        self::$txDepth = [];
        self::$migrated = [];
        SqliteStore::resetSchemaCache();
    }

    /**
     * Safety net: impor sekali data lama (`database/*.json`) saat koneksi DB
     * pertama bila DB masih kosong (mis. instalasi lama di-`git pull` lalu
     * worker di-reload tanpa recreate kontainer sehingga `import-json` di
     * `command:` compose tidak jalan).
     *
     * Idempoten: keputusan impor diserahkan ke {@see JsonImporter::importIfNeeded()},
     * dan menjadi murah setelah pernah diimpor — hanya SATU SELECT pada flag
     * `kv`, tanpa membaca berkas JSON sama sekali.
     *
     * Kegagalan impor TIDAK boleh mematikan worker: ditangkap, dicatat ke
     * `runtime/logs/storage/{Y-m-d}.log`, lalu dilewati (admin bisa mengulang
     * lewat `php cli/db.php import-json`).
     */
    private static function maybeImportLegacy(PDO $pdo, string $file): void
    {
        if (self::$importing) {
            return; // guard re-entrancy: jangan rekursi lewat SqliteStore/JsonImporter
        }

        // Hanya bila fitur dinyalakan eksplisit (config/deploy.php default true).
        if (config('deploy.db_import_legacy_json') !== true) {
            return;
        }

        try {
            $stmt = $pdo->prepare('SELECT "value" FROM "kv" WHERE "key" = ?');
            $stmt->execute(['legacy_imported_at']);
            if ($stmt->fetchColumn() !== false) {
                return; // sudah pernah diimpor
            }
        } catch (Throwable) {
            return; // tabel `kv` belum ada / skema tak terduga → jangan paksa
        }

        self::$importing = true;
        try {
            (new JsonImporter(null, $file))->importIfNeeded($file);
        } catch (Throwable $e) {
            self::logImportFailure($e);
        } finally {
            self::$importing = false;
        }
    }

    /**
     * Catat kegagalan impor otomatis ke berkas log (best-effort, tanpa `echo`).
     */
    private static function logImportFailure(Throwable $e): void
    {
        try {
            $runtime = trim((string) config('app.runtime_path', ''));
            if ($runtime === '') {
                $runtime = function_exists('runtime_path') ? runtime_path() : sys_get_temp_dir();
            }
            $dir = rtrim($runtime, '/') . '/logs/storage';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            // Nama berkas & stempel waktu memakai zona waktu yang SAMA
            // (`app.default_timezone`) supaya baris log tidak "mendahului"
            // nama berkasnya saat proses CLI berjalan di UTC.
            try {
                $tz = new DateTimeZone((string) config('app.default_timezone', 'Asia/Jakarta'));
            } catch (Throwable) {
                // Zona waktu tidak valid: jangan sampai baris log hilang senyap.
                $tz = new DateTimeZone(date_default_timezone_get());
            }
            $now = new DateTimeImmutable('now', $tz);
            $fp = @fopen($dir . '/' . $now->format('Y-m-d') . '.log', 'a');
            if ($fp === false) {
                return;
            }
            @flock($fp, LOCK_EX);
            @fwrite($fp, '[' . $now->format('c') . '] impor otomatis JSON lama gagal: ' . $e->getMessage() . PHP_EOL);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        } catch (Throwable) {
            // best-effort: logging tidak boleh melempar ke pemanggil
        }
    }
}
