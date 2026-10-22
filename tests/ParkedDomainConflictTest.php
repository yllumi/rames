<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\SubdomainManager;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

// Helper webman (`config()`, `app_subdomain*()`) tidak dimuat di luar runtime
// webman: definisikan konstanta + fungsi yang dibutuhkan (pola SubdomainTest).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain_of')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Regresi arah **app → domain parkir**: subdomain/custom domain app tidak boleh
 * memakai FQDN yang sudah terparkir (kalau tidak, dua `server_name` identik
 * ditulis ke Nginx). Arah sebaliknya sudah dijaga
 * {@see \app\library\Domain\ParkedDomainValidator::assertDomainAvailable()}.
 *
 * Semua store memakai berkas .sqlite temp — `database/` nyata tidak disentuh.
 */
class ParkedDomainConflictTest extends TestCase
{
    private string $file;
    private AppStore $apps;
    private ParkedDomainStore $parked;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-parked-conflict-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->apps = new AppStore($this->file);
        $this->parked = new ParkedDomainStore($this->file);
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->file);
    }

    private function manager(?ParkedDomainStore $parked): SubdomainManager
    {
        return new SubdomainManager($this->apps, null, null, $parked);
    }

    public function testAssertAvailableRejectsSubdomainWhoseFqdnIsParked(): void
    {
        $this->parked->create(['domain' => 'Blog.Example.com', 'mode' => 'html']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/blog\.example\.com sudah dipakai sebagai domain parkir/');
        $this->manager($this->parked)->assertAvailable('blog', 'other');
    }

    public function testAssertAvailableRejectsFallbackNameWhoseFqdnIsParked(): void
    {
        // Label kosong ⇒ FQDN efektif memakai `name`; tetap harus dicek parkir.
        $this->parked->create(['domain' => 'myapp.example.com', 'mode' => 'redirect', 'target' => 'https://example.org']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/myapp\.example\.com sudah dipakai sebagai domain parkir/');
        $this->manager($this->parked)->assertAvailable('', 'myapp');
    }

    public function testParkedCheckIsSkippedWhenStoreNotInjected(): void
    {
        // Backward-compat: pemanggil/tes lama tanpa store parkir tidak berubah.
        $this->parked->create(['domain' => 'blog.example.com', 'mode' => 'html']);

        $this->assertSame('blog.example.com', $this->manager(null)->assertAvailable('blog', 'other'));
    }

    public function testDifferentParkedDomainDoesNotBlock(): void
    {
        $this->parked->create(['domain' => 'lain.example.com', 'mode' => 'html']);

        $this->assertSame('blog.example.com', $this->manager($this->parked)->assertAvailable('blog', 'other'));
    }

    public function testAppToAppConflictStillRejected(): void
    {
        // Regresi: pemeriksaan parkir tidak boleh melonggarkan cek app ↔ app.
        $this->apps->create(['name' => 'alpha']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/alpha\.example\.com sudah dipakai app/');
        $this->manager($this->parked)->assertAvailable('alpha', 'beta');
    }

    public function testChangeReturnsErrorWhenTargetFqdnIsParked(): void
    {
        $app = $this->apps->create([
            'name' => 'bloggy',
            'containers' => [
                ['service_name' => 'web', 'container_name' => 'bloggy', 'ports' => [['host' => 30000, 'container' => 80]]],
            ],
        ]);
        $this->parked->create(['domain' => 'khusus.example.com', 'mode' => 'html']);

        $result = $this->manager($this->parked)->change((string) $app['id'], 'khusus');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('domain parkir', $result['error']);
        $this->assertStringContainsString('/domains', $result['error']);
    }
}
