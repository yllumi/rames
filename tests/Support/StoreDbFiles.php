<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Helper pembersihan berkas basis data SQLite untuk tes.
 *
 * SQLite bermode WAL membuat berkas pendamping (`-wal`, `-shm`) di samping
 * berkas utama; keduanya harus ikut dibersihkan agar direktori temp tidak
 * menumpuk antar-tes. `StoreDbFiles::of()` juga menyertakan `<file>.bak`
 * (sisa pola JsonStore lama) supaya bertambah-toleran.
 */
final class StoreDbFiles
{
    /**
     * Semua berkas yang menyertai sebuah berkas basis data .sqlite.
     *
     * @return array<int,string>
     */
    public static function of(string $dbFile): array
    {
        return [$dbFile, $dbFile . '-wal', $dbFile . '-shm', $dbFile . '.bak'];
    }

    /**
     * Hapus berkas basis data + pendampingnya (abaikan yang tidak ada).
     */
    public static function remove(string $dbFile): void
    {
        foreach (self::of($dbFile) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
