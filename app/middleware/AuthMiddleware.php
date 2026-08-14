<?php
declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Melindungi semua route dashboard kecuali /login dan aset statis.
 * (SPECS.md §11: akses dashboard harus selalu di balik autentikasi.)
 */
class AuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC_PATHS = ['/login'];

    private const STATIC_EXTENSIONS = [
        'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp',
        'woff', 'woff2', 'ttf', 'eot', 'map',
    ];

    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();

        if (in_array($path, self::PUBLIC_PATHS, true) || $this->isStaticAsset($path)) {
            return $handler($request);
        }

        if (!$request->session()->get('user')) {
            if ($request->expectsJson() || str_starts_with($path, '/api/')) {
                return response(
                    json_encode(['code' => 401, 'msg' => 'Unauthorized'], JSON_UNESCAPED_UNICODE),
                    401,
                    ['Content-Type' => 'application/json']
                );
            }
            return redirect('/login');
        }

        return $handler($request);
    }

    private function isStaticAsset(string $path): bool
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::STATIC_EXTENSIONS, true);
    }
}
