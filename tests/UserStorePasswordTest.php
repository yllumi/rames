<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use ErrorException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteFixture;

/**
 * Test primitif `UserStore::verifyPassword($id, $password)` — fondasi fitur
 * "ubah password" self-service di halaman Profil (verifikasi berdasarkan id
 * user login, bukan username dari request).
 *
 * Semua I/O memakai berkas basis data temp unik; data runtime nyata tidak
 * disentuh. Kasus hash korup/legacy dibuktikan tidak melempar exception atau
 * notice: selama pemanggilan, error handler sementara mengubah setiap warning
 * /notice menjadi ErrorException.
 */
class UserStorePasswordTest extends TestCase
{
    private const PASSWORD = 'rahasia123';

    private string $tmp;
    private string $path;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-user-password-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
        $this->path = $this->tmp . '/rames.sqlite';

        SqliteFixture::users($this->path, [
            // Hash bcrypt valid.
            [
                'id' => 'u1', 'username' => 'admin',
                'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
                'role' => 'admin', 'created_at' => '',
            ],
            // Hash kosong (korup/legacy).
            ['id' => 'u2', 'username' => 'kosong', 'password_hash' => '', 'role' => 'member', 'created_at' => ''],
            // Hash legacy placeholder non-bcrypt.
            ['id' => 'u3', 'username' => 'legacy', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
            // Hash bukan string (korup) — tidak boleh TypeError.
            ['id' => 'u4', 'username' => 'korup', 'password_hash' => 12345, 'role' => 'member', 'created_at' => ''],
            // Hash string tapi bukan hash bcrypt valid.
            ['id' => 'u5', 'username' => 'palsu', 'password_hash' => 'bukan-hash-bcrypt', 'role' => 'member', 'created_at' => ''],
            // Tanpa field password_hash sama sekali.
            ['id' => 'u6', 'username' => 'tanpahash', 'role' => 'member', 'created_at' => ''],
            // Hash bcrypt dari string kosong (tidak dibuat lewat create(), tapi
            // bisa muncul dari migrasi/impor luar) — tetap harus ditolak.
            [
                'id' => 'u7', 'username' => 'kosongpass',
                'password_hash' => password_hash('', PASSWORD_BCRYPT),
                'role' => 'member', 'created_at' => '',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function store(): UserStore
    {
        return new UserStore($this->path);
    }

    /**
     * Jalankan $fn dengan semua PHP error (warning/notice/deprecation) diubah
     * menjadi ErrorException, lalu kembalikan hasilnya.
     */
    private function failOnPhpErrors(callable $fn): bool
    {
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return (bool) $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function testCorrectPasswordReturnsTrue(): void
    {
        $this->assertTrue($this->store()->verifyPassword('u1', self::PASSWORD));
    }

    public function testWrongPasswordReturnsFalse(): void
    {
        $this->assertFalse($this->store()->verifyPassword('u1', 'salah-total'));
    }

    public function testUnknownIdReturnsFalseWithoutThrow(): void
    {
        $this->assertFalse($this->store()->verifyPassword('ghost', self::PASSWORD));
        $this->assertFalse($this->store()->verifyPassword('', self::PASSWORD));
    }

    public function testEmptyHashAndLegacyPlaceholderReturnFalse(): void
    {
        $store = $this->store();

        $this->assertFalse($this->failOnPhpErrors(
            static fn (): bool => $store->verifyPassword('u2', self::PASSWORD)
        ));
        $this->assertFalse($this->failOnPhpErrors(
            static fn (): bool => $store->verifyPassword('u3', self::PASSWORD)
        ));
        $this->assertFalse($this->failOnPhpErrors(
            static fn (): bool => $store->verifyPassword('u3', 'x')
        ));
    }

    public function testMissingAndNonStringHashReturnFalseWithoutNotice(): void
    {
        $store = $this->store();

        $this->assertFalse($this->failOnPhpErrors(
            static fn (): bool => $store->verifyPassword('u4', self::PASSWORD)
        ));
        $this->assertFalse($this->failOnPhpErrors(
            static fn (): bool => $store->verifyPassword('u6', self::PASSWORD)
        ));
    }

    public function testMalformedHashStringReturnsFalseWithoutFatal(): void
    {
        $store = $this->store();

        $this->assertFalse($this->failOnPhpErrors(
            static fn (): bool => $store->verifyPassword('u5', self::PASSWORD)
        ));
    }

    public function testEmptyPasswordReturnsFalse(): void
    {
        $store = $this->store();

        // Hash bcrypt normal tidak mungkin cocok dengan string kosong.
        $this->assertFalse($store->verifyPassword('u1', ''));
        // Hash yang memang dibuat dari string kosong pun tetap ditolak.
        $this->assertFalse($store->verifyPassword('u7', ''));
    }

    /**
     * `verify()` lama tidak boleh berubah perilakunya (tetap mengembalikan user
     * publik tanpa password_hash untuk kredensial valid).
     */
    public function testLegacyVerifyByUsernameStillWorks(): void
    {
        $store = $this->store();

        $user = $store->verify('admin', self::PASSWORD);
        $this->assertNotNull($user);
        $this->assertSame('u1', $user['id']);
        $this->assertArrayNotHasKey('password_hash', $user);
        $this->assertNull($store->verify('admin', 'salah-total'));
    }

    /**
     * Verifikasi tidak boleh memutasi store: hash tetap utuh setelah pemanggilan.
     */
    public function testVerificationDoesNotMutateStoredHash(): void
    {
        $store = $this->store();
        $before = (string) $store->findById('u1')['password_hash'];

        $this->assertTrue($store->verifyPassword('u1', self::PASSWORD));
        $this->assertFalse($store->verifyPassword('u1', 'salah-total'));

        $this->assertSame($before, (string) $store->findById('u1')['password_hash']);
        $this->assertCount(7, SqliteFixture::readAll($this->path, 'auth'));
    }
}
