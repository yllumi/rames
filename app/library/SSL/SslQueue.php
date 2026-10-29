<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Storage\SqliteDatabase;
use PDO;
use Throwable;

/**
 * Antrean aktivasi SSL (docs/SPECS.md §8a) — **satu job pada satu waktu**
 * lintas seluruh domain (user story SSL antrean).
 *
 * Kenapa antrean: certbot memakai lock global (`/var/lib/letsencrypt/.certbot.lock`)
 * dan Let's Encrypt punya rate limit per akun. Menjalankan beberapa worker SSL
 * paralel (satu per domain) membuat sebagian besar gagal karena lock/rate limit
 * — bukan karena domainnya bermasalah. Dengan antrean, hanya satu proses
 * certbot yang berjalan; sisanya menunggu, dan UI menampilkan posisinya.
 *
 * Tabel `ssl_jobs` (migrasi skema v5) adalah sumber kebenaran antrean. Status
 * baris:
 *   - `queued`  — menunggu; `available_at` = waktu paling awal boleh dijalankan;
 *   - `running` — sedang dikerjakan (diklaim {@see self::claimNext()});
 *   - `done`    — worker melaporkan sukses (`cli/*.php` sudah menulis status
 *                 `active` + `expires_at` ke target);
 *   - `failed`  — gagal permanen / percobaan habis / job macet.
 *
 * Dedupe: satu dedupe key hanya boleh punya **satu** job aktif (ditegakkan
 * indeks parsial `uniq_ssl_jobs_active`). Permintaan kedua untuk domain yang
 * sama mengembalikan job yang sudah ada (`queued=false`) beserta posisinya.
 *
 * Tanpa state lintas-request: seluruh data ada di SQLite (WAL +
 * `BEGIN IMMEDIATE`). Instance kelas ini hanya memegang seam basis data.
 */
final class SslQueue
{
    /** Kunci klaim prune oportunistik di tabel `kv` (pola `ErrorStore`). */
    public const PRUNE_KEY = 'ssl_queue_prune_at';

    /** Jeda minimum antar-prune (detik, lintas proses). */
    private const PRUNE_INTERVAL_SECONDS = 3600;

    /** Backoff default per percobaan gagal (detik): 30 lalu 120. */
    private const DEFAULT_BACKOFF = [30, 120];

    /** Batas panjang `last_error` di basis data. */
    private const MAX_ERROR_LENGTH = 2000;

    /** Kesalahan yang TIDAK akan pulih dengan mengulang percobaan. */
    private const NON_RETRYABLE = [
        'too many certificates',
        'ratelimit',
        'rate limit',
        'too many failed authorizations',
        'invalid response',
        'unauthorized',
        'nxdomain',
        'dns problem',
        'no valid a/b records',
        'bukan domain publik',
        'admin_email belum',
        'webroot',
        '404',
    ];

    /** Kesalahan transien: lock certbot lain, jaringan, atau service 5xx. */
    private const RETRYABLE = [
        'another instance of certbot',
        'certbot.lock',
        'locked',
        'timeout',
        'timed out',
        'temporar',
        'connection reset',
        'connection refused',
        'could not resolve',
        'network is unreachable',
        'service unavailable',
        '502',
        '503',
        '504',
        'curl error',
        'agent tidak dapat dihubungi',
    ];

    /** Seam basis data (null ⇒ `config('deploy.sqlite_file')`). */
    private readonly ?SqliteDatabase $database;

    public function __construct(?SqliteDatabase $database = null)
    {
        $this->database = $database;
    }

    // ==================================================================
    // Enqueue
    // ==================================================================

    /**
     * Masukkan permintaan SSL satu app ke antrean.
     *
     * @param array<string,mixed> $app
     * @return array{job:array<string,mixed>,queued:bool,position:?int,size:int}
     */
    public function enqueueApp(array $app, string $domain, string $slot, ?string $actorId = null): array
    {
        $appId = trim((string) ($app['id'] ?? ''));
        $normalizedDomain = strtolower(trim($domain));
        $normalizedSlot = $slot === 'custom_ssl' ? 'custom_ssl' : 'ssl';

        return $this->persist([
            'dedupe_key' => 'app:' . $appId . ':' . $normalizedDomain . ':' . $normalizedSlot,
            'target_type' => 'app',
            'app_id' => $appId,
            'parked_id' => null,
            'server_id' => self::nullable((string) ($app['server_id'] ?? '')),
            'domain' => $normalizedDomain,
            'slot' => $normalizedSlot,
            'actor_id' => self::nullable((string) ($actorId ?? '')),
        ]);
    }

    /**
     * Masukkan permintaan SSL satu domain parkir ke antrean.
     *
     * @param array<string,mixed> $entry
     * @return array{job:array<string,mixed>,queued:bool,position:?int,size:int}
     */
    public function enqueueParked(array $entry, ?string $actorId = null): array
    {
        $parkedId = trim((string) ($entry['id'] ?? ''));
        $normalizedDomain = strtolower(trim((string) ($entry['domain'] ?? '')));

        return $this->persist([
            'dedupe_key' => 'parked:' . $parkedId . ':' . $normalizedDomain,
            'target_type' => 'parked',
            'app_id' => null,
            'parked_id' => $parkedId,
            'server_id' => self::nullable((string) ($entry['server_id'] ?? '')),
            'domain' => $normalizedDomain,
            'slot' => null,
            'actor_id' => self::nullable((string) ($actorId ?? '')),
        ]);
    }

    /**
     * INSERT job baru atau kembalikan job aktif dengan dedupe key yang sama.
     *
     * `BEGIN IMMEDIATE` menyerialkan penulis sehingga SELECT-lalu-INSERT tidak
     * bisa balapan; indeks parsial unik menjadi jaring pengaman terakhir.
     *
     * @param array<string,mixed> $job
     * @return array{job:array<string,mixed>,queued:bool,position:?int,size:int}
     */
    private function persist(array $job): array
    {
        $dedupeKey = (string) $job['dedupe_key'];

        $result = $this->db()->transaction(function (PDO $pdo) use ($job, $dedupeKey): array {
            $existing = $this->activeRow($pdo, $dedupeKey);
            if ($existing !== null) {
                return ['job' => $existing, 'queued' => false];
            }

            $now = self::now();
            $id = bin2hex(random_bytes(8));
            $pdo->prepare(
                'INSERT INTO "ssl_jobs" ("id","dedupe_key","target_type","app_id","parked_id",'
                . '"server_id","domain","slot","actor_id","status","attempts","max_attempts",'
                . '"available_at","created_at","started_at","finished_at","last_error")'
                . ' VALUES (?,?,?,?,?,?,?,?,?,\'queued\',0,?,?,?,NULL,NULL,NULL)'
            )->execute([
                $id,
                $dedupeKey,
                (string) $job['target_type'],
                $job['app_id'],
                $job['parked_id'],
                $job['server_id'],
                (string) $job['domain'],
                $job['slot'],
                $job['actor_id'],
                $this->maxAttempts(),
                $now,
                $now,
            ]);

            $row = $this->findById($pdo, $id);

            return ['job' => $row ?? [], 'queued' => true];
        });

        $jobId = (string) ($result['job']['id'] ?? '');
        $this->maybePrune();

        return [
            'job' => $result['job'],
            'queued' => (bool) $result['queued'],
            'position' => $jobId === '' ? null : $this->position($jobId),
            'size' => $this->queueSize(),
        ];
    }

    // ==================================================================
    // Drainer
    // ==================================================================

    /**
     * Klaim job berikutnya (FIFO) untuk dijalankan.
     *
     * Dalam satu transaksi tulis:
     *  1. job `running` yang `started_at`-nya lebih tua dari `$staleSeconds`
     *     ditandai `failed` (proses drainer mati di tengah jalan);
     *  2. job `queued` paling tua yang `available_at`-nya sudah lewat diambil,
     *     ditandai `running`, dan `attempts` dinaikkan.
     *
     * @return array<string,mixed>|null baris job yang diklaim
     */
    public function claimNext(int $staleSeconds = 900): ?array
    {
        $stale = max(0, $staleSeconds);

        return $this->db()->transaction(function (PDO $pdo) use ($stale): ?array {
            $now = self::now();

            if ($stale > 0) {
                $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $stale);
                $pdo->prepare(
                    'UPDATE "ssl_jobs" SET "status" = \'failed\', "last_error" = ?, "finished_at" = ?'
                    . ' WHERE "status" = \'running\' AND COALESCE("started_at", "created_at") <= ?'
                )->execute([
                    sprintf('Job SSL macet > %d menit.', max(1, intdiv($stale, 60))),
                    $now,
                    $cutoff,
                ]);
            }

            $stmt = $pdo->prepare(
                'SELECT * FROM "ssl_jobs"'
                . ' WHERE "status" = \'queued\' AND "available_at" <= ?'
                . ' ORDER BY "created_at", "id" LIMIT 1'
            );
            $stmt->execute([$now]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                return null;
            }

            $pdo->prepare(
                'UPDATE "ssl_jobs" SET "status" = \'running\', "attempts" = "attempts" + 1,'
                . ' "started_at" = ? WHERE "id" = ?'
            )->execute([$now, (string) $row['id']]);

            $row['status'] = 'running';
            $row['attempts'] = (int) $row['attempts'] + 1;
            $row['started_at'] = $now;

            return $row;
        });
    }

    /**
     * Tandai job selesai (status target sudah ditulis worker `cli/*.php`).
     */
    public function complete(string $jobId): void
    {
        $this->db()->pdo()->prepare(
            'UPDATE "ssl_jobs" SET "status" = \'done\', "finished_at" = ? WHERE "id" = ?'
        )->execute([self::now(), $jobId]);
    }

    /**
     * Catat kegagalan job.
     *
     * `$retryable` + sisa percobaan ⇒ job kembali `queued` dengan
     * `available_at = now + backoff[attempts-1]` (30 dtk, lalu 120 dtk).
     * Selain itu job `failed` permanen.
     *
     * @return array{status:string,attempts:int,available_at:?string}
     */
    public function fail(string $jobId, string $error, bool $retryable): array
    {
        return $this->db()->transaction(function (PDO $pdo) use ($jobId, $error, $retryable): array {
            $job = $this->findById($pdo, $jobId);
            if ($job === null) {
                return ['status' => 'failed', 'attempts' => 0, 'available_at' => null];
            }

            $attempts = max(0, (int) ($job['attempts'] ?? 0));
            $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
            $text = self::truncate($error, self::MAX_ERROR_LENGTH);

            if ($retryable && $attempts < $maxAttempts) {
                $delays = $this->backoff();
                $delay = $delays[$attempts - 1] ?? $delays[count($delays) - 1];
                $availableAt = gmdate('Y-m-d\TH:i:s\Z', time() + max(0, $delay));
                $pdo->prepare(
                    'UPDATE "ssl_jobs" SET "status" = \'queued\', "available_at" = ?,'
                    . ' "started_at" = NULL, "last_error" = ? WHERE "id" = ?'
                )->execute([$availableAt, $text, $jobId]);

                return ['status' => 'queued', 'attempts' => $attempts, 'available_at' => $availableAt];
            }

            $pdo->prepare(
                'UPDATE "ssl_jobs" SET "status" = \'failed\', "finished_at" = ?,'
                . ' "last_error" = ? WHERE "id" = ?'
            )->execute([self::now(), $text, $jobId]);

            return ['status' => 'failed', 'attempts' => $attempts, 'available_at' => null];
        });
    }

    // ==================================================================
    // Pembacaan
    // ==================================================================

    /**
     * Posisi 1-based job di antrean.
     *
     * Job `running` selalu posisi 1 (sedang diproses, paling depan); job
     * `queued` dihitung dari urutan `created_at, id`. Job selesai/gagal ⇒ null.
     */
    public function position(string $jobId): ?int
    {
        $pdo = $this->db()->pdo();
        $job = $this->findById($pdo, $jobId);
        if ($job === null) {
            return null;
        }

        $status = (string) ($job['status'] ?? '');
        if ($status === 'running') {
            return 1;
        }
        if ($status !== 'queued') {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM "ssl_jobs" WHERE "status" = \'queued\''
            . ' AND ("created_at" < ? OR ("created_at" = ? AND "id" <= ?))'
        );
        $stmt->execute([(string) $job['created_at'], (string) $job['created_at'], $jobId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Jumlah job yang masih di antrean (`queued` + `running`).
     */
    public function queueSize(): int
    {
        $stmt = $this->db()->pdo()->query(
            'SELECT COUNT(*) FROM "ssl_jobs" WHERE "status" IN (\'queued\',\'running\')'
        );

        return (int) $stmt->fetchColumn();
    }

    /**
     * Job aktif (queued/running) untuk satu dedupe key.
     *
     * @return array<string,mixed>|null
     */
    public function findActive(string $dedupeKey): ?array
    {
        return $this->activeRow($this->db()->pdo(), $dedupeKey);
    }

    /**
     * Tambahkan `ssl_queue_position`/`ssl_queue_size` pada baris domain.
     *
     * Hanya baris `ssl_status === 'pending'` yang dihitung; baris lain (dan
     * baris pending tanpa job aktif) mendapat null. **Fail-safe**: basis data
     * rusak/locked tidak boleh menggagalkan render halaman `/domains` — posisi
     * antrean hanya pelengkap tampilan.
     *
     * @param array<int|string,mixed> $rows
     * @return array<int|string,mixed>
     */
    public function decorate(array $rows): array
    {
        $positions = [];
        $size = null;

        if ($this->hasPendingRow($rows)) {
            try {
                $size = $this->queueSize();
                foreach ($rows as $index => $row) {
                    if (!is_array($row) || (string) ($row['ssl_status'] ?? '') !== 'pending') {
                        continue;
                    }
                    $key = self::dedupeKeyOf($row);
                    if ($key === null) {
                        continue;
                    }
                    $job = $this->findActive($key);
                    if ($job === null) {
                        continue;
                    }
                    $position = $this->position((string) ($job['id'] ?? ''));
                    if ($position !== null) {
                        $positions[$index] = $position;
                    }
                }
            } catch (Throwable) {
                // abaikan: baris tetap dirender tanpa posisi antrean
                $positions = [];
                $size = null;
            }
        }

        $decorated = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $decorated[$index] = $row;
                continue;
            }
            $position = $positions[$index] ?? null;
            $row['ssl_queue_position'] = $position;
            $row['ssl_queue_size'] = $position === null ? null : $size;
            $decorated[$index] = $row;
        }

        return $decorated;
    }

    // ==================================================================
    // Retensi
    // ==================================================================

    /**
     * Hapus job `done`/`failed` yang lebih tua dari `$days` hari.
     */
    public function prune(int $days = 7): int
    {
        $age = max(0, $days);
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $age * 86400);

        $stmt = $this->db()->pdo()->prepare(
            'DELETE FROM "ssl_jobs" WHERE "status" IN (\'done\',\'failed\')'
            . ' AND COALESCE("finished_at", "created_at") <= ?'
        );
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Prune oportunistik maksimal sekali per jam, diklaim atomic di `kv`.
     *
     * Kegagalan di sini tidak boleh menggagalkan enqueue — retensi akan dicoba
     * lagi pada permintaan berikutnya.
     */
    private function maybePrune(): void
    {
        try {
            $pdo = $this->db()->pdo();
            $now = time();
            $threshold = $now - self::PRUNE_INTERVAL_SECONDS;

            $claim = $pdo->prepare(
                'INSERT INTO "kv" ("key","value") VALUES (:key, :now)'
                . ' ON CONFLICT("key") DO UPDATE SET "value" = excluded."value"'
                . ' WHERE CAST("value" AS INTEGER) <= :threshold'
            );
            $claim->bindValue(':key', self::PRUNE_KEY);
            $claim->bindValue(':now', $now, PDO::PARAM_INT);
            $claim->bindValue(':threshold', $threshold, PDO::PARAM_INT);
            $claim->execute();

            if ($claim->rowCount() !== 1) {
                return; // proses lain baru saja menjalankan prune
            }

            $this->prune($this->pruneDays());
        } catch (Throwable) {
            // abaikan: retensi bersifat oportunistik
        }
    }

    // ==================================================================
    // Klasifikasi error
    // ==================================================================

    /**
     * Apakah pesan kegagalan layak diulang otomatis?
     *
     * Non-retryable diperiksa lebih dulu: pesan bisa memuat kata transien
     * sekaligus penyebab permanen (mis. rate limit + timeout). Default **false**
     * — tanpa bukti transien, job langsung dianggap gagal permanen.
     */
    public static function isRetryable(string $error): bool
    {
        $text = strtolower(trim($error));
        if ($text === '') {
            return false;
        }

        foreach (self::NON_RETRYABLE as $needle) {
            if (str_contains($text, $needle)) {
                return false;
            }
        }
        foreach (self::RETRYABLE as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Kunci dedupe dari satu baris domain (`SslDomainRows`).
     *
     * @param array<string,mixed> $row
     */
    private static function dedupeKeyOf(array $row): ?string
    {
        $domain = strtolower(trim((string) ($row['domain'] ?? '')));
        if ($domain === '') {
            return null;
        }

        if ((string) ($row['kind'] ?? 'app') === 'parked') {
            $parkedId = trim((string) ($row['parked_id'] ?? $row['id'] ?? ''));

            return $parkedId === '' ? null : 'parked:' . $parkedId . ':' . $domain;
        }

        $appId = trim((string) ($row['app_id'] ?? $row['id'] ?? ''));
        if ($appId === '') {
            return null;
        }
        $slot = (string) ($row['ssl_slot'] ?? '');
        if ($slot === '') {
            $slot = !empty($row['is_custom']) ? 'custom_ssl' : 'ssl';
        }

        return 'app:' . $appId . ':' . $domain . ':' . $slot;
    }

    /**
     * @param array<int|string,mixed> $rows
     */
    private function hasPendingRow(array $rows): bool
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['ssl_status'] ?? '') === 'pending') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|null */
    private function activeRow(PDO $pdo, string $dedupeKey): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM "ssl_jobs" WHERE "dedupe_key" = ?'
            . ' AND "status" IN (\'queued\',\'running\') ORDER BY "created_at", "id" LIMIT 1'
        );
        $stmt->execute([$dedupeKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function findById(PDO $pdo, string $jobId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM "ssl_jobs" WHERE "id" = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('deploy.ssl_queue_max_attempts', 3));
    }

    private function pruneDays(): int
    {
        return max(0, (int) config('deploy.ssl_queue_prune_days', 7));
    }

    /**
     * Jeda backoff per percobaan (detik) — tetap `[30,120]` kecuali dioverride
     * konfigurasi (dipakai tes).
     *
     * @return array<int,int>
     */
    private function backoff(): array
    {
        $configured = config('deploy.ssl_queue_backoff', self::DEFAULT_BACKOFF);
        if (!is_array($configured) || $configured === []) {
            return self::DEFAULT_BACKOFF;
        }

        $delays = [];
        foreach ($configured as $value) {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $delays[] = max(0, (int) $value);
            }
        }

        return $delays === [] ? self::DEFAULT_BACKOFF : $delays;
    }

    private function db(): SqliteDatabase
    {
        return $this->database ?? new SqliteDatabase();
    }

    private static function nullable(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function truncate(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit);
        }

        return substr($value, 0, $limit);
    }

    /** Timestamp kanonik UTC (`Y-m-d\TH:i:s\Z`) — bisa diurutkan leksikografis. */
    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
