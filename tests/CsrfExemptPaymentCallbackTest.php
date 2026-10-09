<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

/**
 * Test pengecualian CSRF sempit untuk callback Duitku (pengecualian ke-2).
 *
 * Pengecualian hanya berlaku untuk **POST** pada **path eksak**
 * `/payments/duitku/callback`; semua path lain (termasuk `/credits/topup` dan
 * `POST`/`PUT` ke path callback dengan method lain) tetap wajib token → 419.
 *
 * Pengecualian Adminer **tidak** disentuh — regresi dipastikan di sini.
 */
class CsrfExemptPaymentCallbackTest extends TestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function callbackPaths(): array
    {
        return [
            'exact' => ['/payments/duitku/callback'],
        ];
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function nonCallbackPaths(): array
    {
        return [
            'trailing-slash' => ['/payments/duitku/callback/'],
            'suffix' => ['/payments/duitku/callbackx'],
            'nested' => ['/payments/duitku/callback/extra'],
            'parent' => ['/payments/duitku'],
            'credits-topup' => ['/credits/topup'],
            'credits-deposit' => ['/credits/deposit'],
            'apps-rebuild' => ['/apps/demo/rebuild'],
        ];
    }

    /**
     * @dataProvider callbackPaths
     */
    public function testCallbackPathIsExactMatch(string $path): void
    {
        $this->assertTrue(CsrfMiddleware::isPaymentCallbackPath($path));
    }

    /**
     * @dataProvider nonCallbackPaths
     */
    public function testOtherPathsAreNotExempt(string $path): void
    {
        $this->assertFalse(CsrfMiddleware::isPaymentCallbackPath($path));
    }

    // ------------------------------------------------------------------
    // Perilaku middleware
    // ------------------------------------------------------------------

    public function testCallbackPostWithoutTokenReachesHandler(): void
    {
        $handled = false;
        $middleware = new CsrfMiddleware();

        $response = $middleware->process(
            $this->post('/payments/duitku/callback', ['merchantOrderId' => 'RM-1', 'resultCode' => '00']),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'OK');
            }
        );

        $this->assertTrue($handled, 'POST callback Duitku harus dilewatkan tanpa token CSRF');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->rawBody());
    }

    /**
     * @dataProvider nonCallbackPaths
     */
    public function testOtherMutationWithoutTokenIsStillRejectedWith419(string $path): void
    {
        $handled = false;
        $middleware = new CsrfMiddleware();

        $response = $middleware->process(
            $this->post($path, ['amount' => '50000']),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'handled');
            }
        );

        $this->assertFalse($handled, 'POST non-callback tanpa token tidak boleh diteruskan');
        $this->assertSame(419, $response->getStatusCode());
    }

    /**
     * Path callback hanya dikecualikan untuk POST: PUT tetap wajib token.
     */
    public function testCallbackPathWithPutMethodIsStillRejected(): void
    {
        $handled = false;
        $middleware = new CsrfMiddleware();

        $request = new Request(
            "PUT /payments/duitku/callback HTTP/1.1\r\nHost: localhost\r\n"
            . "Accept: application/json\r\nContent-Length: 0\r\n\r\n"
        );

        $response = $middleware->process($request, function () use (&$handled): Response {
            $handled = true;

            return new Response(200, [], 'handled');
        });

        $this->assertFalse($handled, 'PUT ke path callback TIDAK dikecualikan (hanya POST)');
        $this->assertSame(419, $response->getStatusCode());
    }

    /**
     * GET tidak pernah dijaga CSRF (semua path) — nyatakan eksplisit di sini,
     * karena route callback hanya didaftarkan sebagai POST.
     */
    public function testCallbackGetIsUntouchedByCsrfButRouteIsPostOnly(): void
    {
        $handled = false;
        $middleware = new CsrfMiddleware();

        $request = new Request("GET /payments/duitku/callback HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = $middleware->process($request, function () use (&$handled): Response {
            $handled = true;

            return new Response(404, [], 'Not Found');
        });

        $this->assertTrue($handled, 'CSRF hanya menjaga metode mutasi; GET diteruskan apa adanya');
        $this->assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Regresi pengecualian Adminer (tidak boleh dilebarkan/dilunakkan)
    // ------------------------------------------------------------------

    /**
     * @dataProvider adminerPaths
     */
    public function testAdminerExemptionStillWorks(string $path): void
    {
        $this->assertTrue(CsrfMiddleware::isAdminerProxyPath($path));
        $this->assertFalse(
            CsrfMiddleware::isPaymentCallbackPath($path),
            'path Adminer tidak boleh dianggap callback Duitku'
        );
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function adminerPaths(): array
    {
        return [
            'bare' => ['/database/myapp-db-1/adminer'],
            'trailing-slash' => ['/database/myapp-db-1/adminer/'],
            'asset' => ['/database/myapp-db-1/adminer/adminer.css'],
            'nested' => ['/database/myapp-db-1/adminer/foo/bar'],
        ];
    }

    public function testAdminerPostWithoutTokenStillReachesHandler(): void
    {
        $handled = false;
        $middleware = new CsrfMiddleware();

        $response = $middleware->process(
            $this->post('/database/myapp-db-1/adminer/', []),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'handled');
            }
        );

        $this->assertTrue($handled);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Request mutasi tanpa token dengan `Accept: application/json` agar
     * middleware mengembalikan 419 JSON tanpa menyentuh session.
     *
     * @param array<string,string> $fields
     */
    private function post(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }
}
