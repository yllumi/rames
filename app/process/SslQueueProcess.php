<?php
declare(strict_types=1);

namespace app\process;

use app\library\SSL\SslQueueWorker;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Drainer antrean aktivasi SSL (docs/SPECS.md §8a) — **satu job pada satu
 * waktu** lintas seluruh domain.
 *
 * Certbot memakai lock global dan Let's Encrypt punya rate limit, jadi beberapa
 * domain tidak boleh diminta sertifikatnya bersamaan. Controller hanya
 * *menulis* job ke tabel `ssl_jobs`; proses inilah yang mengambil dan
 * menjalankannya lewat {@see SslQueueWorker}.
 *
 * Hanya dijalankan di mode `dashboard` (agent tidak memiliki store otoritatif
 * maupun worker certbot lokal): `config/process.php` mendaftarkannya di blok
 * `!$ramesIsAgent`, dan `onWorkerStart()` tetap memeriksa mode sebagai
 * pertahanan kedua.
 *
 * Proses persistent: SEMUA `Throwable` ditangkap dan dicatat ke
 * `runtime/logs/ssl/queue.log` (tanpa secret) — worker tidak boleh mati.
 * Properti `$busy` hanyalah penjaga re-entrancy timer di dalam proses ini
 * (bukan state lintas-request).
 *
 * CATATAN DEPLOY: proses BARU → butuh **restart** (bukan reload) saat pertama
 * kali dipasang.
 */
class SslQueueProcess
{
    /** Jeda tick pertama setelah boot (detik) — biar tidak berebut saat start. */
    private const FIRST_DELAY = 10;

    /** Interval tick berikutnya (detik). */
    private const INTERVAL = 5;

    /** Penjaga re-entrancy: tick berikutnya dilewati selama tick berjalan. */
    private bool $busy = false;

    /**
     * Seam uji: pengganti satu putaran drainer.
     *
     * @var (\Closure():mixed)|null
     */
    private readonly ?\Closure $run;

    /** Path log eksplisit (seam uji); null ⇒ `runtime/logs/ssl/queue.log`. */
    private readonly ?string $logFile;

    public function __construct(?\Closure $run = null, ?string $logFile = null)
    {
        $this->run = $run;
        $this->logFile = $logFile;
    }

    public function onWorkerStart(Worker $worker): void
    {
        if ((string) config('deploy.mode', 'dashboard') === 'agent') {
            return; // agent tidak menjalankan antrean SSL
        }

        $task = function (): void {
            $this->tick();
        };

        Timer::add(self::FIRST_DELAY, $task, [], false);
        Timer::add(self::INTERVAL, $task);
    }

    /**
     * Satu putaran drainer — tidak pernah melempar.
     *
     * Publik supaya bisa diuji tanpa timer Workerman.
     */
    public function tick(): void
    {
        if ($this->busy) {
            return;
        }
        $this->busy = true;

        try {
            $run = $this->run ?? static fn (): string => (new SslQueueWorker())->tick();
            $run();
        } catch (Throwable $e) {
            // Proses persistent: error apa pun tidak boleh mematikan worker.
            $this->log('tick antrean SSL gagal: ' . $e->getMessage());
        } finally {
            $this->busy = false;
        }
    }

    private function log(string $message): void
    {
        $file = $this->logFile ?? runtime_path('logs/ssl/queue.log');
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $file,
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
