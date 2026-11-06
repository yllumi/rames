<?php
declare(strict_types=1);

namespace app\library\Api;

use app\library\Auth\AppAccess;
use app\library\Storage\AppStore;

/**
 * Resolusi app dari **nama (slug)** untuk API eksternal.
 *
 * Aturan:
 *  - slug dinormalkan seperti form create app (trim + lowercase);
 *  - pencarian hanya pada app yang **boleh dilihat** aktor
 *    (`AppAccess::visible()` — satu pintu hak akses), sehingga app milik user
 *    lain tidak pernah bisa dijangkau lewat tebakan nama;
 *  - nama yang dipakai beberapa app (multi-server) **tidak ditebak**: dilempar
 *    {@see AmbiguousAppSlug} supaya pemanggil membalas 409.
 *
 * Stateless (hanya store yang di-inject untuk uji).
 */
final class ApiAppLocator
{
    /** Pola nama app — sama dengan `CreateController::validateCreateName()`. */
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    public const SLUG_MAX_LENGTH = 63;

    private readonly ?AppStore $store;

    public function __construct(?AppStore $store = null)
    {
        $this->store = $store;
    }

    /** Normalisasi slug input API (trim + lowercase, seperti form create). */
    public static function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    /** Bentuk slug sah? (huruf kecil/angka/strip, ≤63, bukan diawali/diakhiri strip) */
    public static function isValidSlug(string $slug): bool
    {
        return $slug !== ''
            && strlen($slug) <= self::SLUG_MAX_LENGTH
            && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /**
     * App terlihat dengan nama tersebut, atau `null` bila tidak ada / slug tidak
     * sah / aktor tidak punya akses.
     *
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>|null
     * @throws AmbiguousAppSlug
     */
    public function findVisible(string $slug, ?array $actor): ?array
    {
        $name = self::normalizeSlug($slug);
        if (!self::isValidSlug($name)) {
            return null;
        }

        $matches = [];
        foreach (AppAccess::visible($this->store()->all(), $actor) as $app) {
            if ((string) ($app['name'] ?? '') === $name) {
                $matches[] = $app;
            }
        }

        if (count($matches) > 1) {
            throw new AmbiguousAppSlug($name);
        }

        return $matches[0] ?? null;
    }

    private function store(): AppStore
    {
        return $this->store ?? new AppStore();
    }
}
