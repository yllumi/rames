<?php
declare(strict_types=1);

namespace app\library\Domain;

use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use RuntimeException;

/**
 * Validasi domain parking (entitas global di luar app).
 *
 * **Statik murni** — tanpa I/O dan tanpa state — kecuali
 * {@see self::assertDomainAvailable()} yang perlu membaca store app & store
 * domain parkir untuk menegakkan keunikan lintas sumber. Semua pesan error
 * berbahasa Indonesia dan memuat nilai yang salah + cara memperbaikinya.
 *
 * Nilai yang divalidasi di sini sengaja **ketat** karena nantinya dipakai
 * merakit direktif Nginx (`server_name`, `return …`, `root`): tidak boleh ada
 * karakter yang bisa memecah/menyuntik direktif.
 *
 * Alur pemakaian yang diharapkan (lapisan manager/controller):
 *  1. `normalizeDomain()` → `isValidDomain()`;
 *  2. `assertDomainAvailable()` (bentrok app / custom domain / parkir lain);
 *  3. untuk mode redirect: `normalizeRedirectTarget()` →
 *     `isValidRedirectTarget()` + `normalizeRedirectCode()`;
 *  4. untuk mode html: `assertHtmlSize()` sebelum menulis berkas.
 */
final class ParkedDomainValidator
{
    /** Batas ukuran HTML halaman parkir: 1 MiB. */
    public const MAX_HTML_BYTES = 1048576;

    /** Batas panjang FQDN (batas DNS). */
    public const MAX_DOMAIN_LENGTH = 253;

    /** Batas panjang satu label DNS. */
    public const MAX_LABEL_LENGTH = 63;

    /** Batas panjang URL tujuan redirect. */
    public const MAX_REDIRECT_LENGTH = 2048;

    /** Kode redirect yang didukung (permanen / sementara). */
    public const REDIRECT_CODES = [301, 302];

    /** Kode default bila pemanggil tidak menentukan. */
    public const DEFAULT_REDIRECT_CODE = 301;

    /**
     * Karakter yang dilarang pada URL tujuan redirect: bisa memecah quoting
     * direktif Nginx (`return 301 "<url>";`) atau membuka injeksi direktif.
     *
     * @var array<int,string>
     */
    private const FORBIDDEN_TARGET_CHARS = ['"', "'", '\\', '<', '>', '{', '}', '$', ';', '`', '|', '^'];

    /**
     * Normalisasi domain: trim, lowercase, dan buang satu titik akhir FQDN
     * (`example.com.` → `example.com`).
     */
    public static function normalizeDomain(string $raw): string
    {
        $domain = strtolower(trim($raw));
        if ($domain !== '' && str_ends_with($domain, '.')) {
            $domain = substr($domain, 0, -1);
        }

        return $domain;
    }

    /**
     * FQDN publik yang sah? (bukan IP literal, bukan `localhost`, tanpa
     * wildcard/underscore/spasi/skema/path; minimal 2 label; TLD alfabet ≥2)
     *
     * Input dinormalisasi lebih dulu, jadi `Example.COM.` juga diterima.
     */
    public static function isValidDomain(string $domain): bool
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '' || strlen($domain) > self::MAX_DOMAIN_LENGTH) {
            return false;
        }
        if ($domain === 'localhost' || str_contains($domain, '..')) {
            return false;
        }
        // IP literal (v4 maupun v6) bukan domain publik.
        if (filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        $tld = (string) $labels[count($labels) - 1];
        if (strlen($tld) > self::MAX_LABEL_LENGTH || preg_match('/^[a-z]{2,63}$/', $tld) !== 1) {
            return false;
        }
        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                return false;
            }
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalisasi URL tujuan redirect: trim spasi tepi saja (path tidak boleh
     * diubah diam-diam).
     */
    public static function normalizeRedirectTarget(string $raw): string
    {
        return trim($raw);
    }

    /**
     * URL tujuan redirect sah untuk direktif Nginx?
     *
     * Wajib `http://`/`https://`; host FQDN publik atau IPv4; opsional `:port`
     * 1–65535; opsional path absolut. Ditolak: userinfo (`@`), query (`?`),
     * fragment (`#`), spasi/kontrol (newline/NUL), karakter quoting direktif
     * (`"'\\<>{}$;`|^`), dan segmen `..` (termasuk yang ter-encode `%2e%2e`).
     */
    public static function isValidRedirectTarget(string $target): bool
    {
        $target = self::normalizeRedirectTarget($target);
        if ($target === '' || strlen($target) > self::MAX_REDIRECT_LENGTH) {
            return false;
        }
        // Spasi + seluruh karakter kontrol (NUL, newline, tab, DEL).
        if (preg_match('/[\x00-\x20\x7f]/', $target) === 1) {
            return false;
        }
        foreach (self::FORBIDDEN_TARGET_CHARS as $char) {
            if (str_contains($target, $char)) {
                return false;
            }
        }
        if (str_contains($target, '@')) {
            return false; // userinfo: http://user:pass@host/
        }
        if (str_contains($target, '?') || str_contains($target, '#')) {
            return false; // query/fragment tidak didukung
        }

        $parts = parse_url($target);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        $isIpv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if (!$isIpv4 && !self::isValidDomain($host)) {
            return false;
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if ($port < 1 || $port > 65535) {
                return false;
            }
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '') {
            if (!str_starts_with($path, '/')) {
                return false; // path harus absolut
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '..' || rawurldecode($segment) === '..') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Kode redirect: default 301; hanya 301/302 (int atau string numerik).
     *
     * @throws RuntimeException untuk nilai lain (termasuk 307/308/angka acak)
     */
    public static function normalizeRedirectCode(mixed $raw): int
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return self::DEFAULT_REDIRECT_CODE;
        }

        $code = null;
        if (is_int($raw)) {
            $code = $raw;
        } elseif (is_string($raw) && preg_match('/^\d{1,4}$/', trim($raw)) === 1) {
            $code = (int) trim($raw);
        }

        if ($code === null) {
            throw new RuntimeException(
                'Kode redirect tidak valid: ' . self::describeValue($raw)
                . '. Gunakan 301 (permanen) atau 302 (sementara).'
            );
        }
        if (!in_array($code, self::REDIRECT_CODES, true)) {
            throw new RuntimeException(
                'Kode redirect ' . $code . ' tidak didukung. Gunakan 301 (permanen) atau 302 (sementara).'
            );
        }

        return $code;
    }

    /**
     * Pastikan isi HTML tidak melebihi {@see self::MAX_HTML_BYTES} (1 MiB).
     *
     * @throws RuntimeException beserta ukuran aktual & batasnya
     */
    public static function assertHtmlSize(string $html): void
    {
        $size = strlen($html);
        if ($size > self::MAX_HTML_BYTES) {
            throw new RuntimeException(
                'Ukuran HTML terlalu besar: ' . $size . ' byte, batas 1 MiB ('
                . self::MAX_HTML_BYTES . ' byte). Ringkas halaman parkir Anda.'
            );
        }
    }

    /**
     * Pastikan domain bebas dari bentrok sebelum disimpan.
     *
     * Sumber bentrok yang diperiksa:
     *  1. FQDN efektif app (`app_subdomain_of()` — satu-satunya jalur resolusi);
     *  2. `custom_domain` app;
     *  3. domain entri parkir lain (kecuali `$excludeId`, untuk edit entri sendiri).
     *
     * @param string $excludeId id entri parkir yang sedang diedit (dilewati)
     *
     * @throws RuntimeException dengan pesan spesifik + identitas yang bentrok
     */
    public static function assertDomainAvailable(
        string $domain,
        AppStore $apps,
        ParkedDomainStore $parked,
        string $excludeId = ''
    ): void {
        $domain = self::normalizeDomain($domain);

        foreach ($apps->all() as $app) {
            $appId = (string) ($app['id'] ?? '');
            $appName = (string) ($app['name'] ?? ($appId !== '' ? $appId : '?'));

            $effective = self::normalizeDomain(app_subdomain_of($app));
            if ($effective !== '' && $effective === $domain) {
                throw new RuntimeException(
                    'Domain "' . $domain . '" sudah dipakai subdomain app "' . $appName
                    . '" (id ' . $appId . '). Pilih domain lain atau ubah subdomain app tersebut lebih dulu.'
                );
            }

            $customDomain = self::normalizeDomain((string) ($app['custom_domain'] ?? ''));
            if ($customDomain !== '' && $customDomain === $domain) {
                throw new RuntimeException(
                    'Domain "' . $domain . '" sudah dipakai sebagai custom domain app "' . $appName
                    . '" (id ' . $appId . '). Lepas custom domain itu atau pilih domain lain.'
                );
            }
        }

        foreach ($parked->all() as $entry) {
            $entryId = (string) ($entry['id'] ?? '');
            if ($excludeId !== '' && $entryId === $excludeId) {
                continue;
            }
            if (self::normalizeDomain((string) ($entry['domain'] ?? '')) === $domain) {
                throw new RuntimeException(
                    'Domain "' . $domain . '" sudah terparkir (id ' . $entryId . ').'
                    . ' Hapus atau ubah entri tersebut lebih dulu.'
                );
            }
        }
    }

    /**
     * Saran varian `www` (opsi cepat UI — entri terpisah, bukan otomatis):
     * `www.{domain}` bila domain belum berawalan `www.`, selain itu `null`.
     */
    public static function suggestedWwwVariant(string $domain): ?string
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '' || str_starts_with($domain, 'www.')) {
            return null;
        }

        return 'www.' . $domain;
    }

    /**
     * Penggambaran nilai non-string untuk pesan error (tanpa `var_export`).
     */
    private static function describeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return '"' . (string) $value . '"';
        }

        return get_debug_type($value);
    }
}
