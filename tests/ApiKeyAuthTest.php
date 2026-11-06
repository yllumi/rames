<?php
declare(strict_types=1);

namespace Tests;

use app\library\Api\ApiKeyAuth;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\StoreDbFiles;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * API key **per user** (docs/SPECS.md §7.19) — store + verifikasi.
 *
 * Kontrak:
 *  1. plaintext hanya dikembalikan sekali oleh `issueApiKey()`; store menyimpan
 *     SHA-256 (bukan plaintext) dan prefix tampilan;
 *  2. `actorByApiKey()` hanya menerima key berprefix `rmk_` yang hash-nya cocok
 *     (`hash_equals`) dan mengembalikan bentuk **publik** (tanpa hash/secret);
 *  3. generate ulang menimpa hash ⇒ key lama langsung tidak berlaku;
 *  4. revoke (idempoten) mematikan key;
 *  5. `api_key_last_used_at` di-throttle (tidak menulis tiap request).
 */
class ApiKeyAuthTest extends TestCase
{
    private string $db = '';

    private UserStore $users;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/rames-apikey-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->users = new UserStore($this->db);
        $this->users->create('admin', 'rahasia-admin-1');
        $this->users->create('member', 'rahasia-member-1');
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
    }

    public function testIssueStoresOnlyHashAndReturnsPlaintextOnce(): void
    {
        $key = $this->users->issueApiKey($this->idOf('admin'));

        $this->assertStringStartsWith(UserStore::API_KEY_PREFIX, $key);
        $this->assertGreaterThanOrEqual(30, strlen($key));

        $meta = $this->users->apiKeyMeta($this->idOf('admin'));
        $this->assertTrue($meta['active']);
        $this->assertSame(substr($key, 0, UserStore::API_KEY_DISPLAY_LENGTH), $meta['prefix']);
        $this->assertNotSame('', $meta['created_at']);
        $this->assertSame('', $meta['last_used_at']);

        $raw = $this->users->findById($this->idOf('admin'));
        $this->assertSame(UserStore::hashApiKey($key), $raw['api_key_hash']);
        $this->assertNotSame($key, $raw['api_key_hash'], 'plaintext tidak boleh disimpan');
    }

    public function testActorByApiKeyReturnsPublicUserWithoutHash(): void
    {
        $key = $this->users->issueApiKey($this->idOf('member'));

        $actor = $this->users->actorByApiKey($key);

        $this->assertIsArray($actor);
        $this->assertSame('member', $actor['username']);
        $this->assertSame('member', $actor['role']);
        $this->assertArrayNotHasKey('api_key_hash', $actor);
        $this->assertArrayNotHasKey('password_hash', $actor);
        $this->assertSame(substr($key, 0, UserStore::API_KEY_DISPLAY_LENGTH), $actor['api_key_prefix']);
    }

    public function testUnknownOrMalformedKeyIsRejected(): void
    {
        $this->users->issueApiKey($this->idOf('admin'));

        $this->assertNull($this->users->actorByApiKey(''));
        $this->assertNull($this->users->actorByApiKey('rmk_' . bin2hex(random_bytes(24))));
        $this->assertNull($this->users->actorByApiKey(str_repeat('a', 52)));
    }

    public function testRegenerateInvalidatesPreviousKey(): void
    {
        $id = $this->idOf('admin');
        $old = $this->users->issueApiKey($id);
        $new = $this->users->issueApiKey($id);

        $this->assertNotSame($old, $new);
        $this->assertNull($this->users->actorByApiKey($old), 'key lama harus langsung tidak berlaku');
        $this->assertNotNull($this->users->actorByApiKey($new));
    }

    public function testRevokeDisablesKeyAndIsIdempotent(): void
    {
        $id = $this->idOf('admin');
        $key = $this->users->issueApiKey($id);

        $this->users->revokeApiKey($id);

        $this->assertFalse($this->users->apiKeyMeta($id)['active']);
        $this->assertNull($this->users->actorByApiKey($key));

        $this->users->revokeApiKey($id);
        $this->assertFalse($this->users->apiKeyMeta($id)['active']);
    }

    public function testTouchApiKeyIsThrottled(): void
    {
        $id = $this->idOf('admin');
        $this->users->issueApiKey($id);

        $this->users->touchApiKey($id);
        $first = (string) $this->users->findById($id)['api_key_last_used_at'];
        $this->assertNotSame('', $first);

        // Panggilan berikutnya dalam jendela throttle tidak mengubah nilai.
        $this->users->touchApiKey($id);
        $this->assertSame($first, (string) $this->users->findById($id)['api_key_last_used_at']);

        // Interval 0 memaksa pembaruan.
        $this->users->touchApiKey($id, 0);
        $this->assertNotSame('', (string) $this->users->findById($id)['api_key_last_used_at']);
    }

    public function testKeyWithoutIssuedStoreEntryDoesNothing(): void
    {
        $this->assertFalse($this->users->apiKeyMeta($this->idOf('admin'))['active']);
        $this->users->revokeApiKey($this->idOf('admin'));
        $this->users->touchApiKey($this->idOf('admin'));
        $this->assertFalse($this->users->apiKeyMeta($this->idOf('admin'))['active']);
    }

    // ------------------------------------------------------------------
    // Verifikasi header (ApiKeyAuth)
    // ------------------------------------------------------------------

    public function testAuthorizationHeaderResolvesActor(): void
    {
        $key = $this->users->issueApiKey($this->idOf('member'));
        $auth = new ApiKeyAuth($this->users);

        $request = new Request(
            "GET /api/v1/apps/demo HTTP/1.1\r\nHost: localhost\r\n"
            . "Authorization: Bearer " . $key . "\r\n\r\n"
        );

        $actor = $auth->authenticate($request);

        $this->assertIsArray($actor);
        $this->assertSame('member', $actor['username']);
        $this->assertNotSame(
            '',
            (string) $this->users->findById($this->idOf('member'))['api_key_last_used_at'],
            'middleware wajib mencatat pemakaian key'
        );
    }

    public function testMalformedOrUnknownAuthorizationHeadersAreRejected(): void
    {
        $auth = new ApiKeyAuth($this->users);

        foreach ([
            null,
            '',
            'rmk_tanpa_skema',
            'Basic ' . base64_encode('x'),
            'Bearer ',
            'Bearer rmk_tidak_ada',
        ] as $header) {
            $raw = "GET /api/v1/apps/demo HTTP/1.1\r\nHost: localhost\r\n";
            if ($header !== null) {
                $raw .= 'Authorization: ' . $header . "\r\n";
            }

            $this->assertNull($auth->actorFromRequest(new Request($raw . "\r\n")));
        }
    }

    public function testActorFromRequestHasNoSideEffectOnLastUsed(): void
    {
        $key = $this->users->issueApiKey($this->idOf('admin'));
        $auth = new ApiKeyAuth($this->users);

        $actor = $auth->actorFromRequest(new Request(
            "GET /api/v1/apps/demo HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer " . $key . "\r\n\r\n"
        ));

        $this->assertIsArray($actor);
        $this->assertSame('', (string) $this->users->findById($this->idOf('admin'))['api_key_last_used_at']);
    }

    private function idOf(string $username): string
    {
        $user = $this->users->findByUsername($username);
        $this->assertIsArray($user);

        return (string) $user['id'];
    }
}
