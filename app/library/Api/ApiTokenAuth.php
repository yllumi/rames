<?php
declare(strict_types=1);

namespace app\library\Api;

use app\library\Auth\UserStore;
use Webman\Http\Request;

/**
 * Verifikasi token API eksternal dashboard → **user aktor**.
 *
 * Model (mengikuti pola `AGENT_TOKEN`, bukan skema API-key per user):
 *  - satu token bearer di `RAMES_API_TOKEN` (`config('deploy.api_token')`);
 *  - token **tidak** punya hak sendiri — ia dipetakan ke user dashboard
 *    `RAMES_API_TOKEN_USER` (default `admin`). Seluruh otorisasi app tetap
 *    lewat `AppAccess` dengan user itu, dan gerbang kredit tetap menilai user
 *    itu (admin ⇒ bebas kredit). Tidak ada "superuser" tersembunyi di luar
 *    model hak yang sudah ada;
 *  - token kosong ⇒ API **mati** (fail-closed). Tidak ada fallback ke sesi.
 *
 * Token dibandingkan `hash_equals()` (constant-time), format header wajib
 * `Authorization: Bearer <token>` (skema case-insensitive). User aktor diambil
 * bentuk **publik** (`findPublicById`) supaya `password_hash`/secret 2FA tidak
 * pernah ikut ke lapisan API.
 *
 * Stateless: token/user dibaca per pemanggilan, tidak ada cache properti
 * lintas-request (worker Webman persistent).
 */
final class ApiTokenAuth
{
    public const HEADER = 'Authorization';
    public const SCHEME = 'Bearer';

    private readonly string $token;
    private readonly string $username;
    private readonly ?UserStore $users;

    /**
     * @param string|null    $token    token yang diharapkan (null = config)
     * @param string|null    $username username user aktor (null = config)
     * @param UserStore|null $users    seam uji: store user temp
     */
    public function __construct(?string $token = null, ?string $username = null, ?UserStore $users = null)
    {
        $this->token = trim($token ?? (string) config('deploy.api_token', ''));
        $configuredUser = trim($username ?? (string) config('deploy.api_token_user', ''));
        $this->username = $configuredUser === '' ? 'admin' : $configuredUser;
        $this->users = $users;
    }

    /** Apakah API diaktifkan (token terkonfigurasi)? */
    public function enabled(): bool
    {
        return $this->token !== '';
    }

    /** Username user aktor yang dipetakan ke token (bukan rahasia). */
    public function username(): string
    {
        return $this->username;
    }

    /**
     * User aktor dari header `Authorization` — `null` bila token salah, kosong,
     * format salah, atau user aktornya tidak ada di store (fail-closed).
     *
     * @return array<string,mixed>|null
     */
    public function actorFromHeader(?string $header): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $presented = self::bearerToken($header);
        if ($presented === null || !hash_equals($this->token, $presented)) {
            return null;
        }

        return $this->actor();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function actorFromRequest(Request $request): ?array
    {
        $header = $request->header(self::HEADER);

        return $this->actorFromHeader(is_string($header) ? $header : null);
    }

    /**
     * Ambil token dari nilai header `Authorization`.
     *
     * Hanya skema bearer yang diterima (`Bearer <token>`); nilai lain (`Basic …`,
     * token telanjang) dianggap tidak ada — tidak ada parsing longgar yang bisa
     * membuka jalur alternatif.
     */
    public static function bearerToken(?string $header): ?string
    {
        $value = trim((string) $header);
        if ($value === '' || preg_match('/^Bearer\s+(\S+)$/i', $value, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function actor(): ?array
    {
        $users = $this->users ?? new UserStore();
        $user = $users->findByUsername($this->username);
        if ($user === null) {
            return null;
        }

        return $users->findPublicById((string) ($user['id'] ?? ''));
    }
}
