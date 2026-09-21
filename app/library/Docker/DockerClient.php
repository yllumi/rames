<?php
declare(strict_types=1);

namespace app\library\Docker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use RuntimeException;

/**
 * Client ringan ke Docker Engine API via unix socket (hand-rolled, tanpa SDK).
 *
 * Dipakai untuk operasi BACA: list/inspect container, log, stats resource, ping
 * engine. Orkestrasi (up/down/build) tetap lewat CLI docker compose — lihat
 * DockerComposeRunner.
 *
 * Koneksi memakai cURL handler + CURLOPT_UNIX_SOCKET_PATH (ext-curl wajib).
 */
class DockerClient
{
    private string $socket;
    private Client $client;

    public function __construct(string $socket = '/var/run/docker.sock', int $timeout = 10)
    {
        $this->socket = $socket;
        $this->client = new Client([
            'base_uri' => 'http://docker',
            'handler' => HandlerStack::create(new CurlHandler()),
            'curl' => [CURLOPT_UNIX_SOCKET_PATH => $socket],
            'timeout' => $timeout,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);
    }

    /**
     * Cek ketersediaan Engine.
     */
    public function ping(): bool
    {
        try {
            $resp = $this->client->get('/_ping');
            return $resp->getStatusCode() === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Daftar semua container (termasuk yang berhenti) dengan filter opsional.
     *
     * @param array $filters filter Docker Engine API (mis. ['label' => ['...']])
     * @return array<int,array>
     */
    public function listContainers(array $filters = []): array
    {
        $query = ['all' => 1];
        if ($filters !== []) {
            $query['filters'] = json_encode($filters);
        }
        try {
            $resp = $this->client->get('/containers/json', ['query' => $query]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        return $this->decode($resp, 'gagal mengambil daftar container');
    }

    /**
     * Daftar container milik sebuah compose project (via label compose).
     *
     * @return array<int,array>
     */
    public function listContainersForProject(string $project): array
    {
        return $this->listContainers(['label' => ["com.docker.compose.project={$project}"]]);
    }

    /**
     * Daftar volume bernama milik sebuah compose project (via label compose).
     *
     * @return array<int,array>
     */
    public function listVolumesForProject(string $project): array
    {
        return $this->listVolumes(['label' => ["com.docker.compose.project={$project}"]]);
    }

    /**
     * Daftar volume dengan filter opsional (mis. label compose project).
     *
     * @param array $filters filter Docker Engine API (mis. ['label' => ['...']])
     * @return array<int,array>
     */
    public function listVolumes(array $filters = []): array
    {
        $query = [];
        if ($filters !== []) {
            $query['filters'] = json_encode($filters);
        }
        try {
            $resp = $this->client->get('/volumes', ['query' => $query]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        $data = $this->decode($resp, 'gagal mengambil daftar volume');
        return $data['Volumes'] ?? [];
    }

    /**
     * Ringkasan pemakaian disk Engine (`GET /system/df`) — sumber ukuran terpakai
     * volume (`Volumes[].UsageData.Size` / `RefCount`).
     *
     * Daemon lama yang belum mengenal parameter `type` (< API 1.42) akan
     * mengabaikannya dan menghitung semua tipe objek — hasil tetap valid, hanya
     * lebih lambat. Karena Engine harus menelusuri filesystem tiap volume,
     * panggilan ini bisa memakan waktu → panggil dari endpoint terpisah (AJAX)
     * dengan timeout lebih longgar, bukan saat merender halaman.
     *
     * @param array<int,string> $types container|image|volume|build-cache (kosong = semua)
     * @return array
     */
    public function getDiskUsage(array $types = ['volume']): array
    {
        // Docker mengharapkan `type` sebagai parameter berulang
        // (`type=volume&type=image`) — array Guzzle akan ter-encode jadi
        // `type[0]=...` dan diabaikan daemon (menghitung semua tipe objek).
        $query = $types === []
            ? []
            : 'type=' . implode('&type=', array_map('rawurlencode', array_values($types)));
        try {
            $resp = $this->client->get('/system/df', ['query' => $query]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        return $this->decode($resp, 'gagal menghitung pemakaian disk');
    }

    /**
     * Log container (`GET /containers/{id}/logs`, non-streaming).
     *
     * Container tanpa TTY mengembalikan stream **multiplexed** (tiap frame
     * ber-header 8 byte) → hasil mentah dikembalikan apa adanya; bersihkan
     * dengan `ContainerLogs::demultiplex()`.
     *
     * @param int  $tail       jumlah baris terakhir yang diambil
     * @param bool $timestamps sertakan timestamp per baris
     */
    public function containerLogs(string $id, int $tail = 200, bool $timestamps = true): string
    {
        $query = [
            'stdout' => 1,
            'stderr' => 1,
            'tail' => max(1, $tail),
            'timestamps' => $timestamps ? 1 : 0,
        ];
        try {
            $resp = $this->client->get('/containers/' . rawurlencode($id) . '/logs', ['query' => $query]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        if ($resp->getStatusCode() >= 300) {
            throw new RuntimeException(
                'Gagal mengambil log container: HTTP ' . $resp->getStatusCode() . ' ' . $resp->getReasonPhrase()
            );
        }
        return (string) $resp->getBody();
    }

    /**
     * Detail container per ID.
     *
     * @return array
     */
    public function inspectContainer(string $id): array
    {
        try {
            $resp = $this->client->get('/containers/' . rawurlencode($id) . '/json');
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        return $this->decode($resp, 'gagal inspect container');
    }

    /**
     * Statistik (`/stats`) + inspect (`/json`) BANYAK container sekaligus,
     * dijalankan paralel — sumber data monitoring resource (SPECS §8d).
     *
     * Alasan paralel: `/stats?stream=false` memblokir ±1 detik per container
     * (daemon harus mengambil dua sampel CPU) sedangkan handler default
     * (`CurlHandler`) serial. Untuk 10 container itu ±10 detik. Fan-out di bawah
     * memakai `CurlMultiHandler` sehingga semua transfer berjalan bersamaan
     * (total ≈ satu siklus sampling).
     *
     * Kegagalan satu container tidak menggagalkan yang lain: hasil per container
     * memuat `error` sehingga satu container bermasalah (mis. sedang berhenti)
     * tidak mengosongkan seluruh halaman monitoring.
     *
     * @param array<int,string> $ids ID container
     * @return array<string,array{stats:?array,inspect:?array,error:?string}>
     */
    public function containersOverview(array $ids, int $timeout = 20): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('strval', $ids),
            static fn (string $id): bool => $id !== ''
        )));
        if ($ids === []) {
            return [];
        }

        $results = [];
        $promises = [];
        $client = new Client([
            'base_uri' => 'http://docker',
            'handler' => new CurlMultiHandler(),
            'curl' => [CURLOPT_UNIX_SOCKET_PATH => $this->socket],
            'timeout' => $timeout,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);

        foreach ($ids as $id) {
            $results[$id] = ['stats' => null, 'inspect' => null, 'error' => null];
            $base = '/containers/' . rawurlencode($id);
            // kunci promise: "<id>|<jenis>" agar hasilnya bisa dipetakan kembali
            $promises[$id . '|stats'] = $client->getAsync($base . '/stats', ['query' => ['stream' => 0]]);
            $promises[$id . '|inspect'] = $client->getAsync($base . '/json');
        }

        // settle() tidak pernah melempar — tiap hasil dibaca per container
        foreach (Utils::settle($promises)->wait() as $key => $outcome) {
            [$id, $kind] = array_pad(explode('|', (string) $key, 2), 2, '');
            if (!isset($results[$id]) || !in_array($kind, ['stats', 'inspect'], true)) {
                continue;
            }

            if (($outcome['state'] ?? '') !== 'fulfilled') {
                $reason = $outcome['reason'] ?? null;
                $results[$id]['error'] = $reason instanceof \Throwable
                    ? $reason->getMessage()
                    : 'gagal terhubung ke Docker Engine';
                continue;
            }

            try {
                $results[$id][$kind] = $this->decode(
                    $outcome['value'],
                    $kind === 'stats' ? 'gagal mengambil statistik container' : 'gagal inspect container'
                );
            } catch (\Throwable $e) {
                $results[$id]['error'] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Stop container (graceful). 404 dianggap sukses (container sudah hilang).
     */
    public function stopContainer(string $id): void
    {
        $this->requestIgnore404('POST', '/containers/' . rawurlencode($id) . '/stop');
    }

    /**
     * Hapus container (force). 404 dianggap sukses (container sudah hilang).
     */
    public function removeContainer(string $id, bool $force = true, bool $removeVolumes = true): void
    {
        $query = ['force' => $force ? 1 : 0, 'v' => $removeVolumes ? 1 : 0];
        $this->requestIgnore404('DELETE', '/containers/' . rawurlencode($id), $query);
    }

    /**
     * Daftar network dengan filter opsional (mis. label compose project).
     *
     * Catatan: `GET /networks` mengembalikan ARRAY JSON polos (daftar network),
     * berbeda dengan `/volumes` yang membungkus dalam kunci `Volumes`.
     *
     * @param array $filters filter Docker Engine API (mis. ['label' => ['...']])
     * @return array<int,array>
     */
    public function listNetworks(array $filters = []): array
    {
        $query = [];
        if ($filters !== []) {
            $query['filters'] = json_encode($filters);
        }
        try {
            $resp = $this->client->get('/networks', ['query' => $query]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        return $this->decode($resp, 'gagal mengambil daftar network');
    }

    /**
     * Daftar network milik sebuah compose project (via label compose).
     *
     * @return array<int,array>
     */
    public function listNetworksForProject(string $project): array
    {
        return $this->listNetworks(['label' => ["com.docker.compose.project={$project}"]]);
    }

    /**
     * Detail network (termasuk daftar container yang terhubung).
     *
     * @return array
     */
    public function inspectNetwork(string $id): array
    {
        try {
            $resp = $this->client->get('/networks/' . rawurlencode($id));
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        return $this->decode($resp, 'gagal inspect network');
    }

    /**
     * Buat network baru (POST /networks/create).
     *
     * @param array $config {Name, Driver, Options, IPAM, Labels, Internal,
     *                      Attachable, EnableIPv6, CheckDuplicate}
     * @return string Id network baru
     */
    public function createNetwork(array $config): string
    {
        try {
            $resp = $this->client->post('/networks/create', ['json' => $config]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        if ($resp->getStatusCode() >= 300) {
            $msg = trim((string) $resp->getBody());
            throw new RuntimeException(
                'Gagal membuat network: HTTP ' . $resp->getStatusCode() . ' ' .
                ($msg !== '' ? $msg : $resp->getReasonPhrase())
            );
        }
        $data = json_decode((string) $resp->getBody(), true);
        return (string) (is_array($data) ? ($data['Id'] ?? '') : '');
    }

    /**
     * Hubungkan container ke network. 404 dianggap sukses (resource hilang).
     *
     * @param array $endpointConfig mis. ['Aliases' => ['svc'], 'IPAMConfig' => ['IPv4Address' => '...']]
     */
    public function connectContainerToNetwork(string $networkId, string $containerId, array $endpointConfig = []): void
    {
        $body = ['Container' => $containerId];
        if ($endpointConfig !== []) {
            $body['EndpointConfig'] = $endpointConfig;
        }
        $this->requestJson('POST', '/networks/' . rawurlencode($networkId) . '/connect', $body, true);
    }

    /**
     * Putuskan container dari network. 404 dianggap sukses (resource hilang).
     */
    public function disconnectContainerFromNetwork(string $networkId, string $containerId, bool $force = false): void
    {
        $this->requestJson('POST', '/networks/' . rawurlencode($networkId) . '/disconnect', [
            'Container' => $containerId,
            'Force' => $force,
        ], true);
    }

    /**
     * Hapus network. 404 dianggap sukses (network sudah hilang).
     */
    public function removeNetwork(string $id): void
    {
        $this->requestIgnore404('DELETE', '/networks/' . rawurlencode($id));
    }

    /**
     * Request API dengan body JSON (POST/PUT/DELETE). 404 opsional dianggap
     * sukses (resource sudah tidak ada). Status >= 300 selain itu dianggap error
     * dengan pesan body (bila ada).
     */
    private function requestJson(string $method, string $path, array $body = [], bool $ignore404 = false): void
    {
        try {
            $resp = $this->client->request(strtoupper($method), $path, ['json' => $body]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        $code = $resp->getStatusCode();
        if ($code >= 300 && !($ignore404 && $code === 404)) {
            $msg = trim((string) $resp->getBody());
            throw new RuntimeException(
                'Operasi Docker gagal: HTTP ' . $code . ' ' .
                ($msg !== '' ? $msg : $resp->getReasonPhrase())
            );
        }
    }

    /**
     * Request API yang toleran 404 (resource sudah tidak ada = sukses).
     * Status >= 300 selain 404 dianggap error.
     */
    private function requestIgnore404(string $method, string $path, array $query = []): void
    {
        try {
            $resp = $this->client->request(strtoupper($method), $path, ['query' => $query]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        $code = $resp->getStatusCode();
        if ($code >= 300 && $code !== 404) {
            throw new RuntimeException("Operasi Docker gagal: HTTP {$code} {$resp->getReasonPhrase()}");
        }
    }

    private function decode($resp, string $errMsg): array
    {
        if ($resp->getStatusCode() >= 300) {
            throw new RuntimeException("{$errMsg}: HTTP {$resp->getStatusCode()} {$resp->getReasonPhrase()}");
        }
        $data = json_decode((string) $resp->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException($errMsg . ': respons tidak valid');
        }
        return $data;
    }
}
