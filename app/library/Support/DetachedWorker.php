<?php
declare(strict_types=1);

namespace app\library\Support;

/**
 * Spawn proses worker CLI **detached** (pcntl_fork + pcntl_exec, tanpa shell).
 *
 * Pola ini SENGAJA diduplikasi dari `AppController::spawnWorker()` dan
 * `BackupController::spawnWorker()` — duplikasi dicatat sebagai **tech debt**,
 * bukan kelalaian: mengekstrak satu implementasi bersama saat ini berarti
 * menyentuh jalur deploy/backup yang sudah terbukti stabil (dan sudah punya
 * test-nya sendiri). Penyatuan ketiga salinan dilakukan pada refactor tersendiri,
 * bukan sebagai efek samping fitur baru.
 *
 * Alasan `fork` + `exec` (bukan `proc_open`): `proc_open` + `proc_close`
 * BLOCKING sampai worker selesai, sehingga request HTTP yang memanggilnya
 * menggantung selama pekerjaan berjalan (menit-an). Dengan fork + exec:
 *   - request langsung kembali (hanya fork, tanpa menunggu worker);
 *   - worker detached (`posix_setsid`) dan tetap lanjut meski HTTP worker
 *     di-restart atau browser ditutup;
 *   - di HTTP worker SIGCHLD di-`SIG_IGN` (kernel otomatis reap anak ⇒ tanpa
 *     zombie); di anak disposisi direset ke `SIG_DFL` **sebelum** `pcntl_exec`
 *     karena `SIG_IGN` ikut terbawa melewati fork+exec sehingga worker kehilangan
 *     exit code proses anaknya sendiri (git/docker) — lihat `SigchldGuard`.
 *
 * Stateless — aman untuk worker Webman persistent.
 */
final class DetachedWorker
{
    /**
     * Jalankan `$argv` sebagai proses baru yang detached dari sesi pemanggil.
     *
     * `$argv[0]` = program, sisanya argumen (bentuk array ⇒ bebas command
     * injection: langsung `execve`, tanpa shell).
     *
     * @param array<int,string> $argv program + argumen (mentah, tanpa escaping)
     * @param int|null          $pid  diisi pid anak (proses yang di-exec) bila spawn
     *                                berhasil; 0 bila gagal. Dipakai pemanggil untuk
     *                                memantau worker tanpa harus menebak-nebak.
     * @return bool true bila anak berhasil di-fork (bukan jaminan exec berhasil)
     */
    public static function spawn(array $argv, ?int &$pid = null): bool
    {
        $pid = 0;

        if ($argv === [] || !function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            return false;
        }

        // Otomatis-reap anak saat selesai supaya tidak menumpuk zombie.
        @pcntl_signal(SIGCHLD, SIG_IGN);

        $child = @pcntl_fork();
        if ($child === -1) {
            return false;
        }

        if ($child === 0) {
            // Proses anak: lepas dari sesi/terminal, ganti stdio ke /dev/null,
            // lalu ganti image proses menjadi worker.
            @posix_setsid();
            @fclose(STDIN);
            @fclose(STDOUT);
            @fclose(STDERR);
            // Buka ulang fd 0/1/2 ke /dev/null (fd reuse — tidak ada posix_dup2).
            // Variabel sengaja dipertahankan sampai pcntl_exec agar fd tetap terbuka.
            $nullIn = @fopen('/dev/null', 'r');
            $nullOut = @fopen('/dev/null', 'w');
            $nullErr = @fopen('/dev/null', 'w');
            // RESET SIGCHLD ke default sebelum exec: tanpa ini worker mewarisi
            // SIGCHLD=SIG_IGN dari HTTP worker → kernel auto-reap proses anak
            // (docker/restic) saat keluar, sehingga exit code-nya hilang.
            @pcntl_signal(SIGCHLD, SIG_DFL);
            pcntl_exec((string) $argv[0], array_slice($argv, 1));
            // Hanya tercapai bila exec gagal.
            exit(127);
        }

        $pid = (int) $child;

        return true;
    }
}
