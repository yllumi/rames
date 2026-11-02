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
 * Guard lapisan **rute & middleware** untuk fitur 2FA TOTP.
 *
 * Melengkapi test controller (yang memanggil action langsung) dengan bukti:
 *  - `/login/2fa` terdaftar GET+POST dan menjadi satu-satunya path 2FA publik
 *    (langkah kedua login belum punya `user` di session);
 *  - empat route `/profile/2fa/*` terdaftar dan **tidak** publik;
 *  - POST tanpa token CSRF ditolak `CsrfMiddleware` (419) sebelum controller.
 */
class TwoFactorRouteGuardTest extends TestCase
{
    private const ROUTE_FILE = __DIR__ . '/../config/route.php';

    // ------------------------------------------------------------------
    // (a) pendaftaran route
    // ------------------------------------------------------------------

    public function testLoginTwoFactorRouteRegisteredAsGetAndPost(): void
    {
        $source = (string) file_get_contents(self::ROUTE_FILE);

        self::assertSame(
            1,
            substr_count($source, "Route::get('/login/2fa'"),
            'GET /login/2fa harus terdaftar tepat satu kali'
        );
        self::assertSame(
            1,
            substr_count($source, "Route::post('/login/2fa'"),
            'POST /login/2fa harus terdaftar tepat satu kali'
        );
        self::assertMatchesRegularExpression(
            "/Route::get\\('\\/login\\/2fa', \\[AuthController::class, 'twoFactorForm'\\]\\);/",
            $source
        );
        self::assertMatchesRegularExpression(
            "/Route::post\\('\\/login\\/2fa', \\[AuthController::class, 'twoFactorVerify'\\]\\);/",
            $source
        );
    }

    public function testProfileTwoFactorRoutesRegisteredExactlyOnce(): void
    {
        $source = (string) file_get_contents(self::ROUTE_FILE);

        $expected = [
            'setup' => 'startTotpSetup',
            'enable' => 'enableTotp',
            'disable' => 'disableTotp',
            'recovery' => 'regenerateTotpRecovery',
        ];

        foreach ($expected as $path => $method) {
            self::assertSame(
                1,
                substr_count($source, "Route::post('/profile/2fa/{$path}'"),
                "POST /profile/2fa/{$path} harus terdaftar tepat satu kali"
            );
            self::assertMatchesRegularExpression(
                "/Route::post\\('\\/profile\\/2fa\\/{$path}', \\[ProfileController::class, '{$method}'\\]\\);/",
                $source
            );
        }
    }

    // ------------------------------------------------------------------
    // (b) klasifikasi publik
    // ------------------------------------------------------------------

    public function testOnlyLoginTwoFactorPathIsPublic(): void
    {
        $publicPaths = (new ReflectionClass(AuthMiddleware::class))->getConstant('PUBLIC_PATHS');
        self::assertIsArray($publicPaths);

        self::assertContains('/login/2fa', $publicPaths, 'langkah kedua login harus publik (belum ada session user)');

        foreach (['/profile/2fa/setup', '/profile/2fa/enable', '/profile/2fa/disable', '/profile/2fa/recovery'] as $path) {
            self::assertNotContains($path, $publicPaths, "{$path} wajib di balik autentikasi");
        }
    }

    // ------------------------------------------------------------------
    // (c) CSRF
    // ------------------------------------------------------------------

    public function testProfileTwoFactorPostWithoutCsrfTokenIsRejected(): void
    {
        foreach (['/profile/2fa/setup', '/profile/2fa/enable', '/profile/2fa/disable', '/profile/2fa/recovery'] as $path) {
            $handled = false;

            $response = (new CsrfMiddleware())->process(
                $this->post($path, []),
                function () use (&$handled): Response {
                    $handled = true;

                    return new Response(200, [], 'handled');
                }
            );

            self::assertFalse($handled, "POST {$path} tanpa token CSRF tidak boleh diteruskan ke controller");
            self::assertSame(419, $response->getStatusCode(), "POST {$path} harus menghasilkan 419");
            self::assertFalse(CsrfMiddleware::isAdminerProxyPath($path));
            self::assertFalse(CsrfMiddleware::isPaymentCallbackPath($path));
        }
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
