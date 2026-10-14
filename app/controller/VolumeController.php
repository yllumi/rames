<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentError;
use app\library\Auth\AppAccess;
use app\library\Auth\ServerAccess;
use app\library\Backup\VolumeTargetMap;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Docker\VolumeUsage;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use support\Request;
use Webman\Http\Response;

/**
 * Halaman Volume — melihat volume ber-label compose **dari setiap server yang
 * berhak dilihat user** & membersihkan volume yatim di server lokal. Controller
 * hanya mediator — akses Docker host ini lewat `DockerClient` /
 * `DockerComposeRunner`, akses server agent lewat `AgentDeployer`.
 *
 * Multi-server (plan §9.3/§10.4/§12):
 *  - daftar server disaring **satu pintu** `ServerAccess::visibleServers()`
 *    (aturan tidak disalin di sini). Non-admin hanya melihat server yang
 *    menaungi app yang boleh ia lihat; server lain **tidak dihubungi** sama
 *    sekali (bukan sekadar disembunyikan di view);
 *  - server agent tak terjangkau ⇒ hanya baris server itu yang hilang + satu
 *    pesan peringatan; halaman & server lain tetap tampil;
 *  - volume server **lokal** dibaca dari Engine host ini (perilaku lama), volume
 *    server **agent** dibaca dari server itu (`GET /volumes?project=…`) dan
 *    tidak pernah dihapus dari host ini (`purge()` fail-fast).
 */
class VolumeController
{
    /**
     * Daftar volume ber-label com.docker.compose.project dari **semua server
     * yang boleh dilihat user**. Volume "yatim" = project-nya sudah tidak ada di
     * apps.json (app dihapus) dan bisa dibersihkan — hanya di server lokal.
     */
    public function index(Request $request)
    {
        $collected = $this->collectRows();

        return $this->renderView('volume/index', [
            'rows' => $collected['rows'],
            'engineError' => $collected['engineError'],
            'serverErrors' => $collected['serverErrors'],
            'canPurge' => $this->isAdmin(),
        ]);
    }

    /**
     * Ukuran terpakai tiap volume (JSON) — dipanggil AJAX setelah halaman tampil.
     *
     * `GET /system/df` membuat Engine menelusuri filesystem tiap volume sehingga
     * bisa lambat; halaman `/volumes` tidak boleh menunggunya. Hanya volume
     * **server lokal** yang boleh dilihat user yang dikembalikan (aturan
     * visibilitas sama dengan `index()`): Engine host ini memang tidak mengenal
     * volume server agent, jadi baris agent tidak diikutkan.
     */
    public function usage(Request $request)
    {
        $collected = $this->collectRows(false);
        if ($collected['engineError'] !== null) {
            return json(['code' => 500, 'msg' => $collected['engineError']]);
        }

        try {
            $diskUsage = $this->docker(30)->getDiskUsage(['volume']);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat menghitung ukuran volume: ' . $e->getMessage()]);
        }

        // hanya volume lokal yang tampil di halaman ikut dihitung/dikirim
        return json(['code' => 0, 'data' => VolumeUsage::summarize(
            $diskUsage['Volumes'] ?? [],
            array_column($collected['rows'], 'name')
        )]);
    }

    /**
     * Baris volume dari **setiap server yang berhak dilihat user** + pesan galat
     * per sumber. Dipakai `index()` (render halaman) dan `usage()` (JSON ukuran,
     * hanya server lokal).
     *
     * Kegagalan satu server tidak pernah menggagalkan halaman: Engine lokal ⇒
     * `engineError`; server agent ⇒ satu entri `serverErrors`.
     *
     * @param bool $includeAgents false ⇒ hanya server lokal (dipakai `usage()`)
     * @return array{rows:array<int,array<string,mixed>>,engineError:?string,serverErrors:array<int,string>}
     */
    protected function collectRows(bool $includeAgents = true): array
    {
        $isAdmin = $this->isAdmin();
        $apps = $this->appStore()->all();

        // Nama project app per server (serverKey ⇒ nama ⇒ true) — dibaca **sekali**,
        // bukan per baris (hindari pembacaan store berulang).
        $activeProjects = $this->activeProjectNames($apps);
        $accessibleProjects = $this->accessibleProjectNames($apps);

        $allProjectNames = [];
        foreach ($activeProjects as $names) {
            foreach ($names as $name => $_) {
                $allProjectNames[$name] = true;
            }
        }

        $rows = [];
        $engineError = null;
        $serverErrors = [];

        $localKey = ServerStore::LOCAL_ID;
        $localName = $this->localServerName();
        $localActive = $activeProjects[$localKey] ?? [];
        $localAccessible = $accessibleProjects[$localKey] ?? [];

        // ---- server lokal: Engine host ini (perilaku lama, tidak berubah) ----
        try {
            $volumes = $this->docker()->listVolumes(['label' => ['com.docker.compose.project']]);
            foreach ($volumes as $v) {
                if (VolumeTargetMap::isDashboardVolume($v)) {
                    continue; // volume DB dashboard, bukan volume app (lihat VolumeTargetMap)
                }
                $labels = $v['Labels'] ?? [];
                $project = (string) ($labels['com.docker.compose.project'] ?? '');
                if ($project === '') {
                    continue; // hanya volume ber-label compose yang dikelola dashboard
                }
                // Non-admin hanya melihat volume milik app yang bisa diakses;
                // volume yatim (project sudah tidak ada) hanya untuk admin.
                if (!$isAdmin && !isset($localAccessible[$project])) {
                    continue;
                }
                $orphaned = !isset($localActive[$project]);
                $rows[] = [
                    'name' => (string) ($v['Name'] ?? ''),
                    'project' => $project,
                    'driver' => (string) ($v['Driver'] ?? ''),
                    'mountpoint' => (string) ($v['Mountpoint'] ?? ''),
                    'created_at' => (string) ($v['CreatedAt'] ?? ''),
                    'orphaned' => $orphaned,
                    'server_id' => '',
                    'server_name' => $localName,
                    'server_kind' => 'local',
                    'can_purge' => $orphaned, // purge hanya didukung untuk server lokal
                ];
            }
        } catch (\Throwable $e) {
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage();
        }

        // ---- server agent: dibaca dari server itu, bukan dari host ini ----
        if ($includeAgents) {
            foreach ($this->visibleAgentServers() as $server) {
                $serverId = (string) ($server['id'] ?? '');
                $label = $this->serverLabel($server);
                // Admin: semua app di server itu. Non-admin: hanya app yang boleh ia
                // lihat ⇒ server tanpa app terlihat tidak dihubungi sama sekali.
                $projects = array_keys($isAdmin
                    ? ($activeProjects[$serverId] ?? [])
                    : ($accessibleProjects[$serverId] ?? []));
                if ($projects === []) {
                    continue;
                }

                try {
                    $agent = $this->agentDeployerForServer($server);
                } catch (\Throwable $e) {
                    $serverErrors[] = 'Server "' . $label . '": ' . $this->agentErrorMessage($server, $e);
                    continue;
                }
                if ($agent === null) {
                    continue; // bukan server agent (mis. baris lokal) — bukan jalur ini
                }

                foreach ($projects as $project) {
                    try {
                        $names = $agent->getProjectVolumes($project);
                    } catch (\Throwable $e) {
                        // Server tak terjangkau: satu pesan per server, sisanya jalan.
                        $serverErrors[] = 'Server "' . $label . '": ' . $this->agentErrorMessage($server, $e);
                        break;
                    }
                    foreach ($names as $name) {
                        $rows[] = [
                            'name' => $name,
                            'project' => $project,
                            // Jalur volume agent melaporkan nama saja (bukan
                            // driver/mountpoint/timestamp) → view menampilkan "—".
                            'driver' => '',
                            'mountpoint' => '',
                            'created_at' => '',
                            'orphaned' => !isset($allProjectNames[$project]),
                            'server_id' => $serverId,
                            'server_name' => $label,
                            'server_kind' => 'agent',
                            'can_purge' => false, // hapus volume server agent belum didukung
                        ];
                    }
                }
            }
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['server_name'] !== $b['server_name']) {
                return strcmp((string) $a['server_name'], (string) $b['server_name']);
            }
            if ($a['orphaned'] !== $b['orphaned']) {
                return $a['orphaned'] ? -1 : 1; // volume yatim tampil lebih dulu
            }
            return strcmp((string) $a['project'], (string) $b['project'])
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        return ['rows' => $rows, 'engineError' => $engineError, 'serverErrors' => $serverErrors];
    }

    // ==================================================================
    // Seam (protected) — pola sama `NetworkController`: mudah diuji tanpa I/O
    // ==================================================================

    /**
     * Server agent (kind=agent, aktif) yang boleh dilihat user — **satu pintu**
     * `ServerAccess::visibleServers()`. Non-admin tidak pernah menerima server
     * lain, jadi agent-nya juga tidak pernah dihubungi.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function visibleAgentServers(): array
    {
        try {
            $servers = $this->serverResolver()->all();
        } catch (\Throwable $e) {
            return []; // registry tak terbaca: halaman tetap tampil (tanpa baris agent)
        }

        $visible = ServerAccess::visibleServers($servers, $this->currentUser(), $this->appStore()->all());

        $out = [];
        foreach ($visible as $server) {
            if (!is_array($server) || ServerResolver::isLocal($server) || !ServerResolver::isEnabled($server)) {
                continue;
            }
            if ((string) ($server['id'] ?? '') === '') {
                continue;
            }
            $out[] = $server;
        }

        return $out;
    }

    /**
     * Nama project app per server (serverKey ⇒ nama ⇒ true) — nama project
     * compose = `apps.name` (konvensi yang sama dipakai `VolumeTargetMap`).
     *
     * @param array<int,array> $apps daftar app (`AppStore::all()`)
     * @return array<string,array<string,bool>>
     */
    protected function activeProjectNames(array $apps): array
    {
        $out = [];
        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }
            $name = (string) ($app['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[AppStore::serverIdOf($app)][$name] = true;
        }

        return $out;
    }

    /**
     * Sama seperti {@see self::activeProjectNames()} tetapi hanya app yang boleh
     * dilihat user saat ini (`AppAccess::visible()` — satu pintu hak akses).
     *
     * @param array<int,array> $apps
     * @return array<string,array<string,bool>>
     */
    protected function accessibleProjectNames(array $apps): array
    {
        return $this->activeProjectNames(AppAccess::visible($apps, $this->currentUser()));
    }

    /**
     * Peta nama volume server agent ⇒ label server.
     *
     * Dipakai `purge()` untuk menolak eksplisit **sebelum** efek samping apa pun:
     * volume yang hidup di server agent tidak boleh dihapus dari host ini.
     *
     * @return array<string,string>
     */
    protected function agentVolumeOwners(): array
    {
        $owners = [];
        $activeProjects = $this->activeProjectNames($this->appStore()->all());

        foreach ($this->visibleAgentServers() as $server) {
            $serverId = (string) ($server['id'] ?? '');
            $label = $this->serverLabel($server);
            $projects = array_keys($activeProjects[$serverId] ?? []);
            if ($projects === []) {
                continue;
            }

            try {
                $agent = $this->agentDeployerForServer($server);
            } catch (\Throwable $e) {
                continue; // server cacat/tak terjangkau: tanpa daftar volume
            }
            if ($agent === null) {
                continue;
            }

            foreach ($projects as $project) {
                try {
                    $names = $agent->getProjectVolumes($project);
                } catch (\Throwable $e) {
                    break; // server tak terjangkau — lewati server ini
                }
                foreach ($names as $name) {
                    if (!isset($owners[$name])) {
                        $owners[$name] = $label;
                    }
                }
            }
        }

        return $owners;
    }

    /** Nama server lokal untuk kolom Server (fallback: `local`). */
    private function localServerName(): string
    {
        try {
            return $this->serverLabel($this->serverResolver()->local());
        } catch (\Throwable $e) {
            return ServerStore::LOCAL_ID;
        }
    }

    /**
     * Pesan galat server agent untuk UI: token **dan** `base_url` internal
     * dibuang (halaman tidak pernah memuat rahasia/endpoint server, plan §9.3).
     */
    private function agentErrorMessage(array $server, \Throwable $e): string
    {
        $message = AgentError::sanitize($e->getMessage(), (string) ($server['token'] ?? ''));

        $baseUrl = trim((string) ($server['base_url'] ?? ''));
        if ($baseUrl !== '') {
            $message = str_replace([$baseUrl, rtrim($baseUrl, '/')], 'server agent', $message);
        }

        return $message;
    }

    /** Nama server untuk ditampilkan (fallback: id server). */
    private function serverLabel(array $server): string
    {
        $name = trim((string) ($server['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $id = trim((string) ($server['id'] ?? ''));

        return $id !== '' ? $id : ServerStore::LOCAL_ID;
    }

    /**
     * Client Engine server **lokal** (socket dari config). Timeout default kecil
     * untuk operasi baca cepat; `usage()` memakai timeout lebih longgar karena
     * `/system/df` menghitung ukuran.
     */
    protected function docker(int $timeout = 10): DockerClient
    {
        return new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), $timeout);
    }

    /** Orkestrasi CLI `docker compose` (seam uji: tanpa eksekusi nyata). */
    protected function compose(): DockerComposeRunner
    {
        return new DockerComposeRunner();
    }

    /**
     * Deployer agent untuk satu dokumen server (seam uji) — `null` = server lokal.
     *
     * @param array<string,mixed> $server
     */
    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        return AgentDeployer::forServer($server);
    }

    /** Registry server (seam uji: path store temp). */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /** Store app (seam uji: path temp tanpa `database/` nyata). */
    protected function appStore(): AppStore
    {
        return new AppStore();
    }

    /** User login (seam uji: tanpa sesi Webman). */
    protected function currentUser(): ?array
    {
        return current_user();
    }

    /** Apakah user saat ini admin global (seam uji). */
    protected function isAdmin(): bool
    {
        return is_admin($this->currentUser());
    }

    /** Pesan flash (seam uji: tanpa sesi Webman). */
    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
    }

    /**
     * Render view (seam uji — `view()` butuh konteks HTTP).
     *
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): Response
    {
        return view($template, $vars);
    }

    /**
     * Hapus volume yatim. Menerima `volumes[]` (pilihan) atau `purge_orphans=1`
     * (semua yatim). Volume milik app yang masih aktif DITOLAK (pengaman).
     *
     * Pemurgaan volume bersifat global (volume yatim tidak bisa diatribusikan ke
     * app yang bisa diakses) → hanya admin.
     *
     * Multi-server: volume milik **server agent** ditolak eksplisit (fail-fast)
     * — penghapusan volume agent belum didukung dan tidak boleh pernah jatuh ke
     * host lokal (plan §3 butir 4).
     */
    public function purge(Request $request)
    {
        if (!$this->isAdmin()) {
            $this->flash('error', 'Membersihkan volume bersifat global — hanya admin yang boleh melakukannya.');
            return redirect('/volumes');
        }

        $apps = $this->appStore()->all();
        $projectNames = $this->activeProjectNames($apps)[ServerStore::LOCAL_ID] ?? [];
        $docker = $this->docker();

        try {
            $volumes = $docker->listVolumes(['label' => ['com.docker.compose.project']]);
        } catch (\Throwable $e) {
            $this->flash('error', 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage());
            return redirect('/volumes');
        }

        // peta nama volume → project
        $volumeProject = [];
        foreach ($volumes as $v) {
            if (VolumeTargetMap::isDashboardVolume($v)) {
                continue; // volume DB dashboard tidak boleh masuk daftar purge
            }
            $labels = $v['Labels'] ?? [];
            $p = (string) ($labels['com.docker.compose.project'] ?? '');
            if ($p !== '') {
                $volumeProject[(string) ($v['Name'] ?? '')] = $p;
            }
        }

        if ((string) $request->post('purge_orphans', '') === '1') {
            $targets = array_keys(array_filter(
                $volumeProject,
                static fn (string $p): bool => !isset($projectNames[$p])
            ));
        } else {
            $targets = array_values(array_filter(
                array_map('strval', (array) $request->post('volumes', [])),
                static fn (string $n): bool => $n !== ''
            ));
        }

        // Volume milik server agent (hanya admin yang sampai di sini). Dipetakan
        // sebelum efek samping apa pun supaya penolakannya eksplisit & tidak ada
        // satu pun volume lokal yang terhapus karena salah atribusi server.
        $agentVolumeOwners = $this->agentVolumeOwners();

        // validasi ketat: pola nama volume & harus yatim
        $valid = [];
        foreach ($targets as $name) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
                $this->flash('error', "Nama volume tidak valid: {$name}");
                return redirect('/volumes');
            }
            if (isset($agentVolumeOwners[$name])) {
                $this->flash('error', 'Volume "' . $name . '" berada di server agent "'
                    . $agentVolumeOwners[$name] . '" — menghapus volume server agent belum didukung '
                    . 'dari dashboard ini. Jalankan di server itu sendiri.');
                return redirect('/volumes');
            }
            if (!isset($volumeProject[$name]) || isset($projectNames[$volumeProject[$name]])) {
                $this->flash('error', "Volume {$name} tidak yatim atau masih dipakai app aktif — dilewati.");
                continue;
            }
            $valid[] = $name;
        }

        if ($valid !== []) {
            try {
                $this->compose()->removeVolumes($valid);
                $this->flash('success', count($valid) . ' volume yatim dihapus.');
            } catch (\Throwable $e) {
                $this->flash('error', 'Gagal purge volume: ' . $e->getMessage());
            }
        } else {
            $this->flash('info', 'Tidak ada volume yatim yang dihapus.');
        }
        return redirect('/volumes');
    }
}
