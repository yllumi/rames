<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupReport;
use PHPUnit\Framework\TestCase;

/**
 * Test BackupReport — status & riwayat run (path temp, JSONStore atomic) dengan
 * jaminan: **hanya metadata**, kunci asing dibuang, pola kredensial diredaksi,
 * retensi N run terakhir.
 */
class BackupReportTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/backupreport_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testStatusRoundTripKeepsOnlyWhitelistedKeys(): void
    {
        $report = new BackupReport($this->tmp);
        $report->writeStatus([
            'started_at' => '2026-09-30T02:30:00+00:00',
            'status' => 'ok',
            'volumes' => [],
            'aws_secret_access_key' => 'harus-dibuang',
            'password' => 'harus-dibuang',
        ]);

        $status = $report->readStatus();
        $this->assertSame('ok', $status['status']);
        $this->assertSame('2026-09-30T02:30:00+00:00', $status['started_at']);
        $this->assertArrayNotHasKey('aws_secret_access_key', $status);
        $this->assertArrayNotHasKey('password', $status);
        $this->assertFileExists($this->tmp . '/status.json');
    }

    public function testSecretsAreRedactedAndVolumeRowsFiltered(): void
    {
        $report = new BackupReport($this->tmp);
        $report->writeStatus([
            'status' => 'error',
            'error' => 'helper gagal: AWS_SECRET_ACCESS_KEY=AKIAverysecret RESTIC_PASSWORD=hunter2',
            'volumes' => [[
                'name' => 'tonidata_data',
                'project' => 'tonidata',
                'strategy' => 'snapshot',
                'status' => 'ok',
                'snapshot_id' => '1a2b3c4d',
                'password' => 'harus-dibuang',
            ]],
            'summary' => ['ok' => 1, 'error' => 0],
        ]);

        $status = $report->readStatus();
        $this->assertStringNotContainsString('AKIAverysecret', (string) $status['error']);
        $this->assertStringNotContainsString('hunter2', (string) $status['error']);
        $this->assertStringContainsString('***', (string) $status['error']);

        $this->assertCount(1, $status['volumes']);
        $this->assertSame('1a2b3c4d', $status['volumes'][0]['snapshot_id']);
        $this->assertArrayNotHasKey('password', $status['volumes'][0]);
        $this->assertSame(['ok' => 1, 'error' => 0], $status['summary']);
    }

    public function testAppendRunWritesFileAndKeepsOnlyLatestRuns(): void
    {
        $report = new BackupReport($this->tmp, 2);

        $report->appendRun(['started_at' => '2026-09-28T02:30:00+00:00', 'status' => 'ok']);
        $report->appendRun(['started_at' => '2026-09-29T02:30:00+00:00', 'status' => 'ok']);
        $report->appendRun(['started_at' => '2026-09-30T02:30:00+00:00', 'status' => 'error']);

        $runs = $report->listRuns();
        $this->assertCount(2, $runs, 'retensi harus menyisakan 2 run terakhir');
        $this->assertSame('2026-09-30T02-30-00-00-00.json', basename((string) $runs[0]['file']));
        $this->assertSame('2026-09-29T02-30-00-00-00.json', basename((string) $runs[1]['file']));
        $this->assertSame('error', $runs[0]['data']['status']);

        // berkas lama benar-benar terhapus (bukan hanya disembunyikan)
        $this->assertFileDoesNotExist($this->tmp . '/runs/2026-09-28T02-30-00-00-00.json');
    }

    public function testAppendRunWithoutTimestampStillGetsSafeFileName(): void
    {
        $report = new BackupReport($this->tmp);
        $path = $report->appendRun(['status' => 'ok']);

        $this->assertFileExists($path);
        // nama berkas selalu aman untuk filesystem (tanpa ':' dari ISO8601)
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z._-]+\.json$/', basename($path));
        $this->assertStringNotContainsString(':', basename($path));
    }
}
