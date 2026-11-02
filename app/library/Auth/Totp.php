<?php
declare(strict_types=1);

namespace app\library\Auth;

use InvalidArgumentException;

/**
 * TOTP (RFC 6238) + base32 (RFC 4648) — **statik murni**, tanpa I/O.
 *
 * Satu-satunya sumber algoritma 2FA untuk dashboard: pembuatan secret
 * (`generateSecret()`), pembuatan kode (`code()`), verifikasi dengan toleransi
 * drift (`verify()`), dan URI `otpauth://` untuk aplikasi authenticator
 * (`provisioningUri()`).
 *
 * Invarian:
 *  - tanpa state (tidak ada properti) sehingga aman dipanggil dari mana pun,
 *    termasuk dari kelas store;
 *  - secret/kode **tidak pernah** dicatat ke log;
 *  - `verify()` tidak melempar exception untuk kode/secret yang salah — nilai
 *    balik `null` berarti "tidak cocok" (pemanggil memutuskan kebijakan);
 *  - perbandingan kode memakai `hash_equals()` (constant-time).
 */
final class Totp
{
    /** Panjang satu langkah waktu (detik) — default RFC 6238. */
    public const PERIOD = 30;

    /** Jumlah digit kode default. */
    public const DIGITS = 6;

    /** Algoritma HMAC default. */
    public const ALGORITHM = 'sha1';

    /** Alfabet base32 RFC 4648 (tanpa padding). */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Buat secret acak baru (default 20 byte = 160 bit, seperti rekomendasi
     * RFC 4226) dan kembalikan dalam bentuk base32 tanpa padding.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('Panjang secret tidak valid.');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Encode data biner ke base32 RFC 4648 (A-Z2-7), uppercase, tanpa padding.
     */
    public static function base32Encode(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $out = '';
        $buffer = 0;
        $bits = 0;
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($raw[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::BASE32_ALPHABET[($buffer >> $bits) & 0x1F];
            }
        }

        if ($bits > 0) {
            $out .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }

        return $out;
    }

    /**
     * Decode base32 ke data biner.
     *
     * Toleran terhadap spasi, tanda hubung, lowercase, dan padding `=`
     * (secret sering disalin dari aplikasi authenticator dengan format
     * berkelompok). Karakter di luar alfabet base32 ⇒
     * `InvalidArgumentException`.
     */
    public static function base32Decode(string $encoded): string
    {
        $normalized = strtoupper($encoded);
        $normalized = (string) preg_replace('/[\s\-=]+/', '', $normalized);
        if ($normalized === '') {
            return '';
        }

        $out = '';
        $buffer = 0;
        $bits = 0;
        $length = strlen($normalized);

        for ($i = 0; $i < $length; $i++) {
            $index = strpos(self::BASE32_ALPHABET, $normalized[$i]);
            if ($index === false) {
                throw new InvalidArgumentException('Secret base32 tidak valid.');
            }
            $buffer = ($buffer << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $out;
    }

    /**
     * Kode HOTP/TOTP untuk timestamp tertentu.
     *
     * Counter = `intdiv($timestamp, $period)`; HMAC-SHA1 atas counter 64-bit
     * big-endian; dynamic truncation (RFC 4226 §5.4).
     *
     * @throws InvalidArgumentException secret base32/digits/period tidak valid
     */
    public static function code(
        string $secret,
        int $timestamp,
        int $digits = self::DIGITS,
        int $period = self::PERIOD
    ): string {
        if ($period < 1) {
            throw new InvalidArgumentException('Periode TOTP tidak valid.');
        }
        if ($digits < 1 || $digits > 10) {
            throw new InvalidArgumentException('Jumlah digit TOTP tidak valid.');
        }

        $key = self::base32Decode($secret);
        if ($key === '') {
            throw new InvalidArgumentException('Secret TOTP kosong.');
        }

        $counter = intdiv($timestamp, $period);
        $hash = hash_hmac(self::ALGORITHM, pack('J', $counter), $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = unpack('N', substr($hash, $offset, 4));
        $value = ((int) ($part[1] ?? 0)) & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verifikasi kode TOTP dengan toleransi `$window` langkah ke belakang/maju.
     *
     * @return int|null langkah (step) yang cocok, atau `null` bila tidak cocok
     */
    public static function verify(
        string $secret,
        string $code,
        ?int $timestamp = null,
        int $window = 1
    ): ?int {
        $secret = trim($secret);
        if ($secret === '') {
            return null;
        }

        $normalized = self::normalizeCode($code);
        if ($normalized === null) {
            return null;
        }

        $now = $timestamp ?? time();
        $window = max(0, $window);
        $currentStep = intdiv($now, self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $currentStep + $offset;
            if ($step < 0) {
                continue;
            }

            try {
                $expected = self::code($secret, $step * self::PERIOD);
            } catch (InvalidArgumentException) {
                return null;
            }

            if (hash_equals($expected, $normalized)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * URI `otpauth://totp/...` untuk dipindai aplikasi authenticator.
     */
    public static function provisioningUri(
        string $secret,
        string $account,
        string $issuer,
        int $digits = self::DIGITS,
        int $period = self::PERIOD
    ): string {
        $label = $issuer . ':' . $account;

        return 'otpauth://totp/' . rawurlencode($label)
            . '?secret=' . rawurlencode(strtoupper(trim($secret)))
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=' . strtoupper(self::ALGORITHM)
            . '&digits=' . $digits
            . '&period=' . $period;
    }

    /**
     * Normalisasi kode masukan: buang spasi/tanda hubung, wajib 6 digit.
     */
    private static function normalizeCode(string $code): ?string
    {
        $normalized = (string) preg_replace('/[\s\-]+/', '', trim($code));

        return preg_match('/^\d{6}$/', $normalized) === 1 ? $normalized : null;
    }
}
