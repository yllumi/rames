<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AuthController;
use app\library\Auth\Totp;
use app\library\Auth\TwoFactorService;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Http\Response;
use Workerman\Protocols\Http\Session;

/**
 * Test alur login dua langkah (2FA TOTP) di {@see AuthController}.
 *
 * Tanpa HTTP nyata: session diganti objek `Session` nyata yang dipakai
 * bersama (seam `session()`), user login/flash/render ditangkap lewat subclass,
 * dan migrasi kepemilikan app dinonaktifkan (tidak boleh menyentuh store app
 * nyata). Membuktikan: password benar tidak cukup untuk user ber-2FA
 * (session `user` TIDAK diisi), TTL pending, batas percobaan, kode pemulihan,
 * dan redirect internal yang aman.
 */
class AuthControllerTwoFactorTest extends TestCase
{
    private string $tmp;

    private string $db;

    private UserStore $users;

    private Session $session;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-auth2fa-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->db = $this->tmp . '/rames.sqlite';

        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->users = new UserStore($this->db);
        $this->users->changePassword('u1', 'rahasia123');
        $this->users->changePassword('u2', 'rahasia123');

        $this->session = new Session('rames2fa' . bin2hex(random_bytes(8)));
    }

    protected function tearDown(): void
    {
        $this->session->flush();
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // POST /login
    // ------------------------------------------------------------------

    public function testLoginWithoutTwoFactorKeepsLegacyBehaviour(): void
    {
        $controller = $this->controller();

        $response = $controller->login($this->post('/login', [
            'username' => 'budi',
            'password' => 'rahasia123',
            'redirect' => '/profile',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile', $response->getHeader('Location'));

        $user = $this->session->get('user');
        self::assertIsArray($user);
        self::assertSame('u2', $user['id']);
        self::assertSame(1, $controller->migrations, 'migrasi ownership dijalankan sekali');
        self::assertSame(1, $controller->rotations, 'id session dirotasi saat login (anti-fixation)');
        self::assertNull($this->session->get(TwoFactorService::SESSION_PENDING));
    }

    public function testLoginWithTwoFactorDoesNotSetUserAndStoresPending(): void
    {
        $this->enableTwoFactor('u1');
        $controller = $this->controller();

        $response = $controller->login($this->post('/login', [
            'username' => 'admin',
            'password' => 'rahasia123',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login/2fa', $response->getHeader('Location'));
        self::assertNull($this->session->get('user'), 'user ber-2FA belum boleh login sebelum kode diverifikasi');
        self::assertSame(0, $controller->migrations, 'migrasi ownership belum boleh jalan');
        self::assertSame(1, $controller->rotations, 'id session dirotasi sebelum menyimpan pending');
        self::assertCount(0, $controller->flashes);

        $pending = $this->session->get(TwoFactorService::SESSION_PENDING);
        self::assertIsArray($pending);
        self::assertSame('u1', $pending['user_id']);
        self::assertSame(0, $pending['attempts']);
        self::assertSame('/apps', $pending['redirect']);
        self::assertIsInt($pending['created_at']);
    }

    public function testLoginRejectsWrongPasswordWithoutPending(): void
    {
        $this->enableTwoFactor('u1');
        $controller = $this->controller();

        $response = $controller->login($this->post('/login', [
            'username' => 'admin',
            'password' => 'salah-sekali',
        ]));

        self::assertSame('/login', $response->getHeader('Location'));
        self::assertNull($this->session->get(TwoFactorService::SESSION_PENDING));
        self::assertNull($this->session->get('user'));
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
    }

    public function testTwoFactorLoginSanitisesExternalRedirectIntoPending(): void
    {
        $this->enableTwoFactor('u1');
        $controller = $this->controller();

        $controller->login($this->post('/login', [
            'username' => 'admin',
            'password' => 'rahasia123',
            'redirect' => 'https://evil.example/phish',
        ]));

        $pending = $this->session->get(TwoFactorService::SESSION_PENDING);
        self::assertIsArray($pending);
        self::assertSame('/apps', $pending['redirect'], 'hanya path internal yang boleh disimpan');
    }

    /**
     * Browser menormalkan `/\evil.com` menjadi `//evil.com`; nilai ini tidak
     * boleh lolos sebagai redirect internal.
     */
    public function testTwoFactorLoginRejectsBackslashRedirect(): void
    {
        $this->enableTwoFactor('u1');
        $controller = $this->controller();

        $controller->login($this->post('/login', [
            'username' => 'admin',
            'password' => 'rahasia123',
            'redirect' => '/\\evil.example',
        ]));

        $pending = $this->session->get(TwoFactorService::SESSION_PENDING);
        self::assertIsArray($pending);
        self::assertSame('/apps', $pending['redirect']);
    }

    /**
     * Karakter kontrol (mis. tab) juga dinormalkan browser menjadi `//host`.
     */
    public function testTwoFactorLoginRejectsControlCharacterRedirect(): void
    {
        $this->enableTwoFactor('u1');
        $controller = $this->controller();

        $controller->login($this->post('/login', [
            'username' => 'admin',
            'password' => 'rahasia123',
            'redirect' => "/\t/evil.example",
        ]));

        $pending = $this->session->get(TwoFactorService::SESSION_PENDING);
        self::assertIsArray($pending);
        self::assertSame('/apps', $pending['redirect']);
    }

    // ------------------------------------------------------------------
    // GET /login/2fa
    // ------------------------------------------------------------------

    public function testTwoFactorFormWithoutPendingRedirectsToLogin(): void
    {
        $controller = $this->controller();

        $response = $controller->twoFactorForm($this->get('/login/2fa'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeader('Location'));
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
    }

    public function testTwoFactorFormWithExpiredPendingRedirectsToLoginAndClearsIt(): void
    {
        $this->seedPending('u1', time() - TwoFactorService::PENDING_TTL - 1);
        $controller = $this->controller();

        $response = $controller->twoFactorForm($this->get('/login/2fa'));

        self::assertSame('/login', $response->getHeader('Location'));
        self::assertNull($this->session->get(TwoFactorService::SESSION_PENDING));
    }

    public function testTwoFactorFormRendersUsernameAndAttemptsRemaining(): void
    {
        $this->seedPending('u1', time(), 2);
        $controller = $this->controller();

        $response = $controller->twoFactorForm($this->get('/login/2fa'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('auth/two-factor', $controller->rendered['template'] ?? null);
        self::assertSame(
            ['username' => 'admin', 'attempts_remaining' => TwoFactorService::MAX_ATTEMPTS - 2],
            $controller->rendered['vars'] ?? null
        );
    }

    public function testTwoFactorFormRedirectsLoggedInUserToApps(): void
    {
        $controller = $this->controller($this->users->findPublicById('u2'));

        $response = $controller->twoFactorForm($this->get('/login/2fa'));

        self::assertSame('/apps', $response->getHeader('Location'));
    }

    // ------------------------------------------------------------------
    // POST /login/2fa
    // ------------------------------------------------------------------

    public function testTwoFactorVerifyWithValidCodeLogsUserInAndClearsPending(): void
    {
        $secret = $this->enableTwoFactor('u1');
        $this->seedPending('u1', time(), 0, '/profile');
        $controller = $this->controller();

        $response = $controller->twoFactorVerify($this->post('/login/2fa', [
            'code' => Totp::code($secret, time()),
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile', $response->getHeader('Location'));
        $user = $this->session->get('user');
        self::assertIsArray($user);
        self::assertSame('u1', $user['id']);
        self::assertTrue($user['totp_enabled']);
        self::assertNull($this->session->get(TwoFactorService::SESSION_PENDING));
        self::assertSame(1, $controller->migrations);
        self::assertSame(1, $controller->rotations, 'id session dirotasi setelah kode lolos');
    }

    public function testTwoFactorVerifyWithWrongCodeIncrementsAttempts(): void
    {
        $secret = $this->enableTwoFactor('u1');
        $this->seedPending('u1', time());
        $controller = $this->controller();

        $response = $controller->twoFactorVerify($this->post('/login/2fa', [
            'code' => $this->wrongCode($secret),
        ]));

        self::assertSame('/login/2fa', $response->getHeader('Location'));
        self::assertNull($this->session->get('user'));

        $pending = $this->session->get(TwoFactorService::SESSION_PENDING);
        self::assertIsArray($pending);
        self::assertSame(1, $pending['attempts']);
        self::assertSame('Kode verifikasi salah.', $controller->flashes[0]['message'] ?? null);
    }

    public function testTwoFactorVerifyClearsPendingWhenMaxAttemptsReached(): void
    {
        $secret = $this->enableTwoFactor('u1');
        $this->seedPending('u1', time(), TwoFactorService::MAX_ATTEMPTS - 1);
        $controller = $this->controller();

        $response = $controller->twoFactorVerify($this->post('/login/2fa', [
            'code' => $this->wrongCode($secret),
        ]));

        self::assertSame('/login', $response->getHeader('Location'));
        self::assertNull($this->session->get(TwoFactorService::SESSION_PENDING));
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertStringContainsString('Terlalu banyak percobaan', (string) ($controller->flashes[0]['message'] ?? ''));
        self::assertNull($this->session->get('user'));
    }

    public function testTwoFactorVerifyAcceptsRecoveryCodeAndConsumesIt(): void
    {
        $this->enableTwoFactor('u1', ['AAAA-BBBB']);
        $this->seedPending('u1', time());
        $controller = $this->controller();

        $response = $controller->twoFactorVerify($this->post('/login/2fa', [
            'code' => 'aaaa bbbb',
        ]));

        self::assertSame('/apps', $response->getHeader('Location'));
        self::assertSame('u1', $this->session->get('user')['id'] ?? null);
        self::assertSame(0, $this->users->recoveryCodesRemaining('u1'), 'kode pemulihan sekali pakai');
    }

    public function testTwoFactorVerifyWithExpiredPendingRedirectsToLogin(): void
    {
        $secret = $this->enableTwoFactor('u1');
        $this->seedPending('u1', time() - TwoFactorService::PENDING_TTL - 5);
        $controller = $this->controller();

        $response = $controller->twoFactorVerify($this->post('/login/2fa', [
            'code' => Totp::code($secret, time()),
        ]));

        self::assertSame('/login', $response->getHeader('Location'));
        self::assertNull($this->session->get('user'));
        self::assertNull($this->session->get(TwoFactorService::SESSION_PENDING));
    }

    public function testTwoFactorVerifyClearsPendingWhenUserNoLongerExists(): void
    {
        $this->seedPending('ghost', time());
        $controller = $this->controller();

        $response = $controller->twoFactorVerify($this->post('/login/2fa', ['code' => '123456']));

        self::assertSame('/login', $response->getHeader('Location'));
        self::assertNull($this->session->get(TwoFactorService::SESSION_PENDING));
    }

    public function testTwoFactorVerifyRejectsArrayValuedCode(): void
    {
        $this->seedPending('u1', time());
        $controller = $this->controller();

        $response = $controller->twoFactorVerify($this->post('/login/2fa', ['code' => ['123456']]));

        self::assertSame('/login/2fa', $response->getHeader('Location'));
        $pending = $this->session->get(TwoFactorService::SESSION_PENDING);
        self::assertIsArray($pending);
        self::assertSame(0, $pending['attempts'], 'input tidak valid tidak menambah percobaan');
        self::assertNull($this->session->get('user'));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(?array $currentUser = null): FakeAuthControllerTwoFactor
    {
        $controller = new FakeAuthControllerTwoFactor(
            $this->users,
            new TwoFactorService($this->users),
            $this->session
        );
        $controller->user = $currentUser;

        return $controller;
    }

    /**
     * Aktifkan 2FA user dan kembalikan secret-nya.
     *
     * @param array<int,string> $recoveryCodes
     */
    private function enableTwoFactor(string $id, array $recoveryCodes = []): string
    {
        $secret = Totp::generateSecret();
        $this->users->enableTotp($id, $secret, $recoveryCodes);

        return $secret;
    }

    private function seedPending(string $userId, int $createdAt, int $attempts = 0, string $redirect = '/apps'): void
    {
        $this->session->set(TwoFactorService::SESSION_PENDING, [
            'user_id' => $userId,
            'created_at' => $createdAt,
            'attempts' => $attempts,
            'redirect' => $redirect,
        ]);
    }

    /**
     * Kode 6 digit yang dijamin berbeda dari kode valid saat ini.
     */
    private function wrongCode(string $secret): string
    {
        $valid = (int) Totp::code($secret, time());

        return str_pad((string) (($valid + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function post(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    private function get(string $path): Request
    {
        return new Request("GET $path HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}

/**
 * Subclass seam: session nyata dipakai bersama, user login/flash/render
 * ditangkap, dan migrasi kepemilikan app dinonaktifkan (tanpa store nyata).
 */
class FakeAuthControllerTwoFactor extends AuthController
{
    public ?array $user = null;

    public Session $sessionObject;

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    /** @var array{template:string,vars:array}|null */
    public ?array $rendered = null;

    public int $migrations = 0;

    /** Berapa kali id session dirotasi (mitigasi session fixation). */
    public int $rotations = 0;

    public function __construct(UserStore $users, TwoFactorService $twoFactor, Session $session)
    {
        parent::__construct($users, $twoFactor);
        $this->sessionObject = $session;
    }

    protected function session(Request $request): Session
    {
        return $this->sessionObject;
    }

    protected function rotateSession(Request $request): Session
    {
        $this->rotations++;

        return $this->sessionObject;
    }

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

    protected function migrateOwnership(): void
    {
        $this->migrations++;
    }
}
