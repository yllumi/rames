<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Backup\BackupRegistry;
use Throwable;

/**
 * Pemakaian penyimpanan backup volume per app (basis biaya kredit backup).
 *
 * **Stateless**: angka GB selalu dibaca segar dari `BackupRegistry` (koleksi
 * `registry` store `backup`) — kelas ini **tidak** memanggil Docker Engine
 * maupun restic, dan **tidak** menyimpan cache di properti lintas-request.
 *
 * Sumber angka: entri registry volume → `app_id` + `bytes` (ukuran snapshot
 * terakhir). Volume milik app yang sama dijumlahkan, lalu dikonversi ke GB
 * (`bytes / 1024^3`, konvensi yang sama dengan `memory_mb/1024` di CPU/RAM).
 *
 * Aturan entri yang dihitung:
 * - `bytes > 0` **dan** `(int)($entry['snapshots'] ?? 1) > 0`;
 * - `app_id` non-kosong (entri kosong/yatim dilewati);
 * - nilai `bytes` non-numerik diabaikan.
 *
 * Biaya ditagih **per GB per bulan** (akrual per tick oleh `UsageMeter`), dengan
 * **floor** minimum per app (`BILLING_BACKUP_MIN_GB`, default 1 GB) sehingga app
 * bersnapshot tipis (mis. 0,3 GB) tetap ditagih 1 GB. Baca registry dibungkus
 * `try/catch(\Throwable)` → kegagalan baca mengembalikan map kosong (tidak
 * menggagalkan meteran).
 *
 * `$registry` opsional (DI) supaya dapat diuji dengan store temp tanpa menyentuh
 * basis data runtime nyata.
 */
final class BackupUsage
{
    /** 1 GB = 1024^3 byte (konvensi repo, selaras `memory_mb/1024`). */
    public const BYTES_PER_GB = 1073741824;

    private BackupRegistry $registry;

    public function __construct(?BackupRegistry $registry = null)
    {
        $this->registry = $registry ?? new BackupRegistry();
    }

    /**
     * GB mentah (tanpa floor) per appId: Σ `bytes` volume milik app / 1024^3.
     *
     * Hanya appId yang punya snapshot (Σ bytes > 0) yang muncul. Entri
     * `app_id` kosong/yatim dan nilai non-numerik dilewati.
     *
     * @return array<string,float> appId => GB
     */
    public function rawGigabytesByApp(): array
    {
        $raw = [];

        foreach ($this->registryEntries() as $entry) {
            if (!$this->isBillable($entry)) {
                continue;
            }
            $appId = (string) ($entry['app_id'] ?? '');
            $bytes = (float) $entry['bytes'];
            $raw[$appId] = ($raw[$appId] ?? 0.0) + $bytes / self::BYTES_PER_GB;
        }

        return $raw;
    }

    /**
     * GB yang **ditagih** per appId = `max(floor, raw)` untuk app yang punya
     * snapshot (raw > 0). App tanpa snapshot tidak muncul (tidak dikenai biaya).
     *
     * @return array<string,float> appId => GB
     */
    public function chargedGigabytesByApp(): array
    {
        $floor = $this->minGigabytes();
        $charged = [];

        foreach ($this->rawGigabytesByApp() as $appId => $raw) {
            if ($raw <= 0.0) {
                continue;
            }
            $charged[$appId] = max($floor, $raw);
        }

        return $charged;
    }

    /**
     * GB ditagih satu app; `0.0` bila app tidak punya snapshot / appId kosong.
     */
    public function chargedGigabytes(string $appId): float
    {
        if ($appId === '') {
            return 0.0;
        }

        return $this->chargedGigabytesByApp()[$appId] ?? 0.0;
    }

    /**
     * Estimasi kredit **1 bulan** untuk `$gb` (dibulatkan 2 desimal).
     */
    public function monthlyCredits(float $gb): float
    {
        return Pricing::backupMonthlyEstimate($gb);
    }

    /**
     * Deposit minimum sebelum boleh menyalakan backup untuk `$gb`:
     * `gb × tarif × (BILLING_MIN_DEPOSIT_DAYS / 30)`, dibulatkan 2 desimal
     * (0 hari ⇒ 0,00 — sama dengan gerbang kredit CPU/RAM).
     */
    public function requiredDeposit(float $gb): float
    {
        $days = max(0, (int) config('deploy.billing_min_deposit_days', 30));

        return round(max(0.0, $gb) * Pricing::backupMonthlyRate() * ($days / 30), 2);
    }

    /**
     * Floor aktif (GB) dari config `deploy.billing_backup_min_gb`
     * (default 1.0; `0` = tanpa floor). Nilai tak masuk akal (bukan angka/negatif)
     * jatuh ke default 1.0.
     */
    public function minGigabytes(): float
    {
        $value = config('deploy.billing_backup_min_gb', 1.0);
        if (!is_numeric($value)) {
            return 1.0;
        }
        $gb = (float) $value;

        return $gb >= 0.0 ? $gb : 1.0;
    }

    /**
     * Entri registry mentah (nama volume => entri); kegagalan baca ⇒ map kosong
     * supaya meteran tidak pernah gagal karena store rusak/tak terbaca.
     *
     * @return array<string,array<string,mixed>>
     */
    private function registryEntries(): array
    {
        try {
            return $this->registry->read();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function isBillable(array $entry): bool
    {
        if ((string) ($entry['app_id'] ?? '') === '') {
            return false;
        }
        if ((int) ($entry['snapshots'] ?? 1) <= 0) {
            return false;
        }
        if (!isset($entry['bytes']) || !is_numeric($entry['bytes'])) {
            return false;
        }

        return (float) $entry['bytes'] > 0.0;
    }
}
