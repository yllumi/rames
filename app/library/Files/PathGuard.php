<?php
declare(strict_types=1);

namespace app\library\Files;

use InvalidArgumentException;

/**
 * Validasi & normalisasi path/nama untuk file manager container.
 *
 * Kelas statik murni (tanpa I/O) sehingga bisa diuji tanpa Docker. Semua path
 * yang berasal dari request WAJIB melewati kelas ini sebelum diteruskan ke
 * `docker exec`/`docker cp`, agar:
 *   - path selalu absolut (`/...`) — cegah argumen diinterpretasi sebagai opsi;
 *   - `.`/`..` diselesaikan secara leksikal — cegah keluar dari akar;
 *   - karakter kontrol (NUL/CR/LF) yang bisa memecah perintah ditolak.
 *
 * Nama entri (mkdir/rename/unggah) tidak boleh memuat `/` maupun diawali `-`
 * (cegah injeksi opsi pada perintah destruktif).
 */
final class PathGuard
{
    /** Panjang maksimum komponen nama (batas umum filesystem). */
    public const MAX_NAME_BYTES = 255;

    /**
     * Normalisasi path absolut menjadi bentuk kanonik leksikal.
     *
     * @throws InvalidArgumentException path tidak valid
     */
    public static function normalize(string $path): string
    {
        $path = self::assertNoControl($path);
        if ($path === '') {
            throw new InvalidArgumentException('Path kosong.');
        }
        if ($path[0] !== '/') {
            throw new InvalidArgumentException('Path harus absolut (diawali "/").');
        }

        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($out === []) {
                    throw new InvalidArgumentException('Path tidak boleh keluar dari akar (".." berlebih).');
                }
                array_pop($out);
                continue;
            }
            $out[] = $part;
        }

        return '/' . implode('/', $out);
    }

    /**
     * Normalisasi path untuk operasi destruktif (delete) — menolak akar `/`.
     *
     * @throws InvalidArgumentException
     */
    public static function assertDeletable(string $path): string
    {
        $normalized = self::normalize($path);
        if ($normalized === '/') {
            throw new InvalidArgumentException('Tidak boleh menghapus akar filesystem "/".');
        }

        return $normalized;
    }

    /**
     * Validasi satu nama entri (nama berkas/direktori tanpa separator).
     *
     * @throws InvalidArgumentException
     */
    public static function assertName(string $name): string
    {
        $name = self::assertNoControl($name);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Nama kosong.');
        }
        if ($name === '.' || $name === '..') {
            throw new InvalidArgumentException('Nama tidak valid.');
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new InvalidArgumentException('Nama tidak boleh mengandung "/".');
        }
        if ($name[0] === '-') {
            throw new InvalidArgumentException('Nama tidak boleh diawali "-".');
        }
        if (strlen($name) > self::MAX_NAME_BYTES) {
            throw new InvalidArgumentException('Nama terlalu panjang.');
        }

        return $name;
    }

    /**
     * Gabungkan direktori + nama entri menjadi path absolut kanonik.
     *
     * @throws InvalidArgumentException
     */
    public static function resolveChild(string $dir, string $name): string
    {
        $dir = self::normalize($dir);
        $name = self::assertName($name);

        return $dir === '/' ? '/' . $name : $dir . '/' . $name;
    }

    /**
     * Direktori induk dari sebuah path absolut (null untuk akar).
     */
    public static function parentOf(string $path): ?string
    {
        $path = self::normalize($path);
        if ($path === '/') {
            return null;
        }
        $pos = strrpos($path, '/');

        return $pos === 0 ? '/' : substr($path, 0, $pos);
    }

    /**
     * Apakah `$path` berada di dalam (atau sama dengan) `$root` — perbandingan
     * leksikal path absolut yang sudah dinormalisasi.
     */
    public static function contains(string $root, string $path): bool
    {
        $root = rtrim($root, '/');
        if ($root === '') {
            $root = '/';
        }
        if ($root === '/') {
            return str_starts_with($path, '/');
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertNoControl(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Nilai memuat karakter NUL.');
        }
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException('Nilai memuat karakter baris baru.');
        }

        return $value;
    }
}
