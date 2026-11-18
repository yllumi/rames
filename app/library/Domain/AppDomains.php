<?php
declare(strict_types=1);

namespace app\library\Domain;

use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use RuntimeException;

/**
 * Domain per app: subdomain efektif, `custom_domain` (domain **utama**), dan
 * daftar **domain tambahan/alias** (`apps.json.custom_domains`).
 *
 * Desain data (aditif — dokumen lama aman):
 *  - `custom_domain` (string) tetap domain utama, perilakunya tidak berubah
 *    (subdomain dialihkan 301 ke sana);
 *  - `custom_domains` = daftar entri `{domain, ssl_status, ssl_stage,
 *    ssl_message, ssl_error, ssl_expires_at, created_at}`. Setiap alias
 *    **melayani app langsung** (tanpa redirect) dan punya status SSL sendiri;
 *  - alias boleh ada tanpa domain utama; batasnya {@see self::MAX_ALIASES}.
 *
 * Slot SSL: `ssl` (subdomain), `custom_ssl` (domain utama), dan
 * `alias:<domain>` (domain tambahan, domain lowercase). Satu pintu pemetaan
 * domain ↔ slot ada di {@see self::slotOf()} / {@see self::domainOfSlot()};
 * status dibaca/ditulis lewat {@see self::status()} /
 * {@see self::writeStatus()} sehingga tidak ada pemanggil yang menebak nama
 * field.
 *
 * Kelas ini **statik murni**: tanpa state, tanpa otorisasi, tanpa I/O tulis.
 * `ownerOf()` hanya membaca dokumen app yang diberikan pemanggil (tidak
 * menyentuh store) — pemanggil yang menentukan cakupan server lewat
 * `$onlyServerId` (biasanya `AppStore::serverIdOf()`).
 *
 * Validasi input memakai {@see SslIssuer::isPublicDomain()} (sumber yang sama
 * dengan jalur SSL) supaya alias yang tersimpan pasti bisa di-SSL-kan; nama
 * dengan TLD `.local`/`.internal` dsb. ditolak — sama seperti `custom_domain`.
 */
final class AppDomains
{
    /** Batas jumlah domain tambahan per app. */
    public const MAX_ALIASES = 20;

    /** Awalan slot domain tambahan (`alias:<domain>`). */
    public const ALIAS_SLOT_PREFIX = 'alias:';

    /** Slot lama: subdomain bawaan app. */
    public const SLOT_SUBDOMAIN = 'ssl';

    /** Slot lama: custom domain utama. */
    public const SLOT_PRIMARY = 'custom_ssl';

    /** Sufiks field status yang dikenal (tanpa awalan `ssl_`). */
    private const STATUS_KEYS = ['status', 'stage', 'message', 'error', 'expires_at'];

    /**
     * Normalisasi domain: lowercase, trim, buang titik akhir FQDN
     * (`Example.COM.` → `example.com`).
     */
    public static function normalizeDomain(string $raw): string
    {
        return rtrim(strtolower(trim($raw)), '.');
    }

    /**
     * FQDN publik yang sah? (wajib ber-titik — minimal dua label — dan lolos
     * {@see SslIssuer::isPublicDomain()}).
     */
    public static function isValidFqdn(string $domain): bool
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '' || !str_contains($domain, '.')) {
            return false;
        }

        return SslIssuer::isPublicDomain($domain);
    }

    /**
     * Entri domain tambahan yang tersanitasi (urutan dipertahankan).
     *
     * Dibuang: entri bukan array, `domain` bukan string, domain kosong/tidak
     * valid, dan duplikat (kemunculan pertama menang). Hasil dipotong ke
     * {@see self::MAX_ALIASES} entri. `domain` di setiap entri sudah
     * dinormalisasi (lowercase, tanpa titik akhir).
     *
     * @param array<string,mixed> $app
     * @return list<array<string,mixed>>
     */
    public static function aliases(array $app): array
    {
        $raw = $app['custom_domains'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $aliases = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['domain'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $domain = self::normalizeDomain($value);
            if ($domain === '' || isset($seen[$domain]) || !self::isValidFqdn($domain)) {
                continue;
            }
            $seen[$domain] = true;
            $entry['domain'] = $domain;
            // Field status bisa rusak (data sunting manual): jangan biarkan
            // non-skalar lolos ke cast `(string)` di view/libs (warning
            // "Array to string conversion"). Nilai tak sah ⇒ null/`disabled`.
            $entry['ssl_status'] = self::scalarText($entry['ssl_status'] ?? null, 'disabled');
            foreach (self::STATUS_KEYS as $key) {
                if ($key === 'status') {
                    continue;
                }
                $field = 'ssl_' . $key;
                if (array_key_exists($field, $entry) && !is_scalar($entry[$field])) {
                    $entry[$field] = null;
                }
            }
            $aliases[] = $entry;
            if (count($aliases) >= self::MAX_ALIASES) {
                break;
            }
        }

        return $aliases;
    }

    /**
     * Seluruh domain app dalam urutan tampil: subdomain efektif → domain utama
     * (bila ada) → domain tambahan. Semua lowercase, tanpa duplikat.
     *
     * @param array<string,mixed> $app
     * @return list<string>
     */
    public static function allDomains(array $app): array
    {
        $domains = [];
        $add = static function (string $candidate) use (&$domains): void {
            $domain = self::normalizeDomain($candidate);
            if ($domain !== '' && !in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        };

        $add(app_subdomain_of($app));
        $add((string) ($app['custom_domain'] ?? ''));
        foreach (self::aliases($app) as $alias) {
            $add((string) ($alias['domain'] ?? ''));
        }

        return $domains;
    }

    /**
     * Slot SSL untuk sebuah domain milik app (`null` bila domain bukan milik
     * app ini).
     *
     * Urutan prioritas: subdomain efektif → custom domain utama → alias.
     */
    public static function slotOf(array $app, string $domain): ?string
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return null;
        }

        if ($domain === self::normalizeDomain(app_subdomain_of($app))) {
            return self::SLOT_SUBDOMAIN;
        }

        $primary = self::normalizeDomain((string) ($app['custom_domain'] ?? ''));
        if ($primary !== '' && $domain === $primary) {
            return self::SLOT_PRIMARY;
        }

        if (self::hasAlias($app, $domain)) {
            return self::ALIAS_SLOT_PREFIX . $domain;
        }

        return null;
    }

    /**
     * Domain yang diwakili sebuah slot — `null` bila slot tidak berlaku untuk
     * app ini (mis. `alias:x` yang entrinya sudah dihapus).
     *
     * @param array<string,mixed> $app
     */
    public static function domainOfSlot(array $app, string $slot): ?string
    {
        if ($slot === self::SLOT_SUBDOMAIN) {
            return app_subdomain_of($app);
        }

        if ($slot === self::SLOT_PRIMARY) {
            $primary = self::normalizeDomain((string) ($app['custom_domain'] ?? ''));

            return $primary !== '' ? $primary : null;
        }

        if (!self::isAliasSlot($slot)) {
            return null;
        }

        $domain = self::normalizeDomain(substr($slot, strlen(self::ALIAS_SLOT_PREFIX)));

        return $domain !== '' && self::hasAlias($app, $domain) ? $domain : null;
    }

    /** Apakah slot berbentuk `alias:<domain>`? */
    public static function isAliasSlot(string $slot): bool
    {
        return str_starts_with($slot, self::ALIAS_SLOT_PREFIX)
            && self::normalizeDomain(substr($slot, strlen(self::ALIAS_SLOT_PREFIX))) !== '';
    }

    /**
     * Status SSL satu slot.
     *
     * Alias ⇒ dibaca dari entri `custom_domains`; `ssl`/`custom_ssl` ⇒ dari
     * field datar lama (`ssl_*` / `custom_ssl_*`). Slot yang tidak berlaku
     * (alias sudah dihapus, domain utama kosong) mengembalikan status
     * `disabled` dengan field lain `null` — pemanggil tidak perlu bercabang.
     *
     * @param array<string,mixed> $app
     * @return array{ssl_status:string,ssl_stage:mixed,ssl_message:mixed,ssl_error:mixed,ssl_expires_at:mixed}
     */
    public static function status(array $app, string $slot): array
    {
        if ($slot === self::SLOT_SUBDOMAIN || $slot === self::SLOT_PRIMARY) {
            return [
                'ssl_status' => (string) ($app[$slot . '_status'] ?? 'disabled'),
                'ssl_stage' => $app[$slot . '_stage'] ?? null,
                'ssl_message' => $app[$slot . '_message'] ?? null,
                'ssl_error' => $app[$slot . '_error'] ?? null,
                'ssl_expires_at' => $app[$slot . '_expires_at'] ?? null,
            ];
        }

        $entry = self::aliasEntry($app, $slot);
        if ($entry === null) {
            return self::disabledStatus();
        }

        return [
            'ssl_status' => self::scalarText($entry['ssl_status'] ?? null, 'disabled'),
            'ssl_stage' => $entry['ssl_stage'] ?? null,
            'ssl_message' => $entry['ssl_message'] ?? null,
            'ssl_error' => $entry['ssl_error'] ?? null,
            'ssl_expires_at' => $entry['ssl_expires_at'] ?? null,
        ];
    }

    /**
     * Tulis status SSL ke slot yang tepat.
     *
     * `$fields` memakai nama field penuh (`ssl_status`, `ssl_stage`,
     * `ssl_message`, `ssl_error`, `ssl_expires_at`); nama tanpa awalan
     * (`status`, `stage`, …) juga diterima. Slot alias yang entrinya sudah
     * tidak ada **diabaikan** (tidak membuat entri baru, tidak melempar) —
     * worker SSL yang selesai setelah alias dihapus tidak boleh menghidupkan
     * kembali domain itu.
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $fields
     */
    public static function writeStatus(array &$app, string $slot, array $fields): void
    {
        if ($slot === self::SLOT_SUBDOMAIN || $slot === self::SLOT_PRIMARY) {
            foreach (self::normalizedFields($fields) as $suffix => $value) {
                $app[$slot . '_' . $suffix] = $value;
            }

            return;
        }

        $domain = self::domainOfSlot($app, $slot);
        if ($domain === null) {
            return;
        }

        $raw = $app['custom_domains'] ?? null;
        if (!is_array($raw)) {
            return;
        }

        foreach ($raw as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (self::normalizeDomain((string) ($entry['domain'] ?? '')) !== $domain) {
                continue;
            }
            foreach (self::normalizedFields($fields) as $suffix => $value) {
                $app['custom_domains'][$index]['ssl_' . $suffix] = $value;
            }

            return;
        }
    }

    /**
     * App lain (di server yang sama) yang sudah memakai `$domain` — atau `null`
     * bila bebas.
     *
     * `$excludeId` = id app yang sedang disunting (dilewati). `$onlyServerId`
     * = cakupan server (sudah dinormalisasi pemanggil; `null` = semua server).
     * Hasil `{app_name, kind}` dengan `kind` = `subdomain` | `primary` |
     * `alias` — tidak pernah membocorkan dokumen app.
     *
     * @param array<int|string,mixed> $apps
     * @return array{app_name:string,kind:string}|null
     */
    public static function ownerOf(array $apps, string $domain, string $excludeId = '', ?string $onlyServerId = null): ?array
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return null;
        }

        $scope = $onlyServerId === null ? null : AppStore::normalizeServerId($onlyServerId);

        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }
            if ((string) ($app['id'] ?? '') === $excludeId) {
                continue;
            }
            if ($scope !== null && AppStore::serverIdOf($app) !== $scope) {
                continue;
            }

            $kind = self::kindOf($app, $domain);
            if ($kind === null) {
                continue;
            }

            return [
                'app_name' => (string) ($app['name'] ?? ($app['id'] ?? '?')),
                'kind' => $kind,
            ];
        }

        return null;
    }

    /**
     * Pastikan `$domain` boleh ditambahkan sebagai alias app ini.
     *
     * @param array<string,mixed> $app
     * @throws RuntimeException FQDN tidak valid, duplikat, sama dengan
     *                          subdomain/domain utama app ini, atau batas
     *                          {@see self::MAX_ALIASES} tercapai.
     */
    public static function assertAliasAddable(array $app, string $domain): void
    {
        $domain = self::normalizeDomain($domain);

        if (!self::isValidFqdn($domain)) {
            throw new RuntimeException('Domain tambahan harus FQDN publik yang valid (mis. alias.example.org).');
        }

        if (self::hasAlias($app, $domain)) {
            throw new RuntimeException('Domain ' . $domain . ' sudah terdaftar sebagai domain tambahan app ini.');
        }

        if ($domain === self::normalizeDomain(app_subdomain_of($app))) {
            throw new RuntimeException('Domain tambahan tidak boleh sama dengan subdomain bawaan app.');
        }

        $primary = self::normalizeDomain((string) ($app['custom_domain'] ?? ''));
        if ($primary !== '' && $domain === $primary) {
            throw new RuntimeException('Domain tambahan tidak boleh sama dengan custom domain utama app.');
        }

        if (count(self::aliases($app)) >= self::MAX_ALIASES) {
            throw new RuntimeException('Maksimal ' . self::MAX_ALIASES . ' domain tambahan per app.');
        }
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Jenis pemakaian domain oleh app: `subdomain` | `primary` | `alias`.
     *
     * @param array<string,mixed> $app
     */
    private static function kindOf(array $app, string $domain): ?string
    {
        if ($domain === self::normalizeDomain(app_subdomain_of($app))) {
            return 'subdomain';
        }
        if ($domain === self::normalizeDomain((string) ($app['custom_domain'] ?? ''))) {
            return 'primary';
        }

        return self::hasAlias($app, $domain) ? 'alias' : null;
    }

    /**
     * Apakah `$domain` (sudah dinormalisasi) terdaftar sebagai alias app?
     *
     * Publik karena pemanggil lain (mis. `setDomain`) harus bisa menolak domain
     * utama yang bentrok dengan alias app itu sendiri — `ownerOf()` sengaja
     * melewati app sendiri.
     */
    public static function hasAlias(array $app, string $domain): bool
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return false;
        }

        foreach (self::aliases($app) as $alias) {
            if ((string) ($alias['domain'] ?? '') === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entri `custom_domains` untuk slot alias — `null` bila slot bukan alias
     * yang masih terdaftar.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>|null
     */
    private static function aliasEntry(array $app, string $slot): ?array
    {
        $domain = self::domainOfSlot($app, $slot);
        if ($domain === null) {
            return null;
        }

        foreach (self::aliases($app) as $alias) {
            if ((string) ($alias['domain'] ?? '') === $domain) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * Normalisasi `$fields` → `suffix => value` (`ssl_status` → `status`).
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function normalizedFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $suffix = str_starts_with($key, 'ssl_') ? substr($key, strlen('ssl_')) : $key;
            if (!in_array($suffix, self::STATUS_KEYS, true)) {
                continue;
            }
            $normalized[$suffix] = $value;
        }

        return $normalized;
    }

    /**
     * @return array{ssl_status:string,ssl_stage:mixed,ssl_message:mixed,ssl_error:mixed,ssl_expires_at:mixed}
     */
    /**
     * Cast aman untuk field teks status: non-skalar (array/objek dari data
     * rusak) tidak pernah di-cast `(string)` (menghindari warning PHP).
     */
    private static function scalarText(mixed $value, string $fallback): string
    {
        if (is_string($value)) {
            $text = trim($value);

            return $text !== '' ? $text : $fallback;
        }

        return is_int($value) || is_float($value) ? (string) $value : $fallback;
    }

    private static function disabledStatus(): array
    {
        return [
            'ssl_status' => 'disabled',
            'ssl_stage' => null,
            'ssl_message' => null,
            'ssl_error' => null,
            'ssl_expires_at' => null,
        ];
    }
}
