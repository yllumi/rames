<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentAppProjection;
use app\library\Deploy\AgentDeployer;
use app\library\Docker\AppContainers;
use app\library\Docker\ContainerLogs;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use support\Request;
use Webman\Http\Response;

/**
 * `GET /apps/{id}/logs?container={nama}&tail=N` — log container **di server
 * agent** (F3a multi-server, plan §4.1/§5).
 *
 * Dipanggil control plane (`AgentDeployer::containerLogs()`), bukan UI: endpoint
 * ini hanya terdaftar di mode `agent` dan otomatis tertutup
 * `AgentAuthMiddleware` (token + timestamp + nonce + signature). Control plane
 * **mem-proxy** hasilnya apa adanya supaya bentuk respons UI
 * (`{code:0,data:{container,tail,text,at}}`) tidak berubah.
 *
 * Perbedaan yang disengaja dibanding `LogController` (sisi dashboard):
 *  - app dibaca dari **proyeksi agent** (`AppStore` agent), bukan store control
 *    plane — dan **tanpa** `AppAccess`: identitas user tidak ada di sini,
 *    otorisasi (ability `logs`) sudah dilakukan control plane sebelum memanggil;
 *  - galat memakai status HTTP yang benar (`AppAccessDenied`-nya dashboard memakai
 *    HTTP 200 + `code`), sehingga `AgentClient` bisa menerjemahkannya menjadi
 *    `AgentError` yang jelas — pesannya tidak pernah memuat token/kredensial;
 *  - teks log dipotong ke {@see self::MAX_LOG_BYTES} (satu respons tidak boleh
 *    menahan memori worker tanpa batas), sementara jalur dashboard tidak dibatasi
 *    karena `tail` di sana sudah dibatasi UI.
 *
 * Nama container dari request **selalu** divalidasi milik app ini
 * (`AppContainers::resolve()` terhadap Docker Engine **server ini**) — tidak
 * pernah diteruskan mentah ke Engine. Controller hanya mediator: normalisasi
 * parameter di `ContainerLogs`, pembersihan stream multiplexed di sana juga,
 * eksekusi di `DockerClient`.
 *
 * Stateless: properti hanya seam uji (nullable); tanpa `session()`/`request()`
 * di konstruktor, tanpa properti yang menyimpan hasil request sebelumnya.
 */
class AgentLogController
{
    /**
     * Plafon ukuran teks log per respons. **Bukan** daftar kedua: nilainya
     * milik kontrak `AgentDeployer` (sisi yang menyusun respons untuk control
     * plane), dan controller ini yang memotongnya.
     */
    public const MAX_LOG_BYTES = AgentDeployer::MAX_LOG_BYTES;

    private ?AppStore $apps;

    private ?DockerClient $docker;

    /**
     * @param AppStore|null     $apps   proyeksi app agent (seam uji; null ⇒ bawaan)
     * @param DockerClient|null $docker Engine API server ini (seam uji; null ⇒ bawaan)
     */
    public function __construct(?AppStore $apps = null, ?DockerClient $docker = null)
    {
        $this->apps = $apps;
        $this->docker = $docker;
    }

    public function index(Request $request, string $id): Response
    {
        try {
            $app = $this->appStore()->find(trim($id));
        } catch (\Throwable) {
            // Basis data agent tidak terbaca: API mesin selalu menjawab JSON.
            return $this->error('Gagal membaca data app di server agent.', 'store_failed', 500);
        }

        if ($app === null) {
            return $this->error('App tidak ditemukan.', 'not_found', 404);
        }

        $requested = trim((string) $request->get('container', ''));
        $container = $requested === ''
            ? AppContainers::defaultContainer($app)
            : AppContainers::resolve($app, $requested);
        if ($container === null || $container === '') {
            return $this->error('Container tidak dikenali atau bukan milik app ini.', 'unknown_container', 404);
        }

        $tail = ContainerLogs::normalizeTail($request->get('tail', ContainerLogs::DEFAULT_TAIL));
        $timestamps = (string) $request->get('timestamps', '1') !== '0';

        try {
            $raw = $this->dockerClient()->containerLogs($container, $tail, $timestamps);
        } catch (\Throwable $e) {
            return $this->error('Gagal membaca log container di server agent: ' . $e->getMessage(), 'docker_failed', 502);
        }

        $text = ContainerLogs::demultiplex($raw);
        $truncated = strlen($text) > self::MAX_LOG_BYTES;
        if ($truncated) {
            // Simpan ekor terbaru (yang paling berguna untuk diagnosa).
            $text = substr($text, -self::MAX_LOG_BYTES);
        }

        return json(['code' => 0, 'data' => [
            'container' => $container,
            'tail' => $tail,
            'text' => $text,
            'truncated' => $truncated,
            'at' => date('H:i:s'),
        ]]);
    }

    /**
     * Respons galat seragam (`code:1`, sama pola `AgentJobController::error()`).
     */
    private function error(string $message, string $reason, int $status): Response
    {
        return json(['code' => 1, 'error' => $message, 'reason' => $reason])->withStatus($status);
    }

    /** Proyeksi app agent (path mengikuti seam yang disuntikkan). */
    private function appStore(): AppStore
    {
        return $this->apps ?? new AppStore((new AgentAppProjection())->path());
    }

    /** Engine API server ini (bukan host control plane) — seam uji. */
    private function dockerClient(): DockerClient
    {
        return $this->docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 20);
    }
}
