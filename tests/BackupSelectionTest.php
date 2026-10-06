<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupSelection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test `BackupSelection` — flag seleksi backup berkala per volume, disimpan di
 * berkas terpisah dari `apps.json` (path temp; tanpa menyentuh data runtime).
 *
 * Kontrak: entri absen ⇒ **default OFF** (opt-in); set eksplisit ON/OFF
 * dipersistensikan lewat `JsonStore`. `backfill()` menyalakan volume yang sudah
 * punya snapshot tanpa menimpa entri eksplisit.
 */
class BackupSelectionTest extends TestCase
{
    private string $tmp;
    private string $path;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backupsel_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        $this->path = $this->tmp . '/backup.json';
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

        // Instance baru membaca berkas yang sama (persistensi lintas-instance).
        $reloaded = new BackupSelection($this->path);
        $this->assertFalse($reloaded->isScheduled('tonidata_data'));

        $data = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame(1, $data['version']);
        $this->assertSame('u1', $data['volumes']['tonidata_data']['updated_by']);
        $this->assertArrayHasKey('updated_at', $data['volumes']['tonidata_data']);
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
     * `isScheduled()` dipanggil per baris volume — berkas harus dibaca SEKALI
     * per instance, bukan tiap panggilan.
     */
    public function testMemoizesFileReadPerInstance(): void
    {
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'volumes' => ['tonidata_data' => ['scheduled' => false]],
        ]));

        $selection = new BackupSelection($this->path);
        $this->assertFalse($selection->isScheduled('tonidata_data'));

        // Ubah berkas di belakang instance. Bila memo bekerja, panggilan
        // berikutnya TIDAK membaca ulang ⇒ tetap memakai nilai lama.
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'volumes' => ['tonidata_data' => ['scheduled' => true]],
        ]));

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

        $data = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame(1, $data['version']);
        $this->assertSame('system', $data['volumes']['tonidata_data']['updated_by']);
        $this->assertArrayHasKey('updated_at', $data['volumes']['tonidata_data']);
    }

    public function testBackfillDoesNotOverwriteExplicitEntries(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', false, 'u1');
        $selection->setScheduled('waha_data', true, 'u1');

        $selection->backfill(['tonidata_data', 'waha_data']);

        $this->assertFalse($selection->isScheduled('tonidata_data'), 'entri eksplisit OFF dipertahankan');
        $this->assertSame(['tonidata_data' => false, 'waha_data' => true], $selection->explicit());

        $data = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame('u1', $data['volumes']['tonidata_data']['updated_by'], 'tidak diubah sistem');
    }

    public function testBackfillIsIdempotent(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->backfill(['tonidata_data']);
        $first = json_decode((string) file_get_contents($this->path), true);

        $selection->backfill(['tonidata_data']);
        $second = json_decode((string) file_get_contents($this->path), true);

        $this->assertSame($first, $second, 'backfill kedua tidak mengubah berkas');
    }

    public function testBackfillIgnoresEmptyAndRejectsInvalidName(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->backfill(['']);
        $this->assertFileDoesNotExist($this->path, 'nama kosong diabaikan (tanpa tulis)');

        $this->expectException(RuntimeException::class);
        $selection->backfill(['../evil']);
    }
}
