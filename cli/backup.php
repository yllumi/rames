#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Worker backup/restore volume (PLAN_VOLUME_BACKUP.md §5.3).
 *
 *   php cli/backup.php run [volume] [trigger] [serverId]
 *   php cli/backup.php restore <appId|-> <volume> <snapshot> [serverId]
 *   php cli/backup.php restore-archived <volume> <snapshot> <targetName>
 *   php cli/backup.php db-dump <appId|-> <volume> [serverId]
 *
 * `run` dipanggil timer host (systemd, `trigger=schedule`) atau tombol "Backup
 * sekarang" (detached, `trigger=manual`), `restore` dipanggil tombol "Restore"
 * (detached), `restore-archived` dipanggil tombol "Restore arsip" (detached) —
 * membuat volume **baru** bernama `<targetName>` lalu memulihkan snapshot ke
 * dalamnya. Otorisasi **sudah** ditegakkan controller lewat `BackupAccess`
 * sebelum worker di-spawn; worker ini berjalan sebagai proses tepercaya (tanpa
 * session user).
 *
 * **Multi-server F4**: `serverId` (opsional) menentukan **di mana** pekerjaan
 * dijalankan. Kosong/`local` ⇒ jalur lokal (perilaku lama, tidak berubah).
 * Selain itu ⇒ `BackupDispatcher` mengirim job ke **server agent** itu
 * (`backup_run`/`restore`/`db_dump`) sehingga daemon & volume server itu yang
 * bekerja; worker ini **tidak** menyentuh Docker/restic host control plane
 * (plan §3 butir 4). Op yang belum didukung di agent (`restore-archived`)
 * ditolak dengan pesan jelas — bukan dijalankan lokal.
 *
 * Log ke runtime/logs/backup/ (per-project + cli.log). **Tidak ada kredensial**
 * di log (nilai S3/passphrase hanya lewat file 0600 milik restic/helper).
 */

use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupRegistry;
use app\library\Backup\ResticRunner;
use app\library\Backup\VolumeBackupService;
use app\library\Backup\VolumeRestoreService;
use app\library\Backup\VolumeStateGuard;
use app\library\Backup\VolumeTargetMap;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use app\library\Support\ProcessRunner;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$mode = (string) ($argv[1] ?? '');
if (!in_array($mode, ['run', 'restore', 'restore-archived', 'db-dump'], true)) {
    fwrite(STDERR, "Usage:\n  php cli/backup.php run [volume] [trigger] [serverId]\n  php cli/backup.php restore <appId|-> <volume> <snapshot> [serverId]\n  php cli/backup.php restore-archived <volume> <snapshot> <targetName>\n  php cli/backup.php db-dump <appId|-> <volume> [serverId]\n");
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

// ---------------------------------------------------------------------------
// Server agent (multi-server F4): jalankan pekerjaan DI server itu lewat job
// agent. Ditempatkan SEBELUM jalur lokal mana pun supaya worker ini tidak
// menyentuh Docker/restic host control plane untuk volume server agent.
// ---------------------------------------------------------------------------
$targetServerId = '';
if ($mode === 'run') {
    $targetServerId = (string) ($argv[4] ?? '');
} elseif ($mode === 'restore') {
    $targetServerId = (string) ($argv[5] ?? '');
} elseif ($mode === 'db-dump') {
    $targetServerId = (string) ($argv[4] ?? '');
}

try {
    BackupDispatcher::assertSupported($mode, $targetServerId);
} catch (\Throwable $e) {
    $cliLog($mode . ' DITOLAK: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!BackupDispatcher::isLocalServer($targetServerId)) {
    $dispatcher = BackupDispatcher::create();
    $serverId = trim($targetServerId);

    try {
        if ($mode === 'run') {
            $volume = (string) ($argv[2] ?? '');
            $trigger = (string) ($argv[3] ?? '');
            if (!in_array($trigger, ['schedule', 'manual'], true)) {
                $trigger = 'schedule';
            }
            $cliLog("run (agent {$serverId}) mulai volume={$volume} trigger={$trigger}");
            $result = $dispatcher->runBackup($serverId, $volume, $trigger, $projectLog);
        } elseif ($mode === 'restore') {
            $appArg = (string) ($argv[2] ?? '');
            $volume = (string) ($argv[3] ?? '');
            $snapshot = (string) ($argv[4] ?? '');
            $cliLog("restore (agent {$serverId}) mulai volume={$volume} snapshot={$snapshot}");
            $result = $dispatcher->restore($serverId, $appArg, $volume, $snapshot, $projectLog);
        } else {
            $appArg = (string) ($argv[2] ?? '');
            $volume = (string) ($argv[3] ?? '');
            $cliLog("db-dump (agent {$serverId}) mulai volume={$volume}");
            $result = $dispatcher->dbDump($serverId, $appArg, $volume, $projectLog);
        }

        $cliLog($mode . " (agent {$serverId}) selesai: " . (string) ($result['message'] ?? 'ok'));
        exit(0);
    } catch (\Throwable $e) {
        $cliLog($mode . " (agent {$serverId}) GAGAL: " . $e->getMessage());
        fwrite(STDERR, $mode . ' GAGAL: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

// mode db-dump (lokal): dump logis DB sebuah volume DI HOST INI. Jalur server
// agent sudah ditangani blok dispatcher di atas (`serverId` diisi).
if ($mode === 'db-dump') {
    $appArg = (string) ($argv[2] ?? '');
    $volume = (string) ($argv[3] ?? '');
    if ($volume === '') {
        fwrite(STDERR, "Usage: php cli/backup.php db-dump <appId|-> <volume> [serverId]\n");
        exit(1);
    }

    try {
        // Kredensial DB dibaca dari container (lewat `MYSQL_PWD`), berkas `.sql`
        // ditinggal di staging host ini — tidak ada kredensial di log/argv.
        $runnerClass = trim((string) getenv('RAMES_BACKUP_RUNNER_CLASS'));
        /** @var \app\library\Backup\BackupJobRunner $runner */
        $runner = ($runnerClass !== '' && class_exists($runnerClass))
            ? new $runnerClass()
            : new \app\library\Backup\BackupJobRunner();
        $result = $runner->dbDump($volume, $appArg === '-' ? null : $appArg, $projectLog);
        $cliLog('db-dump selesai: ' . (string) ($result['message'] ?? 'ok'));
        exit(0);
    } catch (\Throwable $e) {
        $cliLog('db-dump GAGAL: ' . $e->getMessage());
        fwrite(STDERR, 'db-dump GAGAL: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

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

// mode restore-archived: pulihkan volume arsip (sudah dihapus) ke volume BARU.
if ($mode === 'restore-archived') {
    $volume = (string) ($argv[2] ?? '');
    $snapshot = (string) ($argv[3] ?? '');
    $targetName = (string) ($argv[4] ?? '');
    if ($volume === '' || $snapshot === '' || $targetName === '') {
        fwrite(STDERR, "Usage: php cli/backup.php restore-archived <volume> <snapshot> <targetName>\n");
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

    $created = false;
    try {
        VolumeStateGuard::assertVolumeName($volume);
        VolumeStateGuard::assertVolumeName($targetName);
        ResticRunner::assertSnapshotId($snapshot);

        // Entri registry (store `backup` di SQLite) memiliki `project` asal — dipakai
        // sebagai label volume target & konteks log.
        $entry = (new BackupRegistry())->read()[$volume] ?? null;

        $targetExists = false;
        if ($entry !== null) {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
            foreach ($docker->listVolumes(['name' => [$targetName]]) as $candidate) {
                if (is_array($candidate) && (string) ($candidate['Name'] ?? '') === $targetName) {
                    $targetExists = true;
                    break;
                }
            }
        }

        // Satu sumber kebenaran gate (sama dengan controller).
        $plan = VolumeRestoreService::planArchiveRestore($entry, $targetExists);
        if (!$plan['ok']) {
            throw new RuntimeException($plan['msg']);
        }
        $project = (string) $plan['project'];
        VolumeStateGuard::assertProjectName($project);

        $projectLog($project, "restore-archived mulai volume={$volume} snapshot={$snapshot} target={$targetName}");

        // Buat volume target baru ber-label compose-project agar ikut tervalidasi
        // sebagai volume terkelola dashboard (array + bypass_shell, tanpa secret).
        // Bentuk argv = satu sumber kebenaran di `VolumeRestoreService` (mudah diuji).
        $dockerBinary = (string) config('deploy.docker_binary', 'docker');
        $result = (new ProcessRunner())->run(
            VolumeRestoreService::archiveVolumeCreateArgv($dockerBinary, $project, $targetName),
            null,
            60
        );
        if (($result['code'] ?? 1) !== 0) {
            $detail = trim((string) ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
            throw new RuntimeException('Gagal membuat volume target: ' . ($detail !== '' ? $detail : 'exit ' . (int) $result['code']));
        }
        $created = true;

        // Target sintetis: volume baru, tanpa app (yatim) → strategi `snapshot`.
        // `sourceVolume` = nama volume ASAL agar snapshot (tag `volume:<asal>`)
        // tetap ditemukan walau nama volume target berbeda.
        $target = [
            'name' => $targetName,
            'project' => $project,
            'app_id' => null,
            'app_name' => null,
            'orphaned' => true,
        ];

        $service = new VolumeRestoreService(null, null, null, null, null, [], $projectLog);
        $restore = $service->restore($target, null, $snapshot, $volume);

        $projectLog($project, 'restore-archived selesai: ' . (string) ($restore['message'] ?? 'ok'));
        exit(0);
    } catch (\Throwable $e) {
        // Bila restore gagal SETELAH volume dibuat: hapus volume target yang baru
        // kita buat (best-effort) agar tidak meninggalkan volume setengah jadi
        // ber-label project yang bisa disalahartikan sebagai data nyata. Snapshot
        // sumber tetap utuh di S3, jadi tidak ada data yang hilang. Bila hapus
        // gagal, volume dibiarkan + dicatat untuk inspeksi manual.
        if ($created) {
            $dockerBinary = (string) config('deploy.docker_binary', 'docker');
            $removed = (new ProcessRunner())->run(
                VolumeRestoreService::archiveVolumeRemoveArgv($dockerBinary, $targetName),
                null,
                60
            );
            $note = ($removed['code'] ?? 1) === 0
                ? "volume target \"{$targetName}\" dihapus"
                : "volume target \"{$targetName}\" GAGAL dihapus — periksa manual";
            $projectLog($project ?? $volume, 'restore-archived gagal setelah volume dibuat: ' . $note . ' — ' . $e->getMessage());
        }
        $projectLog($project ?? $volume, 'restore-archived GAGAL: ' . $e->getMessage());
        fwrite(STDERR, 'restore-archived GAGAL: ' . $e->getMessage() . PHP_EOL);
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
