<?php
declare(strict_types=1);

namespace app\library\Docker;

/**
 * Resolusi container milik sebuah app (dipakai terminal, log container, dst.).
 *
 * Nama container dari request tidak pernah dipercaya begitu saja: harus cocok
 * dengan data tersimpan di `apps.json`, atau (bila data belum sinkron) dengan
 * container nyata pada Engine API berdasarkan label project compose app.
 */
final class AppContainers
{
    /**
     * Validasi bahwa `$container` milik app ini; kembalikan nama bila valid.
     */
    public static function resolve(array $app, string $container): ?string
    {
        if ($container === '' || strpbrk($container, " \t\n\r/\\") !== false) {
            return null;
        }
        foreach (($app['containers'] ?? []) as $c) {
            if (($c['container_name'] ?? '') === $container) {
                return $container;
            }
        }

        try {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
            foreach ($docker->listContainersForProject((string) ($app['name'] ?? '')) as $live) {
                foreach (($live['Names'] ?? []) as $name) {
                    if ($name === $container || $name === '/' . $container) {
                        return $container;
                    }
                }
            }
        } catch (\Throwable $e) {
            // engine tidak tersedia — andalkan data tersimpan
        }

        return null;
    }

    /**
     * Petakan baris mentah Docker Engine (`GET /containers/json`) ke bentuk
     * container yang disimpan di dokumen app.
     *
     * Satu sumber kebenaran untuk bentuk `containers` — dipakai
     * `LocalDeployer::getContainers()` (app terkelola) dan impor project
     * compose (`ComposeImporter`), sehingga app impor tampil identik dengan app
     * yang di-deploy Rames.
     *
     * Dedupe port lewat {@see AppPorts::forContainer()} (Engine mengembalikan
     * entri per alamat IP untuk publish dual-stack).
     *
     * @param array<int,array> $raw
     * @return array<int,array{service_name:string,container_name:string,image:string,internal_port:?int,host_port:?int,ports:array<int,array{host:int,container:int}>,status:string}>
     */
    public static function fromEngineRows(array $raw): array
    {
        $containers = [];
        foreach ($raw as $c) {
            if (!is_array($c)) {
                continue;
            }

            $labels = is_array($c['Labels'] ?? null) ? $c['Labels'] : [];
            $rawPorts = [];
            foreach ((array) ($c['Ports'] ?? []) as $p) {
                if (is_array($p) && isset($p['PublicPort'], $p['PrivatePort'])) {
                    $rawPorts[] = ['host' => (int) $p['PublicPort'], 'container' => (int) $p['PrivatePort']];
                }
            }
            $ports = AppPorts::forContainer(['ports' => $rawPorts]);

            $names = $c['Names'] ?? [];
            $first = is_array($names) ? ($names[0] ?? '') : (string) ($c['Id'] ?? '');

            $containers[] = [
                'service_name' => (string) ($labels['com.docker.compose.service'] ?? 'unknown'),
                'container_name' => (string) trim((string) $first, '/'),
                'image' => (string) ($c['Image'] ?? ''),
                'internal_port' => $ports[0]['container'] ?? null,
                'host_port' => $ports[0]['host'] ?? null,
                // Semua port yang di-publish (service dengan >1 port, mis. web +
                // gateway API) — dipakai untuk memilih port yang di-proxy Nginx
                // (primary_port) dan ditampilkan di detail app.
                'ports' => $ports,
                'status' => (string) ($c['State'] ?? 'unknown'),
            ];
        }

        return $containers;
    }

    /**
     * Container default app: container service `primary_service`, atau yang
     * pertama bila primary tidak ketemu/tidak diset.
     */
    public static function defaultContainer(array $app): ?string
    {
        $containers = [];
        foreach (($app['containers'] ?? []) as $c) {
            if (is_array($c) && (string) ($c['container_name'] ?? '') !== '') {
                $containers[] = $c;
            }
        }
        if ($containers === []) {
            return null;
        }

        $primary = (string) ($app['primary_service'] ?? '');
        if ($primary !== '') {
            foreach ($containers as $c) {
                if ((string) ($c['service_name'] ?? '') === $primary) {
                    return (string) $c['container_name'];
                }
            }
        }

        return (string) $containers[0]['container_name'];
    }
}
