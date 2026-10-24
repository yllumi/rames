<?php
declare(strict_types=1);

namespace Tests;

use app\library\Branding\BrandingLogo;
use app\library\Branding\BrandingManager;
use app\library\Storage\BrandingStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Support\StoreDbFiles;
use Webman\Config;

// Helper global dashboard hanya dimuat di runtime webman; definisikan konstanta
// + muat berkasnya seperti test controller lain.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('brand_name')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Test helper view rebranding (`brand_name()`, `brand_logo_url()`,
 * `brand_logo_is_custom()`).
 *
 * Store & direktori logo diarahkan ke temp lewat config `deploy.sqlite_file` /
 * `deploy.branding_dir` (registry Config dipulihkan di tearDown). Yang
 * dibuktikan: nilai default saat store kosong, nilai kustom setelah brand
 * disimpan (tanpa cache lintas-panggilan), dan fallback default ketika store
 * gagal dibaca (halaman login tidak boleh rusak).
 */
class BrandingHelperTest extends TestCase
{
    /** PNG 1×1 (RGB). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGPgEpEDAABoAD1UCKP3AAAAAElFTkSuQmCC';

    private string $tmp;
    private string $file;
    private string $dir;

    /** @var array<string,mixed> */
    private array $configState = [];

    /** @var array<int,string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/rames-branding-helper-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';
        $this->dir = $this->tmp . '/branding';

        $this->configState = $this->snapshotConfigState();
        $this->writeConfig($this->file);
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
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
        foreach (['/config/deploy.php', '/config/app.php'] as $relative) {
            if (is_file($this->tmp . $relative)) {
                @unlink($this->tmp . $relative);
            }
        }
        @rmdir($this->tmp . '/config');
        @rmdir($this->tmp);
    }

    public function testHelpersReturnDefaultsWhenStoreIsEmpty(): void
    {
        self::assertSame('Rames', brand_name());
        self::assertSame('/logo-small.png', brand_logo_url());
        self::assertFalse(brand_logo_is_custom());
    }

    /**
     * Nilai kustom terbaca segera (tanpa cache statik lintas-panggilan): worker
     * Webman persistent tidak boleh menyajikan brand basi.
     */
    public function testHelpersFollowStoredBrand(): void
    {
        self::assertSame('Rames', brand_name());

        $manager = new BrandingManager(new BrandingStore($this->file), new BrandingLogo($this->dir));
        $state = $manager->update('Acme', $this->uploadPng());

        self::assertSame('Acme', brand_name());
        self::assertSame($state['logo_url'], brand_logo_url());
        self::assertStringStartsWith('/branding/logo?v=', brand_logo_url());
        self::assertTrue(brand_logo_is_custom());
    }

    /**
     * Store gagal dibaca ⇒ helper tidak melempar; halaman login/UI tetap tampil
     * dengan brand default.
     */
    public function testHelpersFallBackWhenStoreIsUnreadable(): void
    {
        $this->writeConfig('/dev/null/rames-bukan-dir.sqlite');

        self::assertSame('Rames', brand_name());
        self::assertSame('/logo-small.png', brand_logo_url());
        self::assertFalse(brand_logo_is_custom());
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function writeConfig(string $sqliteFile): void
    {
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $sqliteFile,
                'branding_dir' => $this->dir,
            ], true) . ';' . PHP_EOL
        );
        Config::clear();
        Config::load($this->tmp . '/config');
    }

    /** @return array{tmp:string,mime:string,size:int,error:int,name:string} */
    private function uploadPng(): array
    {
        $bytes = (string) base64_decode(self::PNG, true);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'rames-branding-helper-upload-');
        file_put_contents($tmp, $bytes);
        $this->tempFiles[] = $tmp;

        return [
            'tmp' => $tmp,
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'error' => UPLOAD_ERR_OK,
            'name' => 'logo.png',
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }
}
