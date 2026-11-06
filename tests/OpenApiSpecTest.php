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
