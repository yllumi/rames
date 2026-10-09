<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\VolumeTargetMap;
use PHPUnit\Framework\TestCase;

/**
 * Test VolumeTargetMap — pemetaan volume ber-label compose → target backup
 * (termasuk penandaan volume yatim). Statik murni: tanpa Engine, tanpa I/O.
 */
class VolumeTargetMapTest extends TestCase
{
    /**
     * @return array<int,array>
     */
    private function apps(): array
    {
        return [
            ['id' => 'app-1', 'name' => 'tonidata', 'compose_files' => ['docker-compose.yml']],
            ['id' => 'app-2', 'name' => 'waha'],
        ];
    }

    public function testMapsLabelledVolumeToOwningApp(): void
    {
        $targets = VolumeTargetMap::build([
            [
                'Name' => 'tonidata_data',
                'Labels' => ['com.docker.compose.project' => 'tonidata'],
            ],
        ], $this->apps());

        $this->assertSame([
            [
                'name' => 'tonidata_data',
                'project' => 'tonidata',
                'app_id' => 'app-1',
                'app_name' => 'tonidata',
                'orphaned' => false,
            ],
        ], $targets);
    }

    public function testUnlabelledNamelessAndScopelessVolumesAreSkipped(): void
    {
        $targets = VolumeTargetMap::build([
            ['Name' => '', 'Labels' => ['com.docker.compose.project' => 'tonidata']],
            ['Name' => 'anonymous'],
            ['Name' => 'no_labels', 'Labels' => null],
            ['Name' => 'other_label', 'Labels' => ['custom' => 'x']],
            ['Name' => 'db_data', 'Labels' => ['com.docker.compose.project' => 'waha']],
        ], $this->apps());

        $this->assertSame(['db_data'], VolumeTargetMap::names($targets));
        $this->assertSame('waha', $targets[0]['project']);
    }

    public function testVolumeOfDeletedAppIsOrphaned(): void
    {
        $targets = VolumeTargetMap::build([
            ['Name' => 'gone_data', 'Labels' => ['com.docker.compose.project' => 'gone']],
        ], $this->apps());

        $this->assertCount(1, $targets);
        $this->assertTrue($targets[0]['orphaned']);
        $this->assertNull($targets[0]['app_id']);
        $this->assertNull($targets[0]['app_name']);
        $this->assertSame('gone', $targets[0]['project']);
    }

    public function testDuplicateNamesAreDeduplicatedAndResultIsSorted(): void
    {
        $targets = VolumeTargetMap::build([
            ['Name' => 'waha_data', 'Labels' => ['com.docker.compose.project' => 'waha']],
            ['Name' => 'tonidata_data', 'Labels' => ['com.docker.compose.project' => 'tonidata']],
            ['Name' => 'waha_data', 'Labels' => ['com.docker.compose.project' => 'waha']],
            ['Name' => 'tonidata_logs', 'Labels' => ['com.docker.compose.project' => 'tonidata']],
        ], $this->apps());

        $this->assertSame(['tonidata_data', 'tonidata_logs', 'waha_data'], VolumeTargetMap::names($targets));
    }

    public function testFilterAccessibleExcludesForeignAndOrphanedVolumes(): void
    {
        $targets = VolumeTargetMap::build([
            ['Name' => 'mine_data', 'Labels' => ['com.docker.compose.project' => 'tonidata']],
            ['Name' => 'theirs_data', 'Labels' => ['com.docker.compose.project' => 'waha']],
            ['Name' => 'gone_data', 'Labels' => ['com.docker.compose.project' => 'gone']],
        ], $this->apps());

        $user = VolumeTargetMap::filterAccessible($targets, ['tonidata'], false);
        $this->assertSame(['mine_data'], VolumeTargetMap::names($user));

        // admin: app yang bisa diakses + volume yatim (keputusan #7)
        $admin = VolumeTargetMap::filterAccessible($targets, ['tonidata', 'waha'], true);
        $this->assertSame(['gone_data', 'mine_data', 'theirs_data'], VolumeTargetMap::names($admin));
    }

    /**
     * Volume database dashboard (`rames.role=dashboard-db`) lolos filter label
     * compose tetapi **bukan** volume app → wajib tidak pernah jadi target
     * backup/restore/schedule (dengan kebijakan snapshot `stop` + `require_stopped`
     * ia bisa menghentikan container dashboard, yaitu proses yang menjalankan
     * backup itu sendiri).
     */
    public function testDashboardVolumeIsNeverATarget(): void
    {
        $targets = VolumeTargetMap::build([
            [
                'Name' => 'rames',
                'Labels' => [
                    'com.docker.compose.project' => 'rames',
                    VolumeTargetMap::DASHBOARD_VOLUME_LABEL_KEY => VolumeTargetMap::DASHBOARD_VOLUME_LABEL_VALUE,
                ],
            ],
            ['Name' => 'tonidata_data', 'Labels' => ['com.docker.compose.project' => 'tonidata']],
        ], $this->apps());

        $this->assertSame(['tonidata_data'], VolumeTargetMap::names($targets), 'volume app tetap masuk, volume DB dashboard tidak');
        $this->assertSame('tonidata', $targets[0]['app_name']);
    }

    /**
     * Volume dashboard tetap dikecualikan meski project-nya cocok dengan nama
     * app, dan label `rames.role` dengan nilai lain **tidak** dikecualikan
     * (filter presisi, bukan cocok-prefiks).
     */
    public function testDashboardExclusionIsPrecise(): void
    {
        $dashboard = [
            'Name' => 'rames',
            'Labels' => [
                'com.docker.compose.project' => 'tonidata',
                VolumeTargetMap::DASHBOARD_VOLUME_LABEL_KEY => VolumeTargetMap::DASHBOARD_VOLUME_LABEL_VALUE,
            ],
        ];
        $otherRole = [
            'Name' => 'lain_data',
            'Labels' => [
                'com.docker.compose.project' => 'tonidata',
                VolumeTargetMap::DASHBOARD_VOLUME_LABEL_KEY => 'volume-app',
            ],
        ];

        $this->assertTrue(VolumeTargetMap::isDashboardVolume($dashboard));
        $this->assertFalse(VolumeTargetMap::isDashboardVolume($otherRole));
        $this->assertFalse(VolumeTargetMap::isDashboardVolume(['Name' => 'x']), 'tanpa Labels → bukan volume dashboard');

        $targets = VolumeTargetMap::build([$dashboard, $otherRole], $this->apps());
        $this->assertSame(['lain_data'], VolumeTargetMap::names($targets));
    }
}
