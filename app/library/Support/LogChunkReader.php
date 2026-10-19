<?php
declare(strict_types=1);

namespace app\library\Support;

/**
 * Pembaca potongan berkas log berbasis offset — **satu implementasi** untuk
 * log job agent (`AgentJobStore`) dan log deploy app (`DeployLog`).
 *
 * Dipakai polling di UI/control plane: setiap panggilan mengembalikan potongan
 * ≤ {@see self::MAX_READ} byte mulai `offset`, dan pemanggil memakai
 * `offset + strlen(hasil)` sebagai offset berikutnya. Potongan tidak pernah
 * berhenti di tengah baris/UTF-8 selama berkas memuat newline.
 *
 * Kelas statis murni (tanpa state) ⇒ aman di worker Webman persistent.
 */
final class LogChunkReader
{
    /** Plafon baca per panggilan (256 KiB) — log deploy bisa panjang. */
    public const MAX_READ = 262144;

    /** Batas offset yang diterima (menahan angka absurd dari query string). */
    public const MAX_OFFSET = 1073741824; // 1 GiB

    /**
     * Baca potongan log mulai `offset` byte.
     *
     * Berkas tidak ada/tidak terbaca ⇒ string kosong (pemanggil tidak perlu
     * membedakan "belum ada log" dari "log kosong").
     */
    public static function read(string $path, int $offset = 0): string
    {
        if (!is_file($path)) {
            return '';
        }

        $offset = max(0, min($offset, self::MAX_OFFSET));
        $size = filesize($path);
        if ($size === false || $offset >= $size) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                return '';
            }
            $chunk = fread($handle, self::MAX_READ);
        } finally {
            fclose($handle);
        }

        if ($chunk === false || $chunk === '') {
            return '';
        }

        // Berakhir persis di EOF ⇒ tidak ada yang dipangkas.
        if ($offset + strlen($chunk) >= $size || str_ends_with($chunk, "\n")) {
            return $chunk;
        }

        $lastNewline = strrpos($chunk, "\n");
        if ($lastNewline === false || $lastNewline === 0) {
            return $chunk; // satu baris raksasa: serahkan apa adanya
        }

        return substr($chunk, 0, $lastNewline + 1);
    }
}
