<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupSelection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SqliteFixture;

/**
 * Test `BackupSelection` — flag seleksi backup berkala per volume, disimpan di
 * koleksi `volumes` pada store `backup` (basis data SQLite temp; tanpa
 * menyentuh data runtime).
 *
 * Kontrak: entri absen ⇒ **default OFF** (opt-in); set eksplisit ON/OFF
 * dipersistensikan lewat `SqliteStore`. `backfill()` menyalakan volume yang
 * sudah punya snapshot tanpa menimpa entri eksplisit.
 */
class BackupSelectionTest extends TestCase
{
    private string $tmp;
    private string $path;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backupsel_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->path = $this->tmp . '/rames.sqlite';
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Koleksi `volumes` tersimpan (isi store `backup`).
     *
     * @return array<string,array<string,mixed>>
     */
    private function stored(): array
    {
        $data = SqliteFixture::readAll($this->path, 'backup');
        $volumes = $data['volumes'] ?? [];

        return is_array($volumes) ? $volumes : [];
    }

    public function testAbsentEntryDefaultsToNotScheduled(): void
    {
        $selection = new BackupSelection($this->path);

        $this->assertFalse($selection->isScheduled('tonidata_data'), 'default OFF bila entri absen');
        $this->assertSame([], $selection->explicit(), 'entri default tidak diekspos');
    }

    public function testSetScheduledFalsePersistsAndDisables(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', false, 'u1');

        $this->assertFalse($selection->isScheduled('tonidata_data'));
        $this->assertSame(['tonidata_data' => false], $selection->explicit());

        // Instance baru membaca store yang sama (persistensi lintas-instance).
        $reloaded = new BackupSelection($this->path);
        $this->assertFalse($reloaded->isScheduled('tonidata_data'));

        $stored = $this->stored();
        $this->assertSame('u1', $stored['tonidata_data']['updated_by']);
        $this->assertArrayHasKey('updated_at', $stored['tonidata_data']);
    }

    public function testSetScheduledTrueIsExplicit(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('waha_data', true);

        $this->assertTrue($selection->isScheduled('waha_data'));
        $this->assertSame(['waha_data' => true], $selection->explicit());
    }

    public function testInvalidVolumeNameIsRejected(): void
    {
        $selection = new BackupSelection($this->path);

        $this->expectException(RuntimeException::class);
        $selection->setScheduled('../evil', false);
    }

    /**
     * `isScheduled()` dipanggil per baris volume — store harus dibaca SEKALI
     * per instance, bukan tiap panggilan.
     */
    public function testMemoizesStoreReadPerInstance(): void
    {
        SqliteFixture::backup($this->path, [
            'volumes' => ['tonidata_data' => ['scheduled' => false]],
        ]);

        $selection = new BackupSelection($this->path);
        $this->assertFalse($selection->isScheduled('tonidata_data'));

        // Ubah store di belakang instance. Bila memo bekerja, panggilan
        // berikutnya TIDAK membaca ulang ⇒ tetap memakai nilai lama.
        SqliteFixture::backup($this->path, [
            'volumes' => ['tonidata_data' => ['scheduled' => true]],
        ]);

        $this->assertFalse($selection->isScheduled('tonidata_data'), 'memo: tidak baca ulang');
        $this->assertSame(['tonidata_data' => false], $selection->explicit(), 'explicit() ikut memo');
    }

    /**
     * `setScheduled()` menyegarkan memo sehingga pembacaan berikutnya pada
     * instance yang sama sudah benar (tanpa instance baru).
     */
    public function testSetScheduledRefreshesMemo(): void
    {
        $selection = new BackupSelection($this->path);
        // Isi memo dulu (default OFF karena entri absen).
        $this->assertFalse($selection->isScheduled('tonidata_data'));

        $selection->setScheduled('tonidata_data', true, 'u1');
        $this->assertTrue($selection->isScheduled('tonidata_data'), 'memo disegarkan setelah set ON');
        $this->assertSame(['tonidata_data' => true], $selection->explicit());

        $selection->setScheduled('tonidata_data', false, 'u1');
        $this->assertFalse($selection->isScheduled('tonidata_data'), 'memo disegarkan setelah set OFF');
    }

    public function testBackfillMarksAbsentVolumesAsScheduled(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->backfill(['tonidata_data', 'waha_data']);

        $this->assertTrue($selection->isScheduled('tonidata_data'));
        $this->assertTrue($selection->isScheduled('waha_data'));
        $this->assertSame(['tonidata_data' => true, 'waha_data' => true], $selection->explicit());

        $data = $this->stored();
        $this->assertSame('system', $data['tonidata_data']['updated_by']);
        $this->assertArrayHasKey('updated_at', $data['tonidata_data']);
    }

    public function testBackfillDoesNotOverwriteExplicitEntries(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', false, 'u1');
        $selection->setScheduled('waha_data', true, 'u1');

        $selection->backfill(['tonidata_data', 'waha_data']);

        $this->assertFalse($selection->isScheduled('tonidata_data'), 'entri eksplisit OFF dipertahankan');
        $this->assertSame(['tonidata_data' => false, 'waha_data' => true], $selection->explicit());

        $data = $this->stored();
        $this->assertSame('u1', $data['tonidata_data']['updated_by'], 'tidak diubah sistem');
    }

    public function testBackfillIsIdempotent(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->backfill(['tonidata_data']);
        $first = $this->stored();

        $selection->backfill(['tonidata_data']);
        $second = $this->stored();

        $this->assertSame($first, $second, 'backfill kedua tidak mengubah store');
    }

    public function testBackfillIgnoresEmptyAndRejectsInvalidName(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->backfill(['']);
        $this->assertSame([], $this->stored(), 'nama kosong diabaikan (tanpa tulis)');

        $this->expectException(RuntimeException::class);
        $selection->backfill(['../evil']);
    }

    // ------------------------------------------------------------------
    // Mode retensi per volume (`snapshot` / `sync`)
    // ------------------------------------------------------------------

    public function testModeDefaultsToSnapshot(): void
    {
        $selection = new BackupSelection($this->path);

        $this->assertSame(BackupSelection::MODE_SNAPSHOT, BackupSelection::normalizeMode(''));
        $this->assertSame(BackupSelection::MODE_SNAPSHOT, $selection->modeOf('tonidata_data'), 'entri absen ⇒ snapshot');
        $this->assertSame(BackupSelection::MODE_SNAPSHOT, $selection->modeOf('tonidata_data', 'srv-a'), 'entri server absen ⇒ snapshot');
        $this->assertSame(
            [BackupSelection::MODE_SNAPSHOT, BackupSelection::MODE_SYNC],
            BackupSelection::MODES,
            'konstanta MODES = sumber kebenaran'
        );
    }

    public function testModeKeyAbsentInEntryDefaultsToSnapshot(): void
    {
        SqliteFixture::backup($this->path, [
            'volumes' => ['tonidata_data' => ['scheduled' => true, 'updated_by' => 'u1']],
        ]);

        $this->assertSame(BackupSelection::MODE_SNAPSHOT, (new BackupSelection($this->path))->modeOf('tonidata_data'));
    }

    public function testUnknownModeValueInStoreFallsBackToSnapshot(): void
    {
        SqliteFixture::backup($this->path, [
            'volumes' => ['tonidata_data' => ['scheduled' => true, 'mode' => 'bulanan']],
        ]);

        $this->assertSame(BackupSelection::MODE_SNAPSHOT, (new BackupSelection($this->path))->modeOf('tonidata_data'));
    }

    public function testSetModePersistsAndIsReadBack(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1');

        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'), 'memo disegarkan setelah setMode');
        $this->assertSame(
            BackupSelection::MODE_SYNC,
            (new BackupSelection($this->path))->modeOf('tonidata_data'),
            'persistensi lintas-instance'
        );

        $stored = $this->stored();
        $this->assertSame(BackupSelection::MODE_SYNC, $stored['tonidata_data']['mode']);
        $this->assertSame('u1', $stored['tonidata_data']['updated_by']);
        $this->assertSame('local', $stored['tonidata_data']['server_id']);
        $this->assertArrayHasKey('updated_at', $stored['tonidata_data']);

        $selection->setMode('tonidata_data', BackupSelection::MODE_SNAPSHOT, 'u2');
        $this->assertSame(BackupSelection::MODE_SNAPSHOT, (new BackupSelection($this->path))->modeOf('tonidata_data'));
    }

    public function testSetModeIsScopedPerServer(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1', 'srv-a');

        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data', 'srv-a'));
        $this->assertSame(
            BackupSelection::MODE_SNAPSHOT,
            $selection->modeOf('tonidata_data'),
            'mode server lain tidak bocor ke server ini'
        );
        $this->assertSame(
            BackupSelection::MODE_SNAPSHOT,
            $selection->modeOf('tonidata_data', 'srv-b')
        );

        $stored = $this->stored();
        $this->assertArrayHasKey('srv-a::tonidata_data', $stored, 'kunci diskop BackupRegistry::scopeKey()');
        $this->assertSame('srv-a', $stored['srv-a::tonidata_data']['server_id']);
    }

    public function testSetScheduledPreservesMode(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1');

        $selection->setScheduled('tonidata_data', true, 'u1');
        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'), 'mode tidak terhapus saat toggle ON');

        $selection->setScheduled('tonidata_data', false, 'u1');
        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'), 'mode tidak terhapus saat toggle OFF');
        $this->assertFalse($selection->isScheduled('tonidata_data'));

        $stored = $this->stored()['tonidata_data'];
        $this->assertSame(false, $stored['scheduled']);
        $this->assertSame(BackupSelection::MODE_SYNC, $stored['mode']);
    }

    public function testSetModePreservesScheduled(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', true, 'u1');

        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u2');

        $this->assertTrue($selection->isScheduled('tonidata_data'), 'mengubah mode tidak mematikan jadwal');
        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'));
    }

    public function testBackfillDoesNotRemoveMode(): void
    {
        SqliteFixture::backup($this->path, [
            // Entri dengan mode tetapi belum punya kunci `scheduled` (ditulis
            // sebelum fitur jadwal/backfill menyentuhnya).
            'volumes' => ['tonidata_data' => ['mode' => BackupSelection::MODE_SYNC, 'updated_by' => 'u1']],
        ]);

        $selection = new BackupSelection($this->path);
        $selection->backfill(['tonidata_data']);

        $this->assertTrue($selection->isScheduled('tonidata_data'), 'backfill menyalakan jadwal');
        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'), 'mode tidak hilang');
        $this->assertSame(BackupSelection::MODE_SYNC, $this->stored()['tonidata_data']['mode']);
    }

    public function testBackfillDoesNotTouchExplicitEntryWithMode(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1');
        $selection->setScheduled('tonidata_data', false, 'u1');

        $selection->backfill(['tonidata_data']);

        $this->assertFalse($selection->isScheduled('tonidata_data'), 'entri eksplisit OFF dipertahankan');
        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'));
    }

    public function testModeIsIndependentPerVolume(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setMode('tonidata_data', BackupSelection::MODE_SYNC, 'u1');
        $selection->setMode('waha_data', BackupSelection::MODE_SNAPSHOT, 'u1');

        $this->assertSame(BackupSelection::MODE_SYNC, $selection->modeOf('tonidata_data'));
        $this->assertSame(BackupSelection::MODE_SNAPSHOT, $selection->modeOf('waha_data'));
        $this->assertSame(BackupSelection::MODE_SNAPSHOT, $selection->modeOf('lain_data'), 'volume tanpa entri ⇒ snapshot');
    }

    public function testInvalidModeIsRejectedWithoutWriting(): void
    {
        $selection = new BackupSelection($this->path);

        foreach (['', 'daily', 'SNAPSHOT', 'sync ', 'semua'] as $mode) {
            try {
                $selection->setMode('tonidata_data', $mode, 'u1');
                $this->fail('mode invalid harus ditolak: "' . $mode . '"');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Mode backup tidak dikenal', $e->getMessage());
            }
        }

        $this->assertSame([], $this->stored(), 'mode invalid tidak boleh menulis apa pun');
    }

    public function testSetModeRejectsInvalidVolumeName(): void
    {
        $selection = new BackupSelection($this->path);

        $this->expectException(RuntimeException::class);
        $selection->setMode('../evil', BackupSelection::MODE_SYNC);
    }
}
