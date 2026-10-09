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
}
