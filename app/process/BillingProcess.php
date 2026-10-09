<?php
declare(strict_types=1);

namespace app\process;

use app\library\Billing\AppStopper;
use app\library\Billing\BillingRunner;
use app\library\Billing\TopUpOrder;
use app\library\Billing\TopUpService;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Penjadwal billing (SPECS.md §7.12) — satu tick = kedaluwarsa order top-up,
 * akrual meteran pemakaian, penagihan periode (tanggal `BILLING_INVOICE_DAY`),
 * dan konsiliasi order pending ke gateway.
 *
 * Pola mengikuti `UpdateCheckProcess`: jeda pertama (`FIRST_DELAY`) lalu timer
 * berulang tiap `BILLING_SAMPLE_SECONDS`. `BILLING_ENABLED=false` atau
 * `BILLING_SAMPLE_SECONDS=0` → proses tidak memasang timer sama sekali.
 *
 * Proses persistent: SEMUA `Throwable` ditangkap & dicatat ke
 * `runtime/logs/billing/{Y-m-d}.log` — worker tidak boleh mati karena error
 * (aturan keras repo). Wiring callable top-up dilakukan **lazy** di dalam task
 * dan hanya bila `BILLING_TOPUP_ENABLED` + merchant code + api key terisi.
 */
class BillingProcess
{
    /** Jeda tick pertama setelah boot (detik) — biar tidak berebut saat start. */
    private const FIRST_DELAY = 30;

    public function onWorkerStart(Worker $worker): void
    {
        if (!(bool) config('deploy.billing_enabled', true)) {
            return;
        }
        $interval = $this->meterInterval();
        if ($interval <= 0) {
            return; // 0 = meteran dimatikan → tanpa timer (tanpa akrual/pemakaian)
        }

        $task = function (): void {
            try {
                (new BillingRunner(
                    null,
                    null,
                    null,
                    new AppStopper(),
                    $this->reconcileOrders(),
                    $this->expireOrders(),
                    function (string $message): void {
                        $this->log($message);
                    }
                ))->tick();
            } catch (Throwable $e) {
                // Proses persistent: kesalahan apa pun tidak boleh mematikan worker.
                $this->log('tick billing gagal: ' . $e->getMessage());
            }
        };

        Timer::add(self::FIRST_DELAY, $task, [], false);
        Timer::add($interval, $task);
    }

    private function meterInterval(): int
    {
        return max(0, (int) config('deploy.billing_sample_seconds', 300));
    }

    /**
     * Callable kedaluwarsa order — hanya bila fitur top-up terkonfigurasi.
     *
     * @return callable|null fn(?string $now): int
     */
    private function expireOrders(): ?callable
    {
        if (!$this->topUpConfigured()) {
            return null;
        }

        return static fn (?string $now = null): int => (new TopUpOrder())->expireStale($now);
    }

    /**
     * Callable konsiliasi order pending — satu order gagal dicatat & dilewati
     * (tidak menggagalkan sisa tick; proses tetap hidup).
     *
     * @return callable|null fn(int $max, ?string $now): int
     */
    private function reconcileOrders(): ?callable
    {
        if (!$this->topUpConfigured()) {
            return null;
        }

        return function (int $max, ?string $now = null): int {
            if ($max <= 0) {
                return 0;
            }
            $pending = (new TopUpOrder())->allPending();
            $done = 0;
            foreach ($pending as $key => $order) {
                if ($done >= $max) {
                    break;
                }
                $orderId = is_array($order) ? (string) ($order['id'] ?? '') : (string) $order;
                if ($orderId === '' && is_string($key)) {
                    $orderId = $key;
                }
                if ($orderId === '') {
                    continue;
                }
                try {
                    (new TopUpService())->reconcile($orderId, $now);
                    $done++;
                } catch (Throwable $e) {
                    $this->log('konsiliasi order ' . $orderId . ' gagal: ' . $e->getMessage());
                }
            }

            return $done;
        };
    }

    /**
     * Top-up hanya boleh disentuh bila fitur aktif DAN kredensial terisi —
     * tanpa kredensial, jangan pernah memanggil gateway.
     */
    private function topUpConfigured(): bool
    {
        return (bool) config('deploy.billing_topup_enabled', false)
            && trim((string) config('deploy.billing_duitku_merchant_code', '')) !== ''
            && trim((string) config('deploy.billing_duitku_api_key', '')) !== '';
    }

    private function log(string $message): void
    {
        $dir = $this->logDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $dir . '/' . date('Y-m-d') . '.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function logDir(): string
    {
        $configured = trim((string) config('deploy.billing_log_path', ''));

        return $configured !== '' ? rtrim($configured, '/') : runtime_path('logs/billing');
    }
}
