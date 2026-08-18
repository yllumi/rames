<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\ComposeParser;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Manajemen external network per site (fitur "Network" di detail site).
 *
 * Sumber kebenaran: field "external_networks" di sites.json (daftar nama network
 * Docker yang sudah ada — biasanya shared network yang dibuat lewat halaman /networks).
 *
 * Representasi di disk: sites/{name}/docker-compose.override.networks.yml —
 * mendeklarasikan setiap network sebagai `external: true` (top-level) dan
 * menambahkan tiap service ke network itu bersama network default project:
 *
 *   networks:
 *     shared-app:
 *       external: true
 *   services:
 *     web:
 *       networks:
 *         - default
 *         - shared-app
 *
 * Merge compose untuk `services.*.networks` bersifat union (mapping), sehingga
 * network bawaan base compose tetap dipertahankan dan network eksternal ditambahkan.
 *
 * File override berada di direktori repo site (untracked git, sama seperti override
 * ports/env) sehingga tidak konflik dengan `git pull --ff-only`. Sinkronisasi
 * dipanggil dari controller (saveNetworks) dan LocalDeployer (deploy/rebuild/
 * rollback/applyEnv) agar file di disk selalu konsisten dengan sites.json.
 */
class NetworkManager
{
    public const OVERRIDE_FILE = 'docker-compose.override.networks.yml';

    /**
     * Sinkronkan file override external networks dengan state site. Idempoten.
     *
     * @param array             $site         array site (membaca field external_networks)
     * @param string            $dir          direktori site
     * @param array<int,string> $composeFiles daftar compose files site
     * @return bool true bila external networks aktif (file ditulis), false bila kosong
     */
    public function sync(array $site, string $dir, array $composeFiles): bool
    {
        $networks = $this->networksOf($site);
        if ($networks === []) {
            $this->removeOverride($dir);
            return false;
        }
        $this->writeOverride($dir, $composeFiles, $networks);
        return true;
    }

    /**
     * Tulis override external networks ke direktori site.
     *
     * @param string            $dir          direktori site
     * @param array<int,string> $composeFiles daftar compose files site
     * @param array<int,string> $networks     nama network eksternal (sudah tervalidasi)
     */
    public function writeOverride(string $dir, array $composeFiles, array $networks): void
    {
        $services = $this->serviceNames($dir, $composeFiles);
        if ($services === []) {
            throw new RuntimeException('Tidak dapat menentukan daftar service untuk external networks override.');
        }

        $data = ['networks' => [], 'services' => []];
        foreach ($networks as $name) {
            $data['networks'][$name] = ['external' => true];
        }
        foreach ($services as $svc) {
            $data['services'][$svc] = [
                'networks' => array_merge(['default'], $networks),
            ];
        }

        $yaml = Yaml::dump($data, 4, 2);
        if (@file_put_contents($dir . '/' . self::OVERRIDE_FILE, $yaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . self::OVERRIDE_FILE . '.');
        }
    }

    /**
     * Hapus override file dari direktori site (saat external networks dikosongkan).
     */
    public function removeOverride(string $dir): void
    {
        $path = $dir . '/' . self::OVERRIDE_FILE;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Nama-nama service dari base compose (file pertama yang bukan override).
     *
     * @param array<int,string> $composeFiles
     * @return array<int,string>
     */
    private function serviceNames(string $dir, array $composeFiles): array
    {
        $baseFile = null;
        foreach ($composeFiles as $f) {
            if (!str_starts_with($f, 'docker-compose.override')) {
                $baseFile = $f;
                break;
            }
        }
        $baseFile = $baseFile ?? 'docker-compose.yml';

        $path = $dir . '/' . $baseFile;
        if (!is_file($path)) {
            throw new RuntimeException('Base compose tidak ditemukan: ' . $path);
        }

        $parsed = (new ComposeParser())->parse($path);
        return array_keys($parsed['services']);
    }

    /**
     * @return array<int,string>
     */
    private function networksOf(array $site): array
    {
        $networks = $site['external_networks'] ?? [];
        if (!is_array($networks)) {
            return [];
        }
        $result = [];
        foreach ($networks as $name) {
            $name = (string) $name;
            if ($name !== '' && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) && !in_array($name, $result, true)) {
                $result[] = $name;
            }
        }
        return $result;
    }
}
