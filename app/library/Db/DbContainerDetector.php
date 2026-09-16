<?php
declare(strict_types=1);

namespace app\library\Db;

use app\library\Docker\DockerClient;

/**
 * Deteksi container yang berisi server MySQL/MariaDB (phpMyAdmin mini).
 *
 * Deteksi dilakukan lewat nama image (mengandung "mysql"/"mariadb"/"percona")
 * ATAU environment khas server database (MYSQL_* / MARIADB_*). Setiap container
 * diklasifikasikan sebagai milik app yang dikelola dashboard (apps.json) atau
 * "eksternal" (bukan milik app aktif).
 */
class DbContainerDetector
{
    private DockerClient $docker;

    public function __construct(?DockerClient $docker = null)
    {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
    }

    /**
     * Apakah container (berdasarkan hasil inspect) merupakan server MySQL/MariaDB?
     */
    public function isDbContainer(array $inspect): bool
    {
        $image = strtolower((string) ($inspect['Config']['Image'] ?? ''));
        if (str_contains($image, 'mysql') || str_contains($image, 'mariadb') || str_contains($image, 'percona')) {
            return true;
        }
        $env = $this->envMap($inspect);
        foreach ([
            'MYSQL_ROOT_PASSWORD', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD',
            'MARIADB_ROOT_PASSWORD', 'MARIADB_DATABASE', 'MARIADB_USER', 'MARIADB_PASSWORD',
        ] as $key) {
            if (array_key_exists($key, $env)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deteksi semua container DB + klasifikasi kepemilikan app.
     *
     * @param array<int,array> $apps daftar app dari AppStore
     * @return array<int,array{container_name:string, container_id:string, image:string,
     *                          state:string, status:string, owned:bool,
     *                          app_id:?string, app_name:?string}>
     */
    public function detectAll(array $apps): array
    {
        $ownerByContainer = [];
        foreach ($apps as $app) {
            foreach (($app['containers'] ?? []) as $c) {
                $name = (string) ($c['container_name'] ?? '');
                if ($name !== '') {
                    $ownerByContainer[$name] = $app;
                }
            }
        }

        $result = [];
        foreach ($this->docker->listContainers() as $c) {
            $name = $this->firstName($c['Names'] ?? []);
            if ($name === '') {
                continue;
            }
            $inspect = $this->docker->inspectContainer((string) ($c['Id'] ?? $name));
            if (!$this->isDbContainer($inspect)) {
                continue;
            }
            $owner = $ownerByContainer[$name] ?? null;
            $result[] = [
                'container_name' => $name,
                'container_id' => (string) ($c['Id'] ?? ''),
                'image' => (string) ($c['Image'] ?? ''),
                'state' => (string) ($c['State'] ?? ''),
                'status' => (string) ($c['Status'] ?? ''),
                'owned' => $owner !== null,
                'app_id' => $owner !== null ? (string) ($owner['id'] ?? '') : null,
                'app_name' => $owner !== null ? (string) ($owner['name'] ?? '') : null,
            ];
        }
        usort($result, static fn (array $a, array $b): int => strcmp((string) $a['container_name'], (string) $b['container_name']));
        return $result;
    }

    /**
     * Deteksi container DB milik sebuah app (untuk tab "Database" di detail app).
     * Hanya meng-inspect container milik app tsb (dari apps.json) — efisien.
     * Fallback ke heuristik nama image dari apps.json bila Engine tidak tersedia.
     *
     * @return array<int,array>
     */
    public function detectForApp(array $app): array
    {
        $ownedNames = [];
        foreach (($app['containers'] ?? []) as $c) {
            $name = (string) ($c['container_name'] ?? '');
            if ($name !== '') {
                $ownedNames[] = $name;
            }
        }

        $result = [];
        try {
            foreach ($ownedNames as $name) {
                $inspect = $this->docker->inspectContainer($name);
                if (!$this->isDbContainer($inspect)) {
                    continue;
                }
                $result[] = [
                    'container_name' => $name,
                    'container_id' => (string) ($inspect['Id'] ?? ''),
                    'image' => (string) ($inspect['Config']['Image'] ?? ''),
                    'state' => (string) ($inspect['State']['Status'] ?? 'unknown'),
                    'status' => (string) ($inspect['State']['Status'] ?? 'unknown'),
                    'owned' => true,
                    'app_id' => (string) ($app['id'] ?? ''),
                    'app_name' => (string) ($app['name'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // Engine tidak tersedia — heuristik nama image dari data apps.json.
            foreach (($app['containers'] ?? []) as $c) {
                $image = strtolower((string) ($c['image'] ?? ''));
                if (str_contains($image, 'mysql') || str_contains($image, 'mariadb') || str_contains($image, 'percona')) {
                    $result[] = [
                        'container_name' => (string) ($c['container_name'] ?? ''),
                        'container_id' => '',
                        'image' => (string) ($c['image'] ?? ''),
                        'state' => (string) ($c['status'] ?? ''),
                        'status' => (string) ($c['status'] ?? ''),
                        'owned' => true,
                        'app_id' => (string) ($app['id'] ?? ''),
                        'app_name' => (string) ($app['name'] ?? ''),
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * Nama container pertama (tanpa slash) dari daftar Names.
     */
    private function firstName(array $names): string
    {
        foreach ($names as $n) {
            $n = ltrim((string) $n, '/');
            if ($n !== '') {
                return $n;
            }
        }
        return '';
    }

    /**
     * Parse Config.Env menjadi map KEY => value.
     *
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
