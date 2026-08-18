<?php
declare(strict_types=1);

namespace app\library\Docker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use RuntimeException;

/**
 * Client ringan ke Docker Engine API via unix socket (hand-rolled, tanpa SDK).
 *
 * Dipakai untuk operasi BACA: list/inspect container, ping engine.
 * Orkestrasi (up/down/build) tetap lewat CLI docker compose — lihat
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
