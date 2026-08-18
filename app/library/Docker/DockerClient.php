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
     * Daftar container milik sebuah compose project (via label compose).
     *
     * @return array<int,array>
     */
    public function listContainersForProject(string $project): array
    {
        $filters = json_encode(['label' => ["com.docker.compose.project={$project}"]]);
        try {
            $resp = $this->client->get('/containers/json', [
                'query' => ['all' => 1, 'filters' => $filters],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        return $this->decode($resp, 'gagal mengambil daftar container');
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
     * Daftar network milik sebuah compose project (via label compose).
     *
     * @return array<int,array>
     */
    public function listNetworksForProject(string $project): array
    {
        $filters = json_encode(['label' => ["com.docker.compose.project={$project}"]]);
        try {
            $resp = $this->client->get('/networks', ['query' => ['filters' => $filters]]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gagal terhubung ke Docker Engine: ' . $e->getMessage(), 0, $e);
        }
        $data = $this->decode($resp, 'gagal mengambil daftar network');
        return $data['Networks'] ?? [];
    }

    /**
     * Hapus network. 404 dianggap sukses (network sudah hilang).
     */
    public function removeNetwork(string $id): void
    {
        $this->requestIgnore404('DELETE', '/networks/' . rawurlencode($id));
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
