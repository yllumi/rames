<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BrandingController;
use app\library\Branding\BrandingLogo;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

/**
 * Test penyaji logo publik `GET /branding/logo` (`BrandingController`).
 *
 * Berkas logo diarahkan ke direktori temp lewat seam `logoStore()`. Yang
 * dibuktikan:
 *  - belum ada logo ⇒ 404 (tanpa body),
 *  - ada logo ⇒ 200 + `Content-Type` gambar + `nosniff` + `no-cache` + ETag,
 *  - `If-None-Match` cocok (termasuk `*`) ⇒ 304 **tanpa body**,
 *  - parameter request tidak bisa menunjuk berkas lain (anti path traversal).
 */
class BrandingControllerTest extends TestCase
{
    /** PNG 1×1 (RGB). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGPgEpEDAABoAD1UCKP3AAAAAElFTkSuQmCC';

    private string $dir;
    private BrandingLogo $logo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-branding-controller-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->logo = new BrandingLogo($this->dir);
    }

    protected function tearDown(): void
    {
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

    public function testMissingLogoReturns404WithoutBody(): void
    {
        $response = $this->controller()->logo($this->get('/branding/logo'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->rawBody());
    }

    public function testLogoIsServedWithSecurityAndCacheHeaders(): void
    {
        $bytes = (string) base64_decode(self::PNG, true);
        file_put_contents($this->dir . '/logo.png', $bytes);

        $response = $this->controller()->logo($this->get('/branding/logo'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($bytes, $response->rawBody());
        self::assertSame('image/png', $response->getHeader('Content-Type'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertSame('no-cache', $response->getHeader('Cache-Control'));
        // `Content-Length` tidak diset manual: Webman mengisinya saat response
        // dikirim (header ganda melanggar RFC 7230 §3.3.2).

        $etag = $this->logo->etag();
        self::assertNotNull($etag);
        self::assertSame('"' . $etag . '"', $response->getHeader('ETag'));
    }

    public function testMatchingIfNoneMatchReturns304WithoutBody(): void
    {
        file_put_contents($this->dir . '/logo.png', (string) base64_decode(self::PNG, true));
        $etag = (string) $this->logo->etag();

        $response = $this->controller()->logo(
            $this->get('/branding/logo', ['If-None-Match' => '"' . $etag . '"'])
        );

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $response->rawBody());
        self::assertSame('"' . $etag . '"', $response->getHeader('ETag'));
    }

    public function testWildcardIfNoneMatchReturns304(): void
    {
        file_put_contents($this->dir . '/logo.png', (string) base64_decode(self::PNG, true));

        $response = $this->controller()->logo($this->get('/branding/logo', ['If-None-Match' => '*']));

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $response->rawBody());
    }

    public function testStaleIfNoneMatchReturnsFullBody(): void
    {
        $bytes = (string) base64_decode(self::PNG, true);
        file_put_contents($this->dir . '/logo.png', $bytes);

        $response = $this->controller()->logo(
            $this->get('/branding/logo', ['If-None-Match' => '"etag-lama"'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($bytes, $response->rawBody());
    }

    /**
     * Query/parameter request tidak boleh menggeser berkas yang dibaca: store
     * kosong tetap 404 walau ada berkas lain di luar direktori logo.
     */
    public function testRequestCannotPointStoreToArbitraryFile(): void
    {
        $secret = sys_get_temp_dir() . '/rames-branding-secret-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($secret, 'rahasia');
        try {
            $response = $this->controller()->logo(
                $this->get('/branding/logo?file=' . rawurlencode($secret) . '&path=' . rawurlencode($secret))
            );

            self::assertSame(404, $response->getStatusCode());
            self::assertSame('', $response->rawBody());
        } finally {
            @unlink($secret);
        }
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(): FakeBrandingController
    {
        return new FakeBrandingController($this->logo);
    }

    /**
     * @param array<string,string> $headers
     */
    private function get(string $path, array $headers = []): Request
    {
        $raw = '';
        foreach ($headers as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }

        return new Request('GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\n" . $raw . "\r\n");
    }
}

/**
 * Seam uji: store logo di direktori temp.
 */
class FakeBrandingController extends BrandingController
{
    public function __construct(private readonly BrandingLogo $logo)
    {
    }

    protected function logoStore(): BrandingLogo
    {
        return $this->logo;
    }
}
