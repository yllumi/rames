<?php
declare(strict_types=1);

namespace Tests;

use app\library\Api\ApiTokenAuth;
use app\library\Auth\UserStore;
use app\middleware\ApiAuthMiddleware;
use app\middleware\AuthMiddleware;
use app\middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\StoreDbFiles;
use Webman\Http\Response;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Gerbang permukaan API eksternal (docs/SPECS.md §7.19).
 *
 * Kontrak:
 *  1. di luar prefix `/api/v1/` ⇒ **no-op** (middleware dashboard lain tidak
 *     berubah perilakunya);
 *  2. token kosong ⇒ `/api/v1/*` menjawab 404 (fail-closed, permukaan disembunyikan);
 *  3. token salah/hilang ⇒ 401 body identik (tanpa oracle), handler tidak dipanggil;
 *  4. token sah ⇒ handler dipanggil;
 *  5. **tidak ada pengecualian** di prefix API — dokumentasi OpenAPI tinggal di
 *     `/api-docs/openapi.json` (di luar prefix, dijaga sesi login dashboard);
 *  6. `AuthMiddleware` & `CsrfMiddleware` melewati prefix API — kompensasi
 *     penjagaan ada di `ApiAuthMiddleware`, dan `/api/...` dashboard tetap
 *     wajib sesi/CSRF.
 */
class ApiAuthMiddlewareTest extends TestCase
{
    private const TOKEN = 'api_token_rahasia_0123456789';

    private string $db = '';

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/rames-apiauth-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
    }

    public function testNonApiPathIsPassedThrough(): void
    {
        $result = $this->dispatch($this->middleware(self::TOKEN), 'GET', '/apps', null);

        $this->assertTrue($result['handled']);
        $this->assertSame(200, $result['response']->getStatusCode());
    }

    public function testDisabledApiHidesSurfaceWith404(): void
    {
        $result = $this->dispatch($this->middleware(''), 'POST', '/api/v1/apps/demo/build', null);

        $this->assertFalse($result['handled']);
        $this->assertSame(404, $result['response']->getStatusCode());
    }

    public function testMissingTokenIsRejectedWithoutCallingHandler(): void
    {
        $result = $this->dispatch($this->middleware(self::TOKEN), 'POST', '/api/v1/apps/demo/build', null);

        $this->assertFalse($result['handled']);
        $this->assertSame(401, $result['response']->getStatusCode());
        $this->assertSame(
            ['code' => 401, 'error' => 'Unauthorized', 'reason' => 'unauthorized'],
            json_decode($result['response']->rawBody(), true)
        );
    }

    public function testWrongTokenIsRejected(): void
    {
        $result = $this->dispatch($this->middleware(self::TOKEN), 'GET', '/api/v1/apps/demo', 'Bearer salah');

        $this->assertFalse($result['handled']);
        $this->assertSame(401, $result['response']->getStatusCode());
    }

    public function testValidTokenReachesHandler(): void
    {
        $result = $this->dispatch(
            $this->middleware(self::TOKEN),
            'POST',
            '/api/v1/apps/demo/build',
            'Bearer ' . self::TOKEN
        );

        $this->assertTrue($result['handled']);
        $this->assertSame(200, $result['response']->getStatusCode());
    }

    public function testDocsJsonLivesOutsideApiPrefix(): void
    {
        // Dokumentasi OpenAPI wajib login → bukan permukaan bearer token, jadi
        // middleware API tidak boleh menyentuhnya sama sekali.
        $result = $this->dispatch($this->middleware(''), 'GET', \app\library\Api\OpenApiSpec::DOCS_JSON_PATH, null);

        $this->assertTrue($result['handled']);
        $this->assertFalse(
            str_starts_with(\app\library\Api\OpenApiSpec::DOCS_JSON_PATH, ApiAuthMiddleware::PREFIX),
            'dokumen OpenAPI harus berada di luar prefix /api/v1/'
        );
    }

    public function testAuthMiddlewarePassesApiPrefixWithoutSession(): void
    {
        $request = new Request("POST /api/v1/apps/demo/build HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $handled = false;

        (new AuthMiddleware())->process($request, function () use (&$handled): Response {
            $handled = true;

            return new Response(200, [], 'OK');
        });

        $this->assertTrue($handled);
        $this->assertTrue(AuthMiddleware::isApiPath('/api/v1/apps/demo/build'));
        $this->assertFalse(AuthMiddleware::isApiPath('/api/apps/demo/status'));
    }

    public function testCsrfMiddlewareExemptsApiMutationsOnly(): void
    {
        $apiRequest = new Request("POST /api/v1/apps/demo/build HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $apiHandled = false;
        (new CsrfMiddleware())->process($apiRequest, function () use (&$apiHandled): Response {
            $apiHandled = true;

            return new Response(200, [], 'OK');
        });
        $this->assertTrue($apiHandled);

        $this->assertTrue(CsrfMiddleware::isApiPath('/api/v1/apps/demo/build'));
        $this->assertFalse(CsrfMiddleware::isApiPath('/api/apps/demo/rebuild'));
        $this->assertFalse(CsrfMiddleware::isApiPath('/api/v1'));
    }

    private function middleware(string $token): ApiAuthMiddleware
    {
        $users = new UserStore($this->db);
        $users->create('admin', 'rahasia-admin-1');

        return new ApiAuthMiddleware(new ApiTokenAuth($token, 'admin', $users));
    }

    /**
     * @return array{handled:bool,response:Response}
     */
    private function dispatch(ApiAuthMiddleware $middleware, string $method, string $path, ?string $authorization): array
    {
        $headers = "Host: localhost\r\n";
        if ($authorization !== null) {
            $headers .= 'Authorization: ' . $authorization . "\r\n";
        }
        $request = new Request($method . ' ' . $path . " HTTP/1.1\r\n" . $headers . "\r\n");
        $handled = false;

        $response = $middleware->process($request, function () use (&$handled): Response {
            $handled = true;

            return new Response(200, [], 'OK');
        });

        return ['handled' => $handled, 'response' => $response];
    }
}
