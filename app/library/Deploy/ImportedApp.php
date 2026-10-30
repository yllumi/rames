<?php
declare(strict_types=1);

namespace app\library\Deploy;

use RuntimeException;

/**
 * Guard & konteks app hasil impor project compose eksternal.
 *
 * **Kenapa app impor tidak menjalankan `docker compose`**: project aslinya
 * dikelola user di terminal (berkas compose + environment hidup di direktori
 * proyek mereka, di luar Rames). Menjalankan `up/down/build` dari dashboard
 * akan menimpa override yang tidak kita miliki dan dapat menciptakan ulang
 * container yang sedang dipakai. Karena itu Rames hanya **memantau** container
 * dan mengelola domain/SSL/log/terminal/file/database.
 *
 * Statik murni — tanpa I/O — sehingga aman dipakai di worker persistent dan
 * mudah diuji.
 */
final class ImportedApp
{
    /**
     * Apakah app adalah hasil impor project compose eksternal.
     */
    public static function isImported(array $app): bool
    {
        return ComposeSource::isImported($app);
    }

    /**
     * Pesan penolakan fitur yang membutuhkan orkestrasi compose.
     */
    public static function manageableError(array $app, string $feature): string
    {
        $dir = trim((string) ($app['compose_dir'] ?? ''));
        $suffix = $dir !== '' ? ' (direktori: ' . $dir . ')' : '';

        return $feature . ' tidak tersedia untuk app yang diimpor dari project compose eksternal'
            . $suffix . '. Ubah berkas compose & jalankan `docker compose` di direktori proyek itu;'
            . ' Rames hanya memantau container dan mengelola domain/SSL, log, terminal, file, serta database.';
    }

    /**
     * Fail-fast bila app impor (fitur butuh orkestrasi compose).
     *
     * @throws RuntimeException
     */
    public static function assertManageable(array $app, string $feature): void
    {
        if (self::isImported($app)) {
            throw new RuntimeException(self::manageableError($app, $feature));
        }
    }

    /**
     * Konteks view app impor (dipakai controller, bukan view langsung).
     *
     * @return array{imported:bool,compose_dir:string,compose_paths:array<int,string>,imported_at:string}
     */
    public static function context(array $app): array
    {
        $paths = [];
        foreach ((array) ($app['compose_paths'] ?? []) as $path) {
            $path = (string) $path;
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return [
            'imported' => self::isImported($app),
            'compose_dir' => (string) ($app['compose_dir'] ?? ''),
            'compose_paths' => $paths,
            'imported_at' => (string) ($app['imported_at'] ?? ''),
        ];
    }
}
