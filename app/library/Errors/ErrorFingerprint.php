<?php
declare(strict_types=1);

namespace app\library\Errors;

use Throwable;

/**
 * Sidik jari pengelompokan error ("Rekam Error") — statik murni, tanpa I/O.
 *
 * Satu fingerprint = satu baris `error_groups`. Karena itu normalisasi di sini
 * menentukan kualitas pengelompokan: pesan yang sama dengan angka, UUID,
 * nilai acak, path absolut, atau kutipan berbeda **harus** menghasilkan
 * fingerprint yang sama, sedangkan kelas exception/frame asal yang berbeda
 * harus terpisah.
 *
 * Aturan normalisasi teks (urutan penting):
 *  1. string dalam kutip tunggal/ganda → `?`,
 *  2. UUID → `UUID`,
 *  3. literal heksadesimal `0x…` → `0xN`,
 *  4. path absolut panjang (`/home/…`, `/var/…`, `/tmp/…`) → `/…/`,
 *  5. semua deret angka → `N`,
 *  6. whitespace dirapatkan menjadi satu spasi,
 *  7. hasil dipotong 300 byte (UTF-8 safe).
 *
 * Kelas ini **tidak pernah melempar**: ia dipanggil dari jalur pelaporan error,
 * dan kegagalan hashing/fingerprint tidak boleh berubah menjadi kegagalan
 * request. Bila terjadi hal tak terduga, dipakai fingerprint tetap yang aman.
 */
final class ErrorFingerprint
{
    /** Batas byte per komponen setelah normalisasi. */
    public const MAX_COMPONENT_BYTES = 300;

    /** Sidik jari darurat bila normalisasi gagal total. */
    private const FALLBACK_SERVER = 'server|unknown';

    private const FALLBACK_CLIENT = 'client|unknown';

    /**
     * Fingerprint laporan klien:
     * `sha256(kind | normalized message | top stack frame | fallback route)`.
     *
     * @param array<string,mixed> $event
     */
    public static function client(array $event): string
    {
        try {
            $kind = self::text($event['kind'] ?? 'client');
            $kind = $kind === '' ? 'client' : strtolower($kind);

            $message = self::normalize(self::text($event['message'] ?? ''));
            $frame = self::clientFrame($event);
            $route = self::normalize(ErrorRedactor::routePath(self::text($event['route'] ?? '')));

            $anchor = $frame !== '' ? $frame : $route;

            return hash('sha256', $kind . '|' . $message . '|' . $anchor);
        } catch (Throwable) {
            return hash('sha256', self::FALLBACK_CLIENT);
        }
    }

    /**
     * Fingerprint exception server:
     * `sha256(class | normalized message | top app frame)`.
     */
    public static function server(Throwable $e, string $route): string
    {
        try {
            $location = self::serverLocation($e);
            if ($location === '') {
                $location = self::normalize(ErrorRedactor::routePath($route));
            } else {
                // Angka (nomor baris) dinormalisasi hanya untuk hashing; kolom
                // `location` yang tampil memakai `serverLocation()` apa adanya.
                $location = self::normalize($location);
            }

            return hash(
                'sha256',
                get_class($e) . '|' . self::normalize($e->getMessage()) . '|' . $location
            );
        } catch (Throwable) {
            return hash('sha256', self::FALLBACK_SERVER);
        }
    }

    /**
     * Frame aplikasi teratas dari exception, bentuk `app/…/File.php:123`
     * (relatif terhadap `base_path()`), atau `''` bila tak ada frame di
     * `app/`/`public/`.
     *
     * Dipakai juga oleh {@see ErrorReporter} untuk kolom `location` sehingga
     * fingerprint dan lokasi tampilan tidak pernah menyimpang.
     */
    public static function serverLocation(Throwable $e): string
    {
        try {
            $base = self::basePath();

            $file = $e->getFile();
            $line = $e->getLine();
            if (self::isAppFile($file, $base)) {
                return self::relativeFrame($file, $line, $base);
            }

            foreach ($e->getTrace() as $frame) {
                $traceFile = $frame['file'] ?? '';
                if (is_string($traceFile) && self::isAppFile($traceFile, $base)) {
                    return self::relativeFrame($traceFile, (int) ($frame['line'] ?? 0), $base);
                }
            }
        } catch (Throwable) {
            // Jangan pernah melempar dari jalur pelaporan error.
        }

        return '';
    }

    /**
     * Normalisasi teks untuk perbandingan fingerprint.
     */
    public static function normalize(string $value): string
    {
        try {
            if ($value === '') {
                return '';
            }

            // 1. String dalam kutip (isi bisa acak/rahasia).
            $value = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $value);
            $value = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $value);

            // 2. UUID (sebelum angka supaya bentuknya tidak hancur).
            $value = (string) preg_replace(
                '/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/',
                'UUID',
                $value
            );

            // 3. Literal heksadesimal.
            $value = (string) preg_replace('/\b0x[0-9a-fA-F]+\b/', '0xN', $value);

            // 4. Path absolut panjang (root rumah/var/tmp).
            $value = (string) preg_replace(
                '#/(?:home|var|tmp)/[^\s\'"()\[\]{}<>]+#',
                '/…/',
                $value
            );

            // 5. Semua angka.
            $value = (string) preg_replace('/\d+/', 'N', $value);

            // 6. Whitespace.
            $value = (string) preg_replace('/\s+/', ' ', $value);

            return self::truncate(trim($value));
        } catch (Throwable) {
            return '';
        }
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Frame teratas stack klien (baris pertama yang memuat `/app/` atau
     * `/public/`), fallback lokasi, lalu route.
     *
     * @param array<string,mixed> $event
     */
    private static function clientFrame(array $event): string
    {
        $stack = self::text($event['stack'] ?? '');
        if ($stack !== '') {
            foreach (preg_split('/\R/', $stack) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && (str_contains($line, '/app/') || str_contains($line, '/public/'))) {
                    return self::normalize($line);
                }
            }
        }

        $location = self::normalize(self::text($event['location'] ?? ''));
        if ($location !== '') {
            return $location;
        }

        return self::normalize(self::text($event['source'] ?? ''));
    }

    /** Nilai skalar menjadi string; non-skalar ⇒ `''`. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** `base_path()` tanpa membocorkan exception bila helper belum siap. */
    private static function basePath(): string
    {
        try {
            if (function_exists('base_path')) {
                return rtrim((string) base_path(), '/');
            }
        } catch (Throwable) {
            // abaikan — jalur tanpa base path tetap aman
        }

        return '';
    }

    /** Apakah berkas berada di `<base>/app/` atau `<base>/public/`. */
    private static function isAppFile(string $file, string $base): bool
    {
        if ($file === '') {
            return false;
        }
        if ($base === '') {
            return str_contains($file, '/app/') || str_contains($file, '/public/');
        }

        return str_starts_with($file, $base . '/app/') || str_starts_with($file, $base . '/public/');
    }

    /** `file:line` relatif terhadap base path (tanpa normalisasi angka). */
    private static function relativeFrame(string $file, int $line, string $base): string
    {
        if ($base !== '' && str_starts_with($file, $base . '/')) {
            $file = substr($file, strlen($base) + 1);
        } elseif ($base === '') {
            $file = ltrim($file, '/');
        }

        return $file . ':' . $line;
    }

    /** Potong ke {@see self::MAX_COMPONENT_BYTES} byte tanpa merusak UTF-8. */
    private static function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_COMPONENT_BYTES) {
            return $value;
        }

        return function_exists('mb_strcut')
            ? (string) mb_strcut($value, 0, self::MAX_COMPONENT_BYTES, 'UTF-8')
            : substr($value, 0, self::MAX_COMPONENT_BYTES);
    }
}
