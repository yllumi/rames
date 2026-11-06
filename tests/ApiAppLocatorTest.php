<?php
declare(strict_types=1);

namespace Tests;

use app\library\Api\AmbiguousAppSlug;
use app\library\Api\ApiAppLocator;
use app\library\Auth\AppAccess;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\StoreDbFiles;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Resolusi app berdasarkan nama (slug) untuk API eksternal.
 *
 * Kontrak: hanya app yang **terlihat** aktor (`AppAccess::visible()`), slug
 * dinormalkan seperti form create, bentuk slug tidak sah ⇒ `null`, dan nama
 * ganda (multi-server) dilempar sebagai {@see AmbiguousAppSlug} — tidak ditebak.
 */
class ApiAppLocatorTest extends TestCase
{
    private const ADMIN = ['id' => 'u-admin', 'username' => 'admin', 'role' => 'admin'];
    private const OWNER = ['id' => 'u-owner', 'username' => 'owner', 'role' => 'member'];
    private const STRANGER = ['id' => 'u-stranger', 'username' => 'stranger', 'role' => 'member'];

    private string $db = '';

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/rames-apilocator-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
    }

    public function testFindsAppOwnedByActor(): void
    {
        $store = $this->store();
        $app = $store->create(['name' => 'demo', 'owner_id' => self::OWNER['id'], 'status' => 'running']);

        $found = (new ApiAppLocator($store))->findVisible('demo', self::OWNER);

        $this->assertNotNull($found);
        $this->assertSame($app['id'], $found['id']);
    }

    public function testNormalizesSlugLikeCreateForm(): void
    {
        $store = $this->store();
        $store->create(['name' => 'demo', 'owner_id' => self::OWNER['id'], 'status' => 'running']);

        $this->assertNotNull((new ApiAppLocator($store))->findVisible('  DEMO  ', self::ADMIN));
    }

    public function testInvisibleAppIsNotFound(): void
    {
        $store = $this->store();
        $store->create(['name' => 'demo', 'owner_id' => self::OWNER['id'], 'status' => 'running']);

        $this->assertNull((new ApiAppLocator($store))->findVisible('demo', self::STRANGER));
        $this->assertNull((new ApiAppLocator($store))->findVisible('demo', null));
    }

    public function testUnsafeSlugShapesAreRejected(): void
    {
        $store = $this->store();
        $store->create(['name' => 'demo', 'owner_id' => self::OWNER['id'], 'status' => 'running']);
        $locator = new ApiAppLocator($store);

        foreach (['', '../demo', 'demo/../x', 'demo x', '-demo', 'demo-', str_repeat('a', 64)] as $slug) {
            $this->assertNull($locator->findVisible($slug, self::ADMIN), 'slug tidak sah: ' . $slug);
        }
        $this->assertTrue(ApiAppLocator::isValidSlug('demo'));
        $this->assertTrue(ApiAppLocator::isValidSlug('demo-app-2'));
    }

    public function testAmbiguousNameAcrossServersIsRejected(): void
    {
        $store = $this->store();
        $store->create(['name' => 'dup', 'owner_id' => self::OWNER['id'], 'status' => 'running']);
        $store->create([
            'name' => 'dup',
            'owner_id' => self::OWNER['id'],
            'status' => 'running',
            'server_id' => 'server-agent-1',
        ]);

        $this->expectException(AmbiguousAppSlug::class);
        (new ApiAppLocator($store))->findVisible('dup', self::ADMIN);
    }

    public function testAmbiguousNameIsResolvedByVisibility(): void
    {
        $store = $this->store();
        $store->create(['name' => 'dup', 'owner_id' => self::OWNER['id'], 'status' => 'running']);
        $store->create([
            'name' => 'dup',
            'owner_id' => self::STRANGER['id'],
            'status' => 'running',
            'server_id' => 'server-agent-1',
        ]);

        // Owner hanya melihat app miliknya ⇒ tidak ambigu, tidak bocor.
        $found = (new ApiAppLocator($store))->findVisible('dup', self::OWNER);
        $this->assertNotNull($found);
        $this->assertSame(self::OWNER['id'], $found['owner_id']);
        $this->assertSame(
            'owner',
            AppAccess::roleFor($found, self::OWNER)
        );
    }

    private function store(): AppStore
    {
        return new AppStore($this->db);
    }
}
