<?php
declare(strict_types=1);

namespace app\middleware;

use app\library\Api\ApiSurface;
use app\library\Api\ApiKeyAuth;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Gerbang autentikasi API eksternal (`/api/v1/**`, docs/SPECS.md §7.19).
 *
 * Middleware **global** di mode dashboard (`config/middleware.php`) — dipasang
 * setelah `AuthMiddleware`, dan hanya menyentuh prefix `/api/v1/`. Konsekuensi
 * penting:
 *
 *  - `AuthMiddleware` & `CsrfMiddleware` **melewati** prefix ini (klien mesin
 *    tidak punya sesi/cookie dashboard), jadi satu-satunya penjaga
 *    autentikasi permukaan `/api/v1/**` adalah middleware ini. Route API baru
 *    otomatis tertutup — **DILARANG** mendaftarkan route `/api/v1/*` di luar
 *    cakupan middleware ini;
 *  - **tidak ada pengecualian**: setiap path `/api/v1/**` wajib API key
 *    per user (header `Authorization: Bearer <api-key>`).
 *    Dokumentasi OpenAPI (`/api-docs` + `/api-docs/openapi.json`) berada di
 *    luar prefix itu dan dijaga sesi login dashboard.
 *
 * Kebijakan: API key per user (dibuat/dicabut di halaman Profil). Key tidak
 * ada/salah/format salah/user sudah dihapus ⇒ **401 tanpa detail** (body
 * identik untuk semua penyebab, tanpa oracle). Tidak ada token global di env.
 *
 * Tanpa state lintas-request: key & user dibaca per request lewat
 * {@see ApiKeyAuth}; tidak ada `session()`/properti yang menyimpan hasil.
 */
final class ApiAuthMiddleware implements MiddlewareInterface
{
    /** Alias kompatibel — sumber nilai ada di {@see ApiSurface}. */
    public const PREFIX = ApiSurface::PREFIX;

    private readonly ?ApiKeyAuth $auth;

    /** @param ApiKeyAuth|null $auth seam uji: verifier dengan store user temp */
    public function __construct(?ApiKeyAuth $auth = null)
    {
        $this->auth = $auth;
    }

    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();
        if (!ApiSurface::contains($path)) {
            return $handler($request);
        }

        $auth = $this->auth ?? new ApiKeyAuth();
        if ($auth->authenticate($request) === null) {
            return $this->json(401, 'Unauthorized', 'unauthorized');
        }

        return $handler($request);
    }

    private function json(int $status, string $error, ?string $reason = null): Response
    {
        $payload = ['code' => $status, 'error' => $error];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}
