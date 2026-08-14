#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker deploy site.
 *
 *   php cli/deploy.php <siteId> [deploy|rebuild]
 *
 * Dipanggil detached oleh SiteController (proc_open) supaya request HTTP tidak
 * terblokir oleh operasi build yang lama. Pipeline:
 *   - muat site dari sites.json
 *   - update status tiap tahap (deploying / running / error)
 *   - jalankan deployer (deploy/rebuild)
 *   - simpan hasil (containers, status) kembali ke sites.json
 * Log per-site ditulis ke runtime/logs/deploy/{siteId}.log.
 */

use app\library\Deploy\DeployerFactory;
use app\library\Nginx\NginxReloader;
use app\library\Storage\SiteStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$siteId = $argv[1] ?? '';
$mode = $argv[2] ?? 'deploy';

if ($siteId === '' || !in_array($mode, ['deploy', 'rebuild'], true)) {
    fwrite(STDERR, "Usage: php cli/deploy.php <siteId> [deploy|rebuild]\n");
    exit(1);
}

$store = new SiteStore();
$site = $store->find($siteId);
if ($site === null) {
    fwrite(STDERR, "Site tidak ditemukan: {$siteId}\n");
    exit(1);
}

$logDir = runtime_path('logs/deploy');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/' . $siteId . '.log';
file_put_contents($logFile, '[' . date('c') . "] start mode={$mode}\n", FILE_APPEND);

/** @var callable(string,string):void */
$logger = function (string $stage, string $message) use ($store, $siteId, $logFile): void {
    file_put_contents($logFile, '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL, FILE_APPEND);
    $store->update($siteId, function (array &$s) use ($stage, $message): void {
        $s['status'] = 'deploying';
        $s['stage'] = $stage;
        $s['message'] = $message;
        $s['error'] = null;
    });
};

try {
    $deployer = DeployerFactory::create();
    $site = $mode === 'rebuild'
        ? $deployer->rebuild($site, $logger)
        : $deployer->deploy($site, $logger);

    $store->update($siteId, function (array &$s) use ($site): void {
        $s['status'] = (string) ($site['status'] ?? 'running');
        $s['stage'] = null;
        $s['message'] = $s['status'] === 'running' ? 'Running' : (string) ($site['message'] ?? '');
        $s['error'] = null;
        $s['containers'] = $site['containers'] ?? [];
    });

    // Reload nginx host agar config baru (subdomain/custom domain) aktif.
    // Best-effort: kegagalan reload tidak menggagalkan deploy — hanya dicatat.
    try {
        $reload = (new NginxReloader())->reload();
        file_put_contents($logFile, '[' . date('c') . "] [nginx] " . ($reload['ok'] ? 'Reload Nginx berhasil.' : 'Reload Nginx GAGAL: ' . ($reload['error'] ?? '')) . "\n", FILE_APPEND);
    } catch (\Throwable $e) {
        file_put_contents($logFile, '[' . date('c') . "] [nginx] Reload Nginx gagal: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    file_put_contents($logFile, '[' . date('c') . "] done\n", FILE_APPEND);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    file_put_contents($logFile, '[' . date('c') . "] ERROR: {$msg}\n", FILE_APPEND);
    try {
        $store->update($siteId, function (array &$s) use ($msg): void {
            $s['status'] = 'error';
            $s['stage'] = null;
            $s['message'] = $msg;
            $s['error'] = $msg;
        });
    } catch (\Throwable $ignored) {
        // abaikan jika store juga bermasalah
    }
    exit(1);
}
