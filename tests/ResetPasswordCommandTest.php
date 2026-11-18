<?php
declare(strict_types=1);

namespace Tests;

use app\command\ResetPassword;
use app\library\Auth\Totp;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\SqliteFixture;

/**
 * Test command darurat `passwd:reset` (docs/SPECS.md §6.3).
 *
 * Membuktikan: password benar-benar diganti (hash baru, password lama tidak
 * berlaku) sementara role/2FA/API key tetap; password lemah ditolak tanpa
 * mengubah apa pun; username tak dikenal & store kosong gagal dengan pesan
 * jelas; mode non-interaktif men-generate password yang dicetak sekali.
 *
 * Store selalu berkas SQLite temp — data runtime tidak disentuh.
 */
class ResetPasswordCommandTest extends TestCase
{
    private string $tmp;
    private string $db;
    private UserStore $users;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-passwd-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);

        $this->db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($this->db, [[
            'id' => 'u1',
            'username' => 'admin',
            'password_hash' => password_hash('lama-sekali', PASSWORD_BCRYPT),
            'role' => 'admin',
            'created_at' => '',
        ]]);

        $this->users = new UserStore($this->db);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    public function testResetsPasswordAndKeepsRoleAndTwoFactor(): void
    {
        $this->users->enableTotp('u1', Totp::generateSecret(), ['ABCD-EFGH']);
        $apiKey = $this->users->issueApiKey('u1');

        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'admin', 'password' => 'baru-sekali']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('berhasil direset', $tester->getDisplay());

        self::assertTrue($this->users->verifyPassword('u1', 'baru-sekali'));
        self::assertFalse($this->users->verifyPassword('u1', 'lama-sekali'));

        $user = $this->users->findById('u1') ?? [];
        self::assertSame('admin', $user['role'] ?? null);
        self::assertTrue(UserStore::totpEnabled($user), '2FA tidak ikut dihapus');
        self::assertNotNull($this->users->actorByApiKey($apiKey), 'API key tetap berlaku');
    }

    public function testRejectsShortPasswordWithoutChangingAnything(): void
    {
        $before = $this->users->findById('u1') ?? [];

        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'admin', 'password' => '123']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('minimal 6 karakter', $tester->getDisplay());
        self::assertSame($before, $this->users->findById('u1'));
    }

    public function testUnknownUsernameFailsWithoutChangingAnything(): void
    {
        $before = $this->users->all();

        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'tidak-ada', 'password' => 'baru-sekali']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('tidak ditemukan', $tester->getDisplay());
        self::assertSame($before, $this->users->all());
    }

    public function testEmptyStoreFailsWithProvisioningHint(): void
    {
        $emptyDb = $this->tmp . '/empty.sqlite';
        SqliteFixture::users($emptyDb, []);

        $tester = $this->tester(new UserStore($emptyDb));
        $exit = $tester->execute(['username' => 'admin', 'password' => 'baru-sekali']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('make:admin', $tester->getDisplay());
    }

    public function testGeneratesPasswordWhenNonInteractive(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'admin'], ['interactive' => false]);

        self::assertSame(0, $exit);

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/password : ([0-9a-f]{12})/', $display);
        preg_match('/password : ([0-9a-f]{12})/', $display, $match);

        self::assertTrue($this->users->verifyPassword('u1', $match[1]), 'password hasil generate berlaku');
        self::assertFalse($this->users->verifyPassword('u1', 'lama-sekali'));
    }

    public function testInteractivePromptAsksTwiceAndRejectsMismatch(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['pertama123', 'kedua456']);
        $exit = $tester->execute(['username' => 'admin']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('tidak sama', $tester->getDisplay());
        self::assertTrue($this->users->verifyPassword('u1', 'lama-sekali'), 'password lama tetap berlaku');

        $tester = $this->tester();
        $tester->setInputs(['sama-sekali', 'sama-sekali']);
        $exit = $tester->execute(['username' => 'admin']);

        self::assertSame(0, $exit);
        self::assertTrue($this->users->verifyPassword('u1', 'sama-sekali'));
    }

    public function testTotpNoteIsPrintedForTwoFactorUser(): void
    {
        $this->users->enableTotp('u1', Totp::generateSecret(), ['ABCD-EFGH']);

        $tester = $this->tester();
        $tester->execute(['username' => 'admin', 'password' => 'baru-sekali']);

        self::assertStringContainsString('2FA user ini tetap aktif', $tester->getDisplay());
    }

    private function tester(?UserStore $store = null): CommandTester
    {
        $store ??= $this->users;

        $command = new class ($store) extends ResetPassword {
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
