<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\ServerAccess;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\ServerResolver;
use app\library\Docker\ContainerStats;
use app\library\Docker\DockerClient;
use app\library\Monitor\ResourceCollector;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use support\Request;
use Throwable;

/**
 * Monitoring resource container & VM (SPECS §8d) — halaman global `/monitor`.
 *
 * Controller hanya mediator: pembacaan `/proc` host ada di
 * `System\HostUsage`, perhitungan stats container di `Docker\ContainerStats`,
 * dan penggabungan + aturan visibilitas di `Monitor\ResourceCollector`.
 *
 * Hak akses (bertingkat):
 *  - per container: hanya container milik app yang boleh diakses user
 *    (`AppAccess::visible()`); container eksternal (di luar compose project
 *    dashboard) hanya untuk admin;
 *  - total VM: admin melihat seluruh host, user lain melihat ringkasan host
 *    dengan daftar container terbatas pada app yang bisa diakses.
 *
 * Endpoint read-only (GET) → tidak ada CSRF, tanpa state di server
 * (tanpa cache/interval) sehingga aman pada worker Webman persistent.
 *
 * **Multi-server (F3c)**: `/api/monitor/overview` mengumpulkan **semua server** —
 * host lokal lewat Engine & `/proc` host ini, dan tiap server `kind=agent` lewat
 * `AgentDeployer` (metrik diambil **di** server itu; `/proc` & Engine host
 * control plane tidak pernah dipakai untuk app remote, plan §3 butir 4).
 *
 * Bentuk respons **lama tidak berubah**: kunci `host`/`containers`/`totals`/
 * `error`/`scope` tetap milik server **lokal** sehingga `public/js/monitor.js`
 * jalan apa adanya; agregat per server ditambahkan di kunci baru `servers[]`
 * (dipakai misi Frontend UI berikutnya). Server agent yang mati ⇒ entri-nya
 * berstatus `offline` dan **tidak** menggagalkan seluruh halaman (satu server
 * rusak tidak boleh mematikan `/monitor`).
 *
 * **Hak akses server (keputusan §9.3 / plan §4.3)**: `servers[]` disaring di
 * **server** lewat `ServerAccess::visibleServers()` — admin menerima semua
 * server, non-admin hanya server yang menaungi app yang boleh ia lihat.
 * Penyaringan di `public/js/monitor.js` hanya lapisan kedua (kosmetik), bukan
 * pengaman: JSON mentah tidak pernah memuat server milik orang lain.
 */
class MonitorController
{
    /**
     * Halaman `/monitor` — total VM + tabel resource seluruh container.
     *
     * Data diambil asinkron (AJAX) karena `stats` Engine butuh ±1 detik;
     * halaman tidak boleh menunggu Engine agar tetap responsif.
     */
    public function index(Request $request)
    {
        return view('monitor/index', [
            'pageTitle' => 'Monitor',
            'active' => 'monitor',
            'isAdmin' => $this->isAdmin(),
            'appCount' => count($this->visibleApps()),
            'pollMs' => max(0, (int) config('deploy.monitor_poll_ms', 7000)),
        ]);
    }

    /**
     * GET /api/monitor/overview — metrik host + resource container yang boleh
     * dilihat user (admin: seluruh container di host) **beserta agregat per
     * server** (`servers[]`).
     */
    public function overview(Request $request)
    {
        $isAdmin = $this->isAdmin();
        $visible = $this->visibleApps();

        // Jalur lokal PERSIS seperti sebelumnya: hanya app milik server ini yang
        // dipetakan ke compose project host ini (app server agent tidak punya
        // container di sini, jadi namanya tidak boleh memetakan project lokal).
        $local = ResourceCollector::collect($this->localApps($visible), $isAdmin, $this->dockerClient());

        $servers = [$this->localEntry($local)];
        foreach ($this->visibleAgentServers($visible) as $server) {
            $servers[] = $this->agentEntry($server, $visible, $isAdmin);
        }

        return json(['code' => 0, 'data' => $local + [
            'scope' => $isAdmin ? 'all' : 'accessible',
            'at' => date('H:i:s'),
            'servers' => $servers,
        ]]);
    }

    /**
     * GET /api/monitor/host — snapshot metrik host saja (dipanggil berkala oleh
     * halaman `/monitor` selama terbuka).
     *
     * Sengaja terpisah dari `overview()`: `stats` container memblokir ±1 detik
     * per container sedangkan `/proc` hanya pembacaan file lokal, sehingga
     * polling 5–10 detik tidak membebani daemon Docker. Hak akses sama untuk
     * semua user yang login (angka host bersifat ringkasan).
     *
     * **Sengaja tetap server lokal saja**: endpoint ini dipoll tiap beberapa
     * detik; memanggil seluruh agent di sini akan membanjiri jaringan. Metrik
     * per server disajikan `/api/monitor/overview` (halaman dibuka/di-refresh).
     */
    public function host(Request $request)
    {
        return json(['code' => 0, 'data' => [
            'host' => ResourceCollector::hostSnapshot(),
            'at' => date('H:i:s'),
        ]]);
    }

    // ==================================================================
    // Agregat per server (F3c)
    // ==================================================================

    /**
     * Entri server **lokal** untuk `servers[]` — angka-angkanya sudah dihitung
     * `ResourceCollector` di jalur lama (tidak ada perhitungan kedua).
     *
     * @param array<string,mixed> $local hasil `ResourceCollector::collect()`
     * @return array<string,mixed>
     */
    private function localEntry(array $local): array
    {
        $server = $this->localServer();

        return [
            'id' => (string) ($server['id'] ?? ServerStore::LOCAL_ID),
            'name' => (string) ($server['name'] ?? 'local'),
            'kind' => 'local',
            'status' => 'online',
            'enabled' => true,
            'host' => $local['host'] ?? null,
            'containers' => $local['containers'] ?? [],
            'totals' => $local['totals'] ?? null,
            'error' => $local['error'] ?? null,
            'at' => date('H:i:s'),
        ];
    }

    /**
     * Entri satu server **agent** untuk `servers[]`: metrik host + statistik
     * container diambil dari server itu (`AgentDeployer`), lalu baris container
     * disaring dengan aturan visibilitas yang sama dengan jalur lokal.
     *
     * Kegagalan apa pun (server mati, token ditolak, respons tak sesuai kontrak)
     * menjadi `status: 'offline'` + `error` tersanitasi — **tidak** dilempar,
     * supaya satu server rusak tidak mematikan `/monitor`.
     *
     * @param array<string,mixed> $server  dokumen server registry
     * @param array<int,array>    $visible app yang boleh dilihat user
     * @return array<string,mixed>
     */
    private function agentEntry(array $server, array $visible, bool $isAdmin): array
    {
        $entry = [
            'id' => (string) ($server['id'] ?? ''),
            'name' => (string) ($server['name'] ?? ''),
            'kind' => 'agent',
            'status' => 'offline',
            'enabled' => ServerResolver::isEnabled($server),
            'host' => null,
            'containers' => [],
            'totals' => null,
            'error' => null,
            'at' => null,
        ];

        try {
            $deployer = $this->agentDeployerFor($server);
            if ($deployer === null) {
                $entry['error'] = 'Server agent ini belum dapat dipakai sebagai target monitoring.';

                return $entry;
            }

            $host = $deployer->hostUsage();
            $stats = $deployer->containerStats();
            $rows = self::remoteRows($stats['containers'], self::appIds($visible), $isAdmin);

            $entry['status'] = 'online';
            $entry['host'] = $host;
            $entry['containers'] = $rows;
            $entry['totals'] = ContainerStats::aggregate($rows);
            $entry['error'] = $stats['error'];
            $entry['at'] = date('H:i:s');
        } catch (Throwable $e) {
            // Termasuk AgentError (token/TLS/transport) — pesan disanitasi supaya
            // token server tidak pernah muncul di UI.
            $detail = AgentError::sanitize($e->getMessage());
            $entry['error'] = $detail === '' ? 'Server agent tidak dapat dihubungi.' : $detail;
        }

        return $entry;
    }

    /**
     * Saring baris monitoring **dari server agent** dengan aturan yang sama
     * seperti `ResourceCollector` untuk host lokal:
     *  - container milik app → hanya bila app-nya boleh dilihat user;
     *  - container di luar compose project dashboard ("eksternal") → hanya admin;
     *  - container milik project yang **bukan** app dashboard → tidak pernah.
     *
     * (Aturan ini idealnya tinggal di `Monitor\ResourceCollector` bersama
     * `visibleContainers()`; wilayah misi ini tidak mencakup berkas itu, jadi
     * dijaga di sini — satu tempat, di sisi control plane, bukan di agent.)
     *
     * @param array<int,mixed>   $rows    baris dari `AgentDeployer::containerStats()`
     * @param array<string,true> $visible id app yang boleh dilihat user
     * @return array<int,array>
     */
    private static function remoteRows(array $rows, array $visible, bool $isAdmin): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $appId = (string) ($row['app_id'] ?? '');
            if ($appId !== '') {
                if (isset($visible[$appId])) {
                    $out[] = $row;
                }
                continue;
            }
            if ($isAdmin && (string) ($row['project'] ?? '') === '') {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Apakah user saat ini admin — seam uji (pola `NginxController::isAdmin()`);
     * produksi tidak berubah. Diturunkan dari {@see self::currentUser()} supaya
     * keputusan admin & penyaringan server (`ServerAccess`) memakai user yang
     * sama (satu sumber kebenaran, tidak ada dua tafsir role).
     */
    protected function isAdmin(): bool
    {
        return is_admin($this->currentUser());
    }

    /**
     * User dashboard saat ini (seam uji: tanpa sesi/global webman).
     *
     * @return array<string,mixed>|null
     */
    protected function currentUser(): ?array
    {
        return current_user();
    }

    /**
     * App yang boleh dilihat user saat ini (admin: semua app).
     *
     * `protected` (bukan private) semata agar unit test dapat men-bypass
     * `AppStore`/`AppAccess` (data runtime nyata) — produksi tidak berubah.
     *
     * @return array<int,array>
     */
    protected function visibleApps(): array
    {
        return AppAccess::visible((new AppStore())->all(), $this->currentUser());
    }

    /**
     * Saring daftar app yang boleh dilihat menjadi app **server lokal** saja
     * (tanpa multi-server semuanya lokal ⇒ perilaku lama identik).
     *
     * `server_id` tak dikenal diperlakukan sebagai **bukan** milik host ini — app
     * seperti itu tidak boleh memetakan nama project lokal.
     *
     * @param array<int,array> $apps
     * @return array<int,array>
     */
    private function localApps(array $apps): array
    {
        $resolver = $this->serverResolver();
        $local = [];
        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }
            try {
                if (ServerResolver::isLocal($resolver->resolveForApp($app))) {
                    $local[] = $app;
                }
            } catch (Throwable) {
                // server_id tak dikenal ⇒ bukan app host ini; jangan dipetakan.
            }
        }

        return $local;
    }

    /**
     * Server `kind=agent` dari registry (monitoring per server).
     *
     * @return array<int,array<string,mixed>>
     */
    private function agentServers(): array
    {
        $servers = [];
        foreach ($this->serverResolver()->all() as $server) {
            if (!is_array($server) || ServerResolver::isLocal($server) || self::kindOf($server) !== 'agent') {
                continue;
            }
            $servers[] = $server;
        }

        return $servers;
    }

    /**
     * Server agent yang boleh **dilihat** user saat ini — satu pintu
     * {@see ServerAccess::visibleServers()} (jangan menyalin aturannya):
     * admin menerima semua server, non-admin hanya server yang menaungi app yang
     * boleh ia lihat; tanpa app terlihat ⇒ daftar kosong (fail-closed).
     *
     * Inilah pengaman sebenarnya untuk `servers[]` (plan §4.3): penyaringan di
     * `public/js/monitor.js` hanya lapisan kedua. Server yang tidak boleh
     * dilihat **tidak pernah dihubungi** (entri-nya tidak dirakit sama sekali),
     * jadi nama/kind/status/host-nya tak mungkin bocor lewat JSON.
     *
     * @param array<int,array> $visibleApps app yang boleh dilihat user
     * @return array<int,array<string,mixed>>
     */
    private function visibleAgentServers(array $visibleApps): array
    {
        return ServerAccess::visibleServers($this->agentServers(), $this->currentUser(), $visibleApps);
    }

    /**
     * Baris server lokal dari registry (id/nama untuk `servers[]`); registry yang
     * tidak terbaca tidak boleh menggagalkan halaman.
     *
     * @return array<string,mixed>
     */
    private function localServer(): array
    {
        try {
            return $this->serverResolver()->local();
        } catch (Throwable) {
            return ['id' => ServerStore::LOCAL_ID, 'name' => 'local', 'kind' => 'local'];
        }
    }

    /**
     * Id app sebagai kunci pencarian cepat (penyaringan baris container).
     *
     * @param array<int,array> $apps
     * @return array<string,true>
     */
    private static function appIds(array $apps): array
    {
        $ids = [];
        foreach ($apps as $app) {
            if (is_array($app) && (string) ($app['id'] ?? '') !== '') {
                $ids[(string) $app['id']] = true;
            }
        }

        return $ids;
    }

    /** `kind` server (huruf kecil, tanpa spasi). */
    private static function kindOf(array $server): string
    {
        $kind = $server['kind'] ?? '';

        return is_scalar($kind) ? strtolower(trim((string) $kind)) : '';
    }

    /**
     * Resolver registry server (seam uji: subclass memakai store ber-path temp).
     */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /**
     * Deployer agent untuk satu baris registry (seam uji: subclass menyuntikkan
     * deployer dengan klien agent tiruan ⇒ tanpa jaringan & tanpa Docker).
     *
     * @param array<string,mixed> $server
     * @return AgentDeployer|null `null` ⇒ server tidak dapat dipakai
     */
    protected function agentDeployerFor(array $server): ?AgentDeployer
    {
        $deployer = DeployerFactory::createFor($server);

        return $deployer instanceof AgentDeployer ? $deployer : null;
    }

    /**
     * Klien Engine host **ini** (seam uji supaya daemon lokal tidak tersentuh di
     * test). Tidak pernah dipakai untuk server agent.
     */
    protected function dockerClient(): DockerClient
    {
        return new DockerClient(
            (string) config('deploy.docker_socket', '/var/run/docker.sock'),
            (int) config('deploy.monitor_stats_timeout', 20)
        );
    }
}
