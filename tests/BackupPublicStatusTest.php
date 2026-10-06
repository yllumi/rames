<?php
declare(strict_types=1);

namespace Tests;

use app\controller\BackupController;
use PHPUnit\Framework\TestCase;

/**
 * Test `BackupController::publicStatus()` — daftar-putih status run untuk
 * **semua** user login.
 *
 * `readStatus()` memuat `volumes[]` (hasil run terakhir) & `error` yang bisa
 * menyebut nama/error volume milik app lain; keduanya WAJIB dibuang sebelum
 * dikirim ke non-admin. Kunci aman (running/started_at/finished_at/duration_ms/
 * status/trigger/totals) dipertahankan apa adanya.
 *
 * Murni data — tanpa I/O, session, atau berkas runtime.
 */
class BackupPublicStatusTest extends TestCase
{
    public function testDropsVolumesAndErrorKeepsWhitelist(): void
    {
        $out = BackupController::publicStatus([
            'run_id' => 'r1',
            'trigger' => 'schedule',
            'host' => 'rames',
            'status' => 'ok',
            'running' => false,
            'started_at' => '2026-10-05T00:00:00+00:00',
            'finished_at' => '2026-10-05T00:01:00+00:00',
            'duration_ms' => 60000,
            'totals' => ['volumes' => 2, 'ok' => 2],
            'summary' => ['ok' => 2],
            'volumes' => [['name' => 'other_app_data', 'error' => 'boom']],
            'error' => 'gagal di volume other_app_data',
        ]);

        $this->assertArrayNotHasKey('volumes', $out, 'volumes[] bocor nama/error volume app lain');
        $this->assertArrayNotHasKey('error', $out, 'error bocor ke non-admin');
        $this->assertArrayNotHasKey('run_id', $out);
        $this->assertArrayNotHasKey('host', $out);
        $this->assertArrayNotHasKey('summary', $out);

        $this->assertSame([
            'running' => false,
            'started_at' => '2026-10-05T00:00:00+00:00',
            'finished_at' => '2026-10-05T00:01:00+00:00',
            'duration_ms' => 60000,
            'status' => 'ok',
            'trigger' => 'schedule',
            'totals' => ['volumes' => 2, 'ok' => 2],
        ], $out);
    }

    public function testEmptyStatusYieldsEmptyArray(): void
    {
        $this->assertSame([], BackupController::publicStatus([]));
    }

    public function testOnlySensitiveKeysYieldEmptyArray(): void
    {
        $out = BackupController::publicStatus([
            'volumes' => [['name' => 'a']],
            'error' => 'x',
        ]);

        $this->assertSame([], $out, 'status tanpa kunci aman menjadi [] (controller memetakan ke stdClass)');
    }

    public function testKeepsPresentKeysOnly(): void
    {
        $out = BackupController::publicStatus(['running' => true, 'volumes' => []]);

        $this->assertSame(['running' => true], $out);
    }
}
