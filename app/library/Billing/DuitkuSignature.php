<?php
declare(strict_types=1);

namespace app\library\Billing;

use DateTimeImmutable;

/**
 * Penandatanganan permintaan/callback Duitku (Web API v2).
 *
 * Duitku **mengobsoletkan** MD5 & SHA-256 biasa (changelog Apr 2026) — satu-satunya
 * skema yang sah sekarang adalah **HMAC-SHA256 hex lowercase**:
 *
 *   hash_hmac('sha256', $stringToSign, $apiKey)
 *
 * Kelas ini statik murni & **tanpa I/O** (mudah diuji). String-to-sign tiap operasi:
 *
 *   inquiry           : merchantCode + merchantOrderId + paymentAmount
 *   transactionStatus : merchantCode + merchantOrderId
 *   getpaymentmethod  : merchantCode + paymentAmount + datetime (Y-m-d H:i:s)
 *   callback          : merchantCode + amount + merchantOrderId
 *
 * Catatan: `merchantCode` di payload `getpaymentmethod` dikirim dengan kunci huruf
 * kecil (`merchantcode`), tetapi NILAI yang ditandatangani tetap sama.
 */
final class DuitkuSignature
{
    /** Format datetime yang diwajibkan Duitku untuk `getpaymentmethod`. */
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private function __construct()
    {
    }

    public static function inquiryStringToSign(string $merchantCode, string $merchantOrderId, int $paymentAmount): string
    {
        return $merchantCode . $merchantOrderId . $paymentAmount;
    }

    public static function statusStringToSign(string $merchantCode, string $merchantOrderId): string
    {
        return $merchantCode . $merchantOrderId;
    }

    public static function methodsStringToSign(string $merchantCode, int $paymentAmount, string $datetime): string
    {
        return $merchantCode . $paymentAmount . $datetime;
    }

    public static function callbackStringToSign(string $merchantCode, string|int $amount, string $merchantOrderId): string
    {
        return $merchantCode . $amount . $merchantOrderId;
    }

    public static function inquiry(string $merchantCode, string $merchantOrderId, int $paymentAmount, string $apiKey): string
    {
        return hash_hmac('sha256', self::inquiryStringToSign($merchantCode, $merchantOrderId, $paymentAmount), $apiKey);
    }

    public static function status(string $merchantCode, string $merchantOrderId, string $apiKey): string
    {
        return hash_hmac('sha256', self::statusStringToSign($merchantCode, $merchantOrderId), $apiKey);
    }

    public static function methods(string $merchantCode, int $paymentAmount, string $datetime, string $apiKey): string
    {
        return hash_hmac('sha256', self::methodsStringToSign($merchantCode, $paymentAmount, $datetime), $apiKey);
    }

    public static function callback(string $merchantCode, string|int $amount, string $merchantOrderId, string $apiKey): string
    {
        return hash_hmac('sha256', self::callbackStringToSign($merchantCode, $amount, $merchantOrderId), $apiKey);
    }

    /**
     * Verifikasi signature callback dengan perbandingan waktu-tetap (`hash_equals`).
     *
     * Hex Duitku tidak dijamin huruf besar/kecil, jadi signature dibandingkan
     * case-insensitive (dinormalisasi ke lowercase) — tetap aman karena
     * `hash_equals` mencegah timing attack.
     */
    public static function verifyCallback(
        string $merchantCode,
        string|int $amount,
        string $merchantOrderId,
        string $signature,
        string $apiKey,
    ): bool {
        $expected = self::callback($merchantCode, $amount, $merchantOrderId, $apiKey);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * Datetime format Duitku (`Y-m-d H:i:s`). `$now` kosong/null ⇒ waktu server.
     * Input yang tidak bisa diparse ⇒ waktu server (tanpa melempar).
     */
    public static function datetime(?string $now = null): string
    {
        if ($now === null || trim($now) === '') {
            return date(self::DATETIME_FORMAT);
        }
        try {
            return (new DateTimeImmutable($now))->format(self::DATETIME_FORMAT);
        } catch (\Throwable) {
            return date(self::DATETIME_FORMAT);
        }
    }
}
