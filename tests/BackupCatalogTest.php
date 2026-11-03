<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Test `BackupCatalog` — cache baris ringkasan (path temp, tanpa runtime nyata).
 *
 * Menjamin round-trip tulis→baca, `cached_at` terisi, dan daftar-putih kunci
 * (kunci asing dibuang sebelum menyentuh disk).
 */
class BackupCatalogTest extends TestCase
{
    private string $tmp;
    private string $path;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backupcat_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->path = $this->tmp . '/catalog.json';
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
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

    public function testMissingFileReadsEmpty(): void
    {
        $catalog = new BackupCatalog($this->path);
        $read = $catalog->read();

        $this->assertSame([], $read['volumes']);
        $this->assertNull($read['cached_at']);
    }

    public function testWriteThenReadFillsCachedAt(): void
    {
        $catalog = new BackupCatalog($this->path);
        $catalog->write([[
            'name' => 'tonidata_data',
            'project' => 'tonidata',
            'app_id' => 'app1',
            'app_name' => 'tonidata',
            'orphaned' => false,
            'strategy' => 'snapshot',
            'container_state' => 'exited',
            'last_run_at' => '2026-10-05T02:00:00+00:00',
            'last_ok' => true,
            'last_message' => null,
            'snapshots' => 3,
        ]]);

        $read = $catalog->read();
        $this->assertNotNull($read['cached_at']);
        $this->assertCount(1, $read['volumes']);
        $this->assertSame('tonidata_data', $read['volumes'][0]['name']);
        $this->assertSame(3, $read['volumes'][0]['snapshots']);
    }

    public function testLocalWritesKeepCachedAgentRowsButReplaceLocalRows(): void
    {
        $catalog = new BackupCatalog($this->path);
        $catalog->write([
            ['name' => 'lokal_data', 'project' => 'demo', 'server_id' => 'local', 'snapshots' => 1],
            ['name' => 'agent_data', 'project' => 'demo-remote', 'server_id' => 'srv-a', 'snapshots' => 4],
        ]);

        // Penulisan jalur lokal (tanpa baris agent) tidak boleh menghapus baris agent.
        $catalog->write([
            ['name' => 'lokal_data', 'project' => 'demo', 'server_id' => 'local', 'snapshots' => 2],
            ['name' => 'lokal_baru', 'project' => 'baru', 'server_id' => 'local', 'snapshots' => 0],
        ], true);

        $rows = $catalog->read()['volumes'];
        $names = array_column($rows, 'name');
        sort($names);
        $this->assertSame(['agent_data', 'lokal_baru', 'lokal_data'], $names);
        $byName = array_column($rows, null, 'name');
        $this->assertSame(2, $byName['lokal_data']['snapshots'], 'baris lokal diganti data live');
        $this->assertSame(4, $byName['agent_data']['snapshots']);
    }

    public function testWriteAgentRowsReplacesOnlyThatServerAndSealsSyncTime(): void
    {
        $catalog = new BackupCatalog($this->path);
        $catalog->write([
            ['name' => 'lokal_data', 'project' => 'demo', 'server_id' => 'local'],
            ['name' => 'a_data', 'project' => 'app-a', 'server_id' => 'srv-a', 'snapshots' => 1],
            ['name' => 'b_data', 'project' => 'app-b', 'server_id' => 'srv-b', 'snapshots' => 2],
        ]);

        $catalog->writeAgentRows('srv-a', [
            ['name' => 'a_data', 'project' => 'app-a', 'snapshots' => 3],
            ['name' => 'a_cache', 'project' => 'app-a', 'snapshots' => 0],
        ], 1700000000);

        $rows = $catalog->read()['volumes'];
        $this->assertSame(['lokal_data', 'b_data', 'a_data', 'a_cache'], array_column($rows, 'name'));
        $this->assertSame('srv-a', $rows[2]['server_id'], 'server_id dipaksa dari argumen');
        $this->assertSame(['srv-a' => 1700000000], $catalog->agentSyncTimes());

        // Server tanpa volume: baris lama server itu hilang, server lain tetap.
        $catalog->writeAgentRows('srv-a', [], 1700000100);
        $this->assertSame(['lokal_data', 'b_data'], array_column($catalog->read()['volumes'], 'name'));
    }

    public function testTouchAgentSyncDoesNotChangeRows(): void
    {
        $catalog = new BackupCatalog($this->path);
        $catalog->write([['name' => 'b_data', 'project' => 'app-b', 'server_id' => 'srv-b']]);

        $catalog->touchAgentSync('srv-b', 1700000200);

        $this->assertSame(['srv-b' => 1700000200], $catalog->agentSyncTimes());
        $this->assertSame(['b_data'], array_column($catalog->read()['volumes'], 'name'));
    }

    public function testUnknownKeysAreDropped(): void
    {
        $catalog = new BackupCatalog($this->path);
        $catalog->write([[
            'name' => 'tonidata_data',
            'password' => 'harus-dibuang',
            'AWS_SECRET_ACCESS_KEY' => 'harus-dibuang',
            'nested' => ['x' => 'harus-dibuang'],
        ]]);

        $read = $catalog->read();
        $this->assertArrayNotHasKey('password', $read['volumes'][0]);
        $this->assertArrayNotHasKey('AWS_SECRET_ACCESS_KEY', $read['volumes'][0]);
        $this->assertArrayNotHasKey('nested', $read['volumes'][0]);

        $raw = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('harus-dibuang', $raw, 'kunci asing tidak boleh sampai ke disk');
    }
}
