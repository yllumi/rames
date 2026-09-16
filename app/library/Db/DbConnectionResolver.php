<?php
declare(strict_types=1);

namespace app\library\Db;

use app\library\Docker\DockerClient;
use RuntimeException;

/**
 * Resolve host & port agar dashboard (container) bisa menjangkau MySQL/MariaDB
 * di dalam container lain lewat TCP (koneksi PDO).
 *
 * Dashboard dan container DB umumnya berada di bridge network Docker yang
 * BERBEDA → tidak saling ter-route secara default. Strategi:
 *   1. Bila container mem-publish host port → konek via `{host-gateway}:{host-port}`.
 *   2. Bila tidak → dashboard menautkan dirinya (idempoten) ke network compose
 *      container tersebut (DockerClient::connectContainerToNetwork), lalu konek
 *      via IP container di network itu.
 */
class DbConnectionResolver
{
    private DockerClient $docker;
    private string $selfContainer;

    public function __construct(?DockerClient $docker = null, ?string $selfContainer = null)
    {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
        $this->selfContainer = $selfContainer ?? (string) config('deploy.dashboard_container', 'rames-webman');
    }

    /**
     * @return array{host:string, port:int}
     */
    public function resolveHostPort(array $inspect): array
    {
        $internalPort = $this->internalPort($inspect);
        $published = $this->publishedPort($inspect);
        if ($published !== null) {
            $hostIp = (string) $published['hostIp'];
            $host = ($hostIp === '' || $hostIp === '0.0.0.0' || str_contains($hostIp, '::'))
                ? $this->hostGateway()
                : $hostIp;
            return ['host' => $host, 'port' => (int) $published['hostPort']];
        }

        $networks = $inspect['NetworkSettings']['Networks'] ?? [];
        if (!is_array($networks) || $networks === []) {
            throw new RuntimeException('Container tidak memiliki network dan tidak mem-publish port — tidak dapat diakses via TCP.');
        }

        $networkName = null;
        foreach (array_keys($networks) as $name) {
            if (strtolower((string) $name) === 'host') {
                continue;
            }
            $networkName = (string) $name;
            break;
        }
        if ($networkName === null) {
            throw new RuntimeException('Container memakai network "host" tanpa port terpublish — koneksi PDO tidak memungkinkan.');
        }

        $ip = (string) ($networks[$networkName]['IPAddress'] ?? '');
        if ($ip === '') {
            throw new RuntimeException('Tidak dapat menentukan alamat IP container.');
        }

        $this->ensureSelfConnected($networkName);
        return ['host' => $ip, 'port' => $internalPort];
    }

    /**
     * Port host terpublish (bila ada), diutamakan yang cocok dengan port internal.
     *
     * @return array{hostIp:string, hostPort:int}|null
     */
    private function publishedPort(array $inspect): ?array
    {
        $ports = $inspect['NetworkSettings']['Ports'] ?? [];
        if (!is_array($ports)) {
            return null;
        }
        $internalPort = $this->internalPort($inspect);
        $fallback = null;
        foreach ($ports as $containerPort => $bindings) {
            if (!is_array($bindings) || $bindings === []) {
                continue;
            }
            $portNum = (int) explode('/', (string) $containerPort)[0];
            $first = $bindings[0] ?? null;
            if ($first === null) {
                continue;
            }
            // Binding ke loopback (127.0.0.1/::1/localhost) tidak dapat dijangkau
            // dari container dashboard — lewati (fallback ke network connect).
            $hostIp = (string) ($first['HostIp'] ?? '');
            $loopback = in_array($hostIp, ['127.0.0.1', '::1', 'localhost'], true);
            if ($portNum === $internalPort && !$loopback) {
                return ['hostIp' => $hostIp, 'hostPort' => (int) ($first['HostPort'] ?? 0)];
            }
            if ($fallback === null && !$loopback) {
                $fallback = ['hostIp' => $hostIp, 'hostPort' => (int) ($first['HostPort'] ?? 0)];
            }
        }
        return $fallback;
    }

    /**
     * Port internal server DB (3306 default; atau dari env MYSQL/MARIADB_TCP_PORT).
     * Dipakai juga saat dump/restore dijalankan DI DALAM container (127.0.0.1).
     */
    public function internalPort(array $inspect): int
    {
        $env = $this->envMap($inspect);
        foreach (['MYSQL_TCP_PORT', 'MARIADB_TCP_PORT'] as $key) {
            if (isset($env[$key]) && (int) $env[$key] > 0) {
                return (int) $env[$key];
            }
        }
        $exposed = $inspect['Config']['ExposedPorts'] ?? [];
        foreach (array_keys(is_array($exposed) ? $exposed : []) as $containerPort) {
            if (preg_match('#^(\d+)/(tcp|udp)$#', (string) $containerPort, $m)) {
                $port = (int) $m[1];
                if ($port === 3306) {
                    return 3306;
                }
                return $port;
            }
        }
        return 3306;
    }

    /**
     * Gateway IP network dashboard sendiri (target host port terpublish).
     */
    private function hostGateway(): string
    {
        try {
            $self = $this->docker->inspectContainer($this->selfContainer);
            foreach (($self['NetworkSettings']['Networks'] ?? []) as $config) {
                $gateway = (string) ($config['Gateway'] ?? '');
                if ($gateway !== '') {
                    return $gateway;
                }
            }
        } catch (\Throwable $e) {
            // fallthrough ke default
        }
        return '172.17.0.1';
    }

    /**
     * Pastikan container dashboard terhubung ke network target (idempoten).
     */
    private function ensureSelfConnected(string $networkName): void
    {
        try {
            $self = $this->docker->inspectContainer($this->selfContainer);
            $networks = $self['NetworkSettings']['Networks'] ?? [];
            if (is_array($networks) && array_key_exists($networkName, $networks)) {
                return; // sudah terhubung
            }
        } catch (\Throwable $e) {
            // inspect gagal — tetap coba connect (idempoten di sisi Engine)
        }
        $this->docker->connectContainerToNetwork($networkName, $this->selfContainer);
    }

    /**
     * @return array<string,string>
     */
    private function envMap(array $inspect): array
    {
        $env = [];
        foreach (($inspect['Config']['Env'] ?? []) as $line) {
            $eq = strpos((string) $line, '=');
            if ($eq === false) {
                continue;
            }
            $env[substr((string) $line, 0, $eq)] = substr((string) $line, $eq + 1);
        }
        return $env;
    }
}
