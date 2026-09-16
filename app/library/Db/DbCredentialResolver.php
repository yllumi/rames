<?php
declare(strict_types=1);

namespace app\library\Db;

/**
 * Resolve kredensial MySQL/MariaDB secara otomatis dari environment container
 * (Config.Env hasil inspect) dan environment app (apps.json "env").
 *
 * Prioritas:
 *   1. User aplikasi (MYSQL_USER / MARIADB_USER / DB_USERNAME / DB_USER) — least privilege.
 *   2. Root (MYSQL_ROOT_PASSWORD / MARIADB_ROOT_PASSWORD / MYSQL_PASSWORD / MARIADB_PASSWORD).
 *
 * Nilai env dari apps.json (yang dikelola dashboard) lebih diutamakan daripada
 * env container, karena itulah nilai final yang benar-benar di-inject ke service.
 */
class DbCredentialResolver
{
    /**
     * @return array{username:string, password:string, database:?string, detected:bool}|null
     */
    public function resolve(array $app, array $containerInspect): ?array
    {
        $env = $this->mergeEnv($app, $containerInspect);

        $pairs = [
            ['MYSQL_USER', 'MYSQL_PASSWORD'],
            ['MARIADB_USER', 'MARIADB_PASSWORD'],
            ['DB_USERNAME', 'DB_PASSWORD'],
            ['DB_USER', 'DB_PASSWORD'],
        ];
        foreach ($pairs as [$userKey, $passKey]) {
            if ($this->has($env, $userKey) && $this->has($env, $passKey)) {
                return [
                    'username' => (string) $env[$userKey],
                    'password' => (string) $env[$passKey],
                    'database' => $this->firstDatabase($env),
                    'detected' => true,
                ];
            }
        }

        foreach (['MYSQL_ROOT_PASSWORD', 'MARIADB_ROOT_PASSWORD', 'MYSQL_PASSWORD', 'MARIADB_PASSWORD'] as $key) {
            if ($this->has($env, $key)) {
                return [
                    'username' => 'root',
                    'password' => (string) $env[$key],
                    'database' => $this->firstDatabase($env),
                    'detected' => true,
                ];
            }
        }

        return null;
    }

    private function has(array $env, string $key): bool
    {
        return isset($env[$key]) && $env[$key] !== '';
    }

    private function firstDatabase(array $env): ?string
    {
        foreach (['MYSQL_DATABASE', 'MARIADB_DATABASE', 'DB_DATABASE', 'DB_NAME'] as $key) {
            if ($this->has($env, $key)) {
                return (string) $env[$key];
            }
        }
        return null;
    }

    /**
     * Gabungkan env apps.json (diutamakan) dengan env container.
     *
     * @return array<string,string>
     */
    private function mergeEnv(array $app, array $containerInspect): array
    {
        $env = is_array($app['env'] ?? null) ? $app['env'] : [];
        foreach (($containerInspect['Config']['Env'] ?? []) as $line) {
            $eq = strpos((string) $line, '=');
            if ($eq === false) {
                continue;
            }
            $key = substr((string) $line, 0, $eq);
            if (!array_key_exists($key, $env)) {
                $env[$key] = substr((string) $line, $eq + 1);
            }
        }
        return $env;
    }
}
