<?php
declare(strict_types=1);

namespace app\library\Errors;

use Throwable;

/**
 * Redaksi kredensial untuk fitur "Rekam Error".
 *
 * Laporan error datang dari browser pengguna (uncaught JS / `unhandledrejection`)
 * atau dari exception 5xx yang tampil ke user. Isi laporan **tidak**
 * terpercaya: pesan/stack bisa memuat header `Authorization`, cookie sesi,
 * token API, DSN ber-password, blok PEM, atau pasangan kunci sensitif dari
 * form/query/JSON/env yang kebetulan ikut tercetak. Kelas ini satu-satunya
 * pintu redaksi — jangan menyalin regex-nya ke controller/library lain.
 *
 * Sifat: **statik murni, tanpa I/O**, tanpa state. Semua method deterministik
 * dan **tidak pernah melempar** (input aneh, UTF-8 rusak, atau parameter batas
 * negatif sekalipun) karena dipakai di jalur pelaporan error: kegagalan
 * redaksi tidak boleh berubah menjadi kegagalan request.
 *
 * Urutan lapisan redaksi (`redact()`):
 *  1. blok PEM (paling awal supaya seluruh isi kunci ikut tersapu),
 *  2. `Bearer <token>`,
 *  3. header `Authorization` / `Cookie` / `Set-Cookie` (nilai sampai akhir
 *     baris atau `&`),
 *  4. userinfo URL (`scheme://user:pass@host`),
 *  5. pasangan kunci sensitif (`password=…`, `"token": "…"`, `api_key=…`),
 *  6. pola token umum (`sk-…`, `ghp_…`, `xoxb-…`, JWT).
 *
 * Truncation selalu **UTF-8 safe** (memotong di batas karakter) dan sufiks
 * `…[truncated]` hanya ditambahkan bila benar-benar terpotong.
 */
final class ErrorRedactor
{
    /** Batas default teks bebas (byte UTF-8). */
    public const DEFAULT_MAX_BYTES = 8192;

    /** Batas default hasil JSON `redactContext()`. */
    public const DEFAULT_CONTEXT_MAX_BYTES = 4096;

    /** Batas panjang path route (byte). */
    public const ROUTE_MAX_BYTES = 512;

    /** Sufiks penanda pemotongan (UTF-8). */
    public const TRUNCATION_SUFFIX = '…[truncated]';

    /** Pengganti nilai rahasia. */
    public const REDACTED = '[REDACTED]';

    /** Pengganti blok kunci privat. */
    public const REDACTED_PEM = '[REDACTED PEM]';

    /** Kedalaman maksimum kontainer di `redactContext()` (root = level 1). */
    private const CONTEXT_MAX_DEPTH = 5;

    /** Jumlah maksimum kunci yang ditelusuri di `redactContext()`. */
    private const CONTEXT_MAX_KEYS = 50;

    /** Batas byte per string di `redactContext()`. */
    private const CONTEXT_MAX_STRING_BYTES = 1024;

    /**
     * Alternatif kunci sensitif untuk pasangan nilai di teks bebas.
     * Urutan penting: alternatif yang lebih panjang lebih dulu supaya
     * `client_secret` tidak terpotong menjadi `secret`, `signature` tidak
     * menjadi `sig`.
     */
    private const SENSITIVE_KEYS = 'client[_-]?secret|access[_-]?token|refresh[_-]?token'
        . '|id[_-]?token|api[_-]?key|apikey|access[_-]?key|secret[_-]?key|private[_-]?key'
        . '|password|passwd|pwd|csrf[_-]?token|xsrf[_-]?token'
        . '|signature|_token|csrf|xsrf|secret|token|sig';

    /** Kunci yang nilainya dibaca sampai akhir baris (nilai header gabungan). */
    private const HEADER_KEYS = 'authorization|set-cookie|cookie';

    /**
     * Redaksi + truncation teks bebas.
     *
     * @param int $maxBytes batas byte hasil; bila terpotong ditambahi
     *                      {@see self::TRUNCATION_SUFFIX} (sufiks di luar batas)
     */
    public static function redact(string $text, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        if ($text === '') {
            return '';
        }

        $limit = max(0, $maxBytes);
        if ($limit === 0) {
            return '';
        }

        try {
            $clean = self::applyLayers($text);
        } catch (Throwable) {
            // Jalur pelaporan error: jangan pernah melempar. Gagal redaksi
            // berarti lebih aman menyimpan teks kosong daripada nilai mentah.
            return '';
        }

        return self::truncateWithSuffix($clean, $limit);
    }

    /**
     * Redaksi konteks terstruktur (array) menjadi array JSON-safe.
     *
     * Aturan:
     *  - rekursif maksimal {@see self::CONTEXT_MAX_DEPTH} level (root = level 1),
     *  - maksimal {@see self::CONTEXT_MAX_KEYS} kunci ditelusuri (semua level),
     *  - string di-redaksi + dipotong ke 1024 byte,
     *  - nilai **bukan string dan bukan array** (int/float/bool/null/objek/resource)
     *    dibuang — kontrak fitur "Rekam Error" hanya mempertahankan teks &
     *    struktur; angka/flag tidak punya nilai diagnosa sebanding,
     *  - nilai di bawah kunci sensitif (`password`, `token`, `api_key`, …)
     *    diganti `[REDACTED]` tanpa melihat isinya,
     *  - bila hasil `json_encode` melebihi `$maxBytes`, kunci dibuang dari
     *    belakang sampai muat.
     *
     * Method ini tidak pernah melempar; kegagalan internal menghasilkan `[]`.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function redactContext(array $context, int $maxBytes = self::DEFAULT_CONTEXT_MAX_BYTES): array
    {
        $limit = max(0, $maxBytes);

        try {
            $budget = self::CONTEXT_MAX_KEYS;
            $out = self::walkContext($context, 1, $budget);

            $json = self::encode($out);
            while ($out !== [] && ($json === null || strlen($json) > $limit)) {
                array_pop($out); // buang kunci dari belakang
                $json = self::encode($out);
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Normalisasi route laporan menjadi path saja.
     *
     * Query & fragment dibuang (`/apps/x/logs?token=…` → `/apps/x/logs`),
     * hasil wajib diawali `/` (selain itu `''`), dan dipotong UTF-8 safe ke
     * {@see self::ROUTE_MAX_BYTES}. Tanpa sufiks: route dipakai untuk
     * pengelompokan, bukan untuk dibaca manusia.
     */
    public static function routePath(string $route): string
    {
        $route = trim($route);
        if ($route === '') {
            return '';
        }

        try {
            $path = parse_url($route, PHP_URL_PATH);
        } catch (Throwable) {
            return '';
        }

        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return '';
        }

        return self::truncateBytes($path, self::ROUTE_MAX_BYTES);
    }

    /**
     * Terapkan seluruh lapisan redaksi pada teks.
     */
    private static function applyLayers(string $text): string
    {
        // 1. Blok PEM (pasangan BEGIN…END, lalu sisa yang tidak tertutup).
        $text = (string) preg_replace(
            '/-----BEGIN [A-Z0-9 ]+-----.*?-----END [A-Z0-9 ]+-----/s',
            self::REDACTED_PEM,
            $text
        );
        $text = (string) preg_replace(
            '/-----BEGIN [A-Z0-9 ]+-----[\s\S]*/',
            self::REDACTED_PEM,
            $text
        );

        // 2. Bearer token (termasuk header `Authorization: Bearer …`).
        $text = (string) preg_replace(
            '/(?<![A-Za-z0-9])Bearer\s+(?!\[REDACTED\])[^\s,;"\'\)\]]+/i',
            'Bearer ' . self::REDACTED,
            $text
        );

        // 3. Header dengan nilai gabungan (cookie/authorization) — nilai
        //    dibaca sampai akhir baris atau `&` supaya cookie kedua tidak
        //    tertinggal.
        $text = self::redactPairs($text, self::HEADER_KEYS, '[^\r\n&]+', true);

        // 4. Userinfo URL: scheme://user:pass@host → scheme://[REDACTED]@host
        $text = (string) preg_replace(
            '/([a-z][a-z0-9+.\-]*:\/\/)[^\/\s@]+@/i',
            '$1' . self::REDACTED . '@',
            $text
        );

        // 5. Pasangan kunci sensitif (form/query/JSON/env).
        $text = self::redactPairs($text, self::SENSITIVE_KEYS, '[^\s&,;}\)\]"\'<>]+');

        // 6. Pola token umum.
        $patterns = [
            '/(?<![A-Za-z0-9])sk-[A-Za-z0-9_-]{8,}/' => self::REDACTED,
            '/(?<![A-Za-z0-9])gh[pousr]_[A-Za-z0-9]{20,}/' => self::REDACTED,
            '/(?<![A-Za-z0-9])xox[baprs]-[A-Za-z0-9-]{10,}/' => self::REDACTED,
            '/(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/'
                => self::REDACTED,
        ];
        foreach ($patterns as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Redaksi pasangan `kunci <pemisah> nilai` untuk daftar kunci tertentu.
     *
     * Pemisah yang diterima: `=`, `:` (JSON/header), boleh diapit spasi dan
     * tanda kutip pada kunci maupun nilai. Nilai yang sudah `[REDACTED]`
     * dilewati supaya lapisan tidak menumpuk kurung siku.
     *
     * @param string $keys       alternatif regex kunci (tanpa anchor)
     * @param string $valueClass pola nilai tak-berkutip
     * @param bool   $keepBearer pertahankan skema `Bearer` pada nilai header
     *                           (`Authorization: Bearer [REDACTED]`)
     */
    private static function redactPairs(
        string $text,
        string $keys,
        string $valueClass,
        bool $keepBearer = false
    ): string {
        $pattern = '/(?<![A-Za-z0-9])("?(?:' . $keys . ')"?\s*[:=]\s*)'
            . '(?!\s*' . preg_quote(self::REDACTED, '/') . ')'
            . '("[^"\r\n]*"|\'[^\'\r\n]*\'|' . $valueClass . ')/i';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $matches) use ($keepBearer): string {
                $value = $matches[2];
                $quote = '';
                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $quote = $value[0];
                }

                // Nilai header berskema Bearer dipertahankan skemanya agar
                // pesan tetap terbaca: `Authorization: Bearer [REDACTED]`.
                if (
                    $keepBearer
                    && $quote === ''
                    && preg_match('/^Bearer\s+\S/i', $value) === 1
                ) {
                    return $matches[1] . 'Bearer ' . self::REDACTED;
                }

                return $matches[1] . $quote . self::REDACTED . $quote;
            },
            $text
        );
    }

    /**
     * @param array<int|string,mixed> $items
     * @return array<int|string,mixed>
     */
    private static function walkContext(array $items, int $level, int &$budget): array
    {
        $out = [];
        foreach ($items as $key => $value) {
            if ($budget <= 0) {
                break;
            }
            if (!is_int($key) && !is_string($key)) {
                continue; // kunci tidak bisa di-JSON-kan dengan aman
            }
            $budget--;

            if (self::isSensitiveKey((string) $key)) {
                $out[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                if ($level >= self::CONTEXT_MAX_DEPTH) {
                    continue; // terlalu dalam
                }
                $out[$key] = self::walkContext($value, $level + 1, $budget);
                continue;
            }

            if (!is_string($value)) {
                continue; // non-string & non-array dibuang (kontrak fitur)
            }

            $out[$key] = self::redact($value, self::CONTEXT_MAX_STRING_BYTES);
        }

        return $out;
    }

    /**
     * Nama kunci sensitif? Dinormalisasi (huruf kecil, non-alfanumerik dibuang)
     * supaya `X-Api-Key`, `api_key`, `apiKey`, dan `csrfToken` sama-sama
     * tertangkap. Kunci biasa seperti `token_count` tidak ikut.
     */
    private static function isSensitiveKey(string $key): bool
    {
        $norm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        if ($norm === '') {
            return false;
        }

        $exact = [
            'password', 'passwd', 'pwd', 'secret', 'clientsecret',
            'token', 'accesstoken', 'refreshtoken', 'idtoken',
            'apikey', 'accesskey', 'secretkey', 'privatekey',
            'authorization', 'signature', 'sig', 'bearer',
            'cookie', 'cookies', 'setcookie',
            'csrf', 'xsrf', 'csrftoken', 'xsrftoken',
            'credential', 'credentials',
        ];
        if (in_array($norm, $exact, true)) {
            return true;
        }

        foreach ([
            'password', 'passwd', 'secret', 'token', 'apikey', 'accesskey',
            'privatekey', 'signature', 'cookie', 'csrf', 'xsrf', 'credential',
        ] as $suffix) {
            if (str_ends_with($norm, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `json_encode` yang tidak pernah gagal karena UTF-8 rusak.
     * `null` = gagal (mis. struktur rekursif).
     *
     * @param array<int|string,mixed> $value
     */
    private static function encode(array $value): ?string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : null;
    }

    /**
     * Potong ke `$maxBytes` pada batas karakter UTF-8, lalu tambahkan sufiks
     * hanya bila benar-benar terpotong.
     */
    private static function truncateWithSuffix(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return self::truncateBytes($text, $maxBytes) . self::TRUNCATION_SUFFIX;
    }

    /**
     * Potong ke `$maxBytes` tanpa merusak karakter multi-byte.
     */
    private static function truncateBytes(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $cut = function_exists('mb_strcut')
            ? (string) mb_strcut($text, 0, $maxBytes, 'UTF-8')
            : substr($text, 0, $maxBytes);

        // mb_strcut berhenti sebelum karakter yang tidak muat; buang sisa
        // spasi/newline agar hasil tidak berakhir dengan baris kosong.
        return rtrim($cut, "\r\n ");
    }
}
