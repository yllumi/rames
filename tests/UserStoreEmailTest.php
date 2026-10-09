<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test field `email` opsional pada UserStore (prasyarat top-up Duitku, SPECS
 * §7.12) — tanpa migrasi: entri lama tanpa `email` tetap valid.
 *
 * Semua I/O memakai berkas temp unik; `database/auth.json` nyata tidak disentuh.
 */
class UserStoreEmailTest extends TestCase
{
    private string $tmp;
    private string $path;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-user-email-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
        $this->path = $this->tmp . '/auth.json';

        // Skema lama: TANPA field email (harus tetap valid).
        file_put_contents($this->path, json_encode([
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

    private function raw(): array
    {
        return json_decode((string) file_get_contents($this->path), true);
    }

    public function testLegacyUserWithoutEmailIsValid(): void
    {
        $store = $this->store();
        $user = $store->findById('u2');

        $this->assertNotNull($user);
        $this->assertArrayNotHasKey('email', $user);
        $this->assertSame('', $store->emailOf($user));
    }

    public function testEmailOfTrimsAndToleratesBadValues(): void
    {
        $store = $this->store();

        $this->assertSame('a@b.id', $store->emailOf(['email' => '  a@b.id  ']));
        $this->assertSame('', $store->emailOf(['email' => '   ']));
        $this->assertSame('', $store->emailOf([]));
        // Nilai non-string (korup) tidak boleh menyebabkan TypeError.
        $this->assertSame('', $store->emailOf(['email' => 123]));
        $this->assertSame('', $store->emailOf(['email' => null]));
    }

    public function testPublicUserAlwaysIncludesEmailString(): void
    {
        $store = $this->store();
        $users = $store->listWithRoles();

        $this->assertCount(2, $users);
        $this->assertSame('', $users[0]['email']);
        $this->assertSame('', $users[1]['email']);
        $this->assertArrayNotHasKey('password_hash', $users[0]);
    }

    public function testSetEmailPersistsAndIsVisibleInPublicData(): void
    {
        $store = $this->store();
        $store->setEmail('u2', 'member@example.com');

        $this->assertSame('member@example.com', $store->emailOf((array) $store->findById('u2')));
        $this->assertSame('member@example.com', $store->findPublicById('u2')['email']);
        $this->assertSame('member@example.com', $store->listWithRoles()[1]['email']);
        $this->assertSame('member@example.com', $this->raw()[1]['email']);
        // Password hash tetap ada di berkas mentah (tidak terganggu).
        $this->assertArrayHasKey('password_hash', $this->raw()[1]);
    }

    public function testSetEmailTrimsInput(): void
    {
        $store = $this->store();
        $store->setEmail('u2', '  pad@example.com  ');

        $this->assertSame('pad@example.com', $this->raw()[1]['email']);
    }

    public function testBlankEmailRemovesField(): void
    {
        $store = $this->store();
        $store->setEmail('u2', 'x@example.com');
        $this->assertArrayHasKey('email', $this->raw()[1]);

        $store->setEmail('u2', '   ');
        $this->assertArrayNotHasKey('email', $this->raw()[1]);
        $this->assertSame('', $store->emailOf((array) $store->findById('u2')));
    }

    public function testInvalidEmailIsRejectedWithIndonesianMessage(): void
    {
        $store = $this->store();

        try {
            $store->setEmail('u2', 'bukan-email');
            $this->fail('Email invalid seharusnya ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Format email tidak valid.', $e->getMessage());
        }

        // Tidak ada yang berubah.
        $this->assertArrayNotHasKey('email', $this->raw()[1]);
    }

    public function testEmailLongerThan50CharsIsRejected(): void
    {
        $store = $this->store();
        $email = str_repeat('a', 42) . '@example.com'; // 54 karakter

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email maksimal 50 karakter.');
        $store->setEmail('u2', $email);
    }

    public function testEmailAt50CharsIsAccepted(): void
    {
        $store = $this->store();
        $email = str_repeat('a', 38) . '@example.com'; // 50 karakter
        $this->assertSame(50, strlen($email));

        $store->setEmail('u2', $email);

        $this->assertSame($email, $this->raw()[1]['email']);
    }

    public function testSetEmailForUnknownUserThrows(): void
    {
        $store = $this->store();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User tidak ditemukan.');
        $store->setEmail('ghost', 'a@example.com');
    }

    public function testCreateLeavesEmailEmpty(): void
    {
        $store = $this->store();
        $created = $store->create('baru', 'rahasia123');

        $this->assertSame('', $created['email']);
        $this->assertSame('', $store->emailOf((array) $store->findById((string) $created['id'])));
    }
}
