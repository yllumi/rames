<?php
declare(strict_types=1);

namespace app\library\Files;

/**
 * Validasi arsip (.zip/.tar.gz) sebelum/tanpa ekstraksi — logika yang dapat
 * diuji tanpa Docker (kecuali pemindaian symlink & pengukuran yang memakai
 * direktori temp nyata).
 *
 * Proteksi utama:
 *   - hanya `.zip`, `.tar.gz`, `.tgz` yang diterima;
 *   - entri dengan path absolut / drive Windows / komponen `..` DITOLAK
 *     (zip-slip);
 *   - symlink yang menunjuk keluar direktori ekstraksi DITOLAK;
 *   - jumlah entri & total byte hasil ekstrak dibatasi.
 *
 * Ekstraksi dilakukan di host dashboard ke direktori temp, baru disalin ke
 * container (`docker cp`) — tidak bergantung pada tool `unzip`/`tar` di dalam
 * container app yang bisa saja tidak tersedia.
 */
final class ArchiveGuard
{
    /** Batas jumlah entri (rekursif) hasil ekstraksi. */
    public const MAX_ENTRIES = 10000;

    /** Batas total byte hasil ekstraksi (1 GiB). */
    public const MAX_EXTRACT_BYTES = 1073741824;

    /** Jenis arsip yang didukung, atau null bila tidak didukung. */
    public static function kind(string $name): ?string
    {
        $lower = strtolower($name);
        if (str_ends_with($lower, '.zip')) {
            return 'zip';
        }
        if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz')) {
            return 'tar';
        }

        return null;
    }

    /**
     * Alasan entri ditolak, atau null bila aman. Pemeriksaan leksikal: nama
     * tidak boleh kosong, memuat NUL, berawalan `/`, ber-drive Windows, atau
     * memiliki komponen `..` (setelah `\` dinormalkan ke `/`).
     */
    public static function entryError(string $name): ?string
    {
        if ($name === '') {
            return 'Nama entri kosong.';
        }
        if (str_contains($name, "\0")) {
            return 'Entri memuat karakter NUL.';
        }
        $normalized = str_replace('\\', '/', $name);
        if (str_starts_with($normalized, '/')) {
            return 'Entri berpath absolut.';
        }
        if (preg_match('#^[A-Za-z]:#', $normalized) === 1) {
            return 'Entri berpath drive Windows.';
        }
        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') {
                return 'Entri keluar dari direktori ekstraksi ("..").';
            }
        }

        return null;
    }

    /**
     * Nama entri pertama yang tidak aman, atau null bila semua aman.
     *
     * @param array<int,string> $names
     */
    public static function unsafeEntry(array $names): ?string
    {
        foreach ($names as $name) {
            if (self::entryError((string) $name) !== null) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * Parse baris ringkasan `unzip -l` → ['bytes' => int, 'entries' => int].
     *
     * @return array{bytes:int,entries:int}|null
     */
    public static function parseZipTotal(string $listing): ?array
    {
        if (preg_match('/(\d+)\s+(\d+)\s+files?\s*$/m', $listing, $m) !== 1) {
            return null;
        }

        return ['bytes' => (int) $m[1], 'entries' => (int) $m[2]];
    }

    /**
     * Symlink pertama (path lengkap) yang target-nya keluar dari `$root`,
     * atau null bila aman. Symlink absolut selalu dianggap keluar.
     */
    public static function escapingSymlink(string $root): ?string
    {
        $root = rtrim($root, '/');
        if ($root === '') {
            $root = '/';
        }
        if (!is_dir($root)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $path => $info) {
            $path = (string) $path;
            if (!is_link($path)) {
                continue;
            }
            $target = @readlink($path);
            if ($target === false || $target === '') {
                return $path;
            }
            if ($target[0] === '/') {
                return $path;
            }
            try {
                $resolved = PathGuard::normalize(dirname($path) . '/' . $target);
            } catch (\InvalidArgumentException $e) {
                return $path;
            }
            if (!PathGuard::contains($root, $resolved)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Hitung jumlah entri (termasuk direktori) & total byte berkas reguler di
     * bawah `$root`.
     *
     * @return array{entries:int,bytes:int}
     */
    public static function measure(string $root): array
    {
        $entries = 0;
        $bytes = 0;
        if (!is_dir($root)) {
            return ['entries' => 0, 'bytes' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $path => $info) {
            $entries++;
            if (!$info->isDir() && !is_link((string) $path)) {
                $bytes += (int) $info->getSize();
            }
        }

        return ['entries' => $entries, 'bytes' => $bytes];
    }
}
