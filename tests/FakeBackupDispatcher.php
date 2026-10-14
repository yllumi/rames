<?php
declare(strict_types=1);

namespace Tests;

use app\library\Backup\BackupDispatcher;
use RuntimeException;

/**
 * Pengganti `BackupDispatcher` untuk uji **subproses** `cli/backup.php`
 * (diaktifkan lewat env `RAMES_BACKUP_DISPATCHER_CLASS`, seam uji pola
 * `DEPLOYER_CLASS`). Tidak menyentuh jaringan/Docker/restic.
 *
 * Setiap pemanggilan dicatat ke berkas `FAKE_BACKUP_DISPATCH_LOG` sehingga uji
 * dapat membuktikan **dipicu sebagai job server agent** (server id + volume +
 * snapshot) dan — pada uji regresi jalur lokal — membuktikan dispatcher **tidak
 * pernah** dipanggil. Env `FAKE_BACKUP_DISPATCH_FAIL` ⇒ method melempar.
 */
class FakeBackupDispatcher extends BackupDispatcher
{
    private function note(string $entry): void
    {
        $path = (string) getenv('FAKE_BACKUP_DISPATCH_LOG');
        if ($path !== '') {
            @file_put_contents($path, $entry . "\n", FILE_APPEND);
        }
    }

    /**
     * @param callable(string,string):void|null $log
     * @return array<string,mixed>
     */
    #[\Override]
    public function runBackup(string $serverId, string $volume, string $trigger = 'manual', ?callable $log = null): array
    {
        $this->note('runBackup:' . $serverId . ':' . $volume . ':' . $trigger);

        if ((string) getenv('FAKE_BACKUP_DISPATCH_FAIL') !== '') {
            throw new RuntimeException('fake dispatcher gagal (uji)');
        }

        return ['volume' => $volume, 'status' => 'ok', 'message' => 'Backup (fake) dikirim ke ' . $serverId . '.', 'server_id' => $serverId];
    }

    /**
     * @param callable(string,string):void|null $log
     * @return array<string,mixed>
     */
    #[\Override]
    public function restore(string $serverId, string $appId, string $volume, string $snapshot, ?callable $log = null): array
    {
        $this->note('restore:' . $serverId . ':' . $appId . ':' . $volume . ':' . $snapshot);

        if ((string) getenv('FAKE_BACKUP_DISPATCH_FAIL') !== '') {
            throw new RuntimeException('fake dispatcher gagal (uji)');
        }

        return ['volume' => $volume, 'status' => 'ok', 'message' => 'Restore (fake) dikirim ke ' . $serverId . '.', 'server_id' => $serverId];
    }

    /**
     * @param callable(string,string):void|null $log
     * @return array<string,mixed>
     */
    #[\Override]
    public function dbDump(string $serverId, ?string $appId, string $volume, ?callable $log = null): array
    {
        $this->note('dbDump:' . $serverId . ':' . (string) $appId . ':' . $volume);

        if ((string) getenv('FAKE_BACKUP_DISPATCH_FAIL') !== '') {
            throw new RuntimeException('fake dispatcher gagal (uji)');
        }

        return ['volume' => $volume, 'status' => 'ok', 'message' => 'Dump (fake) dikirim ke ' . $serverId . '.', 'server_id' => $serverId];
    }

    /**
     * @param callable(string,string):void|null $log
     * @return array<string,mixed>
     */
    #[\Override]
    public function restoreArchived(
        string $serverId,
        string $volume,
        string $snapshot,
        string $targetName,
        ?callable $log = null,
    ): array {
        $this->note('restoreArchived:' . $serverId . ':' . $volume . ':' . $snapshot . ':' . $targetName);

        if ((string) getenv('FAKE_BACKUP_DISPATCH_FAIL') !== '') {
            throw new RuntimeException('fake dispatcher gagal (uji)');
        }

        return [
            'volume' => $volume,
            'status' => 'ok',
            'message' => 'Restore arsip (fake) dikirim ke ' . $serverId . '.',
            'server_id' => $serverId,
        ];
    }
}
