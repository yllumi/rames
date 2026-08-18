<?php
declare(strict_types=1);

namespace Tests;

use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test AppStore (persistensi apps.json) — termasuk deploy_history.
 */
class AppStoreTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/rames-apps-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '.bak'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function testCreateFindUpdateDeleteWithDeployHistory(): void
    {
        $store = new AppStore($this->file);

        $app = $store->create([
            'name' => 'myapp',
            'subdomain' => 'myapp.example.com',
            'status' => 'running',
            'deploy_history' => [],
        ]);
        $this->assertNotEmpty($app['id']);
        $this->assertNotEmpty($app['created_at']);

        $found = $store->find($app['id']);
        $this->assertSame('myapp', $found['name']);
        $this->assertTrue($store->nameExists('myapp'));

        // update & persist deploy_history
        $store->update($app['id'], function (array &$s): void {
            $s['deploy_history'][] = [
                'sha' => 'abc1234',
                'short' => 'abc1234',
                'action' => 'rebuild',
                'status' => 'success',
                'created_at' => date('c'),
            ];
        });

        $updated = $store->find($app['id']);
        $this->assertCount(1, $updated['deploy_history']);
        $this->assertSame('success', $updated['deploy_history'][0]['status']);

        // persist antar-instance (baca dari store baru)
        $fresh = new AppStore($this->file);
        $this->assertCount(1, $fresh->find($app['id'])['deploy_history']);

        $store->delete($app['id']);
        $this->assertNull($store->find($app['id']));
    }

    public function testDuplicateNameRejected(): void
    {
        $store = new AppStore($this->file);
        $store->create(['name' => 'dup', 'status' => 'running']);

        $this->expectException(RuntimeException::class);
        $store->create(['name' => 'dup', 'status' => 'running']);
    }
}
