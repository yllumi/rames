<?php
declare(strict_types=1);

namespace Tests;

use app\library\Api\ApiTokenAuth;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Verifikasi token API eksternal → user aktor (docs/SPECS.md §7.19).
 *
 * Kontrak yang diuji:
 *  1. token kosong ⇒ API mati (`enabled() === false`, semua header ditolak);
 *  2. hanya `Authorization: Bearer <token>` (skema case-insensitive) yang sah —
 *     `Basic …`, token telanjang, dan token salah ditolak;
 *  3. user aktor diambil bentuk **publik**: tanpa `password_hash`/secret 2FA;
 *  4. username aktor yang tidak ada di store ⇒ tolak (fail-closed).
 */
class ApiTokenAuthTest extends TestCase
{
    private const TOKEN = 'api_token_rahasia_0123456789';

    private string $db = '';

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/rames-apitoken-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
    }

    public function testEmptyTokenDisablesApi(): void
    {
        $auth = new ApiTokenAuth('', 'admin', $this->users());

        $this->assertFalse($auth->enabled());
        $this->assertNull($auth->actorFromHeader('Bearer ' . self::TOKEN));
        $this->assertNull($auth->actorFromHeader('Bearer '));
    }

    public function testValidBearerTokenResolvesAdminActor(): void
    {
        $auth = new ApiTokenAuth(self::TOKEN, 'admin', $this->users());

        $actor = $auth->actorFromHeader('Bearer ' . self::TOKEN);

        $this->assertIsArray($actor);
        $this->assertSame('admin', $actor['username']);
        $this->assertSame('admin', $actor['role']);
        $this->assertArrayNotHasKey('password_hash', $actor);
        $this->assertArrayNotHasKey('totp_secret', $actor);
        $this->assertArrayNotHasKey('totp_recovery_codes', $actor);
    }

    public function testBearerSchemeIsCaseInsensitive(): void
    {
        $auth = new ApiTokenAuth(self::TOKEN, 'admin', $this->users());

        $this->assertIsArray($auth->actorFromHeader('bearer ' . self::TOKEN));
        $this->assertIsArray($auth->actorFromHeader('BEARER  ' . self::TOKEN . '  '));
    }

    public function testWrongOrMalformedHeadersAreRejected(): void
    {
        $auth = new ApiTokenAuth(self::TOKEN, 'admin', $this->users());

        $this->assertNull($auth->actorFromHeader(null));
        $this->assertNull($auth->actorFromHeader(''));
        $this->assertNull($auth->actorFromHeader(self::TOKEN));
        $this->assertNull($auth->actorFromHeader('Basic ' . base64_encode(self::TOKEN)));
        $this->assertNull($auth->actorFromHeader('Bearer ' . self::TOKEN . 'x'));
        $this->assertNull($auth->actorFromHeader('Bearer ' . substr(self::TOKEN, 0, -1)));
    }

    public function testUnknownActorUserIsRejected(): void
    {
        $auth = new ApiTokenAuth(self::TOKEN, 'tidak-ada', $this->users());

        $this->assertNull($auth->actorFromHeader('Bearer ' . self::TOKEN));
    }

    public function testActorFromRequestReadsAuthorizationHeader(): void
    {
        $auth = new ApiTokenAuth(self::TOKEN, 'admin', $this->users());
        $request = new \support\Request(
            "GET /api/v1/apps/demo HTTP/1.1\r\nHost: localhost\r\n"
            . "Authorization: Bearer " . self::TOKEN . "\r\n\r\n"
        );

        $actor = $auth->actorFromRequest($request);

        $this->assertIsArray($actor);
        $this->assertSame('admin', $actor['username']);
    }

    private function users(): UserStore
    {
        $store = new UserStore($this->db);
        $store->create('admin', 'rahasia-admin-1');

        return $store;
    }
}
