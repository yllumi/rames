<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AgentJobController;
use app\library\Agent\AgentAppProjection;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * `custom_domain` dalam proyeksi app agent (misi F1 celah C).
 *
 * Sebelumnya `AgentJobController::create()` menyalin `custom_domain` **sendiri**
 * ke store agent karena field itu belum ada di {@see AgentAppProjection::METADATA}.
 * Dua daftar field seperti itu gampang menyimpang; sekarang satu tempat:
 *
 *  1. `custom_domain` **ada** di `METADATA` (metadata control plane → proyeksi
 *     ditimpa dari payload job, termasuk pengosongan dengan `null`);
 *  2. `custom_domain` **tidak** ada di `EXECUTION` (agent tidak boleh menjadi
 *     penulis field itu — himpunan metadata vs state eksekusi tetap disjoint);
 *  3. controller tidak lagi memuat salinan manual (audit statik badan `create()`).
 *
 * Basis data SQLite **temp**; `database/rames.sqlite` nyata tidak disentuh.
 */
class AgentAppProjectionCustomDomainTest extends TestCase
{
    private const APP_ID = 'a1b2c3d4e5f60718';

    private string $tmp = '';
    private AgentAppProjection $projection;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_projection_custom_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }
        $this->projection = new AgentAppProjection($this->tmp . '/rames.sqlite');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            is_dir($file) || @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testCustomDomainIsProjectedMetadataNotExecutionState(): void
    {
        $this->assertContains('custom_domain', AgentAppProjection::METADATA);
        $this->assertNotContains('custom_domain', AgentAppProjection::EXECUTION);
        $this->assertSame(
            [],
            array_intersect(AgentAppProjection::METADATA, AgentAppProjection::EXECUTION),
            'metadata dan state eksekusi wajib disjoint'
        );
    }

    public function testUpsertCopiesCustomDomainFromPayload(): void
    {
        $stored = $this->projection->upsert([
            'id' => self::APP_ID,
            'name' => 'demo',
            'custom_domain' => 'app.example.org',
        ]);

        $this->assertSame('app.example.org', $stored['custom_domain']);
        $this->assertSame('app.example.org', (new AppStore($this->projection->path()))->find(self::APP_ID)['custom_domain']);
    }

    public function testUpsertRefreshesAndClearsCustomDomainForExistingApp(): void
    {
        $this->projection->upsert(['id' => self::APP_ID, 'name' => 'demo', 'custom_domain' => 'app.example.org']);

        // Perubahan domain domain (control plane menang)…
        $this->projection->upsert(['id' => self::APP_ID, 'name' => 'demo', 'custom_domain' => 'baru.example.org']);
        $this->assertSame(
            'baru.example.org',
            (new AppStore($this->projection->path()))->find(self::APP_ID)['custom_domain']
        );

        // …dan pengosongan eksplisit (`null`) juga ikut diproyeksikan.
        $this->projection->upsert(['id' => self::APP_ID, 'name' => 'demo', 'custom_domain' => null]);
        $stored = (new AppStore($this->projection->path()))->find(self::APP_ID);
        $this->assertArrayHasKey('custom_domain', $stored);
        $this->assertNull($stored['custom_domain']);
    }

    /** Field absen pada payload tidak mengosongkan nilai lama (payload parsial). */
    public function testUpsertKeepsCustomDomainWhenPayloadOmitsIt(): void
    {
        $this->projection->upsert(['id' => self::APP_ID, 'name' => 'demo', 'custom_domain' => 'app.example.org']);

        $this->projection->upsert(['id' => self::APP_ID, 'name' => 'demo']);

        $this->assertSame(
            'app.example.org',
            (new AppStore($this->projection->path()))->find(self::APP_ID)['custom_domain']
        );
    }

    /**
     * Audit statik: `POST /jobs` tidak boleh menyalin `custom_domain` sendiri lagi
     * (satu daftar field = proyeksi). `APP_FIELDS` (whitelist respons
     * `GET /apps/{id}`) tetap boleh memuatnya.
     */
    public function testJobControllerNoLongerCopiesCustomDomainManually(): void
    {
        $reflection = new ReflectionMethod(AgentJobController::class, 'create');
        $lines = (array) file((string) $reflection->getFileName());
        $body = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        $this->assertStringNotContainsString("['custom_domain']", $body, 'proyeksi yang menangani metadata, bukan controller');
        $this->assertStringNotContainsString('$customDomain', $body, 'salinan manual custom_domain harus hilang');
        $this->assertStringContainsString('projection()->upsert(', $body);
    }
}
