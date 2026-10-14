<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Keunikan **nama app per server** (multi-server F1) + satu sumber normalisasi
 * id server (`AppStore::serverIdOf()`).
 *
 * Perilaku yang dijaga:
 *  - nama sama di dua server berbeda **boleh**;
 *  - nama sama di server yang sama **ditolak**, pesannya menyebut server;
 *  - `serverIdOf()` = `server_id` non-kosong, selain itu server lokal;
 *  - `findByName()` lama tetap mencari lintas server (pemakai lama tidak rusak).
 *
 * Semua di path temp (`sys_get_temp_dir()`) — `database/rames.sqlite` nyata
 * tidak pernah disentuh.
 */
class AppStoreServerScopeTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->file = sys_get_temp_dir() . '/rames-appstore-scope-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->file);
    }

    // ==================================================================
    // serverIdOf() — satu sumber normalisasi
    // ==================================================================

    public function testServerIdOfKosongAtauAbsenBerartiServerLokal(): void
    {
        $this->assertSame(ServerStore::LOCAL_ID, AppStore::serverIdOf([]));
        $this->assertSame(ServerStore::LOCAL_ID, AppStore::serverIdOf(['server_id' => '']));
        $this->assertSame(ServerStore::LOCAL_ID, AppStore::serverIdOf(['server_id' => '   ']));
        $this->assertSame(ServerStore::LOCAL_ID, AppStore::serverIdOf(['server_id' => null]));
    }

    public function testServerIdOfMengembalikanIdTerisiApaAdanya(): void
    {
        $this->assertSame('vps-1', AppStore::serverIdOf(['server_id' => 'vps-1']));
        $this->assertSame('vps-1', AppStore::serverIdOf(['server_id' => ' vps-1 ']));
        $this->assertSame('local', AppStore::serverIdOf(['server_id' => 'local']));
    }

    public function testCreateMengisiServerIdLokalBilaTidakDiberi(): void
    {
        $store = new AppStore($this->file);
        $app = $store->create(['name' => 'solo', 'status' => 'running']);

        $this->assertSame(ServerStore::LOCAL_ID, $app['server_id']);
        $this->assertSame(ServerStore::LOCAL_ID, AppStore::serverIdOf($store->find($app['id'])));
    }

    // ==================================================================
    // Keunikan nama per server
    // ==================================================================

    public function testNamaSamaDiDuaServerBerbedaBoleh(): void
    {
        $store = new AppStore($this->file);

        $local = $store->create(['name' => 'dup', 'server_id' => 'local', 'status' => 'running']);
        $remote = $store->create(['name' => 'dup', 'server_id' => 'vps-1', 'status' => 'running']);

        $this->assertNotSame($local['id'], $remote['id']);
        $this->assertCount(2, $store->all());

        $this->assertSame($local['id'], $store->findByNameOnServer('dup', 'local')['id']);
        $this->assertSame($remote['id'], $store->findByNameOnServer('dup', 'vps-1')['id']);
        $this->assertTrue($store->nameExistsOnServer('dup', 'vps-1'));
        $this->assertFalse($store->nameExistsOnServer('dup', 'vps-2'));
    }

    public function testNamaSamaDiServerSamaDitolakDanPesanMenyebutServer(): void
    {
        $store = new AppStore($this->file);
        $store->create(['name' => 'dup', 'server_id' => 'vps-1', 'status' => 'running']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama app "dup" sudah dipakai di server "vps-1".');
        $store->create(['name' => 'dup', 'server_id' => 'vps-1', 'status' => 'running']);
    }

    public function testNamaSamaDiServerLokalDitolakDenganPesanServerLokal(): void
    {
        $store = new AppStore($this->file);
        $store->create(['name' => 'dup', 'status' => 'running']); // default server lokal

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama app "dup" sudah dipakai di server "local".');
        $store->create(['name' => 'dup', 'status' => 'running']);
    }

    public function testFindByNameOnServerMengabaikanServerIdKosongSebagaiLokal(): void
    {
        $store = new AppStore($this->file);
        $app = $store->create(['name' => 'dup', 'server_id' => 'vps-1', 'status' => 'running']);

        $this->assertNull($store->findByNameOnServer('dup', ''));
        $this->assertSame($app['id'], $store->findByNameOnServer('dup', 'vps-1')['id']);
    }

    // ==================================================================
    // Regresi perilaku lama
    // ==================================================================

    public function testFindByNameLamaTetapLintasServer(): void
    {
        $store = new AppStore($this->file);
        $first = $store->create(['name' => 'dup', 'server_id' => 'local', 'status' => 'running']);
        $store->create(['name' => 'dup', 'server_id' => 'vps-1', 'status' => 'running']);

        $this->assertSame($first['id'], $store->findByName('dup')['id']);
        $this->assertTrue($store->nameExists('dup'), 'nameExists lama tetap global (pemakai lama)');
    }

    public function testIdServerDenganSpasiDinormalisasiSaatCekDuplikat(): void
    {
        $store = new AppStore($this->file);
        $store->create(['name' => 'dup', 'server_id' => ' vps-1 ', 'status' => 'running']);

        $this->assertSame('vps-1', AppStore::serverIdOf($store->findByName('dup')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama app "dup" sudah dipakai di server "vps-1".');
        $store->create(['name' => 'dup', 'server_id' => 'vps-1', 'status' => 'running']);
    }
}
