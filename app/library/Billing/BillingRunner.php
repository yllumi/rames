<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Auth\UserStore;
use app\library\Storage\AppStore;

/**
 * Orkestrator satu tick penjadwal billing (SPECS.md §7.12).
 *
 * Urutan satu tick (kunci hasil = kontrak tetap, dipakai proses timer & CLI):
 *   1. `expired`   — tandai order top-up kedaluwarsa (bila top-up aktif);
 *   2. `sampled`   — akrual meteran `billing_sample_seconds` detik;
 *   3. `invoiced`  — penagihan periode tertunggak (`Invoicer::runDue()`) + auto-stop;
 *   4. `reconciled`— konsiliasi order pending ke gateway (bila top-up aktif).
 *
 * Kelas ini **stateless** (tanpa properti statik / cache lintas-request) dan
 * **tidak** menyentuh Docker maupun jaringan secara langsung: auto-stop
 * diserahkan ke `AppStopper`, sedangkan konsiliasi/kedaluwarsa order diserahkan
 * ke callable yang di-inject pemanggil (proses timer / CLI). Dengan begitu
 * seluruh tick dapat diuji tanpa daemon Docker dan tanpa jaringan.
 *
 * `tick()` boleh melempar exception — pemanggil (proses/CLI) yang menangkap &
 * mencatatnya (proses persistent tidak boleh mati karena error).
 */
class BillingRunner
{
    private BillingStore $store;
    private AppStore $apps;
    private UserStore $users;
    private ?AppStopper $stopper;

    /** @var callable|null fn(int $max, ?string $now): int */
    private $reconcileOrders;

    /** @var callable|null fn(?string $now): int */
    private $expireOrders;

    /** @var callable|null fn(string $message): void */
    private $logger;

    public function __construct(
        ?BillingStore $store = null,
        ?AppStore $apps = null,
        ?UserStore $users = null,
        ?AppStopper $stopper = null,
        ?callable $reconcileOrders = null,
        ?callable $expireOrders = null,
        ?callable $logger = null,
    ) {
        $this->store = $store ?? new BillingStore();
        $this->apps = $apps ?? new AppStore();
        $this->users = $users ?? new UserStore();
        $this->stopper = $stopper;
        $this->reconcileOrders = $reconcileOrders;
        $this->expireOrders = $expireOrders;
        $this->logger = $logger;
    }

    /**
     * Interval meteran (detik) dari `BILLING_SAMPLE_SECONDS`; `0` = meteran mati.
     */
    public function meterInterval(): int
    {
        return max(0, (int) config('deploy.billing_sample_seconds', 300));
    }

    /**
     * Jalankan satu tick penuh.
     *
     * @return array{sampled:int,expired:int,invoiced:array,reconciled:int}
     */
    public function tick(?string $now = null): array
    {
        $summary = ['sampled' => 0, 'expired' => 0, 'invoiced' => [], 'reconciled' => 0];

        // Billing mati total: kembalikan bentuk yang sama TANPA efek samping.
        if (!(bool) config('deploy.billing_enabled', true)) {
            return $summary;
        }

        // 1. Kedaluwarsa order top-up (hanya bila fitur top-up aktif).
        if ($this->expireOrders !== null && $this->topUpEnabled()) {
            $summary['expired'] = (int) ($this->expireOrders)($now);
        }

        // 2. Akrual meteran (dilewati bila interval ≤ 0).
        $interval = $this->meterInterval();
        if ($interval > 0) {
            $sampled = (new UsageMeter($this->apps, $this->store, $this->users))->sample($interval, $now);
            $summary['sampled'] = count($sampled);
        }

        // 3. Penagihan periode tertunggak + auto-stop (kebijakan di Invoicer).
        //    Hanya dijalankan pada/atau setelah `BILLING_INVOICE_DAY` (default 1 =
        //    awal bulan). Bila dashboard sempat mati, tanggal berikutnya tetap
        //    menagih (catch-up) karena periode tertunggak tetap terpilih.
        $stopper = $this->stopper ?? new AppStopper($this->apps);
        $invoicer = new Invoicer(
            $this->apps,
            $this->store,
            $this->users,
            static function (string $userId, array $apps = []) use ($stopper): int {
                return $stopper->stopOwnedBy($userId, $apps);
            }
        );
        if ($this->invoiceDayReached($now)) {
            $summary['invoiced'] = $invoicer->runDue($now);
        }

        // 4. Konsiliasi order pending ke gateway (hanya bila fitur top-up aktif).
        if ($this->reconcileOrders !== null && $this->topUpEnabled()) {
            $max = max(0, (int) config('deploy.billing_duitku_status_max_per_tick', 20));
            $summary['reconciled'] = (int) ($this->reconcileOrders)($max, $now);
        }

        if ($summary['expired'] > 0 || $summary['sampled'] > 0
            || $summary['invoiced'] !== [] || $summary['reconciled'] > 0) {
            $this->log(sprintf(
                'tick sampled=%d expired=%d invoiced=%d reconciled=%d',
                $summary['sampled'],
                $summary['expired'],
                count($summary['invoiced']),
                $summary['reconciled']
            ));
        }

        return $summary;
    }

    private function topUpEnabled(): bool
    {
        return (bool) config('deploy.billing_topup_enabled', false);
    }

    /**
     * Sudah masuk tanggal penagihan? `BILLING_INVOICE_DAY` dijepit ke 1–28
     * (di atas 28 bisa melewati bulan pendek). Default 1 = awal bulan berikutnya.
     */
    private function invoiceDayReached(?string $now = null): bool
    {
        $day = (int) config('deploy.billing_invoice_day', 1);
        $day = max(1, min(28, $day));

        return BillingPeriod::dayOfMonth($now) >= $day;
    }

    private function log(string $message): void
    {
        if ($this->logger === null) {
            return;
        }
        ($this->logger)($message);
    }
}
