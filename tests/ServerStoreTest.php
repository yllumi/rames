<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Test {@see ServerStore} (registry server multi-server F0) + default
 * `server_id` di {@see AppStore::create()}.
 *
 * Semua di path temp (`sys_get_temp_dir()`) — `database/rames.sqlite` nyata
 * tidak pernah disentuh.
 */
class ServerStoreTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->file = sys_get_temp_dir() . '/rames-servers-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->file);
    }

    public function testPathAndSeedLocalServer(): void
    {
        $store = new ServerStore($this->file);

        $this->assertSame($this->file, $store->path());
        $this->assertFileExists($this->file);

        $local = $store->local();
        $this->assertSame(ServerStore::LOCAL_ID, $local['id']);
        $this->assertSame('local', $local['name']);
        $this->assertSame('local', $local['kind']);
        $this->assertTrue($local['enabled']);
        $this->assertSame('', $local['domain']);

        // Hanya baris seed; seed dibuat sekali oleh migrasi.
        $this->assertSame([ServerStore::LOCAL_ID], array_column($store->all(), 'id'));
    }

    public function testCreateFindUpdateDelete(): void
    {
        $store = new ServerStore($this->file);

        $server = $store->create(['name' => 'vps-1', 'base_url' => 'https://10.0.0.5:8443']);
        $this->assertNotEmpty($server['id']);
        $this->assertSame('agent', $server['kind']);
        $this->assertTrue($server['enabled']);
        $this->assertNotEmpty($server['created_at']);
        $this->assertSame($server['created_at'], $server['updated_at']);

        $found = $store->find($server['id']);
        $this->assertIsArray($found);
        $this->assertSame('vps-1', $found['name']);
        $this->assertSame('https://10.0.0.5:8443', $found['base_url']);
        $this->assertSame('vps-1', $store->findByName('vps-1')['name']);
        $this->assertTrue($store->nameExists('vps-1'));
        $this->assertFalse($store->nameExists('tidak-ada'));
        $this->assertNull($store->find('tidak-ada'));

        // enabled=false eksplisit dihormati.
        $disabled = $store->create(['name' => 'vps-2', 'enabled' => false]);
        $this->assertFalse($disabled['enabled']);
        $this->assertFalse($store->find($disabled['id'])['enabled']);

        $store->update($server['id'], function (array &$s): void {
            $s['enabled'] = false;
            $s['notes'] = 'catatan';
        });
        $updated = $store->find($server['id']);
        $this->assertFalse($updated['enabled']);
        $this->assertSame('catatan', $updated['notes']);
        $this->assertSame(3, count($store->all()), 'local + vps-1 + vps-2');

        // Persist antar-instance (baca dari store baru).
        $fresh = new ServerStore($this->file);
        $this->assertSame('catatan', $fresh->find($server['id'])['notes']);

        $store->delete($server['id']);
        $this->assertNull($store->find($server['id']));
        $this->assertSame(2, count($store->all()));

        // Hapus id yang tidak ada = no-op.
        $store->delete('tidak-ada');
        $this->assertSame(2, count($store->all()));
    }

    public function testDuplicateNameRejected(): void
    {
        $store = new ServerStore($this->file);
        $store->create(['name' => 'dup']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama server "dup" sudah dipakai.');
        $store->create(['name' => 'dup']);
    }

    public function testUpdateUnknownIdThrows(): void
    {
        $store = new ServerStore($this->file);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Server tidak ditemukan: nope');
        $store->update('nope', static function (array &$s): void {
            $s['name'] = 'x';
        });
    }

    public function testLocalCannotBeDeleted(): void
    {
        $store = new ServerStore($this->file);

        $this->expectException(RuntimeException::class);
        $store->delete(ServerStore::LOCAL_ID);
    }

    public function testLocalIdentityIsForcedBackOnUpdate(): void
    {
        $store = new ServerStore($this->file);

        $store->update(ServerStore::LOCAL_ID, function (array &$s): void {
            $s['id'] = 'dibajak';
            $s['kind'] = 'agent';
            $s['name'] = 'dashboard';
        });

        $local = $store->local();
        $this->assertSame('local', $local['id'], 'id server lokal tidak boleh berubah');
        $this->assertSame('local', $local['kind'], 'kind server lokal tidak boleh berubah');
        $this->assertSame('dashboard', $local['name']);
        $this->assertSame(1, count($store->all()), 'tidak ada baris liar akibat id yang dibajak');
    }

    public function testLocalSelfHealsWhenRowIsMissing(): void
    {
        $store = new ServerStore($this->file);

        // Hapus baris seed dari luar store (simulasi snapshot lama).
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DELETE FROM "servers_servers" WHERE "id" = \'local\'');
        $this->assertCount(0, $store->all());

        $local = $store->local();
        $this->assertSame(ServerStore::LOCAL_ID, $local['id']);
        $this->assertSame('local', $local['kind']);
        $this->assertTrue($local['enabled']);
        $this->assertSame('', $local['domain']);

        $all = $store->all();
        $this->assertCount(1, $all);
        $this->assertSame(ServerStore::LOCAL_ID, $all[0]['id'], 'seed dikembalikan di urutan pertama');
    }

    public function testAppStoreCreateDefaultsToLocalServer(): void
    {
        $apps = new AppStore($this->file);

        $app = $apps->create(['name' => 'myapp']);
        $this->assertSame(ServerStore::LOCAL_ID, $app['server_id']);
        $this->assertSame(ServerStore::LOCAL_ID, $apps->find($app['id'])['server_id']);

        $explicit = $apps->create(['name' => 'remote-app', 'server_id' => 'srv-2']);
        $this->assertSame('srv-2', $explicit['server_id']);
        $this->assertSame('srv-2', $apps->find($explicit['id'])['server_id']);
    }
}
