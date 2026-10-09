<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Auth\UserStore;
use app\library\Deploy\ResourceLimits;
use app\library\Storage\AppStore;

/**
 * Penagihan periode: begitu bulan berganti, akumulasi meteran bulan lalu
 * dipotong dari saldo owner + ditulis ke ledger, lalu baris usage di-reset.
 *
 * **Idempoten**: potongan + reset periode terjadi dalam SATU
 * `JsonStore::update()` sehingga menjalankan dua kali tidak memotong dua kali
 * (dan dashboard yang mati tanggal 1 tetap tertagih saat tick pertama hidup).
 *
 * Baris `usage` milik app yang sudah tidak ada di `AppStore` tetap ditagih,
 * lalu barisnya dihapus setelah berhasil ditagih. Baris milik owner yang sudah
 * tidak ada di `auth.json` dilewati (tidak bisa ditagih) dan dibiarkan utuh.
 *
 * Auto-stop app (kebijakan `stop`) dijalankan lewat callback opsional
 * `$stopApps(userId, apps)` — kelas ini **tidak** menyentuh Docker/Deployer
 * agar tetap mudah diuji; pemanggil (proses scheduler) yang menyediakannya.
 */
class Invoicer
{
    private AppStore $apps;
    private BillingStore $store;
    private UserStore $users;

    /** @var callable|null fn(string $userId, array $apps): void */
    private $stopApps;

    public function __construct(
        ?AppStore $apps = null,
        ?BillingStore $store = null,
        ?UserStore $users = null,
        ?callable $stopApps = null,
    ) {
        $this->apps = $apps ?? new AppStore();
        $this->store = $store ?? new BillingStore();
        $this->users = $users ?? new UserStore();
        $this->stopApps = $stopApps;
    }

    /**
     * Tagih seluruh baris `usage` yang periodenya sebelum bulan berjalan.
     *
     * @return array<int,array{user_id:string,period:string,amount:float,items:array<int,array>,stopped:bool,apps_stopped:int}>
     */
    public function runDue(?string $now = null): array
    {
        $current = BillingPeriod::current($now);

        return $this->close($current, static fn (string $period): bool => BillingPeriod::isBefore($period, $current));
    }

    /**
     * Tutup periode tertentu secara manual (admin/verifikasi). Baris yang
     * periodenya tepat sama dengan `$period` ditagih lalu di-reset ke bulan
     * berjalan — termasuk menutup periode berjalan lebih awal.
     *
     * @return array<int,array{user_id:string,period:string,amount:float,items:array<int,array>,stopped:bool,apps_stopped:int}>
     */
    public function runNow(string $period, ?string $now = null): array
    {
        $target = trim($period);
        BillingPeriod::label($target); // validasi format YYYY-MM (melempar bila salah)

        return $this->close(
            BillingPeriod::current($now),
            static fn (string $rowPeriod): bool => $rowPeriod === $target
        );
    }

    /**
     * @param callable(string):bool $select menerima `period` sebuah baris usage
     * @return array<int,array{user_id:string,period:string,amount:float,items:array<int,array>,stopped:bool,apps_stopped:int}>
     */
    private function close(string $current, callable $select): array
    {
        $rates = Pricing::rates();
        $groups = [];

        foreach ($this->store->usage() as $appId => $row) {
            $appId = (string) $appId;
            if (!is_array($row)) {
                continue;
            }
            $period = (string) ($row['period'] ?? '');
            $owner = (string) ($row['owner_id'] ?? '');
            if ($period === '' || $owner === '' || !$select($period)) {
                continue;
            }
            if ($this->users->findById($owner) === null) {
                continue; // owner tidak dikenal → tidak ada yang bisa ditagih
            }

            $app = $this->apps->find($appId);
            $limits = $app !== null ? ResourceLimits::of($app) : [];
            $seconds = (int) ($row['seconds_pending'] ?? 0);

            $item = [
                'app_id' => $appId,
                'app_name' => (string) ($row['name'] ?? ($app['name'] ?? '')),
                'hours' => round($seconds / 3600, 4),
                'cpus' => self::effectiveCpus($limits),
                'memory_mb' => self::effectiveMemoryMb($limits),
                'hourly' => round(Pricing::hourlyCredits($limits, $rates), 4),
                'amount' => round((float) ($row['credits_pending'] ?? 0.0), 2),
            ];

            $key = $owner . "\0" . $period;
            $groups[$key] ??= ['user_id' => $owner, 'period' => $period, 'items' => [], 'rows' => []];
            $groups[$key]['items'][] = $item;
            // `delete` = app sudah hilang dari AppStore → hapus baris setelah ditagih.
            $groups[$key]['rows'][$appId] = ['delete' => $app === null];
        }

        if ($groups === []) {
            return [];
        }

        $result = [];
        $account = new CreditAccount($this->store, $this->users);

        $this->store->update(function (array &$data) use ($groups, $current, $account, &$result): void {
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

            foreach ($groups as $group) {
                $items = $group['items'];
                $amount = round(array_sum(array_map(
                    static fn (array $item): float => (float) $item['amount'],
                    $items
                )), 2);

                if ($amount > 0) {
                    $account->applyCharge(
                        $data,
                        $group['user_id'],
                        $amount,
                        $items,
                        $group['period'],
                        'Penagihan pemakaian ' . $group['period']
                    );
                }

                foreach ($group['rows'] as $appId => $meta) {
                    if ($meta['delete']) {
                        unset($usage[$appId]);
                        continue;
                    }
                    if (is_array($usage[$appId] ?? null)) {
                        $usage[$appId]['seconds_pending'] = 0;
                        $usage[$appId]['credits_pending'] = 0.0;
                        $usage[$appId]['period'] = $current;
                    }
                }

                $result[] = [
                    'user_id' => $group['user_id'],
                    'period' => $group['period'],
                    'amount' => $amount,
                    'items' => $items,
                    'stopped' => false,
                    'apps_stopped' => 0,
                ];
            }

            $data['usage'] = $usage;
        });

        $this->applyStopPolicy($result);

        return $result;
    }

    /**
     * Kebijakan `stop`: saldo owner negatif ⇒ hentikan app miliknya lewat
     * callback (bila ada). `block` (nilai lain) tidak menghentikan apa pun.
     *
     * @param array<int,array{user_id:string,stopped:bool,apps_stopped:int}> $result
     */
    private function applyStopPolicy(array &$result): void
    {
        if ($this->stopApps === null) {
            return;
        }
        if ((string) config('deploy.billing_payment_policy', 'stop') !== 'stop') {
            return;
        }

        $account = new CreditAccount($this->store, $this->users);
        foreach ($result as &$row) {
            if ($account->balance($row['user_id']) >= 0) {
                continue;
            }
            $apps = array_values(array_filter(
                $this->apps->ownedBy($row['user_id']),
                static fn (array $app): bool => (string) ($app['status'] ?? '') !== 'stopped'
            ));
            if ($apps === []) {
                continue;
            }
            ($this->stopApps)($row['user_id'], $apps);
            $row['stopped'] = true;
            $row['apps_stopped'] = count($apps);
        }
        unset($row);
    }

    /**
     * @param array<int|string,mixed> $limits
     */
    private static function effectiveCpus(array $limits): float
    {
        if ($limits === []) {
            return Pricing::defaultCpus();
        }
        $total = 0.0;
        foreach ($limits as $limit) {
            if (!is_array($limit)) {
                continue;
            }
            $total += isset($limit['cpus']) && is_numeric($limit['cpus'])
                ? (float) $limit['cpus']
                : Pricing::defaultCpus();
        }

        return $total;
    }

    /**
     * @param array<int|string,mixed> $limits
     */
    private static function effectiveMemoryMb(array $limits): int
    {
        if ($limits === []) {
            return Pricing::defaultMemoryMb();
        }
        $total = 0;
        foreach ($limits as $limit) {
            if (!is_array($limit)) {
                continue;
            }
            $total += isset($limit['memory_mb']) && is_numeric($limit['memory_mb'])
                ? (int) $limit['memory_mb']
                : Pricing::defaultMemoryMb();
        }

        return $total;
    }
}
