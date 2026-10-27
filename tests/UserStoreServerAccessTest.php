<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\UserStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SqliteFixture;

/**
 * Test grant pemilihan server pada dokumen user (`server_select` +
 * `server_ids`) — satu tulisan atomik `UserStore::setServerAccess()` dan
 * normalisasi tipe tanpa migrasi.
 *
 * Semua data di berkas SQLite temp; tidak menyentuh `database/` runtime.
 */
class UserStoreServerAccessTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->db = sys_get_temp_dir() . '/rames-user-server-' . bin2hex(random_bytes(5)) . '.sqlite';
        SqliteFixture::users($this->db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        foreach (glob($this->db . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function store(): UserStore
    {
        return new UserStore($this->db);
    }

    public function testDefaultsAreFalseAndEmptyArray(): void
    {
        $user = $this->store()->findPublicById('u2');

        $this->assertIsArray($user);
        $this->assertArrayHasKey('server_select', $user);
        $this->assertArrayHasKey('server_ids', $user);
        $this->assertFalse($user['server_select']);
        $this->assertSame([], $user['server_ids']);
    }

    public function testSetServerAccessPersistsNormalizedValuesAtomically(): void
    {
        $store = $this->store();
        $store->setServerAccess('u2', true, [' s2 ', 's2', '', 7, null, ['x']]);

        $user = $store->findPublicById('u2');
        $this->assertTrue($user['server_select']);
        $this->assertSame(['s2', '7'], $user['server_ids'], 'unik, trim, buang kosong/non-skalar');

        // Allowlist kosong tetap disimpan sebagai array kosong (arti: semua server).
        $store->setServerAccess('u2', true, [' ']);
        $user = $store->findPublicById('u2');
        $this->assertTrue($user['server_select']);
        $this->assertSame([], $user['server_ids']);

        // Grant dimatikan.
        $store->setServerAccess('u2', false, []);
        $user = $store->findPublicById('u2');
        $this->assertFalse($user['server_select']);
        $this->assertSame([], $user['server_ids']);
    }

    public function testUnknownUserIsRejectedWithoutSideEffects(): void
    {
        $this->expectException(RuntimeException::class);

        $this->store()->setServerAccess('ghost', true, ['s2']);
    }

    /**
     * Normalisasi tipe baca: dokumen lama bisa berisi string/int/bool.
     */
    public function testReadNormalizationHandlesMixedTypes(): void
    {
        $cases = [
            [true, true],
            [1, true],
            ['1', true],
            ['true', true],
            ['on', true],
            [' ON ', true],
            [false, false],
            [0, false],
            ['0', false],
            ['off', false],
            ['', false],
            [null, false],
            ['sampah', false],
        ];

        foreach ($cases as [$raw, $expected]) {
            SqliteFixture::users($this->db, [
                ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'server_select' => $raw],
            ]);
            $this->assertSame(
                $expected,
                $this->store()->findPublicById('u2')['server_select'],
                'flag ' . var_export($raw, true)
            );
        }
    }

    public function testServerIdsNormalizationDropsGarbage(): void
    {
        SqliteFixture::users($this->db, [
            [
                'id' => 'u2',
                'username' => 'budi',
                'password_hash' => 'x',
                'role' => 'member',
                'server_select' => true,
                'server_ids' => [' s2 ', 's2', '', 0, false, null, ['x']],
            ],
        ]);

        $this->assertSame(['s2', '0'], $this->store()->findPublicById('u2')['server_ids']);
    }
}
