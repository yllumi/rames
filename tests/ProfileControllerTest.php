<?php
declare(strict_types=1);

namespace Tests;

use app\controller\ProfileController;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\SqliteFixture;
use Webman\Http\Response;

/**
 * Test mediator `ProfileController` (halaman Profil self-service).
 *
 * Tanpa HTTP nyata, tanpa jaringan, tanpa Docker. Store diarahkan ke berkas
 * SQLite temp unik; user login & flash disuntik lewat seam (session Webman butuh
 * konteks HTTP), dan `renderView()` dioverride untuk menangkap template +
 * payload view (view `profile/index.php` milik misi Frontend UI; kelas ini
 * sengaja **tidak** membuat/menyentuhnya).
 *
 * Membuktikan: `index()` mengirim payload kontrak ke `profile/index`,
 * `setEmail()` mengubah email user login sendiri (anti-IDOR), email tidak valid
 * / terlalu panjang ditolak dengan flash error tanpa mutasi, dan email kosong
 * diartikan menghapus email.
 */
class ProfileControllerTest extends TestCase
{
    private string $tmp;
    private string $db;
    private UserStore $users;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-profilectrl-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);

        $this->db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            ['id' => 'u3', 'username' => 'citra', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->users = new UserStore($this->db);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // (a) index() → payload kontrak untuk view profile/index
    // ------------------------------------------------------------------

    public function testIndexRendersProfileViewWithLoggedInUserEmail(): void
    {
        $this->users->setEmail('u2', 'budi@example.com');
        $controller = $this->controller($this->sessionUser('u2'));

        $response = $controller->index($this->get('/profile'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('profile/index', $controller->rendered['template'] ?? null);

        $vars = $controller->rendered['vars'] ?? [];
        $this->assertArrayHasKey('user', $vars);
        $this->assertArrayHasKey('email', $vars);
        $this->assertSame($controller->user, $vars['user']);
        $this->assertSame('budi@example.com', $vars['email']);
    }

    /**
     * Email belum diatur ⇒ view tetap menerima string (`''`), bukan `null`.
     */
    public function testIndexSendsEmptyStringWhenEmailNotSet(): void
    {
        $controller = $this->controller($this->sessionUser('u2'));

        $controller->index($this->get('/profile'));

        $this->assertSame('', $controller->rendered['vars']['email'] ?? null);
    }

    /**
     * Tanpa user login (seharusnya dijaga AuthMiddleware) → redirect `/login`,
     * bukan error fatal.
     */
    public function testIndexWithoutLoggedInUserRedirectsToLogin(): void
    {
        $controller = $this->controller(null);

        $response = $controller->index($this->get('/profile'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
    }

    /**
     * Klien JSON tanpa login → **401 JSON**, bukan redirect `302 /login`
     * (kontrak sama dengan `AuthMiddleware::unauthenticated()`).
     */
    public function testIndexWithoutLoggedInUserReturnsJson401ForJsonClients(): void
    {
        $controller = $this->controller(null);

        $response = $controller->index($this->getJson('/profile'));

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode($response->rawBody(), true);
        $this->assertSame(401, $payload['code'] ?? null);
    }

    public function testSetEmailWithoutLoggedInUserReturnsJson401ForJsonClients(): void
    {
        $controller = $this->controller(null);

        $response = $controller->setEmail($this->postJson('/profile/email', ['email' => 'baru@example.com']));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(401, json_decode($response->rawBody(), true)['code'] ?? null);
    }

    // ------------------------------------------------------------------
    // (b) setEmail valid → user login berubah + flash sukses + redirect
    // ------------------------------------------------------------------

    public function testSetEmailUpdatesLoggedInUserAndFlashesSuccess(): void
    {
        $controller = $this->controller($this->sessionUser('u2'));

        $response = $controller->setEmail($this->post('/profile/email', ['email' => 'baru@example.com']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile', $response->getHeader('Location'));

        $bud = $this->users->findById('u2');
        $this->assertNotNull($bud);
        $this->assertSame('baru@example.com', $this->users->emailOf($bud));

        $this->assertCount(1, $controller->flashes);
        $this->assertSame('success', $controller->flashes[0]['type']);
        $this->assertStringContainsString('diperbarui', $controller->flashes[0]['message']);
    }

    /**
     * Spasi di sekitar email di-trim (persis seperti `CreditController::setEmail`).
     */
    public function testSetEmailTrimsWhitespace(): void
    {
        $controller = $this->controller($this->sessionUser('u2'));

        $controller->setEmail($this->post('/profile/email', ['email' => '  budi@example.com  ']));

        $bud = $this->users->findById('u2');
        $this->assertNotNull($bud);
        $this->assertSame('budi@example.com', $this->users->emailOf($bud));
    }

    // ------------------------------------------------------------------
    // (c) email tidak valid → flash error, tanpa mutasi
    // ------------------------------------------------------------------

    public function testSetEmailRejectsInvalidAddressWithoutMutating(): void
    {
        $this->users->setEmail('u2', 'lama@example.com');
        $controller = $this->controller($this->sessionUser('u2'));

        $response = $controller->setEmail($this->post('/profile/email', ['email' => 'bukan-email']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile', $response->getHeader('Location'));

        $bud = $this->users->findById('u2');
        $this->assertNotNull($bud);
        $this->assertSame('lama@example.com', $this->users->emailOf($bud), 'email lama dipertahankan');
        $this->assertSame('error', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // (d) email > 50 karakter → ditolak (batas dari UserStore)
    // ------------------------------------------------------------------

    public function testSetEmailRejectsAddressLongerThanFiftyCharacters(): void
    {
        $controller = $this->controller($this->sessionUser('u2'));
        $tooLong = str_repeat('a', 45) . '@example.com'; // 57 karakter

        $response = $controller->setEmail($this->post('/profile/email', ['email' => $tooLong]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile', $response->getHeader('Location'));

        $bud = $this->users->findById('u2');
        $this->assertNotNull($bud);
        $this->assertSame('', $this->users->emailOf($bud), 'tidak ada perubahan');
        $this->assertSame('error', $controller->flashes[0]['type']);
        $this->assertStringContainsString('50', $controller->flashes[0]['message']);
    }

    // ------------------------------------------------------------------
    // (e) anti-IDOR: `id`/`user_id` user lain diabaikan
    // ------------------------------------------------------------------

    public function testSetEmailIgnoresForeignUserIdFromRequest(): void
    {
        $controller = $this->controller($this->sessionUser('u2'));

        $response = $controller->setEmail($this->post('/profile/email', [
            'email' => 'budi@example.com',
            // Percobaan menargetkan user lain — harus DIABAIKAN.
            'id' => 'u1',
            'user_id' => 'u1',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile', $response->getHeader('Location'));

        $bud = $this->users->findById('u2');
        $admin = $this->users->findById('u1');
        $this->assertNotNull($bud);
        $this->assertNotNull($admin);
        $this->assertSame('budi@example.com', $this->users->emailOf($bud));
        $this->assertSame('', $this->users->emailOf($admin), 'email user lain tidak boleh berubah');
        $this->assertSame('success', $controller->flashes[0]['type']);
    }

    /**
     * Input berbentuk array (`email[]=…`) bukan "hapus email": ditolak sebagai
     * tidak valid, email lama dipertahankan (tanpa error 500).
     */
    public function testArrayValuedEmailIsRejectedWithoutClearingEmail(): void
    {
        $this->users->setEmail('u2', 'budi@example.com');
        $controller = $this->controller($this->sessionUser('u2'));

        $response = $controller->setEmail($this->post('/profile/email', ['email' => ['x']]));

        $this->assertSame(302, $response->getStatusCode());
        $bud = $this->users->findById('u2');
        $this->assertNotNull($bud);
        $this->assertSame('budi@example.com', $this->users->emailOf($bud));
        $this->assertSame('error', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // (f) email kosong = hapus email (bukan error)
    // ------------------------------------------------------------------

    public function testEmptyEmailRemovesEmailWithoutError(): void
    {
        $this->users->setEmail('u2', 'budi@example.com');
        $controller = $this->controller($this->sessionUser('u2'));

        $response = $controller->setEmail($this->post('/profile/email', ['email' => '']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/profile', $response->getHeader('Location'));

        $bud = $this->users->findById('u2');
        $this->assertNotNull($bud);
        $this->assertSame('', $this->users->emailOf($bud));
        $this->assertSame('success', $controller->flashes[0]['type']);
        $this->assertStringContainsString('dihapus', $controller->flashes[0]['message']);
    }

    /**
     * Field `email` tak hadir sama sekali ⇒ sama dengan kosong (hapus email),
     * bukan error.
     */
    public function testMissingEmailFieldIsTreatedAsRemoval(): void
    {
        $this->users->setEmail('u3', 'citra@example.com');
        $controller = $this->controller($this->sessionUser('u3'));

        $controller->setEmail($this->post('/profile/email', []));

        $citra = $this->users->findById('u3');
        $this->assertNotNull($citra);
        $this->assertSame('', $this->users->emailOf($citra));
        $this->assertSame('success', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(?array $user): FakeProfileController
    {
        $controller = new FakeProfileController($this->users);
        $controller->user = $user;

        return $controller;
    }

    /**
     * Bentuk user sesi seperti hasil `AuthMiddleware::syncSessionUser()`
     * (fresh dari store, berisi `email`).
     */
    private function sessionUser(string $id): ?array
    {
        return $this->users->findPublicById($id);
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

    private function getJson(string $path): Request
    {
        return new Request("GET $path HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n");
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
 * Subclass seam: user login & flash disuntik tanpa session HTTP, dan payload
 * view ditangkap (bukan dirender) karena `view()` butuh konteks request.
 */
class FakeProfileController extends ProfileController
{
    public ?array $user = null;

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    /** @var array{template:string,vars:array}|null */
    public ?array $rendered = null;

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
