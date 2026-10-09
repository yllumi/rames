#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI basis data SQLite (fondasi migrasi JSON → SQLite).
 *
 *   php cli/db.php status                 # ringkasan berkas, versi skema, jumlah baris
 *   php cli/db.php migrate                # jalankan migrasi yang tertunda
 *   php cli/db.php import-json [--force]  # impor sekali dari JSON lama (idempoten)
 *   php cli/db.php export-json [--dir=...]# ekspor balik ke 4 berkas JSON
 *   php cli/db.php integrity              # PRAGMA integrity_check
 *   php cli/db.php backup [--reason=...]  # snapshot + retensi + status
 *   php cli/db.php list                   # daftar snapshot (terbaru dulu)
 *   php cli/db.php restore <berkas> --yes # pulihkan DB dari snapshot
 *   php cli/db.php prune                  # jalankan retensi saja
 *
 * Gunakan `RAMES_DB_FILE=/tmp/...` (dan `RAMES_DB_BACKUP_DIR=/tmp/...`) agar
 * tidak menyentuh basis data nyata.
 */

use app\library\Storage\DbBackup;
use app\library\Storage\JsonImporter;
use app\library\Storage\SchemaMigrations;
use app\library\Storage\SqliteDatabase;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

/** @var callable(string):void $out */
$out = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};
/** @var callable(string):void $err */
$err = static function (string $line): void {
    fwrite(STDERR, $line . PHP_EOL);
};

$usage = <<<TXT
Usage:
  php cli/db.php status
  php cli/db.php migrate
  php cli/db.php import-json [--force]
  php cli/db.php export-json [--dir=<dir>]
  php cli/db.php integrity
  php cli/db.php backup [--reason=<alasan>]
  php cli/db.php list
  php cli/db.php restore <berkas> --yes
  php cli/db.php prune

TXT;

$mode = (string) ($argv[1] ?? '');
if (!in_array($mode, ['status', 'migrate', 'import-json', 'export-json', 'integrity', 'backup', 'list', 'restore', 'prune'], true)) {
    $err($usage);
    exit(1);
}

$options = array_slice($argv, 2);
$force = in_array('--force', $options, true);
$yes = in_array('--yes', $options, true);
$reason = 'manual';
$dir = null;
$restoreName = null;
foreach ($options as $option) {
    if (str_starts_with($option, '--dir=')) {
        $dir = substr($option, strlen('--dir='));
    } elseif (str_starts_with($option, '--reason=')) {
        $reason = substr($option, strlen('--reason='));
    } elseif (!str_starts_with($option, '--')) {
        $restoreName ??= $option;
    }
}

/** @var callable(callable):void $runGuarded jalankan aksi; exit non-zero saat gagal */
$runGuarded = static function (callable $action) use ($err): void {
    try {
        $action();
    } catch (Throwable $e) {
        $err('Gagal: ' . $e->getMessage());
        exit(1);
    }
};

/**
 * Apakah masih ada berkas JSON lama yang belum diimpor ke SQLite (untuk
 * peringatan deploy satu baris di `status`).
 *
 * @var callable(PDO):bool $legacyImportPending
 */
$legacyImportPending = static function (PDO $pdo): bool {
    $dir = trim((string) config('deploy.database_path', ''));
    if ($dir === '') {
        return false;
    }
    $dir = rtrim($dir, '/');
    $found = false;
    foreach (['apps.json', 'auth.json', 'billing.json', 'backup.json'] as $name) {
        if (is_file($dir . '/' . $name)) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT "value" FROM "kv" WHERE "key" = ?');
        $stmt->execute(['legacy_imported_at']);

        return $stmt->fetchColumn() === false;
    } catch (Throwable) {
        return true; // tabel kv belum ada → jelas belum diimpor
    }
};

try {
    $db = new SqliteDatabase();
} catch (Throwable $e) {
    $err('Gagal membuka basis data: ' . $e->getMessage());
    exit(1);
}

$file = $db->file();

switch ($mode) {
    case 'status':
        $existedBefore = is_file($file);
        $pdo = $db->pdo();
        $out('file           : ' . $file);
        $out('exists         : ' . (is_file($file)
            ? 'yes' . ($existedBefore ? '' : ' (dibuat oleh perintah ini)')
            : 'no'));
        $out('journal_mode   : ' . (string) $pdo->query('PRAGMA journal_mode')->fetchColumn());
        $out('busy_timeout   : ' . (string) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $out('user_version   : ' . (string) $pdo->query('PRAGMA user_version')->fetchColumn());
        $out('migrations     : ' . implode(',', $pdo->query('SELECT "version" FROM "schema_migrations" ORDER BY "version"')->fetchAll(PDO::FETCH_COLUMN)));
        foreach (SchemaMigrations::storeDefinitions() as $store => $collections) {
            foreach ($collections as $collection) {
                $table = SchemaMigrations::tableName($store, (string) $collection['table']);
                $count = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
                $out(sprintf('rows %-16s: %d', $store . '/' . $collection['table'], $count));
            }
        }
        $backup = new DbBackup();
        $snapshots = $backup->list();
        $totalBytes = array_sum(array_column($snapshots, 'bytes'));
        $latest = $snapshots[0] ?? null;
        $out('backup_dir     : ' . $backup->backupDir());
        $out('backup_latest  : ' . ($latest !== null ? $latest['file'] . ' (' . $latest['bytes'] . ' B, ' . $latest['at'] . ')' : '-'));
        $out('backup_count   : ' . count($snapshots));
        $out('backup_bytes   : ' . $totalBytes);
        $state = $backup->state();
        $out('backup_last_at : ' . (string) ($state['last_run_at'] ?? '-'));
        $out('backup_error   : ' . (string) ($state['error'] ?? '-'));
        if ($legacyImportPending($pdo)) {
            $out('WARNING        : berkas JSON lama ada tetapi DB belum diimpor — jalankan: php cli/db.php import-json');
        }
        break;

    case 'migrate':
        $applied = SchemaMigrations::migrate($db->pdo());
        $out('migrasi diterapkan: ' . $applied);
        break;

    case 'import-json':
        $summary = (new JsonImporter())->importIfNeeded($file, $force);
        $out('skipped : ' . ($summary['skipped'] ? 'yes' : 'no'));
        $out('apps    : ' . $summary['apps']);
        $out('users   : ' . $summary['users']);
        $out('billing : ' . ($summary['billing'] ? 'yes' : 'no'));
        $out('backup  : ' . ($summary['backup'] ? 'yes' : 'no'));
        break;

    case 'export-json':
        $files = (new JsonImporter())->exportToJson($dir ?? dirname($file));
        foreach ($files as $written) {
            $out('wrote: ' . $written);
        }
        break;

    case 'integrity':
        $out('integrity_check: ' . ($db->integrityCheck() ? 'ok' : 'FAILED'));
        break;

    case 'backup':
        $runGuarded(static function () use ($out, $reason): void {
            $backup = new DbBackup();
            $before = count($backup->list());
            $entry = $backup->run($reason);
            if (!empty($entry['skipped'])) {
                $out('backup lain sedang berjalan — run ini dilewati (busy).');
                return;
            }
            $after = count($backup->list());
            $out('snapshot : ' . $entry['file']);
            $out('bytes    : ' . $entry['bytes']);
            $out('snapshots: ' . $after);
            $out('pruned   : ' . max(0, $before + 1 - $after));
        });
        break;

    case 'list':
        $runGuarded(static function () use ($out): void {
            $snapshots = (new DbBackup())->list();
            if ($snapshots === []) {
                $out('(belum ada snapshot)');
                return;
            }
            foreach ($snapshots as $snapshot) {
                $out(sprintf('%-40s %10d B  %s', $snapshot['file'], $snapshot['bytes'], $snapshot['at']));
            }
        });
        break;

    case 'restore':
        if (!$yes) {
            $err('Restore menimpa basis data aktif. Ulangi dengan --yes untuk melanjutkan.');
            exit(1);
        }
        if ($restoreName === null || $restoreName === '') {
            $err($usage);
            exit(1);
        }
        $runGuarded(static function () use ($out, $restoreName): void {
            $result = (new DbBackup())->restore($restoreName);
            $out('restored : ' . $result['file']);
            $out('bytes    : ' . $result['bytes']);
            $out('PENTING  : worker perlu reload (php start.php reload) agar memakai DB baru.');
            $out('safety   : snapshot pre-restore dibuat di direktori backup.');
        });
        break;

    case 'prune':
        $runGuarded(static function () use ($out): void {
            $result = (new DbBackup())->prune();
            if (!empty($result['skipped'])) {
                $out('backup lain sedang berjalan — retensi dilewati (busy).');
                return;
            }
            $out('removed  : ' . $result['removed']);
            $out('kept     : ' . $result['kept']);
        });
        break;
}

exit(0);
