<?php
declare(strict_types=1);

namespace Tests;

use app\library\Update\UpdateState;
use PHPUnit\Framework\TestCase;

/**
 * Unit test UpdateState (SPECS.md §7.8): dua berkas dengan SATU penulis
 * masing-masing — `check.json` (dashboard) dan `run.json` (helper) — plus
 * penyajian log untuk panel /nginx.
 *
 * Yang dijaga: nama berkas log dari request TIDAK boleh bisa dipakai membaca
 * berkas sembarang (path traversal / ekstensi lain), dan run yang sudah selesai
 * tidak boleh bisa "dihidupkan" lagi atau ditutup dua kali.
 */
class UpdateStateTest extends TestCase
{
    private string $tmp;
    private string $runDir;
    private UpdateState $state;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-state-' . bin2hex(random_bytes(4));
        $this->runDir = $this->tmp . '/runs';
        $this->state = new UpdateState($this->tmp . '/check.json', $this->runDir);
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->tmp);
    }

    // ==================================================================
    // check.json
    // ==================================================================

    public function testCheckDefaultsWhenFileMissing(): void
    {
        $check = $this->state->check();

        $this->assertNull($check['checked_at']);
        $this->assertFalse($check['ok']);
        $this->assertSame('', $check['branch']);
        $this->assertFalse($check['update_available']);
        $this->assertSame([], $check['tracked_changes']);
        $this->assertNull($check['compare_url']);
    }

    public function testWriteCheckThenReadBackNormalized(): void
    {
        $this->state->writeCheck([
            'checked_at' => '2026-09-23T12:00:00+07:00',
            'ok' => true,
            'branch' => 'main',
            'local_sha' => 'aaaa',
            'remote_sha' => 'bbbb',
            'update_available' => true,
            'compare_url' => 'https://github.com/yllumi/rames/compare/aaaa...bbbb',
            'head' => ['sha' => 'aaaa', 'date' => '2026-09-22T17:12:00+07:00', 'subject' => 'Add templates'],
            'untracked' => ['templates/wabaileys/'],
            'tracked_changes' => ['M app/functions.php'],
        ]);

        $check = $this->state->check();
        $this->assertTrue($check['ok']);
        $this->assertSame('main', $check['branch']);
        $this->assertTrue($check['update_available']);
        $this->assertSame(['M app/functions.php'], $check['tracked_changes']);
        $this->assertSame('Add templates', $check['head']['subject']);
        // Berkas korup/aneh tidak membuat pembacaan gagal.
        $this->assertFileExists($this->tmp . '/check.json');
    }

    public function testCorruptCheckFileFallsBackToDefaults(): void
    {
        // Direktori check belum tentu ada (writeCheck membuatnya; di sini kita
        // menulis langsung) — buat lebih dulu agar tidak ada warning PHP.
        mkdir($this->tmp, 0777, true);
        file_put_contents($this->tmp . '/check.json', '{bukan json');

        $check = $this->state->check();
        $this->assertFalse($check['ok']);
        $this->assertNull($check['checked_at']);
    }

    // ==================================================================
    // run.json (ditulis helper)
    // ==================================================================

    public function testStartRunAndReadBack(): void
    {
        $this->state->startRun([
            'id' => '20260923-120000-abc123',
            'mode' => 'update',
            'stage' => 'starting',
            'message' => 'Menjalankan helper update…',
            'result' => null,
            'error' => null,
            'started_at' => '2026-09-23T12:00:00+07:00',
            'finished_at' => null,
            'old_sha' => 'aaaa',
            'target_sha' => null,
            'actor' => 'admin',
            'log' => '20260923-120000-abc123.log',
            'health_url' => 'http://rames-webman:8787/healthz',
            'rollback_from' => 'aaaa',
        ]);

        $run = $this->state->run();
        $this->assertSame('20260923-120000-abc123', $run['id']);
        $this->assertSame('update', $run['mode']);
        $this->assertSame('starting', $run['stage']);
        $this->assertSame('aaaa', $run['old_sha']);
        $this->assertNull($run['result']);
        $this->assertTrue($this->state->isRunning($run));
    }

    public function testRunningDetection(): void
    {
        $this->assertFalse($this->state->isRunning($this->state->run()));
        $this->assertFalse($this->state->isRunning([
            'id' => null, 'result' => null, 'finished_at' => null,
        ]));
        $this->assertFalse($this->state->isRunning([
            'id' => 'x', 'result' => 'success', 'finished_at' => null,
        ]));
        $this->assertTrue($this->state->isRunning([
            'id' => 'x', 'result' => null, 'finished_at' => null,
        ]));
    }

    public function testFinalizeClosesDanglingRunOnce(): void
    {
        $this->state->startRun([
            'id' => '20260923-120000-abc123',
            'mode' => 'update',
            'stage' => 'build',
            'result' => null,
            'started_at' => '2026-09-23T12:00:00+07:00',
        ]);

        $this->state->finalize('error', 'Helper berhenti tanpa laporan.');
        $run = $this->state->run();

        $this->assertSame('error', $run['result']);
        $this->assertSame('finished', $run['stage']);
        $this->assertSame('Helper berhenti tanpa laporan.', $run['error']);
        $this->assertNotNull($run['finished_at']);

        // Panggilan kedua tidak boleh mengubah apa pun (idempoten).
        $this->state->finalize('success', 'jangan menimpa');
        $this->assertSame('error', $this->state->run()['result']);
    }

    public function testFinalizeWithoutRunDoesNothing(): void
    {
        $this->state->finalize('error', 'tidak ada run');

        $this->assertFileDoesNotExist($this->runDir . '/run.json');
    }

    // ==================================================================
    // Log
    // ==================================================================

    public function testLogsAreListedNewestFirstAndFiltered(): void
    {
        mkdir($this->runDir, 0777, true);
        file_put_contents($this->runDir . '/20260923-100000-aaaaaa.log', "lama\n");
        file_put_contents($this->runDir . '/20260923-120000-bbbbbb.log', "baru\n");
        file_put_contents($this->runDir . '/run.json', '{}');
        file_put_contents($this->runDir . '/catatan.txt', 'bukan log');

        $logs = $this->state->logs();

        $this->assertCount(2, $logs);
        $this->assertSame('20260923-120000-bbbbbb.log', $logs[0]['name']);
        $this->assertSame('20260923-100000-aaaaaa.log', $logs[1]['name']);
        $this->assertGreaterThan(0, $logs[0]['size']);
    }

    public function testLogTailReadsFile(): void
    {
        mkdir($this->runDir, 0777, true);
        file_put_contents($this->runDir . '/20260923-120000-bbbbbb.log', "baris-1\nbaris-2\n");

        $this->assertSame("baris-1\nbaris-2\n", $this->state->logTail('20260923-120000-bbbbbb.log'));
        // Nama tidak dikenal → kosong (bukan error).
        $this->assertSame('', $this->state->logTail('tidak-ada.log'));
    }

    public function testLogTailRejectsUnsafeNames(): void
    {
        mkdir($this->runDir, 0777, true);
        file_put_contents($this->tmp . '/rahasia.log', 'rahasia');

        foreach (['../rahasia.log', '../../etc/passwd', 'run.json', 'evil.sh', '', '.', '/etc/passwd'] as $name) {
            $this->assertSame('', $this->state->logTail($name), 'Nama harus ditolak: ' . $name);
            $this->assertNull($this->state->resolveLogPath($name), 'Path harus null: ' . $name);
        }

        // Dasar nama yang sah tapi keluar direktori → basename tetap divalidasi.
        $this->assertNull($this->state->resolveLogPath($this->runDir . '/../rahasia.log'));
    }

    public function testLogTailIsTruncatedToMaxBytes(): void
    {
        mkdir($this->runDir, 0777, true);
        file_put_contents($this->runDir . '/20260923-120000-cccccc.log', str_repeat('x', 1000) . 'EKOR');

        $tail = $this->state->logTail('20260923-120000-cccccc.log', 10);

        $this->assertSame(10, strlen($tail));
        $this->assertStringEndsWith('EKOR', $tail);
    }
}
