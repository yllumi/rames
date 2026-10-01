<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\AppAccessDenied;
use app\library\Backup\BackupAccess;
use app\library\Backup\VolumeTargetMap;
use PHPUnit\Framework\TestCase;

/**
 * Test satu pintu otorisasi backup (`BackupAccess`) — PLAN_VOLUME_BACKUP.md §5.1.
 *
 * Murni data (tanpa Docker/restic/berkas runtime nyata):
 *  - non-admin tidak melihat volume app orang lain;
 *  - volume yatim hanya admin;
 *  - `backup` ditolak viewer, `restore` ditolak operator.
 */
class BackupVisibilityTest extends TestCase
{
    private const OWNER = ['id' => 'u1', 'username' => 'owner', 'role' => 'member'];
    private const OPERATOR = ['id' => 'u2', 'username' => 'operator', 'role' => 'member'];
    private const VIEWER = ['id' => 'u3', 'username' => 'viewer', 'role' => 'member'];
    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];
    private const STRANGER = ['id' => 'u8', 'username' => 'stranger', 'role' => 'member'];

    /**
     * @return array<int,array>
     */
    private function apps(): array
    {
        return [
            [
                'id' => 'app1',
                'name' => 'tonidata',
                'owner_id' => 'u1',
                'members' => [
                    'u2' => ['role' => 'operator'],
                    'u3' => ['role' => 'viewer'],
                ],
            ],
            [
                'id' => 'app2',
                'name' => 'waha',
                'owner_id' => 'u7',
                'members' => [],
            ],
        ];
    }

    /**
     * @return array<int,array>
     */
    private function volumes(): array
    {
        return [
            ['Name' => 'tonidata_data', 'Labels' => ['com.docker.compose.project' => 'tonidata']],
            ['Name' => 'waha_data', 'Labels' => ['com.docker.compose.project' => 'waha']],
            ['Name' => 'ghost_data', 'Labels' => ['com.docker.compose.project' => 'ghost']],
        ];
    }

    /**
     * @return array<int,array{name:string,project:string,app_id:?string,app_name:?string,orphaned:bool}>
     */
    private function targets(): array
    {
        return VolumeTargetMap::build($this->volumes(), $this->apps());
    }

    /**
     * @param array<int,array> $targets
     * @return array<string,array>
     */
    private function byName(array $targets): array
    {
        $map = [];
        foreach ($targets as $target) {
            $map[(string) $target['name']] = $target;
        }
        return $map;
    }

    private function app(string $id): array
    {
        foreach ($this->apps() as $app) {
            if ((string) $app['id'] === $id) {
                return $app;
            }
        }
        $this->fail("app {$id} tidak ada di fixture");
    }

    // ==================================================================
    // Visibilitas (filter)
    // ==================================================================

    public function testNonAdminCannotSeeVolumesOfOtherApps(): void
    {
        $visible = BackupAccess::visible($this->targets(), $this->apps(), self::OWNER);
        $names = array_column($visible, 'name');

        $this->assertContains('tonidata_data', $names);
        $this->assertNotContains('waha_data', $names, 'volume app user lain tidak boleh bocor');
        $this->assertNotContains('ghost_data', $names, 'volume yatim bukan untuk non-admin');
    }

    public function testViewerStillSeesOwnAppVolume(): void
    {
        $visible = BackupAccess::visible($this->targets(), $this->apps(), self::VIEWER);
        $this->assertSame(['tonidata_data'], array_column($visible, 'name'));
    }

    public function testStrangerSeesNothing(): void
    {
        $this->assertSame([], BackupAccess::visible($this->targets(), $this->apps(), self::STRANGER));
    }

    public function testAdminSeesEverythingIncludingOrphans(): void
    {
        $visible = BackupAccess::visible($this->targets(), $this->apps(), self::ADMIN);
        $names = array_column($visible, 'name');
        sort($names);

        $this->assertSame(['ghost_data', 'tonidata_data', 'waha_data'], $names);
    }

    // ==================================================================
    // Volume yatim → hanya admin
    // ==================================================================

    public function testOrphanVolumeRequiresAdmin(): void
    {
        $target = $this->byName($this->targets())['ghost_data'];
        $this->assertTrue($target['orphaned']);
        $this->assertNull($target['app_id']);

        $this->assertFalse(BackupAccess::can('backup', $target, null, self::OWNER));
        $this->assertTrue(BackupAccess::can('backup', $target, null, self::ADMIN));
        $this->assertTrue(BackupAccess::can('restore', $target, null, self::ADMIN));

        $this->expectException(AppAccessDenied::class);
        BackupAccess::require('backup', $target, null, self::OWNER);
    }

    // ==================================================================
    // Ability per role
    // ==================================================================

    public function testBackupDeniedForViewerAllowedForOperator(): void
    {
        $target = $this->byName($this->targets())['tonidata_data'];
        $app = $this->app('app1');

        $this->assertFalse(BackupAccess::can('backup', $target, $app, self::VIEWER));
        $this->assertTrue(BackupAccess::can('backup', $target, $app, self::OPERATOR));
        $this->assertTrue(BackupAccess::can('backup', $target, $app, self::OWNER));
        $this->assertTrue(BackupAccess::can('backup', $target, $app, self::ADMIN));

        $this->expectException(AppAccessDenied::class);
        BackupAccess::require('backup', $target, $app, self::VIEWER);
    }

    public function testRestoreDeniedForOperatorAllowedForOwner(): void
    {
        $target = $this->byName($this->targets())['tonidata_data'];
        $app = $this->app('app1');

        $this->assertFalse(BackupAccess::can('restore', $target, $app, self::OPERATOR));
        $this->assertTrue(BackupAccess::can('restore', $target, $app, self::OWNER));
        $this->assertTrue(BackupAccess::can('restore', $target, $app, self::ADMIN));

        $this->expectException(AppAccessDenied::class);
        BackupAccess::require('restore', $target, $app, self::OPERATOR);
    }

    public function testTargetWithoutAppIsDenied(): void
    {
        $target = $this->byName($this->targets())['tonidata_data'];

        // App tidak ditemukan → jangan menebak pemiliknya.
        $this->assertFalse(BackupAccess::can('view', $target, null, self::OWNER));

        $this->expectException(AppAccessDenied::class);
        BackupAccess::require('view', $target, null, self::OWNER);
    }

    public function testVisibleAbilityAllowsViewer(): void
    {
        $target = $this->byName($this->targets())['tonidata_data'];
        $app = $this->app('app1');

        $this->assertTrue(BackupAccess::can('view', $target, $app, self::VIEWER));
        $this->assertTrue(BackupAccess::can('logs', $target, $app, self::VIEWER));
    }

    public function testIsAdminDelegatesToAppAccess(): void
    {
        $this->assertTrue(BackupAccess::isAdmin(self::ADMIN));
        $this->assertFalse(BackupAccess::isAdmin(self::OWNER));
        $this->assertFalse(BackupAccess::isAdmin(null));
    }

    public function testAllowedProjectsMatchesVisibleApps(): void
    {
        $this->assertSame(['tonidata'], BackupAccess::allowedProjects($this->apps(), self::OPERATOR));
        $this->assertSame([], BackupAccess::allowedProjects($this->apps(), self::STRANGER));
    }
}
