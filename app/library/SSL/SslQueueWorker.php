<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use app\library\Support\ProcessRunner;
use Throwable;

/**
 * Drainer antrean SSL (docs/SPECS.md §8a) — satu tick = satu job.
 *
 * Dipanggil `app\process\SslQueueProcess` tiap beberapa detik. Setiap tick:
 *   1. klaim job berikutnya ({@see SslQueue::claimNext()}); tidak ada ⇒ `idle`;
 *   2. pastikan target (app/parked) masih ada — hilang ⇒ job gagal permanen;
 *   3. tandai target `pending`/`queued` + pesan "Memproses ..." supaya UI
 *      tidak menampilkan status basi selama certbot berjalan;
 *   4. jalankan worker yang sudah ada (`cli/ssl.php` / `cli/parked-ssl.php`)
 *      lewat {@see ProcessRunner} (array + `bypass_shell`, timeout);
 *   5. exit 0 ⇒ `done`; selain itu klasifikasikan pesan galat
 *      ({@see SslQueue::isRetryable()}) lalu `fail()` — retry berarti target
 *      kembali `pending` dengan pesan "Percobaan ulang N/M ...".
 *
 * Logika penerbitan sertifikat TIDAK diduplikasi di sini: seluruh pipeline
 * certbot/Nginx tetap milik skrip CLI; kelas ini hanya pelaksana antrean.
 *
 * Seam uji: `$queue` dan `$executor` (callable yang menerima array command dan
 * mengembalikan `{code,stdout,stderr,timedOut}`) — tes tidak pernah menjalankan
 * certbot. Tidak ada state lintas-request; properti hanya readonly/seam.
 */
final class SslQueueWorker
{
    /** Pesan saat job mulai dijalankan (target kembali `pending`). */
    public const MESSAGE_RUNNING = 'Memproses penerbitan SSL ...';

    private readonly SslQueue $queue;

    /** @var \Closure(array<int,string>):array<string,mixed> */
    private readonly \Closure $executor;

    /** Batas waktu satu worker SSL (detik; 0 = tanpa batas). */
    private readonly int $timeout;

    /** Umur maksimum job `running` sebelum dianggap macet (detik). */
    private readonly int $staleSeconds;

    /**
     * @param SslQueue|null $queue    seam uji basis data
     * @param callable|null $executor fn(array<int,string> $command): array
     */
    public function __construct(?SslQueue $queue = null, ?callable $executor = null)
    {
        $this->queue = $queue ?? new SslQueue();
        $this->timeout = max(0, (int) config('deploy.ssl_queue_timeout', 900));
        $this->staleSeconds = max(0, (int) config('deploy.ssl_queue_stale_seconds', 900));
        $this->executor = $executor !== null
            ? \Closure::fromCallable($executor)
            : function (array $command): array {
                return (new ProcessRunner())->run($command, base_path(), $this->timeout);
            };
    }

    /**
     * Proses satu job (bila ada).
     *
     * @return string `idle` | `done` | `retry` | `failed`
     */
    public function tick(): string
    {
        $job = $this->queue->claimNext($this->staleSeconds);
        if ($job === null) {
            return 'idle';
        }

        $jobId = (string) ($job['id'] ?? '');
        $targetType = (string) ($job['target_type'] ?? '');
        $domain = strtolower(trim((string) ($job['domain'] ?? '')));

        if ($targetType === 'app') {
            return $this->runApp($job, $jobId, $domain);
        }
        if ($targetType === 'parked') {
            return $this->runParked($job, $jobId);
        }

        return $this->failJob($job, $jobId, 'Jenis target job SSL tidak dikenal: ' . $targetType, false);
    }

    /**
     * Jalankan job milik app (`cli/ssl.php <appId> <domain>`).
     *
     * @param array<string,mixed> $job
     */
    private function runApp(array $job, string $jobId, string $domain): string
    {
        $appId = (string) ($job['app_id'] ?? '');
        $slot = (string) ($job['slot'] ?? 'ssl');
        if ($slot !== 'custom_ssl') {
            $slot = 'ssl';
        }

        try {
            $app = $this->findApp($appId);
        } catch (Throwable $e) {
            // Kegagalan membaca store bisa transien (mis. `database is locked`).
            $message = 'Gagal membaca app untuk job SSL: ' . $e->getMessage();

            return $this->failJob($job, $jobId, $message, SslQueue::isRetryable($message));
        }
        if ($app === null) {
            return $this->failJob($job, $jobId, 'App untuk job SSL tidak ditemukan (mungkin sudah dihapus).', false);
        }

        $this->writeAppSlot($appId, $slot, [
            'status' => 'pending',
            'stage' => 'queued',
            'message' => self::MESSAGE_RUNNING,
            'error' => null,
        ]);

        return $this->runCommand($job, $jobId, [PHP_BINARY, base_path('cli/ssl.php'), $appId, $domain]);
    }

    /**
     * Jalankan job milik domain parkir (`cli/parked-ssl.php <id>`).
     *
     * @param array<string,mixed> $job
     */
    private function runParked(array $job, string $jobId): string
    {
        $parkedId = (string) ($job['parked_id'] ?? '');
        try {
            $entry = $this->findParked($parkedId);
        } catch (Throwable $e) {
            $message = 'Gagal membaca domain parkir untuk job SSL: ' . $e->getMessage();

            return $this->failJob($job, $jobId, $message, SslQueue::isRetryable($message));
        }
        if ($entry === null) {
            return $this->failJob($job, $jobId, 'Domain parkir untuk job SSL tidak ditemukan (mungkin sudah dihapus).', false);
        }

        $this->writeParked($parkedId, [
            'ssl_status' => 'pending',
            'ssl_stage' => 'queued',
            'ssl_message' => self::MESSAGE_RUNNING,
            'ssl_error' => null,
        ]);

        return $this->runCommand($job, $jobId, [PHP_BINARY, base_path('cli/parked-ssl.php'), $parkedId]);
    }

    /**
     * Eksekusi command worker lalu terjemahkan hasilnya ke status antrean.
     *
     * @param array<string,mixed> $job
     * @param array<int,string>   $command
     */
    private function runCommand(array $job, string $jobId, array $command): string
    {
        $result = $this->execute($command);
        if ((int) ($result['code'] ?? 1) === 0) {
            $this->queue->complete($jobId);

            return 'done';
        }

        $text = trim((string) ($result['stdout'] ?? '') . "\n" . (string) ($result['stderr'] ?? ''));
        if (!empty($result['timedOut'])) {
            $text = trim($text . "\nProses penerbitan SSL timeout setelah {$this->timeout} detik.");
        }
        if ($text === '') {
            $text = 'Proses penerbitan SSL keluar dengan kode ' . (int) ($result['code'] ?? 1) . '.';
        }
        // Batasi ukuran pesan yang diproses (output certbot bisa sangat panjang);
        // `SslQueue::fail()` memotong lagi untuk kolom `last_error`.
        $text = self::bound($text);

        return $this->failJob($job, $jobId, $text, SslQueue::isRetryable($text));
    }

    /**
     * Catat kegagalan + sinkronkan status target.
     *
     * Retry ⇒ target kembali `pending` dengan pesan percobaan ulang; gagal ⇒
     * target `failed` + `error` (worker CLI biasanya sudah menulis `failed`;
     * menimpa dengan pesan yang sama aman).
     *
     * @param array<string,mixed> $job
     */
    private function failJob(array $job, string $jobId, string $error, bool $retryable): string
    {
        $outcome = $this->queue->fail($jobId, $error, $retryable);

        if ((string) ($outcome['status'] ?? 'failed') === 'queued') {
            $attempts = (int) ($outcome['attempts'] ?? 0);
            $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
            $this->syncTarget($job, [
                'status' => 'pending',
                'stage' => 'queued',
                'message' => 'Percobaan ulang ' . $attempts . '/' . $maxAttempts . ' ...',
                'error' => null,
            ]);

            return 'retry';
        }

        $this->syncTarget($job, [
            'status' => 'failed',
            'stage' => null,
            'message' => null,
            'error' => $error,
        ]);

        return 'failed';
    }

    /**
     * Tulis status ke target (app/parked) bila targetnya masih ada.
     *
     * @param array<string,mixed>          $job
     * @param array<string,mixed>          $fields status/stage/message/error
     */
    private function syncTarget(array $job, array $fields): void
    {
        if ((string) ($job['target_type'] ?? '') === 'app') {
            $slot = (string) ($job['slot'] ?? 'ssl');
            $this->writeAppSlot((string) ($job['app_id'] ?? ''), $slot !== 'custom_ssl' ? 'ssl' : $slot, $fields);

            return;
        }

        if ((string) ($job['target_type'] ?? '') === 'parked') {
            $this->writeParked((string) ($job['parked_id'] ?? ''), [
                'ssl_status' => $fields['status'] ?? 'failed',
                'ssl_stage' => $fields['stage'] ?? null,
                'ssl_message' => $fields['message'] ?? null,
                'ssl_error' => $fields['error'] ?? null,
            ]);
        }
    }

    /**
     * Tulis field slot SSL app (`{slot}_status`, `{slot}_stage`, …).
     *
     * Best-effort: kegagalan menulis status target tidak boleh menggagalkan
     * pencatatan job (sumber kebenaran antrean).
     *
     * @param array<string,string|null> $fields
     */
    private function writeAppSlot(string $appId, string $slot, array $fields): void
    {
        if ($appId === '') {
            return;
        }
        try {
            (new AppStore())->update($appId, static function (array &$row) use ($slot, $fields): void {
                foreach ($fields as $name => $value) {
                    $row[$slot . '_' . $name] = $value;
                }
            });
        } catch (Throwable) {
            // abaikan: status target adalah pelengkap tampilan
        }
    }

    /**
     * Tulis status SSL entri domain parkir (best-effort).
     *
     * @param array<string,mixed> $fields
     */
    private function writeParked(string $parkedId, array $fields): void
    {
        if ($parkedId === '') {
            return;
        }
        try {
            (new ParkedDomainStore())->update($parkedId, static function (array &$row) use ($fields): void {
                foreach ($fields as $name => $value) {
                    $row[$name] = $value;
                }
            });
        } catch (Throwable) {
            // abaikan: status target adalah pelengkap tampilan
        }
    }

    /**
     * Target app masih ada?
     *
     * @return array<string,mixed>|null
     * @throws Throwable kegagalan store diteruskan agar `tick()` bisa
     *                  mengklasifikasikannya (transien vs permanen)
     */
    private function findApp(string $appId): ?array
    {
        if ($appId === '') {
            return null;
        }

        return (new AppStore())->find($appId);
    }

    /**
     * Target domain parkir masih ada?
     *
     * @return array<string,mixed>|null
     * @throws Throwable
     */
    private function findParked(string $parkedId): ?array
    {
        if ($parkedId === '') {
            return null;
        }

        return (new ParkedDomainStore())->find($parkedId);
    }

    /** Potong pesan ke batas aman (4000 karakter) tanpa memotong byte UTF-8. */
    private static function bound(string $text, int $limit = 4000): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit);
        }

        return substr($text, 0, $limit);
    }

    /**
     * Jalankan executor; exception di dalamnya diterjemahkan menjadi hasil
     * gagal (bukan exception yang lolos keluar `tick()`).
     *
     * @param array<int,string> $command
     * @return array<string,mixed>
     */
    private function execute(array $command): array
    {
        try {
            $result = ($this->executor)($command);
        } catch (Throwable $e) {
            return [
                'code' => 1,
                'stdout' => '',
                'stderr' => 'Gagal menjalankan worker SSL: ' . $e->getMessage(),
                'timedOut' => false,
            ];
        }

        return is_array($result)
            ? $result
            : ['code' => 1, 'stdout' => '', 'stderr' => 'Executor antrean SSL tidak mengembalikan hasil.', 'timedOut' => false];
    }
}
