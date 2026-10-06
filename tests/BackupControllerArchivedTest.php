<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use PHPUnit\Framework\TestCase;

/**
 * Test `BackupController::archivedRows()` — baris arsip registry untuk respons
 * `/backups` (status & refresh).
 *
 * Keputusan 4a: tampilan arsip **admin-only** → non-admin selalu `[]`. Tiap
 * baris admin diberi `restorable` (strategi `snapshot` **dan** `snapshots > 0`).
 * Murni data (tanpa I/O) — cocok diuji tanpa berkas/Engine.
 */
class BackupControllerArchivedTest extends TestCase
{
    /**
     * @return array<int,array<string,mixed>>
     */
    private function entries(): array
    {
        return [
            ['name' => 'snap_data', 'project' => 'snap', 'strategy' => 'snapshot', 'snapshots' => 2],
            ['name' => 'empty_data', 'project' => 'empty', 'strategy' => 'snapshot', 'snapshots' => 0],
            ['name' => 'dump_data', 'project' => 'dump', 'strategy' => 'dump', 'snapshots' => 1],
            ['name' => 'gone_data', 'project' => 'gone', 'strategy' => 'snapshot', 'last_snapshot' => 'aaa'],
        ];
    }

    public function testNonAdminGetsEmptyArchived(): void
    {
        $this->assertSame([], BackupController::archivedRows($this->entries(), false));
    }

    public function testAdminGetsRowsInOrderWithRestorableFlag(): void
    {
        $out = BackupController::archivedRows($this->entries(), true);

        $this->assertCount(4, $out);
        $this->assertSame('snap_data', $out[0]['name']);
        $this->assertSame('empty_data', $out[1]['name']);
        $this->assertSame('dump_data', $out[2]['name']);
        $this->assertSame('gone_data', $out[3]['name']);

        $this->assertTrue($out[0]['restorable'], 'snapshot dengan snapshots>0 dapat direstore');
        $this->assertFalse($out[1]['restorable'], 'snapshot tanpa snapshot tidak dapat direstore');
        $this->assertFalse($out[2]['restorable'], 'strategi dump bukan arsip snapshot');
        $this->assertFalse($out[3]['restorable'], 'snapshots absen dianggap 0');
    }

    public function testAdminRowPreservesOriginalKeys(): void
    {
        $out = BackupController::archivedRows($this->entries(), true);

        $this->assertSame('snap', $out[0]['project']);
        $this->assertSame(2, $out[0]['snapshots']);
        $this->assertSame('aaa', $out[3]['last_snapshot']);
    }

    public function testEmptyInputYieldsEmptyArray(): void
    {
        $this->assertSame([], BackupController::archivedRows([], true));
        $this->assertSame([], BackupController::archivedRows([], false));
    }
}
