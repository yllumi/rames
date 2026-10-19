<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployLog;
use app\library\Deploy\ServerResolver;
use app\library\Docker\AppContainers;
use app\library\Docker\ContainerLogs;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Log container app (`docker logs`) untuk popup modal di halaman detail app.
 *
 * Controller hanya mediator: pengambilan log di DockerClient, pembersihan stream
 * multiplexed & normalisasi parameter di ContainerLogs, validasi kepemilikan
 * container di AppContainers.
 *
 * Otorisasi: ability `logs` (Viewer ke atas — membaca log tidak mengubah apa pun).
 * Nama container dari request selalu divalidasi milik app ini sebelum menyentuh
 * Docker Engine.
 *
 * **Multi-server (F3a)**: app yang dilayani server `kind=agent` diambil dari
 * **agent** (`AgentDeployer::containerLogs()` → `GET /apps/{id}/logs` agent),
 * bukan dari Docker Engine host ini — app remote tidak boleh diam-diam dibaca
 * dari daemon lokal (plan §3 butir 4). Bentuk respons UI **tidak berubah**
 * (`{code:0,data:{container,tail,text,at}}`); endpoint sisi agent ada di
 * `AgentLogController`.
 */
class LogController
{
    /**
     * GET /api/apps/{id}/logs?container={nama}&tail={baris}
     */
    /**
     * Ambil app + pastikan user berhak membaca log-nya.
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
            throw new AppAccessDenied('logs', $id);
        }
        // ability `logs` = viewer ke atas; app user lain → 404 (tidak terbocor)
        AppAccess::require('logs', $app, current_user());

        return $app;
    }

    public function index(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $requested = trim((string) $request->get('container', ''));
        $tail = ContainerLogs::normalizeTail($request->get('tail', ContainerLogs::DEFAULT_TAIL));
        $timestamps = (string) $request->get('timestamps', '1') !== '0';

        try {
            $deployer = $this->agentDeployer($app);
        } catch (\Throwable $e) {
            // Konfigurasi server agent cacat / `server_id` tak dikenal ⇒ fail-fast:
            // jangan pernah membaca daemon lokal untuk app server agent.
            return $this->failure($e, 502, $this->agentServerForApp($app));
        }

        if ($deployer !== null) {
            // Validasi tanpa I/O (bentuk nama + keanggotaan data proyeksi); daftar
            // container hidup tetap milik agent (`AppContainers::resolve()` di sana).
            $container = AgentDeployer::containerRef($app, $requested);
            if ($container === null) {
                return json(['code' => 404, 'msg' => 'Container tidak dikenali atau bukan milik app ini.']);
            }

            try {
                $text = $deployer->containerLogs($app, $container, $tail, $timestamps);
            } catch (\Throwable $e) {
                return $this->failure($e, AgentDeployer::failureCode($e), $this->agentServerForApp($app));
            }

            return json(['code' => 0, 'data' => [
                'container' => $container,
                'tail' => $tail,
                'text' => $text,
                'at' => date('H:i:s'),
            ]]);
        }

        // --- jalur server lokal (perilaku lama tidak berubah) ---
        $container = $requested === ''
            ? AppContainers::defaultContainer($app)
            : AppContainers::resolve($app, $requested);
        if ($container === null) {
            return json(['code' => 404, 'msg' => 'Container tidak dikenali atau bukan milik app ini.']);
        }

        try {
            $raw = $this->dockerClient()->containerLogs($container, $tail, $timestamps);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }

        return json(['code' => 0, 'data' => [
            'container' => $container,
            'tail' => $tail,
            'text' => ContainerLogs::demultiplex($raw),
            'at' => date('H:i:s'),
        ]]);
    }

    /**
     * `GET /api/apps/{id}/deploy-log?offset=N[&tail=1]` — potongan log deploy
     * app (≤256 KiB) + header `X-Rames-Offset` = offset lanjutan untuk polling.
     *
     * Sumbernya berkas yang ditulis worker (`runtime/logs/deploy/{appId}.log`),
     * termasuk output build `docker compose` yang dialirkan live. Karena worker
     * di server agent meneruskan log job agent ke log yang sama, endpoint ini
     * berlaku untuk app lokal **maupun** app server agent (tanpa proxy kedua).
     *
     * `tail=1` = mulai dari ekor log (dipakai saat modal dibuka). Ability `logs`
     * (Viewer ke atas) — sama dengan log container.
     */
    public function deployLog(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $offset = max(0, (int) $request->get('offset', '0'));
        $tail = (string) $request->get('tail', '') === '1';

        try {
            $chunk = $this->deployLogReader()->read((string) $app['id'], $offset, $tail);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Gagal membaca log deploy.']);
        }

        return response($chunk['text'], 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Rames-Offset' => (string) $chunk['offset'],
        ]);
    }

    /**
     * Seam pembaca log deploy (uji memakai direktori temp, bukan `runtime/`).
     */
    protected function deployLogReader(): DeployLog
    {
        return new DeployLog();
    }

    /**
     * Seam Engine API host ini (di-override unit test supaya tidak menyentuh
     * daemon Docker nyata).
     */
    protected function dockerClient(): DockerClient
    {
        return new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 20);
    }

    /**
     * Deployer **agent** untuk app, atau `null` bila app dilayani server lokal
     * (satu pintu: `AgentDeployer::forApp()` — resolusi `server_id` app).
     *
     * Seam uji: subclass menyuntikkan deployer dengan klien agent tiruan supaya
     * jalur proxy dapat diuji tanpa jaringan & tanpa Docker.
     *
     * @param array<string,mixed> $app
     * @throws \Throwable server `kind=agent` yang konfigurasinya cacat — fail-fast,
     *                    TIDAK ada jalur mundur ke server lokal
     */
    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return AgentDeployer::forApp($app);
    }

    /**
     * Registry server (seam uji: store temp tanpa `database/` nyata) — pola
     * `DatabaseController::serverResolver()`. Dipakai **hanya** untuk meredaksi
     * identitas server pada pesan galat.
     */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /**
     * Dokumen server sebuah app — **hanya** untuk meredaksi identitas server
     * (token, `base_url`/host internal, blok PEM) pada pesan galat; keputusan
     * otorisasi tetap di `AppAccess`/`AgentDeployer::forApp()`.
     *
     * Best-effort: server tak dikenal/gagal dibaca ⇒ `[]` (pesan tetap disanitasi
     * token-nya oleh `AgentError`).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function agentServerForApp(array $app): array
    {
        try {
            $server = $this->serverResolver()->resolveForApp($app);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($server) ? $server : [];
    }

    /**
     * Respons galat jalur agent: pesan disanitasi **satu pintu**
     * (`AgentError::sanitizeForServer()`) supaya token/`Authorization`/signature
     * **dan** identitas server (`base_url`/host internal, PEM) tidak pernah muncul
     * di UI atau log.
     *
     * @param array<string,mixed> $server dokumen server (kosong ⇒ sanitasi biasa)
     */
    private function failure(\Throwable $e, int $code, array $server = [])
    {
        $detail = AgentError::sanitizeForServer($e->getMessage(), $server);

        return json([
            'code' => $code,
            'msg' => $detail === '' ? 'Gagal mengambil log dari server agent.' : $detail,
        ]);
    }
}
