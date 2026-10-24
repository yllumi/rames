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
 * Guard lapisan rute/middleware untuk rebranding.
 *
 * Controller test memanggil method langsung, jadi ia tidak membuktikan bahwa
 * (a) `GET /branding/logo` benar-benar terdaftar tepat sekali dan mengarah ke
 * `BrandingController::logo`, (b) path itu ada di `PUBLIC_PATHS` sebagai
 * **path eksak** (bukan prefix, tanpa parameter), dan (c) `POST /nginx/branding`
 * tidak mendapat pengecualian CSRF.
 */
class BrandingRouteGuardTest extends TestCase
{
    private const ROUTE_FILE = __DIR__ . '/../config/route.php';

    // ------------------------------------------------------------------
    // (a) route terdaftar tepat sekali
    // ------------------------------------------------------------------

    public function testLogoRouteRegisteredExactlyOnceToBrandingController(): void
    {
        $source = (string) file_get_contents(self::ROUTE_FILE);

        self::assertSame(
            1,
            substr_count($source, "Route::get('/branding/logo'"),
            'GET /branding/logo harus terdaftar tepat satu kali'
        );
        self::assertMatchesRegularExpression(
            "/Route::get\\('\\/branding\\/logo', \\[BrandingController::class, 'logo'\\]\\);/",
            $source
        );
        // Tanpa parameter path (`{...}`) — berkas logo tidak boleh dipilih klien.
        self::assertStringNotContainsString("Route::get('/branding/logo/{", $source);
    }

    public function testBrandingMutationsAreRegisteredAsPostRoutes(): void
    {
        $source = (string) file_get_contents(self::ROUTE_FILE);

        self::assertSame(1, substr_count($source, "Route::post('/nginx/branding'"));
        self::assertSame(1, substr_count($source, "Route::post('/nginx/branding/reset'"));
        self::assertMatchesRegularExpression(
            "/Route::post\\('\\/nginx\\/branding', \\[NginxController::class, 'saveBranding'\\]\\);/",
            $source
        );
        self::assertMatchesRegularExpression(
            "/Route::post\\('\\/nginx\\/branding\\/reset', \\[NginxController::class, 'resetBranding'\\]\\);/",
            $source
        );
    }

    // ------------------------------------------------------------------
    // (b) path publik eksak, bukan prefix
    // ------------------------------------------------------------------

    public function testLogoPathIsPublicButOnlyExact(): void
    {
        $publicPaths = (new ReflectionClass(AuthMiddleware::class))->getConstant('PUBLIC_PATHS');
        self::assertIsArray($publicPaths);

        self::assertContains('/branding/logo', $publicPaths);
        self::assertNotContains('/branding', $publicPaths, 'hanya logo yang publik, bukan seluruh /branding/*');
        self::assertNotContains('/branding/logo/', $publicPaths);
        self::assertNotContains('/branding/logo/extra', $publicPaths);
    }

    /**
     * Bukti perilaku: request ke `/branding/logo` lolos tanpa sesi, sedangkan
     * subpath-nya tidak lolos pemeriksaan path publik (bukan prefix). Subpath
     * tidak diproses middleware penuh di sini karena `Request::session()` butuh
     * konteks HTTP; aturan pencocokan middleware adalah `in_array($path,
     * PUBLIC_PATHS, true)` — persis yang diperiksa di bawah.
     */
    public function testOnlyExactLogoPathSkipsAuthentication(): void
    {
        $handled = false;
        $middleware = new AuthMiddleware();

        $response = $middleware->process(
            $this->get('/branding/logo'),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'logo');
            }
        );

        self::assertTrue($handled, 'logo publik harus diteruskan tanpa sesi');
        self::assertSame(200, $response->getStatusCode());

        $publicPaths = (new ReflectionClass(AuthMiddleware::class))->getConstant('PUBLIC_PATHS');
        self::assertIsArray($publicPaths);
        self::assertFalse(
            in_array($this->get('/branding/logo/extra')->path(), $publicPaths, true),
            'subpath logo tidak boleh otomatis publik'
        );
        self::assertFalse(in_array('/branding/logo/', $publicPaths, true));
    }

    // ------------------------------------------------------------------
    // (c) mutasi branding tetap wajib CSRF
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string}>
     */
    public static function brandingMutationPaths(): array
    {
        return [
            'save' => ['/nginx/branding'],
            'reset' => ['/nginx/branding/reset'],
        ];
    }

    /**
     * @dataProvider brandingMutationPaths
     */
    public function testBrandingPostWithoutCsrfTokenIsRejected(string $path): void
    {
        $handled = false;

        $response = (new CsrfMiddleware())->process(
            $this->post($path),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'handled');
            }
        );

        self::assertFalse($handled, 'POST tanpa token CSRF tidak boleh diteruskan ke controller');
        self::assertSame(419, $response->getStatusCode());
    }

    /**
     * @dataProvider brandingMutationPaths
     */
    public function testBrandingPathsAreNotCsrfExemptions(string $path): void
    {
        self::assertFalse(CsrfMiddleware::isAdminerProxyPath($path));
        self::assertFalse(CsrfMiddleware::isPaymentCallbackPath($path));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function get(string $path): Request
    {
        return new Request('GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    private function post(string $path): Request
    {
        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Accept: application/json\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n\r\n"
        );
    }
}
