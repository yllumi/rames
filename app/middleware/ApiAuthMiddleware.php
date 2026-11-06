<?php
declare(strict_types=1);

namespace app\middleware;

use app\library\Api\ApiSurface;
use app\library\Api\ApiTokenAuth;
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
 *  - **tidak ada pengecualian**: setiap path `/api/v1/**` wajib bearer token.
 *    Dokumentasi OpenAPI (`/api-docs` + `/api-docs/openapi.json`) berada di
 *    luar prefix itu dan dijaga sesi login dashboard.
 *
 * Kebijakan:
 *  - token `RAMES_API_TOKEN` kosong ⇒ API **mati**: `/api/v1/*` menjawab 404
 *    (permukaan disembunyikan, bukan 401 yang mengonfirmasi keberadaan API);
 *  - token salah / tidak ada / format salah / user aktor hilang ⇒ **401 tanpa
 *    detail** (body identik untuk semua penyebab, tanpa oracle).
 *
 * Tanpa state lintas-request: token & user dibaca per request lewat
 * {@see ApiTokenAuth}; tidak ada `session()`/properti yang menyimpan hasil.
 */
final class ApiAuthMiddleware implements MiddlewareInterface
{
    /** Alias kompatibel — sumber nilai ada di {@see ApiSurface}. */
    public const PREFIX = ApiSurface::PREFIX;

    private readonly ?ApiTokenAuth $auth;

    /** @param ApiTokenAuth|null $auth seam uji: verifier dengan store user temp */
    public function __construct(?ApiTokenAuth $auth = null)
    {
        $this->auth = $auth;
    }

    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();
        if (!ApiSurface::contains($path)) {
            return $handler($request);
        }

        $auth = $this->auth ?? new ApiTokenAuth();
        if (!$auth->enabled()) {
            return $this->json(404, 'Not Found');
        }

        if ($auth->actorFromRequest($request) === null) {
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
