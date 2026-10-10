<?php
declare(strict_types=1);

namespace app\library\Billing;

/**
 * Katalog pengelompokan kanal pembayaran Duitku (Virtual Account, E-Wallet,
 * QRIS, Retail) — **satu sumber kebenaran di PHP**.
 *
 * Gateway Duitku **tidak** mengirim kategori kanal: `DuitkuClient::paymentMethods()`
 * hanya mengembalikan `code`/`name`/`image`/`fee`. Karena itu tabel kategori
 * diturunkan **lokal** di kelas ini (statik murni, tanpa I/O) lalu dipakai dua
 * jalur render:
 *  - server: `CreditController::index()` → payload view `methodGroups`;
 *  - JS   : `CreditController::methods()` → tiap item `data` diberi `group` dan
 *           diurutkan per grup kanonik (JS cukup mengikuti urutan `data`, tidak
 *           boleh menyalin tabel kode→grup).
 *
 * Fail-safe: kode tak dikenal & tanpa petunjuk nama → {@see self::OTHER}
 * (`'Lainnya'`) — kode asing **tidak pernah** dipaksa masuk grup bank/VA.
 */
final class PaymentMethodCatalog
{
    /** Grup cadangan untuk kode yang tak terpetakan & tak punya petunjuk nama. */
    public const OTHER = 'Lainnya';

    /**
     * Urutan kanonik grup — **urutan inilah yang dipakai UI** (render server &
     * `<optgroup>` JS). Jangan menambah/mengubah urutan tanpa menyesuaikan UI.
     *
     * @var array<int,string>
     */
    public const GROUPS = ['Virtual Account', 'E-Wallet', 'QRIS', 'Retail', 'Lainnya'];

    /** Kode kanal per grup (kunci = kode 2 karakter, nilai = label grup). */
    private const CODE_GROUPS = [
        // Virtual Account
        'BC' => 'Virtual Account',
        'BT' => 'Virtual Account',
        'I1' => 'Virtual Account',
        'M2' => 'Virtual Account',
        'VA' => 'Virtual Account',
        'B1' => 'Virtual Account',
        'DM' => 'Virtual Account',
        'BV' => 'Virtual Account',
        'BR' => 'Virtual Account',
        'NC' => 'Virtual Account',
        'A1' => 'Virtual Account',
        'AG' => 'Virtual Account',
        'S1' => 'Virtual Account',
        // E-Wallet
        'OV' => 'E-Wallet',
        'DA' => 'E-Wallet',
        'SA' => 'E-Wallet', // nama gateway: "SHOPEEPAY APP"
        'LF' => 'E-Wallet',
        'LA' => 'E-Wallet',
        // QRIS
        // SP = "SHOPEEPAY QRIS" → QRIS, **bukan** E-Wallet murni (bukti nama live gateway).
        'SP' => 'QRIS', // nama gateway: "SHOPEEPAY QRIS"
        'NQ' => 'QRIS',
        'SQ' => 'QRIS',
        'QR' => 'QRIS',
        // Retail
        'IR' => 'Retail',
        'AL' => 'Retail',
        'FT' => 'Retail', // nama gateway: "RETAIL" (bukan Lainnya)
    ];

    /**
     * Petunjuk nama (huruf kecil) → grup, dicek berurutan. Dipakai hanya bila
     * kode tidak ada di tabel {@see self::CODE_GROUPS}.
     *
     * @var array<int,array{group:string,hints:array<int,string>}>
     */
    private const NAME_HINTS = [
        ['group' => 'Virtual Account', 'hints' => ['virtual account']],
        // QRIS **sengaja** dicek sebelum E-Wallet: nama live gateway
        // "… SHOPEEPAY QRIS" harus masuk QRIS, sedangkan "SHOPEEPAY APP", "OVO",
        // "DANA Wallet" (tanpa `qris`/`qr`) tetap E-Wallet.
        ['group' => 'QRIS', 'hints' => ['qris', 'qr']],
        ['group' => 'E-Wallet', 'hints' => ['wallet', 'ovo', 'dana', 'shopeepay', 'linkaja', 'gopay', 'jenius']],
        ['group' => 'Retail', 'hints' => ['indomaret', 'alfamart', 'retail', 'gerai', 'fastpay']],
    ];

    /**
     * Tentukan grup satu kanal. `$code` dinormalisasi (`strtoupper(trim())`);
     * bila tak ada di tabel, coba `$name` (case-insensitive); selain itu
     * {@see self::OTHER}.
     */
    public static function group(string $code, string $name = ''): string
    {
        $code = strtoupper(trim($code));
        if ($code !== '' && isset(self::CODE_GROUPS[$code])) {
            return self::CODE_GROUPS[$code];
        }

        $needle = strtolower(trim($name));
        if ($needle !== '') {
            foreach (self::NAME_HINTS as $entry) {
                foreach ($entry['hints'] as $hint) {
                    if (str_contains($needle, $hint)) {
                        return $entry['group'];
                    }
                }
            }
        }

        return self::OTHER;
    }

    /**
     * Tambahkan key `group` ke tiap item; semua key lain dipertahankan apa adanya.
     * Item non-array dilewati; item tanpa `code` → grup {@see self::OTHER}.
     *
     * @param array<int,mixed> $methods
     * @return array<int,array<string,mixed>>
     */
    public static function decorate(array $methods): array
    {
        $out = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $code = (string) ($method['code'] ?? '');
            $name = (string) ($method['name'] ?? '');
            $method['group'] = $code === '' ? self::OTHER : self::group($code, $name);
            $out[] = $method;
        }

        return $out;
    }

    /**
     * Kelompokkan item (menggunakan key `group` bila sudah valid, jika tidak
     * dihitung ulang dari `code`/`name`) dengan urutan kanonik {@see self::GROUPS}.
     * Grup kosong dilewati; urutan item di dalam grup = urutan masukan (stabil).
     *
     * @param array<int,mixed> $methods
     * @return array<int,array{label:string,items:array<int,array<string,mixed>>}>
     */
    public static function grouped(array $methods): array
    {
        $buckets = [];
        foreach (self::GROUPS as $label) {
            $buckets[$label] = [];
        }

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $label = self::labelOf($method);
            $buckets[$label][] = $method;
        }

        $out = [];
        foreach (self::GROUPS as $label) {
            if ($buckets[$label] === []) {
                continue;
            }
            $out[] = ['label' => $label, 'items' => $buckets[$label]];
        }

        return $out;
    }

    /**
     * Kelompokkan kode kanal (mis. allowlist config) untuk render awal server.
     * Kode dinormalisasi (uppercase/trim), duplikat & kode tak valid
     * (bukan `[A-Z0-9]{2}`) dibuang; urutan di dalam grup = urutan masukan.
     *
     * @param array<int,mixed> $codes
     * @return array<int,array{label:string,codes:array<int,string>}>
     */
    public static function groupedCodes(array $codes): array
    {
        $buckets = [];
        foreach (self::GROUPS as $label) {
            $buckets[$label] = [];
        }
        $seen = [];

        foreach ($codes as $code) {
            if (!is_string($code) && !is_int($code)) {
                continue;
            }
            $code = strtoupper(trim((string) $code));
            if (preg_match('/^[A-Z0-9]{2}$/', $code) !== 1 || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $buckets[self::group($code)][] = $code;
        }

        $out = [];
        foreach (self::GROUPS as $label) {
            if ($buckets[$label] === []) {
                continue;
            }
            $out[] = ['label' => $label, 'codes' => $buckets[$label]];
        }

        return $out;
    }

    /**
     * Label grup untuk satu item: pakai `group` yang sudah valid, selain itu
     * hitung dari `code`/`name` (item tanpa `code` & tanpa grup → OTHER).
     *
     * @param array<string,mixed> $method
     */
    private static function labelOf(array $method): string
    {
        $existing = $method['group'] ?? null;
        if (is_string($existing) && in_array($existing, self::GROUPS, true)) {
            return $existing;
        }

        $code = (string) ($method['code'] ?? '');
        if ($code === '') {
            return self::OTHER;
        }

        return self::group($code, (string) ($method['name'] ?? ''));
    }
}
