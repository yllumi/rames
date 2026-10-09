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
 * - `period` diinisialisasi ke bulan berjalan saat baris pertama dibuat
 *   (**tanpa** tagihan retroaktif);
 * - `name`/`owner_id` disegarkan tiap tick (snapshot untuk app yang kemudian
 *   dihapus agar tetap bisa ditagih).
 *
 * Harga memakai `Pricing`; parsing `limits` memakai `ResourceLimits` (satu
 * sumber kebenaran validasi & penulisan limit CPU/RAM).
 */
class UsageMeter
{
    private AppStore $apps;
    private BillingStore $store;
    private UserStore $users;

    public function __construct(?AppStore $apps = null, ?BillingStore $store = null, ?UserStore $users = null)
    {
        $this->apps = $apps ?? new AppStore();
        $this->store = $store ?? new BillingStore();
        $this->users = $users ?? new UserStore();
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

            $pending[$appId] = [
                'name' => (string) ($app['name'] ?? ''),
                'owner_id' => $ownerId,
                'credits' => ($seconds / 3600) * Pricing::hourlyCredits(ResourceLimits::of($app), $rates),
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
                    'sampled_at' => $sampledAt,
                ];
            }
            $data['usage'] = $usage;
        });

        return array_keys($pending);
    }

    /**
     * Ringkasan pemakaian berjalan per app (untuk UI): `owner_id`, `name`,
     * `seconds_pending`, `credits_pending`, `hourly`, `estimate_month`.
     *
     * Dikunci berdasarkan appId (kunci sama dengan `usage` di billing.json).
     *
     * @return array<string,array{owner_id:string,name:string,seconds_pending:int,credits_pending:float,hourly:float,estimate_month:float}>
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

            $summary[$appId] = [
                'owner_id' => (string) ($row['owner_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'seconds_pending' => (int) ($row['seconds_pending'] ?? 0),
                'credits_pending' => round((float) ($row['credits_pending'] ?? 0.0), 2),
                'hourly' => $hourly,
                'estimate_month' => round($hourly * 24 * BillingPeriod::daysIn($period), 2),
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
