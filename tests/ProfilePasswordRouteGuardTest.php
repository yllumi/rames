<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\AuthMiddleware;
use app\middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use support\Request;
use Webman\Http\Response;

/**
 * Guard regresi untuk route `POST /profile/password` (ubah password
 * self-service) — **lapisan rute/middleware**, bukan controller.
 *
 * `tests/ProfileControllerTest.php` memanggil controller langsung sehingga
 * tidak bisa membuktikan bahwa (a) route benar-benar terdaftar tepat sekali,
 * (b) request POST tanpa token CSRF ditolak **sebelum** controller, dan
 * (c) path ini tidak masuk daftar publik `AuthMiddleware`. Tanpa guard ini,
 * penghapusan/CSRF-exemption baru pada route password bisa lolos tanpa
 * kegagalan test.
 *
 * Catatan: test ini tidak memeriksa sesi nyata (tidak ada HTTP); perilaku
 * autentikasi tetap diuji lewat daftar `PUBLIC_PATHS` + rantai middleware.
 */
class ProfilePasswordRouteGuardTest extends TestCase
{
    private const ROUTE_FILE = __DIR__ . '/../config/route.php';

    private const MW_FILE = __DIR__ . '/../config/middleware.php';

    // ------------------------------------------------------------------
    // (a) route terdaftar tepat sekali, mengarah ke controller yang benar
    // ------------------------------------------------------------------

    public function testPasswordRouteRegisteredExactlyOnceToProfileController(): void
    {
        $source = (string) file_get_contents(self::ROUTE_FILE);

        self::assertSame(
            1,
            substr_count($source, "Route::post('/profile/password'"),
            'POST /profile/password harus terdaftar tepat satu kali'
        );
        self::assertMatchesRegularExpression(
            "/Route::post\\('\\/profile\\/password', \\[ProfileController::class, 'changePassword'\\]\\);/",
            $source
        );
    }

    // ------------------------------------------------------------------
    // (b) CSRF menolak POST tanpa token (tidak ada pengecualian untuk path ini)
    // ------------------------------------------------------------------

    public function testPasswordPostWithoutCsrfTokenIsRejectedBeforeController(): void
    {
        $handled = false;

        $response = (new CsrfMiddleware())->process(
            $this->post('/profile/password', []),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'handled');
            }
        );

        self::assertFalse($handled, 'POST tanpa token CSRF tidak boleh diteruskan ke controller');
        self::assertSame(419, $response->getStatusCode());
    }

    /**
     * Path password bukan target pengecualian CSRF (keduanya path-presisi).
     */
    public function testPasswordPathIsNotACsrfExemption(): void
    {
        self::assertFalse(CsrfMiddleware::isAdminerProxyPath('/profile/password'));
        self::assertFalse(CsrfMiddleware::isPaymentCallbackPath('/profile/password'));
    }

    // ------------------------------------------------------------------
    // (c) path password bukan publik & rantai middleware dashboard lengkap
    // ------------------------------------------------------------------

    public function testPasswordPathIsProtectedByAuthAndCsrfMiddleware(): void
    {
        $publicPaths = (new ReflectionClass(AuthMiddleware::class))->getConstant('PUBLIC_PATHS');
        self::assertIsArray($publicPaths);
        self::assertNotContains('/profile/password', $publicPaths, 'route password tidak boleh publik');
        self::assertNotContains('/profile', $publicPaths, 'halaman profil tidak boleh publik');

        // Mode default (`dashboard`) membaca env `RAMES_MODE`; pastikan bersih.
        $previous = getenv('RAMES_MODE');
        putenv('RAMES_MODE=dashboard');
        try {
            $config = require self::MW_FILE;
        } finally {
            if ($previous === false) {
                putenv('RAMES_MODE');
            } else {
                putenv('RAMES_MODE=' . $previous);
            }
        }

        $chain = $config[''] ?? [];
        self::assertContains(CsrfMiddleware::class, $chain);
        self::assertContains(AuthMiddleware::class, $chain);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function post(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Accept: application/json\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }
}
