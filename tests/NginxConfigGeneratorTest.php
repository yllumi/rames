<?php
declare(strict_types=1);

namespace Tests;

use app\library\Nginx\NginxConfigGenerator;
use PHPUnit\Framework\TestCase;

// render() memakai helper webman `base_path()` yang membaca konstanta BASE_PATH;
// konstanta itu tidak ada di luar runtime webman, jadi didefinisikan di sini
// (tanpa menyentuh data runtime) agar config Nginx bisa diuji langsung.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Unit test NginxConfigGenerator::render() — mengunci dukungan WebSocket pada
 * serve block (HTTP 80 & HTTPS 443) tanpa bocor ke redirect block / blok ACME.
 *
 * Prasyarat app UI berbasis WebSocket (mis. OpenClaw Control UI): nginx harus
 * memakai HTTP/1.1 dan meneruskan header Upgrade/Connection.
 */
class NginxConfigGeneratorTest extends TestCase
{
    private const WS_DIRECTIVES = [
        'proxy_http_version 1.1;',
        'proxy_set_header Upgrade $http_upgrade;',
        'proxy_set_header Connection "upgrade";',
    ];

    private function generator(): NginxConfigGenerator
    {
        return new NginxConfigGenerator(sys_get_temp_dir(), '');
    }

    /**
     * @return array<int,string> blok `server { ... }` hasil render
     */
    private function serverBlocks(string $config): array
    {
        preg_match_all('/^server \{.*?^\}/ms', $config, $matches);

        return $matches[0];
    }

    public function testHttpServeBlockForwardsWebSocketUpgrade(): void
    {
        $config = $this->generator()->render(8080, [
            ['server_name' => 'app.example.com'],
        ]);

        foreach (self::WS_DIRECTIVES as $directive) {
            $this->assertStringContainsString($directive, $config);
        }
        // `$http_upgrade` harus literal (bukan hasil interpolasi PHP).
        $this->assertStringNotContainsString('proxy_set_header Upgrade ;', $config);
    }

    public function testHttpsServeBlockForwardsWebSocketUpgrade(): void
    {
        $config = $this->generator()->render(8080, [
            ['server_name' => 'app.example.com', 'ssl' => true],
        ]);

        $blocks = $this->serverBlocks($config);
        $this->assertCount(2, $blocks, 'HTTP 80→https + HTTPS 443');

        $https = '';
        $http = '';
        foreach ($blocks as $block) {
            if (str_contains($block, 'listen 443')) {
                $https = $block;
            } else {
                $http = $block;
            }
        }

        $this->assertNotSame('', $https, 'blok serve 443 harus ada');
        foreach (self::WS_DIRECTIVES as $directive) {
            $this->assertStringContainsString($directive, $https);
        }

        // Blok 80 hanya redirect — tidak mem-proxy, jadi tanpa direktif WebSocket.
        $this->assertStringContainsString('return 301 https://', $http);
        foreach (self::WS_DIRECTIVES as $directive) {
            $this->assertStringNotContainsString($directive, $http);
        }
    }

    public function testRedirectBlockHasNoWebSocketDirectives(): void
    {
        $config = $this->generator()->render(8080, [
            [
                'server_name' => 'sub.app.example.com',
                'redirect_to' => 'https://custom.example.com',
            ],
        ]);

        $blocks = $this->serverBlocks($config);
        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('return 301 https://custom.example.com$request_uri;', $blocks[0]);

        // Redirect block tidak mem-proxy → tidak boleh ada direktif WebSocket.
        foreach (self::WS_DIRECTIVES as $directive) {
            $this->assertStringNotContainsString($directive, $blocks[0]);
        }
        $this->assertStringNotContainsString('proxy_pass', $blocks[0]);
    }

    public function testAcmeChallengeBlockStaysFreeOfProxyDirectives(): void
    {
        $config = $this->generator()->render(8080, [
            ['server_name' => 'app.example.com', 'ssl' => true],
        ]);

        preg_match_all('/location \^~ \/\.well-known\/acme-challenge\/ \{.*?^\s*\}/ms', $config, $m);
        $this->assertNotEmpty($m[0], 'blok ACME harus dirender');
        foreach ($m[0] as $acme) {
            $this->assertStringContainsString('root ', $acme);
            $this->assertStringNotContainsString('proxy_', $acme);
        }
    }

    public function testWebSocketDirectivesOnlyOnProxyingLocations(): void
    {
        // ssl=true → 2 server block, tapi hanya satu `location /` yang mem-proxy.
        $config = $this->generator()->render(8080, [
            ['server_name' => 'app.example.com', 'ssl' => true],
        ]);

        $this->assertSame(1, substr_count($config, 'proxy_http_version 1.1;'));
        $this->assertSame(1, substr_count($config, 'proxy_set_header Upgrade $http_upgrade;'));
        $this->assertSame(1, substr_count($config, 'proxy_set_header Connection "upgrade";'));
    }

    // ------------------------------------------------------------------
    // Rute proxy tambahan per app (NginxRoutes) — hanya di serve block.
    // ------------------------------------------------------------------

    /** @var array<int,array{path:string,target:string}> */
    private const SAMPLE_ROUTES = [
        ['path' => '/api/', 'target' => 'http://127.0.0.1:3001'],
        ['path' => '/gateway/', 'target' => 'http://127.0.0.1:3002'],
    ];

    /**
     * Blok rute yang diharapkan (indent 4 spasi, `location ^~`, tanpa nested).
     */
    private function expectedRouteBlock(string $path, string $target): string
    {
        return <<<NGINX
    location ^~ {$path} {
        proxy_pass {$target};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }
NGINX;
    }

    public function testRouteBlocksRenderedAfterLocationSlashInHttpServeBlock(): void
    {
        $config = $this->generator()->render(8080, [
            ['server_name' => 'app.example.com'],
        ], self::SAMPLE_ROUTES);

        $this->assertStringContainsString($this->expectedRouteBlock('/api/', 'http://127.0.0.1:3001'), $config);
        $this->assertStringContainsString($this->expectedRouteBlock('/gateway/', 'http://127.0.0.1:3002'), $config);

        // Rute diletakkan SETELAH blok `location /`, bukan nested di dalamnya.
        $location = strpos($config, "location / {");
        $route = strpos($config, 'location ^~ /api/ {');
        $this->assertNotFalse($location);
        $this->assertNotFalse($route);
        $this->assertGreaterThan($location, $route);

        preg_match('/^    location \/ \{.*?^    \}/ms', $config, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('location ^~ /api/', $m[0], 'rute tidak boleh nested di location /');
    }

    public function testRouteBlocksRenderedOnlyInHttpsServeBlockWhenSsl(): void
    {
        $config = $this->generator()->render(8080, [
            ['server_name' => 'app.example.com', 'ssl' => true],
        ], self::SAMPLE_ROUTES);

        $blocks = $this->serverBlocks($config);
        $this->assertCount(2, $blocks, 'HTTP 80→https + HTTPS 443');

        $https = '';
        $httpRedirect = '';
        foreach ($blocks as $block) {
            if (str_contains($block, 'listen 443')) {
                $https = $block;
            } else {
                $httpRedirect = $block;
            }
        }

        // Serve block 443 memuat kedua rute.
        $this->assertStringContainsString('location ^~ /api/ {', $https);
        $this->assertStringContainsString('location ^~ /gateway/ {', $https);
        $this->assertSame(1, substr_count($https, 'proxy_pass http://127.0.0.1:3001;'));

        // Blok 80→https hanya redirect → tanpa blok rute.
        $this->assertStringContainsString('return 301 https://', $httpRedirect);
        $this->assertStringNotContainsString('location ^~ /api/', $httpRedirect);
        $this->assertStringNotContainsString('location ^~ /gateway/', $httpRedirect);
    }

    public function testRouteBlocksNotRenderedInRedirectBlock(): void
    {
        // Kasus custom domain: subdomain redirect (301) ke custom domain, dan
        // custom domain (ssl) melayani app. Rute hanya di serve block.
        $config = $this->generator()->render(8080, [
            ['server_name' => 'sub.app.example.com', 'redirect_to' => 'https://custom.example.com'],
            ['server_name' => 'custom.example.com', 'ssl' => true],
        ], self::SAMPLE_ROUTES);

        $blocks = $this->serverBlocks($config);
        $this->assertCount(3, $blocks, 'redirect + 80→https + 443 serve');

        foreach ($blocks as $block) {
            if (str_contains($block, 'return 301 https://custom.example.com')) {
                $this->assertStringNotContainsString('location ^~ /api/', $block);
                $this->assertStringNotContainsString('proxy_pass', $block);
            }
        }

        // Hanya serve block 443 custom domain yang memuat rute.
        $this->assertSame(1, substr_count($config, 'location ^~ /api/ {'));
        $this->assertSame(1, substr_count($config, 'location ^~ /gateway/ {'));
    }

    public function testRouteBlocksRenderedInHttpServeBlockForCustomDomainWithoutSsl(): void
    {
        $config = $this->generator()->render(8080, [
            ['server_name' => 'sub.app.example.com', 'redirect_to' => 'http://custom.example.com'],
            ['server_name' => 'custom.example.com'],
        ], self::SAMPLE_ROUTES);

        $this->assertSame(1, substr_count($config, 'location ^~ /api/ {'));
        $this->assertSame(1, substr_count($config, 'location ^~ /gateway/ {'));
        $this->assertStringContainsString('return 301 http://custom.example.com$request_uri;', $config);
    }

    public function testRenderWithoutRoutesIsUnchanged(): void
    {
        $servers = [
            ['server_name' => 'app.example.com', 'ssl' => true],
        ];

        $withDefault = $this->generator()->render(8080, $servers);
        $withEmpty = $this->generator()->render(8080, $servers, []);

        $this->assertSame($withEmpty, $withDefault);
        // Satu-satunya `location ^~` yang tersisa adalah blok ACME.
        $this->assertSame(
            substr_count($withDefault, 'location ^~ /.well-known'),
            substr_count($withDefault, 'location ^~'),
            'tanpa rute tambahan tidak boleh ada location ^~ lain'
        );
        $this->assertSame(1, substr_count($withDefault, 'proxy_http_version 1.1;'));
    }

    public function testRenderRejectsInvalidRoutes(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->generator()->render(8080, [
            ['server_name' => 'app.example.com'],
        ], [['path' => '/', 'target' => 'http://127.0.0.1:3001']]);
    }
}
