<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupStrategyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Test BackupStrategyResolver — aturan D1: dump untuk container DB hidup,
 * snapshot untuk sisanya (termasuk fallback saat container DB mati).
 */
class BackupStrategyResolverTest extends TestCase
{
    public function testRunningDbContainerYieldsDump(): void
    {
        $strategy = BackupStrategyResolver::resolve([
            ['name' => 'tonidata-db-1', 'state' => 'running', 'is_db' => true],
        ]);

        $this->assertSame(BackupStrategyResolver::STRATEGY_DUMP, $strategy);
    }

    public function testStoppedDbContainerFallsBackToSnapshot(): void
    {
        $strategy = BackupStrategyResolver::resolve([
            ['name' => 'tonidata-db-1', 'state' => 'exited', 'is_db' => true],
        ]);

        $this->assertSame(BackupStrategyResolver::STRATEGY_SNAPSHOT, $strategy);
    }

    public function testNonDbContainerYieldsSnapshot(): void
    {
        $strategy = BackupStrategyResolver::resolve([
            ['name' => 'tonidata-app-1', 'state' => 'running', 'is_db' => false],
        ]);

        $this->assertSame(BackupStrategyResolver::STRATEGY_SNAPSHOT, $strategy);
    }

    public function testNoContainersYieldsSnapshot(): void
    {
        $this->assertSame(BackupStrategyResolver::STRATEGY_SNAPSHOT, BackupStrategyResolver::resolve([]));
    }

    public function testDisabledDumpForcesSnapshotEvenWhenDbIsRunning(): void
    {
        $strategy = BackupStrategyResolver::resolve([
            ['name' => 'tonidata-db-1', 'state' => 'running', 'is_db' => true],
        ], false);

        $this->assertSame(BackupStrategyResolver::STRATEGY_SNAPSHOT, $strategy);
    }

    public function testAliasesAreAccepted(): void
    {
        // 'db' sebagai alias is_db, 'running' eksplisit, 'State' gaya inspect
        $this->assertSame(BackupStrategyResolver::STRATEGY_DUMP, BackupStrategyResolver::resolve([
            ['State' => 'exited', 'running' => true, 'db' => true],
        ]));
    }

    public function testRunningOnlyCountsExactRunningState(): void
    {
        foreach (['created', 'paused', 'dead', 'exited', 'removing', ''] as $state) {
            $this->assertSame(
                BackupStrategyResolver::STRATEGY_SNAPSHOT,
                BackupStrategyResolver::resolve([['state' => $state, 'is_db' => true]]),
                "state \"{$state}\" tidak boleh dianggap hidup"
            );
        }
    }
}
