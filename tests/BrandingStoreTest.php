<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\BrandingStore;
use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteDatabase;
use app\library\Storage\SqliteStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

/**
 * Test {@see BrandingStore} — fondasi rebranding dashboard (lapisan storage).
 *
 * Semua memakai berkas .sqlite di `sys_get_temp_dir()`; basis data runtime
 * tidak pernah disentuh.
 */
class BrandingStoreTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->file = sys_get_temp_dir() . '/rames-branding-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->file);
    }

    public function testGetReturnsDefaults(): void
    {
        $store = new BrandingStore($this->file);

        $this->assertSame($this->file, $store->path());
        $this->assertSame(
            ['name' => 'Rames', 'logo' => null, 'updated_at' => null],
            $store->get()
        );
    }

    public function testSaveRoundtripAndReset(): void
    {
        $store = new BrandingStore($this->file);

        $saved = $store->save('  Acme Cloud  ', 'logo.webp');
        $this->assertSame('Acme Cloud', $saved['name']);
        $this->assertSame('logo.webp', $saved['logo']);
        $this->assertNotNull($saved['updated_at']);
        $this->assertSame($saved, $store->get());

        // Dokumen persisten lintas instance (berkas yang sama).
        $this->assertSame($saved, (new BrandingStore($this->file))->get());

        $reset = $store->reset();
        $this->assertSame(['name' => 'Rames', 'logo' => null, 'updated_at' => null], $reset);
        $this->assertSame($reset, $store->get());

        // Idempoten.
        $this->assertSame($reset, $store->reset());
    }

    public function testSaveStoresOnlyLogoBasename(): void
    {
        $store = new BrandingStore($this->file);

        $this->assertSame('logo.png', $store->save('Acme', '/etc/passwd/logo.png')['logo']);
        $this->assertSame('logo.jpg', $store->save('Acme', '../../logo.jpg')['logo']);
        $this->assertNull($store->save('Acme', 'evil.php')['logo']);
        $this->assertNull($store->save('Acme', null)['logo']);
    }

    public function testGetNormalizesCorruptDocument(): void
    {
        // Dokumen mentah di luar jalur save(): nama kosong, logo berekstensi
        // asing, updated_at kosong ⇒ seluruhnya kembali ke default.
        $raw = new SqliteStore('branding', SchemaMigrations::storeDefinitions()['branding'], $this->file);
        $raw->write([['id' => 'branding', 'name' => '   ', 'logo' => '/srv/evil/logo.svg', 'updated_at' => '  ']]);

        $store = new BrandingStore($this->file);
        $this->assertSame(
            ['name' => 'Rames', 'logo' => null, 'updated_at' => null],
            $store->get()
        );
    }

    public function testResetRemovesDocumentRow(): void
    {
        $store = new BrandingStore($this->file);
        $store->save('Acme', 'logo.png');
        $store->reset();

        $raw = new SqliteStore('branding', SchemaMigrations::storeDefinitions()['branding'], $this->file);
        $this->assertSame([], $raw->read());
    }
}
