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
