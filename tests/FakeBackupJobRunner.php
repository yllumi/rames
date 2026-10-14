<?php
declare(strict_types=1);

namespace Tests;

use RuntimeException;

/**
 * Pengganti `BackupJobRunner` untuk uji **subproses** `cli/deploy.php`
 * (diaktifkan lewat env `RAMES_BACKUP_RUNNER_CLASS`, seam uji pola
 * `DEPLOYER_CLASS`). Tidak menyentuh Docker/restic sama sekali.
 *
 * Setiap pemanggilan dicatat ke berkas `FAKE_BACKUP_RUNNER_LOG` (statik kelas
 * tidak terbaca lintas proses). Env `FAKE_BACKUP_RUNNER_FAIL` diisi ⇒ method apa
 * pun melempar (menguji jalur gagal: job agent harus `error`, bukan sukses palsu).
 *
 * Bentuk kelas sengaja **duck-typed** (bukan subclass `BackupJobRunner` yang
 * final): kontrak yang dipakai `cli/deploy.php` hanya tiga method itu.
 */
class FakeBackupJobRunner
{
    private function note(string $entry): void
    {
        $path = (string) getenv('FAKE_BACKUP_RUNNER_LOG');
        if ($path !== '') {
            @file_put_contents($path, $entry . "\n", FILE_APPEND);
        }
    }

    /**
     * @param callable(string,string):void|null $log
     * @return array<string,mixed>
     */
    public function runBackup(string $volume, string $trigger = 'manual', ?callable $log = null): array
    {
        $this->note('runBackup:' . $volume . ':' . $trigger);

        if ((string) getenv('FAKE_BACKUP_RUNNER_FAIL') !== '') {
            throw new RuntimeException('fake runner backup gagal (uji)');
        }

        if ($log !== null) {
            $log('demo', 'backup ' . $volume . ' ok (fake)');
        }

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => 'Backup volume ' . $volume . ' ok (fake).',
            'strategy' => 'snapshot',
            'snapshot_id' => null,
            'bytes' => 42,
        ];
    }

    /**
     * @param callable(string,string):void|null $log
     * @return array<string,mixed>
     */
    public function restoreVolume(string $volume, string $snapshot, ?string $appId = null, ?callable $log = null): array
    {
        $this->note('restoreVolume:' . $volume . ':' . $snapshot . ':' . (string) $appId);

        if ((string) getenv('FAKE_BACKUP_RUNNER_FAIL') !== '') {
            throw new RuntimeException('fake runner restore gagal (uji)');
        }

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => 'Restore volume ' . $volume . ' selesai (fake).',
            'strategy' => 'snapshot',
        ];
    }

    /**
     * @param callable(string,string):void|null $log
     * @return array<string,mixed>
     */
    public function dbDump(string $volume, ?string $appId = null, ?callable $log = null): array
    {
        $this->note('dbDump:' . $volume . ':' . (string) $appId);

        if ((string) getenv('FAKE_BACKUP_RUNNER_FAIL') !== '') {
            throw new RuntimeException('fake runner dump gagal (uji)');
        }

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => 'Dump volume ' . $volume . ' selesai (fake).',
            'file' => '/tmp/fake-dump.sql',
            'bytes' => 7,
            'database' => 'demo',
        ];
    }
}
