<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupRegistry;
use app\library\Backup\BackupSelection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test `BackupRegistry` — riwayat volume yang pernah ter-backup, disimpan di
 * kunci `registry` pada berkas yang sama dengan seleksi (`database/backup.json`).
 *
 * Kontrak: `upsertMany()` mempertahankan `first_backed_up_at` & nilai lama yang
 * tak disuplai; `syncCounts()` menyegarkan `snapshots` **dan** membuang entri
 * dengan count `0` (keputusan 3b). Registry dan seleksi berbagi berkas —
 * menulis salah satu **tidak** merusak kunci milik yang lain. Path temp
 * (larangan #15: tanpa menyentuh data runtime nyata).
 */
class BackupRegistryTest extends TestCase
{
    private string $tmp;
    private string $path;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backupreg_' . bin2hex(random_bytes(4));
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

    public function testMissingFileReadsEmpty(): void
    {
        $this->assertSame([], (new BackupRegistry($this->path))->read());
    }

    public function testUpsertPreservesFirstBackedUpAtAndKeepsOldValues(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->upsertMany([
            'tonidata_data' => [
                'project' => 'tonidata',
                'app_id' => 'app1',
                'app_name' => 'tonidata',
                'strategy' => 'snapshot',
                'last_snapshot' => 'aaa',
                'bytes' => 100,
            ],
        ]);

        $first = $registry->read()['tonidata_data'];
        $this->assertSame('tonidata', $first['project']);
        $this->assertSame('app1', $first['app_id']);
        $this->assertSame('tonidata', $first['app_name']);
        $this->assertSame('snapshot', $first['strategy']);
        $this->assertSame('aaa', $first['last_snapshot']);
        $this->assertSame(100, $first['bytes']);
        $this->assertSame(0, $first['snapshots'], 'snapshots awal 0 (diisi syncCounts)');
        $this->assertSame($first['first_backed_up_at'], $first['last_backed_up_at']);

        // Upsert kedua hanya menyuplai sebagian kunci: nilai lama dipertahankan,
        // `first_backed_up_at` tidak berubah, `last_snapshot`/`bytes` disegarkan.
        $registry->upsertMany([
            'tonidata_data' => [
                'strategy' => 'snapshot',
                'last_snapshot' => 'bbb',
                'bytes' => 200,
            ],
        ]);

        $second = $registry->read()['tonidata_data'];
        $this->assertSame($first['first_backed_up_at'], $second['first_backed_up_at'], 'first_backed_up_at dipertahankan');
        $this->assertSame('aaa', $first['last_snapshot'], 'snapshot pertama tetap utuh (instans lama)');
        $this->assertSame('bbb', $second['last_snapshot']);
        $this->assertSame(200, $second['bytes']);
        $this->assertSame('app1', $second['app_id'], 'app_id lama dipertahankan bila tak disuplai');
    }

    public function testUpsertEmptyEntriesDoesNotWrite(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->upsertMany([]);
        $this->assertFileDoesNotExist($this->path, 'tanpa entri valid → tanpa tulis');
    }

    public function testUpsertRejectsInvalidVolumeName(): void
    {
        $registry = new BackupRegistry($this->path);
        $this->expectException(RuntimeException::class);
        $registry->upsertMany(['../evil' => ['project' => 'x']]);
    }

    /**
     * Backfill menyisipkan entri untuk volume yang **belum ada** di registry
     * (mis. snapshot lama sebelum fitur registry ada), dengan bentuk lengkap
     * `{project, app_id, app_name, strategy, snapshots, first_/last_backed_up_at,
     * last_snapshot: null, bytes: 0}`.
     */
    public function testBackfillInsertsAbsentEntriesWithFullShape(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->backfill([
            'old_data' => [
                'project' => 'old',
                'app_id' => 'app9',
                'app_name' => 'old',
                'strategy' => 'snapshot',
                'snapshots' => 4,
                'at' => '2026-09-01T10:00:00+00:00',
            ],
        ]);

        $entry = $registry->read()['old_data'];
        $this->assertSame('old', $entry['project']);
        $this->assertSame('app9', $entry['app_id']);
        $this->assertSame('old', $entry['app_name']);
        $this->assertSame('snapshot', $entry['strategy']);
        $this->assertSame(4, $entry['snapshots']);
        $this->assertSame('2026-09-01T10:00:00+00:00', $entry['first_backed_up_at']);
        $this->assertSame('2026-09-01T10:00:00+00:00', $entry['last_backed_up_at']);
        $this->assertNull($entry['last_snapshot']);
        $this->assertSame(0, $entry['bytes']);
    }

    /**
     * `at` absen/kosong → `first_`/`last_backed_up_at` = waktu sekarang (terisi,
     * bukan string kosong).
     */
    public function testBackfillFallsBackToNowWhenAtMissing(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->backfill(['old_data' => ['project' => 'old', 'strategy' => 'snapshot', 'snapshots' => 1]]);

        $entry = $registry->read()['old_data'];
        $this->assertNotSame('', $entry['first_backed_up_at']);
        $this->assertSame($entry['first_backed_up_at'], $entry['last_backed_up_at']);
    }

    /**
     * Entri yang sudah ada **tidak** ditimpa (idempotent): backfill kedua dengan
     * `at` berbeda tidak mengubah apa pun.
     */
    public function testBackfillDoesNotOverwriteExistingAndIsIdempotent(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->upsertMany([
            'keep_data' => [
                'project' => 'keep',
                'app_id' => 'app1',
                'app_name' => 'keep',
                'strategy' => 'dump',
                'last_snapshot' => 'aaa',
                'bytes' => 512,
                'snapshots' => 3,
            ],
        ]);
        $before = $registry->read()['keep_data'];

        $registry->backfill([
            'keep_data' => [
                'project' => 'changed',
                'strategy' => 'snapshot',
                'snapshots' => 99,
                'at' => '2030-01-01T00:00:00+00:00',
            ],
            'new_data' => ['project' => 'new', 'strategy' => 'snapshot', 'snapshots' => 2],
        ]);

        $after = $registry->read()['keep_data'];
        $this->assertSame($before, $after, 'entri eksisting tidak boleh diubah backfill');
        $this->assertArrayHasKey('new_data', $registry->read(), 'entri absen tetap disisipkan');

        // Idempotent: backfill ulang tidak mengubah apa pun lagi.
        $registry->backfill([
            'keep_data' => ['project' => 'x', 'snapshots' => 1, 'at' => '2040-01-01T00:00:00+00:00'],
            'new_data' => ['project' => 'y', 'snapshots' => 7, 'at' => '2040-01-01T00:00:00+00:00'],
        ]);
        $this->assertSame($after, $registry->read()['keep_data']);
        $this->assertSame(2, $registry->read()['new_data']['snapshots']);
    }

    /**
     * Backfill hanya menyentuh kunci `registry` — kunci `volumes` milik seleksi
     * dibiarkan utuh.
     */
    public function testBackfillDoesNotTouchVolumesKey(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('some_data', true, 'u1');

        $registry = new BackupRegistry($this->path);
        $registry->backfill(['some_data' => ['project' => 'some', 'strategy' => 'snapshot', 'snapshots' => 1]]);

        $this->assertTrue((new BackupSelection($this->path))->isScheduled('some_data'), 'kunci volumes tidak terganggu');
        $this->assertArrayHasKey('some_data', $registry->read());

        $data = json_decode((string) file_get_contents($this->path), true);
        $this->assertArrayHasKey('volumes', $data);
        $this->assertArrayHasKey('registry', $data);
    }

    /**
     * Nama invalid **dibuang** (bukan fail-fast) agar satu baris rusak tidak
     * membatalkan backfill baris lain.
     */
    public function testBackfillSkipsInvalidVolumeNameWithoutThrowing(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->backfill([
            '../evil' => ['project' => 'evil', 'strategy' => 'snapshot', 'snapshots' => 1],
            'good_data' => ['project' => 'good', 'strategy' => 'snapshot', 'snapshots' => 1],
        ]);

        $read = $registry->read();
        $this->assertArrayNotHasKey('../evil', $read);
        $this->assertArrayHasKey('good_data', $read);
    }

    public function testBackfillEmptyEntriesDoesNotWrite(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->backfill([]);
        $this->assertFileDoesNotExist($this->path, 'tanpa entri valid → tanpa tulis');
    }

    public function testSyncCountsUpdatesAndPrunesZero(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->upsertMany([
            'keep_data' => ['project' => 'a', 'strategy' => 'snapshot'],
            'gone_data' => ['project' => 'b', 'strategy' => 'snapshot'],
        ]);

        // `gone_data` absen → dianggap 0 → dibuang (keputusan 3b).
        $registry->syncCounts(['keep_data' => 3]);

        $read = $registry->read();
        $this->assertArrayHasKey('keep_data', $read);
        $this->assertSame(3, $read['keep_data']['snapshots'], 'jumlah snapshot disegarkan');
        $this->assertArrayNotHasKey('gone_data', $read, 'entri dengan count 0 dibuang (keputusan 3b)');
    }

    public function testSyncCountsPrunesExplicitZero(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->upsertMany(['gone_data' => ['project' => 'b', 'strategy' => 'snapshot']]);

        $registry->syncCounts(['gone_data' => 0]);

        $this->assertSame([], $registry->read(), 'snapshot habis → entri dihapus');
    }

    /**
     * Guard riwayat: peta counts **kosong** diperlakukan sebagai "tak ada data /
     * tidak diketahui" (mis. repo restic terjangkau tetapi kosong / salah bucket)
     * → JANGAN prune. Entri tetap utuh (`snapshots` tidak disentuh sama sekali).
     */
    public function testSyncCountsEmptyMapDoesNotPrune(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->upsertMany([
            'keep_data' => ['project' => 'a', 'strategy' => 'snapshot'],
            'also_data' => ['project' => 'b', 'strategy' => 'dump'],
        ]);

        $registry->syncCounts([]);

        $read = $registry->read();
        $this->assertArrayHasKey('keep_data', $read, 'peta kosong tidak boleh menghapus riwayat');
        $this->assertArrayHasKey('also_data', $read, 'peta kosong tidak boleh menghapus riwayat');
        $this->assertSame(0, $read['keep_data']['snapshots'], 'counts kosong → snapshots tidak disegarkan');
    }

    /**
     * Pemetaan campuran: count > 0 menyegarkan `snapshots`, count `0` membuang
     * entri — keduanya dalam satu panggilan (peta non-kosong → prune sah).
     */
    public function testSyncCountsUpdatesNonZeroAndPrunesZeroTogether(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->upsertMany([
            'a_data' => ['project' => 'a', 'strategy' => 'snapshot'],
            'b_data' => ['project' => 'b', 'strategy' => 'snapshot'],
        ]);

        $registry->syncCounts(['a_data' => 2, 'b_data' => 0]);

        $read = $registry->read();
        $this->assertArrayHasKey('a_data', $read);
        $this->assertSame(2, $read['a_data']['snapshots'], 'count > 0 menyegarkan snapshots');
        $this->assertArrayNotHasKey('b_data', $read, 'count 0 membuang entri');
    }

    public function testSyncCountsOnEmptyRegistryIsNoop(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->syncCounts(['anything' => 5]);
        $this->assertSame([], $registry->read());
    }

    public function testRemoveDeletesEntryIdempotently(): void
    {
        $registry = new BackupRegistry($this->path);
        $registry->upsertMany(['tonidata_data' => ['project' => 'tonidata', 'strategy' => 'snapshot']]);

        $registry->remove('tonidata_data');
        $this->assertSame([], $registry->read());

        // Idempotent: menghapus lagi tidak melempar.
        $registry->remove('tonidata_data');
        $this->assertSame([], $registry->read());
    }

    /**
     * Koeksistensi: registry & seleksi berbagi berkas yang sama, tetapi menulis
     * salah satu **tidak** menghapus kunci milik yang lain (masing-masing hanya
     * menyentuh kuncinya).
     */
    public function testCoexistsWithSelectionOnSameFile(): void
    {
        $selection = new BackupSelection($this->path);
        $selection->setScheduled('tonidata_data', true, 'u1');

        $registry = new BackupRegistry($this->path);
        $registry->upsertMany([
            'tonidata_data' => ['project' => 'tonidata', 'strategy' => 'snapshot'],
        ]);

        // Seleksi tetap utuh setelah registry menulis.
        $reloadedSelection = new BackupSelection($this->path);
        $this->assertTrue($reloadedSelection->isScheduled('tonidata_data'), 'registry menulis tanpa mengganggu kunci volumes');

        // Registry tetap utuh setelah seleksi menulis lagi.
        $selection->setScheduled('waha_data', true, 'u1');
        $reloadedRegistry = new BackupRegistry($this->path);
        $read = $reloadedRegistry->read();
        $this->assertArrayHasKey('tonidata_data', $read, 'seleksi menulis tanpa mengganggu kunci registry');

        // Berkas memuat kedua kunci.
        $data = json_decode((string) file_get_contents($this->path), true);
        $this->assertArrayHasKey('volumes', $data);
        $this->assertArrayHasKey('registry', $data);
    }
}
