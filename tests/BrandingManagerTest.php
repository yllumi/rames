<?php
declare(strict_types=1);

namespace Tests;

use app\library\Branding\BrandingLogo;
use app\library\Branding\BrandingManager;
use app\library\Storage\BrandingStore;
use app\library\Storage\SqliteDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

/**
 * Test {@see BrandingManager} — orkestrasi store SQLite + berkas logo.
 *
 * Fixture PNG memakai byte base64 tetap (tanpa `ext-gd`). Semua state berada
 * di direktori temp.
 */
class BrandingManagerTest extends TestCase
{
    /** PNG 1×1 (RGB). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGPgEpEDAABoAD1UCKP3AAAAAElFTkSuQmCC';

    /** WebP 1×1 (RGB) — varian berbeda agar kegagalan tulis terlihat jelas. */
    private const WEBP = 'UklGRi4AAABXRUJQVlA4ICIAAABwAQCdASoBAAEAAUAmJZQCdAFAAAD+/DeBV/fU6D4r4AAA';

    private string $file;
    private string $dir;
    private BrandingStore $store;
    private BrandingLogo $logo;
    private BrandingManager $manager;

    /** @var array<int,string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();

        $this->file = sys_get_temp_dir() . '/rames-branding-manager-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->dir = sys_get_temp_dir() . '/rames-branding-assets-' . bin2hex(random_bytes(4));

        $this->store = new BrandingStore($this->file);
        $this->logo = new BrandingLogo($this->dir);
        $this->manager = new BrandingManager($this->store, $this->logo);
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->file);

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];

        if (is_dir($this->dir)) {
            foreach ((array) scandir($this->dir) as $entry) {
                $path = $this->dir . '/' . $entry;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($this->dir);
        }
    }

    public function testCurrentDefaults(): void
    {
        $this->assertSame(
            [
                'name' => 'Rames',
                'logo_url' => '/logo-small.png',
                'logo_custom' => false,
                'updated_at' => null,
            ],
            $this->manager->current()
        );

        $this->assertSame('Rames', $this->manager->name());
        $this->assertSame('/logo-small.png', $this->manager->logoUrl());
    }

    public function testUpdateWithLogo(): void
    {
        $state = $this->manager->update('  Acme  ', $this->uploadPng());

        $this->assertSame('Acme', $state['name']);
        $this->assertTrue($state['logo_custom']);
        $this->assertStringStartsWith('/branding/logo?v=', $state['logo_url']);
        $this->assertStringEndsWith('.png', $state['logo_url']);
        $this->assertNotNull($state['updated_at']);

        $this->assertSame('logo.png', $this->store->get()['logo']);
        $this->assertFileExists($this->dir . '/logo.png');
        $this->assertSame($state, $this->manager->current());
        $this->assertSame($state['logo_url'], $this->manager->logoUrl());
        $this->assertSame('Acme', $this->manager->name());
    }

    public function testUpdateWithNameOnlyKeepsExistingLogo(): void
    {
        $first = $this->manager->update('Acme', $this->uploadPng());
        $etagBefore = $this->logo->etag();
        $this->assertNotNull($etagBefore);

        $state = $this->manager->update('Beta', null);

        $this->assertSame('Beta', $state['name']);
        $this->assertTrue($state['logo_custom']);
        $this->assertSame($first['logo_url'], $state['logo_url']);
        $this->assertStringContainsString((string) $etagBefore, $state['logo_url']);
        $this->assertFileExists((string) $this->logo->path());
        $this->assertSame('logo.png', $this->store->get()['logo']);
    }

    public function testRejectsInvalidNamesWithoutTouchingStore(): void
    {
        $invalid = [
            '',
            '    ',
            str_repeat('a', BrandingManager::MAX_NAME + 1),
            "Acme\nCorp",
        ];

        foreach ($invalid as $name) {
            try {
                $this->manager->update($name, null);
                $this->fail('Nama brand seharusnya ditolak: ' . json_encode($name));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(
            ['name' => 'Rames', 'logo' => null, 'updated_at' => null],
            $this->store->get()
        );
        $this->assertNull($this->logo->path());
    }

    public function testAcceptsMaximumLengthName(): void
    {
        $name = str_repeat('a', BrandingManager::MAX_NAME);

        $state = $this->manager->update($name, null);

        $this->assertSame($name, $state['name']);
        $this->assertSame($name, $this->manager->name());
    }

    public function testFailedUploadDoesNotTouchStore(): void
    {
        $this->manager->update('Acme', $this->uploadPng());
        $before = $this->store->get();
        $etagBefore = $this->logo->etag();

        try {
            $this->manager->update('Beta', $this->upload('bukan gambar', 'image/png'));
            $this->fail('Unggahan palsu seharusnya ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame($before, $this->store->get());
        $this->assertSame('Acme', $this->manager->name());
        $this->assertSame('logo.png', $this->store->get()['logo']);
        $this->assertSame($etagBefore, $this->logo->etag());
        $this->assertFileExists($this->dir . '/logo.png');
        $this->assertSame(['logo.png'], $this->entries());
    }

    /**
     * Metadata disimpan **sebelum** berkas ditulis: bila store gagal, tidak
     * boleh ada berkas logo baru yang tertinggal dan berkas lama tetap utuh.
     *
     * `BrandingStore` bersifat `final` (tidak bisa di-double), jadi kegagalan
     * tulis disimulasikan lewat trigger SQLite yang meng-abort INSERT/UPSERT.
     */
    public function testStoreFailureLeavesNoNewLogoFile(): void
    {
        $this->manager->update('Acme', $this->uploadPng());
        $before = $this->store->get();
        $etagBefore = $this->logo->etag();

        $pdo = new \PDO('sqlite:' . $this->file);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TRIGGER branding_block_write BEFORE INSERT ON branding_settings '
            . "BEGIN SELECT RAISE(ABORT, 'blocked'); END"
        );

        try {
            $this->manager->update('Beta', $this->upload($this->webp(), 'image/webp'));
            $this->fail('Kegagalan store seharusnya diteruskan.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('blocked', $e->getMessage());
        }

        // Tidak ada `logo.webp` baru; berkas lama tidak terhapus.
        $this->assertSame(['logo.png'], $this->entries());
        $this->assertFileExists($this->dir . '/logo.png');
        $this->assertSame($before, $this->store->get());
        $this->assertSame($etagBefore, $this->logo->etag());
        $this->assertSame('Acme', $this->manager->name());
    }

    public function testResetRemovesLogoAndRestoresDefaults(): void
    {
        $this->manager->update('Acme', $this->uploadPng());
        $this->assertFileExists($this->dir . '/logo.png');

        $state = $this->manager->reset();

        $this->assertSame(
            [
                'name' => 'Rames',
                'logo_url' => '/logo-small.png',
                'logo_custom' => false,
                'updated_at' => null,
            ],
            $state
        );
        $this->assertNull($this->logo->path());
        $this->assertSame(
            ['name' => 'Rames', 'logo' => null, 'updated_at' => null],
            $this->store->get()
        );

        $entries = is_dir($this->dir)
            ? array_values(array_diff((array) scandir($this->dir), ['.', '..']))
            : [];
        $this->assertSame([], $entries);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{tmp:string,mime:string,size:int,error:int,name:string}
     */
    private function upload(string $bytes, string $mime, array $overrides = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rames-branding-manager-upload-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $bytes);
        $this->tempFiles[] = $tmp;

        return array_merge([
            'tmp' => $tmp,
            'mime' => $mime,
            'size' => strlen($bytes),
            'error' => UPLOAD_ERR_OK,
            'name' => 'logo.png',
        ], $overrides);
    }

    /**
     * @return array{tmp:string,mime:string,size:int,error:int,name:string}
     */
    private function uploadPng(): array
    {
        $bytes = (string) base64_decode(self::PNG, true);
        return $this->upload($bytes, 'image/png');
    }

    /**
     * Byte WebP 1×1 untuk unggahan.
     */
    private function webp(): string
    {
        return (string) base64_decode(self::WEBP, true);
    }

    /**
     * @return array<int,string>
     */
    private function entries(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        return array_values(array_diff((array) scandir($this->dir), ['.', '..']));
    }
}
