<?php
declare(strict_types=1);

namespace app\library\Errors;

use app\library\Storage\SqliteDatabase;
use PDO;
use Throwable;

/**
 * Pembatas laju laporan error per user ("Rekam Error").
 *
 * Pelaporan error berasal dari browser yang sedang dipakai; tanpa pembatas,
 * satu loop JS yang gagal terus-menerus (atau tab yang dipulihkan otomatis)
 * bisa membanjiri dashboard. Bucket dihitung **per user per menit (UTC)**
 * memakai tabel `kv` yang sudah ada (key TEXT PK, value TEXT) sehingga aman
 * untuk worker Webman yang persistent & multi-proses: tidak ada counter di
 * memori.
 *
 * Kunci: `errors:rate:{userId}:{YmdHi}` (UTC, 12 digit). Kenaikan memakai
 * satu statement UPSERT atomic:
 * `INSERT … ON CONFLICT(key) DO UPDATE SET value = CAST(value AS INTEGER) + 1`.
 * Pembacaan sesudahnya terpisah; pada balapan sangat sempit nilai yang
 * terbaca bisa sedikit berbeda. Untuk pembatas laju (bukan kuota tagihan)
 * selisih itu tidak berarti.
 *
 * **Dua kebijakan kegagalan yang sengaja berbeda:**
 *  - `userId` tidak valid ⇒ `false` (**fail-closed**): tanpa identitas yang
 *    aman tidak ada bucket yang bisa dipertanggungjawabkan.
 *  - galat SQLite/exception apa pun ⇒ `true` (**fail-open**): pelaporan error
 *    adalah fitur diagnostik, bukan kontrol akses. Menolak laporan karena DB
 *    sibuk hanya membuat bug pengguna tak pernah terekam — dan pemanggil tidak
 *    boleh menerima exception dari jalur ini.
 *
 * Pembersihan kunci lama (> 2 menit) **oportunistik** dan dibatasi klaim
 * atomic pada `kv` kunci `errors:rate:cleanup_at` (interval 60 s). Klaim
 * memakai `ON CONFLICT … DO UPDATE … WHERE` + `rowCount()`, jadi hanya satu
 * worker yang menjalankan DELETE walau banyak request masuk bersamaan; tidak
 * perlu counter di properti/static yang akan bocor antar-request.
 */
final class ErrorRateLimiter
{
    /** Prefiks kunci bucket. */
    public const KEY_PREFIX = 'errors:rate:';

    /** Kunci penanda waktu pembersihan terakhir (detik unix). */
    public const CLEANUP_KEY = 'errors:rate:cleanup_at';

    /** Umur maksimum bucket (detik) sebelum boleh dibuang. */
    public const RETENTION_SECONDS = 120;

    /** Jeda minimum antar-pembersihan (detik, lintas worker). */
    public const CLEANUP_INTERVAL_SECONDS = 60;

    /** Pola `userId` yang diterima (tanpa perlu escape saat jadi bagian SQL/Log). */
    private const USER_PATTERN = '/\A[A-Za-z0-9_.:@-]{1,128}\z/';

    private readonly ?SqliteDatabase $db;

    /**
     * @param SqliteDatabase|null $db seam uji; null = `database/rames.sqlite`
     *                                sesuai `config('deploy.sqlite_file')`
     */
    public function __construct(?SqliteDatabase $db = null)
    {
        $this->db = $db;
    }

    /**
     * Apakah laporan error user ini masih boleh diterima pada menit berjalan?
     *
     * Setiap panggilan (termasuk yang ditolak) menambah counter, sehingga
     * banjir laporan tidak "menghemat" kuota menit berikutnya.
     *
     * @param int $limitPerMinute kuota per menit; < 1 berarti semua ditolak
     */
    public function allow(string $userId, int $limitPerMinute = 20): bool
    {
        if (preg_match(self::USER_PATTERN, $userId) !== 1) {
            return false; // fail-closed: identitas tak aman
        }

        $limit = max(0, $limitPerMinute);

        try {
            $pdo = ($this->db ?? new SqliteDatabase())->pdo();
            $key = self::KEY_PREFIX . $userId . ':' . gmdate('YmdHi');

            $increment = $pdo->prepare(
                'INSERT INTO "kv" ("key", "value") VALUES (:key, \'1\')'
                . ' ON CONFLICT("key") DO UPDATE SET "value" = CAST("value" AS INTEGER) + 1'
            );
            $increment->execute([':key' => $key]);

            $read = $pdo->prepare('SELECT CAST("value" AS INTEGER) FROM "kv" WHERE "key" = :key');
            $read->execute([':key' => $key]);
            $count = (int) $read->fetchColumn();
            $read->closeCursor();

            $allowed = $count <= $limit;

            $this->maybeCleanup($pdo);

            return $allowed;
        } catch (Throwable) {
            // Fail-open: lihat docblock kelas.
            return true;
        }
    }

    /**
     * Bersihkan bucket kedaluwarsa bila klaim pembersihan berhasil.
     *
     * Klaim & DELETE berada di dalam `try` milik `allow()`; kegagalan di sini
     * tidak boleh mengubah jawaban.
     */
    private function maybeCleanup(PDO $pdo): void
    {
        $now = time();
        $threshold = $now - self::CLEANUP_INTERVAL_SECONDS;

        $claim = $pdo->prepare(
            'INSERT INTO "kv" ("key", "value") VALUES (:key, :now)'
            . ' ON CONFLICT("key") DO UPDATE SET "value" = excluded."value"'
            . ' WHERE CAST("value" AS INTEGER) <= :threshold'
        );
        $claim->bindValue(':key', self::CLEANUP_KEY);
        $claim->bindValue(':now', $now, PDO::PARAM_INT);
        $claim->bindValue(':threshold', $threshold, PDO::PARAM_INT);
        $claim->execute();

        if ($claim->rowCount() !== 1) {
            return; // worker lain baru saja membersihkan
        }

        // Sufiks kunci = `YmdHi` (12 digit, urut leksikografis = urut waktu),
        // jadi cukup dibandingkan sebagai bilangan bulat.
        $cutoff = (int) gmdate('YmdHi', $now - self::RETENTION_SECONDS);
        $purge = $pdo->prepare(
            'DELETE FROM "kv"'
            . ' WHERE "key" LIKE :prefix'
            . ' AND "key" <> :cleanup_key'
            . ' AND CAST(substr("key", -12) AS INTEGER) < :cutoff'
        );
        $purge->bindValue(':prefix', self::KEY_PREFIX . '%');
        $purge->bindValue(':cleanup_key', self::CLEANUP_KEY);
        $purge->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
        $purge->execute();
    }
}
