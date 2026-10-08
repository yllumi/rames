<?php
declare(strict_types=1);

namespace app\library\Files;

/**
 * Penerjemah field request → parameter operasi file (logika murni, dapat diuji).
 *
 * Dipisah dari controller agar kontrak nama field dengan UI (`public/js/app-files.js`)
 * punya satu sumber kebenaran dan bisa diverifikasi tanpa HTTP:
 *   - `write`  : `text` (kontrak) dengan fallback `content`; kedua absen → 400
 *                (JANGAN pernah menulis berkas kosong karena field hilang).
 *   - `mkdir`  : `path` = direktori induk + `name` = nama folder → `<path>/<name>`;
 *                `name` kosong → `path` diperlakukan sebagai target absolut (kompatibilitas).
 *   - `rename` : `to` (path absolut) diprioritaskan; jika tidak ada, `name` =
 *                nama baru di direktori induk sumber.
 *   - `move`   : `path` = sumber, `dest` = direktori tujuan, `name` = nama entri
 *                di tujuan (opsional, default nama sumber).
 */
final class FilesInput
{
    /**
     * Isi berkas untuk `write`.
     *
     * @throws FileError 400 bila kedua field absen
     */
    public static function text(mixed $text, mixed $content): string
    {
        if ($text !== null) {
            return (string) $text;
        }
        if ($content !== null) {
            return (string) $content;
        }

        throw new FileError('Field isi berkas ("text") tidak dikirim.', 400);
    }

    /**
     * Target direktori untuk `mkdir`.
     *
     * @throws \InvalidArgumentException path/nama tidak valid
     */
    public static function mkdirTarget(string $parent, mixed $name): string
    {
        $parent = PathGuard::normalize($parent);
        $name = trim((string) ($name ?? ''));
        if ($name === '') {
            return $parent;
        }

        return PathGuard::resolveChild($parent, $name);
    }

    /**
     * Target path untuk `rename`.
     *
     * @throws \InvalidArgumentException path/nama tidak valid
     */
    public static function renameTarget(string $from, mixed $to, mixed $name): string
    {
        $from = PathGuard::normalize($from);
        $to = trim((string) ($to ?? ''));
        if ($to !== '') {
            return PathGuard::normalize($to);
        }

        $name = PathGuard::assertName((string) ($name ?? ''));
        $parent = PathGuard::parentOf($from) ?? '/';

        return PathGuard::resolveChild($parent, $name);
    }

    /**
     * Rencana operasi `move` (pindah berkas/folder) — logika MURNI, tanpa I/O,
     * sehingga bisa diuji tanpa Docker. Pemeriksaan keberadaan sumber/tujuan dan
     * bentrok nama dilakukan `ContainerFiles::move()` (butuh container).
     *
     * Aturan:
     *  - sumber tidak boleh akar `/`;
     *  - `dest` = direktori tujuan (wajib absolut, dinormalisasi);
     *  - `name` = nama entri di tujuan (opsional; default = nama sumber);
     *  - nama divalidasi `PathGuard::assertName()` (tanpa `/`, tak diawali `-`);
     *  - tujuan tidak boleh berada di dalam sumber (diri sendiri/descendant).
     *
     * @return array{from:string,dir:string,target:string}
     * @throws FileError sumber `/` atau tujuan di dalam sumber
     * @throws \InvalidArgumentException path/nama tidak valid
     */
    public static function moveTarget(mixed $from, mixed $dest, mixed $name = null): array
    {
        $source = self::moveSource($from);
        $dir = PathGuard::normalize((string) ($dest ?? ''));
        $entry = trim((string) ($name ?? ''));
        if ($entry === '') {
            $entry = (string) basename($source);
        }
        $entry = PathGuard::assertName($entry);
        if (PathGuard::contains($source, $dir)) {
            throw new FileError('Tidak dapat memindahkan entri ke dalam dirinya sendiri.', 400);
        }

        return [
            'from' => $source,
            'dir' => $dir,
            'target' => PathGuard::resolveChild($dir, $entry),
        ];
    }

    /**
     * Sumber `move`: path absolut kanonik, TIDAK boleh akar filesystem.
     *
     * @throws FileError
     * @throws \InvalidArgumentException
     */
    public static function moveSource(mixed $path): string
    {
        $from = PathGuard::normalize((string) ($path ?? ''));
        if ($from === '/') {
            throw new FileError('Tidak dapat memindahkan akar filesystem "/".', 400);
        }

        return $from;
    }
}
