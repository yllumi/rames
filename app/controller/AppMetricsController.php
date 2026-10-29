<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\DeployerInterface;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Monitor\AppMetrics;
use app\library\Monitor\AppStorage;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Metrik per-app untuk tab **Info** di detail app (SPECS §8d).
 *
 * ```
 * GET /api/apps/{id}/metrics[?storage=1]
 * → {code:0,data:{at,usage:{…},storage:null|{…}}}
 * ```
 *
 * Endpoint ini hanya menyediakan **snapshot terkini**; grafik CPU/RAM rolling
 * 5 menit dirakit browser (`public/js/app-metrics.js`) sehingga server tidak
 * menyimpan state lintas-request.
 *
 * Otorisasi: ability `view` (Viewer ke atas — membaca metrik tidak mengubah
 * apa pun). App tak dikenal / user tanpa hak ⇒ `AppAccessDenied` yang
 * dirender **404**, bukan 403 (app user lain tidak terbocor).
 *
 * `storage=1` memicu `GET /system/df` yang bisa lambat (Engine menelusuri
 * filesystem tiap volume) sehingga blok `storage` **hanya** dihitung bila
 * diminta; tanpa parameter itu `storage` bernilai `null`.
 *
 * Kegagalan tidak pernah menjadi 500: app yatim (server hilang) tetap bisa
 * dibuka dengan `usage.error` generik + `storage.available=false`. Pesan galat
 * jalur agent disanitasi satu pintu lewat
 * `AgentError::sanitizeForServer()` (token/host/PEM tidak pernah sampai UI).
 */
class AppMetricsController
{
    private ?AppStore $apps;

    private ?DockerClient $docker;

    private ?ServerResolver $servers;

    /**
     * @param AppStore|null       $apps    store app (seam uji; null ⇒ `database/`)
     * @param DockerClient|null   $docker  Engine host ini (seam uji)
     * @param ServerResolver|null $servers registry server (seam uji)
     */
    public function __construct(
        ?AppStore $apps = null,
        ?DockerClient $docker = null,
        ?ServerResolver $servers = null,
    ) {
        $this->apps = $apps;
        $this->docker = $docker;
        $this->servers = $servers;
    }

    /**
     * GET /api/apps/{id}/metrics?storage=1
     *
     * @return \Webman\Http\Response
     * @throws AppAccessDenied app tidak ada / user tidak berhak (dirender 404)
     */
    public function show(Request $request, string $id)
    {
        $app = $this->requireApp($id);

        $rawStorage = $request->get('storage', '');
        if ($rawStorage !== null && !is_scalar($rawStorage)) {
            // `?storage[]=1` dsb. — bentuk tidak sah, jangan menggemakan nilainya.
            return json(['code' => 1, 'msg' => 'Parameter tidak valid.'])->withStatus(400);
        }
        $withStorage = (string) ($rawStorage ?? '') === '1';
        $server = $this->serverForApp($app);

        try {
            $deployer = $this->deployerFor($app);
        } catch (\Throwable) {
            // Server app hilang / konfigurasi rusak: detail app tetap terbuka
            // (200) dengan galat generik, bukan 500.
            return $this->respond(
                $this->emptyUsage('Metrik app tidak tersedia.'),
                $withStorage ? AppStorage::unavailable('Ukuran storage tidak tersedia.') : null
            );
        }

        $metrics = $this->metrics();
        $usage = $metrics->usage($app, $deployer);
        if (isset($usage['error'])) {
            $usage['error'] = $this->sanitize((string) $usage['error'], $server);
        }

        $storage = $withStorage ? $metrics->storage($app, $deployer) : null;
        if (is_array($storage) && isset($storage['error'])) {
            $storage['error'] = $this->sanitize(
                (string) $storage['error'],
                $server,
                'Ukuran storage tidak tersedia.'
            );
        }

        return $this->respond($usage, $storage);
    }

    /**
     * Ambil app + pastikan user berhak membacanya.
     *
     * `protected` semata agar unit test bisa menyuntikkan store temp; produksi
     * tidak berubah.
     *
     * @return array<string,mixed>
     * @throws AppAccessDenied app tidak dikenal atau user tanpa ability `view`
     */
    protected function requireApp(string $id): array
    {
        $app = $this->appStore()->find($id);
        if ($app === null) {
            throw new AppAccessDenied('view', $id);
        }

        AppAccess::require('view', $app, $this->currentUser());

        return $app;
    }

    /**
     * User login saat ini (seam uji tanpa sesi HTTP).
     *
     * @return array<string,mixed>|null
     */
    protected function currentUser(): ?array
    {
        return current_user();
    }

    /** Store app (path mengikuti seam yang disuntikkan). */
    protected function appStore(): AppStore
    {
        return $this->apps ?? new AppStore();
    }

    /** Engine host ini (seam uji). */
    protected function docker(): DockerClient
    {
        return $this->docker ?? new DockerClient(
            (string) config('deploy.docker_socket', '/var/run/docker.sock'),
            (int) config('deploy.monitor_stats_timeout', 20)
        );
    }

    /** Registry server (dipakai untuk memilih deployer & meredaksi pesan galat). */
    protected function serverResolver(): ServerResolver
    {
        return $this->servers ?? new ServerResolver();
    }

    /**
     * Deployer app (satu pintu `DeployerFactory::createForApp()`): lokal ⇒
     * `LocalDeployer`, agent ⇒ `AgentDeployer`.
     *
     * @param array<string,mixed> $app
     * @throws \Throwable server app tidak dikenal / konfigurasi agent cacat
     */
    protected function deployerFor(array $app): DeployerInterface
    {
        return DeployerFactory::createForApp($app, $this->serverResolver());
    }

    /** Perakit metrik (seam uji). */
    protected function metrics(): AppMetrics
    {
        return new AppMetrics($this->docker());
    }

    /**
     * Dokumen server pemilik app — **best-effort**, hanya untuk meredaksi
     * identitas server pada pesan galat. Kegagalan resolve ⇒ `[]` (sanitasi
     * dasar tetap berjalan).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    protected function serverForApp(array $app): array
    {
        try {
            $server = $this->serverResolver()->resolveForApp($app);
        } catch (\Throwable) {
            return [];
        }

        return is_array($server) ? $server : [];
    }

    /**
     * @param array<string,mixed>      $usage
     * @param array<string,mixed>|null $storage
     */
    private function respond(array $usage, ?array $storage)
    {
        return json(['code' => 0, 'data' => [
            'at' => date('H:i:s'),
            'usage' => $usage,
            'storage' => $storage,
        ]]);
    }

    /** Blok usage tanpa data (semua angka `null`, bukan 0 yang menyesatkan). */
    private function emptyUsage(string $error): array
    {
        return [
            'cpu_percent' => null,
            'mem_used' => null,
            'mem_limit' => null,
            'mem_percent' => null,
            'containers' => 0,
            'running' => 0,
            'containers_detail' => [],
            'error' => $error,
        ];
    }

    /**
     * Redaksi pesan galat jalur agent (token, `base_url`/host internal, PEM).
     *
     * @param array<string,mixed> $server dokumen server (kosong ⇒ sanitasi dasar)
     */
    private function sanitize(string $message, array $server, string $fallback = 'Metrik app tidak tersedia.'): string
    {
        $clean = AgentError::sanitizeForServer($message, $server);

        return $clean === '' ? $fallback : $clean;
    }
}
