<?php
declare(strict_types=1);

namespace Tests;

use app\library\Template\TemplateCatalog;
use PHPUnit\Framework\TestCase;

/**
 * `TemplateCatalog::images()` — sumber daftar image untuk tombol `docker pull`
 * di halaman Config (`/nginx` tab Template, docs/SPECS.md §7.13).
 *
 * Yang dikunci di sini:
 *  - image dibaca dari service hasil parse (bukan regex atas compose) sehingga
 *    baris **komentar** ber-`image:` (mis. contoh pin digest di template
 *    wabaileys) tidak ikut terbaca;
 *  - urutan service dipertahankan & duplikat dibuang;
 *  - service ber-`build:` / tanpa `image` dilewati.
 */
class TemplateImagesTest extends TestCase
{
    public function testShippedMultiImageTemplateListsAllImagesInServiceOrder(): void
    {
        $template = (new TemplateCatalog($this->shippedTemplates()))->find('outline');

        $this->assertNotNull($template);
        $this->assertTrue($template['valid'], (string) $template['error']);
        $this->assertSame(
            [
                'docker.getoutline.com/outlinewiki/outline:latest',
                'postgres:18',
                'redis:8-alpine',
            ],
            TemplateCatalog::images($template)
        );
    }

    public function testCommentedImageLinesAreNotRead(): void
    {
        // wabaileys memuat baris komentar `#   image: yllumi/wabaileys@sha256:<digest>`
        // di panduan pin digest — HANYA image service yang boleh terbaca.
        $template = (new TemplateCatalog($this->shippedTemplates()))->find('wabaileys');

        $this->assertNotNull($template);
        $this->assertSame(['yllumi/wabaileys:latest'], TemplateCatalog::images($template));
    }

    public function testEveryShippedTemplateHasAtLeastOneImage(): void
    {
        $catalog = new TemplateCatalog($this->shippedTemplates());
        $templates = $catalog->all();

        $this->assertNotSame([], $templates);
        foreach ($templates as $template) {
            $images = TemplateCatalog::images($template);
            $this->assertNotSame(
                [],
                $images,
                'Template "' . $template['slug'] . '" tanpa image prebuilt — tombol tarik image tidak akan ada gunanya.'
            );
        }
    }

    public function testDedupesRepeatedImagesAndKeepsOrder(): void
    {
        $images = TemplateCatalog::images(['services' => [
            'web' => ['image' => 'nginx:1'],
            'worker' => ['image' => 'app:latest'],
            'worker-2' => ['image' => 'nginx:1'],
            'meta' => ['image' => 'busybox:1'],
        ]]);

        $this->assertSame(['nginx:1', 'app:latest', 'busybox:1'], $images);
    }

    public function testSkipsBuildOnlyAndEmptyImageServices(): void
    {
        $images = TemplateCatalog::images(['services' => [
            'built' => ['build' => '.', 'image' => 'lokal:dev'],
            'empty' => ['image' => '   '],
            'tanpa-image' => ['internal_port' => 80],
            'bukan-array' => 'server',
            'nyata' => ['image' => 'mysql:8.4'],
        ]]);

        $this->assertSame(['mysql:8.4'], $images);
    }

    public function testMissingServicesYieldsEmptyList(): void
    {
        $this->assertSame([], TemplateCatalog::images([]));
        $this->assertSame([], TemplateCatalog::images(['services' => 'bukan-map']));
    }

    private function shippedTemplates(): string
    {
        return dirname(__DIR__) . '/templates';
    }
}
