<?php
declare(strict_types=1);

namespace app\library\Api;

/**
 * Bentuk **publik** dokumen app untuk respons API eksternal.
 *
 * Allowlist eksplisit — hanya field yang memang untuk konsumen API. Yang
 * **tidak pernah** keluar: `env` (berisi secret app), `ssh_key`, kredensial
 * repo, `worker_pid`, `limits` internal, `members`, dan seluruh field agent
 * (token/PEM). Menambah field baru = ubah allowlist ini, bukan
 * `AppStore::all()` mentah.
 */
final class AppApiPayload
{
    /**
     * Ringkasan satu app.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    public static function summary(array $app): array
    {
        return [
            'slug' => (string) ($app['name'] ?? ''),
            'id' => (string) ($app['id'] ?? ''),
            'server_id' => (string) ($app['server_id'] ?? 'local'),
            'status' => (string) ($app['status'] ?? ''),
            'stage' => self::stringOrNull($app['stage'] ?? null),
            'message' => (string) ($app['message'] ?? ''),
            'domain' => self::domain($app),
            'created_at' => (string) ($app['created_at'] ?? ''),
            'updated_at' => (string) ($app['updated_at'] ?? ''),
            'containers' => self::containers($app['containers'] ?? []),
            'last_deploy' => self::lastDeploy($app['deploy_history'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $app
     */
    private static function domain(array $app): string
    {
        try {
            return app_subdomain_of($app);
        } catch (\Throwable) {
            // Server app hilang dari registry: jangan gagalkan pembacaan status.
            return '';
        }
    }

    /**
     * @param mixed $containers
     * @return array<int,array<string,mixed>>
     */
    private static function containers(mixed $containers): array
    {
        if (!is_array($containers)) {
            return [];
        }

        $out = [];
        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }
            $out[] = [
                'service' => (string) ($container['service_name'] ?? ''),
                'name' => (string) ($container['container_name'] ?? ''),
                'status' => (string) ($container['status'] ?? ''),
                'ports' => self::ports($container['ports'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $ports
     * @return array<int,array{host:int,container:int}>
     */
    private static function ports(mixed $ports): array
    {
        if (!is_array($ports)) {
            return [];
        }

        $out = [];
        foreach ($ports as $port) {
            if (!is_array($port)) {
                continue;
            }
            $host = $port['host'] ?? null;
            $container = $port['container'] ?? null;
            if (!is_int($host) && !is_numeric($host)) {
                continue;
            }
            if (!is_int($container) && !is_numeric($container)) {
                continue;
            }
            $out[] = ['host' => (int) $host, 'container' => (int) $container];
        }

        return $out;
    }

    /**
     * Entri deploy terakhir (bentuk allowlist, tanpa pesan galat mentah agent).
     *
     * @param mixed $history
     * @return array<string,mixed>|null
     */
    private static function lastDeploy(mixed $history): ?array
    {
        if (!is_array($history) || $history === []) {
            return null;
        }

        $last = end($history);
        if (!is_array($last)) {
            return null;
        }

        return [
            'sha' => (string) ($last['sha'] ?? ''),
            'short' => (string) ($last['short'] ?? ''),
            'action' => (string) ($last['action'] ?? ''),
            'status' => (string) ($last['status'] ?? ''),
            'message' => (string) ($last['message'] ?? ''),
            'created_at' => (string) ($last['created_at'] ?? ''),
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
