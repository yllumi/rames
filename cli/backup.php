#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Worker backup/restore volume (PLAN_VOLUME_BACKUP.md §5.3).
 *
 *   php cli/backup.php run [volume] [trigger] [serverId]
 *   php cli/backup.php restore <appId|-> <volume> <snapshot> [serverId]
 *   php cli/backup.php restore-archived <volume> <snapshot> <targetName> [serverId]
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
 * (`backup_run`/`restore`/`db_dump`/`restore_archived`) sehingga daemon & volume
 * server itu yang bekerja; worker ini **tidak** menyentuh Docker/restic host
 * control plane (plan §3 butir 4).
 *
 * `run` **tanpa daftar volume** (pemicu berkala oleh timer host) juga
 * mendistribusikan volume terjadwal milik server agent ke server pemiliknya
 * (`BackupSelection::scheduledByServer()` ⇒ `BackupDispatcher`), sehingga satu
 * clock di control plane tetap cukup dan volume server agent benar-benar
 * ter-backup berkala.
 *
 * Log ke runtime/logs/backup/ (per-project + cli.log). **Tidak ada kredensial**
 * di log (nilai S3/passphrase hanya lewat file 0600 milik restic/helper).
 */

use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupSelection;
use app\library\Backup\VolumeArchiveRestore;
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
if (!in_array($mode, ['run', 'restore', 'restore-archived', 'db-dump'], true)) {
    fwrite(STDERR, "Usage:\n  php cli/backup.php run [volume] [trigger] [serverId]\n  php cli/backup.php restore <appId|-> <volume> <snapshot> [serverId]\n  php cli/backup.php restore-archived <volume> <snapshot> <targetName> [serverId]\n  php cli/backup.php db-dump <appId|-> <volume> [serverId]\n");
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
} elseif ($mode === 'restore-archived') {
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
        } elseif ($mode === 'restore-archived') {
            $volume = (string) ($argv[2] ?? '');
            $snapshot = (string) ($argv[3] ?? '');
            $targetName = (string) ($argv[4] ?? '');
            $cliLog("restore-archived (agent {$serverId}) mulai volume={$volume} snapshot={$snapshot} target={$targetName}");
            $result = $dispatcher->restoreArchived($serverId, $volume, $snapshot, $targetName, $projectLog);
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

    // -----------------------------------------------------------------------
    // Pemicu berkala (timer host) — **satu clock di control plane**: volume yang
    // terjadwal di **server agent** dikirim ke server pemiliknya sebagai job
    // agent (`BackupDispatcher`), sehingga host control plane tidak pernah
    // menjalankan restic untuk volume server agent. Volume server lokal
    // dijalankan lokal di bawah (`VolumeBackupService`) seperti sebelumnya.
    //
    // Hanya berlaku untuk run berkala TANPA daftar volume eksplisit: pemanggilan
    // manual (`run <volume> manual`) tetap memakai jalur yang diminta.
    //
    // Exit code = **hasil nyata per volume/server** (bukan status satu host):
    // sukses job agent tetap sukses walaupun Engine lokal mati, dan sebaliknya
    // kegagalan agent tidak pernah disembunyikan oleh jalur lokal (dan sebaliknya)
    // — supaya cron tidak menandai backup yang berhasil sebagai gagal, dan tidak
    // juga melaporkan sukses untuk pekerjaan yang benar-benar gagal.
    // -----------------------------------------------------------------------
    $agentFailures = 0;
    $agentOk = 0;
    if ($volume === '' && $trigger === 'schedule') {
        $scheduled = (new BackupSelection())->scheduledByServer();
        foreach ($scheduled as $scheduledServerId => $names) {
            if (BackupDispatcher::isLocalServer((string) $scheduledServerId)) {
                continue; // server ini: ditangani jalur lokal di bawah
            }
            $serverId = trim((string) $scheduledServerId);
            $dispatcher = BackupDispatcher::create();
            foreach ($names as $name) {
                try {
                    $cliLog("run (agent {$serverId}) mulai volume={$name} trigger=schedule");
                    $result = $dispatcher->runBackup($serverId, (string) $name, 'schedule', $projectLog);
                    $agentOk++;
                    $cliLog("run (agent {$serverId}) selesai volume={$name}: " . (string) ($result['message'] ?? 'ok'));
                } catch (\Throwable $e) {
                    $agentFailures++;
                    $cliLog("run (agent {$serverId}) GAGAL volume={$name}: " . $e->getMessage());
                    fwrite(STDERR, "gagal: volume={$name} server={$serverId} — " . $e->getMessage() . PHP_EOL);
                }
            }
        }
    }

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
        $localFailed = (int) ($summary['totals']['failed'] ?? 0);
        if ($localFailed > 0) {
            // Identitas kegagalan nyata di server lokal (volume + server) supaya
            // operator/cron tahu apa yang harus diulang.
            foreach ((array) ($summary['volumes'] ?? []) as $row) {
                if (!is_array($row) || (string) ($row['status'] ?? '') === 'ok' || (string) ($row['status'] ?? '') === 'skipped') {
                    continue;
                }
                fwrite(STDERR, sprintf(
                    "gagal: volume=%s server=lokal project=%s%s\n",
                    (string) ($row['name'] ?? '?'),
                    (string) ($row['project'] ?? '?'),
                    ($row['error'] ?? null) !== null ? ' — ' . (string) $row['error'] : ''
                ));
            }
        }
        exit(($localFailed === 0 && $agentFailures === 0) ? 0 : 1);
    } catch (\Throwable $e) {
        // Jalur lokal gagal (mis. Engine lokal mati, atau lock dipegang run lain):
        // pekerjaan server agent di atas **sudah** selesai dan tidak diulang.
        // Exit code mengikuti hasil nyata: bila ada job agent yang sukses dan
        // tidak ada kegagalan agent, run ini **tidak** dianggap gagal hanya
        // karena host lokal tidak terbaca (cron jangan menandai backup server
        // agent gagal). Bila tidak ada pekerjaan agent yang berhasil, ini
        // kegagalan nyata ⇒ exit non-nol.
        $cliLog('run lokal GAGAL (agent ok=' . $agentOk . ', gagal=' . $agentFailures . '): ' . $e->getMessage());
        fwrite(STDERR, 'gagal: server=lokal — ' . $e->getMessage() . PHP_EOL);
        if ($agentOk > 0 && $agentFailures === 0) {
            fwrite(STDERR, 'catatan: ' . $agentOk . ' job backup server agent sudah sukses; exit code mengikuti hasil itu.' . PHP_EOL);
            exit(0);
        }
        exit(1);
    }
}

// mode restore-archived: pulihkan volume arsip (sudah dihapus) ke volume BARU.
// Logika ada di library (`VolumeArchiveRestore`) agar jalur server agent
// (`cli/deploy.php … restore_archived`) memakai implementasi yang SAMA —
// termasuk `VolumeStateGuard` (snapshot filesystem hanya sah saat container
// berhenti) dan pembersihan volume target bila restore gagal.
if ($mode === 'restore-archived') {
    $volume = (string) ($argv[2] ?? '');
    $snapshot = (string) ($argv[3] ?? '');
    $targetName = (string) ($argv[4] ?? '');
    if ($volume === '' || $snapshot === '' || $targetName === '') {
        fwrite(STDERR, "Usage: php cli/backup.php restore-archived <volume> <snapshot> <targetName> [serverId]\n");
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
        $result = (new VolumeArchiveRestore(null, null, null, null, null, $projectLog))
            ->run($volume, $snapshot, $targetName);
        $cliLog('restore-archived selesai: ' . (string) ($result['message'] ?? 'ok'));
        exit(0);
    } catch (\Throwable $e) {
        $cliLog('restore-archived GAGAL: ' . $e->getMessage());
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
