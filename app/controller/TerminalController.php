<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Docker\DockerClient;
use app\library\Docker\DockerExec;
use app\library\Storage\AppStore;
use RuntimeException;
use support\Request;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response;
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
 */
class TerminalController
{
    // ==================================================================
    // Buka sesi interaktif
    // ==================================================================

    public function open(Request $request, string $id)
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            return json(['code' => 404, 'msg' => 'App tidak ditemukan.']);
        }
        $container = $this->resolveContainer($app, (string) $request->post('container', ''));
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
        $exec = new DockerExec();
        $session = $exec->sessionInfo($token, $id);
        if ($session === null) {
            return json(['code' => 404, 'msg' => 'Sesi terminal tidak ditemukan atau sudah berakhir.']);
        }
        $createdAt = (int) ($session['created_at'] ?? time());

        $connection = $request->connection;
        if (!$connection instanceof TcpConnection) {
            return json(['code' => 500, 'msg' => 'Koneksi streaming tidak tersedia.']);
        }

        // Kirim header SSE; body di-stream berikutnya lewat $connection->send().
        $connection->send(new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ], ''));

        $timerId = null;
        $cleanup = function () use (&$timerId, $exec, $token) {
            if ($timerId !== null) {
                Timer::del($timerId);
                $timerId = null;
            }
            $exec->closeSession($token);
        };

        $timerId = Timer::add(0.15, function () use ($connection, $exec, $token, $cleanup, $createdAt) {
            if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
                $cleanup();
                $connection->close();
                return;
            }
            try {
                $chunk = $exec->readOutput($token);
                if ($chunk !== '') {
                    // Kirim objek ServerSentEvents (bukan string) — Workerman meng-encode
                    // string biasa menjadi HTTP response penuh yang akan merusak aliran SSE.
                    $exec->markOutput($token);
                    $connection->send(new ServerSentEvents(['event' => 'output', 'data' => base64_encode($chunk)]));
                    return;
                }
                if (!$exec->isRunning($token)) {
                    $connection->send(new ServerSentEvents(['event' => 'close', 'data' => '']));
                    $cleanup();
                    $connection->close();
                    return;
                }
                // Deteksi sesi macet: proses hidup tapi TIDAK pernah memproduksi output
                // (mis. race saat spawn) — hentikan agar tidak menggantung selamanya.
                if (!$exec->hasOutput($token) && (time() - $createdAt) > 10) {
                    $connection->send(new ServerSentEvents(['event' => 'error', 'data' => 'Sesi terminal macet (tidak ada output). Sesi ditutup, silakan buka ulang.']));
                    $connection->send(new ServerSentEvents(['event' => 'close', 'data' => '']));
                    $cleanup();
                    $connection->close();
                }
            } catch (\Throwable $e) {
                $cleanup();
                $connection->close();
            }
        });

        $connection->onClose = $cleanup;

        // false → webman tidak mengirim body response tambahan; koneksi dijaga tetap
        // terbuka oleh timer di atas.
        return false;
    }

    // ==================================================================
    // Kirim input ke sesi
    // ==================================================================

    public function input(Request $request, string $id, string $token)
    {
        $exec = new DockerExec();
        if ($exec->sessionInfo($token, $id) === null) {
            return json(['code' => 404, 'msg' => 'Sesi terminal tidak ditemukan atau sudah berakhir.']);
        }
        $data = (string) $request->post('data', '');
        if (strlen($data) > 65536) {
            return json(['code' => 400, 'msg' => 'Input terlalu besar.']);
        }
        $exec->writeInput($token, $data);
        return json(['code' => 0]);
    }

    // ==================================================================
    // Tutup sesi
    // ==================================================================

    public function close(Request $request, string $id, string $token)
    {
        $exec = new DockerExec();
        $session = $exec->sessionInfo($token, $id);
        if ($session === null) {
            return json(['code' => 404, 'msg' => 'Sesi terminal tidak ditemukan atau sudah berakhir.']);
        }
        $exec->closeSession($token);
        $this->audit(['name' => $id, 'id' => $id], (string) ($session['container'] ?? ''), 'close sesi terminal');
        return json(['code' => 0]);
    }

    // ==================================================================
    // One-shot run command (non-interaktif)
    // ==================================================================

    public function run(Request $request, string $id)
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            return json(['code' => 404, 'msg' => 'App tidak ditemukan.']);
        }
        $container = $this->resolveContainer($app, (string) $request->post('container', ''));
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
     * Validasi bahwa container benar-benar milik app ini (bukan container acak).
     * Cek dari data tersimpan apps.json, lalu fallback ke Engine API (label project
     * compose) bila data tersimpan belum sinkron.
     */
    private function resolveContainer(array $app, string $container): ?string
    {
        if ($container === '' || strpbrk($container, " \t\n\r/\\") !== false) {
            return null;
        }
        foreach (($app['containers'] ?? []) as $c) {
            if (($c['container_name'] ?? '') === $container) {
                return $container;
            }
        }
        try {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
            foreach ($docker->listContainersForProject((string) ($app['name'] ?? '')) as $lc) {
                foreach (($lc['Names'] ?? []) as $name) {
                    if ($name === $container || $name === '/' . $container) {
                        return $container;
                    }
                }
            }
        } catch (\Throwable $e) {
            // engine tidak tersedia — andalkan data tersimpan
        }
        return null;
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
