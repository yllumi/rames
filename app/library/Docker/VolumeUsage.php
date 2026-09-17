<?php
declare(strict_types=1);

namespace app\library\Docker;

/**
 * Pemetaan & format ukuran terpakai volume dari Docker Engine (`GET /system/df`).
 *
 * Murni fungsi statik (tanpa I/O) supaya mudah diuji: pengambilan data dilakukan
 * oleh `DockerClient::getDiskUsage()`, kelas ini hanya menafsirkan `UsageData`.
 */
final class VolumeUsage
{
    /**
     * Peta nama volume → ukuran terpakai.
     *
     * `UsageData.Size` bernilai negatif bila Engine tidak bisa menghitungnya
     * (mis. volume sedang dipakai container yang berjalan) — ditandai
     * `size_human` = "N/A", bukan 0, agar UI tidak berbohong.
     *
     * @param array<int,array> $volumes daftar `Volumes` dari respons /system/df
     * @return array<string,array{size:int,ref_count:int,size_human:string}>
     */
    public static function map(array $volumes): array
    {
        $usage = [];
        foreach ($volumes as $volume) {
            $name = (string) ($volume['Name'] ?? '');
            if ($name === '') {
                continue;
            }
            $size = (int) ($volume['UsageData']['Size'] ?? 0);
            $usage[$name] = [
                'size' => $size,
                'ref_count' => (int) ($volume['UsageData']['RefCount'] ?? 0),
                'size_human' => self::human($size),
            ];
        }
        return $usage;
    }

    /**
     * Ringkasan untuk halaman `/volumes`: hanya volume yang boleh dilihat user
     * (aturan visibilitas ditegakkan pemanggil) + total terpakai.
     *
     * @param array<int,array> $volumes daftar `Volumes` dari respons /system/df
     * @param array<int,string> $allowedNames nama volume yang boleh ditampilkan
     * @return array{usage:array<string,array{size:int,ref_count:int,size_human:string}>,total:int,total_human:string}
     */
    public static function summarize(array $volumes, array $allowedNames): array
    {
        $allowed = [];
        foreach ($allowedNames as $name) {
            $allowed[(string) $name] = true;
        }

        $usage = array_intersect_key(self::map($volumes), $allowed);

        // volume dengan ukuran negatif (Engine tak bisa menghitung) tidak dijumlah
        $total = 0;
        foreach ($usage as $item) {
            if ($item['size'] > 0) {
                $total += $item['size'];
            }
        }

        return [
            'usage' => $usage,
            'total' => $total,
            'total_human' => self::human($total),
        ];
    }

    /**
     * Format byte jadi satuan SI (basis 1000) seperti `docker system df`
     * (mis. 1234567 → "1.235MB"). Nilai negatif = tidak diketahui → "N/A".
     */
    public static function human(int $bytes): string
    {
        if ($bytes < 0) {
            return 'N/A';
        }
        if ($bytes < 1000) {
            return $bytes . 'B';
        }

        $units = ['kB', 'MB', 'GB', 'TB', 'PB', 'EB'];
        $last = count($units) - 1;
        $value = $bytes / 1000;
        $unit = $units[0];
        foreach ($units as $i => $candidate) {
            $unit = $candidate;
            if ($value < 1000 || $i === $last) {
                break;
            }
            $value /= 1000;
        }

        $decimals = $value < 10 ? 3 : ($value < 100 ? 2 : ($value < 1000 ? 1 : 0));
        return number_format($value, $decimals, '.', '') . $unit;
    }
}
