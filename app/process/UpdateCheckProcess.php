<?php
declare(strict_types=1);

namespace app\process;

use app\library\Update\RepoInfo;
use app\library\Update\UpdateChecker;
use app\library\Update\UpdateState;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Cek pembaruan dashboard secara berkala (SPECS.md §7.8).
 *
 * Tugasnya hanya menyegarkan `runtime/update/check.json` supaya badge di nav
 * topbar bisa menampilkan "ada pembaruan" tanpa memanggil jaringan dari setiap
 * render halaman. Proses ini sengaja memakai `git ls-remote` (lihat
 * `UpdateChecker`) yang TIDAK menyentuh repo lokal, sehingga aman dijalankan
 * sebagai root di dalam container.
 *
 * Kegagalan (jaringan/DNS mati, remote tidak dikenal) tidak pernah melempar ke
 * luar: pesannya disimpan di check.json dan ditampilkan di panel /nginx.
 */
class UpdateCheckProcess
{
    /** Jeda cek pertama setelah boot (detik) — biar tidak berebut saat start. */
    private const FIRST_DELAY = 20;

    public function onWorkerStart(Worker $worker): void
    {
        if (!(bool) config('deploy.update_enabled', true)) {
            return;
        }
        $interval = (int) config('deploy.update_check_interval', 1800);
        if ($interval <= 0) {
            return; // 0 = tanpa cek berkala (cek manual tetap tersedia di /nginx)
        }

        $task = function (): void {
            try {
                $this->check();
            } catch (\Throwable $e) {
                // Proses persistent: kesalahan apa pun tidak boleh mematikan worker.
                $this->log('cek pembaruan gagal: ' . $e->getMessage());
            }
        };

        Timer::add(self::FIRST_DELAY, $task, [], false);
        Timer::add($interval, $task);
    }

    private function check(): void
    {
        $root = (string) config('deploy.update_path', base_path());
        $checker = new UpdateChecker(
            new RepoInfo($root),
            new UpdateState(
                (string) config('deploy.update_check_file', runtime_path('update/check.json')),
                (string) config('deploy.update_run_dir', runtime_path('logs/update'))
            ),
            (string) config('deploy.update_branch', '')
        );

        $result = $checker->check();
        if (!$result['ok']) {
            $this->log('cek pembaruan: ' . (string) $result['error']);
        }
    }

    private function log(string $message): void
    {
        $dir = runtime_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . '/update-check.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
