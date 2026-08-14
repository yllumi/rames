<?php
declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Validasi token CSRF untuk semua request yang mengubah state (POST/PUT/PATCH/DELETE).
 */
class CsrfMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
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

        return $handler($request);
    }
}
