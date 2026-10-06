<?php
declare(strict_types=1);

namespace app\library\Db;

use app\library\Docker\DockerClient;
use app\library\Docker\DockerExec;

/**
 * Deteksi container yang berisi server MySQL/MariaDB (phpMyAdmin mini).
 *
 * Deteksi dilakukan lewat nama image (mengandung "mysql"/"mariadb"/"percona")
 * ATAU environment khas server database (MYSQL_* / MARIADB_*). Setiap container
 * diklasifikasikan sebagai milik app yang dikelola dashboard (apps.json) atau
 * "eksternal" (bukan milik app aktif).
 *
 * Dua tingkat klasifikasi (jangan dicampur — pemakainya berbeda):
 *  - "container DB" (heuristik murah: image/env) dipakai halaman `/database`
 *    lewat `isDbContainer()`/`detectAll()`/`detectForApp()`. Cukup untuk
 *    mengelompokkan tampilan, tidak menjamin ada tool dump.
 *  - "DB layak-dump untuk backup" (`isDumpableForBackup()`) menambahkan
 *    verifikasi kapabilitas: bila sinyal DB hanya dari env (image bukan image
 *    DB), container benar-benar dicek punya `mysqldump`/`mariadb-dump`.
 *    Tanpa ini, container non-DB yang kebetulan mewarisi env `MYSQL_*` (mis.
 *    Ghost yang menyuntik `MYSQL_PASSWORD` ke prosesnya) akan dipilih untuk
 *    strategi `dump` dan gagal (exit 127: `sh: --host=...: not found`).
 *
 * Halaman `/database` **tidak** ikut berubah: `isDbContainer()` tetap persis
 * heuristik image-ATAU-env yang lama (tanpa exec).
 */
class DbContainerDetector
{
    /**
     * Key env khas server DB — satu-satunya sumber daftar (jangan duplikasi).
     */
    private const DB_ENV_KEYS = [
        'MYSQL_ROOT_PASSWORD', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD',
        'MARIADB_ROOT_PASSWORD', 'MARIADB_DATABASE', 'MARIADB_USER', 'MARIADB_PASSWORD',
    ];

    private DockerClient $docker;
    private DockerExec $exec;

    /** @var array<string,bool> memo hasil `command -v` per container id (hindari exec berulang) */
    private array $dumpableMemo = [];

    public function __construct(?DockerClient $docker = null, ?DockerExec $exec = null)
    {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
        $this->exec = $exec ?? new DockerExec();
    }

    /**
     * Apakah container (berdasarkan hasil inspect) merupakan server MySQL/MariaDB?
     *
     * Heuristik murah image-ATAU-env — dipakai halaman `/database`. TIDAK
     * melakukan exec; jangan menambahkan exec di sini (lihat `isDumpableForBackup()`).
     */
    public function isDbContainer(array $inspect): bool
    {
        return self::isDbImage($inspect) || self::hasDbEnv($inspect);
    }

    /**
     * Murni: apakah nama image adalah image server DB (mysql/mariadb/percona)?
     */
    public static function isDbImage(array $inspect): bool
    {
        $image = strtolower((string) ($inspect['Config']['Image'] ?? ''));
        return str_contains($image, 'mysql') || str_contains($image, 'mariadb') || str_contains($image, 'percona');
    }

    /**
     * Murni: apakah ada environment khas server DB (MYSQL_* atau MARIADB_*)?
     */
    public static function hasDbEnv(array $inspect): bool
    {
        $env = self::envMap($inspect);
        foreach (self::DB_ENV_KEYS as $key) {
            if (array_key_exists($key, $env)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apakah container ini layak di-dump untuk pemilihan strategi backup?
     *
     * Berbeda dari `isDbContainer()` (klasifikasi tampilan): di sini sinyal DB
     * dari env saja tidak cukup — container wajib benar-benar punya binary dump.
     * Urutan sengaja begini agar subclass/pengujian yang meng-override
     * `isDbContainer()` tetap dipercaya:
     *  1. bukan container DB menurut `isDbContainer()` (polimorfik — WAJIB) → false;
     *  2. image DB nyata (`isDbImage()`) → true (pasti punya tool);
     *  3. tidak punya env DB khas → percayai detector → true;
     *  4. sisanya (env-only) → verifikasi tool via `canRunDumpTool()`.
     */
    public function isDumpableForBackup(string $containerId, array $inspect): bool
    {
        if (!$this->isDbContainer($inspect)) {
            return false;
        }
        if (self::isDbImage($inspect)) {
            return true;
        }
        if (!self::hasDbEnv($inspect)) {
            return true;
        }
        return $this->canRunDumpTool($containerId);
    }

    /**
     * Verifikasi container punya `mysqldump` atau `mariadb-dump` (memoize per id).
     *
     * Gagal (exit != 0 / exception) → false → pemanggil memilih `snapshot`
     * (jalur aman: volume tetap ter-backup).
     */
    public function canRunDumpTool(string $containerId): bool
    {
        if ($containerId === '') {
            return false;
        }
        if (array_key_exists($containerId, $this->dumpableMemo)) {
            return $this->dumpableMemo[$containerId];
        }

        $ok = false;
        try {
            $result = $this->exec->runCommand($containerId, 'command -v mysqldump || command -v mariadb-dump', 10);
            $ok = (int) ($result['code'] ?? 1) === 0;
        } catch (\Throwable $e) {
            $ok = false;
        }

        return $this->dumpableMemo[$containerId] = $ok;
    }

    /**
     * Deteksi semua container DB + klasifikasi kepemilikan app.
     *
     * @param array<int,array> $apps           daftar app yang dipakai untuk memetakan kepemilikan
     * @param bool             $includeUnowned true = ikut tampilkan container "eksternal" (bukan milik
     *                                         app dalam $apps). Untuk halaman /database non-admin,
     *                                         kirim $apps = app yang boleh diakses & $includeUnowned
     *                                         = false sehingga hanya container milik app tsb yang
     *                                         muncul (container app user lain tidak bocor).
     * @return array<int,array{container_name:string, container_id:string, image:string,
     *                          state:string, status:string, owned:bool,
     *                          app_id:?string, app_name:?string}>
     */
    public function detectAll(array $apps, bool $includeUnowned = true): array
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
            $owner = $ownerByContainer[$name] ?? null;
            if ($owner === null && !$includeUnowned) {
                continue; // milik app lain / eksternal — tidak boleh ditampilkan
            }
            $inspect = $this->docker->inspectContainer((string) ($c['Id'] ?? $name));
            if (!$this->isDbContainer($inspect)) {
                continue;
            }
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
     * Parse Config.Env menjadi map KEY => value (murni — helper statik).
     *
     * @return array<string,string>
     */
    private static function envMap(array $inspect): array
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
