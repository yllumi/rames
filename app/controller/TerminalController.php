<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
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

    // ==================================================================
    // Buka sesi interaktif
    // ==================================================================

    public function open(Request $request, string $id)
    {
        $app = $this->requireApp($id);
        $container = AppContainers::resolve($app, (string) $request->post('container', ''));
        if ($container === null) {
            return json(['code' => 400, 'msg' => 'Container tidak dikenali atau bukan milik app ini.']);
        }

        $opts = [
            'shell' => (string) $request->post('shell', 'sh'),
            'user' => (string) $request->post('user', ''),
        ];

        try {
            $session = (new DockerExec())->open((string) $app['id'], $container, $opts);
        } catch (RuntimeException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        }

        $this->audit($app, $container, 'open shell ' . $session['shell'] . ($opts['user'] !== '' ? ' (user ' . $opts['user'] . ')' : ''));
        return json(['code' => 0, 'data' => $session]);
    }

    // ==================================================================
    // Stream output (SSE) — koneksi panjang
    // ==================================================================

    public function stream(Request $request, string $id, string $token)
    {
        $this->requireApp($id);

        $exec = new DockerExec();
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
        $this->requireApp($id);

        $exec = new DockerExec();
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
        $exec = new DockerExec();
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

        try {
            $result = (new DockerExec())->runCommand($container, $command, $timeout);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }

        $this->audit($app, $container, 'run: ' . substr($command, 0, 120));
        return json(['code' => 0, 'data' => $result]);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Ambil app + pastikan user berhak memakai terminal container-nya.
     *
     * @throws AppAccessDenied dirender sebagai 404 oleh webman (JSON untuk
     *                         endpoint /api/*) — app user lain tidak terbocor.
     */
    private function requireApp(string $id): array
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
     */
    private function sseGone(Request $request, string $message)
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

    private function audit(array $app, string $container, string $action): void
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
