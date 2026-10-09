<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteStore;

/**
 * Penyimpanan kredit/billing — store logis `billing` pada basis data SQLite
 * (`config('deploy.sqlite_file')`, dahulu `database/billing.json`). Semua mutasi
 * lewat satu transaksi (`SqliteStore::update()` → `BEGIN IMMEDIATE`) sehingga
 * penulisan lintas tabel (order + saldo + ledger) tetap atomik.
 *
 * Bentuk data yang dibaca pemakai tetap `{version, users, usage, orders}`
 * (`read()` menambahkan `version` sendiri karena `SqliteStore` hanya
 * menyimpan koleksi). `$path` di konstruktor (pola `UserStore`/
 * `BackupSelection`) = berkas .sqlite, supaya bisa di-override saat tes.
 *
 * Normalisasi dijalankan pada setiap baca & tulis: memastikan keempat kunci
 * ada, saldo dibulatkan 2 desimal, dan ledger dipangkas ke
 * `BILLING_LEDGER_KEEP` entri terakhir (nilai ≤ 0 = tanpa batas).
 */
class BillingStore
{
    /** Versi skema berkas billing (dipertahankan untuk kompatibilitas bentuk lama). */
    private const VERSION = 1;

    private SqliteStore $store;

    public function __construct(?string $path = null)
    {
        $this->store = new SqliteStore('billing', SchemaMigrations::storeDefinitions()['billing'], $path);
    }

    public function path(): string
    {
        return $this->store->path();
    }

    /**
     * Isi store lengkap dalam bentuk kanonik `{version, users, usage, orders}`.
     *
     * @return array{version:int,users:array<string,array>,usage:array<string,array>,orders:array<string,array>}
     */
    public function read(): array
    {
        return $this->normalize($this->store->read());
    }

    /**
     * @return array<string,array>
     */
    public function users(): array
    {
        return $this->read()['users'];
    }

    /**
     * @return array<string,array>
     */
    public function usage(): array
    {
        return $this->read()['usage'];
    }

    /**
     * @return array<string,array>
     */
    public function orders(): array
    {
        return $this->read()['orders'];
    }

    /**
     * Update atomik seluruh store. Mutator menerima `array &$data` (struktur
     * lengkap `{version, users, usage, orders}` yang sudah dinormalisasi) dan
     * boleh mengubahnya; normalisasi dijalankan ulang setelah mutator sebelum
     * ditulis. Semua kunci dibaca & ditulis dalam satu transaksi.
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
            'version' => self::VERSION,
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
        // Nominal rupiah asli entri `topup` (int, tanpa desimal).
        if (isset($entry['idr']) && is_numeric($entry['idr'])) {
            $entry['idr'] = (int) $entry['idr'];
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
