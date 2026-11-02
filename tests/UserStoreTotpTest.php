<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\Totp;
use app\library\Auth\UserStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SqliteFixture;

/**
 * Test field 2FA pada dokumen user ({@see UserStore}) — tanpa migrasi skema,
 * tanpa menyentuh basis data nyata (SQLite temp unik per test).
 *
 * Membuktikan: aktivasi/penonaktifan, anti-replay TOTP, kode pemulihan
 * single-use, normalisasi secret/kode, dan bahwa bentuk publik user tidak
 * pernah memuat secret/hash.
 */
class UserStoreTotpTest extends TestCase
{
    private string $tmp;

    private string $db;

    private UserStore $users;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-usertotp-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->db = $this->tmp . '/rames.sqlite';

        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->users = new UserStore($this->db);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // Aktivasi
    // ------------------------------------------------------------------

    public function testEnableTotpMarksUserEnabledAndHidesSecrets(): void
    {
        $secret = Totp::generateSecret();

        $this->users->enableTotp('u1', $secret, ['AAAA-BBBB', 'CCCC-DDDD']);

        $public = $this->users->findPublicById('u1');
        self::assertNotNull($public);
        self::assertTrue($public['totp_enabled']);
        self::assertTrue(UserStore::totpEnabled($public));
        // Bentuk publik tidak memuat hash; hitungan diambil dari store.
        self::assertSame(0, UserStore::recoveryCodeCount($public));
        self::assertSame(2, $this->users->recoveryCodesRemaining('u1'));

        foreach (['totp_secret', 'totp_recovery_codes', 'totp_last_step', 'totp_enabled_at'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $public, "field rahasia {$hidden} tidak boleh bocor ke bentuk publik");
        }
        self::assertStringNotContainsString($secret, (string) json_encode($public));
    }

    public function testEnableTotpNormalizesSecretToUppercaseBase32(): void
    {
        $secret = Totp::generateSecret();

        $this->users->enableTotp('u1', strtolower($secret), []);

        self::assertSame($secret, $this->users->totpSecret('u1'));
    }

    public function testEnableTotpStoresOnlyHashedRecoveryCodes(): void
    {
        $this->users->enableTotp('u1', Totp::generateSecret(), ['aaaa-bbbb']);

        $raw = $this->users->findById('u1');
        self::assertNotNull($raw);
        $hashes = $raw['totp_recovery_codes'] ?? [];
        self::assertIsArray($hashes);
        self::assertCount(1, $hashes);
        self::assertNotSame('AAAABBBB', $hashes[0]);
        self::assertTrue(password_verify('AAAABBBB', (string) $hashes[0]));
    }

    public function testEnableTotpRejectsInvalidSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->users->enableTotp('u1', 'BUKAN!BASE32', []);
    }

    public function testEnableTotpForUnknownUserThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->users->enableTotp('tidak-ada', Totp::generateSecret(), []);
    }

    // ------------------------------------------------------------------
    // Verifikasi & anti-replay
    // ------------------------------------------------------------------

    public function testVerifyTotpAcceptsCorrectCodeAndRejectsReplayOfSameStep(): void
    {
        $secret = Totp::generateSecret();
        $timestamp = 1_700_000_000;
        $this->users->enableTotp('u1', $secret, []);

        $code = Totp::code($secret, $timestamp);

        self::assertTrue($this->users->verifyTotp('u1', $code, $timestamp));
        self::assertFalse($this->users->verifyTotp('u1', $code, $timestamp), 'kode langkah yang sama tidak boleh dipakai ulang');
    }

    public function testVerifyTotpRejectsWrongCodeWithoutMutatingLastStep(): void
    {
        $secret = Totp::generateSecret();
        $timestamp = 1_700_000_000;
        $this->users->enableTotp('u1', $secret, []);

        self::assertFalse($this->users->verifyTotp('u1', '000000', $timestamp));
        self::assertSame(0, (int) ($this->users->findById('u1')['totp_last_step'] ?? -1));
    }

    public function testVerifyTotpReturnsFalseWhenTwoFactorDisabled(): void
    {
        self::assertFalse($this->users->verifyTotp('u1', '123456', 1_700_000_000));

        $secret = Totp::generateSecret();
        $this->users->enableTotp('u1', $secret, []);
        self::assertFalse($this->users->verifyTotp('tidak-ada', Totp::code($secret, 1_700_000_000), 1_700_000_000));
    }

    // ------------------------------------------------------------------
    // Kode pemulihan
    // ------------------------------------------------------------------

    public function testConsumeRecoveryCodeIsSingleUse(): void
    {
        $this->users->enableTotp('u1', Totp::generateSecret(), ['AAAA-BBBB', 'CCCC-DDDD']);

        self::assertTrue($this->users->consumeRecoveryCode('u1', 'aaaa bbbb'));
        self::assertSame(1, $this->users->recoveryCodesRemaining('u1'));
        self::assertFalse($this->users->consumeRecoveryCode('u1', 'AAAA-BBBB'));
        self::assertSame(1, $this->users->recoveryCodesRemaining('u1'));
    }

    public function testConsumeRecoveryCodeRejectsUnknownCodeWithoutMutation(): void
    {
        $this->users->enableTotp('u1', Totp::generateSecret(), ['AAAA-BBBB']);

        self::assertFalse($this->users->consumeRecoveryCode('u1', 'ZZZZ-ZZZZ'));
        self::assertSame(1, $this->users->recoveryCodesRemaining('u1'));
    }

    public function testReplaceRecoveryCodesOverwritesPreviousSet(): void
    {
        $this->users->enableTotp('u1', Totp::generateSecret(), ['AAAA-BBBB']);
        $this->users->replaceRecoveryCodes('u1', ['EEEE-FFFF', 'GGGG-HHHH', 'IIII-JJJJ']);

        self::assertSame(3, $this->users->recoveryCodesRemaining('u1'));
        self::assertFalse($this->users->consumeRecoveryCode('u1', 'AAAA-BBBB'));
        self::assertTrue($this->users->consumeRecoveryCode('u1', 'GGGG-HHHH'));
    }

    // ------------------------------------------------------------------
    // Penonaktifan
    // ------------------------------------------------------------------

    public function testDisableTotpRemovesAllTwoFactorFields(): void
    {
        $this->users->enableTotp('u1', Totp::generateSecret(), ['AAAA-BBBB']);

        $this->users->disableTotp('u1');

        $raw = $this->users->findById('u1');
        self::assertNotNull($raw);
        foreach (['totp_secret', 'totp_enabled_at', 'totp_last_step', 'totp_recovery_codes'] as $field) {
            self::assertArrayNotHasKey($field, $raw);
        }
        self::assertFalse($this->users->findPublicById('u1')['totp_enabled']);
        self::assertSame('', $this->users->totpSecret('u1'));
        self::assertSame(0, $this->users->recoveryCodesRemaining('u1'));
    }

    public function testDisableTotpForUnknownUserThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->users->disableTotp('tidak-ada');
    }

    // ------------------------------------------------------------------
    // Normalisasi & statik
    // ------------------------------------------------------------------

    public function testNormalizeRecoveryCodeStripsNonAlphanumerics(): void
    {
        self::assertSame('AAAABBBB', UserStore::normalizeRecoveryCode(' aa-aa bb bb '));
        self::assertSame('', UserStore::normalizeRecoveryCode('---'));
    }

    public function testTotpEnabledIsFalseForLegacyDocumentWithoutFields(): void
    {
        self::assertFalse(UserStore::totpEnabled(['id' => 'u1', 'username' => 'admin']));
        self::assertSame(0, UserStore::recoveryCodeCount(['id' => 'u1']));
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
