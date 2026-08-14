#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker SSL (Let's Encrypt) — SPECS.md §8a.
 *
 *   php cli/ssl.php <siteId>
 *
 * Dipanggil detached oleh SslController (proc_open) supaya request HTTP tidak
 * terblokir oleh proses certbot yang bisa lama. Pipeline:
 *   - muat site dari sites.json, tentukan domain = subdomain site
 *   - validasi domain publik
 *   - jalankan certbot certonly (http webroot / dns-cloudflare)
 *   - sukses: update ssl_status=active + ssl_expires_at, tulis ulang config
 *     Nginx dengan blok listen 443 ssl (dashboard tetap satu-satunya penulis .conf)
 *   - gagal: ssl_status=failed + pesan error; HTTP tetap jalan, tombol Retry tampil
 * Log per-site: runtime/logs/ssl/{siteId}.log
 */

use app\library\Deploy\DeployerFactory;
use app\library\SSL\SslIssuer;
use app\library\Storage\SiteStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$siteId = $argv[1] ?? '';
if ($siteId === '') {
    fwrite(STDERR, "Usage: php cli/ssl.php <siteId>\n");
    exit(1);
}

$store = new SiteStore();
$site = $store->find($siteId);
if ($site === null) {
    fwrite(STDERR, "Site tidak ditemukan: {$siteId}\n");
    exit(1);
}

$logDir = runtime_path('logs/ssl');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/' . $siteId . '.log';
file_put_contents($logFile, '[' . date('c') . "] start\n", FILE_APPEND);

/** @var callable(string,string):void */
$logger = function (string $stage, string $message) use ($store, $siteId, $logFile): void {
    file_put_contents($logFile, '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL, FILE_APPEND);
    $store->update($siteId, function (array &$s) use ($stage, $message): void {
        $s['ssl_status'] = 'pending';
        $s['ssl_stage'] = $stage;
        $s['ssl_message'] = $message;
        $s['ssl_error'] = null;
    });
};

try {
    $domain = site_subdomain($site['name']);
    if (!SslIssuer::isPublicDomain($domain)) {
        throw new RuntimeException("Domain {$domain} bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).");
    }

    $logger('issue', "Menerbitkan sertifikat untuk {$domain} ...");
    (new SslIssuer())->issue($domain);

    $expires = (new SslIssuer())->certExpiry($domain);
    $store->update($siteId, function (array &$s) use ($expires): void {
        $s['ssl_status'] = 'active';
        $s['ssl_stage'] = null;
        $s['ssl_message'] = null;
        $s['ssl_error'] = null;
        $s['ssl_expires_at'] = $expires;
        $s['needs_ssl'] = false;
    });

    $logger('nginx', 'Menulis ulang config Nginx dengan SSL ...');
    $site = $store->find($siteId);
    if ($site !== null) {
        DeployerFactory::create()->writeNginxConfig($site);
    }

    file_put_contents($logFile, '[' . date('c') . "] done\n", FILE_APPEND);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    file_put_contents($logFile, '[' . date('c') . "] ERROR: {$msg}\n", FILE_APPEND);
    try {
        $store->update($siteId, function (array &$s) use ($msg): void {
            $s['ssl_status'] = 'failed';
            $s['ssl_stage'] = null;
            $s['ssl_message'] = null;
            $s['ssl_error'] = $msg;
        });
    } catch (\Throwable $ignored) {
        // abaikan jika store juga bermasalah
    }
    exit(1);
}
