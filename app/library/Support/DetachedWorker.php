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
 * Lingkungan (`envp`) — **jangan** disamakan dengan pewarisan:
 * `pcntl_exec($path, $args, $envp)` memakai `execve()`, dan begitu argumen ke-3
 * diberikan, lingkungan proses baru adalah **persis** isi `$envp` — bukan
 * tambahan di atas lingkungan lama. Array kosong **bukan** berarti "warisi":
 * anak sungguhan lahir tanpa satu pun variabel (`getenv('PATH') === false`,
 * sudah dibuktikan dengan probe di container `rames-webman`). Konsekuensinya
 * nyata di produksi (dashboard `RAMES_DB_DIR=/var/lib/rames`): worker deploy
 * kehilangan `RAMES_DB_DIR` ⇒ jatuh ke default `<repo>/database` (bukan store
 * runtime) ⇒ app tidak ada di store yang salah itu ⇒ `App tidak ditemukan` dan
 * keluar senyap **sebelum** menulis
 * `runtime/logs/deploy/<appId>.log`, sehingga app tertinggal
 * `status=deploying, stage=queued` selamanya. Kehilangan `PATH`/`HOME` juga
 * membuat `docker`/`git` (dipanggil worker) berperilaku beda — mis. `HOME`
 * menentukan lokasi `~/.docker/config.json` dan cache credential git.
 * Karena itu lingkungan saat ini **selalu** digabung eksplisit di
 * {@see self::spawn()}.
 *
 * Efek luas yang ikut pulih: mode runtime server **agent** juga dibaca dari
 * lingkungan (`RAMES_MODE` ⇒ `config('deploy.mode')`, `config/deploy.php:42-46`),
 * jadi worker agent tanpa env terlihat sebagai `dashboard` ⇒ gerbang
 * `cli/deploy.php` menolak `self_update` ("hanya berlaku di server agent") dan
 * op agent lain kehilangan identitasnya. Dengan penggabungan ini worker agent
 * menerima `RAMES_JOB_ID` **beserta** seluruh lingkungan asalnya.
 *
 * GUARDRAIL: lingkungan anak memuat rahasia (`AGENT_TOKEN`, kredensial
 * restic/S3/DB dari `.env` yang dimuat Dotenv ke proses), sama seperti proses
 * Webman pemanggilnya — jangan pernah mencetak/mengirim environment ke log,
 * respons HTTP, atau UI; `/proc/<pid>/environ` anak juga memuat rahasia itu.
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
     * @param array<int,string>    $argv program + argumen (mentah, tanpa escaping)
     * @param int|null             $pid  diisi pid anak (proses yang di-exec) bila spawn
     *                                   berhasil; 0 bila gagal. Dipakai pemanggil untuk
     *                                   memantau worker tanpa harus menebak-nebak.
     * @param array<string,string> $env  variabel lingkungan **tambahan** yang
     *                                   di-overlay ke worker. Produksi: hanya
     *                                   `RAMES_JOB_ID` (id job agent) yang diisi, dan
     *                                   itu pun cuma oleh `DeployWorkerLauncher::launch()`
     *                                   saat memuat job agent — jalur lain mengirim `[]`
     *                                   dan **tetap** mewarisi seluruh lingkungan
     *                                   pemanggil (lihat catatan kelas: `envp` kosong
     *                                   BUKAN pewarisan). `RAMES_JOB_DIR` bukan env
     *                                   produksi: itu override direktori store job yang
     *                                   dipakai uji CLI (`cli/deploy.php` membacanya).
     *                                   Nama yang sama di `$env` menimpa nilai pemanggil.
     * @return bool true bila anak berhasil di-fork (bukan jaminan exec berhasil)
     */
    public static function spawn(array $argv, ?int &$pid = null, array $env = []): bool
    {
        $pid = 0;

        if ($argv === [] || !function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            return false;
        }

        // Lingkungan saat ini SELALU digabung eksplisit: pcntl_exec() memakai
        // execve(), jadi apa pun yang dikirim sebagai envp menggantikan SELURUH
        // lingkungan anak — dan array kosong berarti lingkungan kosong, bukan
        // pewarisan (dibuktikan probe: `getenv('PATH') === false` pada anak).
        // Tanpa penggabungan ini worker deploy kehilangan `RAMES_DB_DIR`
        // (membaca store yang salah ⇒ stage=`queued` selamanya) plus `PATH`/`HOME`.
        // Nilai di-`strval`-kan karena pcntl_exec menuntut envp berisi string.
        $envp = array_merge(getenv() ?: [], array_map('strval', $env));

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
            pcntl_exec((string) $argv[0], array_slice($argv, 1), $envp);
            // Hanya tercapai bila exec gagal.
            exit(127);
        }

        $pid = (int) $child;

        return true;
    }
}
