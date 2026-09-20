<?php
declare(strict_types=1);

namespace app\library\Support;

/**
 * Penjaga disposisi SIGCHLD pada worker Webman yang persistent.
 *
 * Masalah
 * -------
 * Dua jalur sengaja menyetel `SIGCHLD = SIG_IGN` agar proses detached
 * (`cli/deploy.php`, sesi terminal) tidak menumpuk zombie:
 * `AppController::spawnWorker()` dan `DockerExec::openInteractive()`.
 *
 * Disposisi `SIG_IGN` **diwariskan melewati fork + exec** (berbeda dari handler
 * yang di-reset ke SIG_DFL) dan menetap selama worker hidup — sehingga SEMUA
 * proses yang di-spawn worker itu setelahnya ikut meng-ignore SIGCHLD. Akibatnya:
 *
 *  1. proses yang memanggil `waitpid()` atas anaknya sendiri mendapat ECHILD —
 *     git → `git-remote-https` / index-pack, `script` → `docker exec`,
 *     `docker compose` → docker:
 *     `error: waitpid for git-remote-https failed: No child process`
 *     `error: waitpid for --shallow-file failed: No child process`
 *     `fatal: index-pack failed`
 *  2. `proc_get_status()` / `proc_close()` tidak bisa membaca exit code (selalu
 *     -1), sehingga perintah yang sukses dianggap gagal.
 *
 * Karena disposisi diwariskan pada saat `fork`, `SIG_DFL` harus dipasang
 * **sebelum** proses di-spawn dan dipertahankan sampai proses itu di-wait
 * (untuk `proc_open`: sampai `proc_close` selesai) — lihat `ProcessRunner::run()`.
 */
final class SigchldGuard
{
    /**
     * Kembalikan SIGCHLD ke SIG_DFL bila sedang di-ignore.
     *
     * @return bool true bila disposisi sebelumnya SIG_IGN (pemanggil wajib
     *              memulihkannya lewat {@see self::ignoreAndReap()})
     */
    public static function disableIgnore(): bool
    {
        if (!self::supported() || @pcntl_signal_get_handler(SIGCHLD) !== SIG_IGN) {
            return false;
        }
        @pcntl_signal(SIGCHLD, SIG_DFL);
        return true;
    }

    /**
     * Pulihkan pola "fire and forget": SIGCHLD di-ignore (kernel auto-reap anak
     * detached) sekaligus membuang sisa anak yang sempat berakhir selama jendela
     * SIG_DFL — tanpa ini anak tersebut tertinggal sebagai zombie.
     */
    public static function ignoreAndReap(): void
    {
        if (!self::supported()) {
            return;
        }
        @pcntl_signal(SIGCHLD, SIG_IGN);
        $status = 0;
        while (@pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // status proses detached — tidak dikonsumsi siapa pun
        }
    }

    /**
     * Jalankan $spawn dengan SIGCHLD=SIG_DFL sehingga proses yang di-fork di
     * dalamnya mewarisi disposisi normal; disposisi sebelumnya dipulihkan setelah
     * $spawn selesai (juga saat $spawn melempar exception).
     *
     * @template T
     * @param callable():T $spawn
     * @return T
     */
    public static function withDefault(callable $spawn): mixed
    {
        $restore = self::disableIgnore();
        try {
            return $spawn();
        } finally {
            if ($restore) {
                self::ignoreAndReap();
            }
        }
    }

    /**
     * Apakah SIGCHLD saat ini di-ignore di proses ini.
     */
    public static function isIgnored(): bool
    {
        return self::supported() && @pcntl_signal_get_handler(SIGCHLD) === SIG_IGN;
    }

    /**
     * Fungsi pcntl tersedia (CLI/worker) — di lingkungan tanpa pcntl semua
     * operasi jadi no-op.
     */
    private static function supported(): bool
    {
        return function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler')
            && function_exists('pcntl_waitpid')
            && defined('SIGCHLD')
            && defined('SIG_DFL')
            && defined('SIG_IGN')
            && defined('WNOHANG');
    }
}
