<?php
declare(strict_types=1);

namespace app\library\Deploy;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Override **entitlement BuildKit** (`build.entitlements`) per app.
 *
 * Sejak build memakai **buildx/BuildKit**, build yang meminta jaringan host —
 * `build.network: host` di compose, atau `RUN --network=host` di Dockerfile app
 * — ditolak BuildKit dengan
 * `additional privileges requested: pass "--allow=network.host"` /
 * `network.host is not allowed`. Legacy builder dulu mengizinkannya diam-diam,
 * sehingga app yang sudah berjalan bisa tiba-tiba gagal deploy setelah buildx
 * dipasang. Compose tidak punya flag `--allow`; satu-satunya jalur deklaratif
 * adalah `build.entitlements` di file compose.
 *
 * Karena file compose milik app (repo user) tidak boleh diubah dashboard,
 * entitlement ditulis ke override generated tepat sebelum build:
 *
 *   services:
 *     webman:
 *       build:
 *         entitlements: [network.host]
 *
 * Hanya service yang **punya `build:`** yang disebut (service ber-`image:` saja
 * tidak diberi blok build), dan hanya bila belum menyatakan entitlement itu
 * sendiri. File **tidak** dipersist ke `compose_files` — ia dipakai untuk operasi
 * build yang sedang berjalan, lalu dibersihkan bila tidak ada service yang perlu
 * (mis. setelah base compose berubah). Murni statik — aman untuk worker
 * Webman persistent.
 */
final class BuildEntitlements
{
    /** Nama file override (generated, untracked di repo app). */
    public const OVERRIDE_FILE = 'docker-compose.override.build.yml';

    /**
     * Entitlement yang dibutuhkan build app.
     *
     * `network.host` = build boleh memakai network host (`build.network: host` /
     * `RUN --network=host`). Ini pemulihan perilaku legacy builder, bukan
     * pelebihan: build app memang sudah berjalan sebagai root di daemon host.
     */
    public const ENTITLEMENT = 'network.host';

    // ==================================================================
    // Sinkronisasi
    // ==================================================================

    /**
     * Tulis/hapus override sesuai isi compose app.
     *
     * @param array<int,string> $composeFiles daftar compose app (urut pakai)
     * @return bool true bila override ditulis, false bila tidak diperlukan
     *              (file lama ikut dihapus)
     */
    public static function sync(string $dir, array $composeFiles): bool
    {
        $services = self::servicesMissingEntitlement($dir, $composeFiles);
        if ($services === []) {
            self::removeOverride($dir);
            return false;
        }
        self::writeOverride($dir, $services);
        return true;
    }

    /**
     * Service yang punya `build:` tetapi belum menyatakan entitlement sendiri.
     *
     * @param array<int,string> $composeFiles
     * @return array<int,string> nama service
     */
    public static function servicesMissingEntitlement(string $dir, array $composeFiles): array
    {
        $missing = [];
        foreach (self::buildServices($dir, $composeFiles) as $service => $entitlements) {
            if (!in_array(self::ENTITLEMENT, $entitlements, true)) {
                $missing[] = $service;
            }
        }
        return $missing;
    }

    /**
     * Service ber-`build:` pada seluruh file compose app, beserta entitlement
     * yang sudah dideklarasikan.
     *
     * Semua file diparse (bukan hanya base) karena `build` boleh dideklarasikan
     * di `docker-compose.override.yml` milik repo. File override milik kelas ini
     * dilewati agar hasil tidak memengaruhi keputusan.
     *
     * @param array<int,string> $composeFiles
     * @return array<string,array<int,string>> service => entitlement terdeklarasi
     */
    public static function buildServices(string $dir, array $composeFiles): array
    {
        $found = [];
        foreach ($composeFiles as $file) {
            $file = (string) $file;
            if ($file === '' || $file === self::OVERRIDE_FILE) {
                continue;
            }
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            try {
                $data = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable $e) {
                throw new RuntimeException('Gagal parse ' . $file . ' untuk entitlement build: ' . $e->getMessage());
            }
            $declared = is_array($data['services'] ?? null) ? $data['services'] : [];
            foreach ($declared as $name => $config) {
                $name = (string) $name;
                if ($name === '' || !is_array($config) || !array_key_exists('build', $config)) {
                    continue;
                }
                $found[$name] = $found[$name] ?? [];
                $build = $config['build'];
                if (!is_array($build) || !is_array($build['entitlements'] ?? null)) {
                    continue;
                }
                foreach ($build['entitlements'] as $entitlement) {
                    $found[$name][] = (string) $entitlement;
                }
            }
        }
        return $found;
    }

    // ==================================================================
    // Daftar compose_files
    // ==================================================================

    /**
     * Sisipkan nama override ke daftar `-f` compose, tepat **sebelum** override
     * env (override env tetap paling akhir, sesuai urutan kanonik
     * `ComposeSource::orderFiles()`). Duplikat dibuang.
     *
     * @param array<int,string> $files
     * @return array<int,string>
     */
    public static function withOverride(array $files): array
    {
        $clean = [];
        foreach ($files as $file) {
            if ((string) $file !== self::OVERRIDE_FILE) {
                $clean[] = (string) $file;
            }
        }
        $pos = array_search(EnvManager::OVERRIDE_FILE, $clean, true);
        if ($pos === false) {
            $clean[] = self::OVERRIDE_FILE;
            return $clean;
        }
        array_splice($clean, (int) $pos, 0, [self::OVERRIDE_FILE]);
        return $clean;
    }

    // ==================================================================
    // File
    // ==================================================================

    /**
     * Tulis override entitlement ke direktori app.
     *
     * @param array<int,string> $services nama service ber-`build:`
     */
    public static function writeOverride(string $dir, array $services): void
    {
        $data = ['services' => []];
        foreach ($services as $service) {
            $service = (string) $service;
            if ($service === '') {
                continue;
            }
            $data['services'][$service] = ['build' => ['entitlements' => [self::ENTITLEMENT]]];
        }
        if ($data['services'] === []) {
            throw new RuntimeException('Tidak dapat menentukan service untuk override entitlement build.');
        }

        $yaml = Yaml::dump($data, 4, 2);
        if (@file_put_contents($dir . '/' . self::OVERRIDE_FILE, $yaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . self::OVERRIDE_FILE . '.');
        }
    }

    /**
     * Hapus override entitlement (tidak ada service yang membutuhkannya).
     */
    public static function removeOverride(string $dir): void
    {
        $path = $dir . '/' . self::OVERRIDE_FILE;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
