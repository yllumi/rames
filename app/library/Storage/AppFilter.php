<?php
declare(strict_types=1);

namespace app\library\Storage;

/**
 * Filter daftar app berbasis **query string** (docs/SPECS.md §8e / §7.7): `name`,
 * `domain`, `owner`.
 *
 * Statik murni — tanpa I/O, tanpa state, **tanpa otorisasi**. Kelas ini tidak
 * tahu siapa pemanggilnya: `AppController::index()` yang memutuskan filter mana
 * yang berhak dihormati (mis. `owner` hanya untuk admin) dan sudah menyaring
 * daftar app lewat `AppAccess::visible()` sebelum masuk ke sini.
 *
 * Aturan pencocokan:
 *  - `name`   — substring **case-insensitive** pada `$app['name']`;
 *  - `domain` — substring case-insensitive pada domain **efektif**
 *               ({@see app_subdomain_of()} — satu sumber resolusi subdomain,
 *               jangan menebak dari `name`) **atau** `$app['custom_domain']`;
 *  - `owner`  — **cocok persis** dengan `(string) ($app['owner_id'] ?? '')`;
 *  - string kosong / key tidak ada ⇒ filter itu tidak aktif; semua filter
 *    digabung **AND**.
 *
 * Input dinormalisasi lewat {@see self::normalize()} (`trim()` + `mb_strtolower`
 * untuk perbandingan — jangan `strtolower()` pada string non-ASCII), sehingga
 * nilai efektif yang dikirim ke view berasal dari **satu** tempat.
 */
final class AppFilter
{
    /** Key filter yang dikenal (urutan ini dipakai `normalize()`). */
    public const KEYS = ['name', 'domain', 'owner'];

    /**
     * Saring daftar app.
     *
     * Urutan input dipertahankan, hasil di-reindex (`array_values`) sehingga
     * selalu berbentuk `list`. Tanpa filter aktif daftar dikembalikan apa
     * adanya (hanya di-reindex) — perilaku lama tidak berubah.
     *
     * @param array<int|string,mixed> $apps
     * @param array<string,mixed>     $filters
     * @return list<array<string,mixed>>
     */
    public static function apply(array $apps, array $filters): array
    {
        $normalized = self::normalize($filters);
        if (!self::isActive($normalized)) {
            return array_values($apps);
        }

        $filtered = [];
        foreach ($apps as $app) {
            if (is_array($app) && self::matches($app, $normalized)) {
                $filtered[] = $app;
            }
        }

        return $filtered;
    }

    /**
     * Apakah satu app lolos **seluruh** filter aktif?
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $filters
     */
    public static function matches(array $app, array $filters): bool
    {
        $normalized = self::normalize($filters);

        if ($normalized['name'] !== ''
            && !str_contains(self::lower((string) ($app['name'] ?? '')), $normalized['name'])) {
            return false;
        }

        if ($normalized['domain'] !== '' && !self::domainMatches($app, $normalized['domain'])) {
            return false;
        }

        if ($normalized['owner'] !== '' && (string) ($app['owner_id'] ?? '') !== $normalized['owner']) {
            return false;
        }

        return true;
    }

    /**
     * Ada filter aktif? (dipakai controller untuk `filterActive` di view)
     *
     * @param array<string,mixed> $filters
     */
    public static function isActive(array $filters): bool
    {
        $normalized = self::normalize($filters);

        foreach (self::KEYS as $key) {
            if ($normalized[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalisasi filter mentah (biasanya dari `$request->get()`) menjadi tiga
     * key string yang selalu ada: `trim()` + `mb_strtolower` untuk `name`/
     * `domain`; `owner` hanya di-`trim()` (cocok persis, id bersifat sensitif
     * huruf). Nilai non-skalar (mis. `?name[]=x`) diperlakukan sebagai kosong.
     *
     * @param array<string,mixed> $filters
     * @return array{name:string,domain:string,owner:string}
     */
    public static function normalize(array $filters): array
    {
        return [
            'name' => self::lower(self::text($filters['name'] ?? '')),
            'domain' => self::lower(self::text($filters['domain'] ?? '')),
            'owner' => self::text($filters['owner'] ?? ''),
        ];
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Domain efektif app cocok dengan jarum (sudah lowercase/trim) — atau
     * `custom_domain`-nya cocok.
     *
     * @param array<string,mixed> $app
     */
    private static function domainMatches(array $app, string $needle): bool
    {
        if (str_contains(self::lower(app_subdomain_of($app)), $needle)) {
            return true;
        }

        $custom = self::lower(self::text($app['custom_domain'] ?? ''));

        return $custom !== '' && str_contains($custom, $needle);
    }

    /** Nilai filter menjadi string; non-skalar/absen ⇒ string kosong. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Lowercase Unicode-safe (nama/domain bisa berisi karakter non-ASCII). */
    private static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }
}
