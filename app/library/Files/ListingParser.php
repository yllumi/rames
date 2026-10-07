<?php
declare(strict_types=1);

namespace app\library\Files;

/**
 * Parser keluaran `stat` dari dalam container → daftar entri direktori.
 *
 * Logika murni (tanpa I/O) sehingga dapat diuji tanpa Docker. Format keluaran
 * dibangun oleh `ContainerFiles` dengan pemisah kendali:
 *   field  : US (`\x1f`)  → file-type | size | mtime | mode | name | link
 *   record : RS (`\x1e`)
 *
 * Dipilih pemisah kendali (bukan newline) karena nama berkas boleh memuat
 * spasi; pemisah kendali jauh lebih jarang muncul di nama berkas.
 */
final class ListingParser
{
    public const US = "\x1f";
    public const RS = "\x1e";

    /**
     * @return array<int,array{name:string,type:string,size:int,mtime:?int,mode:?string,link:?string}>
     */
    public static function parse(string $raw, string $dir): array
    {
        $dir = PathGuard::normalize($dir);
        $entries = [];

        if ($raw === '') {
            return $entries;
        }

        foreach (explode(self::RS, $raw) as $record) {
            if ($record === '') {
                continue;
            }
            $parts = explode(self::US, $record);
            if (count($parts) < 5) {
                continue;
            }
            $parts = array_pad($parts, 6, '');

            $full = $parts[4];
            $name = self::nameFrom($full, $dir);
            if ($name === '') {
                continue;
            }
            $link = $parts[5];
            $entries[] = [
                'name' => $name,
                'type' => self::typeOf($parts[0]),
                'size' => (int) $parts[1],
                'mtime' => $parts[2] === '' ? null : (int) $parts[2],
                'mode' => $parts[3] === '' ? null : $parts[3],
                'link' => $link === '' ? null : $link,
            ];
        }

        return self::sortEntries($entries);
    }

    /**
     * Petakan deskripsi tipe `stat -c %F` (GNU & BusyBox) ke tipe kontrak.
     */
    public static function typeOf(string $fileType): string
    {
        $lower = strtolower($fileType);
        if (str_contains($lower, 'directory')) {
            return 'dir';
        }
        if (str_contains($lower, 'symbolic link')) {
            return 'link';
        }
        if (str_contains($lower, 'regular')) {
            return 'file';
        }

        return 'other';
    }

    /**
     * Direktori lebih dulu, lalu nama case-insensitive.
     *
     * @param array<int,array{name:string,type:string}> $entries
     * @return array<int,array{name:string,type:string}>
     */
    public static function sortEntries(array $entries): array
    {
        usort($entries, static function (array $a, array $b): int {
            $adir = $a['type'] === 'dir' ? 0 : 1;
            $bdir = $b['type'] === 'dir' ? 0 : 1;
            if ($adir !== $bdir) {
                return $adir <=> $bdir;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    /**
     * Ambil nama entri dari path absolut hasil `stat` berdasarkan direktori
     * induk yang diketahui.
     */
    private static function nameFrom(string $full, string $dir): string
    {
        if ($full === '') {
            return '';
        }
        if ($dir === '/') {
            return ltrim($full, '/');
        }
        if (str_starts_with($full, $dir . '/')) {
            return substr($full, strlen($dir) + 1);
        }

        return basename($full);
    }
}
