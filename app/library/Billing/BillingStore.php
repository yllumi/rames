<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Storage\JsonStore;

/**
 * Penyimpanan kredit/billing (`database/billing.json`) — berkas TERPISAH dari
 * `apps.json`/`auth.json` supaya skema keduanya tidak berubah (tanpa migrasi
 * app lama). Semua mutasi lewat `JsonStore::update()` (atomik + flock + .bak).
 *
 * Bentuk data:
 *   {version, users, usage, orders}
 *
 * Berkas boleh belum ada: pembacaan mengembalikan struktur default, dan berkas
 * baru dibuat saat penulisan pertama. `$path` di konstruktor (pola
 * `UserStore`/`BackupSelection`) supaya bisa di-override saat tes.
 *
 * Normalisasi dijalankan pada setiap baca & tulis: memastikan keempat kunci
 * ada, saldo dibulatkan 2 desimal, dan ledger dipangkas ke
 * `BILLING_LEDGER_KEEP` entri terakhir (nilai ≤ 0 = tanpa batas).
 */
class BillingStore
{
    private JsonStore $store;

    public function __construct(?string $path = null)
    {
        $this->store = new JsonStore($path ?? (config('deploy.database_path') . '/billing.json'));
    }

    public function path(): string
    {
        return $this->store->path();
    }

    /**
     * @return array<string,array>
     */
    public function users(): array
    {
        return $this->normalize($this->store->read())['users'];
    }

    /**
     * @return array<string,array>
     */
    public function usage(): array
    {
        return $this->normalize($this->store->read())['usage'];
    }

    /**
     * @return array<string,array>
     */
    public function orders(): array
    {
        return $this->normalize($this->store->read())['orders'];
    }

    /**
     * Update atomik seluruh berkas. Mutator menerima `array &$data` (struktur
     * lengkap billing.json yang sudah dinormalisasi) dan boleh mengubahnya;
     * normalisasi dijalankan ulang setelah mutator sebelum ditulis.
     */
    public function update(callable $mutator): void
    {
        $this->store->update(function (array &$data) use ($mutator): void {
            $data = $this->normalize($data);
            $mutator($data);
            $data = $this->normalize($data);
        });
    }

    /**
     * Bentuk kanonik: hanya empat kunci yang dikenal, nilai default bila absen,
     * saldo dibulatkan 2 desimal, ledger dipangkas.
     *
     * @param array<string,mixed> $data
     * @return array{version:int,users:array<string,array>,usage:array<string,array>,orders:array<string,array>}
     */
    private function normalize(array $data): array
    {
        if (!is_array($data['users'] ?? null)) {
            $data['users'] = [];
        }
        if (!is_array($data['usage'] ?? null)) {
            $data['usage'] = [];
        }
        if (!is_array($data['orders'] ?? null)) {
            $data['orders'] = [];
        }

        $keep = (int) config('deploy.billing_ledger_keep', 200);

        $users = [];
        foreach ($data['users'] as $userId => $user) {
            $user = is_array($user) ? $user : [];
            $ledger = is_array($user['ledger'] ?? null) ? array_values($user['ledger']) : [];
            $ledger = array_map([self::class, 'normalizeLedgerEntry'], $ledger);
            // keep ≤ 0 = tanpa batas (jangan sampai data lama terbuang diam-diam).
            if ($keep > 0 && count($ledger) > $keep) {
                $ledger = array_slice($ledger, -$keep);
            }
            $users[(string) $userId] = [
                'balance' => is_numeric($user['balance'] ?? null) ? round((float) $user['balance'], 2) : 0.0,
                'updated_at' => (string) ($user['updated_at'] ?? ''),
                'ledger' => $ledger,
            ];
        }

        $usage = [];
        foreach ($data['usage'] as $appId => $row) {
            if (!is_array($row)) {
                continue;
            }
            // JSON round-trip mengubah float bulat (mis. 0.0) menjadi int — paksa
            // tipe kanonik di sini supaya kontrak "kredit = float" tetap terjaga.
            $row['seconds_pending'] = (int) ($row['seconds_pending'] ?? 0);
            $row['credits_pending'] = round((float) ($row['credits_pending'] ?? 0.0), 2);
            $usage[(string) $appId] = $row;
        }

        return [
            'version' => 1,
            'users' => $users,
            'usage' => $usage,
            'orders' => $data['orders'],
        ];
    }

    /**
     * Paksa tipe numerik kanonik pada entri ledger (uang = float 2 desimal)
     * agar nilai tetap float setelah round-trip JSON.
     *
     * @param mixed $entry
     * @return mixed
     */
    private static function normalizeLedgerEntry(mixed $entry): mixed
    {
        if (!is_array($entry)) {
            return $entry;
        }
        if (isset($entry['amount']) && is_numeric($entry['amount'])) {
            $entry['amount'] = round((float) $entry['amount'], 2);
        }
        if (isset($entry['balance_after']) && is_numeric($entry['balance_after'])) {
            $entry['balance_after'] = round((float) $entry['balance_after'], 2);
        }
        if (is_array($entry['items'] ?? null)) {
            $entry['items'] = array_map(static function (mixed $item): mixed {
                if (!is_array($item)) {
                    return $item;
                }
                foreach (['amount', 'hourly', 'hours', 'cpus'] as $field) {
                    if (isset($item[$field]) && is_numeric($item[$field])) {
                        $item[$field] = (float) $item[$field];
                    }
                }
                if (isset($item['memory_mb']) && is_numeric($item['memory_mb'])) {
                    $item['memory_mb'] = (int) $item['memory_mb'];
                }

                return $item;
            }, array_values($entry['items']));
        }

        return $entry;
    }
}
