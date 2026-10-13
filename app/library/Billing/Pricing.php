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
     * Tarif biaya backup volume (kredit per GB **per bulan**) dari konfigurasi
     * dashboard; fallback 1000.0 bila kosong/0/negatif/bukan angka.
     *
     * Kurs top-up default 1:1 (Rp1 = 1 kredit) sehingga nilai di sini = rupiah
     * per GB per bulan.
     */
    public static function backupMonthlyRate(): float
    {
        return self::positiveFloat(config('deploy.billing_rate_backup_per_gb_month', 1000.0), 1000.0);
    }

    /**
     * Kredit backup untuk `$seconds` detik pemakaian `$gb` dalam periode
     * `$daysInPeriod` hari (akrual inkremental per tick, bukan sekali per run).
     *
     * Rumus: `max(0,$gb) × tarif × ($seconds / (max(1,$daysInPeriod) × 86400))`.
     * Satu bulan penuh (`$seconds = daysInPeriod × 86400`) ⇒ `gb × tarif`.
     * Nilai **tidak** dibulatkan agar akumulasi tick tetap presisi (pembulatan
     * 2 desimal dilakukan saat menyimpan field pending).
     */
    public static function backupCredits(float $gb, float $seconds, int $daysInPeriod, ?float $monthlyRate = null): float
    {
        $rate = self::backupRate($monthlyRate);
        $days = max(1, $daysInPeriod);
        $periodSeconds = $days * 86400;

        return max(0.0, $gb) * $rate * (max(0.0, $seconds) / $periodSeconds);
    }

    /**
     * Estimasi kredit satu bulan penuh untuk `$gb` = `gb × tarif`, dibulatkan
     * 2 desimal (untuk tampilan/estimasi, bukan akrual).
     */
    public static function backupMonthlyEstimate(float $gb, ?float $monthlyRate = null): float
    {
        return round(max(0.0, $gb) * self::backupRate($monthlyRate), 2);
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

    /**
     * Tarif backup efektif: nilai eksplisit bila > 0, selain itu tarif config.
     */
    private static function backupRate(?float $monthlyRate): float
    {
        return self::positiveFloat($monthlyRate ?? config('deploy.billing_rate_backup_per_gb_month', 1000.0), 1000.0);
    }
}
