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
