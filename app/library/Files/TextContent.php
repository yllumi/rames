<?php
declare(strict_types=1);

namespace app\library\Files;

/**
 * Deteksi berkas teks vs biner + batas ukuran edit — logika murni (tanpa I/O).
 *
 * Editor teks file manager hanya boleh membuka berkas yang aman direpresentasikan
 * sebagai string JSON (UTF-8). Berkas biner / non-UTF-8 ditolak agar `json()`
 * tidak menghasilkan body kosong dan agar UI tidak menampilkan sampah.
 */
final class TextContent
{
    /** Batas maksimum byte yang boleh dibuka sebagai teks (2 MiB). */
    public const MAX_TEXT_BYTES = 2097152;

    /** Ambang rasio karakter kontrol (selain tab/LF/CR) menandakan biner. */
    private const CONTROL_RATIO = 0.01;

    /**
     * Apakah konten tampak biner (NUL, bukan UTF-8 valid, atau terlalu banyak
     * karakter kontrol).
     */
    public static function isBinary(string $content): bool
    {
        if (str_contains($content, "\0")) {
            return true;
        }
        // `preg_match('//u', ...)` gagal (false) bila subjek bukan UTF-8 valid.
        if (@preg_match('//u', $content) !== 1) {
            return true;
        }

        $len = strlen($content);
        if ($len === 0) {
            return false;
        }
        $control = 0;
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($content[$i]);
            // izinkan tab (9), LF (10), CR (13); tolak kontrol lain < 32 dan DEL.
            if (($ord < 32 && $ord !== 9 && $ord !== 10 && $ord !== 13) || $ord === 127) {
                $control++;
            }
        }

        return $control > 0 && ($control / $len) > self::CONTROL_RATIO;
    }

    public static function isTooLarge(int $bytes): bool
    {
        return $bytes > self::MAX_TEXT_BYTES;
    }
}
