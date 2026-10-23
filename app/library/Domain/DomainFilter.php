<?php
declare(strict_types=1);

namespace app\library\Domain;

/**
 * Filter daftar domain berbasis **query string** (`?domain=…`) untuk halaman
 * `/domains` (SPECS §8j / ARCHITECTURE §5.38).
 *
 * Statik murni — tanpa I/O, tanpa state, **tanpa otorisasi**. Kelas ini tidak
 * tahu siapa pemanggilnya: `DomainController::index()` yang menyaring baris app
 * lewat `AppAccess::visible()` (dan membuang baris parkir untuk non-admin)
 * **sebelum** filter diterapkan, sehingga filter tidak pernah menambah baris
 * yang tidak berhak dilihat.
 *
 * Aturan pencocokan: substring **case-insensitive** pada `domain`
 * (`mb_strtolower`, aman untuk domain ber-unicode). Daftar hasil mempertahankan
 * urutan input dan selalu di-reindex menjadi `list`; nilai kosong/absen berarti
 * filter tidak aktif (semua baris dikembalikan apa adanya).
 *
 * Dipakai untuk **kedua** tab: baris tabel "Semua Domain" (`rows`) dan daftar
 * CRUD "Domain Parkir" (`domains`) — keduanya memakai key `domain`, sehingga
 * satu kata kunci menghasilkan himpunan yang konsisten di dua tab.
 */
final class DomainFilter
{
    /** Nama parameter query string. */
    public const QUERY_KEY = 'domain';

    /**
     * Normalisasi input filter menjadi kata kunci efektif.
     *
     * Nilai non-skalar (mis. `?domain[]=x`) diperlakukan sebagai kosong —
     * jangan biarkan input tak terduga menjadi string "Array".
     */
    public static function normalize(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        return self::lower(trim((string) $raw));
    }

    /** Ada filter aktif? */
    public static function isActive(string $needle): bool
    {
        return self::normalize($needle) !== '';
    }

    /**
     * Saring daftar baris/entri berdasarkan `domain`.
     *
     * @param array<int|string,mixed> $items baris ber-key `domain`
     * @return list<array<string,mixed>>
     */
    public static function apply(array $items, string $needle): array
    {
        $needle = self::normalize($needle);

        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($needle !== '' && !str_contains(self::lower((string) ($item['domain'] ?? '')), $needle)) {
                continue;
            }
            $filtered[] = $item;
        }

        return $filtered;
    }

    /** Lowercase Unicode-safe (domain bisa memuat karakter non-ASCII). */
    private static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }
}
