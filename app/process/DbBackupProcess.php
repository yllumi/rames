<?php
declare(strict_types=1);

namespace app\process;

use app\library\Storage\DbBackup;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Penjadwal backup basis data SQLite dashboard.
 *
 * Satu tick (tiap {@see INTERVAL} detik) memeriksa {@see DbBackup::isDue()}:
 * bila fitur aktif (`DB_BACKUP_ENABLED`) dan jadwal harian (`DB_BACKUP_HOUR`)
 * belum jalan hari ini → `run('scheduled')`. Kegagalan ditangkap & dicatat ke
 * `runtime/logs/db-backup/{Y-m-d}.log` — proses persistent tidak boleh mati
 * karena error.
 *
 * Catatan: proses BARU di `config/process.php` butuh **restart** (bukan reload)
 * saat pertama kali di-deploy.
 */
class DbBackupProcess
{
    /** Interval tick (detik). */
    private const INTERVAL = 300;

    /** Jeda tick pertama setelah boot (detik). */
    private const FIRST_DELAY = 45;

    public function onWorkerStart(Worker $worker): void
    {
        if (!(bool) config('deploy.db_backup_enabled', true)) {
            return; // fitur mati → tanpa timer sama sekali
        }

        $task = function (): void {
            try {
                $backup = new DbBackup();
                if (!$backup->isDue()) {
                    return;
                }
                $backup->run('scheduled');
            } catch (Throwable $e) {
                // Proses persistent: error apa pun tidak boleh mematikan worker.
                $this->log('tick backup DB gagal: ' . $e->getMessage());
            }
        };

        Timer::add(self::FIRST_DELAY, $task, [], false);
        Timer::add(self::INTERVAL, $task);
    }

    private function log(string $message): void
    {
        $dir = $this->logDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $dir . '/' . date('Y-m-d') . '.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function logDir(): string
    {
        return runtime_path('logs/db-backup');
    }
}
