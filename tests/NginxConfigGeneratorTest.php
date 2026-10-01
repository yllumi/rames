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
}
