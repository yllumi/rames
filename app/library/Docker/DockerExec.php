<?php
declare(strict_types=1);

namespace app\library\Docker;

use app\library\Support\SigchldGuard;
use RuntimeException;

/**
 * Menjalankan `docker exec` ke container milik app (eksekusi pada daemon host via docker.sock).
 *
 * Dua mode:
 *  1. runCommand()      — eksekusi satu-perintah (non-interaktif); output lengkap dikembalikan.
 *  2. openInteractive() — sesi shell interaktif (PTY via `script` + `docker exec -it`) dengan
 *     IPC berbasis FIFO di runtime/terminal/{token}/. Proses di-spawn detached (bukan anak dari
 *     satu worker HTTP tertentu) sehingga aman lintas-worker Webman: koneksi SSE (output) dan
 *     POST (input) boleh dilayani oleh worker yang berbeda — semua state lewat file/FIFO.
 *
 * Keamanan:
 *  - Seluruh command memakai bentuk array + bypass_shell (tanpa shell host) → bebas injection.
 *  - Container yang boleh di-exec divalidasi milik app di controller (tidak diterima mentah).
 *  - Token sesi acak (16 byte) — tak tertebak, bertindak sebagai capability.
 *  - Shell dibatasi whitelist.
 *
 * Siklus hidup sesi: sesi TIDAK terikat pada satu koneksi SSE. Koneksi SSE boleh
 * drop (proxy memutus koneksi lama, browser reconnect otomatis, dsb.) dan sesi
 * tetap hidup sampai: (a) `POST .../close` dari klien, (b) prosesnya berakhir,
 * (c) `pruneStale()` membuangnya karena lewat TTL maksimum (`terminal_session_ttl`)
 * atau lama tanpa aktivitas/klien (`terminal_idle_timeout`).
 */
class DockerExec
{
    /**
     * Jeda (detik) proteksi untuk direktori sesi yang baru dibuat: `open()`
     * menulis metadata setelah proses di-spawn, sehingga worker lain yang
     * menjalankan `pruneStale()` di jendela tersebut tidak boleh menganggapnya
     * sampah.
     */
    private const PRUNE_GRACE_SECONDS = 30;

    private string $dockerBinary;
    private string $runtimeDir;
    private string $scriptBinary;
    private int $runTimeout;
    private int $sessionTtl;
    private int $idleTimeout;
    private int $maxSessions;

    /** @var array<int,string> shell yang boleh dipakai sesi interaktif */
    private const ALLOWED_SHELLS = ['sh', 'bash', 'ash', 'zsh'];

    public function __construct(?string $dockerBinary = null, ?string $runtimeDir = null, ?string $scriptBinary = null)
    {
        $this->dockerBinary = $dockerBinary ?? (string) config('deploy.docker_binary', 'docker');
        $this->runtimeDir = $runtimeDir ?? runtime_path() . '/terminal';
        $this->scriptBinary = $scriptBinary ?? (string) config('deploy.terminal_script_bin', 'script');
        $this->runTimeout = (int) config('deploy.terminal_run_timeout', 120);
        $this->sessionTtl = (int) config('deploy.terminal_session_ttl', 3600);
        $this->idleTimeout = (int) config('deploy.terminal_idle_timeout', 900);
        $this->maxSessions = (int) config('deploy.terminal_max_sessions', 20);
    }

    // ==================================================================
    // Mode 1 — one-shot run command (non-interaktif)
    // ==================================================================

    /**
     * Jalankan satu perintah di dalam container via `docker exec ... sh -c <cmd>`.
     * String command diteruskan sebagai satu argumen ke sh di dalam container
     * (tanpa shell host) — aman dari command injection pada sisi dashboard.
     *
     * @return array{code:int, stdout:string, stderr:string, timedOut:bool}
     */
    public function runCommand(string $container, string $command, int $timeout = 0): array
    {
        $args = [$this->dockerBinary, 'exec', '-i', $container, 'sh', '-c', $command];
        $runner = new \app\library\Support\ProcessRunner();
        return $runner->run($args, null, $timeout > 0 ? $timeout : $this->runTimeout, ['TERM' => 'xterm']);
    }

    /**
     * Jalankan satu perintah di dalam container sambil mengirim konten ke stdin
     * (mis. restore database: mysql < dump.sql). Command diteruskan sebagai satu
     * argumen ke `sh -c` di dalam container (tanpa shell host) — aman dari
     * command injection di sisi dashboard.
     *
     * @return array{code:int, stdout:string, stderr:string, timedOut:bool}
     */
    public function runCommandWithInput(string $container, string $command, string $input, int $timeout = 0): array
    {
        $args = [$this->dockerBinary, 'exec', '-i', $container, 'sh', '-c', $command];
        $runner = new \app\library\Support\ProcessRunner();
        return $runner->run($args, null, $timeout > 0 ? $timeout : $this->runTimeout, ['TERM' => 'xterm'], $input);
    }

    // ==================================================================
    // Mode 2 — sesi interaktif (PTY + FIFO)
    // ==================================================================

    /**
     * Buka sesi shell interaktif ke container.
     *
     * @param array{shell?:string, user?:string} $opts
     * @return array{token:string, pid:int, shell:string, container:string}
     * @throws RuntimeException
     */
    public function open(string $appId, string $container, array $opts = []): array
    {
        $this->pruneStale();
        $this->assertCapacity();

        $shell = $this->validateShell((string) ($opts['shell'] ?? 'sh'));
        $user = (string) ($opts['user'] ?? '');
        if ($user !== '' && !preg_match('/^[a-zA-Z0-9_.][a-zA-Z0-9_.-]*(:[a-zA-Z0-9_.-]+)?$/', $user)) {
            throw new RuntimeException('User tidak valid (contoh: root, www-data, 1000:1000).');
        }

        $token = bin2hex(random_bytes(16));
        $dir = $this->runtimeDir . '/' . $token;
        if (!is_dir($this->runtimeDir) && !@mkdir($this->runtimeDir, 0700, true) && !is_dir($this->runtimeDir)) {
            throw new RuntimeException('Tidak bisa membuat direktori terminal.');
        }
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Tidak bisa membuat direktori sesi terminal.');
        }

        // FIFO stdin/stdout (stderr digabung ke stdout).
        $stdinFifo = $dir . '/stdin';
        $stdoutFifo = $dir . '/stdout';
        foreach ([$stdinFifo, $stdoutFifo] as $fifo) {
            if (!@posix_mkfifo($fifo, 0600)) {
                $this->removeDir($dir);
                throw new RuntimeException('Gagal membuat FIFO terminal.');
            }
        }

        // Command PTY: script -qf -c "<docker exec -it ...>" /dev/null
        // `script` menyediakan pseudo-terminal untuk docker CLI → docker exec -t
        // berhasil (tanpa PTY di sisi client, docker CLI menolak -t), sehingga proses
        // di dalam container mendapat TTY sungguhan (line-editing, vim, top, warna).
        // Catatan: util-linux `script` hanya menerima command lewat -c (string), dan
        // string tsb dieksekusi oleh shell INTERNAL script. Seluruh bagian perintah
        // di-escape dengan escapeshellarg + variabel sudah divalidasi (container milik
        // app, shell whitelist, user regex) → tidak ada jalur command injection.
        $dockerCmd = escapeshellarg($this->dockerBinary) . ' exec -it';
        if ($user !== '') {
            $dockerCmd .= ' -u ' . escapeshellarg($user);
        }
        $dockerCmd .= ' ' . escapeshellarg($container) . ' ' . escapeshellarg($shell);
        $args = [$this->scriptBinary, '-q', '-f', '/dev/null', '-c', $dockerCmd];

        // Buka FIFO O_RDWR di parent (open O_RDWR pada FIFO tidak blocking) lalu
        // teruskan stream resource sebagai fd 0/1/2 ke anak via proc_open.
        // O_RDWR sekaligus memastikan anak tidak pernah diblokir menunggu lawan
        // (stdin punya reader, stdout punya writer) sejak saat pertama.
        $stdin = @fopen($stdinFifo, 'c+');
        $stdout = @fopen($stdoutFifo, 'c+');
        if ($stdin === false || $stdout === false) {
            if (is_resource($stdin)) {
                fclose($stdin);
            }
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            $this->removeDir($dir);
            throw new RuntimeException('Gagal membuka FIFO terminal.');
        }
        // CATATAN: jangan set O_NONBLOCK di sini — flag itu disimpan di open file
        // description yang SHARED dengan fd duplikat anak (proc_open). Stream yang
        // diteruskan harus tetap blocking agar `script`/docker exec berperilaku normal.

        $env = array_merge(getenv(), ['TERM' => 'xterm']);
        // Spawn + ambil PID dalam satu blok: `script` menunggu anaknya (docker exec)
        // lewat waitpid, jadi proses anak wajib mewarisi SIGCHLD=SIG_DFL — bila worker
        // sudah meng-ignore SIGCHLD (lihat SigchldGuard), waitpid itu gagal (ECHILD)
        // dan sesi mati seketika. SIG_IGN dipasang kembali di akhir karena sesi ini
        // sengaja tidak pernah di-proc_close.
        /** @var array{proc:resource|null, pid:int} $spawned */
        $spawned = SigchldGuard::withDefault(static function () use ($args, $stdin, $stdout, $env): array {
            $pipes = [];
            $proc = @proc_open($args, [0 => $stdin, 1 => $stdout, 2 => $stdout], $pipes, null, $env, ['bypass_shell' => true]);
            if (!is_resource($proc)) {
                return ['proc' => null, 'pid' => 0];
            }
            return ['proc' => $proc, 'pid' => (int) (proc_get_status($proc)['pid'] ?? 0)];
        });
        $proc = $spawned['proc'];
        $pid = $spawned['pid'];
        if (!is_resource($proc)) {
            fclose($stdin);
            fclose($stdout);
            $this->removeDir($dir);
            throw new RuntimeException('Gagal menjalankan proses terminal: ' . implode(' ', $args));
        }

        // Tutup salinan parent agar deteksi exit akurat: hanya anak yang menjadi
        // writer/reader FIFO (bila parent ikut memegang, EOF tidak akan pernah terlihat).
        fclose($stdin);
        fclose($stdout);

        // Metadata sesi disimpan ke file → worker mana pun bisa menemukan/validasi sesi ini.
        $creator = '?';
        try {
            if (function_exists('current_user')) {
                $cu = current_user();
                if (is_array($cu)) {
                    $creator = (string) ($cu['username'] ?? '?');
                }
            }
        } catch (\Throwable $e) {
            $creator = '?';
        }
        $meta = json_encode([
            'token' => $token,
            'app_id' => $appId,
            'container' => $container,
            'shell' => $shell,
            'user' => $user,
            'pid' => $pid,
            'created_at' => time(),
            'created_by' => $creator,
        ], JSON_UNESCAPED_UNICODE);
        // Metadata WAJIB tersimpan: tanpa session.json setiap request lanjutan
        // (stream/input/close) akan menjawab 404 "sesi tidak ditemukan" — kegagalan
        // tulis harus muncul sebagai error nyata, bukan sesi hantu.
        if ($meta === false || @file_put_contents($dir . '/session.json', $meta, LOCK_EX) === false) {
            $this->killProcessTree($pid);
            $this->removeDir($dir);
            throw new RuntimeException('Gagal menyimpan metadata sesi terminal (direktori tidak bisa ditulis?).');
        }
        if (@file_put_contents($dir . '/pid', (string) $pid, LOCK_EX) === false) {
            $this->killProcessTree($pid);
            $this->removeDir($dir);
            throw new RuntimeException('Gagal menyimpan PID sesi terminal (direktori tidak bisa ditulis?).');
        }
        // Sesi baru langsung ditandai aktif agar prune di worker lain tidak
        // menganggapnya terlantar sebelum penanda pertama dari stream/input tiba.
        $this->touchActivity($token);

        // SIGCHLD di-ignore agar proc anak (yang tidak pernah kita proc_close) tidak
        // menjadi zombie — kernel otomatis reap (pola sama dengan worker deploy).
        SigchldGuard::ignoreAndReap();

        return ['token' => $token, 'pid' => $pid, 'shell' => $shell, 'container' => $container];
    }

    /**
     * Validasi token milik app; kembalikan metadata sesi atau null.
     */
    public function sessionInfo(string $token, string $appId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $meta = $this->metaOf($token);
        if ($meta === [] || ($meta['app_id'] ?? '') !== $appId) {
            return null;
        }
        return $meta;
    }

    /**
     * Kirim data (input keyboard / resize stty) ke stdin sesi.
     */
    public function writeInput(string $token, string $data): bool
    {
        if ($data === '' || !$this->isRunning($token)) {
            return false;
        }
        $fifo = $this->runtimeDir . '/' . $token . '/stdin';
        // 'c+' (O_RDWR) → open tidak pernah block, dan karena anak memegang stdin
        // sebagai reader, data tetap sampai ke anak. Non-blocking agar request HTTP
        // tidak menggantung bila buffer penuh.
        $fh = @fopen($fifo, 'c+');
        if ($fh === false) {
            return false;
        }
        stream_set_blocking($fh, false);
        $written = 0;
        $len = strlen($data);
        while ($written < $len) {
            $n = @fwrite($fh, substr($data, $written));
            if ($n === false || $n === 0) {
                break;
            }
            $written += $n;
        }
        fclose($fh);
        if ($written > 0) {
            $this->touchActivity($token);
        }
        return $written > 0;
    }

    /**
     * Baca output yang tersedia dari stdout sesi (non-blocking, sekali baca).
     */
    public function readOutput(string $token): string
    {
        $fifo = $this->runtimeDir . '/' . $token . '/stdout';
        // file_exists (bukan is_file — FIFO bukan regular file)
        if (!file_exists($fifo)) {
            return '';
        }
        // 'c+' (O_RDWR): open FIFO tidak pernah block walau tanpa writer sesaat.
        $fh = @fopen($fifo, 'c+');
        if ($fh === false) {
            return '';
        }
        stream_set_blocking($fh, false);
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        return $data;
    }

    /**
     * Tandai sesi pernah memproduksi output (untuk deteksi sesi macet).
     */
    public function markOutput(string $token): void
    {
        @touch($this->runtimeDir . '/' . $token . '/output.seen');
        $this->touchActivity($token);
    }

    /**
     * Tandai sesi masih ada yang mengurusinya (klien SSE terpasang / ada I/O).
     * Dipakai `pruneStale()` untuk membuang sesi yang ditinggalkan tanpa
     * mematikan sesi yang sedang dipakai.
     */
    public function touchActivity(string $token): void
    {
        @touch($this->runtimeDir . '/' . $token . '/activity');
    }

    /**
     * Apakah sesi pernah memproduksi output.
     */
    public function hasOutput(string $token): bool
    {
        return file_exists($this->runtimeDir . '/' . $token . '/output.seen');
    }

    /**
     * Apakah proses sesi masih hidup (PID valid).
     */
    public function isRunning(string $token): bool
    {
        $pid = $this->pidOf($token);
        return $pid > 0 && @posix_kill($pid, 0);
    }

    /**
     * Hentikan proses sesi (process tree) dan bersihkan direktori sesi.
     */
    public function closeSession(string $token): void
    {
        $pid = $this->pidOf($token);
        if ($pid > 0) {
            $this->killProcessTree($pid);
        }
        $this->removeDir($this->runtimeDir . '/' . $token);
    }

    // ==================================================================
    // Helper internal
    // ==================================================================

    private function validateShell(string $shell): string
    {
        if (!in_array($shell, self::ALLOWED_SHELLS, true)) {
            throw new RuntimeException('Shell tidak diizinkan: ' . $shell);
        }
        return $shell;
    }

    private function metaOf(string $token): array
    {
        $file = $this->runtimeDir . '/' . $token . '/session.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function pidOf(string $token): int
    {
        $file = $this->runtimeDir . '/' . $token . '/pid';
        if (!is_file($file)) {
            return 0;
        }
        return (int) file_get_contents($file);
    }

    /**
     * Bersihkan sesi yang sudah mati atau tidak lagi diurus klien (dipanggil lazily
     * saat membuka sesi baru).
     *
     * Sesi dibuang bila salah satu terpenuhi:
     *  - prosesnya tidak lagi hidup (shell keluar / gagal spawn),
     *  - umur sesi melewati `terminal_session_ttl`,
     *  - tidak ada aktivitas (stream terpasang / input / output) melebihi
     *    `terminal_idle_timeout` — mis. browser ditutup tanpa `POST .../close`.
     *
     * Direktori yang baru dibuat SELALU dilindungi `PRUNE_GRACE_SECONDS`: `open()`
     * menulis metadata setelah proses di-spawn, jadi ada jendela singkat di mana
     * direktori sudah ada tapi `session.json`/`pid` belum. Tanpa proteksi itu,
     * prune dari worker lain bisa menghapus sesi yang baru dibuka — dan semua
     * request lanjutan (stream/input/close) menjawab 404.
     *
     * @return array<int,string> token sesi yang dibersihkan
     */
    public function pruneStale(): array
    {
        if (!is_dir($this->runtimeDir)) {
            return [];
        }
        $now = time();
        $pruned = [];
        foreach (glob($this->runtimeDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $token = basename($dir);
            $mtime = @filemtime($dir);
            $mtime = $mtime === false ? $now : $mtime;
            if ($now - $mtime < self::PRUNE_GRACE_SECONDS) {
                continue;
            }
            $meta = $this->metaOf($token);
            $createdAt = (int) ($meta['created_at'] ?? 0);
            if ($createdAt <= 0) {
                // Metadata rusak/absen → pakai mtime direktori, JANGAN anggap
                // "lahir tahun 1970" (dulu itu membuat sesi baru ikut terhapus).
                $createdAt = $mtime;
            }
            $activity = @filemtime($dir . '/activity');
            $activity = $activity === false ? $mtime : $activity;

            $expired = ($now - $createdAt) > $this->sessionTtl;
            $idle = ($now - $activity) > $this->idleTimeout;
            if (!$this->isRunning($token) || $expired || $idle) {
                $this->closeSession($token);
                $pruned[] = $token;
            }
        }
        return $pruned;
    }

    private function assertCapacity(): void
    {
        $count = is_dir($this->runtimeDir) ? count(glob($this->runtimeDir . '/*', GLOB_ONLYDIR) ?: []) : 0;
        if ($count >= $this->maxSessions) {
            throw new RuntimeException('Terlalu banyak sesi terminal aktif (' . $count . '). Tutup sebagian sesi lalu coba lagi.');
        }
    }

    /**
     * Hentikan seluruh tree proses (anak dulu, lalu induk) — TERM lalu KILL.
     *
     * WAJIB dipanggil dengan PID > 0: `posix_kill(0, ...)` menandai SELURUH
     * process group (termasuk worker Webman pemanggilnya).
     */
    private function killProcessTree(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        $children = $this->childrenOf($pid);
        foreach ($children as $child) {
            $this->killProcessTree($child);
        }
        @posix_kill($pid, SIGTERM);
        if (function_exists('usleep')) {
            usleep(150000);
        }
        $children = $this->childrenOf($pid);
        foreach ($children as $child) {
            @posix_kill($child, SIGKILL);
        }
        @posix_kill($pid, SIGKILL);
    }

    /**
     * Baca daftar PID anak langsung dari /proc (linux).
     *
     * @return array<int,int>
     */
    private function childrenOf(int $pid): array
    {
        $out = [];
        $content = @file_get_contents('/proc/' . $pid . '/task/' . $pid . '/children');
        if ($content === false) {
            return $out;
        }
        foreach (preg_split('/\s+/', trim($content)) ?: [] as $child) {
            if ($child !== '' && ctype_digit($child)) {
                $out[] = (int) $child;
            }
        }
        return $out;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($dir . '/' . $entry);
        }
        @rmdir($dir);
    }
}
