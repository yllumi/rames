<?php
declare(strict_types=1);

namespace Tests;

use app\controller\UserController;
use app\library\Auth\UserStore;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\SqliteFixture;

/**
 * Test mediator `UserController::setServerAccess()` (grant pemilihan server).
 *
 * Harness mengikuti pola controller lain: seam `users()`/`servers()` diganti
 * store temp, helper global (`is_admin`/`current_user`/`flash_set`) distub di
 * proses anak. Tidak menyentuh data runtime (`database/`, `apps/`).
 */
class UserControllerServerAccessTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];

    private const STUB_SOURCE = <<<'PHP'
        <?php
        namespace {
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return $GLOBALS['RAMES_TEST_USER'] ?? null;
                }
            }
            if (!function_exists('is_admin')) {
                function is_admin(?array $user = null): bool
                {
                    $user ??= current_user();

                    return ($user['role'] ?? '') === 'admin';
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                    $GLOBALS['RAMES_FLASH'][] = ['type' => $type, 'message' => $message];
                }
            }
        }
        PHP;

    private string $tmp;
    private string $db;
    private UserStore $users;
    private ServerStore $servers;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/rames-userctrl-server-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->db = $this->tmp . '/rames.sqlite';

        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->users = new UserStore($this->db);
        $this->servers = new ServerStore($this->db);
        $this->servers->local();
        foreach (['srv-a', 'srv-b'] as $id) {
            $this->servers->create([
                'id' => $id,
                'name' => $id,
                'kind' => 'agent',
                'enabled' => true,
                'base_url' => 'https://' . $id . '.internal.test:8443',
                'token' => 'tok-' . $id,
            ]);
        }
        ServerResolver::forgetMemo();

        $GLOBALS['RAMES_FLASH'] = [];
        $GLOBALS['RAMES_TEST_USER'] = self::ADMIN;
        $this->installStubs();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        unset($GLOBALS['RAMES_FLASH'], $GLOBALS['RAMES_TEST_USER']);
        self::removeTree($this->tmp);
    }

    private function installStubs(): void
    {
        if (!function_exists('flash_set')) {
            $file = $this->tmp . '/stubs.php';
            file_put_contents($file, self::STUB_SOURCE);
            require $file;
        }
    }

    private function controller(?ServerResolver $resolver = null, bool $serversBroken = false): TestableUserServerController
    {
        return new TestableUserServerController(
            $this->users,
            $resolver ?? new ServerResolver($this->servers),
            $serversBroken
        );
    }

    /** @param array<string,mixed> $fields */
    private function post(array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST /users/u2/servers HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    private function access(): array
    {
        $user = $this->users->findPublicById('u2');
        self::assertIsArray($user);

        return $user;
    }

    /** @return array<int,array{type:string,message:string}> */
    private function flashes(): array
    {
        return $GLOBALS['RAMES_FLASH'] ?? [];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminSavesGrantWithAllowlistAndDropsUnknownIds(): void
    {
        $this->controller()->setServerAccess(
            $this->post(['server_select' => '1', 'server_ids' => ['srv-a', 'ghost', 'srv-b']]),
            'u2'
        );

        $user = $this->access();
        self::assertTrue($user['server_select']);
        self::assertSame(['srv-a', 'srv-b'], $user['server_ids'], 'id asing dibuang, duplikat dinormalkan');

        $flash = $this->flashes();
        self::assertCount(1, $flash);
        self::assertSame('success', $flash[0]['type']);
        self::assertStringContainsString('2 server diizinkan', $flash[0]['message']);
        self::assertStringContainsString('1 id tidak dikenal diabaikan', $flash[0]['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEmptyAllowlistWithGrantMeansAllServers(): void
    {
        $this->controller()->setServerAccess($this->post(['server_select' => '1']), 'u2');

        $user = $this->access();
        self::assertTrue($user['server_select']);
        self::assertSame([], $user['server_ids'], 'server_ids absen = semua server (disengaja)');
        self::assertStringContainsString('boleh memilih semua server', $this->flashes()[0]['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAllInvalidSubmittedIdsAreRejectedInsteadOfSavingEmptyAllowlist(): void
    {
        $this->controller()->setServerAccess(
            $this->post(['server_select' => '1', 'server_ids' => ['ghost-a', 'ghost-b']]),
            'u2'
        );

        $user = $this->access();
        self::assertFalse($user['server_select'], 'tidak ada perubahan saat semua id tidak valid');
        self::assertSame([], $user['server_ids']);

        $flash = $this->flashes();
        self::assertCount(1, $flash);
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Tidak ada id server yang valid', $flash[0]['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnknownUserIsReportedWithoutWriting(): void
    {
        $this->controller()->setServerAccess(
            $this->post(['server_select' => '1', 'server_ids' => ['srv-a']]),
            'ghost'
        );

        self::assertFalse($this->access()['server_select']);
        $flash = $this->flashes();
        self::assertSame('error', $flash[0]['type']);
        self::assertSame('User tidak ditemukan.', $flash[0]['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnreadableRegistryFailsClosed(): void
    {
        // Resolver yang gagal dibaca (simulasi store rusak/tak terbaca) ⇒
        // controller wajib fail-closed, bukan menyimpan `[]`.
        $this->controller(null, true)->setServerAccess(
            $this->post(['server_select' => '1', 'server_ids' => ['srv-a']]),
            'u2'
        );

        $user = $this->access();
        self::assertFalse($user['server_select']);
        self::assertSame([], $user['server_ids']);
        $flash = $this->flashes();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Registry server tidak terbaca', $flash[0]['message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNonAdminIsRejected(): void
    {
        $GLOBALS['RAMES_TEST_USER'] = self::MEMBER;

        $this->controller()->setServerAccess(
            $this->post(['server_select' => '1', 'server_ids' => ['srv-a']]),
            'u2'
        );

        $user = $this->access();
        self::assertFalse($user['server_select']);
        self::assertSame([], $user['server_ids']);
        $flash = $this->flashes();
        self::assertSame('error', $flash[0]['type']);
        self::assertSame('Halaman kelola user hanya untuk admin.', $flash[0]['message']);
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

/** `UserController` dengan seam store & registry temp (tanpa data runtime). */
final class TestableUserServerController extends UserController
{
    public function __construct(
        private UserStore $userStore,
        private ServerResolver $serverResolver,
        private bool $serversBroken = false,
    ) {
    }

    protected function users(): UserStore
    {
        return $this->userStore;
    }

    protected function servers(): ServerResolver
    {
        if ($this->serversBroken) {
            throw new \RuntimeException('registry server tidak terbaca (uji)');
        }

        return $this->serverResolver;
    }
}
