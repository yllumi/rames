#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Worker CLI billing (SPECS.md §7.12) — operasi manual & verifikasi penjadwal
 * billing. Dipilih `cli/` (bukan hanya timer) agar bisa diperiksa manual dan
 * dijalankan lewat `docker exec` bila dashboard sempat mati lama.
 *
 *   php cli/billing.php status                    # ringkasan saldo/usage/order (tanpa mutasi)
 *   php cli/billing.php sample                    # satu tick meteran saja
 *   php cli/billing.php tick                      # satu tick penuh (seperti proses timer)
 *   php cli/billing.php invoice [--period=YYYY-MM]# tagih periode tertunggak / periode tertentu
 *   php cli/billing.php expire                    # tandai order top-up pending kedaluwarsa
 *
 * Berjalan sebagai proses tepercaya (tanpa session). Log ke
 * runtime/logs/billing/cli.log. **Tidak pernah** menampilkan/menyimpan kredensial
 * gateway (`BILLING_DUITKU_API_KEY` dan sejenisnya) di keluaran maupun log.
 */

use app\library\Auth\UserStore;
use app\library\Billing\AppStopper;
use app\library\Billing\BillingRunner;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\Invoicer;
use app\library\Billing\Pricing;
use app\library\Billing\TopUpOrder;
use app\library\Billing\TopUpService;
use app\library\Billing\UsageMeter;
use app\library\Storage\AppStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$usage = <<<TXT
Usage:
  php cli/billing.php status
  php cli/billing.php sample
  php cli/billing.php tick
  php cli/billing.php invoice [--period=YYYY-MM]
  php cli/billing.php expire

TXT;

$mode = (string) ($argv[1] ?? '');
if (!in_array($mode, ['status', 'sample', 'tick', 'invoice', 'expire'], true)) {
    fwrite(STDERR, $usage);
    exit(1);
}

$logDirConfigured = trim((string) config('deploy.billing_log_path', ''));
$logDir = $logDirConfigured !== '' ? rtrim($logDirConfigured, '/') : runtime_path('logs/billing');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

/** @var callable(string):void $cliLog */
$cliLog = static function (string $message) use ($logDir): void {
    @file_put_contents(
        $logDir . '/cli.log',
        '[' . date('c') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
};

/** @var callable(string):void $out */
$out = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$store = new BillingStore();
$apps = new AppStore();
$users = new UserStore();

/**
 * Wiring top-up (lazy): hanya bila fitur aktif, kredensial terisi, dan kelas
 * modul top-up tersedia. Tanpa itu jalur gateway tidak pernah disentuh.
 */
$topUpConfigured = (bool) config('deploy.billing_topup_enabled', false)
    && trim((string) config('deploy.billing_duitku_merchant_code', '')) !== ''
    && trim((string) config('deploy.billing_duitku_api_key', '')) !== ''
    && class_exists(TopUpOrder::class)
    && class_exists(TopUpService::class);

/** @var callable|null fn(?string):int $expireOrders */
$expireOrders = $topUpConfigured
    ? static fn (?string $now = null): int => (new TopUpOrder())->expireStale($now)
    : null;

/** @var callable|null fn(int,?string):int $reconcileOrders */
$reconcileOrders = $topUpConfigured
    ? static function (int $max, ?string $now = null) use ($cliLog): int {
        if ($max <= 0) {
            return 0;
        }
        $done = 0;
        foreach ((new TopUpOrder())->allPending() as $key => $order) {
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
                $cliLog('konsiliasi order ' . $orderId . ' gagal: ' . $e->getMessage());
            }
        }

        return $done;
    }
    : null;

try {
    if ($mode === 'status') {
        $out('== Saldo kredit ==');
        $balances = (new CreditAccount($store, $users))->allBalances();
        $byId = [];
        foreach ($users->all() as $user) {
            $byId[(string) ($user['id'] ?? '')] = $user;
        }
        if ($balances === []) {
            $out('(belum ada saldo tercatat)');
        }
        foreach ($balances as $userId => $balance) {
            $user = $byId[$userId] ?? null;
            $out(sprintf(
                '%-20s %-8s %14s  %s',
                $user !== null ? (string) ($user['username'] ?? '') : '(user dihapus)',
                $user !== null ? $users->roleOf($user) : '-',
                Pricing::format($balance),
                (string) $userId
            ));
        }

        $out('');
        $out('== Pemakaian berjalan (belum tertagih) ==');
        $summary = (new UsageMeter($apps, $store, $users))->summary();
        if ($summary === []) {
            $out('(tidak ada pemakaian tercatat)');
        }
        $totals = [];
        foreach ($summary as $appId => $row) {
            $out(sprintf(
                '%-24s owner=%-20s %12s kredit (%d detik)',
                ($row['name'] ?? '') !== '' ? (string) $row['name'] : (string) $appId,
                (string) $row['owner_id'],
                Pricing::format((float) $row['credits_pending']),
                (int) $row['seconds_pending']
            ));
            $owner = (string) $row['owner_id'];
            $totals[$owner] = ($totals[$owner] ?? 0.0) + (float) $row['credits_pending'];
        }
        foreach ($totals as $owner => $total) {
            $out(sprintf('  total owner %-20s %12s kredit', (string) $owner, Pricing::format($total)));
        }

        $out('');
        $out('== Order top-up pending ==');
        if (!class_exists(TopUpOrder::class)) {
            $out('(modul top-up belum tersedia)');
        } else {
            $out('jumlah pending: ' . count((new TopUpOrder())->allPending()));
        }

        exit(0);
    }

    if ($mode === 'sample') {
        $interval = max(0, (int) config('deploy.billing_sample_seconds', 300));
        if ($interval <= 0) {
            $out('Meteran dimatikan (BILLING_SAMPLE_SECONDS=0) — tidak ada akrual.');
            exit(0);
        }
        $sampled = (new UsageMeter($apps, $store, $users))->sample($interval);
        $cliLog('sample interval=' . $interval . ' app=' . count($sampled));
        $out('Diakru: ' . count($sampled) . ' app (' . $interval . ' detik).');
        foreach ($sampled as $appId) {
            $out('  - ' . (string) $appId);
        }
        exit(0);
    }

    if ($mode === 'tick') {
        $runner = new BillingRunner(
            $store,
            $apps,
            $users,
            new AppStopper($apps),
            $reconcileOrders,
            $expireOrders,
            $cliLog
        );
        $summary = $runner->tick();
        $cliLog(sprintf(
            'tick sampled=%d expired=%d invoiced=%d reconciled=%d',
            (int) $summary['sampled'],
            (int) $summary['expired'],
            count((array) $summary['invoiced']),
            (int) $summary['reconciled']
        ));
        $out(sprintf(
            'Tick selesai: sampled=%d expired=%d invoiced=%d reconciled=%d',
            (int) $summary['sampled'],
            (int) $summary['expired'],
            count((array) $summary['invoiced']),
            (int) $summary['reconciled']
        ));
        foreach ((array) $summary['invoiced'] as $group) {
            $out(sprintf(
                '  tagih user=%s periode=%s jumlah=%s kredit app_dihentikan=%d',
                (string) ($group['user_id'] ?? ''),
                (string) ($group['period'] ?? ''),
                Pricing::format((float) ($group['amount'] ?? 0.0)),
                (int) ($group['apps_stopped'] ?? 0)
            ));
        }
        exit(0);
    }

    if ($mode === 'invoice') {
        $period = '';
        foreach (array_slice($argv, 2) as $arg) {
            if (str_starts_with($arg, '--period=')) {
                $period = substr($arg, strlen('--period='));
            }
        }

        $stopper = new AppStopper($apps);
        $invoicer = new Invoicer(
            $apps,
            $store,
            $users,
            static fn (string $userId, array $ownerApps = []): int => $stopper->stopOwnedBy($userId, $ownerApps)
        );

        $result = $period !== '' ? $invoicer->runNow($period) : $invoicer->runDue();
        $cliLog('invoice ' . ($period !== '' ? $period : 'due') . ' grup=' . count($result));
        if ($result === []) {
            $out('Tidak ada periode tertunggak untuk ditagih.');
            exit(0);
        }
        foreach ($result as $group) {
            $out(sprintf(
                'Tagih user=%s periode=%s jumlah=%s kredit app_dihentikan=%d',
                (string) ($group['user_id'] ?? ''),
                (string) ($group['period'] ?? ''),
                Pricing::format((float) ($group['amount'] ?? 0.0)),
                (int) ($group['apps_stopped'] ?? 0)
            ));
        }
        exit(0);
    }

    // expire
    if (!class_exists(TopUpOrder::class)) {
        throw new RuntimeException('Modul top-up belum tersedia — perintah expire tidak bisa dijalankan.');
    }
    $expired = (new TopUpOrder())->expireStale();
    $cliLog('expire jumlah=' . $expired);
    $out('Order kedaluwarsa ditandai: ' . $expired . '.');
    exit(0);
} catch (Throwable $e) {
    $cliLog($mode . ' GAGAL: ' . $e->getMessage());
    fwrite(STDERR, $mode . ' GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
