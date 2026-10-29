<?php
declare(strict_types=1);

namespace app\library\Monitor;

use app\library\Docker\VolumeUsage;

/**
 * Ringkasan **total storage satu app** dari `GET /system/df` (SPECS §8d).
 *
 * Storage app = **volume project** + **writable layer container** milik compose
 * project app. Image bersama sengaja **tidak** dihitung: image dipakai bersama
 * banyak app sehingga menambahkannya akan membuat "ukuran app" menyesatkan
 * (satu image 500 MB akan muncul di setiap app yang memakainya).
 *
 * Murni statik tanpa I/O supaya rumusnya bisa diuji tanpa daemon Docker —
 * pengambilan data dilakukan `DockerClient::getDiskUsage(['volume','container'])`
 * (jalur lokal) atau endpoint agent (`AgentDockerController::diskUsage()`).
 *
 * Ukuran negatif dari Engine (volume/container yang sedang dipakai sehingga
 * ukurannya tidak bisa dihitung) **tetap dilaporkan** dengan `size_human` "N/A",
 * tetapi tidak dijumlahkan — pola yang sama dengan `Docker\VolumeUsage`.
 */
final class AppStorage
{
    /** Label Engine yang menandai pemilik compose project sebuah objek. */
    private const PROJECT_LABEL = 'com.docker.compose.project';

    /**
     * Ringkas pemakaian disk satu project compose.
     *
     * @param array $df      respons `GET /system/df` (`Volumes[]`, `Containers[]`)
     * @param string $project nama compose project (= `apps.name`)
     * @return array{
     *   available:bool, volumes_bytes:int, containers_bytes:int, total_bytes:int,
     *   total_human:string,
     *   volumes:array<int,array{name:string,size:int,size_human:string}>,
     *   containers:array<int,array{name:string,size:int,size_human:string}>,
     *   error:null
     * }
     */
    public static function summarize(array $df, string $project): array
    {
        $project = trim($project);
        if ($project === '') {
            return self::empty();
        }

        $volumes = [];
        $volumesBytes = 0;
        foreach (self::rows($df['Volumes'] ?? []) as $volume) {
            if (self::projectOf($volume) !== $project) {
                continue;
            }
            $name = trim((string) ($volume['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $size = self::intOf($volume['UsageData']['Size'] ?? 0);
            $volumes[] = [
                'name' => $name,
                'size' => $size,
                'size_human' => self::human($size),
            ];
            if ($size > 0) {
                $volumesBytes += $size;
            }
        }

        $containers = [];
        $containersBytes = 0;
        foreach (self::rows($df['Containers'] ?? []) as $container) {
            if (self::projectOf($container) !== $project) {
                continue;
            }
            $name = self::containerName($container);
            if ($name === '') {
                continue;
            }
            $size = self::intOf($container['SizeRw'] ?? 0);
            $containers[] = [
                'name' => $name,
                'size' => $size,
                'size_human' => self::human($size),
            ];
            if ($size > 0) {
                $containersBytes += $size;
            }
        }

        $total = $volumesBytes + $containersBytes;

        return [
            'available' => true,
            'volumes_bytes' => $volumesBytes,
            'containers_bytes' => $containersBytes,
            'total_bytes' => $total,
            'total_human' => self::human($total),
            'volumes' => $volumes,
            'containers' => $containers,
            'error' => null,
        ];
    }

    /**
     * Format byte jadi satuan SI (basis 1000) seperti `docker system df` —
     * satu implementasi dengan `Docker\VolumeUsage::human()` (nilai negatif =
     * tidak diketahui → "N/A").
     */
    public static function human(int $bytes): string
    {
        return VolumeUsage::human($bytes);
    }

    /**
     * Blok storage "tidak tersedia" (`available:false`, angka 0) untuk agent
     * lama atau kegagalan Engine. UI menampilkan "—" tanpa menebak ukuran.
     *
     * @return array<string,mixed>
     */
    public static function unavailable(string $error = 'Ukuran storage tidak tersedia.'): array
    {
        $empty = self::empty();
        $empty['available'] = false;
        $empty['error'] = trim($error) === '' ? 'Ukuran storage tidak tersedia.' : $error;

        return $empty;
    }

    /**
     * Blok nol tanpa galat (dipakai `summarize()` saat project kosong).
     *
     * @return array<string,mixed>
     */
    private static function empty(): array
    {
        return [
            'available' => true,
            'volumes_bytes' => 0,
            'containers_bytes' => 0,
            'total_bytes' => 0,
            'total_human' => self::human(0),
            'volumes' => [],
            'containers' => [],
            'error' => null,
        ];
    }

    /**
     * @return array<int,array>
     */
    private static function rows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    /** @param array $row */
    private static function projectOf(array $row): string
    {
        $labels = $row['Labels'] ?? null;

        return is_array($labels) ? trim((string) ($labels[self::PROJECT_LABEL] ?? '')) : '';
    }

    /** Nama tampilan container: `Names[0]` tanpa `/`, cadangan `Id` 12 karakter. */
    private static function containerName(array $container): string
    {
        $names = $container['Names'] ?? null;
        if (is_array($names) && isset($names[0]) && is_scalar($names[0])) {
            $name = ltrim(trim((string) $names[0]), '/');
            if ($name !== '') {
                return $name;
            }
        }

        $id = trim((string) ($container['Id'] ?? ''));

        return $id === '' ? '' : substr($id, 0, 12);
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
