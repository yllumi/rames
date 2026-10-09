<?php
declare(strict_types=1);

namespace app\library\Billing;

use RuntimeException;

/**
 * Perhitungan harga kredit dari batas CPU/memori — statik murni, final,
 * **tanpa I/O** (mudah diuji & tidak menyimpan state lintas-request).
 *
 * Rumus (satu sumber kebenaran):
 *   harga_jam(service) = cpus × RATE_CPU + (memory_mb / 1024) × RATE_RAM
 *   harga_app          = Σ_service harga_jam(service)
 *
 * Service tanpa `cpus`/`memory_mb` dianggap memakai `BILLING_DEFAULT_*`
 * sehingga app tanpa `limits` tetap dihitung (bukan gratis). `$limits` kosong
 * ⇒ dihitung SATU unit default.
 *
 * Parsing/normalisasi `limits` TIDAK diduplikasi di sini — pemanggil
 * (UsageMeter/Invoicer/BillingGate) memakai `ResourceLimits` untuk itu,
 * lalu menyerahkan map ternormalisasi ke kelas ini.
 */
final class Pricing
{
    /**
     * Tarif aktif dari konfigurasi dashboard.
     *
     * @return array{cpu:float,ram:float}
     */
    public static function rates(): array
    {
        return [
            'cpu' => self::positiveFloat(config('deploy.billing_rate_cpu_per_core_hour', 100), 100.0),
            'ram' => self::positiveFloat(config('deploy.billing_rate_ram_per_gb_hour', 20), 20.0),
        ];
    }

    /**
     * Harga satu service per jam (kredit).
     *
     * @param array{cpu:float,ram:float}|array<string,float> $rates
     */
    public static function hourlyCreditsForService(float $cpus, int $memoryMb, array $rates): float
    {
        $cpuRate = self::positiveFloat($rates['cpu'] ?? null, 100.0);
        $ramRate = self::positiveFloat($rates['ram'] ?? null, 20.0);

        return max(0.0, $cpus) * $cpuRate + (max(0, $memoryMb) / 1024) * $ramRate;
    }

    /**
     * Harga per jam seluruh service pada `$limits`.
     *
     * `$limits` kosong ⇒ satu unit default (lihat defaultCpus/defaultMemoryMb).
     * Nilai `cpus`/`memory_mb` null/kosong per service ⇒ nilai default.
     *
     * @param array<int|string,mixed> $limits
     * @param array{cpu:float,ram:float}|array<string,float> $rates
     */
    public static function hourlyCredits(array $limits, array $rates): float
    {
        if ($limits === []) {
            return self::hourlyCreditsForService(self::defaultCpus(), self::defaultMemoryMb(), $rates);
        }

        $total = 0.0;
        foreach ($limits as $limit) {
            if (!is_array($limit)) {
                continue;
            }
            $cpus = isset($limit['cpus']) && is_numeric($limit['cpus'])
                ? (float) $limit['cpus']
                : self::defaultCpus();
            $memoryMb = isset($limit['memory_mb']) && is_numeric($limit['memory_mb'])
                ? (int) $limit['memory_mb']
                : self::defaultMemoryMb();
            $total += self::hourlyCreditsForService($cpus, $memoryMb, $rates);
        }

        return $total;
    }

    /**
     * Estimasi biaya `$days` hari (kredit, dibulatkan 2 desimal).
     *
     * @param array<int|string,mixed> $limits
     * @param array{cpu:float,ram:float}|array<string,float> $rates
     */
    public static function estimate(array $limits, array $rates, int $days): float
    {
        return round(self::hourlyCredits($limits, $rates) * 24 * max(0, $days), 2);
    }

    /**
     * Deposit minimum yang dibutuhkan = estimasi `$days` hari, tidak pernah
     * kurang dari `$minCredits` (dibulatkan 2 desimal).
     *
     * @param array<int|string,mixed> $limits
     * @param array{cpu:float,ram:float}|array<string,float> $rates
     */
    public static function requiredDeposit(array $limits, array $rates, int $days, float $minCredits = 0): float
    {
        return round(max(self::estimate($limits, $rates, $days), max(0.0, $minCredits)), 2);
    }

    /**
     * Format kredit dengan 2 desimal tanpa pemisah ribuan (mis. "1234.50").
     */
    public static function format(float $credits): string
    {
        return number_format($credits, 2, '.', '');
    }

    /**
     * Tolak `$limits` yang melampaui plafon `BILLING_MAX_CPUS` /
     * `BILLING_MAX_MEMORY_MB` (pesan Bahasa Indonesia menyebut service).
     *
     * Validasi nilai minimum/tipe tetap milik `ResourceLimits`; kelas ini hanya
     * menegakkan plafon billing.
     *
     * @param array<int|string,mixed> $limits
     */
    public static function assertWithinCaps(array $limits): void
    {
        $maxCpus = self::positiveFloat(config('deploy.billing_max_cpus', 4), 4.0);
        $maxMemoryMb = max(1, (int) config('deploy.billing_max_memory_mb', 8192));

        foreach ($limits as $service => $limit) {
            if (!is_array($limit)) {
                continue;
            }
            $label = (string) $service;
            if (isset($limit['cpus']) && is_numeric($limit['cpus']) && (float) $limit['cpus'] > $maxCpus) {
                throw new RuntimeException(
                    'Batas CPU service "' . $label . '" (' . self::format((float) $limit['cpus'])
                    . ' core) melebihi plafon ' . self::format($maxCpus) . ' core. Turunkan nilai CPU.'
                );
            }
            if (isset($limit['memory_mb']) && is_numeric($limit['memory_mb']) && (int) $limit['memory_mb'] > $maxMemoryMb) {
                throw new RuntimeException(
                    'Batas memori service "' . $label . '" (' . (int) $limit['memory_mb']
                    . ' MB) melebihi plafon ' . $maxMemoryMb . ' MB. Turunkan nilai memori.'
                );
            }
        }
    }

    public static function defaultCpus(): float
    {
        return self::positiveFloat(config('deploy.billing_default_cpus', 0.5), 0.5);
    }

    public static function defaultMemoryMb(): int
    {
        return max(1, (int) config('deploy.billing_default_memory_mb', 512));
    }

    /**
     * Nilai float > 0 dari config/array; fallback bila kosong/0/negatif/bukan angka.
     */
    private static function positiveFloat(mixed $value, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        $float = (float) $value;

        return $float > 0 ? $float : $fallback;
    }
}
