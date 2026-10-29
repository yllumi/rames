<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentAppProjection;
use app\library\Docker\DockerClient;
use app\library\Monitor\AppStorage;
use app\library\Monitor\ResourceCollector;
use app\library\Storage\AppStore;
use support\Request;
use Webman\Http\Response;

/**
 * Monitoring **sisi agent** (F3c multi-server, plan §4.1/§5):
 *
 * ```
 * GET /system/host-usage   → {code:0,data:{host,at}}          (/proc SERVER INI)
 * GET /monitor/containers  → {code:0,data:{containers,totals,error,at}}
 * GET /system/disk-usage   → {code:0,data:{available,…,at}}   (/system/df SERVER INI)
 * ```
 *
 * Dipanggil control plane (`MonitorController` ⇒ `AgentDeployer`), bukan UI:
 * route ini hanya terdaftar di mode `agent` dan otomatis tertutup
 * `AgentAuthMiddleware` (token + timestamp + nonce + signature).
 *
 * Aturan yang dijaga kelas ini: **metrik yang keluar selalu milik server ini**.
 * Pemakaian host dibaca `ResourceCollector::hostSnapshot()` (⇒ `System\HostUsage`
 * atas `/proc` server agent) dan statistik container dirakit
 * `ResourceCollector::collect()` dengan `DockerClient` **server ini** — tidak ada
 * jalur di mana `/proc` atau Engine host control plane terbaca untuk app remote
 * (plan §3 butir 4; kebalikannya juga berlaku: control plane tidak boleh
 * membaca Engine-nya sendiri untuk server agent).
 *
 * Otorisasi **tidak** dilakukan di sini: agent tidak memiliki identitas user
 * dashboard, dan penyaringan (`AppAccess::visible()`) adalah tanggung jawab
 * control plane (`MonitorController`) — satu tempat, sisi server.
 *
 * Controller hanya mediator: seluruh penafsiran `/proc` dan perakitan baris ada
 * di `Monitor\ResourceCollector`/`System\HostUsage`/`Docker\ContainerStats`.
 * Stateless: properti hanya seam uji (nullable).
 */
class AgentDockerController
{
    private ?AppStore $apps;

    private ?DockerClient $docker;

    /**
     * Bentuk nama compose project yang boleh masuk query Engine (huruf kecil,
     * angka, `-`; ≤63 karakter = batas label Docker). Nilai tidak sah ditolak
     * **tanpa** menggemakan kembali ke respons.
     */
    private const PROJECT_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    private const PROJECT_MAX_LENGTH = 63;

    /**
     * @param AppStore|null     $apps   proyeksi app agent (seam uji; null ⇒ bawaan)
     * @param DockerClient|null $docker Engine API server ini (seam uji; null ⇒ bawaan)
     */
    public function __construct(?AppStore $apps = null, ?DockerClient $docker = null)
    {
        $this->apps = $apps;
        $this->docker = $docker;
    }

    /**
     * `GET /system/host-usage` — CPU/memori/load/uptime host + storage server
     * (filesystem `DISK_PATH`) **server agent**.
     *
     * Field yang tidak terbaca bernilai `null` (bukan galat): halaman monitor
     * control plane tetap tampil walau satu server melaporkan sebagian metrik.
     */
    public function hostUsage(Request $request): Response
    {
        try {
            $host = ResourceCollector::hostSnapshot($this->hostProcPath(), $this->diskPath());
        } catch (\Throwable $e) {
            return $this->error('Gagal membaca metrik host di server agent: ' . $e->getMessage(), 'host_failed', 502);
        }

        return json(['code' => 0, 'data' => ['host' => $host, 'at' => date('H:i:s')]]);
    }

    /**
     * `GET /monitor/containers` — baris monitoring seluruh container di server
     * ini (termasuk container di luar app dashboard; penyaringan hak akses
     * dilakukan control plane).
     *
     * `apps` = proyeksi app agent supaya `managed`/`app_id`/`app_name` baris
     * benar (dipakai control plane untuk menyaring per app yang boleh dilihat
     * user). Kegagalan Engine **tidak** menggagalkan respons: `error` ikut
     * terisi (halaman monitor satu server rusak tidak boleh mematikan halaman
     * agregat).
     */
    public function containers(Request $request): Response
    {
        try {
            $apps = $this->appStore()->all();
        } catch (\Throwable) {
            // Basis data agent tidak terbaca: API mesin tetap menjawab JSON.
            return $this->error('Gagal membaca proyeksi app di server agent.', 'store_failed', 500);
        }

        $data = ResourceCollector::collect($apps, true, $this->docker());

        return json(['code' => 0, 'data' => [
            'containers' => $data['containers'],
            'totals' => $data['totals'],
            'error' => $data['error'],
            'at' => date('H:i:s'),
        ]]);
    }

    /**
     * `GET /system/disk-usage?project=<app-name>` — ukuran storage **server
     * ini** untuk satu compose project: volume project + writable layer
     * container (image bersama tidak dihitung).
     *
     * Dipanggil control plane (`AgentDeployer::diskUsage()`) untuk tab Info
     * app yang berjalan di server agent. Parameter `project` wajib berbentuk
     * nama project compose; nilai tidak sah ditolak 400 tanpa menggemukannya.
     * Kegagalan Engine ⇒ 502 (pola `hostUsage()`), bukan respons setengah jadi.
     */
    public function diskUsage(Request $request): Response
    {
        $rawProject = $request->get('project', '');
        if ($rawProject !== null && !is_scalar($rawProject)) {
            // `?project[]=x` dsb. — tolak bentuknya tanpa menggemakan nilai.
            return json(['code' => 1, 'error' => 'Parameter tidak valid.'])->withStatus(400);
        }
        $project = trim((string) ($rawProject ?? ''));
        if (
            $project === ''
            || strlen($project) > self::PROJECT_MAX_LENGTH
            || preg_match(self::PROJECT_PATTERN, $project) !== 1
        ) {
            return json(['code' => 1, 'error' => 'Parameter project tidak sah.'])->withStatus(400);
        }

        try {
            $df = $this->docker()->getDiskUsage(['volume', 'container']);
        } catch (\Throwable $e) {
            return $this->error(
                'Gagal menghitung ukuran storage di server agent: ' . $e->getMessage(),
                'disk_failed',
                502
            );
        }

        return json(['code' => 0, 'data' => AppStorage::summarize(is_array($df) ? $df : [], $project) + [
            'at' => date('H:i:s'),
        ]]);
    }

    /**
     * Direktori `/proc` yang dibaca — **selalu milik server ini**
     * (`deploy.host_proc_path` server agent). Seam uji supaya metrik host dapat
     * diuji tanpa `/proc` nyata.
     */
    protected function hostProcPath(): string
    {
        return (string) config('deploy.host_proc_path', '/proc');
    }

    /**
     * Path yang filesystem-nya dilaporkan sebagai storage server (seam uji).
     * Default `deploy.disk_path` (= direktori repo yang di-mount dari host).
     */
    protected function diskPath(): ?string
    {
        $path = (string) config('deploy.disk_path', '');

        return $path === '' ? null : $path;
    }

    /**
     * Respons galat seragam (`code:1`, sama pola `AgentLogController::error()`).
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
    protected function docker(): DockerClient
    {
        return $this->docker ?? new DockerClient(
            (string) config('deploy.docker_socket', '/var/run/docker.sock'),
            (int) config('deploy.monitor_stats_timeout', 20)
        );
    }
}
