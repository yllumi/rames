<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

/**
 * Test pengecualian CSRF sempit untuk proxy Adminer.
 *
 * Request POST ke proxy Adminer dilewatkan **sebelum** `$request->post()`
 * dipanggil (body multipart diteruskan mentah oleh controller), sedangkan semua
 * path lain tetap divalidasi token → 419. AuthMiddleware berjalan di rantai yang
 * sama (CSRF → Auth), jadi pengecualian ini tidak membuka akses tanpa login.
 */
class CsrfExemptAdminerProxyTest extends TestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function proxyPaths(): array
    {
        return [
            'bare' => ['/database/myapp-db-1/adminer'],
            'trailing-slash' => ['/database/myapp-db-1/adminer/'],
            'asset' => ['/database/myapp-db-1/adminer/adminer.css'],
            'nested' => ['/database/myapp-db-1/adminer/foo/bar'],
            'underscore-container' => ['/database/rames_adminer/adminer'],
        ];
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function protectedPaths(): array
    {
        return [
            'apps' => ['/apps'],
            'db-query' => ['/database/myapp-db-1/query'],
            'db-import' => ['/database/myapp-db-1/import'],
            'adminer-suffix' => ['/database/myapp-db-1/adminerx'],
            'adminer-prefix' => ['/database/myapp-db-1/xadminer'],
            'terminal-input' => ['/api/apps/1/terminal/tok/input'],
        ];
    }

    /**
     * @dataProvider proxyPaths
     */
    public function testProxyPathsAreExempt(string $path): void
    {
        $this->assertTrue(CsrfMiddleware::isAdminerProxyPath($path));
    }

    /**
     * @dataProvider protectedPaths
     */
    public function testOtherPathsAreNotExempt(string $path): void
    {
        $this->assertFalse(CsrfMiddleware::isAdminerProxyPath($path));
    }

    public function testProxyPostWithoutTokenReachesHandler(): void
    {
        $handled = false;
        $middleware = new CsrfMiddleware();

        $response = $middleware->process(
            $this->post('/database/myapp-db-1/adminer/'),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'handled');
            }
        );

        $this->assertTrue($handled, 'request proxy Adminer harus diteruskan tanpa token CSRF');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('handled', $response->rawBody());
    }

    public function testOtherPostWithoutTokenIsStillRejectedWith419(): void
    {
        $handled = false;
        $middleware = new CsrfMiddleware();

        $response = $middleware->process(
            $this->post('/database/myapp-db-1/query'),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'handled');
            }
        );

        $this->assertFalse($handled, 'POST non-proxy tanpa token tidak boleh diteruskan');
        $this->assertSame(419, $response->getStatusCode());
    }

    public function testGetProxyRequestIsUntouched(): void
    {
        $handled = false;
        $middleware = new CsrfMiddleware();

        $request = new Request("GET /database/myapp-db-1/adminer/ HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = $middleware->process($request, function () use (&$handled): Response {
            $handled = true;

            return new Response(200, [], 'ok');
        });

        $this->assertTrue($handled);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Request POST tanpa body (tanpa token) dengan `Accept: application/json`
     * supaya middleware mengembalikan 419 JSON tanpa menyentuh session.
     */
    private function post(string $path): Request
    {
        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\nContent-Length: 0\r\n\r\n"
        );
    }
}
