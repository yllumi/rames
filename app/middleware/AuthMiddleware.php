<?php
declare(strict_types=1);

namespace app\middleware;

use app\library\Auth\UserStore;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Melindungi semua route dashboard kecuali /login dan aset statis.
 * (SPECS.md §11: akses dashboard harus selalu di balik autentikasi.)
 *
 * Selain memeriksa login, middleware menyinkronkan data user di session dengan
 * `auth.json` pada setiap request yang dilindungi — sehingga penghapusan user
 * atau perubahan role (admin ↔ member) langsung berlaku tanpa menunggu login
 * ulang, dan tidak ada sesi menggantung milik user yang sudah dihapus.
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * `/healthz` publik (tanpa session) karena dipakai (a) helper self-update untuk
     * memverifikasi versi baru benar-benar melayani request sebelum rollback
     * otomatis diputuskan, dan (b) monitoring eksternal (mis. Uptime Kuma).
     * Responsnya hanya berisi status + SHA commit — tanpa data sensitif.
     */
    private const PUBLIC_PATHS = ['/', '/login', '/healthz'];

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

        if (!$request->session()->get('user') || !$this->syncSessionUser($request)) {
            return $this->unauthenticated($request, $path);
        }

        return $handler($request);
    }

    /**
     * Selaraskan user di session dengan `auth.json`.
     *
     * - user sudah dihapus → sesi dibuang (return false)
     * - role berubah (admin ↔ member) atau field lain berubah → session diperbarui
     *
     * Biaya: satu pembacaan kecil `auth.json` per request terproteksi — sepadan
     * dengan hilangnya risiko sesi "hantu" setelah user dihapus/diturunkan.
     */
    private function syncSessionUser(Request $request): bool
    {
        $session = $request->session();
        $user = $session->get('user');
        if (!is_array($user)) {
            return false;
        }

        $fresh = (new UserStore())->findPublicById((string) ($user['id'] ?? ''));
        if ($fresh === null) {
            $session->delete('user');
            return false;
        }

        if ($user !== $fresh) {
            $session->set('user', $fresh);
        }

        return true;
    }

    private function unauthenticated(Request $request, string $path): Response
    {
        if ($request->expectsJson() || str_starts_with($path, '/api/')) {
            return response(
                json_encode(['code' => 401, 'msg' => 'Unauthorized'], JSON_UNESCAPED_UNICODE),
                401,
                ['Content-Type' => 'application/json']
            );
        }

        return redirect('/login');
    }

    private function isStaticAsset(string $path): bool
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::STATIC_EXTENSIONS, true);
    }
}
