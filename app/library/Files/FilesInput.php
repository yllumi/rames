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
}
