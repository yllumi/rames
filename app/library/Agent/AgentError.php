<?php
declare(strict_types=1);

namespace app\library\Agent;

use RuntimeException;

/**
 * Kegagalan berbicara dengan agent (control plane → agent) atau kegagalan
 * konfigurasi pemanggilan agent.
 *
 * `reason` = alasan mesin-terbaca (bukan untuk ditampilkan apa adanya ke UI):
 *  - `unreachable`    : koneksi gagal / timeout / host tak resolve
 *  - `tls_failed`     : TLS diverifikasi gagal (cert tidak cocok dengan pin)
 *  - `unauthorized`   : HTTP 401/403 (token ditolak)
 *  - `not_found`      : HTTP 404 (endpoint/job tidak ada di agent)
 *  - `bad_response`   : respons bukan JSON/HTTP 3xx/4xx lain — agent tak sesuai kontrak
 *  - `server_error`   : HTTP 5xx (agent gagal memproses)
 *  - `unsupported`    : HTTP 501 / op tidak didukung agent (capabilities)
 *  - `invalid_config` : konfigurasi server cacat (base_url bukan https, token kosong,
 *                       PEM sertifikat hilang/rusak) — **fail-fast sebelum efek samping**
 *
 * **Higienitas kredensial**: pesan exception **tidak boleh** memuat token,
 * signature, atau header `Authorization`. Konstruktor menyanitasi setiap pesan
 * lewat {@see self::sanitize()}; `AgentClient` menambahkan token-nya sendiri
 * sebagai argumen kedua supaya token yang bocor dari body/host error ikut
 * di-redaksi (`[redacted]`). Pola ini mengikuti `DuitkuClient::sanitize()`.
 */
final class AgentError extends RuntimeException
{
    public const UNREACHABLE = 'unreachable';
    public const TLS_FAILED = 'tls_failed';
    public const UNAUTHORIZED = 'unauthorized';
    public const NOT_FOUND = 'not_found';
    public const BAD_RESPONSE = 'bad_response';
    public const SERVER_ERROR = 'server_error';
    public const UNSUPPORTED = 'unsupported';
    public const INVALID_CONFIG = 'invalid_config';
    public const UNKNOWN = 'unknown';

    /** Batas panjang pesan — pesan galat tidak boleh menjadi salinan body agent. */
    public const MAX_MESSAGE_LENGTH = 500;

    public function __construct(
        string $message,
        public readonly string $reason = self::UNKNOWN,
    ) {
        parent::__construct(self::sanitize($message));
    }

    /** Agent tidak bisa dihubungi (koneksi/timeout/DNS). */
    public static function unreachable(string $message): self
    {
        return new self($message, self::UNREACHABLE);
    }

    /** Verifikasi TLS gagal (sertifikat tidak cocok dengan yang di-pin). */
    public static function tlsFailed(string $message): self
    {
        return new self($message, self::TLS_FAILED);
    }

    /** Token ditolak agent (HTTP 401/403). */
    public static function unauthorized(string $message): self
    {
        return new self($message, self::UNAUTHORIZED);
    }

    /** Endpoint/job tidak ada di agent (HTTP 404). */
    public static function notFound(string $message): self
    {
        return new self($message, self::NOT_FOUND);
    }

    /** Respons agent tidak sesuai kontrak (bukan JSON, 3xx, atau 4xx lain). */
    public static function badResponse(string $message): self
    {
        return new self($message, self::BAD_RESPONSE);
    }

    /** Agent gagal memproses permintaan (HTTP 5xx). */
    public static function serverError(string $message): self
    {
        return new self($message, self::SERVER_ERROR);
    }

    /** Operasi tidak didukung agent (HTTP 501 / di luar capabilities). */
    public static function unsupported(string $message): self
    {
        return new self($message, self::UNSUPPORTED);
    }

    /**
     * Konfigurasi server cacat (base_url bukan `https://`, token kosong,
     * PEM sertifikat hilang/rusak). Wajib dilempar **sebelum** efek samping.
     */
    public static function invalidConfig(string $message): self
    {
        return new self($message, self::INVALID_CONFIG);
    }

    /**
     * Buang kredensial/material sensitif dari pesan apa pun
     * (sabuk pengaman higienitas, pola `DuitkuClient::sanitize()`).
     *
     * @param string $token token server bila diketahui pemanggil (opsional)
     */
    public static function sanitize(string $message, string $token = ''): string
    {
        if ($message === '') {
            return '';
        }

        if ($token !== '') {
            $message = str_replace([$token, rawurlencode($token)], '[redacted]', $message);
        }

        // Header Authorization yang tercecer ke pesan exception.
        $message = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message);

        // Nilai header signature (hex) yang tercecer ke pesan exception.
        $message = (string) preg_replace(
            '/(x-rames-signature"?\s*[:=]\s*)[A-Fa-f0-9]{16,}/i',
            '$1[redacted]',
            $message
        );

        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return self::truncate($message, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Potong pesan pada batas karakter (bukan byte) supaya hasilnya tetap UTF-8
     * valid — pesan galat bisa ikut di-`json_encode()` oleh controller.
     */
    private static function truncate(string $message, int $max): string
    {
        if (strlen($message) <= $max) {
            return $message;
        }

        $cut = function_exists('mb_strcut') ? mb_strcut($message, 0, $max, 'UTF-8') : substr($message, 0, $max);

        return rtrim((string) $cut) . '…';
    }
}
