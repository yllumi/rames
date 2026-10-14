<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Deploy\AgentDeployer;
use app\library\Docker\AppContainers;
use app\library\Docker\DockerExec;
use app\library\Storage\AppStore;
use RuntimeException;
use support\Request;
use Webman\Http\Response;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\ServerSentEvents;
use Workerman\Timer;

/**
 * Terminal container (docker exec) — SPECS tambahan.
 *
 * Controller hanya mediator: semua logika eksekusi di app/library (DockerExec).
 * Endpoint dilindungi AuthMiddleware global; semua POST wajib CSRF.
 *
 * Transport:
 *  - output: GET SSE (text/event-stream) via Workerman\Timer (non-blocking, tidak
 *    mengunci worker event loop).
 *  - input : POST (menulis ke FIFO stdin sesi).
 * Sesi hidup sebagai proses detached + state di file (runtime/terminal/{token}/),
 * sehingga SSE dan POST boleh dilayani worker HTTP yang berbeda.
 *
 * Siklus hidup: koneksi SSE boleh drop kapan saja (proxy memutus koneksi lama,
 * browser reconnect otomatis, laptop sleep, dsb.) dan sesi TETAP hidup. Sesi
 * berakhir lewat `POST .../close`, proses yang keluar, atau prune di `DockerExec`
 * (TTL / idle). Ini penting: EventSource menyambung ulang otomatis, jadi bila drop
 * koneksi = sesi ditutup, setiap reconnect (dan setiap input) akan menjawab 404.
 */
class TerminalController
{
    /**
     * Header respons SSE — satu sumber agar semua jalur stream konsisten.
     *
     * PENTING: respons HARUS dibuat sebagai `Webman\Http\Response`. `App::send()`
     * melakukan `$response instanceof Webman\Http\Response` lalu memakai jalur
     * streaming (tanpa `close()`) hanya bila `Transfer-Encoding: chunked`.
     * `Workerman\Protocols\Http\Response` adalah INDUK dari kelas itu, sehingga
     * objeknya GAGAL cek `instanceof` → koneksi ditutup seketika (header SSE
     * terkirim, nol event) — baik saat request ber-`Connection: close`/HTTP/1.0
     * (default `proxy_http_version 1.0` nginx) maupun akses langsung.
     */
    private const SSE_HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'Connection' => 'keep-alive',
        'Transfer-Encoding' => 'chunked',
        'X-Accel-Buffering' => 'no',
    ];

    /** Interval polling output sesi (detik). */
    private const SSE_TICK = 0.15;

    /** Jeda reconnect EventSource (milidetik) — lebih cepat dari default browser (3 dtk). */
    private const SSE_RETRY_MS = 1000;

    /** Heartbeat + penanda aktivitas sesi (detik). */
    private const SSE_HEARTBEAT_SECONDS = 15;

    /**
     * Umur maksimum SATU koneksi SSE (detik) sebelum siklus ditutup dan klien
     * menyambung ulang. Proxy yang men-buffer respons (nginx `proxy_buffering`,
     * Cloudflare) baru mengalirkan body saat respons SELESAI — siklus pendek
     * memastikan output tetap sampai ke browser, sekaligus menghindari
     * `proxy_read_timeout` (default nginx 60 detik).
     */
    private const SSE_CYCLE_SECONDS = 55;

    /** Detik tanpa output sama sekali sebelum sesi dianggap macet. */
    private const SSE_STALL_SECONDS = 10;

    /**
     * Interval memompa aliran **dari agent** (detik) — membaca socket upstream
     * non-blocking; kecil supaya echo ketikan terasa instan, tapi tetap memberi
     * worker Webman kesempatan melayani request lain.
     */
    private const PROXY_TICK = 0.05;

    // ==================================================================
    // Buka sesi interaktif
    // ==================================================================

    public function open(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        try {
            $deployer = $this->agentDeployer($app);
        } catch (\Throwable $e) {
            return $this->agentFailure($e);
        }

        if ($deployer !== null) {
            return $this->openAtAgent($request, $app, $deployer);
        }

        $container = AppContainers::resolve($app, (string) $request->post('container', ''));
        if ($container === null) {
            return json(['code' => 400, 'msg' => 'Container tidak dikenali atau bukan milik app ini.']);
        }

        $opts = [
            // '' = auto: server memilih shell terbaik yang ada di container
            // (bash > ash > sh — lihat DockerExec::resolveShell()). Permintaan
            // eksplisit klien tetap dihormati apa adanya.
            'shell' => trim((string) $request->post('shell', '')),
            'user' => self::resolveUser((string) $request->post('user', '')),
        ];

        try {
            $session = $this->dockerExec()->open((string) $app['id'], $container, $opts);
        } catch (RuntimeException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        }

        $this->audit($app, $container, 'open shell ' . $session['shell'] . ' (user ' . $opts['user'] . ')');
        return json(['code' => 0, 'data' => $session]);
    }

    // ==================================================================
    // Stream output (SSE) — koneksi panjang
    // ==================================================================

    public function stream(Request $request, string $id, string $token)
    {
        $app = $this->requireApp($id);

        try {
            $deployer = $this->agentDeployer($app);
        } catch (\Throwable $e) {
            // Endpoint ini hanya dipanggil EventSource → selalu balas SSE (klien
            // berhenti sendiri, bukan badai retry) — lihat catatan `sseGone()`.
            return $this->sseGone($request, AgentError::sanitize($e->getMessage()));
        }

        if ($deployer !== null) {
            return $this->proxyAgentStream($request, $app, $token, $deployer);
        }

        $exec = $this->dockerExec();
        if ($exec->sessionInfo($token, $id) === null) {
            return $this->sseGone($request, 'Sesi terminal tidak ditemukan atau sudah berakhir.');
        }

        $connection = $request->connection;
        if (!$connection instanceof TcpConnection) {
            return json(['code' => 500, 'msg' => 'Koneksi streaming tidak tersedia.']);
        }

        // Kirim header SSE; body di-stream berikutnya lewat $connection->send().
        // Dikembalikan sebagai response (bukan `false`) supaya Webman memakai jalur
        // streaming chunked yang tidak menutup koneksi — lihat catatan di SSE_HEADERS.
        $response = new Response(200, self::SSE_HEADERS, '');

        $timerId = null;
        // Koneksi drop BUKAN akhir sesi: proxy (nginx/Cloudflare) boleh memutus
        // koneksi lama dan EventSource menyambung ulang otomatis. Sesi dibiarkan
        // hidup (lihat DockerExec::pruneStale) — kalau tidak, reconnect pertama
        // akan 404 dan terminal mati permanen.
        $cleanup = function () use (&$timerId) {
            if ($timerId !== null) {
                Timer::del($timerId);
                $timerId = null;
            }
        };
        /** Kirim satu frame SSE sebagai potongan chunked. */
        $sendEvent = static function (array $event) use ($connection): void {
            $connection->send(new Chunk((string) new ServerSentEvents($event)));
        };
        /** Tutup aliran chunked dengan benar (potongan 0\r\n\r\n) lalu koneksi. */
        $finish = static function () use ($connection, $cleanup, $sendEvent): void {
            $sendEvent(['event' => 'close', 'data' => '']);
            $connection->send(new Chunk(''));
            $cleanup();
            $connection->close();
        };

        $attachedAt = time();
        $lastHeartbeat = time();
        $sentRetry = false;
        $timerId = Timer::add(self::SSE_TICK, function () use ($connection, $exec, $token, $cleanup, $finish, $sendEvent, $attachedAt, &$lastHeartbeat, &$sentRetry) {
            if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                $cleanup();
                return;
            }
            try {
                if (!$sentRetry) {
                    // Header SSE baru terkirim Webman SETELAH handler kembali, jadi
                    // frame apa pun dari dalam handler akan mendahului header — kirim
                    // di tick pertama (EventSource memakai nilai ini untuk reconnect).
                    $sentRetry = true;
                    $sendEvent(['retry' => self::SSE_RETRY_MS]);
                }

                $now = time();
                $chunk = $exec->readOutput($token);
                if ($chunk !== '') {
                    $exec->markOutput($token);
                    $sendEvent(['event' => 'output', 'data' => base64_encode($chunk)]);
                    return;
                }

                if ($now - $lastHeartbeat >= self::SSE_HEARTBEAT_SECONDS) {
                    $lastHeartbeat = $now;
                    // Sesi yang masih di-stream ditandai aktif → tidak dibuang prune
                    // milik worker lain sebagai "sesi terlantar".
                    $exec->touchActivity($token);
                    // Komentar SSE (diabaikan EventSource): menjaga koneksi hidup dan
                    // mendeteksi klien yang sudah hilang lewat error tulis socket.
                    $sendEvent(['' => 'keepalive']);
                }

                if (!$exec->isRunning($token)) {
                    $exec->closeSession($token);
                    $finish();
                    return;
                }

                // Deteksi sesi macet: proses hidup tapi TIDAK pernah memproduksi output
                // (mis. race saat spawn) — hentikan agar tidak menggantung selamanya.
                // Diukur per-koneksi agar reconnect tidak langsung menuduh macet.
                if (!$exec->hasOutput($token) && ($now - $attachedAt) > self::SSE_STALL_SECONDS) {
                    $sendEvent(['event' => 'error', 'data' => 'Sesi terminal macet (tidak ada output). Sesi ditutup, silakan buka ulang.']);
                    $exec->closeSession($token);
                    $finish();
                    return;
                }

                // Siklus koneksi berakhir: klien menyambung ulang (retry) dan melanjutkan
                // sesi yang sama. Sesi TIDAK ditutup dan output yang tertahan di FIFO
                // dikirim pada sambungan berikutnya.
                if (($now - $attachedAt) >= self::SSE_CYCLE_SECONDS) {
                    $sendEvent(['event' => 'cycle', 'data' => '']);
                    $connection->send(new Chunk(''));
                    $cleanup();
                    $connection->close();
                }
            } catch (\Throwable $e) {
                $cleanup();
                $connection->close();
            }
        });

        $connection->onClose = $cleanup;

        return $response;
    }

    // ==================================================================
    // Kirim input ke sesi
    // ==================================================================

    public function input(Request $request, string $id, string $token)
    {
        $app = $this->requireApp($id);

        try {
            $deployer = $this->agentDeployer($app);
        } catch (\Throwable $e) {
            return $this->agentFailure($e);
        }

        if ($deployer !== null) {
            $data = (string) $request->post('data', '');
            if (strlen($data) > 65536) {
                return json(['code' => 400, 'msg' => 'Input terlalu besar.']);
            }
            if ($data === '') {
                return json(['code' => 0]);
            }
            try {
                $deployer->terminalInput($app, $token, $data);
            } catch (\Throwable $e) {
                return $this->agentFailure($e);
            }

            return json(['code' => 0]);
        }

        $exec = $this->dockerExec();
        if ($exec->sessionInfo($token, $id) === null) {
            return json(['code' => 404, 'msg' => 'Sesi terminal tidak ditemukan atau sudah berakhir.']);
        }
        $data = (string) $request->post('data', '');
        if (strlen($data) > 65536) {
            return json(['code' => 400, 'msg' => 'Input terlalu besar.']);
        }
        if ($data !== '' && !$exec->writeInput($token, $data)) {
            return json(['code' => 410, 'msg' => 'Sesi terminal sudah berakhir.']);
        }
        return json(['code' => 0]);
    }

    // ==================================================================
    // Tutup sesi
    // ==================================================================

    public function close(Request $request, string $id, string $token)
    {
        $app = $this->requireApp($id);

        try {
            $deployer = $this->agentDeployer($app);
        } catch (\Throwable $e) {
            return $this->agentFailure($e);
        }

        if ($deployer !== null) {
            try {
                $deployer->terminalClose($app, $token);
            } catch (\Throwable $e) {
                return $this->agentFailure($e);
            }
            // Container tidak diketahui di sini (metadata sesi hidup di agent);
            // audit tetap mencatat aksi + app.
            $this->audit($app, '', 'close sesi terminal di server agent');
            return json(['code' => 0]);
        }

        $exec = $this->dockerExec();
        $session = $exec->sessionInfo($token, $id);
        if ($session === null) {
            return json(['code' => 404, 'msg' => 'Sesi terminal tidak ditemukan atau sudah berakhir.']);
        }
        $exec->closeSession($token);
        $this->audit($app, (string) ($session['container'] ?? ''), 'close sesi terminal');
        return json(['code' => 0]);
    }

    // ==================================================================
    // One-shot run command (non-interaktif)
    // ==================================================================

    public function run(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        try {
            $deployer = $this->agentDeployer($app);
        } catch (\Throwable $e) {
            return $this->agentFailure($e);
        }

        if ($deployer !== null) {
            // Penjaga antar-fase (plan §3 butir 4): jalur "Run" (one-shot) belum
            // server-aware, jadi app di server agent DITOLAK eksplisit — bukan
            // diam-diam dieksekusi di server lokal control plane.
            return json([
                'code' => 501,
                'msg' => 'Perintah satu-kali (Run) belum didukung untuk app di server agent. '
                    . 'Gunakan tab Terminal (sesi interaktif) — sesi itu berjalan di server agent.',
            ])->withStatus(501);
        }

        $container = AppContainers::resolve($app, (string) $request->post('container', ''));
        if ($container === null) {
            return json(['code' => 400, 'msg' => 'Container tidak dikenali atau bukan milik app ini.']);
        }
        $command = trim((string) $request->post('command', ''));
        if ($command === '') {
            return json(['code' => 400, 'msg' => 'Perintah kosong.']);
        }
        $timeout = (int) $request->post('timeout', 0);
        if ($timeout < 0 || $timeout > 600) {
            $timeout = 0;
        }

        // Aturan user sama dengan sesi interaktif `open()`: ability `terminal`
        // identik (operator+ sudah memegang shell root lewat sesi), jadi default
        // `root` — tanpa ini jalur "Run" justru gagal `permission denied` atas
        // berkas milik root padahal operator bisa membuka sesi root. Nilai
        // eksplisit klien dihormati; validasi tetap satu sumber di
        // `DockerExec::assertUser()`.
        $user = self::resolveUser((string) $request->post('user', ''));

        try {
            $result = $this->dockerExec()->runCommand($container, $command, $timeout, $user);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }

        $this->audit($app, $container, 'run: ' . substr($command, 0, 120) . ' (user ' . $user . ')');
        return json(['code' => 0, 'data' => $result]);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Kebijakan dashboard untuk user eksekusi terminal/one-shot run.
     *
     * Ability `terminal` = operator+ yang sudah memegang shell penuh, jadi default
     * adalah root (tanpa ini `rm`/edit berkas milik root gagal karena user default
     * image, mis. www-data, tidak berizin). Permintaan eksplisit klien dihormati
     * apa adanya; `''` diteruskan ke `DockerExec` sebagai "user default image"
     * HANYA bila pemanggil memang tidak ingin default root — validasinya satu
     * sumber di `DockerExec::assertUser()` (bukan regex kedua di controller).
     */
    private static function resolveUser(string $requested): string
    {
        $requested = trim($requested);

        return $requested === '' ? 'root' : $requested;
    }

    /**
     * Seam pembuatan `DockerExec` (di-override unit test untuk menyuntik fake
     * tanpa Docker/Engine nyata). Produksi selalu memakai implementasi asli.
     */
    protected function dockerExec(): DockerExec
    {
        return new DockerExec();
    }

    /**
     * Deployer **agent** untuk app, atau `null` bila app dilayani server lokal
     * (satu pintu: `AgentDeployer::forApp()` — resolusi `server_id` app).
     *
     * Seam uji: subclass menyuntikkan deployer dengan klien agent tiruan supaya
     * seluruh jalur proxy bisa diuji tanpa jaringan & tanpa Docker.
     *
     * @param array<string,mixed> $app
     * @throws \Throwable server `kind=agent` yang konfigurasinya cacat — fail-fast,
     *                    TIDAK ada jalur mundur ke server lokal (plan §3 butir 4)
     */
    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return AgentDeployer::forApp($app);
    }

    // ==================================================================
    // Jalur server agent (F3a) — sesi hidup DI AGENT, kontrol hanya mem-proxy
    // ==================================================================

    /**
     * `open()` untuk app di server agent: validasi container di sini (bentuk +
     * keanggotaan data proyeksi, tanpa menyentuh Engine lokal), pembukaan sesi di
     * agent, dan audit di sini (identitas pelaku hanya ada di control plane).
     */
    private function openAtAgent(Request $request, array $app, AgentDeployer $deployer)
    {
        $container = AgentDeployer::containerRef($app, (string) $request->post('container', ''));
        if ($container === null) {
            return json(['code' => 400, 'msg' => 'Container tidak dikenali atau bukan milik app ini.']);
        }

        $opts = [
            'shell' => trim((string) $request->post('shell', '')),
            'user' => self::resolveUser((string) $request->post('user', '')),
        ];

        try {
            $session = $deployer->terminalOpen($app, $container, $opts);
        } catch (\Throwable $e) {
            return $this->agentFailure($e);
        }

        $this->audit(
            $app,
            $container,
            'open shell ' . (string) ($session['shell'] ?? '') . ' (user ' . $opts['user'] . ') di server agent'
        );

        return json(['code' => 0, 'data' => $session]);
    }

    /**
     * Proxy aliran SSE sesi terminal yang hidup **di server agent**.
     *
     * Byte dari agent diteruskan apa adanya sebagai potongan chunked: agent
     * memakai protokol SSE yang sama (`retry`/`output`/`cycle`/`gone`/`close`),
     * jadi `EventSource` di browser tidak perlu tahu bedanya. Upstream dibaca
     * **non-blocking** lewat `Timer` (worker Webman tetap melayani request lain)
     * dan **tidak** di-buffer penuh di memori.
     *
     * Koneksi klien drop **bukan** akhir sesi (aturan repo): cleanup hanya menutup
     * socket upstream — sesi tetap hidup di agent sampai `close`/TTL, sehingga
     * `EventSource` boleh menyambung ulang.
     *
     * @param array<string,mixed> $app
     */
    private function proxyAgentStream(Request $request, array $app, string $token, AgentDeployer $deployer)
    {
        try {
            $stream = $deployer->terminalStream($app, $token);
        } catch (\Throwable $e) {
            return $this->sseGone($request, AgentError::sanitize($e->getMessage()));
        }

        $connection = $request->connection;
        if (!$connection instanceof TcpConnection) {
            ($stream['close'])();
            return json(['code' => 500, 'msg' => 'Koneksi streaming tidak tersedia.']);
        }

        // Header SSE dikembalikan sebagai respons (bukan `false`) supaya Webman
        // memakai jalur streaming chunked yang tidak menutup koneksi — sama
        // dengan jalur lokal (lihat catatan di SSE_HEADERS).
        $response = new Response(200, self::SSE_HEADERS, '');

        $timerId = null;
        $read = $stream['read'];
        $closeUpstream = $stream['close'];
        $cleanup = function () use (&$timerId, $closeUpstream): void {
            if ($timerId !== null) {
                Timer::del($timerId);
                $timerId = null;
            }
            // Idempoten & boleh dipanggil dari beberapa jalur (EOF, drop klien, error).
            $closeUpstream();
        };

        $timerId = Timer::add(self::PROXY_TICK, function () use ($connection, $read, $cleanup): void {
            if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                $cleanup();
                return;
            }
            try {
                $chunk = $read();
            } catch (\Throwable $e) {
                $cleanup();
                $connection->close();
                return;
            }
            if ($chunk === null) {
                // Aliran agent berakhir (siklus SSE, sesi ditutup di agent, atau
                // sambungan putus): tutup aliran chunked dengan benar. EventSource
                // menyambung ulang dan akan menemukan sesi yang masih hidup.
                $connection->send(new Chunk(''));
                $cleanup();
                $connection->close();
                return;
            }
            if ($chunk !== '') {
                $connection->send(new Chunk($chunk));
            }
        });

        $connection->onClose = $cleanup;

        return $response;
    }

    /**
     * Respons JSON kegagalan jalur agent (koneksi/timeout/404/konfigurasi server
     * cacat) — pesan disanitasi `AgentError::sanitize()` sehingga token server,
     * header `Authorization`, dan signature tidak pernah tampil di UI atau log.
     */
    private function agentFailure(\Throwable $e)
    {
        $detail = AgentError::sanitize($e->getMessage());

        return json([
            'code' => AgentDeployer::failureCode($e),
            'msg' => $detail === '' ? 'Operasi di server agent gagal.' : $detail,
        ]);
    }

    /**
     * Ambil app + pastikan user berhak memakai terminal container-nya.
     *
     * `protected` (bukan private) semata agar unit test bisa men-bypass
     * `AppStore`/`AppAccess` (data runtime nyata) — produksi tidak berubah.
     *
     * @throws AppAccessDenied dirender sebagai 404 oleh webman (JSON untuk
     *                         endpoint /api/*) — app user lain tidak terbocor.
     */
    protected function requireApp(string $id): array
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            throw new AppAccessDenied('terminal', $id);
        }
        AppAccess::require('terminal', $app, current_user());

        return $app;
    }

    /**
     * Balas permintaan stream sebagai aliran SSE berisi satu event `gone`, lalu tutup.
     *
     * Endpoint ini hanya dipakai `EventSource`, yang otomatis menyambung ulang saat
     * body respons bukan `text/event-stream` — respons 404 JSON justru memicu badai
     * retry plus error "MIME type is not text/event-stream" di console browser, tanpa
     * cara klien tahu bahwa sesinya sudah tidak ada. Dengan balasan SSE, klien bisa
     * berhenti sendiri dan menampilkan pesan yang jelas.
     *
     * `protected` supaya jalur agent (`AgentTerminalController`) memakai balasan
     * yang sama untuk app yang tidak ada / sesi yang tidak dikenal — satu bentuk
     * protokol, bukan salinan kedua.
     */
    protected function sseGone(Request $request, string $message)
    {
        $connection = $request->connection;
        if (!$connection instanceof TcpConnection) {
            return json(['code' => 404, 'msg' => $message]);
        }
        // Pola sama dengan stream(): header chunked + frame SSE sebagai chunk + penutup.
        $connection->send(new Response(200, self::SSE_HEADERS, ''));
        $connection->send(new Chunk((string) new ServerSentEvents(['retry' => self::SSE_RETRY_MS])));
        $connection->send(new Chunk((string) new ServerSentEvents(['event' => 'gone', 'data' => $message])));
        $connection->send(new Chunk(''));
        $connection->close();

        // Koneksi sudah ditutup sendiri; `false` memastikan Webman tidak menambah
        // respons kedua (percabangan close() di App::send() no-op saat status CLOSING).
        return false;
    }

    /**
     * Audit trail operasi terminal (open/close + one-shot run).
     *
     * Pemanggil menyertakan user EFEKTIF eksekusi di dalam `$action`
     * (mis. `run: id (user root)`), sebab `docker exec -u` menentukan izin yang
     * benar-benar dipakai — tercatat terpisah dari `user` (akun dashboard).
     *
     * `protected` semata agar unit test tidak menulis ke `runtime/logs/terminal` nyata.
     */
    protected function audit(array $app, string $container, string $action): void
    {
        $user = (string) (current_user()['username'] ?? '?');
        $dir = runtime_path() . '/logs/terminal';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $line = json_encode([
            'ts' => date('c'),
            'user' => $user,
            'app' => (string) ($app['name'] ?? ''),
            'app_id' => (string) ($app['id'] ?? ''),
            'container' => $container,
            'action' => $action,
        ], JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
