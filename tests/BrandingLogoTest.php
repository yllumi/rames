<?php
declare(strict_types=1);

namespace Tests;

use app\library\Branding\BrandingLogo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see BrandingLogo} — validasi & penyimpanan berkas logo kustom.
 *
 * Fixture gambar memakai byte base64 tetap (bukan `ext-gd`) karena container
 * uji tidak menjamin GD tersedia. Semua berkas ditulis ke direktori temp.
 */
class BrandingLogoTest extends TestCase
{
    /** PNG 1×1 (RGB). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGPgEpEDAABoAD1UCKP3AAAAAElFTkSuQmCC';

    /** JPEG 1×1 (RGB). */
    private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDxGiiitjI//9k=';

    /** WebP 1×1 (RGB). */
    private const WEBP = 'UklGRi4AAABXRUJQVlA4ICIAAABwAQCdASoBAAEAAUAmJZQCdAFAAAD+/DeBV/fU6D4r4AAA';

    private string $dir;

    /** @var array<int,string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-branding-logo-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
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

    public function testEmptyState(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->assertSame($this->dir, $logo->dir());
        $this->assertNull($logo->path());
        $this->assertNull($logo->mime());
        $this->assertSame(0, $logo->size());
        $this->assertNull($logo->etag());
    }

    public function testSavesPng(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->assertSame('logo.png', $logo->save($this->upload($this->png(), 'image/png')));

        $path = $logo->path();
        $this->assertNotNull($path);
        $this->assertSame($this->dir . '/logo.png', $path);
        $this->assertFileExists($path);
        $this->assertSame('image/png', $logo->mime());
        $this->assertSame(strlen($this->png()), $logo->size());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}\.png$/', (string) $logo->etag());
        $this->assertSame(0644, fileperms($path) & 0777);
    }

    public function testSavesJpegAndWebp(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->assertSame('logo.jpg', $logo->save($this->upload($this->jpeg(), 'image/jpeg')));
        $this->assertSame('image/jpeg', $logo->mime());

        $logo->delete();
        $this->assertSame('logo.webp', $logo->save($this->upload($this->webp(), 'image/webp')));
        $this->assertSame('image/webp', $logo->mime());
    }

    public function testReplacesOldVariant(): void
    {
        $logo = new BrandingLogo($this->dir);

        $logo->save($this->upload($this->png(), 'image/png'));
        $logo->save($this->upload($this->webp(), 'image/webp'));

        $this->assertSame(['logo.webp'], $this->entries());
        $this->assertFileDoesNotExist($this->dir . '/logo.png');
        $this->assertSame('image/webp', $logo->mime());

        $logo->save($this->upload($this->jpeg(), 'image/jpeg'));
        $this->assertSame(['logo.jpg'], $this->entries());
        $this->assertSame('image/jpeg', $logo->mime());
    }

    public function testDeleteRemovesStoredLogo(): void
    {
        $logo = new BrandingLogo($this->dir);
        $logo->save($this->upload($this->png(), 'image/png'));

        $logo->delete();

        $this->assertNull($logo->path());
        $this->assertNull($logo->etag());
        $this->assertSame([], $this->entries());

        // Idempoten.
        $logo->delete();
        $this->assertSame([], $this->entries());
    }

    public function testRejectsTooLargeFile(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Logo maksimal 512 KB.');
        $logo->save($this->upload(str_repeat('a', 600 * 1024), 'image/png'));
    }

    public function testRejectsSvg(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Format logo harus PNG, JPEG, atau WebP.');
        $logo->save($this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'));
    }

    public function testRejectsFakePngContent(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Isi berkas bukan gambar yang valid.');
        $logo->save($this->upload('<?php echo "bukan gambar";', 'image/png'));
    }

    public function testRejectsMimeMismatch(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jenis berkas tidak cocok dengan isinya.');
        $logo->save($this->upload($this->png(), 'image/jpeg'));
    }

    public function testRejectsMissingFile(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tidak ada berkas yang diunggah.');
        $logo->save($this->upload($this->png(), 'image/png', ['error' => UPLOAD_ERR_NO_FILE]));
    }

    public function testRejectsOtherUploadError(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $logo->save($this->upload($this->png(), 'image/png', ['error' => UPLOAD_ERR_PARTIAL]));
    }

    public function testRejectsNonexistentTmpPath(): void
    {
        $logo = new BrandingLogo($this->dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Berkas unggahan tidak ditemukan.');
        $logo->save([
            'tmp' => '/tidak/ada/berkas.png',
            'mime' => 'image/png',
            'size' => 10,
            'error' => UPLOAD_ERR_OK,
            'name' => 'logo.png',
        ]);
    }

    public function testValidateHasNoSideEffectsAndStoreWritesFile(): void
    {
        $logo = new BrandingLogo($this->dir);
        $upload = $this->upload($this->png(), 'image/png');

        $this->assertSame('logo.png', $logo->validate($upload));

        // validate() murni validasi: tidak membuat direktori/berkas apa pun.
        $this->assertDirectoryDoesNotExist($this->dir);
        $this->assertSame([], $this->entries());
        $this->assertNull($logo->path());

        $logo->store($upload, 'logo.png');

        $this->assertSame(['logo.png'], $this->entries());
        $this->assertFileExists($this->dir . '/logo.png');
        $this->assertSame('image/png', $logo->mime());
        $this->assertSame(0644, fileperms($this->dir . '/logo.png') & 0777);
    }

    public function testValidateRejectsWithoutTouchingDirectory(): void
    {
        $logo = new BrandingLogo($this->dir);
        $logo->save($this->upload($this->png(), 'image/png'));
        $before = $this->entries();

        try {
            $logo->validate($this->upload('<?php echo "bukan gambar";', 'image/png'));
            $this->fail('Isi non-gambar seharusnya ditolak.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame($before, $this->entries());
        $this->assertSame('image/png', $logo->mime());
    }

    public function testStoreRemovesOtherVariant(): void
    {
        $logo = new BrandingLogo($this->dir);
        $logo->save($this->upload($this->png(), 'image/png'));

        $webp = $this->upload($this->webp(), 'image/webp');
        $name = $logo->validate($webp);
        $this->assertSame(['logo.png'], $this->entries());

        $logo->store($webp, $name);

        $this->assertSame(['logo.webp'], $this->entries());
        $this->assertSame('image/webp', $logo->mime());
    }

    public function testDefaultDirIsNotEmpty(): void
    {
        $this->assertNotSame('', BrandingLogo::defaultDir());
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    private function jpeg(): string
    {
        return (string) base64_decode(self::JPEG, true);
    }

    private function webp(): string
    {
        return (string) base64_decode(self::WEBP, true);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{tmp:string,mime:string,size:int,error:int,name:string}
     */
    private function upload(string $bytes, string $mime, array $overrides = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rames-branding-upload-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $bytes);
        $this->tempFiles[] = $tmp;

        return array_merge([
            'tmp' => $tmp,
            'mime' => $mime,
            'size' => strlen($bytes),
            'error' => UPLOAD_ERR_OK,
            'name' => 'nama asli (diabaikan).png',
        ], $overrides);
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
