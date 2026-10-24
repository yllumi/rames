<?php
declare(strict_types=1);

namespace Tests;

use app\controller\NginxController;
use app\library\Auth\AppAccessDenied;
use app\library\Branding\BrandingLogo;
use app\library\Branding\BrandingManager;
use app\library\Storage\BrandingStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use support\Request;
use Tests\Support\StoreDbFiles;

/**
 * Test mediator aksi rebranding di halaman Config (`NginxController`).
 *
 * Tanpa HTTP nyata: admin & orkestrasi branding disuntik lewat seam
 * (`isAdmin()`, `branding()`, `uploadedLogo()`, `flash()`), sedangkan
 * {@see BrandingManager} dijalankan sungguhan dengan store SQLite + direktori
 * logo di temp. Yang dibuktikan:
 *
 *  - non-admin ⇒ `AppAccessDenied` (404) **sebelum** efek samping,
 *  - simpan valid ⇒ store berubah + flash sukses + redirect PRG,
 *  - nama kosong/kepanjangan/berbentuk array ⇒ flash error & store utuh,
 *  - unggahan tidak valid ⇒ brand lama dipertahankan,
 *  - reset ⇒ kembali `Rames` + logo bawaan,
 *  - panel branding memuat batas unggahan dan **tidak** membuat halaman 500
 *    ketika store gagal dibaca (fallback default).
 */
class NginxControllerBrandingTest extends TestCase
{
    /** PNG 1×1 (RGB) — fixture tanpa butuh `ext-gd`. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGPgEpEDAABoAD1UCKP3AAAAAElFTkSuQmCC';

    private string $tmp;
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

        $this->tmp = sys_get_temp_dir() . '/rames-nginx-branding-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0777, true);
        $this->file = $this->tmp . '/rames.sqlite';
        $this->dir = $this->tmp . '/branding';

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
        @rmdir($this->tmp);
    }

    // ------------------------------------------------------------------
    // (a) admin-only (404) sebelum efek samping
    // ------------------------------------------------------------------

    public function testMemberCannotSaveBranding(): void
    {
        $controller = $this->controller(false);

        try {
            $controller->saveBranding($this->post('/nginx/branding', ['name' => 'Evil']));
            self::fail('Non-admin seharusnya ditolak.');
        } catch (AppAccessDenied $e) {
            self::assertSame(404, $e->getCode());
            self::assertSame('nginx', $e->ability);
        }

        self::assertSame('Rames', $this->store->get()['name'], 'penolakan tidak boleh mengubah store');
        self::assertSame([], $controller->flashes);
    }

    public function testMemberCannotResetBranding(): void
    {
        $this->manager->update('Acme', null);
        $controller = $this->controller(false);

        $this->expectException(AppAccessDenied::class);
        try {
            $controller->resetBranding($this->post('/nginx/branding/reset', []));
        } finally {
            self::assertSame('Acme', $this->store->get()['name'], 'reset oleh non-admin tidak boleh berefek');
        }
    }

    // ------------------------------------------------------------------
    // (b) simpan valid
    // ------------------------------------------------------------------

    public function testAdminSavesNameAndRedirectsToBrandingTab(): void
    {
        $controller = $this->controller(true);

        $response = $controller->saveBranding($this->post('/nginx/branding', ['name' => '  Acme  ']));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/nginx?tab=branding', $response->getHeader('Location'));
        self::assertSame('Acme', $this->store->get()['name']);
        self::assertSame('Acme', $this->manager->current()['name']);
        self::assertSame([['type' => 'success', 'message' => 'Branding diperbarui.']], $controller->flashes);
    }

    public function testAdminSavesNameWithNewLogo(): void
    {
        $controller = $this->controller(true);
        $controller->upload = $this->uploadPng();

        $response = $controller->saveBranding($this->post('/nginx/branding', ['name' => 'Acme']));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('logo.png', $this->store->get()['logo']);
        self::assertFileExists($this->dir . '/logo.png');
        $current = $this->manager->current();
        self::assertTrue($current['logo_custom']);
        self::assertStringStartsWith('/branding/logo?v=', $current['logo_url']);
        self::assertSame('success', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // (c) input tidak valid — store tidak berubah
    // ------------------------------------------------------------------

    public function testBlankNameIsRejected(): void
    {
        $controller = $this->controller(true);

        $response = $controller->saveBranding($this->post('/nginx/branding', ['name' => '   ']));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/nginx?tab=branding', $response->getHeader('Location'));
        self::assertSame('Rames', $this->store->get()['name']);
        self::assertSame('error', $controller->flashes[0]['type']);
        self::assertStringContainsString('wajib diisi', $controller->flashes[0]['message']);
    }

    public function testTooLongNameIsRejected(): void
    {
        $controller = $this->controller(true);

        $controller->saveBranding($this->post('/nginx/branding', ['name' => str_repeat('a', BrandingManager::MAX_NAME + 1)]));

        self::assertSame('Rames', $this->store->get()['name']);
        self::assertSame('error', $controller->flashes[0]['type']);
        self::assertStringContainsString((string) BrandingManager::MAX_NAME, $controller->flashes[0]['message']);
    }

    /**
     * `name[]=Acme` bukan string: ditolak sebagai input tidak valid (bukan 500),
     * dan unggahan tidak pernah diproses.
     */
    public function testArrayNameIsRejectedAsInvalid(): void
    {
        $controller = $this->controller(true);
        $controller->upload = $this->uploadPng();

        $response = $controller->saveBranding($this->post('/nginx/branding', ['name' => ['Acme']]));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/nginx?tab=branding', $response->getHeader('Location'));
        self::assertSame('Rames', $this->store->get()['name']);
        self::assertSame(0, $controller->uploadCalls, 'input tidak valid tidak boleh memproses berkas');
        self::assertSame('error', $controller->flashes[0]['type']);
        self::assertSame('Nama brand tidak valid.', $controller->flashes[0]['message']);
    }

    /**
     * Unggahan ditolak `BrandingLogo` (mis. GIF) ⇒ nama lama dipertahankan dan
     * pesan galat asli diteruskan ke flash.
     */
    public function testInvalidUploadKeepsPreviousBrand(): void
    {
        $this->manager->update('Acme', null);
        $controller = $this->controller(true);
        $controller->upload = $this->upload((string) base64_decode(self::PNG, true), 'image/gif');

        $controller->saveBranding($this->post('/nginx/branding', ['name' => 'Baru']));

        self::assertSame('Acme', $this->store->get()['name'], 'gagal logo tidak boleh menyimpan nama baru');
        self::assertSame('error', $controller->flashes[0]['type']);
    }

    // ------------------------------------------------------------------
    // (d) reset
    // ------------------------------------------------------------------

    public function testResetRestoresDefaults(): void
    {
        $this->manager->update('Acme', $this->uploadPng());
        self::assertFileExists($this->dir . '/logo.png');

        $controller = $this->controller(true);
        $response = $controller->resetBranding($this->post('/nginx/branding/reset', []));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/nginx?tab=branding', $response->getHeader('Location'));
        self::assertSame(
            [
                'name' => 'Rames',
                'logo_url' => '/logo-small.png',
                'logo_custom' => false,
                'updated_at' => null,
            ],
            $this->manager->current()
        );
        self::assertFileDoesNotExist($this->dir . '/logo.png');
        self::assertSame([['type' => 'success', 'message' => 'Branding dikembalikan ke default (Rames).']], $controller->flashes);
    }

    // ------------------------------------------------------------------
    // (d-bis) seam unggahan produksi (tanpa override `uploadedLogo()`)
    // ------------------------------------------------------------------

    /**
     * Membuktikan seam default benar-benar membaca `$request->file('logo')`
     * (multipart) dan menyerahkan tmp/mime/size/error ke manager. Nama berkas
     * asli (`kotor.png`) tidak pernah dipakai sebagai target penyimpanan.
     */
    public function testDefaultUploadedLogoSeamHandlesMultipartUpload(): void
    {
        $controller = new DefaultSeamBrandingController($this->manager);
        $png = (string) base64_decode(self::PNG, true);

        $response = $controller->saveBranding($this->multipart('/nginx/branding', 'Acme', 'kotor.png', $png));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/nginx?tab=branding', $response->getHeader('Location'));
        self::assertSame('Acme', $this->store->get()['name']);
        self::assertSame('logo.png', $this->store->get()['logo']);
        self::assertFileExists($this->dir . '/logo.png');
        self::assertSame($png, (string) file_get_contents($this->dir . '/logo.png'));
        self::assertSame([['type' => 'success', 'message' => 'Branding diperbarui.']], $controller->flashes);
    }

    // ------------------------------------------------------------------
    // (e) payload panel tab Branding
    // ------------------------------------------------------------------
    public function testBrandingPanelExposesDefaultsAndUploadLimits(): void
    {
        $panel = $this->brandingPanel($this->controller(true));

        self::assertSame('Rames', $panel['name']);
        self::assertSame('/logo-small.png', $panel['logo_url']);
        self::assertFalse($panel['logo_custom']);
        self::assertSame(BrandingManager::MAX_NAME, $panel['max_name']);
        self::assertSame(524288, $panel['max_bytes']);
        self::assertSame('PNG, JPEG, WebP', $panel['allowed']);
    }

    /**
     * Store gagal dibaca ⇒ panel jatuh ke default; halaman Config tidak 500.
     */
    public function testBrandingPanelFallsBackToDefaultsWhenStoreFails(): void
    {
        $controller = $this->controller(true);
        $controller->failBranding = true;

        $panel = $this->brandingPanel($controller);

        self::assertSame('Rames', $panel['name']);
        self::assertSame('/logo-small.png', $panel['logo_url']);
        self::assertFalse($panel['logo_custom']);
        self::assertSame(524288, $panel['max_bytes']);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(bool $admin): FakeBrandingNginxController
    {
        return new FakeBrandingNginxController($admin, $this->manager);
    }

    /**
     * @return array<string,mixed>
     */
    private function brandingPanel(NginxController $controller): array
    {
        $method = new ReflectionMethod(NginxController::class, 'brandingPanel');
        $method->setAccessible(true);

        /** @var array<string,mixed> $panel */
        $panel = $method->invoke($controller);

        return $panel;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function post(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /**
     * Request multipart dengan field teks `name` + berkas `logo`.
     */
    private function multipart(string $path, string $name, string $filename, string $content): Request
    {
        $boundary = '----RamesBrandingBoundary' . bin2hex(random_bytes(4));
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n{$name}\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"logo\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: image/png\r\n\r\n{$content}\r\n"
            . "--{$boundary}--\r\n";

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /**
     * @return array{tmp:string,mime:string,size:int,error:int,name:string}
     */
    private function upload(string $bytes, string $mime): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'rames-nginx-branding-upload-');
        file_put_contents($tmp, $bytes);
        $this->tempFiles[] = $tmp;

        return [
            'tmp' => $tmp,
            'mime' => $mime,
            'size' => strlen($bytes),
            'error' => UPLOAD_ERR_OK,
            // Nama asli sengaja "kotor" — controller/manager tidak boleh
            // memakainya sebagai target berkas.
            'name' => 'logo.png',
        ];
    }

    /**
     * @return array{tmp:string,mime:string,size:int,error:int,name:string}
     */
    private function uploadPng(): array
    {
        return $this->upload((string) base64_decode(self::PNG, true), 'image/png');
    }
}

/**
 * Seam uji: admin, manager branding, berkas unggahan, dan flash disuntik tanpa
 * session/HTTP. `failBranding` mensimulasikan store yang gagal dibaca.
 */
class FakeBrandingNginxController extends NginxController
{    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    /** @var array{tmp:string,mime:string,size:int,error:int,name:string}|null */
    public ?array $upload = null;

    public int $uploadCalls = 0;

    public bool $failBranding = false;

    public function __construct(
        private readonly bool $admin,
        private readonly BrandingManager $manager
    ) {
    }

    protected function isAdmin(): bool
    {
        return $this->admin;
    }

    protected function branding(): BrandingManager
    {
        if ($this->failBranding) {
            throw new RuntimeException('store branding tidak terbaca');
        }

        return $this->manager;
    }

    protected function uploadedLogo(Request $request): ?array
    {
        $this->uploadCalls++;

        return $this->upload;
    }

    protected function flash(string $type, string $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => $message];
    }
}

/**
 * Seam minimal: `uploadedLogo()` **tidak** di-override, sehingga jalur unggahan
 * produksi (`$request->file('logo')` dari multipart) yang diuji.
 */
class DefaultSeamBrandingController extends NginxController
{
    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    public function __construct(private readonly BrandingManager $manager)
    {
    }

    protected function isAdmin(): bool
    {
        return true;
    }

    protected function branding(): BrandingManager
    {
        return $this->manager;
    }

    protected function flash(string $type, string $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => $message];
    }
}
