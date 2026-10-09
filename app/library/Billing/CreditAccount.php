<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Auth\UserStore;
use InvalidArgumentException;
use RuntimeException;

/**
 * Operasi saldo kredit (baca & tulis ledger) di atas `BillingStore`.
 *
 * Seluruh mutasi terjadi dalam SATU `SqliteStore::update()` (satu transaksi, atomik) — entri
 * ledger, saldo baru, dan `updated_at` ditulis bersamaan sehingga tidak ada
 * penulisan parsial. Uang/kredit selalu dibulatkan 2 desimal saat disimpan.
 *
 * Stateless: tanpa cache lintas-request; saldo selalu dibaca segar dari berkas.
 */
class CreditAccount
{
    private BillingStore $store;
    private UserStore $users;

    /**
     * `$users` bersifat **opsional aditif** (melengkapi kontrak
     * `__construct(?BillingStore $store = null)`) supaya validasi keberadaan
     * user dapat diuji dengan `UserStore($path)` temp tanpa menyentuh
     * `database/auth.json` nyata.
     */
    public function __construct(?BillingStore $store = null, ?UserStore $users = null)
    {
        $this->store = $store ?? new BillingStore();
        $this->users = $users ?? new UserStore();
    }

    public function balance(string $userId): float
    {
        $user = $this->store->users()[trim($userId)] ?? null;

        return is_array($user) && is_numeric($user['balance'] ?? null)
            ? round((float) $user['balance'], 2)
            : 0.0;
    }

    /**
     * Peta userId → saldo (semua user yang punya entri di billing.json).
     *
     * @return array<string,float>
     */
    public function allBalances(): array
    {
        $balances = [];
        foreach ($this->store->users() as $userId => $user) {
            $balances[(string) $userId] = is_array($user) && is_numeric($user['balance'] ?? null)
                ? round((float) $user['balance'], 2)
                : 0.0;
        }

        return $balances;
    }

    /**
     * Riwayat ledger user, **terbaru dulu**. `$limit <= 0` = seluruh entri.
     *
     * @return array<int,array>
     */
    public function ledger(string $userId, int $limit = 20): array
    {
        $user = $this->store->users()[trim($userId)] ?? null;
        $ledger = is_array($user) && is_array($user['ledger'] ?? null) ? array_values($user['ledger']) : [];
        if ($limit > 0) {
            $ledger = array_slice($ledger, -$limit);
        }

        return array_reverse($ledger);
    }

    /**
     * Tambah/kurangi saldo user secara manual (deposit admin, topup gateway,
     * adjust). `$amount` positif menambah saldo; `adjust` boleh negatif.
     *
     * @param string $type salah satu: deposit | topup | adjust
     * @return array entri ledger yang ditulis
     */
    public function deposit(string $userId, float $amount, string $by, string $note, string $type = 'deposit', ?string $reference = null): array
    {
        $userId = trim($userId);
        $by = trim($by);
        if ($userId === '') {
            throw new InvalidArgumentException('User tujuan kredit tidak valid.');
        }
        if ($by === '') {
            throw new InvalidArgumentException('Pelaku transaksi kredit (by) wajib diisi.');
        }
        if (!in_array($type, ['deposit', 'topup', 'adjust'], true)) {
            throw new InvalidArgumentException('Jenis transaksi kredit tidak dikenal: "' . $type . '".');
        }
        if (!is_finite($amount) || $amount === 0.0) {
            throw new InvalidArgumentException('Nominal kredit harus berupa angka dan tidak boleh nol.');
        }
        if (in_array($type, ['deposit', 'topup'], true) && $amount < 0) {
            throw new InvalidArgumentException('Nominal ' . $type . ' harus lebih besar dari 0.');
        }

        $max = (float) config('deploy.billing_admin_deposit_max', 10000000);
        if ($max > 0 && abs($amount) > $max) {
            throw new InvalidArgumentException(
                'Nominal kredit ' . Pricing::format($amount) . ' melebihi batas maksimum '
                . Pricing::format($max) . ' per transaksi.'
            );
        }

        if ($this->users->findById($userId) === null) {
            throw new RuntimeException('User "' . $userId . '" tidak ditemukan.');
        }

        $entry = null;
        $this->store->update(function (array &$data) use ($userId, $amount, $by, $note, $type, $reference, &$entry): void {
            $entry = $this->applyDeposit($data, $userId, $amount, $by, $note, $type, $reference);
        });

        return $entry ?? [];
    }

    /**
     * Potong saldo user untuk pemakaian `$period`. `$amount` positif = besar
     * potongan; entri ledger menyimpan `amount = -$amount` (boleh membuat saldo
     * negatif). `$items` = rincian per app (lihat `Invoicer`).
     *
     * @param array<int,array> $items
     * @return array entri ledger yang ditulis
     */
    public function charge(string $userId, float $amount, array $items, string $period, string $note = ''): array
    {
        $entry = null;
        $this->store->update(function (array &$data) use ($userId, $amount, $items, $period, $note, &$entry): void {
            $entry = $this->applyCharge($data, $userId, $amount, $items, $period, $note);
        });

        return $entry ?? [];
    }

    /**
     * Terapkan potongan ke struktur billing.json yang **sudah dibuka** pemanggil
     * (tidak membuka transaksi sendiri). Dipakai `Invoicer` agar potong + reset
     * periode berada dalam SATU `SqliteStore::update()` (idempoten); pemakaian
     * biasa lewat `charge()`.
     *
     * @param array<string,mixed> $data data billing.json (by reference)
     * @param array<int,array>    $items
     * @return array entri ledger
     */
    public function applyCharge(array &$data, string $userId, float $amount, array $items, string $period, string $note = ''): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new InvalidArgumentException('User yang ditagih tidak valid.');
        }
        if (!is_finite($amount) || $amount < 0) {
            throw new InvalidArgumentException('Nominal tagihan tidak valid.');
        }
        $period = trim($period);
        if ($period === '') {
            throw new InvalidArgumentException('Periode tagihan wajib diisi.');
        }
        if ($this->users->findById($userId) === null) {
            throw new RuntimeException('User "' . $userId . '" tidak ditemukan.');
        }

        $amount = round($amount, 2);
        $items = array_map(static function (mixed $item): mixed {
            if (is_array($item) && isset($item['amount']) && is_numeric($item['amount'])) {
                $item['amount'] = round((float) $item['amount'], 2);
            }

            return $item;
        }, array_values($items));

        return $this->appendEntry($data, $userId, [
            'type' => 'charge',
            'amount' => -$amount,
            'note' => $note,
            'period' => $period,
            'items' => $items,
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function applyDeposit(array &$data, string $userId, float $amount, string $by, string $note, string $type, ?string $reference): array
    {
        $entry = [
            'type' => $type,
            'amount' => round($amount, 2),
            'by' => $by,
            'note' => $note,
        ];
        if ($reference !== null && trim($reference) !== '') {
            $entry['reference'] = $reference;
        }

        return $this->appendEntry($data, $userId, $entry);
    }

    /**
     * Tambah entri ledger + perbarui saldo dalam satu langkah. `amount` pada
     * `$entry` inilah yang menambah/mengurangi saldo pemilik.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $entry
     */
    private function appendEntry(array &$data, string $userId, array $entry): array
    {
        $users = is_array($data['users'] ?? null) ? $data['users'] : [];
        $current = is_array($users[$userId] ?? null) ? $users[$userId] : [];
        $balance = is_numeric($current['balance'] ?? null) ? (float) $current['balance'] : 0.0;
        $amount = (float) ($entry['amount'] ?? 0);
        $now = date('c');

        $entry['id'] = bin2hex(random_bytes(8));
        $entry['at'] = $now;
        $entry['amount'] = round($amount, 2);
        $entry['balance_after'] = round($balance + $amount, 2);

        $ledger = is_array($current['ledger'] ?? null) ? array_values($current['ledger']) : [];
        $ledger[] = $entry;

        $users[$userId] = [
            'balance' => $entry['balance_after'],
            'updated_at' => $now,
            'ledger' => $ledger,
        ];
        $data['users'] = $users;

        return $entry;
    }
}
