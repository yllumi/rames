<?php
declare(strict_types=1);

namespace Tests;

use app\library\Nginx\NginxConfigGenerator;
use app\library\Nginx\ParkedNginxConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Helper webman (`base_path()`, `config()`) tidak dimuat di luar runtime webman
// (pola NginxConfigGeneratorTest / SubdomainTest).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Test render & tulis config Nginx domain parking (`_parked_{id}.conf`).
 *
 * Semua berkas di direktori temp — config Nginx host nyata tidak disentuh.
 */
class ParkedNginxConfigTest extends TestCase
{
    private const ID = 'pd_0123456789ab';

    private string $base;
    private string $conf;
    private string $enabled;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rames-parked-nginx-' . bin2hex(random_bytes(4));
        $this->conf = $this->base . '/sites-available';
        $this->enabled = $this->base . '/sites-enabled';
        mkdir($this->conf, 0775, true);
        mkdir($this->enabled, 0775, true);
    }

    protected function tearDown(): void
    {
        self::rmTree($this->base);
    }

    // ==================================================================
    // Render
    // ==================================================================

    public function testRenderHtmlServesStaticFileWithAcmeBlockAndNo443(): void
    {
        $contentDir = $this->base . '/parked/' . self::ID;
        $webroot = $this->base . '/webroot';

        $config = ParkedNginxConfig::render(
            ['id' => self::ID, 'domain' => 'parked.example.com', 'mode' => 'html'],
            $contentDir,
            $webroot
        );

        $this->assertStringContainsString('listen 80;', $config);
        $this->assertStringContainsString('server_name parked.example.com;', $config);
        $this->assertStringContainsString('root ' . $contentDir . ';', $config);
        $this->assertStringContainsString('index index.html;', $config);
        $this->assertStringContainsString('try_files $uri $uri/ =404;', $config);
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/ {', $config);
        $this->assertStringContainsString('root ' . $webroot . ';', $config);
        $this->assertStringContainsString(ParkedNginxConfig::HEADER_MARKER, $config);
        // Fase 1: tanpa SSL.
        $this->assertStringNotContainsString('listen 443', $config);
        $this->assertStringNotContainsString('ssl_certificate', $config);
    }

    // ==================================================================
    // SSL per entri
    // ==================================================================

    public function testRenderActiveHtmlRedirectsHttpToHttpsAndServes443WithCert(): void
    {
        $le = $this->base . '/letsencrypt';
        $this->makeCert($le, 'parked.example.com');
        $contentDir = $this->base . '/parked/' . self::ID;
        $webroot = $this->base . '/webroot';

        $config = ParkedNginxConfig::render(
            [
                'id' => self::ID,
                'domain' => 'parked.example.com',
                'mode' => 'html',
                'ssl_status' => 'active',
            ],
            $contentDir,
            $webroot,
            $le
        );

        $this->assertSame(1, substr_count($config, 'listen 80;'));
        $this->assertSame(1, substr_count($config, 'listen 443 ssl;'));
        $this->assertStringContainsString('return 301 https://$host$request_uri;', $config);
        $this->assertStringContainsString(
            'ssl_certificate ' . $le . '/live/parked.example.com/fullchain.pem;',
            $config
        );
        $this->assertStringContainsString(
            'ssl_certificate_key ' . $le . '/live/parked.example.com/privkey.pem;',
            $config
        );
        $this->assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $config);
        $this->assertStringContainsString('root ' . $contentDir . ';', $config);
        $this->assertStringContainsString('index index.html;', $config);
        $this->assertStringContainsString('try_files $uri $uri/ =404;', $config);

        // ACME (HTTP-01) cukup di blok 80 — blok 443 tidak memuatnya.
        [$http, $https] = explode('listen 443 ssl;', $config, 2);
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/ {', $http);
        $this->assertStringContainsString('root ' . $webroot . ';', $http);
        $this->assertStringNotContainsString('acme-challenge', $https);
    }

    public function testRenderActiveRedirectReturnsCodeOnBothBlocksWithoutHttpsRedirect(): void
    {
        $le = $this->base . '/letsencrypt';
        $this->makeCert($le, 'lama.example.com');

        $config = ParkedNginxConfig::render(
            [
                'id' => self::ID,
                'domain' => 'lama.example.com',
                'mode' => 'redirect',
                'redirect_to' => 'https://baru.example.com',
                'redirect_code' => 302,
                'ssl_status' => 'active',
            ],
            $this->base . '/parked/' . self::ID,
            $this->base . '/webroot',
            $le
        );

        $this->assertSame(1, substr_count($config, 'listen 80;'));
        $this->assertSame(1, substr_count($config, 'listen 443 ssl;'));
        $this->assertSame(
            2,
            substr_count($config, 'return 302 https://baru.example.com$request_uri;'),
            'blok 80 dan 443 harus memakai kode/target yang sama'
        );
        $this->assertStringNotContainsString(
            'return 301 https://$host$request_uri;',
            $config,
            'mode redirect tidak boleh dialihkan dulu ke https (redirect ganda)'
        );

        [$http, $https] = explode('listen 443 ssl;', $config, 2);
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/ {', $http);
        $this->assertStringNotContainsString('acme-challenge', $https);
    }

    public function testRenderActiveWithoutCertFallsBackToHttpOnly(): void
    {
        $le = $this->base . '/letsencrypt';
        @mkdir($le, 0775, true); // direktori ada, berkas cert tidak (restore DB saja)

        $config = ParkedNginxConfig::render(
            [
                'id' => self::ID,
                'domain' => 'parked.example.com',
                'mode' => 'html',
                'ssl_status' => 'active',
            ],
            $this->base . '/parked/' . self::ID,
            $this->base . '/webroot',
            $le
        );

        $this->assertStringNotContainsString('listen 443', $config);
        $this->assertStringNotContainsString('ssl_certificate', $config);
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/ {', $config);
    }

    public function testRenderNonActiveNeverEmits443EvenWhenCertExists(): void
    {
        $le = $this->base . '/letsencrypt';
        $this->makeCert($le, 'parked.example.com');

        foreach (['disabled', 'pending', 'failed', ''] as $status) {
            $entry = ['id' => self::ID, 'domain' => 'parked.example.com', 'mode' => 'html'];
            if ($status !== '') {
                $entry['ssl_status'] = $status;
            }

            $config = ParkedNginxConfig::render(
                $entry,
                $this->base . '/parked/' . self::ID,
                $this->base . '/webroot',
                $le
            );

            $this->assertStringNotContainsString('listen 443', $config, 'status: ' . $status);
            $this->assertStringNotContainsString('ssl_certificate', $config, 'status: ' . $status);
        }
    }

    /**
     * @dataProvider unsafeLetsencryptPaths
     */
    public function testRenderActiveRejectsUnsafeLetsencryptPath(string $lePath, string $needle): void
    {
        try {
            ParkedNginxConfig::render(
                [
                    'id' => self::ID,
                    'domain' => 'parked.example.com',
                    'mode' => 'html',
                    'ssl_status' => 'active',
                ],
                $this->base . '/parked/' . self::ID,
                $this->base . '/webroot',
                $lePath
            );
            $this->fail('path Let\'s Encrypt tidak aman harus ditolak saat SSL aktif');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("direktori Let's Encrypt", $e->getMessage());
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function unsafeLetsencryptPaths(): array
    {
        return [
            'relatif' => ['etc/letsencrypt', 'wajib absolut'],
            'ber-$' => ['/etc/lets/$encrypt', 'karakter $'],
            'ber-..' => ['/etc/../etc/letsencrypt', 'segmen ".."'],
            'ber-spasi' => ['/etc/lets encrypt', 'tidak aman'],
        ];
    }

    /**
     * Path relatif sah di disk tetapi di-resolve relatif oleh Nginx host →
     * 404 senyap, jadi harus ditolak saat render. `$` akan diekspansi Nginx,
     * dan segmen `..` membuka traversal.
     *
     * @dataProvider unsafeRootPaths
     */
    public function testRenderRejectsUnsafeRootPaths(string $contentDir, string $webroot, string $needle): void
    {
        try {
            ParkedNginxConfig::render(
                ['id' => self::ID, 'domain' => 'parked.example.com', 'mode' => 'html'],
                $contentDir,
                $webroot
            );
            $this->fail('path tidak aman harus ditolak sebelum masuk config Nginx');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function unsafeRootPaths(): array
    {
        return [
            'contentDir relatif' => [
                'database/parked/' . self::ID,
                '/srv/rames/webroot',
                'wajib absolut',
            ],
            'webroot relatif' => [
                '/srv/rames/database/parked/' . self::ID,
                'webroot',
                'wajib absolut',
            ],
            'contentDir ber-$' => [
                '/srv/rames/database/$host/' . self::ID,
                '/srv/rames/webroot',
                'karakter $',
            ],
            'webroot ber-$' => [
                '/srv/rames/database/parked/' . self::ID,
                '/srv/rames/$host/letsencrypt',
                'karakter $',
            ],
            'contentDir ber-..' => [
                '/srv/rames/database/../database/parked/' . self::ID,
                '/srv/rames/webroot',
                'segmen ".."',
            ],
            'webroot ber-..' => [
                '/srv/rames/database/parked/' . self::ID,
                '/srv/rames/webroot/../webroot',
                'segmen ".."',
            ],
        ];
    }

    /**
     * @dataProvider redirectCodes
     */
    public function testRenderRedirectUsesCodeAndRequestUriLiteral(int $code): void
    {
        $config = ParkedNginxConfig::render(
            [
                'id' => self::ID,
                'domain' => 'lama.example.com',
                'mode' => 'redirect',
                'redirect_to' => 'https://baru.example.com',
                'redirect_code' => $code,
            ],
            $this->base . '/parked/' . self::ID,
            $this->base . '/webroot'
        );

        $this->assertStringContainsString('return ' . $code . ' https://baru.example.com$request_uri;', $config);
        // ACME tetap ada di block redirect agar renewal HTTP-01 tidak putus.
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/ {', $config);
        $this->assertStringNotContainsString('listen 443', $config);
        $this->assertStringNotContainsString('proxy_pass', $config);
    }

    /**
     * @return array<string,array{0:int}>
     */
    public static function redirectCodes(): array
    {
        return ['301' => [301], '302' => [302]];
    }

    /**
     * @dataProvider dangerousEntries
     *
     * @param array<string,mixed> $entry
     */
    public function testRenderRejectsDangerousDomainOrTargetBeforeWriting(array $entry): void
    {
        $this->expectException(RuntimeException::class);

        ParkedNginxConfig::render($entry, $this->base . '/parked/' . self::ID, $this->base . '/webroot');
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function dangerousEntries(): array
    {
        return [
            'domain injeksi titik-koma' => [[
                'id' => self::ID,
                'domain' => 'evil.example.com; }',
                'mode' => 'html',
            ]],
            'domain newline' => [[
                'id' => self::ID,
                'domain' => "evil.example.com\nlisten 9999",
                'mode' => 'html',
            ]],
            'domain bukan fqdn' => [[
                'id' => self::ID,
                'domain' => 'localhost',
                'mode' => 'html',
            ]],
            'target skema terlarang' => [[
                'id' => self::ID,
                'domain' => 'parked.example.com',
                'mode' => 'redirect',
                'redirect_to' => 'javascript:alert(1)',
                'redirect_code' => 301,
            ]],
            'target dengan kutip' => [[
                'id' => self::ID,
                'domain' => 'parked.example.com',
                'mode' => 'redirect',
                'redirect_to' => 'https://baru.example.com/dengan"kutip',
                'redirect_code' => 301,
            ]],
            'target dengan newline' => [[
                'id' => self::ID,
                'domain' => 'parked.example.com',
                'mode' => 'redirect',
                'redirect_to' => "https://baru.example.com/\nreturn 200",
                'redirect_code' => 301,
            ]],
            'kode redirect tak didukung' => [[
                'id' => self::ID,
                'domain' => 'parked.example.com',
                'mode' => 'redirect',
                'redirect_to' => 'https://baru.example.com',
                'redirect_code' => 307,
            ]],
            'mode tak dikenal' => [[
                'id' => self::ID,
                'domain' => 'parked.example.com',
                'mode' => 'proxy',
            ]],
            'id path traversal' => [[
                'id' => 'pd_../../etc/passwd',
                'domain' => 'parked.example.com',
                'mode' => 'html',
            ]],
        ];
    }

    // ==================================================================
    // Nama berkas
    // ==================================================================

    public function testFileNameIsPrefixedWithParkedAndSafe(): void
    {
        $this->assertSame('_parked_' . self::ID, ParkedNginxConfig::fileName(self::ID));
        $this->assertMatchesRegularExpression('/^_parked_pd_[0-9a-f]{12}$/', ParkedNginxConfig::fileName(self::ID));
    }

    /**
     * @dataProvider unsafeIds
     */
    public function testFileNameRejectsUnsafeIds(string $id): void
    {
        $this->expectException(RuntimeException::class);

        ParkedNginxConfig::fileName($id);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unsafeIds(): array
    {
        return [
            'kosong' => [''],
            'traversal' => ['../../etc/passwd'],
            'prefiks salah' => ['app_0123456789ab'],
            'huruf besar' => ['pd_0123456789AB'],
            'terlalu pendek' => ['pd_0123456789a'],
            'terlalu panjang' => ['pd_0123456789abc'],
            'newline' => ["pd_0123456789ab\n"],
        ];
    }

    // ==================================================================
    // Tulis & hapus
    // ==================================================================

    public function testWriteCreatesConfigAndEnabledSymlinkThenRemoveDeletesBoth(): void
    {
        $config = new ParkedNginxConfig(new NginxConfigGenerator($this->conf, $this->enabled));
        $entry = ['id' => self::ID, 'domain' => 'parked.example.com', 'mode' => 'html'];

        $config->write($entry, $this->base . '/parked/' . self::ID, $this->base . '/webroot');

        $file = $this->conf . '/_parked_' . self::ID . '.conf';
        $link = $this->enabled . '/_parked_' . self::ID . '.conf';
        $this->assertFileExists($file);
        $this->assertStringContainsString('server_name parked.example.com;', (string) file_get_contents($file));
        $this->assertTrue(is_link($link), 'config aktif harus di-symlink ke sites-enabled');
        $this->assertSame($file, readlink($link));

        $config->remove(self::ID);

        $this->assertFileDoesNotExist($file);
        $this->assertFalse(is_link($link));
        $this->assertFileDoesNotExist($link);
    }

    /**
     * Buat berkas cert palsu di `{le}/live/{domain}/` supaya render SSL
     * dianggap sah (render memeriksa keberadaan berkas, bukan isi).
     */
    private function makeCert(string $leRoot, string $domain): void
    {
        $dir = $leRoot . '/live/' . $domain;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/fullchain.pem', "-----BEGIN CERTIFICATE-----\n");
        file_put_contents($dir . '/privkey.pem', "-----BEGIN PRIVATE KEY-----\n");
    }

    private static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::rmTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
