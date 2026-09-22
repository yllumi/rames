<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ComposeSource;
use app\library\Template\TemplateCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit test TemplateCatalog — galeri template create app (SPECS.md §7.2b):
 * pembacaan & validasi manifest/compose (image prebuilt, tolak build:,
 * container_name, name:, replica, tanpa port, variabel tanpa deklarasi),
 * resolusi nilai env (input → default → generate → wajib), materialisasi file
 * ke direktori app, serta validitas template bawaan yang ikut di repo.
 */
class TemplateCatalogTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-tpl-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    // ==================================================================
    // Pembacaan & validasi
    // ==================================================================

    public function testReadsValidTemplateWithEnvPrimaryAndPorts(): void
    {
        $this->writeTemplate('demo', <<<'YAML'
title: Demo App
description: Aplikasi demo.
category: Demo
icon: "🧪"
docs_url: https://example.com/docs
env:
  - key: APP_KEY
    label: Application key
    generate: secret
  - key: APP_MODE
    label: Mode
    default: production
  - key: ENABLED
    default: false
  - key: RETRIES
    default: 0
primary:
  service: web
  port: 8080
YAML, <<<'YAML'
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:8080"
    environment:
      APP_KEY: ${APP_KEY}
      APP_MODE: ${APP_MODE}
      ENABLED: ${ENABLED}
      RETRIES: ${RETRIES}
YAML);

        $template = (new TemplateCatalog($this->tmp))->require('demo');

        $this->assertTrue($template['valid']);
        $this->assertSame('Demo App', $template['title']);
        $this->assertSame('Demo', $template['category']);
        $this->assertSame('🧪', $template['icon']);
        $this->assertSame('https://example.com/docs', $template['docs_url']);
        $this->assertSame('nginx:alpine', $template['image']);
        $this->assertSame(['service' => 'web', 'port' => 8080], $template['primary']);
        $this->assertSame([8080], $template['ports']);
        $this->assertSame([], $template['files']);
    }

    public function testEnvDeclarationNormalizesScalars(): void
    {
        $this->writeTemplate('demo', <<<'YAML'
env:
  - key: SECRET_KEY
    generate: secret
  - key: FLAG
    default: false
  - key: COUNT
    default: 0
  - key: NOTE
    label: Catatan
YAML, $this->composeWithEnv(['SECRET_KEY', 'FLAG', 'COUNT', 'NOTE']));

        $env = (new TemplateCatalog($this->tmp))->require('demo')['env'];
        $byKey = [];
        foreach ($env as $spec) {
            $byKey[$spec['key']] = $spec;
        }

        $this->assertTrue($byKey['SECRET_KEY']['secret']);
        $this->assertTrue($byKey['SECRET_KEY']['generate']);
        $this->assertFalse($byKey['SECRET_KEY']['required']);

        // `false`/`0` adalah nilai default yang sah — bukan "tidak ada default".
        $this->assertSame('false', $byKey['FLAG']['default']);
        $this->assertFalse($byKey['FLAG']['required']);
        $this->assertSame('0', $byKey['COUNT']['default']);

        // Tanpa default & tanpa generate = wajib diisi.
        $this->assertTrue($byKey['NOTE']['required']);
        $this->assertSame('Catatan', $byKey['NOTE']['label']);
    }

    public function testRejectsTemplateWithBuild(): void
    {
        $this->assertInvalid('demo', $this->manifest(), <<<'YAML'
services:
  web:
    build: .
    ports:
      - "8080:80"
YAML, '/build:/');
    }

    public function testRejectsServiceWithoutImage(): void
    {
        $this->assertInvalid('demo', $this->manifest(), <<<'YAML'
services:
  web:
    ports:
      - "8080:80"
YAML, '/image:/');
    }

    public function testRejectsContainerName(): void
    {
        $this->assertInvalid('demo', $this->manifest(), <<<'YAML'
services:
  web:
    image: nginx:alpine
    container_name: my-web
    ports:
      - "8080:80"
YAML, '/container_name/');
    }

    public function testRejectsTopLevelProjectName(): void
    {
        $this->assertInvalid('demo', $this->manifest(), <<<'YAML'
name: myproject
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:80"
YAML, '/name:/');
    }

    public function testRejectsReplicatedService(): void
    {
        $this->assertInvalid('demo', $this->manifest(), <<<'YAML'
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:80"
    deploy:
      replicas: 2
YAML, '/replica/');
    }

    public function testRejectsTemplateWithoutPorts(): void
    {
        $this->assertInvalid('demo', $this->manifest(), <<<'YAML'
services:
  web:
    image: nginx:alpine
YAML, '/port/');
    }

    public function testRejectsMissingComposeFile(): void
    {
        $dir = $this->tmp . '/demo';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/' . TemplateCatalog::MANIFEST_FILE, "title: Demo\n");

        $template = (new TemplateCatalog($this->tmp))->find('demo');

        $this->assertNotNull($template);
        $this->assertFalse($template['valid']);
        $this->assertStringContainsString('docker-compose.yml', (string) $template['error']);
    }

    public function testRejectsUndeclaredComposeVariable(): void
    {
        $this->assertInvalid('demo', $this->manifest(), <<<'YAML'
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:80"
    environment:
      DB_PASSWORD: ${DB_PASSWORD}
YAML, '/DB_PASSWORD/');
    }

    public function testAllowsVariableWithDefaultAndReservedVars(): void
    {
        $this->writeTemplate('demo', $this->manifest(), <<<'YAML'
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:80"
    environment:
      MODE: ${MODE:-production}
      DATA_DIR: ${PWD}/data
    volumes:
      - ./.data:/data
YAML);

        $this->assertTrue((new TemplateCatalog($this->tmp))->require('demo')['valid']);
    }

    public function testRejectsInvalidAndDuplicateEnvKeys(): void
    {
        $this->assertInvalid('demo', <<<'YAML'
env:
  - key: not-a-key
YAML, $this->composeWithEnv([]), '/Kunci env tidak valid/');

        $this->assertInvalid('demo', <<<'YAML'
env:
  - key: APP_KEY
  - key: APP_KEY
YAML, $this->composeWithEnv([]), '/duplikat/');
    }

    public function testRejectsUnknownPrimaryServiceAndPort(): void
    {
        $this->assertInvalid('demo', <<<'YAML'
primary:
  service: api
  port: 8080
YAML, $this->composeWithEnv([]), '/primary.service/');

        $this->assertInvalid('demo', <<<'YAML'
primary:
  service: web
  port: 9999
YAML, $this->composeWithEnv([]), '/primary.port/');
    }

    public function testPrimaryDefaultsToFirstServiceWithPort(): void
    {
        $this->writeTemplate('demo', $this->manifest(), $this->composeWithEnv([]));

        $this->assertSame(
            ['service' => 'web', 'port' => 80],
            (new TemplateCatalog($this->tmp))->require('demo')['primary']
        );
    }

    public function testRejectsUnsafeOrMissingSupportFile(): void
    {
        $this->assertInvalid('demo', <<<'YAML'
files:
  - ../secrets.txt
YAML, $this->composeWithEnv([]), '/Nama file tidak valid/');

        $this->assertInvalid('demo', <<<'YAML'
files:
  - nginx.conf
YAML, $this->composeWithEnv([]), '/tidak ada/');
    }

    public function testRejectsGeneratedOverrideAsSupportFile(): void
    {
        $this->assertInvalid('demo', <<<'YAML'
files:
  - docker-compose.override.yml
YAML, $this->composeWithEnv([]), '/dikelola dashboard/');
    }

    public function testAllSkipsNonSlugDirectoriesAndFindsBySlug(): void
    {
        $this->writeTemplate('demo', $this->manifest(), $this->composeWithEnv([]));
        mkdir($this->tmp . '/Not_A_Slug', 0777, true);
        file_put_contents($this->tmp . '/Not_A_Slug/' . TemplateCatalog::MANIFEST_FILE, "title: X\n");

        $catalog = new TemplateCatalog($this->tmp);

        $this->assertSame(['demo'], array_column($catalog->all(), 'slug'));
        $this->assertNull($catalog->find('tidak-ada'));
        $this->assertNull($catalog->find('../demo'));
    }

    public function testAllReturnsBrokenTemplateAsInvalid(): void
    {
        $this->writeTemplate('broken', $this->manifest(), "services:\n  web:\n    build: .\n");
        $this->writeTemplate('healthy', $this->manifest(), $this->composeWithEnv([]));

        $all = (new TemplateCatalog($this->tmp))->all();

        $this->assertCount(2, $all);
        $errors = [];
        foreach ($all as $template) {
            $errors[$template['slug']] = $template['valid'];
        }
        $this->assertFalse($errors['broken']);
        $this->assertTrue($errors['healthy']);
    }

    public function testRequireThrowsForInvalidTemplate(): void
    {
        $this->writeTemplate('broken', $this->manifest(), "services:\n  web:\n    build: .\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak valid/');

        (new TemplateCatalog($this->tmp))->require('broken');
    }

    // ==================================================================
    // Nilai env
    // ==================================================================

    public function testResolveEnvPrefersInputThenDefaultThenGenerated(): void
    {
        $this->writeTemplate('demo', <<<'YAML'
env:
  - key: APP_KEY
    generate: secret
  - key: MODE
    default: production
  - key: NOTE
    required: true
YAML, $this->composeWithEnv(['APP_KEY', 'MODE', 'NOTE']));

        $catalog = new TemplateCatalog($this->tmp);
        $template = $catalog->require('demo');

        $env = $catalog->resolveEnv($template, ['APP_KEY' => 'dari-user', 'NOTE' => 'catatan']);

        $this->assertSame('dari-user', $env['APP_KEY']);
        $this->assertSame('production', $env['MODE']);
        $this->assertSame('catatan', $env['NOTE']);

        // Generate: nilai acak hex sepanjang SECRET_LENGTH, berbeda tiap app.
        $generated = $catalog->resolveEnv($template, ['NOTE' => 'x']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{' . TemplateCatalog::SECRET_LENGTH . '}$/', $generated['APP_KEY']);
        $this->assertNotSame($generated['APP_KEY'], $catalog->resolveEnv($template, ['NOTE' => 'x'])['APP_KEY']);
    }

    public function testResolveEnvRequiresMandatoryValue(): void
    {
        $this->writeTemplate('demo', <<<'YAML'
env:
  - key: NOTE
    label: Catatan
    required: true
YAML, $this->composeWithEnv(['NOTE']));

        $catalog = new TemplateCatalog($this->tmp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wajib diisi/');

        $catalog->resolveEnv($catalog->require('demo'), []);
    }

    public function testResolveEnvRejectsMultilineAndNonScalarValues(): void
    {
        $this->writeTemplate(
            'demo',
            "env:\n  - key: NOTE\n    required: true\n",
            $this->composeWithEnv(['NOTE'])
        );
        $catalog = new TemplateCatalog($this->tmp);
        $template = $catalog->require('demo');

        try {
            $catalog->resolveEnv($template, ['NOTE' => "baris1\nbaris2"]);
            $this->fail('Nilai dengan baris baru seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('baris baru', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak valid/');

        $catalog->resolveEnv($template, ['NOTE' => ['nested' => 'value']]);
    }

    public function testGeneratedKeysListsOnlyEmptySecretFields(): void
    {
        $this->writeTemplate('demo', <<<'YAML'
env:
  - key: APP_KEY
    generate: secret
  - key: JWT_SECRET
    generate: secret
  - key: MODE
    default: production
YAML, $this->composeWithEnv(['APP_KEY', 'JWT_SECRET', 'MODE']));

        $catalog = new TemplateCatalog($this->tmp);
        $template = $catalog->require('demo');

        $this->assertSame([], $catalog->generatedKeys($template, ['APP_KEY' => 'x', 'JWT_SECRET' => 'y']));
        $this->assertSame(['APP_KEY'], $catalog->generatedKeys($template, ['APP_KEY' => '', 'JWT_SECRET' => 'y']));
    }

    // ==================================================================
    // Materialisasi
    // ==================================================================

    public function testMaterializeWritesComposeAndSupportFiles(): void
    {
        $this->writeTemplate('demo', <<<'YAML'
files:
  - nginx.conf
YAML, <<<'YAML'
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
YAML, ['nginx.conf' => "server {\n  listen 80;\n}\n"]);

        $dest = $this->tmp . '/dest';
        mkdir($dest, 0777, true);

        $catalog = new TemplateCatalog($this->tmp);
        $written = $catalog->materialize($catalog->require('demo'), $dest);

        sort($written);
        $this->assertSame(['docker-compose.yml', 'nginx.conf'], $written);
        $this->assertFileExists($dest . '/docker-compose.yml');
        $this->assertSame("server {\n  listen 80;\n}\n", (string) file_get_contents($dest . '/nginx.conf'));
        $this->assertSame('docker-compose.yml', ComposeSource::detectMainFile($dest));
    }

    public function testMaterializeRejectsInvalidTemplate(): void
    {
        $this->writeTemplate('broken', $this->manifest(), "services:\n  web:\n    build: .\n");
        $dest = $this->tmp . '/dest';
        mkdir($dest, 0777, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak valid/');

        (new TemplateCatalog($this->tmp))->materialize((new TemplateCatalog($this->tmp))->find('broken') ?? [], $dest);
    }

    // ==================================================================
    // Template bawaan repo
    // ==================================================================

    public function testShippedTemplatesAreValid(): void
    {
        $shipped = new TemplateCatalog(dirname(__DIR__) . '/templates');
        $templates = $shipped->all();

        $this->assertNotSame([], $templates, 'Tidak ada template bawaan di folder templates/.');

        foreach ($templates as $template) {
            $this->assertTrue(
                $template['valid'],
                'Template "' . $template['slug'] . '" tidak valid: ' . (string) $template['error']
            );
            $this->assertNotSame('', $template['primary']['service']);
            $this->assertGreaterThan(0, $template['primary']['port']);
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    private function manifest(): string
    {
        return "title: Demo\ndescription: Demo template.\n";
    }

    /**
     * Compose minimal (image prebuilt + port) dengan environment opsional.
     *
     * @param array<int,string> $envKeys
     */
    private function composeWithEnv(array $envKeys): string
    {
        $lines = [
            'services:',
            '  web:',
            '    image: nginx:alpine',
            '    ports:',
            '      - "8080:80"',
        ];
        if ($envKeys !== []) {
            $lines[] = '    environment:';
            foreach ($envKeys as $key) {
                $lines[] = '      ' . $key . ': ${' . $key . '}';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,string> $files nama relatif => isi
     */
    private function writeTemplate(string $slug, string $manifest, string $compose, array $files = []): void
    {
        $dir = $this->tmp . '/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . TemplateCatalog::MANIFEST_FILE, $manifest);
        file_put_contents($dir . '/' . TemplateCatalog::COMPOSE_FILE, $compose);

        foreach ($files as $name => $content) {
            $path = $dir . '/' . TemplateCatalog::FILES_DIR . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $content);
        }
    }

    private function assertInvalid(string $slug, string $manifest, string $compose, string $errorPattern): void
    {
        $this->writeTemplate($slug, $manifest, $compose);

        $template = (new TemplateCatalog($this->tmp))->find($slug);

        $this->assertNotNull($template);
        $this->assertFalse($template['valid'], 'Template seharusnya ditolak.');
        $this->assertMatchesRegularExpression($errorPattern, (string) $template['error']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
