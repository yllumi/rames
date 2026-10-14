<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Override **nama container** (`container_name`) per app — pasangan dari
 * override host port.
 *
 * Sumber kebenaran: field `container_prefix` di apps.json (string, boleh kosong).
 * Representasi di disk: `apps/{name}/docker-compose.override.names.yml` —
 * satu `container_name: {prefix}-{service}` per service:
 *
 *   services:
 *     web:
 *       container_name: hermes-web
 *     worker:
 *       container_name: hermes-worker
 *
 * Prefix kosong = file dihapus → compose memakai nama default
 * `{project}_{service}_{n}` seperti sebelumnya (tanpa prefix project Docker,
 * nama custom bersifat **unik se-host**, lihat assertAvailable()).
 *
 * File override berada di direktori repo app (untuk app mode git sifatnya
 * untracked, sama seperti override ports/env/network) sehingga tidak konflik
 * dengan `git pull --ff-only`. Sinkronisasi dipanggil dari controller
 * (create & tab Container) dan LocalDeployer (deploy/rebuild/rollback/apply)
 * agar file di disk selalu konsisten dengan apps.json.
 *
 * Stateless & murni statik — aman untuk worker Webman persistent.
 */
final class ContainerNames
{
    public const OVERRIDE_FILE = 'docker-compose.override.names.yml';

    /** Panjang maksimum prefix (menjaga nama container tetap terbaca). */
    public const MAX_PREFIX_LENGTH = 20;

    /** Prefix: huruf kecil/angka, boleh `-` di tengah. */
    private const PREFIX_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    // ==================================================================
    // Skema nama
    // ==================================================================

    /**
     * Normalisasi input user (trim + lowercase, seperti slug nama app).
     */
    public static function normalizePrefix(mixed $raw): string
    {
        return strtolower(trim((string) $raw));
    }

    /**
     * Prefix kosong berarti "pakai nama default compose" — bukan error.
     */
    public static function assertValidPrefix(string $prefix): void
    {
        if ($prefix === '') {
            return;
        }
        if (strlen($prefix) > self::MAX_PREFIX_LENGTH) {
            throw new RuntimeException(
                'Prefix nama container maksimal ' . self::MAX_PREFIX_LENGTH . ' karakter.'
            );
        }
        if (!preg_match(self::PREFIX_PATTERN, $prefix)) {
            throw new RuntimeException(
                'Prefix nama container tidak valid: "' . $prefix . '" (huruf kecil/angka, boleh "-" di tengah, contoh: hermes).'
            );
        }
    }

    /**
     * Nama container final untuk satu service.
     */
    public static function nameFor(string $prefix, string $service): string
    {
        return $prefix . '-' . $service;
    }

    /**
     * Peta service => nama container final.
     *
     * @param array<int,string> $services
     * @return array<string,string>
     */
    public static function mapFor(array $services, string $prefix): array
    {
        $map = [];
        foreach ($services as $service) {
            $service = (string) $service;
            if ($service === '') {
                continue;
            }
            $map[$service] = self::nameFor($prefix, $service);
        }
        return $map;
    }

    // ==================================================================
    // Service dari compose & batasan replica
    // ==================================================================

    /**
     * Service pada base compose app: nama => info.
     *
     * `container_name` tidak kompatibel dengan replica (compose menolak
     * `--scale`/`deploy.replicas` > 1), jadi jumlah replica dibaca di sini
     * untuk divalidasi sebelum override ditulis.
     *
     * @param array<int,string> $composeFiles
     * @return array<string,array{replicas:int}>
     */
    public static function services(string $dir, array $composeFiles): array
    {
        $base = ComposeSource::mainFileFrom($composeFiles);
        if ($base === '') {
            $base = ComposeSource::detectMainFile($dir);
        }
        $base = $base !== '' ? $base : ComposeSource::MAIN_FILES[0];

        $path = $dir . '/' . $base;
        if (!is_file($path)) {
            throw new RuntimeException('Base compose tidak ditemukan: ' . $path);
        }
        try {
            $data = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable $e) {
            throw new RuntimeException('Gagal parse ' . $base . ': ' . $e->getMessage());
        }

        $declared = is_array($data['services'] ?? null) ? $data['services'] : [];
        $services = [];
        foreach ($declared as $name => $config) {
            $replicas = 1;
            if (is_array($config)) {
                $replicas = (int) ($config['deploy']['replicas'] ?? $config['scale'] ?? 1);
            }
            $services[(string) $name] = ['replicas' => max(1, $replicas)];
        }
        if ($services === []) {
            throw new RuntimeException('Tidak ada service pada ' . $base . '.');
        }

        return $services;
    }

    /**
     * Tolak pemberian nama custom bila ada service ber-replica > 1.
     *
     * @param array<string,array{replicas:int}> $services
     */
    public static function assertNotReplicated(array $services): void
    {
        $replicated = [];
        foreach ($services as $name => $info) {
            if ((int) ($info['replicas'] ?? 1) > 1) {
                $replicated[] = $name . ' (replicas=' . (int) $info['replicas'] . ')';
            }
        }
        if ($replicated !== []) {
            throw new RuntimeException(
                'Nama container custom tidak didukung untuk service ber-replica: '
                . implode(', ', $replicated)
                . '. Nama container bersifat unik se-host sehingga compose tidak bisa menyalin container. '
                . 'Kurangi jumlah replica service tersebut terlebih dahulu.'
            );
        }
    }

    // ==================================================================
    // Deteksi bentrok nama
    // ==================================================================

    /**
     * Nama container yang sudah terpakai di host (dari Engine API), di luar
     * project milik app ini sendiri.
     *
     * Scope server (multi-server F1): `$serverId` null = server **lokal**
     * (Engine yang dibaca memang Engine lokal — perilaku lama). Server selain
     * lokal **ditolak fail-fast**: pembacaan Engine lintas server belum aktif di
     * F1 (F1/F3 akan merutekannya ke agent). JANGAN diam-diam membaca Engine
     * lokal untuk app milik server remote — itu kesalahan paling berbahaya
     * (plan §3 butir 4).
     *
     * @param array<int,array> $containers hasil DockerClient::listContainers()
     * @return array<string,string> nama => deskripsi pemilik
     * @throws RuntimeException bila $serverId bukan server lokal
     */
    public static function usedFromEngine(array $containers, string $ownProject, ?string $serverId = null): array
    {
        if ($serverId !== null) {
            $scope = AppStore::normalizeServerId($serverId);
            if ($scope !== ServerStore::LOCAL_ID) {
                throw new RuntimeException(
                    'Nama container server "' . $scope . '" tidak bisa dibaca: pembacaan Docker Engine '
                    . 'lintas server belum aktif (F1/F3 akan merutekannya lewat agent). '
                    . 'Operasi dibatalkan, bukan dijalankan pada Docker Engine lokal.'
                );
            }
        }

        $used = [];
        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }
            $labels = is_array($container['Labels'] ?? null) ? $container['Labels'] : [];
            $project = (string) ($labels['com.docker.compose.project'] ?? '');
            if ($ownProject !== '' && $project === $ownProject) {
                continue; // container app ini sendiri sedang digantikan
            }
            $owner = $project !== ''
                ? 'app "' . $project . '"'
                : 'container di luar dashboard (docker ps)';

            foreach ((array) ($container['Names'] ?? []) as $rawName) {
                $name = ltrim((string) $rawName, '/');
                if ($name !== '') {
                    $used[$name] = $owner;
                }
            }
        }
        return $used;
    }

    /**
     * Cadangan saat Engine API tidak dapat diakses: nama container dari
     * `apps.json` app lain.
     *
     * Scope server (multi-server F1): `$serverId` null = **semua server**
     * (perilaku lama, fail-safe); bila diisi = hanya app di server itu — app di
     * server lain tidak boleh menahan nama container di server ini.
     *
     * @param array<int,array> $apps
     * @param string|null $serverId id server (null = semua server)
     * @return array<string,string> nama => deskripsi pemilik
     */
    public static function usedFromApps(array $apps, string $ownProject, ?string $serverId = null): array
    {
        $scope = $serverId === null ? null : AppStore::normalizeServerId($serverId);
        $used = [];
        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }
            if ($scope !== null && AppStore::serverIdOf($app) !== $scope) {
                continue;
            }
            $name = (string) ($app['name'] ?? '');
            if ($ownProject !== '' && $name === $ownProject) {
                continue;
            }
            foreach ((array) ($app['containers'] ?? []) as $container) {
                $containerName = (string) ($container['container_name'] ?? '');
                if ($containerName !== '') {
                    $used[$containerName] = 'app "' . ($name !== '' ? $name : '?') . '"';
                }
            }
        }
        return $used;
    }

    /**
     * Nama yang bentrok.
     *
     * @param array<string,string> $names service => nama container final
     * @param array<string,string> $used  nama terpakai => deskripsi pemilik
     * @return array<string,string>       nama bentrok => pemilik
     */
    public static function conflicts(array $names, array $used): array
    {
        $conflicts = [];
        foreach ($names as $name) {
            if (isset($used[$name])) {
                $conflicts[$name] = $used[$name];
            }
        }
        return $conflicts;
    }

    /**
     * Fail-fast: tolak bila ada nama yang sudah dipakai container lain.
     * Tanpa pemeriksaan ini `docker compose up` gagal di tengah deploy dengan
     * "Conflict. The container name ... is already in use".
     *
     * @param array<string,string> $names service => nama container final
     * @param array<string,string> $used  nama terpakai => deskripsi pemilik
     */
    public static function assertAvailable(array $names, array $used): void
    {
        $conflicts = self::conflicts($names, $used);
        if ($conflicts === []) {
            return;
        }
        $parts = [];
        foreach ($conflicts as $name => $owner) {
            $parts[] = '"' . $name . '" (dipakai ' . $owner . ')';
        }
        throw new RuntimeException(
            'Nama container sudah terpakai: ' . implode(', ', $parts)
            . '. Nama container bersifat unik se-host (tanpa prefix project), pilih prefix lain.'
        );
    }

    // ==================================================================
    // Representasi di disk
    // ==================================================================

    /**
     * Sinkronkan file override nama container dengan state app. Idempoten.
     *
     * @param array             $app          array app (membaca field container_prefix)
     * @param string            $dir          direktori app
     * @param array<int,string> $composeFiles daftar compose files app
     * @return bool true bila override aktif (file ditulis), false bila prefix kosong
     */
    public static function sync(array $app, string $dir, array $composeFiles): bool
    {
        $prefix = self::normalizePrefix($app['container_prefix'] ?? '');
        if ($prefix === '') {
            self::removeOverride($dir);
            return false;
        }

        $services = array_keys(self::services($dir, $composeFiles));
        self::writeOverride($dir, $services, $prefix);
        return true;
    }

    /**
     * Tulis override nama container ke direktori app.
     *
     * @param array<int,string> $services nama service (kunci base compose)
     */
    public static function writeOverride(string $dir, array $services, string $prefix): void
    {
        self::assertValidPrefix($prefix);
        if ($services === []) {
            throw new RuntimeException('Tidak dapat menentukan daftar service untuk override nama container.');
        }

        $data = ['services' => []];
        foreach ($services as $service) {
            $service = (string) $service;
            if ($service === '') {
                continue;
            }
            $data['services'][$service] = ['container_name' => self::nameFor($prefix, $service)];
        }

        $yaml = Yaml::dump($data, 4, 2);
        if (@file_put_contents($dir . '/' . self::OVERRIDE_FILE, $yaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . self::OVERRIDE_FILE . '.');
        }
    }

    /**
     * Hapus override nama container (saat prefix dikosongkan).
     */
    public static function removeOverride(string $dir): void
    {
        $path = $dir . '/' . self::OVERRIDE_FILE;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
