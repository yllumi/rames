<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Auth\UserStore;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orkestrasi top-up kredit via Duitku (jalur B, §5.7).
 *
 * Tanggung jawab: validasi input, membangun payload inquiry lengkap, mencatat
 * order, memproses callback (idempoten) dan re-konsiliasi status.
 *
 * **Idempotensi** adalah inti: saldo HANYA ditambah pada transisi order
 * `belum paid → paid`. Transisi + penulisan saldo/ledger terjadi dalam SATU
 * `BillingStore::update()` (lewat callback `TopUpOrder::settle()`), sehingga
 * tidak ada jendela crash antara "order jadi paid" dan "saldo bertambah".
 *
 * Tanpa state statik/lintas-request; seluruh dependensi di-inject.
 */
class TopUpService
{
    private CreditAccount $accounts;
    private DuitkuClient $client;
    private UserStore $users;
    private TopUpOrder $orders;

    public function __construct(
        ?BillingStore $store = null,
        ?CreditAccount $accounts = null,
        ?DuitkuClient $client = null,
        ?UserStore $users = null,
    ) {
        // Satu store dipakai bersama agar order, saldo & ledger berada di berkas
        // yang sama (default: database/billing.json).
        $store ??= new BillingStore();
        $this->orders = new TopUpOrder($store);
        $this->accounts = $accounts ?? new CreditAccount($store, $users);
        $this->client = $client ?? new DuitkuClient();
        $this->users = $users ?? new UserStore();
    }

    /**
     * Saldo kredit user (convenience untuk pemanggil UI).
     */
    public function balance(string $userId): float
    {
        return $this->accounts->balance($userId);
    }

    /**
     * Riwayat ledger user, terbaru dulu (convenience untuk pemanggil UI).
     *
     * @return array<int,array>
     */
    public function ledger(string $userId, int $limit = 20): array
    {
        return $this->accounts->ledger($userId, $limit);
    }

    /**
     * Kredit yang diperoleh untuk nominal rupiah tertentu (2 desimal).
     */
    public function creditsFor(int $amountIdr): float
    {
        $perCredit = (float) config('deploy.billing_topup_idr_per_credit', 10.0);
        if (!($perCredit > 0)) {
            throw new RuntimeException('Konfigurasi tarif kredit (BILLING_TOPUP_IDR_PER_CREDIT) tidak valid.');
        }

        return round($amountIdr / $perCredit, 2);
    }

    /**
     * Ubah username (boleh sampai 64 karakter) menjadi `customerVaName` yang
     * aman: alfanumerik + spasi, dipotong 20 karakter, fallback `'Rames User'`.
     */
    public function customerVaName(string $username): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $username) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        $clean = trim(mb_substr($clean, 0, 20));

        return $clean !== '' ? $clean : 'Rames User';
    }

    /**
     * Mulai top-up: buat order lalu inquiry ke Duitku.
     *
     * @return array{order:array,payment_url:string}
     * @throws RuntimeException email user tidak valid / user tidak ada / gateway belum dikonfigurasi
     * @throws InvalidArgumentException nominal/metode tidak valid
     * @throws DuitkuError inquiry gagal (order sudah ditandai `failed`)
     */
    public function start(string $userId, int $amountIdr, string $method): array
    {
        $user = $this->users->findById(trim($userId));
        if ($user === null) {
            throw new RuntimeException('User tidak ditemukan.');
        }

        $email = $this->requireEmail($user);

        $method = strtoupper(trim($method));
        if (!$this->client->isAllowedMethod($method)) {
            throw new InvalidArgumentException('Metode pembayaran tidak tersedia.');
        }

        $order = $this->orders->create((string) $user['id'], $amountIdr, $method);

        $payload = $this->buildInquiryPayload($user, $order, $email);

        try {
            $response = $this->client->inquiry($payload);
        } catch (DuitkuError $e) {
            // Jangan tinggalkan order pending yatim saat inquiry gagal.
            $this->orders->markFailed((string) $order['id'], $e->getMessage());
            throw $e;
        }

        $order = $this->orders->attachPayment((string) $order['id'], $response);

        return [
            'order' => $order,
            'payment_url' => (string) ($order['payment_url'] ?? ''),
        ];
    }

    /**
     * Proses callback Duitku (`x-www-form-urlencoded`). **Tidak melempar** untuk
     * input tidak valid — mengembalikan `{ok:false, reason:'...'}`.
     *
     * Urutan verifikasi: (1) merchantCode cocok, (2) signature HMAC valid,
     * (3) order ada, (4) amount sama persis, (5) resultCode `00` → settle+kredit
     * (idempoten), `01` → failed.
     *
     * @param array<string,mixed> $payload
     * @return array{ok:bool,reason?:string,status?:string,order_id?:string,user_id?:string,credits?:float}
     */
    public function handleCallback(array $payload): array
    {
        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        $merchantCode = trim((string) ($payload['merchantCode'] ?? ''));
        if ($merchantCode === '' || $merchantCode !== $this->client->merchantCode()) {
            return ['ok' => false, 'reason' => 'merchant_code_mismatch'];
        }

        $orderId = trim((string) ($payload['merchantOrderId'] ?? ''));
        if ($orderId === '') {
            return ['ok' => false, 'reason' => 'missing_order_id'];
        }

        // Signature dihitung dari nilai `amount` persis seperti yang dikirim Duitku.
        $amount = $payload['amount'] ?? '';
        $signature = (string) ($payload['signature'] ?? '');
        if (!DuitkuSignature::verifyCallback($merchantCode, $amount, $orderId, $signature, $this->client->apiKey())) {
            return ['ok' => false, 'reason' => 'invalid_signature'];
        }

        $order = $this->orders->find($orderId);
        if ($order === null) {
            return ['ok' => false, 'reason' => 'unknown_order'];
        }

        if (!$this->amountMatches($amount, (int) $order['amount_idr'])) {
            return ['ok' => false, 'reason' => 'amount_mismatch'];
        }

        $resultCode = trim((string) ($payload['resultCode'] ?? ''));
        $reference = trim((string) ($payload['reference'] ?? ''));

        if ($resultCode === '00') {
            $credited = false;
            $order = $this->settleAndCredit($orderId, $reference, null, $credited);

            return [
                'ok' => true,
                'status' => $credited ? 'paid' : 'ignored',
                'order_id' => (string) $order['id'],
                'user_id' => (string) $order['user_id'],
                'credits' => (float) $order['credits'],
            ];
        }

        if ($resultCode === '01') {
            // Hanya order pending yang diturunkan ke failed; selain itu no-op.
            if (($order['status'] ?? '') !== 'pending') {
                return [
                    'ok' => true,
                    'status' => 'ignored',
                    'order_id' => (string) $order['id'],
                    'user_id' => (string) $order['user_id'],
                    'credits' => (float) $order['credits'],
                ];
            }
            $order = $this->orders->markFailed($orderId, 'Duitku: resultCode 01');

            return [
                'ok' => true,
                'status' => 'failed',
                'order_id' => (string) $order['id'],
                'user_id' => (string) $order['user_id'],
                'credits' => (float) $order['credits'],
            ];
        }

        return ['ok' => false, 'reason' => 'unknown_result_code'];
    }

    /**
     * Re-konsiliasi satu order via `transactionStatus` (dipakai returnUrl/worker).
     *
     * Menghormati throttle `BILLING_DUITKU_STATUS_MIN_INTERVAL` per order
     * (`last_check_at`) — **tanpa** loop/poll agresif. `statusCode` `00` → settle +
     * kredit (idempoten, jalur sama dengan callback); `02` → failed. Error
     * jaringan/HTTP ditelan (kembalikan `checked=false`) agar tidak mematikan worker.
     *
     * @return array order terbaru + `{checked:bool, changed:bool}`
     */
    public function reconcile(string $orderId, ?string $now = null): array
    {
        $now = $now !== null && trim($now) !== '' ? $now : date('c');

        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException('Order "' . $orderId . '" tidak ditemukan.');
        }

        // Hanya order pending yang perlu dicek.
        if (($order['status'] ?? '') !== 'pending') {
            return $order + ['checked' => false, 'changed' => false];
        }

        $minInterval = (int) config('deploy.billing_duitku_status_min_interval', 900);
        $lastCheck = (string) ($order['last_check_at'] ?? '');
        if ($minInterval > 0 && $lastCheck !== '') {
            $lastTs = strtotime($lastCheck);
            $nowTs = strtotime($now);
            if ($lastTs !== false && $nowTs !== false && ($nowTs - $lastTs) < $minInterval) {
                return $order + ['checked' => false, 'changed' => false];
            }
        }

        try {
            $response = $this->client->transactionStatus($orderId);
        } catch (DuitkuError) {
            return $order + ['checked' => false, 'changed' => false];
        }

        $this->orders->touchChecked($orderId, $now);

        $statusCode = (string) ($response['statusCode'] ?? '');
        $changed = false;

        if ($statusCode === '00') {
            $credited = false;
            $order = $this->settleAndCredit($orderId, (string) ($response['reference'] ?? ''), $now, $credited);
            $changed = $credited;
        } elseif ($statusCode === '02') {
            $before = (string) ($order['status'] ?? '');
            $order = $this->orders->markFailed($orderId, 'Duitku: transactionStatus canceled/expired');
            $changed = $before !== (string) ($order['status'] ?? '');
        }

        return $order + ['checked' => true, 'changed' => $changed];
    }

    /**
     * Order milik user (jalur UI — jangan pakai `find()`).
     */
    public function findForUser(string $orderId, string $userId): ?array
    {
        return $this->orders->findForUser($orderId, $userId);
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Settle `pending → paid` + tambah saldo/ledger secara ATOMIK (satu update).
     * Kredit hanya terjadi sekali; `$credited` di-set true hanya saat transisi.
     *
     * @param bool $credited di-set true bila transisi & kredit benar-benar terjadi
     * @return array order terbaru
     */
    private function settleAndCredit(string $orderId, string $reference, ?string $paidAt, bool &$credited): array
    {
        $credited = false;

        return $this->orders->settle(
            $orderId,
            $reference,
            $paidAt,
            function (array $order, array &$data) use (&$credited): void {
                $this->creditTopUp($data, $order, (string) ($order['reference'] ?? ''));
                $credited = true;
            }
        );
    }

    /**
     * Tambahkan saldo + entri ledger `topup` ke struktur billing.json yang sudah
     * dibuka pemanggil (satu transaksi). Bentuk entri **identik** dengan
     * `CreditAccount::deposit(..., 'topup', $reference)` — sengaja ditulis inline
     * agar transisi order + saldo + ledger berada dalam satu `JsonStore::update()`
     * (§5.7 #3); `CreditAccount` tidak mengekspos helper `applyDeposit(&$data)`.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $order
     */
    private function creditTopUp(array &$data, array $order, string $reference): void
    {
        $userId = (string) ($order['user_id'] ?? '');
        $credits = round((float) ($order['credits'] ?? 0.0), 2);
        if ($userId === '') {
            throw new RuntimeException('Order top-up tidak memiliki user.');
        }
        if ($credits <= 0) {
            throw new RuntimeException('Order top-up tidak memiliki kredit.');
        }

        $users = is_array($data['users'] ?? null) ? $data['users'] : [];
        $current = is_array($users[$userId] ?? null) ? $users[$userId] : [];
        $balance = is_numeric($current['balance'] ?? null) ? (float) $current['balance'] : 0.0;
        $at = date('c');

        $entry = [
            'type' => 'topup',
            'amount' => $credits,
            'by' => $userId,
            'note' => 'Top-up Duitku',
            'id' => bin2hex(random_bytes(8)),
            'at' => $at,
            'balance_after' => round($balance + $credits, 2),
        ];
        if (trim($reference) !== '') {
            $entry['reference'] = trim($reference);
        }

        $ledger = is_array($current['ledger'] ?? null) ? array_values($current['ledger']) : [];
        $ledger[] = $entry;

        $users[$userId] = [
            'balance' => $entry['balance_after'],
            'updated_at' => $at,
            'ledger' => $ledger,
        ];
        $data['users'] = $users;
    }

    /**
     * Email wajib valid untuk inquiry Duitku (field `email` user, ditambahkan
     * misi lain). Defensif bila field belum ada / kosong / tidak valid / > 50.
     *
     * @param array<string,mixed> $user
     */
    private function requireEmail(array $user): string
    {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || strlen($email) > 50 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(
                'Email user belum diisi dengan benar. Lengkapi email di halaman pengguna sebelum melakukan top-up.'
            );
        }

        return $email;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function buildInquiryPayload(array $user, array $order, string $email): array
    {
        $merchantCode = $this->client->merchantCode();
        $orderId = (string) $order['id'];
        $amount = (int) $order['amount_idr'];

        $payload = [
            'merchantCode' => $merchantCode,
            'paymentAmount' => $amount,
            'merchantOrderId' => $orderId,
            'productDetails' => mb_substr('Top-up kredit ' . $orderId, 0, 255),
            'email' => $email,
            'paymentMethod' => (string) $order['method'],
            'customerVaName' => $this->customerVaName((string) ($user['username'] ?? '')),
            'returnUrl' => $this->client->returnUrl(),
            'callbackUrl' => $this->client->callbackUrl(),
            // Info user (bukan PII sensitif) — dipakai Duitku untuk tampilan.
            'merchantUserInfo' => mb_substr((string) ($user['username'] ?? ''), 0, 64),
            'signature' => DuitkuSignature::inquiry($merchantCode, $orderId, $amount, $this->client->apiKey()),
        ];

        // expiryPeriod dalam MENIT; 0 = jangan kirim (pakai default kanal Duitku).
        $expiryMinutes = (int) config('deploy.billing_topup_expiry_minutes', 0);
        if ($expiryMinutes > 0) {
            $payload['expiryPeriod'] = $expiryMinutes;
        }

        // phoneNumber sengaja TIDAK dikirim (hindari PII).

        return $payload;
    }

    /**
     * Bandingkan amount callback dengan nominal order (toleransi 1 sen untuk
     * representasi desimal seperti `10000.00`).
     *
     * @param mixed $amount
     */
    private function amountMatches(mixed $amount, int $orderAmount): bool
    {
        if (!is_numeric($amount)) {
            return false;
        }

        return abs((float) $amount - (float) $orderAmount) < 0.01;
    }
}
