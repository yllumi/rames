<?php
declare(strict_types=1);

namespace app\library\Billing;

use InvalidArgumentException;
use RuntimeException;

/**
 * Order top-up Duitku (state di `database/billing.json` → `orders`).
 *
 * Semua mutasi lewat SATU `BillingStore::update()` (atomik + flock) sehingga
 * transisi status + (opsional) penulisan saldo berada dalam satu transaksi.
 *
 * Order = array dengan kunci:
 *   id, user_id, amount_idr (int), credits (float 2 desimal), method, status
 *   (pending|paid|failed|expired), created_at, expires_at?, updated_at?,
 *   reference?, payment_url?, va_number?, qr_string?, last_check_at?, paid_at?,
 *   failed_reason?
 *
 * Nominal rupiah selalu integer; kredit selalu `round($x, 2)`.
 */
class TopUpOrder
{
    private BillingStore $store;

    public function __construct(?BillingStore $store = null)
    {
        $this->store = $store ?? new BillingStore();
    }

    /**
     * Buat order `pending` baru.
     *
     * Sebelum membuat: order pending yang sudah lewat `expires_at` ditandai
     * `expired`, lalu jumlah order `pending` milik user dibatasi
     * `BILLING_TOPUP_MAX_PENDING`.
     *
     * @return array order yang dibuat
     * @throws InvalidArgumentException nominal/metode tidak valid
     * @throws RuntimeException bila order pending user sudah mencapai batas
     */
    public function create(string $userId, int $amountIdr, string $method): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new InvalidArgumentException('User pembuat order top-up tidak valid.');
        }

        $min = (int) config('deploy.billing_topup_min_idr', 10000);
        $max = (int) config('deploy.billing_topup_max_idr', 5000000);
        if ($amountIdr < $min) {
            throw new InvalidArgumentException('Nominal top-up minimal Rp' . number_format($min, 0, ',', '.') . '.');
        }
        if ($max > 0 && $amountIdr > $max) {
            throw new InvalidArgumentException('Nominal top-up maksimal Rp' . number_format($max, 0, ',', '.') . '.');
        }

        $perCredit = (float) config('deploy.billing_topup_idr_per_credit', 1.0);
        if (!($perCredit > 0)) {
            throw new RuntimeException('Konfigurasi tarif kredit (BILLING_TOPUP_IDR_PER_CREDIT) tidak valid.');
        }

        $method = strtoupper(trim($method));
        if (preg_match('/^[A-Z0-9]{2}$/', $method) !== 1) {
            throw new InvalidArgumentException('Metode pembayaran tidak valid.');
        }

        // Bersihkan order kedaluwarsa lebih dulu supaya tidak ikut menghitung kuota.
        $this->expireStale();

        $maxPending = (int) config('deploy.billing_topup_max_pending', 3);
        $expiryMinutes = (int) config('deploy.billing_topup_expiry_minutes', 0);
        $credits = round($amountIdr / $perCredit, 2);
        $now = date('c');

        $order = [
            'id' => 'RM-' . bin2hex(random_bytes(8)),
            'user_id' => $userId,
            'amount_idr' => $amountIdr,
            'credits' => $credits,
            'method' => $method,
            'status' => 'pending',
            'created_at' => $now,
        ];
        // 0 = fitur expiry lokal mati (Duitku memakai default kanal).
        if ($expiryMinutes > 0) {
            $order['expires_at'] = date('c', time() + $expiryMinutes * 60);
        }

        $created = null;
        $this->store->update(function (array &$data) use ($userId, $order, $maxPending, &$created): void {
            $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];

            if ($maxPending > 0) {
                $pending = 0;
                foreach ($orders as $existing) {
                    if (is_array($existing)
                        && ($existing['user_id'] ?? '') === $userId
                        && ($existing['status'] ?? '') === 'pending') {
                        $pending++;
                    }
                }
                if ($pending >= $maxPending) {
                    throw new RuntimeException(
                        'Selesaikan/batalkan order yang masih pending sebelum membuat order top-up baru '
                        . '(maksimal ' . $maxPending . ' order pending).'
                    );
                }
            }

            $orders[$order['id']] = $order;
            $data['orders'] = $orders;
            $created = $order;
        });

        return $created ?? $order;
    }

    /**
     * Lampirkan detail pembayaran hasil inquiry (tanpa field rahasia).
     *
     * @param array<string,mixed> $inquiryResponse respons `inquiry()`
     * @return array order terbaru
     */
    public function attachPayment(string $orderId, array $inquiryResponse, ?string $now = null): array
    {
        $now = $now !== null && trim($now) !== '' ? $now : date('c');
        $result = [];

        $this->store->update(function (array &$data) use ($orderId, $inquiryResponse, $now, &$result): void {
            $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
            if (!isset($orders[$orderId]) || !is_array($orders[$orderId])) {
                throw new RuntimeException('Order "' . $orderId . '" tidak ditemukan.');
            }
            $order = $orders[$orderId];

            $map = [
                'reference' => 'reference',
                'payment_url' => 'paymentUrl',
                'va_number' => 'vaNumber',
                'qr_string' => 'qrString',
            ];
            foreach ($map as $field => $source) {
                $value = $inquiryResponse[$source] ?? null;
                if ($value !== null && trim((string) $value) !== '') {
                    $order[$field] = (string) $value;
                }
            }
            $order['updated_at'] = $now;

            $orders[$orderId] = $order;
            $data['orders'] = $orders;
            $result = $order;
        });

        return $result;
    }

    /**
     * @return array|null order temuan (lintas user — hanya untuk jalur internal/callback)
     */
    public function find(string $orderId): ?array
    {
        $order = $this->store->orders()[$orderId] ?? null;

        return is_array($order) ? $order : null;
    }

    /**
     * Order milik user tertentu. **Wajib** dipakai pada jalur yang bisa diakses
     * user (UI) — jangan pernah memakai `find()` di sana.
     */
    public function findForUser(string $orderId, string $userId): ?array
    {
        $order = $this->find($orderId);
        if ($order === null || (string) ($order['user_id'] ?? '') !== trim($userId)) {
            return null;
        }

        return $order;
    }

    /**
     * @return array<int,array> order pending milik user
     */
    public function pendingForUser(string $userId): array
    {
        $userId = trim($userId);
        $out = [];
        foreach ($this->store->orders() as $order) {
            if (is_array($order) && ($order['status'] ?? '') === 'pending' && ($order['user_id'] ?? '') === $userId) {
                $out[] = $order;
            }
        }

        return $out;
    }

    /**
     * @return array<int,array> semua order pending
     */
    public function allPending(): array
    {
        $out = [];
        foreach ($this->store->orders() as $order) {
            if (is_array($order) && ($order['status'] ?? '') === 'pending') {
                $out[] = $order;
            }
        }

        return $out;
    }

    /**
     * Tandai order `failed` (hanya dari `pending`; order `paid` tidak diturunkan).
     *
     * @return array order terbaru
     */
    public function markFailed(string $orderId, string $reason = ''): array
    {
        return $this->transition($orderId, 'failed', static function (array $order) use ($reason): array {
            $order['failed_at'] = date('c');
            if (trim($reason) !== '') {
                $order['failed_reason'] = mb_substr(trim($reason), 0, 255);
            }

            return $order;
        });
    }

    /**
     * Tandai order `expired` (hanya dari `pending`).
     *
     * @return array order terbaru
     */
    public function markExpired(string $orderId): array
    {
        return $this->transition($orderId, 'expired');
    }

    /**
     * Tandai `expired` semua order `pending` yang `expires_at` sudah lewat.
     * `0` = tidak ada yang diubah (atau fitur expiry lokal mati).
     */
    public function expireStale(?string $now = null): int
    {
        $now = $now !== null && trim($now) !== '' ? $now : date('c');
        $nowTs = strtotime($now) ?: time();

        // Hindari menulis berkas bila memang tidak ada yang kedaluwarsa.
        $hasStale = false;
        foreach ($this->store->orders() as $order) {
            if (is_array($order)
                && ($order['status'] ?? '') === 'pending'
                && is_string($order['expires_at'] ?? null)
                && ($ts = strtotime((string) $order['expires_at'])) !== false
                && $ts <= $nowTs) {
                $hasStale = true;
                break;
            }
        }
        if (!$hasStale) {
            return 0;
        }

        $count = 0;
        $this->store->update(function (array &$data) use ($nowTs, $now, &$count): void {
            $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
            foreach ($orders as $id => $order) {
                if (!is_array($order) || ($order['status'] ?? '') !== 'pending') {
                    continue;
                }
                $expiresAt = $order['expires_at'] ?? null;
                if (!is_string($expiresAt) || trim($expiresAt) === '') {
                    continue;
                }
                $ts = strtotime($expiresAt);
                if ($ts !== false && $ts <= $nowTs) {
                    $order['status'] = 'expired';
                    $order['updated_at'] = $now;
                    $orders[$id] = $order;
                    $count++;
                }
            }
            $data['orders'] = $orders;
        });

        return $count;
    }

    /**
     * Settle order ke `paid` — transisi `pending → paid`, **idempoten**
     * (memanggil dua kali = no-op). Order yang sudah `paid` tidak diubah.
     *
     * Callback Duitku yang tervalidasi tetapi datang **terlambat** (order sudah
     * ditandai `expired` lokal) tetap disettle selama belum `paid` — uangnya nyata
     * (SPECS/plan §5.7: "selama order belum paid").
     *
     * `$onTransition`, bila diberikan, dipanggil **di dalam transaksi** yang sama
     * (menerima order + `array &$data`) sehingga penulisan saldo/ledger bisa
     * atomik dengan perubahan status order (§5.7 #3). Dipanggil HANYA saat
     * transisi benar-benar terjadi.
     *
     * @param callable|null $onTransition function(array $order, array &$data): void
     * @return array order terbaru
     */
    public function settle(
        string $orderId,
        string $reference = '',
        ?string $paidAt = null,
        ?callable $onTransition = null,
    ): array {
        $paidAt = $paidAt !== null && trim($paidAt) !== '' ? $paidAt : date('c');
        $result = [];

        $this->store->update(function (array &$data) use ($orderId, $reference, $paidAt, $onTransition, &$result): void {
            $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
            if (!isset($orders[$orderId]) || !is_array($orders[$orderId])) {
                throw new RuntimeException('Order "' . $orderId . '" tidak ditemukan.');
            }
            $order = $orders[$orderId];

            // Idempoten: sudah paid ⇒ no-op (return apa adanya).
            if (($order['status'] ?? '') === 'paid') {
                $result = $order;
                return;
            }

            $order['status'] = 'paid';
            $order['paid_at'] = $paidAt;
            if (trim($reference) !== '') {
                $order['reference'] = trim($reference);
            }
            $order['updated_at'] = $paidAt;

            $orders[$orderId] = $order;
            $data['orders'] = $orders;
            $result = $order;

            if ($onTransition !== null) {
                $onTransition($order, $data);
            }
        });

        return $result;
    }

    /**
     * Catat waktu pengecekan status terakhir (throttle `transactionStatus`).
     */
    public function touchChecked(string $orderId, ?string $now = null): void
    {
        $now = $now !== null && trim($now) !== '' ? $now : date('c');

        $this->store->update(function (array &$data) use ($orderId, $now): void {
            $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
            if (!isset($orders[$orderId]) || !is_array($orders[$orderId])) {
                return;
            }
            $orders[$orderId]['last_check_at'] = $now;
            $data['orders'] = $orders;
        });
    }

    /**
     * Transisi status generik dari `pending` ke status target.
     *
     * @param callable|null $mutator function(array $order): array
     * @return array order terbaru
     */
    private function transition(string $orderId, string $target, ?callable $mutator = null): array
    {
        $result = [];
        $this->store->update(function (array &$data) use ($orderId, $target, $mutator, &$result): void {
            $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
            if (!isset($orders[$orderId]) || !is_array($orders[$orderId])) {
                throw new RuntimeException('Order "' . $orderId . '" tidak ditemukan.');
            }
            $order = $orders[$orderId];

            if (($order['status'] ?? '') === 'pending') {
                $order['status'] = $target;
                $order['updated_at'] = date('c');
                if ($mutator !== null) {
                    $order = $mutator($order);
                }
                $orders[$orderId] = $order;
                $data['orders'] = $orders;
            }

            $result = $order;
        });

        return $result;
    }
}
