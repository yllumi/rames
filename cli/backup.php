#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Worker backup/restore volume (PLAN_VOLUME_BACKUP.md §5.3).
 *
 *   php cli/backup.php run [volume] [trigger]
 *   php cli/backup.php restore <appId|-> <volume> <snapshot>
 *
 * `run` dipanggil timer host (systemd, `trigger=schedule`) atau tombol "Backup
 * sekarang" (detached, `trigger=manual`), `restore` dipanggil tombol "Restore"
 * (detached). Otorisasi **sudah** ditegakkan controller lewat `BackupAccess`
 * sebelum worker di-spawn; worker ini berjalan sebagai proses tepercaya (tanpa
 * session user).
 *
 * Log ke runtime/logs/backup/ (per-project + cli.log). **Tidak ada kredensial**
 * di log (nilai S3/passphrase hanya lewat file 0600 milik restic/helper).
 */

use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeTargetMap;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$mode = (string) ($argv[1] ?? '');
if (!in_array($mode, ['run', 'restore'], true)) {
    fwrite(STDERR, "Usage:\n  php cli/backup.php run [volume] [trigger]\n  php cli/backup.php restore <appId|-> <volume> <snapshot>\n");
    exit(1);
}

$logDir = runtime_path('logs/backup');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

/** @var callable(string):void $cliLog */
$cliLog = static function (string $message) use ($logDir): void {
    @file_put_contents($logDir . '/cli.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
};

if ($mode === 'run') {
    $volume = (string) ($argv[2] ?? '');
    // Trigger hanya boleh `schedule` (timer host, default) atau `manual`
    // (tombol "Backup sekarang") — nilai lain diabaikan ke default.
    $trigger = (string) ($argv[3] ?? '');
    if (!in_array($trigger, ['schedule', 'manual'], true)) {
        $trigger = 'schedule';
    }

    /** @var callable(string,string):void $projectLog */
    $projectLog = static function (string $project, string $message) use ($logDir, $cliLog): void {
        $name = $project !== '' ? $project : 'orphan';
        @file_put_contents(
            $logDir . '/' . $name . '.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
        $cliLog($name . ' ' . $message);
    };

    try {
        $service = new VolumeBackupService(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            [],
            $projectLog
        );
        $options = [
            'trigger' => $trigger,
            'host' => gethostname() ?: 'rames',
            'volumes' => $volume !== '' ? [$volume] : null,
        ];
        $summary = $service->run($options);
        $cliLog(sprintf(
            'run selesai status=%s ok=%d gagal=%d dilewati=%d',
            (string) ($summary['status'] ?? '?'),
            (int) ($summary['totals']['ok'] ?? 0),
            (int) ($summary['totals']['failed'] ?? 0),
            (int) ($summary['totals']['skipped'] ?? 0)
        ));
        exit(((int) ($summary['totals']['failed'] ?? 0)) === 0 ? 0 : 1);
    } catch (\Throwable $e) {
        $cliLog('run GAGAL: ' . $e->getMessage());
        fwrite(STDERR, 'run GAGAL: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

// mode restore
$appId = (string) ($argv[2] ?? '');
$volume = (string) ($argv[3] ?? '');
$snapshot = (string) ($argv[4] ?? '');
if ($volume === '' || $snapshot === '') {
    fwrite(STDERR, "Usage: php cli/backup.php restore <appId|-> <volume> <snapshot>\n");
    exit(1);
}

/** @var callable(string,string):void $projectLog */
$projectLog = static function (string $project, string $message) use ($logDir, $cliLog): void {
    $name = $project !== '' ? $project : 'orphan';
    @file_put_contents(
        $logDir . '/' . $name . '.log',
        '[' . date('c') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
    $cliLog($name . ' ' . $message);
};

try {
    $apps = (new AppStore())->all();
    $app = null;
    if ($appId !== '' && $appId !== '-') {
        foreach ($apps as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $appId) {
                $app = $candidate;
                break;
            }
        }
        if ($app === null) {
            throw new RuntimeException("App tidak ditemukan: {$appId}");
        }
    }

    // Volume → target (project/app_id/orphaned) dari label Engine — sumber
    // kebenaran pemetaan yang sama dengan backup.
    $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
    $targets = VolumeTargetMap::build($docker->listVolumes(['name' => [$volume]]), $apps);
    $target = $targets[0] ?? null;
    if ($target === null) {
        throw new RuntimeException("Volume \"{$volume}\" tidak ditemukan atau bukan volume compose yang dikelola dashboard.");
    }
    if ($app !== null && (string) ($target['app_id'] ?? '') !== $appId) {
        throw new RuntimeException("Volume \"{$volume}\" bukan milik app {$appId} — restore dibatalkan.");
    }
    if ($app === null && empty($target['orphaned'])) {
        throw new RuntimeException("Volume \"{$volume}\" milik app lain — restore dibatalkan.");
    }

    $projectLog((string) $target['project'], "restore mulai volume={$volume} snapshot={$snapshot}");

    $service = new VolumeRestoreService(null, null, null, null, null, [], $projectLog);
    $result = $service->restore($target, $app, $snapshot);

    $projectLog((string) $target['project'], 'restore selesai: ' . (string) ($result['message'] ?? 'ok'));
    exit(0);
} catch (\Throwable $e) {
    $projectLog($volume, 'restore GAGAL: ' . $e->getMessage());
    fwrite(STDERR, 'restore GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
