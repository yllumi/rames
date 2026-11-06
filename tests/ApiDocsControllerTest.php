<?php
declare(strict_types=1);

namespace Tests;

use app\controller\api\ApiDocsController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use support\Request;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Tab halaman `/api-docs` (`?tab=endpoint|mcp`, SPECS §7.19).
 *
 * Tab dirender **server-side** supaya refresh/tautan yang dibagikan mendarat di
 * tab yang sama (pola `NginxController::tabFromRequest()`). Nilai tak dikenal
 * atau input non-skalar (`?tab[]=…`) wajib jatuh ke tab pertama — bukan error.
 */
class ApiDocsControllerTest extends TestCase
{
    public function testTabsAreEndpointAndMcp(): void
    {
        $this->assertSame(['endpoint', 'mcp'], ApiDocsController::TABS);
    }

    public function testKnownTabIsHonored(): void
    {
        $this->assertSame('mcp', $this->tabOf('/api-docs?tab=mcp'));
        $this->assertSame('endpoint', $this->tabOf('/api-docs?tab=endpoint'));
    }

    public function testUnknownOrMissingTabFallsBackToEndpoint(): void
    {
        $this->assertSame('endpoint', $this->tabOf('/api-docs'));
        $this->assertSame('endpoint', $this->tabOf('/api-docs?tab=nippon'));
        $this->assertSame('endpoint', $this->tabOf('/api-docs?tab='));
        $this->assertSame('endpoint', $this->tabOf('/api-docs?tab[]=mcp'));
        $this->assertSame('endpoint', $this->tabOf('/api-docs?tab=MCP'));
    }

    private function tabOf(string $path): string
    {
        $request = new Request('GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $method = new ReflectionMethod(ApiDocsController::class, 'tabFromRequest');
        $method->setAccessible(true);

        return (string) $method->invoke(new ApiDocsController(), $request);
    }
}
