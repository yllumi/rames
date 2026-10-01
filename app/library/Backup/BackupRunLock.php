<?php
declare(strict_types=1);

namespace app\library\Backup;

use RuntimeException;

/**
 * Kunci run backup lintas-proses (PLAN_VOLUME_BACKUP.md §5.3, §7).
 *
 * Mencegah backup harian (systemd timer) dan backup manual dari UI tumpang
 * tindih — dua run serentak akan berebut lock internal restic (`forget --prune`)
 * dan menggandakan I/O ke volume produksi.
 *
 * Mekanisme: `flock(LOCK_EX|LOCK_NB)` pada `runtime/backup/run.lock`. Bila lock
 * sudah dipegang proses lain, `acquire()` **melempar** pesan jelas (bukan
 * menunggu diam-diam, bukan gagal senyap).
 *
 * Instance memegang handle file (bukan cache lintas-request: ini handle lock
 * yang memang harus hidup selama run). `release()` wajib dipanggil di `finally`
 * (atau pakai `withLock()` yang menjaminnya).
 */
class BackupRunLock
{
    private string $path;
    private mixed $handle = null;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (runtime_path() . '/backup/run.lock');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isHeld(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Ambil lock (non-blocking).
     *
     * @throws RuntimeException lock sedang dipegang proses lain, atau instance ini sudah memegangnya
     */
    public function acquire(): void
    {
        if ($this->handle !== null) {
            throw new RuntimeException('Lock backup sudah dipegang instance ini — jangan acquire dua kali.');
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Tidak bisa membuat direktori lock: {$dir}.");
        }

        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Tidak bisa membuka file lock: {$this->path}.");
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(
                'Backup lain sedang berjalan (lock ' . $this->path . ' dipegang proses lain). '
                . 'Tunggu sampai run sebelumnya selesai.'
            );
        }

        $this->handle = $handle;

        // Diagnostik: siapa & sejak kapan memegang lock (bila run menggantung).
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, (string) getmypid() . ' ' . date('c') . "\n");
        @fflush($handle);
    }

    /**
     * Lepaskan lock. Aman dipanggil walau tidak sedang dipegang.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Jalankan `$work` dengan lock, dan **selalu** lepaskan lewat `finally`.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function withLock(callable $work): mixed
    {
        $this->acquire();
        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    /**
     * Lepaskan lock bila instance dihancurkan tanpa `release()` (worker
     * persistent: handle tidak boleh ikut bocor ke run berikutnya).
     */
    public function __destruct()
    {
        $this->release();
    }
}
