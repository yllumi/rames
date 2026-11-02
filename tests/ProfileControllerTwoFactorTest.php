<?php
declare(strict_types=1);

namespace Tests;

use app\controller\ProfileController;
use app\library\Auth\Totp;
use app\library\Auth\TwoFactorService;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Http\Response;
use Workerman\Protocols\Http\Session;

/**
 * Test aksi 2FA self-service di {@see ProfileController} + payload `index()`.
 *
 * Tanpa HTTP nyata (session objek nyata sebagai seam, flash/render ditangkap
 * subclass, store = SQLite temp). Membuktikan kontrak payload view
 * (`twoFactor`), alur setup → enable → kode pemulihan sekali tampil, syarat
 * penonaktifan/pembuatan ulang (password + kode), serta penolakan input array
 * dan akses tanpa login.
 */
class ProfileControllerTwoFactorTest extends TestCase
{
    private string $tmp;

    private string $db;

    private UserStore $users;

    private Session $session;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-profile2fa-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->db = $this->tmp . '/rames.sqlite';

        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->users = new UserStore($this->db);
        $this->users->changePassword('u1', 'rahasia123');
        $this->users->changePassword('u2', 'rahasia123');

        $this->session = new Session('ramesprofile2fa' . bin2hex(random_bytes(8)));
    }

    protected function tearDown(): void
    {
        $this->session->flush();
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // index(): kontrak payload twoFactor
    // ------------------------------------------------------------------

    public function testIndexSendsTwoFactorPayloadWhenDisabled(): void
    {
        $controller = $this->controller('u2');

        $response = $controller->index($this->get('/profile'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('profile/index', $controller->rendered['template'] ?? null);

        $payload = $controller->rendered['vars']['twoFactor'] ?? null;
        self::assertIsArray($payload);
        self::assertSame(
            ['enabled', 'recovery_remaining', 'setup', 'recovery_codes'],
            array_keys($payload)
        );
        self::assertFalse($payload['enabled']);
        self::assertSame(0, $payload['recovery_remaining']);
        self::assertNull($payload['setup']);
        self::assertNull($payload['recovery_codes']);
    }

    public function testIndexIncludesFreshSetupWithUriAndQrSvg(): void
    {
        $secret = Totp::generateSecret();
        $this->session->set(TwoFactorService::SESSION_SETUP, [
            'secret' => $secret,
            'created_at' => time(),
        ]);
        $controller = $this->controller('u2');

        $controller->index($this->get('/profile'));

        $setup = $controller->rendered['vars']['twoFactor']['setup'] ?? null;
        self::assertIsArray($setup);
        self::assertSame($secret, $setup['secret']);
        self::assertStringContainsString($secret, $setup['uri']);
        self::assertStringContainsString('otpauth://totp/', $setup['uri']);
        self::assertStringStartsWith('<?xml', $setup['qr_svg']);
        self::assertArrayHasKey(TwoFactorService::SESSION_SETUP, $this->session->all());
    }

    public function testIndexDropsExpiredSetup(): void
    {
        $this->session->set(TwoFactorService::SESSION_SETUP, [
            'secret' => Totp::generateSecret(),
            'created_at' => time() - TwoFactorService::SETUP_TTL - 1,
        ]);
        $controller = $this->controller('u2');

        $controller->index($this->get('/profile'));

        $this->assertNull($controller->rendered['vars']['twoFactor']['setup']);
        self::assertNull($this->session->get(TwoFactorService::SESSION_SETUP));
    }

    /**
     * Kegagalan render QR tidak boleh membuat `/profile` 500 terus selama TTL
     * setup: sesi setup dibuang + flash error.
     */
    public function testIndexClearsSetupWhenQrRenderingFails(): void
    {
        $this->session->set(TwoFactorService::SESSION_SETUP, [
            'secret' => Totp::generateSecret(),
            'created_at' => time(),
        ]);
        $controller = $this->controller('u2');
        $controller->failQr = true;

        $response = $controller->index($this->get('/profile'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($controller->rendered['vars']['twoFactor']['setup']);
        self::assertNull($this->session->get(TwoFactorService::SESSION_SETUP));
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertStringContainsString('QR', (string) ($controller->flashes[0]['message'] ?? ''));
    }

    public function testIndexShowsRecoveryCodesOnlyOnce(): void
    {
        $this->session->set(TwoFactorService::SESSION_RECOVERY, ['AAAA-BBBB', 'CCCC-DDDD']);
        $controller = $this->controller('u2');

        $controller->index($this->get('/profile'));
        self::assertSame(
            ['AAAA-BBBB', 'CCCC-DDDD'],
            $controller->rendered['vars']['twoFactor']['recovery_codes'] ?? null
        );
        self::assertNull($this->session->get(TwoFactorService::SESSION_RECOVERY));

        $controller->index($this->get('/profile'));
        $this->assertNull($controller->rendered['vars']['twoFactor']['recovery_codes']);
    }

    public function testIndexReportsEnabledStateAndRecoveryRemaining(): void
    {
        $this->users->enableTotp('u2', Totp::generateSecret(), ['AAAA-BBBB', 'CCCC-DDDD']);
        $controller = $this->controller('u2');

        $controller->index($this->get('/profile'));

        $payload = $controller->rendered['vars']['twoFactor'];
        self::assertTrue($payload['enabled']);
        self::assertSame(2, $payload['recovery_remaining']);
    }

    // ------------------------------------------------------------------
    // POST /profile/2fa/setup
    // ------------------------------------------------------------------

    public function testStartTotpSetupRejectsWrongPasswordWithoutSessionSetup(): void
    {
        $controller = $this->controller('u2');

        $response = $controller->startTotpSetup($this->post('/profile/2fa/setup', [
            'current_password' => 'salah-sekali',
        ]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile', $response->getHeader('Location'));
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertNull($this->session->get(TwoFactorService::SESSION_SETUP));
        self::assertFalse($this->users->findPublicById('u2')['totp_enabled']);
    }

    public function testStartTotpSetupStoresSecretInSessionAndFlashesSuccess(): void
    {
        $controller = $this->controller('u2');

        $response = $controller->startTotpSetup($this->post('/profile/2fa/setup', [
            'current_password' => 'rahasia123',
        ]));

        self::assertSame('/profile', $response->getHeader('Location'));
        self::assertSame('success', $controller->flashes[0]['type'] ?? null);

        $setup = $this->session->get(TwoFactorService::SESSION_SETUP);
        self::assertIsArray($setup);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', (string) $setup['secret']);
        self::assertIsInt($setup['created_at']);
        self::assertFalse($this->users->findPublicById('u2')['totp_enabled'], 'belum aktif sebelum kode dikonfirmasi');
    }

    public function testStartTotpSetupRefusesWhenTwoFactorAlreadyEnabled(): void
    {
        $this->users->enableTotp('u2', Totp::generateSecret(), []);
        $controller = $this->controller('u2');

        $controller->startTotpSetup($this->post('/profile/2fa/setup', [
            'current_password' => 'rahasia123',
        ]));

        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertStringContainsString('sudah aktif', (string) ($controller->flashes[0]['message'] ?? ''));
        self::assertNull($this->session->get(TwoFactorService::SESSION_SETUP));
    }

    // ------------------------------------------------------------------
    // POST /profile/2fa/enable
    // ------------------------------------------------------------------

    public function testEnableTotpActivatesAndExposesRecoveryCodesOnce(): void
    {
        $secret = $this->beginSetup('u2');
        $controller = $this->controller('u2');

        $response = $controller->enableTotp($this->post('/profile/2fa/enable', [
            'code' => Totp::code($secret, time()),
        ]));

        self::assertSame('/profile', $response->getHeader('Location'));
        self::assertSame('success', $controller->flashes[0]['type'] ?? null);
        self::assertTrue($this->users->findPublicById('u2')['totp_enabled']);
        self::assertNull($this->session->get(TwoFactorService::SESSION_SETUP));

        $codes = $this->session->get(TwoFactorService::SESSION_RECOVERY);
        self::assertIsArray($codes);
        self::assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $codes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        }
        self::assertSame(TwoFactorService::RECOVERY_CODE_COUNT, $this->users->recoveryCodesRemaining('u2'));
    }

    public function testEnableTotpRejectsWrongCodeAndKeepsSetup(): void
    {
        $secret = $this->beginSetup('u2');
        $valid = (int) Totp::code($secret, time());
        $wrong = str_pad((string) (($valid + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
        $controller = $this->controller('u2');

        $controller->enableTotp($this->post('/profile/2fa/enable', ['code' => $wrong]));

        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertSame('Kode verifikasi salah.', $controller->flashes[0]['message'] ?? null);
        self::assertFalse($this->users->findPublicById('u2')['totp_enabled']);
        self::assertNotNull($this->session->get(TwoFactorService::SESSION_SETUP), 'setup dipertahankan agar bisa diulang');
        self::assertNull($this->session->get(TwoFactorService::SESSION_RECOVERY));
    }

    public function testEnableTotpWithoutSetupFlashesError(): void
    {
        $controller = $this->controller('u2');

        $controller->enableTotp($this->post('/profile/2fa/enable', ['code' => '123456']));

        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertFalse($this->users->findPublicById('u2')['totp_enabled']);
    }

    public function testEnableTotpWithoutLoginReturnsJson401(): void
    {
        $controller = $this->controller(null);

        $response = $controller->enableTotp($this->postJson('/profile/2fa/enable', ['code' => '123456']));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(401, json_decode($response->rawBody(), true)['code'] ?? null);
        self::assertCount(0, $controller->flashes);
    }

    // ------------------------------------------------------------------
    // POST /profile/2fa/disable
    // ------------------------------------------------------------------

    public function testDisableTotpRequiresPasswordAndValidCode(): void
    {
        $secret = Totp::generateSecret();
        $this->users->enableTotp('u2', $secret, []);
        $controller = $this->controller('u2');

        $controller->disableTotp($this->post('/profile/2fa/disable', [
            'current_password' => 'salah-sekali',
            'code' => Totp::code($secret, time()),
        ]));

        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertTrue($this->users->findPublicById('u2')['totp_enabled'], 'percobaan gagal tidak boleh menonaktifkan');

        $controller->disableTotp($this->post('/profile/2fa/disable', [
            'current_password' => 'rahasia123',
            'code' => Totp::code($secret, time()),
        ]));

        self::assertSame('success', $controller->flashes[1]['type'] ?? null);
        self::assertSame('2FA dinonaktifkan.', $controller->flashes[1]['message'] ?? null);
        self::assertFalse($this->users->findPublicById('u2')['totp_enabled']);
    }

    public function testDisableTotpAcceptsRecoveryCode(): void
    {
        $this->users->enableTotp('u2', Totp::generateSecret(), ['AAAA-BBBB']);
        $controller = $this->controller('u2');

        $controller->disableTotp($this->post('/profile/2fa/disable', [
            'current_password' => 'rahasia123',
            'code' => 'aaaa-bbbb',
        ]));

        self::assertSame('success', $controller->flashes[0]['type'] ?? null);
        self::assertFalse($this->users->findPublicById('u2')['totp_enabled']);
    }

    public function testDisableTotpRejectsArrayValuedCodeWithoutMutation(): void
    {
        $this->users->enableTotp('u2', Totp::generateSecret(), []);
        $controller = $this->controller('u2');

        $controller->disableTotp($this->post('/profile/2fa/disable', [
            'current_password' => 'rahasia123',
            'code' => ['123456'],
        ]));

        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertSame('Kode verifikasi tidak valid.', $controller->flashes[0]['message'] ?? null);
        self::assertTrue($this->users->findPublicById('u2')['totp_enabled']);
    }

    // ------------------------------------------------------------------
    // POST /profile/2fa/recovery
    // ------------------------------------------------------------------

    public function testRegenerateTotpRecoveryReplacesCodesAndKeepsTwoFactor(): void
    {
        $secret = Totp::generateSecret();
        $this->users->enableTotp('u2', $secret, ['AAAA-BBBB']);
        $controller = $this->controller('u2');

        $response = $controller->regenerateTotpRecovery($this->post('/profile/2fa/recovery', [
            'current_password' => 'rahasia123',
            'code' => Totp::code($secret, time()),
        ]));

        self::assertSame('/profile', $response->getHeader('Location'));
        self::assertSame('success', $controller->flashes[0]['type'] ?? null);
        self::assertSame('Kode pemulihan baru dibuat.', $controller->flashes[0]['message'] ?? null);
        self::assertTrue($this->users->findPublicById('u2')['totp_enabled']);

        $codes = $this->session->get(TwoFactorService::SESSION_RECOVERY);
        self::assertIsArray($codes);
        self::assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $codes);
        self::assertSame(TwoFactorService::RECOVERY_CODE_COUNT, $this->users->recoveryCodesRemaining('u2'));
        self::assertFalse($this->users->consumeRecoveryCode('u2', 'AAAA-BBBB'), 'kode lama harus hangus');
    }

    public function testRegenerateTotpRecoveryRejectsWrongPassword(): void
    {
        $this->users->enableTotp('u2', Totp::generateSecret(), ['AAAA-BBBB']);
        $controller = $this->controller('u2');

        $controller->regenerateTotpRecovery($this->post('/profile/2fa/recovery', [
            'current_password' => 'salah-sekali',
            'code' => 'AAAA-BBBB',
        ]));

        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertSame(1, $this->users->recoveryCodesRemaining('u2'), 'tanpa mutasi');
        self::assertNull($this->session->get(TwoFactorService::SESSION_RECOVERY));
    }

    public function testRecoveryActionWithoutLoginReturnsJson401(): void
    {
        $controller = $this->controller(null);

        $response = $controller->regenerateTotpRecovery($this->postJson('/profile/2fa/recovery', [
            'current_password' => 'rahasia123',
            'code' => '123456',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testIndexWithoutLoginRedirectsBrowserToLogin(): void
    {
        $controller = $this->controller(null);

        $response = $controller->index($this->get('/profile'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeader('Location'));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(?string $userId): FakeProfileTwoFactorController
    {
        $controller = new FakeProfileTwoFactorController(
            $this->users,
            new TwoFactorService($this->users),
            $this->session
        );
        $controller->user = $userId === null ? null : $this->users->findPublicById($userId);

        return $controller;
    }

    /**
     * Jalankan langkah setup (password benar) dan kembalikan secret sesi.
     */
    private function beginSetup(string $id): string
    {
        $controller = $this->controller($id);
        $controller->startTotpSetup($this->post('/profile/2fa/setup', [
            'current_password' => 'rahasia123',
        ]));

        $setup = $this->session->get(TwoFactorService::SESSION_SETUP);
        self::assertIsArray($setup);

        return (string) $setup['secret'];
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

    private function postJson(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Accept: application/json\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
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
 * Subclass seam: session nyata dipakai bersama dan flash/render ditangkap.
 */
class FakeProfileTwoFactorController extends ProfileController
{
    public ?array $user = null;

    public Session $sessionObject;

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    /** @var array{template:string,vars:array}|null */
    public ?array $rendered = null;

    /** Paksa kegagalan render QR (uji jalur penanganan error). */
    public bool $failQr = false;

    public function __construct(UserStore $users, TwoFactorService $twoFactor, Session $session)
    {
        parent::__construct($users, $twoFactor);
        $this->sessionObject = $session;
    }

    protected function renderQr(string $uri): string
    {
        if ($this->failQr) {
            throw new \RuntimeException('qr render gagal (uji)');
        }

        return parent::renderQr($uri);
    }

    protected function session(Request $request): Session
    {
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
}
