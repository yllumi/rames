<?php
declare(strict_types=1);

namespace app\library\Api;

/**
 * Batas permukaan API eksternal — **satu sumber** prefix path.
 *
 * Dipakai tiga middleware sekaligus (`ApiAuthMiddleware` sebagai penjaga,
 * `AuthMiddleware` & `CsrfMiddleware` sebagai pengecualian). Prefix yang
 * menyimpang di salah satu lapisan akan membuka/menutup permukaan secara tidak
 * konsisten, jadi jangan menulis ulang literal `/api/v1/`.
 */
final class ApiSurface
{
    public const PREFIX = '/api/v1/';

    /** Apakah path berada di permukaan API eksternal. */
    public static function contains(string $path): bool
    {
        return str_starts_with($path, self::PREFIX);
    }
}
