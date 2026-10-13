<?php
declare(strict_types=1);

namespace Tests;

use app\library\Template\TemplatePullState;
use PHPUnit\Framework\TestCase;

/**
 * `TemplatePullState` — status & log run tarik image template (§7.13).
 *
 * Semua berkas ditulis di direktori TEMP (tidak pernah `runtime/` nyata).
 * Yang dikunci: normalisasi bentuk `run` (kontrak UI), penggabungan patch,
 * kerangka baru saat `id` berbeda, penutupan run, keamanan nama berkas log, serta
 * `isRunning()` (hasil null + pid hidup, dan run yang ditinggalkan worker).
 */
class TemplatePullStateTest extends TestCase
{
    /** Pid yang dijamin tidak ada (di atas `pid_max` Linux). */
    private const DEAD_PID = 2147483646;

    private string $dir;
    private TemplatePullState $state;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-pullstate-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->state = new TemplatePullState($this->dir);
    }

    protected function tearDown(): void
    {
        $this->state->clear();
        @rmdir($this->dir);
    }

    public function testRunIsEmptyAndNotRunningBeforeAnyStart(): void
    {
        $run = $this->state->run();

        $this->assertNull($run['id']);
        $this->assertNull($run['result']);
        $this->assertSame(0, $run['pid']);
        $this->assertSame([], $run['images']);
        $this->assertSame([], $run['results']);
        $this->assertFalse($this->state->isRunning());
    }

    public function testStartRunThenUpdateMergesPatchOfSameRun(): void
    {
        $this->state->startRun([
            'id' => 'run-1',
            'slug' => 'outline',
            'title' => 'Outline',
            'stage' => 'starting',
            'result' => null,
            'pid' => 0,
            'images' => ['redis:8-alpine'],
        ]);

        $this->state->update(['stage' => 'pulling', 'message' => 'Menarik image 1/1: redis:8-alpine']);
        $this->state->update(['results' => [
            ['image' => 'redis:8-alpine', 'ok' => true, 'seconds' => 1.25, 'message' => 'OK'],
        ]]);

        $run = $this->state->run();
        $this->assertSame('run-1', $run['id']);
        $this->assertSame('outline', $run['slug']);
        $this->assertSame('pulling', $run['stage']);
        $this->assertSame('Menarik image 1/1: redis:8-alpine', $run['message']);
        $this->assertSame(['redis:8-alpine'], $run['images']);
        $this->assertCount(1, $run['results']);
        $this->assertTrue($run['results'][0]['ok']);
        $this->assertSame('redis:8-alpine', $run['results'][0]['image']);
    }

    public function testUpdateWithDifferentIdStartsFreshFrame(): void
    {
        $this->state->startRun(['id' => 'run-1', 'slug' => 'outline', 'stage' => 'finished', 'result' => 'success']);
        $this->state->update(['results' => [['image' => 'lama:1', 'ok' => true, 'seconds' => 1, 'message' => 'OK']]]);

        $run = $this->state->update(['id' => 'run-2', 'slug' => 'mysql', 'stage' => 'starting', 'result' => null]);

        $this->assertSame('run-2', $run['id']);
        $this->assertSame('mysql', $run['slug']);
        $this->assertSame([], $run['results'], 'results run lama tidak boleh bocor ke run baru');
        $this->assertNull($run['result']);
    }

    public function testFinalizeMarksResultAndClearsPid(): void
    {
        $this->state->startRun(['id' => 'run-1', 'slug' => 'outline', 'stage' => 'pulling', 'result' => null, 'pid' => getmypid()]);
        $this->state->update(['results' => [['image' => 'a:1', 'ok' => true, 'seconds' => 2, 'message' => 'OK']]]);

        $run = $this->state->finalize('success');

        $this->assertSame('success', $run['result']);
        $this->assertSame('finished', $run['stage']);
        $this->assertNotNull($run['finished_at']);
        $this->assertSame(0, $run['pid']);
        $this->assertNull($run['error']);
        $this->assertStringContainsString('1 image', $run['message']);

        $this->assertFalse($this->state->isRunning($run));
    }

    public function testFinalizeWithErrorStoresMessage(): void
    {
        $this->state->startRun(['id' => 'run-1', 'slug' => 'outline', 'result' => null, 'pid' => getmypid()]);

        $run = $this->state->finalize('error', 'Gagal menarik image: mysql:8.4. Periksa log.');

        $this->assertSame('error', $run['result']);
        $this->assertSame('Gagal menarik image: mysql:8.4. Periksa log.', $run['error']);
        $this->assertSame('Gagal menarik image: mysql:8.4. Periksa log.', $run['message']);
        $this->assertSame('finished', $run['stage']);
    }

    public function testFinalizeWithoutRunIsNoop(): void
    {
        $run = $this->state->finalize('error', 'tidak ada run');

        $this->assertNull($run['id']);
        $this->assertFileDoesNotExist($this->state->runFile());
    }

    public function testIsRunningUsesLivenessOfPid(): void
    {
        $this->state->startRun(['id' => 'run-1', 'slug' => 'outline', 'result' => null, 'pid' => getmypid()]);
        $this->assertTrue($this->state->isRunning(), 'pid hidup + hasil null = berjalan');

        $this->state->update(['pid' => self::DEAD_PID]);
        $this->assertFalse($this->state->isRunning(), 'worker mati = bukan lagi berjalan (stale)');

        $this->state->update(['pid' => 0]);
        $this->assertTrue($this->state->isRunning(), 'pid belum dilaporkan = anggap masih berjalan');

        $this->state->update(['result' => 'error']);
        $this->assertFalse($this->state->isRunning(), 'sudah ada hasil = tidak berjalan');
    }

    public function testLogIsAppendedAndTailIsLimited(): void
    {
        $this->state->appendLog('run-1.log', "baris-1\n");
        $this->state->appendLog('run-1.log', "baris-2\n");

        $this->assertSame("baris-1\nbaris-2\n", $this->state->logTail('run-1.log'));
        $this->assertSame("is-2\n", $this->state->logTail('run-1.log', 5));
        $this->assertSame($this->dir . '/run-1.log', $this->state->logPath('run-1.log'));

        $logs = $this->state->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('run-1.log', $logs[0]['name']);
        $this->assertSame(16, $logs[0]['size']);
    }

    public function testLogNamesAreSanitized(): void
    {
        $this->state->appendLog('run-1.log', 'aman');
        $this->state->appendLog('run-2.txt', 'tidak boleh ditulis');
        $this->state->appendLog('run-1.log', '');

        // Nama dinormalisasi ke basename-nya: `../jahat.log` ditulis sebagai
        // `jahat.log` DI DALAM run_dir (bukan keluar direktori).
        $this->state->appendLog('../jahat.log', 'aman juga');
        $this->assertFileDoesNotExist(dirname($this->dir) . '/jahat.log');
        $this->assertSame('aman juga', $this->state->logTail('jahat.log'));

        $this->assertNull($this->state->logPath('run-2.txt'));
        $this->assertNull($this->state->logPath(''));
        $this->assertSame('', $this->state->logTail('run-2.txt'));
        $this->assertSame(['run-1.log', 'jahat.log'], array_column($this->state->logs(), 'name'));
    }

    public function testLogsAreSortedNewestFirstAndCapped(): void
    {
        foreach (['a.log', 'b.log', 'c.log'] as $name) {
            $this->state->appendLog($name, $name);
        }

        $this->assertSame(['c.log', 'b.log'], array_column($this->state->logs(2), 'name'));
        $this->assertSame(['c.log', 'b.log', 'a.log'], array_column($this->state->logs(), 'name'));
    }

    public function testClearRemovesStatusAndLogs(): void
    {
        $this->state->startRun(['id' => 'run-1', 'result' => null]);
        $this->state->appendLog('run-1.log', 'isi');

        $this->state->clear();

        $this->assertFileDoesNotExist($this->state->runFile());
        $this->assertFileDoesNotExist($this->dir . '/run-1.log');
        $this->assertNull($this->state->run()['id']);
    }
}
