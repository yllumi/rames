<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use app\library\Agent\AgentError;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use support\Request;

/**
 * Halaman Network — mengelola network Docker host (Engine API via DockerClient).
 *
 * **Per server (F3c multi-server, plan §5)**: halaman ini dapat dibatasi ke satu
 * server lewat parameter `server` (`?server=<id>` / field POST `server`). Untuk
 * server `kind=agent` seluruh operasi Engine dijalankan **di server itu** lewat
 * `AgentDeployer`; daemon host control plane tidak pernah disentuh untuk app
 * remote (plan §3 butir 4). Tanpa parameter, perilakunya persis seperti dulu
 * (server lokal).
 *
 * Karena satu network hanya bermakna di satu server, aturan berikut ditegakkan:
 *  - daftar/„shared network” disaring per server (`external_networks` app di
 *    server lain **diabaikan** di halaman ini);
 *  - connect container yang app-nya berjalan di server lain **ditolak** dengan
 *    pesan jelas (`assertContainerServer()`), bukan dieksekusi ke server salah.
 *
 * Controller hanya mediator — tanpa state di properti (Webman persistent).
 *
 * **Hak akses server (keputusan §9.3)**: admin boleh semua server; member
 * bergrant `server_select` boleh server lokal + allowlist (`selectableServers()`);
 * non-admin lainnya hanya server yang menaungi app yang boleh ia lihat
 * (`ServerAccess::visibleServers()`). Server di luar jangkauan **ditolak 404** lewat
 * `AppAccessDenied` (bukan 403, bukan fallback diam-diam ke server lokal) supaya
 * keberadaan server itu tidak bocor — lihat `requestedServer()`/`assertServerVisible()`.
 * Id server **tak dikenal** diperlakukan sama (404, tanpa pesan pembeda, untuk
 * daftar maupun mutasi) supaya tidak ada oracle keberadaan server.
 * Penyembunyian pemilih server di view hanya lapisan kedua, bukan pengaman.
 *
 * Proteksi hapus network (berlapis, di controller BUKAN hanya di UI):
 *  - Network built-in/system (bridge, host, none, ingress, docker_gwbridge) → blok.
 *  - Network yang masih dipakai container → blok (Engine juga menolak dengan 409).
 *  - Network compose milik app yang masih ada di apps.json **server itu** → blok
 *    (dikelola lewat halaman app; hindari merusak networking app yang berjalan).
 */
class NetworkController
{
    /** Network built-in / system yang tidak pernah boleh dihapus. */
    private const PROTECTED_NAMES = ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'];

    /**
     * Daftar semua network + status (built-in / dikelola app / dipakai / bebas).
     */
    public function index(Request $request)
    {
        $engineError = null;
        $rows = [];
        $isAdmin = $this->isAdmin();
        $server = null;
        $agent = null;
        $scopeFailed = false;

        try {
            $server = $this->requestedServer($request);
            $agent = $server !== null ? $this->agentFor($server) : null;
        } catch (AppAccessDenied $e) {
            throw $e; // server di luar jangkauan user / tak dikenal ⇒ 404 (jangan ditelan jadi pesan halaman)
        } catch (\Throwable $e) {
            // Server **terlihat** tapi konfigurasi agentnya cacat: **jangan**
            // diam-diam menampilkan network server lokal (itu server yang salah)
            // — tampilkan galatnya dan daftar kosong. (Id tak dikenal tidak
            // sampai ke sini: `requestedServer()` sudah melempar 404.)
            $engineError = $this->serverError($server, $e);
            $scopeFailed = true;
        }

        $serverId = $this->serverKeyOf($server);
        $accessible = $this->accessibleProjects($serverId);
        $sharedAttached = $this->sharedNetworkNames($serverId);

        try {
            if ($scopeFailed) {
                $networks = [];
            } else {
                $networks = $agent !== null ? $agent->networks() : $this->docker()->listNetworks();
            }
            $activeProjects = $scopeFailed ? [] : $this->activeProjects($serverId);
            $inspect = $agent !== null
                ? static fn (string $id): array => $agent->networkDetail($id)['network']
                : fn (string $id): array => $this->docker()->inspectNetwork($id);

            foreach ($networks as $n) {
                $name = (string) ($n['Name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $project = (string) (($n['Labels'] ?? [])['com.docker.compose.project'] ?? '');
                $builtin = in_array($name, self::PROTECTED_NAMES, true);
                $managed = $project !== '' && isset($activeProjects[$project]);
                $isYours = $project !== '' && isset($accessible[$project]);
                $sharedHere = isset($sharedAttached[$name]);

                // Non-admin hanya melihat network milik app yang bisa diakses +
                // shared network yang terpasang di app tersebut (satu server).
                if (!$isAdmin && !$isYours && !$sharedHere) {
                    continue;
                }

                $count = $this->containerCount($n, $inspect);

                $rows[] = [
                    'id' => (string) ($n['Id'] ?? ''),
                    'name' => $name,
                    'driver' => (string) ($n['Driver'] ?? ''),
                    'scope' => (string) ($n['Scope'] ?? 'local'),
                    'created_at' => (string) ($n['Created'] ?? ''),
                    'internal' => (bool) ($n['Internal'] ?? false),
                    'attachable' => (bool) ($n['Attachable'] ?? false),
                    'project' => $project,
                    'container_count' => $count,
                    'builtin' => $builtin,
                    'in_use' => $count > 0,
                    'managed' => $managed,
                    'mine' => $isYours || $sharedHere,
                    // Hapus network = operasi global → hanya admin, dan tetap wajib bebas.
                    'can_delete' => $isAdmin && !$builtin && !$managed && $count === 0,
                ];
            }

            // Urutkan: network yang bisa dihapus ("bebas") dulu, lalu dipakai/dikelola,
            // built-in paling bawah; selebihnya alfabetis.
            usort($rows, static function (array $a, array $b): int {
                $ga = $a['builtin'] ? 3 : ($a['managed'] || $a['in_use'] ? 2 : 1);
                $gb = $b['builtin'] ? 3 : ($b['managed'] || $b['in_use'] ? 2 : 1);
                return $ga <=> $gb ?: strcmp((string) $a['name'], (string) $b['name']);
            });
        } catch (\Throwable $e) {
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $this->serverError($server, $e);
        }

        return $this->renderView('network/index', [
            'rows' => $rows,
            'engineError' => $engineError,
            'canManage' => $isAdmin,
            // Cakupan server (halaman per server, F3c). Field tambahan ini tidak
            // mengubah pemakaian lama view (yang belum membacanya = server lokal).
            'serverId' => $serverId,
            'serverName' => (string) ($server['name'] ?? ''),
            'servers' => $this->serverOptions(),
        ]);
    }

    /**
     * Buat network baru (POST /networks/create).
     */
    public function create(Request $request)
    {
        // Cakupan server dibaca lebih dulu supaya pesan/redirect tetap di
        // halaman server yang benar (agent-scoped page tidak "melompat" ke lokal).
        $server = null;
        try {
            $server = $this->requestedServer($request, true);
        } catch (AppAccessDenied $e) {
            throw $e; // server di luar jangkauan user ⇒ 404, tanpa efek samping
        } catch (\Throwable $e) {
            $this->flash('error', $this->serverError($server, $e));
            return redirect('/networks');
        }
        $back = $this->redirectToNetworks($server);

        if (!$this->isAdmin()) {
            $this->flash('error', 'Membuat network bersifat global (lintas app) — hanya admin yang boleh melakukannya.');
            return $back;
        }

        $name = strtolower(trim((string) $request->post('name', '')));
        $driver = (string) $request->post('driver', 'bridge');
        $subnet = trim((string) $request->post('subnet', ''));
        $gateway = trim((string) $request->post('gateway', ''));
        $ipRange = trim((string) $request->post('ip_range', ''));
        $internal = (string) $request->post('internal', '') === '1';
        $attachable = (string) $request->post('attachable', '') === '1';

        if ($name === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
            $this->flash('error', 'Nama network tidak valid. Gunakan huruf/angka, titik, underscore, atau dash (diawali huruf/angka).');
            return $back;
        }
        $allowedDrivers = ['bridge', 'overlay', 'macvlan'];
        if (!in_array($driver, $allowedDrivers, true)) {
            $driver = 'bridge';
        }

        // Validasi ringan format IPAM (Engine melakukan validasi final).
        if ($subnet !== '' && !preg_match('#^[0-9a-fA-F:.]+/\d+$#', $subnet)) {
            $this->flash('error', 'Subnet harus berupa CIDR, mis. 172.20.0.0/16.');
            return $back;
        }
        foreach (['gateway' => $gateway, 'ip_range' => $ipRange] as $label => $val) {
            if ($val !== '' && !preg_match('#^[0-9a-fA-F:.]+$#', $val)) {
                $this->flash('error', ucfirst($label) . ' harus berupa alamat IP, mis. 172.20.0.1.');
                return $back;
            }
        }

        $config = [
            'Name' => $name,
            'Driver' => $driver,
            'Internal' => $internal,
            'Attachable' => $attachable,
            'CheckDuplicate' => true,
        ];
        if ($subnet !== '') {
            $ipamConfig = ['Subnet' => $subnet];
            if ($gateway !== '') {
                $ipamConfig['Gateway'] = $gateway;
            }
            if ($ipRange !== '') {
                $ipamConfig['IPRange'] = $ipRange;
            }
            $config['IPAM'] = ['Driver' => 'default', 'Config' => [$ipamConfig]];
        }

        try {
            $agent = $server !== null ? $this->agentFor($server) : null;
            if ($agent !== null) {
                // Network dibuat DI server itu (bukan di host control plane).
                $agent->createNetwork($config);
            } else {
                $this->docker()->createNetwork($config);
            }
            $this->flash('success', "Network \"{$name}\" dibuat.");
        } catch (\Throwable $e) {
            $this->flash('error', $this->serverError($server, $e));
        }

        return $back;
    }

    /**
     * Detail network: info + container terhubung + form connect.
     */
    public function detail(Request $request, string $id)
    {
        $server = null;
        $agent = null;
        try {
            $server = $this->requestedServer($request);
            $agent = $server !== null ? $this->agentFor($server) : null;
        } catch (AppAccessDenied $e) {
            throw $e; // server di luar jangkauan user ⇒ 404 (fail-closed)
        } catch (\Throwable $e) {
            $this->flash('error', $this->serverError($server, $e));

            return redirect('/networks');
        }

        if ($agent !== null) {
            return $this->agentDetail($agent, $server, $id);
        }

        $docker = $this->docker();
        $engineError = null;
        $network = null;
        $attached = [];
        $candidates = [];

        try {
            $network = $docker->inspectNetwork($id);

            // Container terhubung (dari field Containers hasil inspect).
            $attachedRaw = $network['Containers'] ?? [];
            $attachedIds = [];
            foreach (is_array($attachedRaw) ? $attachedRaw : [] as $ep) {
                $cname = ltrim((string) ($ep['Name'] ?? ''), '/');
                if ($cname === '') {
                    continue;
                }
                $attached[] = [
                    'name' => $cname,
                    'ipv4' => (string) ($ep['IPv4Address'] ?? ''),
                    'ipv6' => (string) ($ep['IPv6Address'] ?? ''),
                    'mac' => (string) ($ep['MacAddress'] ?? ''),
                ];
                $attachedIds[$cname] = true;
            }
            usort($attached, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

            // Kandidat container yang bisa di-connect (semua container, kecuali
            // yang sudah terhubung). Connect memakai nama container (tanpa slash).
            foreach ($docker->listContainers() as $c) {
                $names = $c['Names'] ?? [];
                $cname = is_array($names) && isset($names[0]) ? ltrim((string) $names[0], '/') : '';
                if ($cname === '' || isset($attachedIds[$cname])) {
                    continue;
                }
                $candidates[] = [
                    'name' => $cname,
                    'image' => (string) ($c['Image'] ?? ''),
                    'status' => (string) ($c['State'] ?? ''),
                ];
            }
            usort($candidates, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                $this->flash('error', 'Network tidak ditemukan.');
                return redirect('/networks');
            }
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $this->serverError($server, $e);
        }

        $name = $network !== null ? (string) ($network['Name'] ?? $id) : $id;

        return $this->renderView('network/detail', [
            'id' => $id,
            'name' => $name,
            'network' => $network,
            'attached' => $attached,
            'candidates' => $candidates,
            'engineError' => $engineError,
            'builtin' => in_array($name, self::PROTECTED_NAMES, true),
            'canManage' => $this->isAdmin(),
            'serverId' => '',
            'serverName' => '',
            'servers' => $this->serverOptions(),
        ]);
    }

    /**
     * Detail network **di server agent** — kontrol & kandidat container dibaca
     * dari agent (Engine server itu), bukan dari daemon host control plane.
     *
     * @param array<string,mixed> $server
     */
    private function agentDetail(AgentDeployer $agent, array $server, string $id)
    {
        $serverId = $this->serverKeyOf($server);

        try {
            $data = $agent->networkDetail($id);
        } catch (AgentError $e) {
            if (AgentDeployer::failureCode($e) === 404 || str_contains($e->getMessage(), '404')) {
                $this->flash('error', 'Network tidak ditemukan di server "' . $serverId . '".');
            } else {
                $this->flash('error', $this->serverError($server, $e));
            }

            return $this->redirectToNetworks($server);
        }

        $network = $data['network'];
        $name = (string) ($network['Name'] ?? $id);

        return $this->renderView('network/detail', [
            'id' => $id,
            'name' => $name,
            'network' => $network,
            'attached' => $data['attached'],
            'candidates' => $data['candidates'],
            'engineError' => null,
            'builtin' => in_array($name, self::PROTECTED_NAMES, true),
            'canManage' => $this->isAdmin(),
            'serverId' => $serverId,
            'serverName' => (string) ($server['name'] ?? ''),
            'servers' => $this->serverOptions(),
        ]);
    }

    /**
     * Hubungkan container ke network (POST /networks/{id}/connect).
     *
     * **Satu server**: container milik app yang berjalan di server lain
     * ditolak (`assertContainerServer()`) — network tidak bisa dipakai lintas
     * server (plan §5).
     */
    public function connect(Request $request, string $id)
    {
        $server = null;
        try {
            $server = $this->requestedServer($request, true);
        } catch (AppAccessDenied $e) {
            throw $e; // server di luar jangkauan user ⇒ 404, tanpa efek samping
        } catch (\Throwable $e) {
            $this->flash('error', $this->serverError($server, $e));

            return redirect('/networks');
        }
        $back = $this->redirectToNetworks($server, $id);

        if (!$this->isAdmin()) {
            $this->flash('error', 'Menghubungkan container ke network bersifat global — hanya admin yang boleh melakukannya. Gunakan tab Network di detail app untuk koneksi persisten.');
            return $back;
        }

        $container = trim((string) $request->post('container', ''));
        $alias = trim((string) $request->post('alias', ''));

        if ($container === '') {
            $this->flash('error', 'Pilih container untuk dihubungkan.');
            return $back;
        }

        try {
            $this->assertContainerServer($container, $this->serverKeyOf($server));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $back;
        }

        $endpointConfig = [];
        if ($alias !== '') {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $alias)) {
                $this->flash('error', 'Alias tidak valid. Gunakan huruf/angka, titik, underscore, atau dash.');
                return $back;
            }
            $endpointConfig['Aliases'] = [$alias];
        }

        try {
            $agent = $server !== null ? $this->agentFor($server) : null;
            if ($agent !== null) {
                $agent->connectNetwork($id, $container, $alias);
            } else {
                $this->docker()->connectContainerToNetwork($id, $container, $endpointConfig);
            }
            $this->flash('success', "Container \"{$container}\" dihubungkan ke \"{$id}\".");
        } catch (\Throwable $e) {
            $this->flash('error', 'Gagal menghubungkan container: ' . $this->serverError($server, $e));
        }

        return $back;
    }

    /**
     * Putuskan container dari network (POST /networks/{id}/disconnect).
     */
    public function disconnect(Request $request, string $id)
    {
        $server = null;
        try {
            $server = $this->requestedServer($request, true);
        } catch (AppAccessDenied $e) {
            throw $e; // server di luar jangkauan user ⇒ 404, tanpa efek samping
        } catch (\Throwable $e) {
            $this->flash('error', $this->serverError($server, $e));

            return redirect('/networks');
        }
        $back = $this->redirectToNetworks($server, $id);

        if (!$this->isAdmin()) {
            $this->flash('error', 'Memutus container dari network bersifat global — hanya admin yang boleh melakukannya. Gunakan tab Network di detail app untuk koneksi persisten.');
            return $back;
        }

        $container = trim((string) $request->post('container', ''));
        if ($container === '') {
            $this->flash('error', 'Container tidak valid.');
            return $back;
        }

        try {
            $agent = $server !== null ? $this->agentFor($server) : null;
            if ($agent !== null) {
                $agent->disconnectNetwork($id, $container);
            } else {
                $this->docker()->disconnectContainerFromNetwork($id, $container, false);
            }
            $this->flash('success', "Container \"{$container}\" diputus dari \"{$id}\".");
        } catch (\Throwable $e) {
            $this->flash('error', 'Gagal memutus container: ' . $this->serverError($server, $e));
        }

        return $back;
    }

    /**
     * Hapus network dengan proteksi berlapis (POST /networks/{id}/delete).
     *
     * Untuk server agent: daftar network & penghapusan dijalankan **di server
     * itu**; proteksi "dikelola app"/"masih dipakai" tetap dinilai di sini
     * (control plane memegang `AppStore` server tersebut).
     */
    public function delete(Request $request, string $id)
    {
        $server = null;
        try {
            $server = $this->requestedServer($request, true);
        } catch (AppAccessDenied $e) {
            throw $e; // server di luar jangkauan user ⇒ 404, tanpa efek samping
        } catch (\Throwable $e) {
            $this->flash('error', $this->serverError($server, $e));

            return redirect('/networks');
        }
        $back = $this->redirectToNetworks($server);

        if (!$this->isAdmin()) {
            $this->flash('error', 'Menghapus network bersifat global — hanya admin yang boleh melakukannya.');
            return $back;
        }

        $serverId = $this->serverKeyOf($server);

        try {
            $agent = $server !== null ? $this->agentFor($server) : null;
            $docker = $agent === null ? $this->docker() : null;
            $networks = $agent !== null ? $agent->networks() : $docker->listNetworks();
        } catch (\Throwable $e) {
            $this->flash('error', 'Tidak dapat mengakses Docker Engine: ' . $this->serverError($server, $e));
            return $back;
        }

        $target = null;
        foreach ($networks as $n) {
            if ((string) ($n['Name'] ?? '') === $id) {
                $target = $n;
                break;
            }
        }
        if ($target === null) {
            $this->flash('error', "Network \"{$id}\" tidak ditemukan (mungkin sudah dihapus).");
            return $back;
        }

        $name = (string) ($target['Name'] ?? $id);
        if (in_array($name, self::PROTECTED_NAMES, true)) {
            $this->flash('error', "Network built-in \"{$name}\" tidak bisa dihapus.");
            return $back;
        }

        $project = (string) (($target['Labels'] ?? [])['com.docker.compose.project'] ?? '');
        if ($project !== '' && isset($this->activeProjects($serverId)[$project])) {
            $this->flash('error', "Network \"{$name}\" dikelola app \"{$project}\" (compose) — kelola lewat halaman app, bukan di sini.");
            return $back;
        }

        $inspect = $agent !== null
            ? static fn (string $ref): array => $agent->networkDetail($ref)['network']
            : fn (string $ref): array => $this->docker()->inspectNetwork($ref);
        $count = $this->containerCount($target, $inspect);
        if ($count > 0) {
            $this->flash('error', "Network \"{$name}\" masih dipakai {$count} container. Hentikan/putuskan container dulu sebelum menghapus.");
            return $back;
        }

        try {
            if ($agent !== null) {
                $agent->deleteNetwork($name);
            } else {
                $this->docker()->removeNetwork($id);
            }
            $this->flash('success', "Network \"{$name}\" dihapus.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Gagal menghapus network: ' . $this->serverError($server, $e));
        }

        return $back;
    }

    /**
     * Jumlah container yang terhubung ke sebuah network. Memakai field Containers
     * hasil list bila ada; fallback ke inspect (lokal **atau** agent lewat
     * `$inspect`).
     *
     * @param callable(string):array|null $inspect fallback inspect untuk Engine
     *                                             server network itu
     */
    private function containerCount(array $network, ?callable $inspect = null): int
    {
        $containers = $network['Containers'] ?? null;
        if (is_array($containers)) {
            return count($containers);
        }
        if (is_int($containers)) {
            return $containers;
        }
        $id = (string) ($network['Id'] ?? '');
        if ($inspect === null || $id === '') {
            return 0;
        }
        try {
            $detail = $inspect($id);
            $c = $detail['Containers'] ?? [];
            return is_array($c) ? count($c) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Flash message (seam uji: tanpa sesi/global webman).
     */
    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
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
     * Apakah user saat ini admin global (seam uji: tanpa sesi webman).
     *
     * Operasi network bersifat global per server, jadi keputusan admin ada di
     * satu tempat ini — bukan tersebar sebagai `is_admin()` di tiap aksi.
     */
    protected function isAdmin(): bool
    {
        return is_admin($this->currentUser());
    }

    /**
     * Store app control plane (seam uji: path temp tanpa `database/` nyata).
     */
    protected function appStore(): AppStore
    {
        return new AppStore();
    }

    /**
     * Render view (seam uji — `view()` butuh konteks HTTP).
     *
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): \Webman\Http\Response
    {
        return view($template, $vars);
    }

    /**
     * Registry server (seam uji: store temp tanpa `database/` nyata).
     */
    protected function serverResolver(): ServerResolver
    {
        return new ServerResolver();
    }

    /**
     * Server yang dipilih halaman/aksi: `null` = server lokal (perilaku lama).
     *
     * `server` kosong ⇒ lokal. Id tak dikenal diperlakukan **sama** dengan
     * "tidak berhak" ⇒ `AppAccessDenied` (**404**, keputusan §9.3/§12): kalau
     * id yang ADA-tapi-bukan-milik-user menjawab 404 sementara id tak dikenal
     * menjawab 200 dengan pesan "Server tidak dikenal", selisih itu sendiri
     * adalah oracle keberadaan server. Karena itu id tak dikenal **tidak pernah**
     * jatuh ke server lokal (plan §3 butir 4) dan **tidak pernah** dirender
     * sebagai halaman/pesan yang membedakannya — termasuk untuk admin (pilihan
     * server di luar registry = pilihan tidak sah).
     *
     * `?server=local` bukan pemilihan server (hasilnya sama dengan tanpa
     * parameter: cakupan server lokal yang tetap disaring per app) sehingga tetap
     * boleh untuk semua user yang login.
     *
     * @return array<string,mixed>|null
     * @throws AppAccessDenied server tak dikenal / di luar jangkauan user
     */
    private function requestedServer(Request $request, bool $post = false): ?array
    {
        $id = trim((string) ($post ? $request->post('server', '') : $request->get('server', '')));
        if ($id === '') {
            return null;
        }

        try {
            $server = $this->serverResolver()->resolve($id);
        } catch (\Throwable) {
            // Id tak dikenal / registry server tak terbaca ⇒ fail-closed 404,
            // identik dengan "server milik orang lain" (tanpa pesan pembeda).
            throw new AppAccessDenied(ServerAccess::ABILITY_SELECT);
        }

        if (ServerResolver::isLocal($server)) {
            return null;
        }

        $this->assertServerVisible($server);

        return $server;
    }

    /**
     * Gerbang "pilih server" — **fail-closed**: server yang tidak terlihat user
     * (non-admin tanpa app di sana) ditolak `AppAccessDenied` ⇒ 404, bukan 403
     * (keberadaan server tidak bocor) dan bukan operasi di server yang salah.
     *
     * Satu pintu: keputusan diserahkan ke `ServerAccess::visibleServers()`
     * (aturan tidak disalin di controller). Dipakai sebelum efek samping apa pun.
     *
     * @param array<string,mixed> $server
     * @throws AppAccessDenied
     */
    private function assertServerVisible(array $server): void
    {
        $visible = ServerAccess::visibleServers([$server], $this->currentUser(), $this->appStore()->all());
        if ($visible === []) {
            throw new AppAccessDenied(ServerAccess::ABILITY_SELECT);
        }
    }

    /**
     * Deployer agent untuk server terpilih (seam uji; `null` ⇒ jalur lokal).
     *
     * @param array<string,mixed> $server
     * @throws AgentError server agent yang konfigurasinya cacat
     */
    protected function agentFor(array $server): ?AgentDeployer
    {
        return AgentDeployer::forServer($server);
    }

    /**
     * Kunci server untuk membandingkan kepemilikan app dengan network:
     * `''` = server lokal, selain itu id server.
     *
     * @param array<string,mixed>|null $server
     */
    private function serverKeyOf(?array $server): string
    {
        if ($server === null) {
            return '';
        }

        $id = (string) ($server['id'] ?? '');

        return ServerResolver::isLocal($server) ? '' : $id;
    }

    /**
     * `server_id` efektif sebuah app (`''` ⇒ server lokal).
     *
     * @param array<string,mixed> $app
     */
    private function serverKeyOfApp(array $app): string
    {
        $id = trim((string) ($app['server_id'] ?? ''));

        return ($id === '' || $id === ServerStore::LOCAL_ID) ? '' : $id;
    }

    /**
     * Container yang app-nya berjalan di server **lain** tidak boleh
     * disambungkan ke network server ini — network Docker tidak lintas server
     * (plan §5). Container eksternal (tanpa app di `apps.json`) tidak dibatasi:
     * admin yang memutuskan, dan Engine tetap menolak bila memang tidak ada.
     *
     * @throws \RuntimeException pesan jelas untuk UI (flash)
     */
    protected function assertContainerServer(string $container, string $serverId): void
    {
        $app = $this->appOwningContainer($container);
        if ($app === null) {
            return;
        }

        $ownerServerId = $this->serverKeyOfApp($app);
        if ($ownerServerId === $serverId) {
            return;
        }

        $here = $serverId === '' ? 'server lokal' : 'server "' . $this->serverLabel($serverId) . '"';
        $there = $ownerServerId === '' ? 'server lokal' : 'server "' . $this->serverLabel($ownerServerId) . '"';

        throw new \RuntimeException(sprintf(
            'Container "%s" milik app "%s" yang berjalan di %s — network ini ada di %s. '
            . 'Shared network hanya berlaku dalam satu server; jalankan operasi dari %s.',
            $container,
            (string) ($app['name'] ?? ''),
            $there,
            $here,
            $there
        ));
    }

    /**
     * Pesan galat untuk UI/log — **satu pintu** higienitas identitas server:
     * cakupan server agent (`$server !== null`) disanitasi
     * `AgentError::sanitizeForServer()` sehingga token, `base_url`/host internal,
     * dan blok PEM tidak pernah tampil; cakupan lokal (`$server === null`) tetap
     * seperti sebelumnya (`AgentError` tidak mungkin datang dari jalur itu).
     *
     * Jangan menyalin regex `base_url` di controller — aturannya ada di
     * `AgentError` (dipakai `VolumeController`/`DatabaseController` juga).
     *
     * @param array<string,mixed>|null $server
     */
    private function serverError(?array $server, \Throwable $e): string
    {
        return $server === null
            ? $e->getMessage()
            : AgentError::sanitizeForServer($e->getMessage(), $server);
    }

    /** Nama server untuk pesan (fallback: id). */
    private function serverLabel(string $serverId): string
    {
        try {
            $server = $this->serverResolver()->resolve($serverId);

            return trim((string) ($server['name'] ?? '')) !== '' ? (string) $server['name'] : $serverId;
        } catch (\Throwable) {
            return $serverId;
        }
    }

    /**
     * App pemilik satu container (nama container dari `apps.json`), atau `null`.
     *
     * @return array<string,mixed>|null
     */
    private function appOwningContainer(string $container): ?array
    {
        foreach ($this->appStore()->all() as $app) {
            foreach (($app['containers'] ?? []) as $c) {
                if (is_array($c) && (string) ($c['container_name'] ?? '') === $container) {
                    return $app;
                }
            }
        }

        return null;
    }

    /**
     * Redirect kembali ke halaman network server terpilih (mempertahankan cakupan
     * server) — opsional ke halaman detail network.
     *
     * @param array<string,mixed>|null $server
     */
    private function redirectToNetworks(?array $server, string $network = ''): \Webman\Http\Response
    {
        $query = $server === null ? '' : '?server=' . rawurlencode((string) ($server['id'] ?? ''));

        return redirect('/networks' . ($network !== '' ? '/' . rawurlencode($network) : '') . $query);
    }

    /**
     * Pilihan server untuk view (tanpa token/PEM; display only).
     *
     * Disaring `ServerAccess::visibleServers()` — non-admin **tidak** menerima
     * daftar server lengkap (hanya server yang menaungi app yang boleh ia lihat),
     * jadi menyembunyikan pemilih di view bukan lagi satu-satunya pengaman.
     *
     * @return array<int,array{id:string,name:string}>
     */
    private function serverOptions(): array
    {
        $options = [];
        try {
            $servers = ServerAccess::visibleServers(
                $this->serverResolver()->all(),
                $this->currentUser(),
                $this->appStore()->all()
            );
            foreach ($servers as $server) {
                $options[] = [
                    'id' => (string) ($server['id'] ?? ''),
                    'name' => (string) ($server['name'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            // registry tidak terbaca — halaman tetap tampil (tanpa pemilih server)
        }

        return $options;
    }

    /**
     * `protected` = seam uji (subclass dapat memeriksa penyaringan per server).
     *
     * @param string $serverId kunci server (`''` = lokal)
     * @return array<string,bool> nama project app di server itu
     */
    protected function activeProjects(string $serverId = ''): array
    {
        $names = [];
        foreach ($this->appStore()->all() as $app) {
            if ($this->serverKeyOfApp($app) !== $serverId) {
                continue; // app server lain tidak relevan untuk network server ini
            }
            $names[(string) ($app['name'] ?? '')] = true;
        }
        return $names;
    }

    /**
     * Nama project app di server itu yang boleh diakses user saat ini.
     *
     * @param string $serverId kunci server (`''` = lokal)
     * @return array<string,bool>
     */
    protected function accessibleProjects(string $serverId = ''): array
    {
        $names = [];
        foreach (AppAccess::visible($this->appStore()->all(), $this->currentUser()) as $app) {
            if ($this->serverKeyOfApp($app) !== $serverId) {
                continue;
            }
            $names[(string) ($app['name'] ?? '')] = true;
        }
        return $names;
    }

    /**
     * Shared network yang terpasang di app **server ini** yang boleh diakses user
     * (field `external_networks` di apps.json).
     *
     * Network app di server lain **diabaikan**: nama network hanya bermakna pada
     * server tempat ia dibuat (plan §5).
     *
     * @param string $serverId kunci server (`''` = lokal)
     * @return array<string,bool>
     */
    protected function sharedNetworkNames(string $serverId = ''): array
    {
        $names = [];
        foreach (AppAccess::visible($this->appStore()->all(), $this->currentUser()) as $app) {
            if ($this->serverKeyOfApp($app) !== $serverId) {
                continue;
            }
            foreach ((array) ($app['external_networks'] ?? []) as $net) {
                $net = (string) $net;
                if ($net !== '') {
                    $names[$net] = true;
                }
            }
        }
        return $names;
    }

    /**
     * Engine Docker **host ini** (jalur lokal) — seam uji (subclass dapat
     * menyuntikkan daemon tiruan / daemon yang gagal keras untuk membuktikan app
     * server agent tidak pernah menyentuhnya).
     */
    protected function docker(): DockerClient
    {
        return new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
    }
}
