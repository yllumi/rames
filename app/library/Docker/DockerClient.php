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
