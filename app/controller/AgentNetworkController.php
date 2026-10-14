<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Docker\DockerClient;
use support\Request;
use Webman\Http\Response;

/**
 * Network Docker **sisi agent** (F3c multi-server, plan §4.1/§5):
 *
 * ```
 * GET  /networks                    → {code:0,data:{networks}}       (Engine server ini)
 * GET  /networks/{id}               → {code:0,data:{network,attached,candidates}}
 * GET  /volumes?project={name}      → {code:0,data:{volumes}}        (Engine server ini)
 * POST /networks/create             → {code:0}
 * POST /networks/{id}/connect       → {code:0}
 * POST /networks/{id}/disconnect    → {code:0}
 * POST /networks/{id}/delete        → {code:0}
 * ```
 *
 * Dipanggil control plane (`NetworkController` ⇒ `AgentDeployer`), bukan UI:
 * route ini hanya terdaftar di mode `agent` dan otomatis tertutup
 * `AgentAuthMiddleware` (token + timestamp + nonce + signature).
 *
 * Aturan yang dijaga kelas ini: **setiap operasi Engine dijalankan di server
 * ini** — tidak ada jalur di mana network host control plane dibaca/diubah untuk
 * app remote (plan §3 butir 4).
 *
 * Pembagian tanggung jawab (sengaja):
 *  - **control plane** memegang `AppStore` + `AppAccess` + identitas user, jadi
 *    otorisasi (network built-in/dikelola app/masih dipakai, filter visibilitas,
 *    larangan lintas server) diputuskan di sana;
 *  - **agent** hanya mengeksekusi operasi Engine dengan validasi bentuk input
 *    (nama/driver/id), supaya agent yang dibobol tidak menyediakan lebih dari
 *    apa yang sudah dimiliki control plane.
 *
 * Controller hanya mediator: seluruh I/O di `DockerClient`. Stateless: properti
 * hanya seam uji (nullable).
 */
class AgentNetworkController
{
    /** Network built-in/system yang tidak pernah boleh dibuat/dihapus di sini. */
    public const PROTECTED_NAMES = ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'];

    /** Pola nama network yang boleh dibuat (anti injeksi argv/Engine). */
    private const NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/';

    /** Pola id/nama network yang boleh masuk URL (anti path traversal). */
    private const REF_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/';

    private ?DockerClient $docker;

    /**
     * @param DockerClient|null $docker Engine API server ini (seam uji; null ⇒ bawaan)
     */
    public function __construct(?DockerClient $docker = null)
    {
        $this->docker = $docker;
    }

    // ==================================================================
    // Baca
    // ==================================================================

    /** Daftar seluruh network Engine **server ini** (apa adanya, untuk control plane). */
    public function index(Request $request): Response
    {
        try {
            $networks = $this->dockerClient()->listNetworks();
        } catch (\Throwable $e) {
            return $this->error('Gagal membaca network di server agent: ' . $e->getMessage(), 'docker_failed', 502);
        }

        return json(['code' => 0, 'data' => ['networks' => array_values($networks)]]);
    }

    /**
     * Daftar nama named volume milik project compose **server ini**
     * (`GET /volumes?project=…`) — dipakai control plane untuk modal konfirmasi
     * delete app di server agent (plan §5).
     *
     * Filter label `com.docker.compose.project` dijalankan Engine server ini,
     * jadi hasilnya sama dengan `LocalDeployer::getProjectVolumes()` di jalur
     * lokal. Nama project divalidasi bentuk (anti injeksi ke filter Engine).
     */
    public function volumes(Request $request): Response
    {
        $project = trim((string) $request->get('project', ''));
        if ($project === '' || preg_match(self::NAME_PATTERN, $project) !== 1) {
            return $this->error('Nama project tidak valid.', 'invalid_project', 400);
        }

        try {
            $volumes = $this->dockerClient()->listVolumesForProject($project);
        } catch (\Throwable $e) {
            return $this->error('Gagal membaca volume project di server agent: ' . $e->getMessage(), 'docker_failed', 502);
        }

        $names = [];
        foreach ($volumes as $volume) {
            $name = is_array($volume) ? (string) ($volume['Name'] ?? '') : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return json(['code' => 0, 'data' => ['volumes' => $names]]);
    }

    /** Detail satu network + container terhubung + kandidat container. */
    public function detail(Request $request, string $id): Response
    {
        $ref = $this->validRef($id);
        if ($ref === null) {
            return $this->error('Id network tidak valid.', 'invalid_network', 400);
        }

        try {
            $docker = $this->dockerClient();
            $network = $docker->inspectNetwork($ref);

            $attached = [];
            $attachedIds = [];
            foreach ((array) ($network['Containers'] ?? []) as $ep) {
                $name = ltrim((string) (($ep['Name'] ?? '')), '/');
                if ($name === '') {
                    continue;
                }
                $attached[] = [
                    'name' => $name,
                    'ipv4' => (string) ($ep['IPv4Address'] ?? ''),
                    'ipv6' => (string) ($ep['IPv6Address'] ?? ''),
                    'mac' => (string) ($ep['MacAddress'] ?? ''),
                ];
                $attachedIds[$name] = true;
            }
            usort($attached, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

            $candidates = [];
            foreach ($docker->listContainers() as $container) {
                $names = $container['Names'] ?? [];
                $name = '';
                foreach (is_array($names) ? $names : [] as $candidate) {
                    $name = ltrim((string) $candidate, '/');
                    if ($name !== '') {
                        break;
                    }
                }
                if ($name === '' || isset($attachedIds[$name])) {
                    continue;
                }
                $candidates[] = [
                    'name' => $name,
                    'image' => (string) ($container['Image'] ?? ''),
                    'status' => (string) ($container['State'] ?? ''),
                ];
            }
            usort($candidates, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        } catch (\Throwable $e) {
            return $this->error('Gagal membaca detail network di server agent: ' . $e->getMessage(), 'docker_failed', 502);
        }

        return json(['code' => 0, 'data' => [
            'network' => $network,
            'attached' => $attached,
            'candidates' => $candidates,
        ]]);
    }

    // ==================================================================
    // Mutasi
    // ==================================================================

    /**
     * Buat network baru di server ini. `config` sudah divalidasi control plane;
     * di sini hanya bentuk inti yang diperiksa ulang (pertahanan berlapis).
     */
    public function create(Request $request): Response
    {
        $input = (array) $request->post('config', []);
        $name = trim((string) ($input['Name'] ?? ''));
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return $this->error('Nama network tidak valid.', 'invalid_name', 400);
        }

        $config = [
            'Name' => $name,
            'Driver' => (string) ($input['Driver'] ?? 'bridge'),
            'Internal' => (bool) ($input['Internal'] ?? false),
            'Attachable' => (bool) ($input['Attachable'] ?? false),
            'CheckDuplicate' => true,
        ];
        if (is_array($input['IPAM'] ?? null)) {
            $config['IPAM'] = $input['IPAM'];
        }
        $labels = $input['Labels'] ?? null;
        if (is_array($labels) && $labels !== []) {
            $config['Labels'] = array_map('strval', $labels);
        }

        try {
            $this->dockerClient()->createNetwork($config);
        } catch (\Throwable $e) {
            return $this->error('Gagal membuat network di server agent: ' . $e->getMessage(), 'docker_failed', 502);
        }

        return json(['code' => 0]);
    }

    /** Hubungkan container ke network (server ini). */
    public function connect(Request $request, string $id): Response
    {
        $ref = $this->validRef($id);
        $container = trim((string) $request->post('container', ''));
        if ($ref === null) {
            return $this->error('Id network tidak valid.', 'invalid_network', 400);
        }
        if ($container === '' || preg_match(self::NAME_PATTERN, $container) !== 1) {
            return $this->error('Nama container tidak valid.', 'invalid_container', 400);
        }

        $endpointConfig = [];
        $alias = trim((string) $request->post('alias', ''));
        if ($alias !== '') {
            if (preg_match(self::NAME_PATTERN, $alias) !== 1) {
                return $this->error('Alias tidak valid.', 'invalid_alias', 400);
            }
            $endpointConfig['Aliases'] = [$alias];
        }

        try {
            $this->dockerClient()->connectContainerToNetwork($ref, $container, $endpointConfig);
        } catch (\Throwable $e) {
            return $this->error('Gagal menghubungkan container: ' . $e->getMessage(), 'docker_failed', 502);
        }

        return json(['code' => 0]);
    }

    /** Putuskan container dari network (server ini). */
    public function disconnect(Request $request, string $id): Response
    {
        $ref = $this->validRef($id);
        $container = trim((string) $request->post('container', ''));
        if ($ref === null) {
            return $this->error('Id network tidak valid.', 'invalid_network', 400);
        }
        if ($container === '' || preg_match(self::NAME_PATTERN, $container) !== 1) {
            return $this->error('Nama container tidak valid.', 'invalid_container', 400);
        }

        try {
            $this->dockerClient()->disconnectContainerFromNetwork($ref, $container, false);
        } catch (\Throwable $e) {
            return $this->error('Gagal memutus container: ' . $e->getMessage(), 'docker_failed', 502);
        }

        return json(['code' => 0]);
    }

    /**
     * Hapus network (server ini). Proteksi berlapis (built-in, dikelola app,
     * masih dipakai) **tidak** diduplikasi di sini — pemiliknya control plane
     * yang memegang `AppStore`; agent menolak built-in sebagai jaring pengaman.
     */
    public function delete(Request $request, string $id): Response
    {
        $ref = $this->validRef($id);
        if ($ref === null) {
            return $this->error('Id network tidak valid.', 'invalid_network', 400);
        }
        if (in_array(strtolower($ref), self::PROTECTED_NAMES, true)) {
            return $this->error('Network built-in tidak bisa dihapus.', 'protected_network', 400);
        }

        try {
            $this->dockerClient()->removeNetwork($ref);
        } catch (\Throwable $e) {
            return $this->error('Gagal menghapus network: ' . $e->getMessage(), 'docker_failed', 502);
        }

        return json(['code' => 0]);
    }

    // ==================================================================
    // Helper internal
    // ==================================================================

    private function validRef(string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || preg_match(self::REF_PATTERN, $ref) !== 1) {
            return null;
        }

        return $ref;
    }

    /** Respons galat seragam (`code:1`, pola `AgentLogController::error()`). */
    private function error(string $message, string $reason, int $status): Response
    {
        return json(['code' => 1, 'error' => $message, 'reason' => $reason])->withStatus($status);
    }

    /** Engine API server ini (bukan host control plane) — seam uji. */
    private function dockerClient(): DockerClient
    {
        return $this->docker ?? new DockerClient(
            (string) config('deploy.docker_socket', '/var/run/docker.sock'),
            20
        );
    }
}
