<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Halaman Network — mengelola network Docker host (Engine API via DockerClient).
 *
 * Controller hanya mediator — tanpa state di properti (Webman persistent).
 *
 * Proteksi hapus network (berlapis, di controller BUKAN hanya di UI):
 *  - Network built-in/system (bridge, host, none, ingress, docker_gwbridge) → blok.
 *  - Network yang masih dipakai container → blok (Engine juga menolak dengan 409).
 *  - Network compose milik app yang masih ada di apps.json → blok
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
        $docker = $this->docker();
        $engineError = null;
        $rows = [];

        try {
            $activeProjects = $this->activeProjects();
            foreach ($docker->listNetworks() as $n) {
                $name = (string) ($n['Name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $project = (string) (($n['Labels'] ?? [])['com.docker.compose.project'] ?? '');
                $count = $this->containerCount($docker, $n);
                $builtin = in_array($name, self::PROTECTED_NAMES, true);
                $managed = $project !== '' && isset($activeProjects[$project]);

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
                    'can_delete' => !$builtin && !$managed && $count === 0,
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
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage();
        }

        return view('network/index', [
            'rows' => $rows,
            'engineError' => $engineError,
        ]);
    }

    /**
     * Buat network baru (POST /networks/create).
     */
    public function create(Request $request)
    {
        $name = strtolower(trim((string) $request->post('name', '')));
        $driver = (string) $request->post('driver', 'bridge');
        $subnet = trim((string) $request->post('subnet', ''));
        $gateway = trim((string) $request->post('gateway', ''));
        $ipRange = trim((string) $request->post('ip_range', ''));
        $internal = (string) $request->post('internal', '') === '1';
        $attachable = (string) $request->post('attachable', '') === '1';

        if ($name === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
            flash_set('error', 'Nama network tidak valid. Gunakan huruf/angka, titik, underscore, atau dash (diawali huruf/angka).');
            return redirect('/networks');
        }
        $allowedDrivers = ['bridge', 'overlay', 'macvlan'];
        if (!in_array($driver, $allowedDrivers, true)) {
            $driver = 'bridge';
        }

        // Validasi ringan format IPAM (Engine melakukan validasi final).
        if ($subnet !== '' && !preg_match('#^[0-9a-fA-F:.]+/\d+$#', $subnet)) {
            flash_set('error', 'Subnet harus berupa CIDR, mis. 172.20.0.0/16.');
            return redirect('/networks');
        }
        foreach (['gateway' => $gateway, 'ip_range' => $ipRange] as $label => $val) {
            if ($val !== '' && !preg_match('#^[0-9a-fA-F:.]+$#', $val)) {
                flash_set('error', ucfirst($label) . ' harus berupa alamat IP, mis. 172.20.0.1.');
                return redirect('/networks');
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
            $this->docker()->createNetwork($config);
            flash_set('success', "Network \"{$name}\" dibuat.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/networks');
    }

    /**
     * Detail network: info + container terhubung + form connect.
     */
    public function detail(Request $request, string $id)
    {
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
                flash_set('error', 'Network tidak ditemukan.');
                return redirect('/networks');
            }
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage();
        }

        $name = $network !== null ? (string) ($network['Name'] ?? $id) : $id;

        return view('network/detail', [
            'id' => $id,
            'name' => $name,
            'network' => $network,
            'attached' => $attached,
            'candidates' => $candidates,
            'engineError' => $engineError,
            'builtin' => in_array($name, self::PROTECTED_NAMES, true),
        ]);
    }

    /**
     * Hubungkan container ke network (POST /networks/{id}/connect).
     */
    public function connect(Request $request, string $id)
    {
        $container = trim((string) $request->post('container', ''));
        $alias = trim((string) $request->post('alias', ''));

        if ($container === '') {
            flash_set('error', 'Pilih container untuk dihubungkan.');
            return redirect('/networks/' . rawurlencode($id));
        }
        $endpointConfig = [];
        if ($alias !== '') {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $alias)) {
                flash_set('error', 'Alias tidak valid. Gunakan huruf/angka, titik, underscore, atau dash.');
                return redirect('/networks/' . rawurlencode($id));
            }
            $endpointConfig['Aliases'] = [$alias];
        }

        try {
            $this->docker()->connectContainerToNetwork($id, $container, $endpointConfig);
            flash_set('success', "Container \"{$container}\" dihubungkan ke \"{$id}\".");
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal menghubungkan container: ' . $e->getMessage());
        }
        return redirect('/networks/' . rawurlencode($id));
    }

    /**
     * Putuskan container dari network (POST /networks/{id}/disconnect).
     */
    public function disconnect(Request $request, string $id)
    {
        $container = trim((string) $request->post('container', ''));
        if ($container === '') {
            flash_set('error', 'Container tidak valid.');
            return redirect('/networks/' . rawurlencode($id));
        }

        try {
            $this->docker()->disconnectContainerFromNetwork($id, $container, false);
            flash_set('success', "Container \"{$container}\" diputus dari \"{$id}\".");
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal memutus container: ' . $e->getMessage());
        }
        return redirect('/networks/' . rawurlencode($id));
    }

    /**
     * Hapus network dengan proteksi berlapis (POST /networks/{id}/delete).
     */
    public function delete(Request $request, string $id)
    {
        $docker = $this->docker();
        $activeProjects = $this->activeProjects();

        try {
            $networks = $docker->listNetworks();
        } catch (\Throwable $e) {
            flash_set('error', 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage());
            return redirect('/networks');
        }

        $target = null;
        foreach ($networks as $n) {
            if ((string) ($n['Name'] ?? '') === $id) {
                $target = $n;
                break;
            }
        }
        if ($target === null) {
            flash_set('error', "Network \"{$id}\" tidak ditemukan (mungkin sudah dihapus).");
            return redirect('/networks');
        }

        $name = (string) ($target['Name'] ?? $id);
        if (in_array($name, self::PROTECTED_NAMES, true)) {
            flash_set('error', "Network built-in \"{$name}\" tidak bisa dihapus.");
            return redirect('/networks');
        }

        $project = (string) (($target['Labels'] ?? [])['com.docker.compose.project'] ?? '');
        if ($project !== '' && isset($activeProjects[$project])) {
            flash_set('error', "Network \"{$name}\" dikelola app \"{$project}\" (compose) — kelola lewat halaman app, bukan di sini.");
            return redirect('/networks');
        }

        $count = $this->containerCount($docker, $target);
        if ($count > 0) {
            flash_set('error', "Network \"{$name}\" masih dipakai {$count} container. Hentikan/putuskan container dulu sebelum menghapus.");
            return redirect('/networks');
        }

        try {
            $docker->removeNetwork($id);
            flash_set('success', "Network \"{$name}\" dihapus.");
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal menghapus network: ' . $e->getMessage());
        }
        return redirect('/networks');
    }

    /**
     * Jumlah container yang terhubung ke sebuah network. Memakai field Containers
     * hasil list bila ada; fallback ke inspect untuk API yang tidak menyertakannya.
     */
    private function containerCount(DockerClient $docker, array $network): int
    {
        $containers = $network['Containers'] ?? null;
        if (is_array($containers)) {
            return count($containers);
        }
        if (is_int($containers)) {
            return $containers;
        }
        try {
            $detail = $docker->inspectNetwork((string) ($network['Id'] ?? ''));
            $c = $detail['Containers'] ?? [];
            return is_array($c) ? count($c) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string,bool> nama project app yang masih ada di apps.json
     */
    private function activeProjects(): array
    {
        $names = [];
        foreach ((new AppStore())->all() as $app) {
            $names[(string) ($app['name'] ?? '')] = true;
        }
        return $names;
    }

    private function docker(): DockerClient
    {
        return new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
    }
}
