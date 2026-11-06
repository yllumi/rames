<?php
declare(strict_types=1);

namespace Tests;

use app\library\Api\ApiAppLocator;
use app\library\Api\OpenApiSpec;
use app\middleware\ApiAuthMiddleware;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Dokumen OpenAPI adalah **satu sumber** halaman `/api-docs` dan JSON-nya, jadi
 * dokumen ini diuji strukturnya: versi, setiap operasi punya respons, seluruh
 * `$ref` menunjuk skema yang ada, skema keamanan terdefinisi, dan path dokumen
 * berada di luar prefix bearer token (wajib login).
 */
class OpenApiSpecTest extends TestCase
{
    public function testDocumentIsValidOpenApiShell(): void
    {
        $spec = OpenApiSpec::build();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotSame('', (string) $spec['info']['title']);
        $this->assertNotSame('', (string) $spec['info']['version']);
        $this->assertNotSame('', (string) $spec['info']['description']);
        $this->assertIsArray($spec['paths']);
        $this->assertNotSame([], $spec['paths']);
        $this->assertNotFalse(json_encode($spec));
    }

    public function testEveryOperationHasSummaryAndResponses(): void
    {
        foreach (OpenApiSpec::build()['paths'] as $path => $operations) {
            $this->assertIsArray($operations, $path);
            foreach ($operations as $method => $operation) {
                $this->assertIsArray($operation, $path . ' ' . $method);
                $this->assertNotSame('', (string) ($operation['summary'] ?? ''), $path . ' ' . $method);
                $this->assertNotSame('', (string) ($operation['operationId'] ?? ''), $path . ' ' . $method);
                $this->assertIsArray($operation['responses'] ?? null, $path . ' ' . $method);
                $this->assertNotSame([], $operation['responses'], $path . ' ' . $method);
            }
        }
    }

    public function testEveryRefResolvesToDefinedSchema(): void
    {
        $spec = OpenApiSpec::build();
        $definitions = array_keys($spec['components']['schemas']);

        foreach ($this->refs($spec) as $ref) {
            $this->assertIsString($ref);
            $this->assertStringStartsWith('#/components/schemas/', (string) $ref);
            $this->assertContains(substr((string) $ref, strlen('#/components/schemas/')), $definitions);
        }
    }

    public function testSecuritySchemesAreDefined(): void
    {
        $spec = OpenApiSpec::build();
        $schemes = array_keys($spec['components']['securitySchemes']);

        $this->assertContains('bearerAuth', $schemes);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertContains('sessionLogin', $schemes);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);

        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $operation) {
                foreach ($operation['security'] ?? [] as $requirement) {
                    foreach (array_keys($requirement) as $name) {
                        $this->assertContains($name, $schemes, $path . ' memakai skema keamanan tak dikenal');
                    }
                }
            }
        }
    }

    public function testApiPathsAreTokenProtected(): void
    {
        foreach (array_keys(OpenApiSpec::build()['paths']) as $path) {
            $this->assertTrue(
                str_starts_with((string) $path, ApiAuthMiddleware::PREFIX)
                || (string) $path === OpenApiSpec::DOCS_JSON_PATH,
                'path tidak dikenal cakupan autentikasinya: ' . $path
            );
        }

        $this->assertFalse(
            str_starts_with(OpenApiSpec::DOCS_JSON_PATH, ApiAuthMiddleware::PREFIX),
            'dokumen OpenAPI harus dijaga sesi login, bukan bearer token'
        );
        $this->assertStringEndsWith('.json', OpenApiSpec::DOCS_JSON_PATH);
    }

    public function testSlugParameterMatchesLocatorRules(): void
    {
        $operation = OpenApiSpec::build()['paths']['/api/v1/apps/{slug}/build']['post'];
        $parameter = $operation['parameters'][0];

        $this->assertSame('slug', $parameter['name']);
        $this->assertSame('path', $parameter['in']);
        $this->assertTrue($parameter['required']);
        $this->assertSame(ApiAppLocator::SLUG_MAX_LENGTH, $parameter['schema']['maxLength']);
        $this->assertSame(
            trim(ApiAppLocator::SLUG_PATTERN, '/'),
            $parameter['schema']['pattern'],
            'pola OpenAPI (tanpa delimiter PHP) harus sama dengan pola locator'
        );
    }

    public function testBuildEndpointDocumentsAsyncContract(): void
    {
        $operation = OpenApiSpec::build()['paths']['/api/v1/apps/{slug}/build']['post'];

        foreach (['202', '401', '402', '404', '409', '422'] as $status) {
            $this->assertArrayHasKey($status, $operation['responses']);
        }
        $this->assertSame(
            '#/components/schemas/BuildAccepted',
            $operation['responses']['202']['content']['application/json']['schema']['$ref']
        );
    }

    // ------------------------------------------------------------------
    // Extension MCP (x-rames-mcp)
    // ------------------------------------------------------------------

    public function testMcpExtensionIsDocumentedInTheSpec(): void
    {
        $mcp = OpenApiSpec::build()['x-rames-mcp'] ?? null;

        $this->assertIsArray($mcp);
        $this->assertSame('stdio', $mcp['transport']);
        $this->assertSame(OpenApiSpec::MCP_SERVER_NAME, $mcp['name']);
        $this->assertSame(OpenApiSpec::MCP_SERVER_VERSION, $mcp['version']);
        $this->assertSame(OpenApiSpec::MCP_SCRIPT, $mcp['script']);
        $this->assertSame('python3', $mcp['command']);
        $this->assertSame([OpenApiSpec::MCP_SCRIPT], $mcp['args']);
        $this->assertNotSame('', (string) ($mcp['docs'] ?? ''));
    }

    public function testMcpEnvDocumentsUrlAndKey(): void
    {
        $env = OpenApiSpec::build()['x-rames-mcp']['env'] ?? [];

        $this->assertArrayHasKey('RAMES_URL', $env);
        $this->assertArrayHasKey('RAMES_API_KEY', $env);
        $this->assertStringContainsString('tanpa garis miring', strtolower((string) $env['RAMES_URL']));
    }

    public function testMcpToolsMapToDocumentedApiPaths(): void
    {
        $spec = OpenApiSpec::build();
        $paths = $spec['paths'];
        $tools = $spec['x-rames-mcp']['tools'];

        $this->assertCount(2, $tools);
        $this->assertSame(
            ['rames_app_status', 'rames_build_app'],
            array_column($tools, 'name')
        );

        foreach ($tools as $tool) {
            // Path tool wajib benar-benar ada di dokumen, dengan method sama.
            $this->assertArrayHasKey($tool['path'], $paths);
            $operations = $paths[$tool['path']];
            $this->assertArrayHasKey(strtolower($tool['method']), $operations);
        }
    }

    /**
     * Satu sumber tidak boleh menyimpang dari skrip MCP: nama tool, versi, dan
     * path API harus sama dengan yang tertulis di `tools/mcp/rames_mcp.py`.
     */
    public function testMcpSpecMatchesServerScript(): void
    {
        $script = dirname(__DIR__) . '/' . OpenApiSpec::MCP_SCRIPT;
        $this->assertFileExists($script);
        $source = (string) file_get_contents($script);
        $spec = OpenApiSpec::build();

        foreach ($spec['x-rames-mcp']['tools'] as $tool) {
            $this->assertStringContainsString(
                (string) $tool['name'],
                $source,
                'tool ' . $tool['name'] . ' tidak ada di skrip MCP'
            );
            $this->assertStringContainsString(
                (string) $tool['path'],
                $source,
                'path ' . $tool['path'] . ' tidak ada di skrip MCP'
            );
        }

        $this->assertMatchesRegularExpression(
            '/SERVER_VERSION\\s*=\\s*["\']' . preg_quote(OpenApiSpec::MCP_SERVER_VERSION, '/') . '["\']/',
            $source,
            'versi server MCP di skrip berbeda dari OpenApiSpec::MCP_SERVER_VERSION'
        );
    }

    public function testDocsViewRendersMcpFromSpecAndUsesSharedCopyScript(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__) . '/app/view/api/docs.php');
        $profileView = (string) file_get_contents(dirname(__DIR__) . '/app/view/profile/index.php');

        $this->assertStringContainsString("x-rames-mcp", $view, 'view harus membaca extension dari OpenApiSpec');
        $this->assertStringContainsString('/js/copy-text.js', $view);
        $this->assertStringContainsString('/js/copy-text.js', $profileView);
        $this->assertFileDoesNotExist(dirname(__DIR__) . '/public/js/profile.js', 'skrip salin sudah digeneralisasi');
        $this->assertFileExists(dirname(__DIR__) . '/public/js/copy-text.js');
    }

    /**
     * Konten Endpoint & MCP dipisah ke tab sendiri: nav memakai `data-tab`
     * server-side dan pane-nya punya id tetap (`pane-endpoint`, `pane-mcp`).
     */
    public function testDocsViewSeparatesEndpointAndMcpIntoTabs(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__) . '/app/view/api/docs.php');
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/js/api-docs.js');

        $this->assertStringContainsString('id="api-docs-tabs"', $view);
        $this->assertStringContainsString('id="pane-endpoint"', $view);
        $this->assertStringContainsString('id="pane-mcp"', $view);
        $this->assertStringContainsString('data-tab="endpoint"', $view);
        $this->assertStringContainsString('data-tab="mcp"', $view);
        $this->assertStringContainsString('/js/api-docs.js', $view);
        $this->assertStringContainsString("searchParams.set('tab'", $script);
    }

    /**
     * Kumpulkan seluruh nilai `$ref` di dokumen.
     *
     * @param array<mixed> $node
     * @return array<int,mixed>
     */
    private function refs(array $node): array
    {
        $found = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                $found[] = $value;
                continue;
            }
            if (is_array($value)) {
                $found = array_merge($found, $this->refs($value));
            }
        }

        return $found;
    }
}
