<?php
declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Validasi token CSRF untuk semua request yang mengubah state (POST/PUT/PATCH/DELETE).
 *
 * PENGECUALIAN SEMPIT — proxy Adminer (`/database/{container}/adminer…`):
 * halaman Adminer di-render di dalam helper container dan punya token CSRF-nya
 * sendiri; form-nya (termasuk unggahan multipart import) diposting ke prefix
 * proxy. Request itu harus dilewatkan **sebelum** `$request->post()` dipanggil,
 * karena `post()` mem-parse body multipart — kita meneruskan body mentah
 * (`rawBody()`) agar batas ukuran dan isinya utuh.
 *
 * Kompensasi keamanan (pengecualian TIDAK melebarkan permukaan serang):
 *  1. `AuthMiddleware` tetap berjalan pada rantai yang sama (urutan global:
 *     CSRF → Auth), jadi request tanpa sesi login tetap ditolak/redirect.
 *  2. Otorisasi per-app tetap dicek di controller lewat `AppAccess`
 *     (ability `database`, operator+) dan penolakan tetap 404.
 *  3. Cookie sesi Rames memakai `SameSite=Lax` sehingga request lintas-situs
 *     tidak membawa sesi (dan proxy tidak pernah meneruskan cookie browser ke
 *     helper).
 *  4. Pemetaannya path-presisi: hanya `#^/database/[^/]+/adminer(/|$)#` — route
 *     lain (`/database/{container}/query`, `/apps`, …) tetap wajib token.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /** Path proxy Adminer (satu-satunya pengecualian CSRF). */
    private const ADMINER_PROXY_PATTERN = '#^/database/[^/]+/adminer(/|$)#';

    public function process(Request $request, callable $handler): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            // Jangan panggil $request->post() untuk proxy Adminer (body multipart
            // diteruskan mentah oleh DatabaseController::adminer()).
            if (!self::isAdminerProxyPath($request->path())) {
                $token = (string) $request->post('_token', '');
                if ($token === '' || !hash_equals(csrf_token(), $token)) {
                    if ($request->expectsJson()) {
                        return response(
                            json_encode(['code' => 419, 'msg' => 'CSRF token mismatch'], JSON_UNESCAPED_UNICODE),
                            419,
                            ['Content-Type' => 'application/json']
                        );
                    }
                    flash_set('error', 'Sesi kedaluwarsa. Silakan coba lagi.');
                    return redirect('/');
                }
            }
        }

        return $handler($request);
    }

    /**
     * Apakah path menuju proxy Adminer (pengecualian CSRF sempit).
     */
    public static function isAdminerProxyPath(string $path): bool
    {
        return preg_match(self::ADMINER_PROXY_PATTERN, $path) === 1;
    }
}
