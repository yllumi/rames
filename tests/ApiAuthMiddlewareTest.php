<?php
declare(strict_types=1);

namespace Tests;

use app\library\Api\ApiKeyAuth;
use app\library\Api\OpenApiSpec;
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
 * Gerbang permukaan API eksternal (docs/SPECS.md §7.19) — **API key per user**.
 *
 * Kontrak:
 *  1. di luar prefix `/api/v1/` ⇒ no-op;
 *  2. key tidak ada / salah / format salah / sudah dicabut ⇒ **401** body
 *     identik (tanpa oracle), handler tidak dipanggil;
 *  3. key sah ⇒ handler dipanggil + `api_key_last_used_at` dicatat;
 *  4. dokumentasi OpenAPI (`/api-docs/openapi.json`) di luar prefix ⇒ dijaga
 *     sesi login, bukan middleware ini;
 *  5. `AuthMiddleware` & `CsrfMiddleware` melewati prefix API (kompensasi:
 *     `ApiAuthMiddleware` satu-satunya penjaga), `/api/...` dashboard tetap
 *     wajib sesi/CSRF.
 */
class ApiAuthMiddlewareTest extends TestCase
{
    private string $db = '';

    private UserStore $users;

    private string $key = '';

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/rames-apiauth-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->users = new UserStore($this->db);
        $this->users->create('admin', 'rahasia-admin-1');
        $this->key = $this->users->issueApiKey($this->userId());
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
    }

    public function testNonApiPathIsPassedThrough(): void
    {
        $result = $this->dispatch('GET', '/apps', null);

        $this->assertTrue($result['handled']);
        $this->assertSame(200, $result['response']->getStatusCode());
    }

    public function testMissingKeyIsRejectedWithoutCallingHandler(): void
    {
        $result = $this->dispatch('POST', '/api/v1/apps/demo/build', null);

        $this->assertFalse($result['handled']);
        $this->assertSame(401, $result['response']->getStatusCode());
        $this->assertSame(
            ['code' => 401, 'error' => 'Unauthorized', 'reason' => 'unauthorized'],
            json_decode($result['response']->rawBody(), true)
        );
    }

    public function testUnknownKeyIsRejected(): void
    {
        $result = $this->dispatch('GET', '/api/v1/apps/demo', 'Bearer rmk_' . bin2hex(random_bytes(24)));

        $this->assertFalse($result['handled']);
        $this->assertSame(401, $result['response']->getStatusCode());
    }

    public function testRevokedKeyIsRejected(): void
    {
        $this->users->revokeApiKey($this->userId());

        $result = $this->dispatch('GET', '/api/v1/apps/demo', 'Bearer ' . $this->key);

        $this->assertFalse($result['handled']);
        $this->assertSame(401, $result['response']->getStatusCode());
    }

    public function testValidKeyReachesHandlerAndRecordsUsage(): void
    {
        $result = $this->dispatch('POST', '/api/v1/apps/demo/build', 'Bearer ' . $this->key);

        $this->assertTrue($result['handled']);
        $this->assertSame(200, $result['response']->getStatusCode());
        $this->assertNotSame(
            '',
            (string) $this->users->findById($this->userId())['api_key_last_used_at']
        );
    }

    public function testBearerSchemeIsCaseInsensitive(): void
    {
        $result = $this->dispatch('GET', '/api/v1/apps/demo', 'bearer ' . $this->key);

        $this->assertTrue($result['handled']);
    }

    public function testDocsJsonLivesOutsideApiPrefix(): void
    {
        $result = $this->dispatch('GET', OpenApiSpec::DOCS_JSON_PATH, null);

        $this->assertTrue($result['handled']);
        $this->assertFalse(
            str_starts_with(OpenApiSpec::DOCS_JSON_PATH, ApiAuthMiddleware::PREFIX),
            'dokumen OpenAPI harus berada di luar prefix /api/v1/'
        );
    }

    public function testAuthMiddlewarePassesApiPrefixWithoutSession(): void
    {
        $handled = false;

        (new AuthMiddleware())->process(
            new Request("POST /api/v1/apps/demo/build HTTP/1.1\r\nHost: localhost\r\n\r\n"),
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'OK');
            }
        );

        $this->assertTrue($handled);
        $this->assertTrue(AuthMiddleware::isApiPath('/api/v1/apps/demo/build'));
        $this->assertFalse(AuthMiddleware::isApiPath('/api/apps/demo/status'));
    }

    public function testCsrfMiddlewareExemptsApiMutationsOnly(): void
    {
        $apiHandled = false;
        (new CsrfMiddleware())->process(
            new Request("POST /api/v1/apps/demo/build HTTP/1.1\r\nHost: localhost\r\n\r\n"),
            function () use (&$apiHandled): Response {
                $apiHandled = true;

                return new Response(200, [], 'OK');
            }
        );

        $this->assertTrue($apiHandled);
        $this->assertTrue(CsrfMiddleware::isApiPath('/api/v1/apps/demo/build'));
        $this->assertFalse(CsrfMiddleware::isApiPath('/api/apps/demo/rebuild'));
        $this->assertFalse(CsrfMiddleware::isApiPath('/api/v1'));
    }

    /**
     * @return array{handled:bool,response:Response}
     */
    private function dispatch(string $method, string $path, ?string $authorization): array
    {
        $headers = "Host: localhost\r\n";
        if ($authorization !== null) {
            $headers .= 'Authorization: ' . $authorization . "\r\n";
        }
        $request = new Request($method . ' ' . $path . " HTTP/1.1\r\n" . $headers . "\r\n");
        $handled = false;

        $response = (new ApiAuthMiddleware(new ApiKeyAuth($this->users)))->process(
            $request,
            function () use (&$handled): Response {
                $handled = true;

                return new Response(200, [], 'OK');
            }
        );

        return ['handled' => $handled, 'response' => $response];
    }

    private function userId(): string
    {
        $user = $this->users->findByUsername('admin');
        $this->assertIsArray($user);

        return (string) $user['id'];
    }
}
