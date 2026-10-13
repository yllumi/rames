<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Auth\UserStore;
use app\library\Deploy\ResourceLimits;
use app\library\Storage\AppStore;
use DateTimeImmutable;
use Throwable;

/**
 * Meteran pemakaian per app — akrual inkremental tiap tick (bukan selisih
 * timestamp) supaya tahan restart worker dan tidak membengkakkan tagihan bila
 * dashboard sempat mati.
 *
 * Aturan:
 * - hanya app `status === 'running'` yang diakru;
 * - app milik owner ber-role global **admin** dilewati (admin gratis);
 * - `credits_pending += (seconds / 3600) × harga_per_jam(limits app)`;
 * - `backup_credits_pending += gb_ditagih × tarif_backup × (seconds / periode)`
 *   — akumulasi disimpan **4 desimal** (bukan 2) agar bias pembulatan per tick
 *   tidak terakumulasi jadi drift bulanan; pembulatan uang 2 desimal dilakukan
 *   saat penagihan (`Invoicer`). (GB ditagih dari `BackupUsage`, lihat catatan
 *   di bawah);
 * - `period` diinisialisasi ke bulan berjalan saat baris pertama dibuat
 *   (**tanpa** tagihan retroaktif);
 * - `name`/`owner_id` disegarkan tiap tick (snapshot untuk app yang kemudian
 *   dihapus agar tetap bisa ditagih).
 *
 * Backup volume ditagih **per GB per bulan**, diakru per tick bersama CPU/RAM
 * dengan aturan kelayakan yang sama (app running & owner non-admin). Basis GB
 * dibaca sekali per tick dari `BackupUsage` (`BackupRegistry`, tanpa Docker),
 * lengkap dengan floor minimum per app. Aturan `status`/admin sengaja **tidak**
 * diubah: app berhenti/ber-owner admin juga tidak diakru backup.
 *
 * Harga memakai `Pricing`; parsing `limits` memakai `ResourceLimits` (satu
 * sumber kebenaran validasi & penulisan limit CPU/RAM).
 */
class UsageMeter
{
    private AppStore $apps;
    private BillingStore $store;
    private UserStore $users;
    private BackupUsage $backup;

    public function __construct(
        ?AppStore $apps = null,
        ?BillingStore $store = null,
        ?UserStore $users = null,
        ?BackupUsage $backup = null,
    ) {
        $this->apps = $apps ?? new AppStore();
        $this->store = $store ?? new BillingStore();
        $this->users = $users ?? new UserStore();
        $this->backup = $backup ?? new BackupUsage();
    }

    /**
     * Akrual `$seconds` detik pemakaian untuk seluruh app yang memenuhi syarat.
     *
     * @return array<int,string> daftar appId yang diakru
     */
    public function sample(int $seconds, ?string $now = null): array
    {
        if ($seconds <= 0) {
            return [];
        }
        if (!(bool) config('deploy.billing_enabled', true)) {
            return [];
        }

        $current = BillingPeriod::current($now);
        $rates = Pricing::rates();
        // Dihitung sekali per tick: jumlah hari periode & GB ditagih per app.
        $daysInPeriod = BillingPeriod::daysIn($current);
        $chargedGb = $this->backup->chargedGigabytesByApp();
        $pending = [];

        foreach ($this->apps->all() as $app) {
            if ((string) ($app['status'] ?? '') !== 'running') {
                continue;
            }
            $appId = (string) ($app['id'] ?? '');
            $ownerId = (string) ($app['owner_id'] ?? '');
            if ($appId === '' || $ownerId === '') {
                continue;
            }
            $owner = $this->users->findById($ownerId);
            if ($owner === null || $this->users->isAdmin($owner)) {
                continue; // owner tidak diketahui / admin gratis
            }

            $gb = (float) ($chargedGb[$appId] ?? 0.0);
            $pending[$appId] = [
                'name' => (string) ($app['name'] ?? ''),
                'owner_id' => $ownerId,
                'credits' => ($seconds / 3600) * Pricing::hourlyCredits(ResourceLimits::of($app), $rates),
                'backup_gb' => $gb,
                'backup_credits' => Pricing::backupCredits($gb, (float) $seconds, $daysInPeriod),
            ];
        }

        if ($pending === []) {
            return [];
        }

        $sampledAt = $this->timestamp($now);
        $this->store->update(function (array &$data) use ($pending, $current, $sampledAt, $seconds): void {
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            foreach ($pending as $appId => $row) {
                $existing = is_array($usage[$appId] ?? null) ? $usage[$appId] : [];
                $period = (string) ($existing['period'] ?? '');
                if ($period === '') {
                    // Baris pertama bulan ini: mulai dari nol, tanpa tagihan retroaktif.
                    $period = $current;
                }
                $usage[$appId] = [
                    'name' => $row['name'],
                    'owner_id' => $row['owner_id'],
                    'period' => $period,
                    'seconds_pending' => (int) ($existing['seconds_pending'] ?? 0) + $seconds,
                    'credits_pending' => round((float) ($existing['credits_pending'] ?? 0.0) + $row['credits'], 2),
                    'backup_gb' => round((float) $row['backup_gb'], 4),
                    // Backup diakru per tick: pembulatan 4 desimal (bukan 2) agar
                    // bias naik per tick tidak terakumulasi jadi drift nyata
                    // (uang tetap 2 desimal saat ditagih — `Invoicer`).
                    'backup_credits_pending' => round((float) ($existing['backup_credits_pending'] ?? 0.0) + $row['backup_credits'], 4),
                    'sampled_at' => $sampledAt,
                ];
            }
            $data['usage'] = $usage;
        });

        return array_keys($pending);
    }

    /**
     * Ringkasan pemakaian berjalan per app (untuk UI): `owner_id`, `name`,
     * `seconds_pending`, `credits_pending`, `hourly`, `estimate_month`, plus
     * rincian backup `backup_gb`, `backup_credits`, `backup_monthly`.
     *
     * Dikunci berdasarkan appId (kunci sama dengan `usage` di billing.json).
     * `estimate_month` = estimasi CPU/RAM bulan berjalan **+** estimasi backup
     * bulan berjalan. Baris lama tanpa field backup diperlakukan `0` (tidak
     * error).
     *
     * @return array<string,array{owner_id:string,name:string,seconds_pending:int,credits_pending:float,hourly:float,estimate_month:float,backup_gb:float,backup_credits:float,backup_monthly:float}>
     */
    public function summary(): array
    {
        $rates = Pricing::rates();
        $summary = [];

        foreach ($this->store->usage() as $appId => $row) {
            $appId = (string) $appId;
            if (!is_array($row)) {
                continue;
            }
            $app = $this->apps->find($appId);
            $limits = $app !== null ? ResourceLimits::of($app) : [];
            $hourly = Pricing::hourlyCredits($limits, $rates);

            $period = (string) ($row['period'] ?? '');
            if ($period === '') {
                $period = BillingPeriod::current();
            }

            $backupGb = (float) ($row['backup_gb'] ?? 0.0);
            $backupMonthly = Pricing::backupMonthlyEstimate($backupGb);

            $summary[$appId] = [
                'owner_id' => (string) ($row['owner_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'seconds_pending' => (int) ($row['seconds_pending'] ?? 0),
                'credits_pending' => round((float) ($row['credits_pending'] ?? 0.0), 2),
                'hourly' => $hourly,
                'estimate_month' => round($hourly * 24 * BillingPeriod::daysIn($period) + $backupMonthly, 2),
                'backup_gb' => $backupGb,
                'backup_credits' => round((float) ($row['backup_credits_pending'] ?? 0.0), 2),
                'backup_monthly' => $backupMonthly,
            ];
        }

        return $summary;
    }

    /**
     * Timestamp ISO 8601 untuk `sampled_at`: pakai `$now` bila diberikan,
     * selain itu waktu sekarang.
     */
    private function timestamp(?string $now): string
    {
        if ($now === null || trim($now) === '') {
            return date('c');
        }
        try {
            return (new DateTimeImmutable($now))->format('c');
        } catch (Throwable) {
            return date('c');
        }
    }
}
