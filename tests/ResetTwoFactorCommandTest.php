<?php
declare(strict_types=1);

namespace Tests;

use app\command\ResetTwoFactor;
use app\library\Auth\Totp;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\SqliteFixture;

/**
 * Test command darurat `2fa:reset` (docs/SPECS.md §6.4).
 *
 * Membuktikan: 2FA user target benar-benar dinonaktifkan (secret, hash kode
 * pemulihan, dan anti-replay hilang; password/role tetap), user yang memang
 * tidak ber-2FA tidak diubah, dan username tak dikenal gagal dengan pesan
 * yang jelas. Store selalu berkas SQLite temp — bukan data runtime.
 */
class ResetTwoFactorCommandTest extends TestCase
{
    private string $tmp;
    private string $db;
    private UserStore $users;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-2fareset-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);

        $this->db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
        ]);

        $this->users = new UserStore($this->db);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    public function testResetDisablesTwoFactorAndKeepsPasswordAndRole(): void
    {
        $this->users->enableTotp('u1', Totp::generateSecret(), ['ABCD-EFGH', 'IJKL-MNOP']);
        self::assertTrue(UserStore::totpEnabled($this->users->findById('u1') ?? []));

        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'admin']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('dinonaktifkan', $tester->getDisplay());

        $user = $this->users->findById('u1') ?? [];
        self::assertFalse(UserStore::totpEnabled($user));
        self::assertSame(0, $this->users->recoveryCodesRemaining('u1'));
        self::assertArrayNotHasKey('totp_secret', $user);
        self::assertArrayNotHasKey('totp_last_step', $user);
        // Password & role tidak boleh ikut berubah.
        self::assertSame('x', $user['password_hash'] ?? null);
        self::assertSame('admin', $user['role'] ?? null);
    }

    public function testResetOnUserWithoutTwoFactorIsNoOp(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'admin']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('memang tidak aktif', $tester->getDisplay());
        self::assertSame('x', ($this->users->findById('u1') ?? [])['password_hash'] ?? null);
    }

    public function testUnknownUsernameFailsWithoutChangingAnything(): void
    {
        $before = $this->users->all();

        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'tidak-ada']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('tidak ditemukan', $tester->getDisplay());
        self::assertSame($before, $this->users->all());
    }

    private function tester(): CommandTester
    {
        $command = new class ($this->users) extends ResetTwoFactor {
            public function __construct(private UserStore $store)
            {
                parent::__construct();
            }

            protected function users(): UserStore
            {
                return $this->store;
            }
        };

        return new CommandTester($command);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
