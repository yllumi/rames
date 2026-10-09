<?php
declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Validasi token CSRF untuk semua request yang mengubah state (POST/PUT/PATCH/DELETE).
 *
 * Ada **dua** pengecualian sempit, keduanya path-presisi (larangan repo #17):
 *
 * 1. PENGECUALIAN 1 — proxy Adminer (`/database/{container}/adminer…`):
 *    halaman Adminer di-render di dalam helper container dan punya token CSRF-nya
 *    sendiri; form-nya (termasuk unggahan multipart import) diposting ke prefix
 *    proxy. Request itu harus dilewatkan **sebelum** `$request->post()` dipanggil,
 *    karena `post()` mem-parse body multipart — kita meneruskan body mentah
 *    (`rawBody()`) agar batas ukuran dan isinya utuh.
 *    Kompensasi: `AuthMiddleware` tetap berjalan pada rantai yang sama (CSRF →
 *    Auth); otorisasi per-app dicek controller lewat `AppAccess` (ability
 *    `database`, operator+, penolakan 404); cookie sesi `SameSite=Lax` tidak
 *    terbawa lintas-situs; pola presisi `#^/database/[^/]+/adminer(/|$)#`.
 *
 * 2. PENGECUALIAN 2 — callback Duitku (`POST /payments/duitku/callback`):
 *    Duitku adalah pengirim mesin tanpa sesi/token CSRF, jadi POST-nya wajib
 *    dilewatkan — **hanya** untuk method POST dan **hanya** path eksak itu
 *    (`#^/payments/duitku/callback$#`), sama sekali bukan prefix `/payments/*`.
 *    Kompensasi (di controller `PaymentController::callback()`): (a) signature
 *    HMAC-SHA256 API key diverifikasi `hash_equals`, (b) amount dicocokkan
 *    dengan nominal order tersimpan, (c) idempoten (`pending → paid` sekali),
 *    (d) tidak memakai session (`AuthMiddleware` path publik eksak) dan
 *    responsnya polos tanpa data sensitif. Body-nya `x-www-form-urlencoded`
 *    kecil, jadi `post()` aman dipanggil (berbeda dari Adminer yang butuh
 *    `rawBody()` mentah).
 *
 * Route lain — termasuk `/credits/deposit`, `/credits/topup`, dan
 * `/database/{container}/query` — tetap **wajib** token.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /** Path proxy Adminer (pengecualian 1). */
    private const ADMINER_PROXY_PATTERN = '#^/database/[^/]+/adminer(/|$)#';

    /** Path callback Duitku (pengecualian 2; eksak & hanya POST). */
    private const PAYMENT_CALLBACK_PATTERN = '#^/payments/duitku/callback$#';

    public function process(Request $request, callable $handler): Response
    {
        $method = $request->method();
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $path = $request->path();

            // Proxy Adminer: body multipart diteruskan mentah oleh controller.
            // Callback Duitku: mesin tanpa sesi, verifikasi di controller.
            $exempt = self::isAdminerProxyPath($path)
                || ($method === 'POST' && self::isPaymentCallbackPath($path));

            if (!$exempt) {
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
     * Apakah path menuju proxy Adminer (pengecualian CSRF 1).
     */
    public static function isAdminerProxyPath(string $path): bool
    {
        return preg_match(self::ADMINER_PROXY_PATTERN, $path) === 1;
    }

    /**
     * Apakah path persis callback Duitku (pengecualian CSRF 2).
     *
     * Hanya path eksak — `/payments/duitku/callback/…` atau prefix lain
     * **tidak** dikecualikan. Pengecualian baru tetap wajib diverifikasi
     * `POST` oleh pemanggil.
     */
    public static function isPaymentCallbackPath(string $path): bool
    {
        return preg_match(self::PAYMENT_CALLBACK_PATTERN, $path) === 1;
    }
}
