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
 *    server **agent** dibaca dari server itu **per server**
 *    (`GET /backups/volumes`, bukan per project) supaya volume **yatim** server
 *    agent ikut terlihat — jalur per project hanya bisa menyebut project yang
 *    masih ada di `apps.json`;
 *  - volume server agent **tidak pernah** dihapus dari host ini: `purge()`
 *    menolaknya eksplisit (fail-fast) dan melaporkan yatim agent yang belum bisa
 *    dibersihkan dari dashboard, bukan melewatinya diam-diam.
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

        // ---- server agent: dibaca **per server** dari server itu ----
        if ($includeAgents) {
            $collected = $this->collectAgentVolumes($activeProjects, $accessibleProjects, $isAdmin);
            foreach ($collected['errors'] as $error) {
                $serverErrors[] = $error;
            }

            foreach ($collected['volumes'] as $volume) {
                // Non-admin: hanya project app yang boleh ia lihat — yatim
                // (project sudah tidak ada) & app user lain disembunyikan, sama
                // dengan jalur lokal.
                if (!$isAdmin && !isset($accessibleProjects[$volume['server_id']][$volume['project']])) {
                    continue;
                }

                $rows[] = [
                    'name' => $volume['name'],
                    'project' => $volume['project'],
                    // Jalur volume agent melaporkan nama + project saja (bukan
                    // driver/mountpoint/timestamp) → view menampilkan "—".
                    'driver' => '',
                    'mountpoint' => '',
                    'created_at' => '',
                    'orphaned' => $volume['orphaned'],
                    'server_id' => $volume['server_id'],
                    'server_name' => $volume['server_label'],
                    'server_kind' => 'agent',
                    'can_purge' => false, // hapus volume server agent belum didukung (lihat purge())
                ];
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
     * Volume seluruh **server agent** yang boleh dilihat user — dibaca **per
     * server** (`AgentDeployer::backupVolumes()` ⇒ `GET /backups/volumes`), satu
     * panggilan per server.
     *
     * Kenapa per server dan bukan per project seperti jalur lama
     * (`GET /volumes?project=…`): daftar per project hanya bisa menyebut project
     * yang masih ada di `apps.json`, sehingga volume **yatim** server agent
     * (app-nya sudah dihapus) tidak pernah terlihat dan purge yatim menjadi buta
     * di server remote (jebakan §12). `GET /backups/volumes` melaporkan seluruh
     * volume ber-label compose **server itu** — termasuk yang yatim — dari Engine
     * server itu juga (Engine host control plane tidak pernah dibaca, plan §3
     * butir 4).
     *
     * `orphaned` **selalu** diputuskan di sini dari `apps.json` server tersebut,
     * bukan dari medan `orphaned` agent: metadata app adalah milik control plane
     * (plan §4.3).
     *
     * Kegagalan satu server tidak pernah menggagalkan halaman: satu entri
     * `errors` (server offline/`config` cacat), server lain tetap tampil.
     *
     * @param array<string,array<string,bool>> $activeProjects     nama project per server (semua app)
     * @param array<string,array<string,bool>> $accessibleProjects nama project per server (app yang boleh dilihat user)
     * @return array{volumes:array<int,array{name:string,project:string,server_id:string,server_label:string,orphaned:bool}>,errors:array<int,string>}
     */
    protected function collectAgentVolumes(array $activeProjects, array $accessibleProjects, bool $isAdmin): array
    {
        $volumes = [];
        $errors = [];

        foreach ($this->visibleAgentServers() as $server) {
            $serverId = (string) ($server['id'] ?? '');
            $label = $this->serverLabel($server);
            $active = $activeProjects[$serverId] ?? [];
            $accessible = $accessibleProjects[$serverId] ?? [];

            // Non-admin: server tanpa satu pun app terlihat **tidak dihubungi**
            // (fail-closed). Admin selalu dihubungi — server yang seluruh app-nya
            // sudah dihapus justru satu-satunya tempat volume yatim berada.
            if (!$isAdmin && $accessible === []) {
                continue;
            }

            try {
                $agent = $this->agentDeployerForServer($server);
            } catch (\Throwable $e) {
                $errors[] = 'Server "' . $label . '": ' . $this->agentErrorMessage($server, $e);
                continue;
            }
            if ($agent === null) {
                continue; // bukan server agent (mis. baris lokal) — bukan jalur ini
            }

            try {
                $rows = $this->agentVolumeRows(
                    $agent,
                    $isAdmin ? array_keys($active) : array_keys($accessible)
                );
            } catch (\Throwable $e) {
                // Server tak terjangkau: satu pesan per server, sisanya jalan.
                $errors[] = 'Server "' . $label . '": ' . $this->agentErrorMessage($server, $e);
                continue;
            }

            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $project = trim((string) ($row['project'] ?? ''));
                if ($name === '' || $project === '') {
                    continue; // kontrak agent tidak lengkap — lewati barisnya, bukan servernya
                }

                $volumes[] = [
                    'name' => $name,
                    'project' => $project,
                    'server_id' => $serverId,
                    'server_label' => $label,
                    'orphaned' => !isset($active[$project]),
                ];
            }
        }

        return ['volumes' => $volumes, 'errors' => $errors];
    }

    /**
     * Baris volume satu server agent (`name` + `project`) — jalur utama
     * `GET /backups/volumes`, dengan **mundur** ke jalur per project
     * (`GET /volumes?project=…`) bila agent belum punya endpoint itu
     * (`not_found`/`unsupported`) supaya agent versi lama tetap menampilkan
     * volume app-nya (tanpa yatim) alih-alih kehilangan seluruh baris.
     *
     * Galat lain (tak terjangkau/TLS/token) **tidak** ditelan: itu bukan alasan
     * memakai jalur alternatif, dan harus tampil sebagai galat server itu.
     *
     * @param array<int,string> $projects project yang boleh dikirim ke agent
     * @return array<int,array{name:string,project:string}>
     * @throws \Throwable server tak terjangkau / respons tidak sesuai kontrak
     */
    protected function agentVolumeRows(AgentDeployer $agent, array $projects): array
    {
        try {
            return $agent->backupVolumes()['volumes'];
        } catch (\Throwable $e) {
            $legacy = $e instanceof AgentError
                && in_array($e->reason, [AgentError::NOT_FOUND, AgentError::UNSUPPORTED], true);
            if (!$legacy) {
                throw $e;
            }
        }

        $rows = [];
        foreach ($projects as $project) {
            foreach ($agent->getProjectVolumes($project) as $name) {
                $rows[] = ['name' => $name, 'project' => $project];
            }
        }

        return $rows;
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
     * Pesan galat server agent untuk UI: token, `base_url`/host internal, dan
     * blok PEM dibuang lewat **satu pintu** `AgentError::sanitizeForServer()`
     * (halaman tidak pernah memuat rahasia/endpoint server, plan §9.3). Jangan
     * menyalin regex `base_url` di controller ini lagi.
     */
    private function agentErrorMessage(array $server, \Throwable $e): string
    {
        return AgentError::sanitizeForServer($e->getMessage(), $server);
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
     * (semua yatim **server lokal**). Volume milik app yang masih aktif DITOLAK
     * (pengaman).
     *
     * Pemurgaan volume bersifat global (volume yatim tidak bisa diatribusikan ke
     * app yang bisa diakses) → hanya admin.
     *
     * Multi-server: volume milik **server agent** tidak pernah jatuh ke host
     * lokal (plan §3 butir 4) —
     *  - disebut eksplisit di `volumes[]` ⇒ ditolak **sebelum** efek samping apa
     *    pun (fail-fast, tidak ada satu pun volume lokal terhapus);
     *  - `purge_orphans=1` ⇒ hanya yatim Engine host ini yang dihapus, sementara
     *    yatim server agent (yang terdeteksi lewat `GET /backups/volumes`)
     *    dilaporkan eksplisit supaya tidak diam-diam dilewatkan. Penghapusan
     *    volume server agent belum punya op/job di agent, jadi belum dijalankan
     *    dari sini.
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

        $agentListing = $this->collectAgentVolumes(
            $this->activeProjectNames($apps),
            $this->accessibleProjectNames($apps),
            $this->isAdmin()
        );

        if ($agentListing['errors'] !== []) {
            // Volume server agent tidak bisa diperiksa (offline/config cacat):
            // beri tahu admin — daftar yatim yang ditampilkan/dihapus **tidak
            // lengkap**, jangan diam-diam dilewatkan. Pesannya sudah diredaksi
            // (`collectAgentVolumes()`), jadi tanpa token/base_url internal.
            foreach ($agentListing['errors'] as $error) {
                $this->flash('error', 'Volume server agent tidak diperiksa — ' . $error);
            }
        }

        $agentOwners = [];
        $agentOrphans = [];
        foreach ($agentListing['volumes'] as $volume) {
            $agentOwners[$volume['name']] ??= $volume['server_label'];
            if ($volume['orphaned']) {
                $agentOrphans[$volume['name']] = $volume['server_label'];
            }
        }

        if ((string) $request->post('purge_orphans', '') === '1') {
            $targets = array_keys(array_filter(
                $volumeProject,
                static fn (string $p): bool => !isset($projectNames[$p])
            ));

            // Purge massal menyasar **hanya** Engine host ini (volume agent tidak
            // pernah masuk daftar di atas). Yatim server agent dilaporkan
            // eksplisit — jangan sampai admin mengira semuanya sudah bersih.
            if ($agentOrphans !== []) {
                $servers = array_values(array_unique(array_values($agentOrphans)));
                sort($servers);
                $this->flash(
                    'error',
                    count($agentOrphans) . ' volume yatim di server agent tidak dihapus (server '
                    . implode(', ', $servers) . ') — penghapusan volume server agent belum didukung '
                    . 'dari dashboard ini. Hapus di server itu sendiri, atau pilih volume lokal '
                    . 'lalu gunakan "Hapus volume terpilih".'
                );
            }
        } else {
            $targets = array_values(array_filter(
                array_map('strval', (array) $request->post('volumes', [])),
                static fn (string $n): bool => $n !== ''
            ));
        }

        // validasi ketat: pola nama volume & harus yatim. Volume milik server
        // agent ditolak **sebelum efek samping apa pun** supaya tidak ada satu pun
        // volume lokal yang terhapus karena salah atribusi server (fail-fast).
        $valid = [];
        foreach ($targets as $name) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
                $this->flash('error', "Nama volume tidak valid: {$name}");
                return redirect('/volumes');
            }
            if (isset($agentOwners[$name])) {
                $this->flash('error', 'Volume "' . $name . '" berada di server agent "'
                    . $agentOwners[$name] . '" — menghapus volume server agent belum didukung '
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
