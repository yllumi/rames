<?php
declare(strict_types=1);

namespace Tests;

use app\controller\ProfileController;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\StoreDbFiles;
use Webman\Http\Response;
use Workerman\Protocols\Http\Session;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * API key **per user** dari halaman Profil (docs/SPECS.md §7.19) — mediator.
 *
 * Kontrak:
 *  1. `generateApiKey()` membuat key untuk user **login sendiri** (anti-IDOR:
 *     id dari request diabaikan), plaintext hanya masuk session sekali-tampil
 *     (bukan flash/log), lalu `index()` menariknya tepat sekali;
 *  2. generate ulang mengganti key lama (langsung tidak berlaku);
 *  3. `revokeApiKey()` mencabut key (idempoten);
 *  4. tanpa login ⇒ 401 JSON / redirect, tanpa mutasi.
 */
class ProfileApiKeyTest extends TestCase
{
    private string $db = '';

    private UserStore $users;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/rames-profileapikey-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->users = new UserStore($this->db);
        $this->users->create('admin', 'rahasia-admin-1');
        $this->users->create('budi', 'rahasia-budi-1');
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
    }

    public function testGenerateIssuesKeyForLoggedInUserAndShowsItOnce(): void
    {
        $controller = $this->controller('budi');

        $response = $controller->generateApiKey($this->post('/profile/api-key'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile', $response->getHeader('Location'));
        $this->assertSame('success', $controller->flashes[0]['type'] ?? null);

        $plain = (string) ($controller->session->items['api_key_new'] ?? '');
        $this->assertStringStartsWith(UserStore::API_KEY_PREFIX, $plain);
        $this->assertStringNotContainsString($plain, (string) ($controller->flashes[0]['message'] ?? ''), 'key tidak boleh masuk flash');

        $meta = $this->users->apiKeyMeta($this->idOf('budi'));
        $this->assertTrue($meta['active']);
        $this->assertSame(substr($plain, 0, UserStore::API_KEY_DISPLAY_LENGTH), $meta['prefix']);
        $this->assertNotNull($this->users->actorByApiKey($plain));

        // Halaman Profil menarik plaintext tepat sekali.
        $controller->index($this->get('/profile'));
        $this->assertSame($plain, $controller->rendered['vars']['newApiKey'] ?? null);
        $this->assertSame('', (string) ($controller->session->items['api_key_new'] ?? ''));

        $controller->index($this->get('/profile'));
        $this->assertSame('', $controller->rendered['vars']['newApiKey'] ?? null);
    }

    public function testIndexExposesOnlySafeMetadata(): void
    {
        $controller = $this->controller('budi');
        $controller->generateApiKey($this->post('/profile/api-key'));

        $controller->index($this->get('/profile'));
        $apiKey = $controller->rendered['vars']['apiKey'] ?? [];

        $this->assertTrue((bool) ($apiKey['active'] ?? false));
        $this->assertArrayNotHasKey('hash', $apiKey);
        $this->assertArrayNotHasKey('api_key_hash', $apiKey);
        $this->assertArrayNotHasKey('api_key_hash', $controller->rendered['vars']['user'] ?? []);
    }

    public function testRegenerateInvalidatesPreviousKey(): void
    {
        $controller = $this->controller('budi');
        $controller->generateApiKey($this->post('/profile/api-key'));
        $first = (string) ($controller->session->items['api_key_new'] ?? '');

        $controller->generateApiKey($this->post('/profile/api-key'));
        $second = (string) ($controller->session->items['api_key_new'] ?? '');

        $this->assertNotSame($first, $second);
        $this->assertNull($this->users->actorByApiKey($first));
        $this->assertNotNull($this->users->actorByApiKey($second));
    }

    public function testRevokeClearsKey(): void
    {
        $controller = $this->controller('budi');
        $controller->generateApiKey($this->post('/profile/api-key'));
        $key = (string) ($controller->session->items['api_key_new'] ?? '');

        $response = $controller->revokeApiKey($this->post('/profile/api-key/revoke'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->users->apiKeyMeta($this->idOf('budi'))['active']);
        $this->assertNull($this->users->actorByApiKey($key));
        $this->assertSame('success', $controller->flashes[0]['type'] ?? null);
        $this->assertArrayNotHasKey('api_key_new', $controller->session->items);
    }

    public function testGenerateIgnoresForeignUserIdFromRequest(): void
    {
        $controller = $this->controller('budi');

        $controller->generateApiKey($this->post('/profile/api-key', ['id' => $this->idOf('admin'), 'user_id' => $this->idOf('admin')]));

        $this->assertTrue($this->users->apiKeyMeta($this->idOf('budi'))['active']);
        $this->assertFalse($this->users->apiKeyMeta($this->idOf('admin'))['active'], 'id dari request wajib diabaikan');
    }

    public function testWithoutLoginNoMutation(): void
    {
        $controller = $this->controller(null);

        $json = $controller->generateApiKey($this->postJson('/profile/api-key'));
        $this->assertSame(401, $json->getStatusCode());
        $this->assertFalse($this->users->apiKeyMeta($this->idOf('budi'))['active']);
        $this->assertSame([], $controller->flashes);

        $browser = $controller->revokeApiKey($this->post('/profile/api-key/revoke'));
        $this->assertSame(302, $browser->getStatusCode());
        $this->assertSame('/login', $browser->getHeader('Location'));
    }

    private function controller(?string $username): FakeApiKeyProfileController
    {
        $controller = new FakeApiKeyProfileController($this->users);
        $controller->user = $username === null ? null : $this->users->findPublicById($this->idOf($username));
        $controller->session = new FakeApiKeySession();

        return $controller;
    }

    private function idOf(string $username): string
    {
        $user = $this->users->findByUsername($username);
        $this->assertIsArray($user);

        return (string) $user['id'];
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function post(string $path, array $fields = []): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\n"
            . 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function postJson(string $path, array $fields = []): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n"
            . 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }
}

/** Controller Profil dengan seam sesi/flash/render (tanpa HTTP). */
final class FakeApiKeyProfileController extends ProfileController
{
    /** @var array<string,mixed>|null */
    public ?array $user = null;

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    /** @var array{template:string,vars:array<string,mixed>}|null */
    public ?array $rendered = null;

    public ?FakeApiKeySession $session = null;

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function flash(string $type, string $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => $message];
    }

    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered');
    }

    protected function session(Request $request): ?Session
    {
        return $this->session;
    }
}

/** Session in-memory (tanpa handler/berkas) untuk menguji sekali-tampil. */
final class FakeApiKeySession extends Session
{
    /** @var array<string,mixed> */
    public array $items = [];

    public function __construct()
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->items[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void
    {
        $this->items[$name] = $value;
    }

    public function delete(string $name): void
    {
        unset($this->items[$name]);
    }

    public function pull(string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, $this->items)) {
            return $default;
        }

        $value = $this->items[$name];
        unset($this->items[$name]);

        return $value;
    }
}
