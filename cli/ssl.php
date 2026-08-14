#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker SSL (Let's Encrypt) — SPECS.md §8a.
 *
 *   php cli/ssl.php <siteId> [domain]
 *
 * Dipanggil detached oleh SslController (proc_open) supaya request HTTP tidak
 * terblokir oleh proses certbot yang bisa lama. Pipeline:
 *   - muat site dari sites.json; tentukan domain target & slot SSL:
 *       domain == site.custom_domain -> slot `custom_ssl` (SSL custom domain)
 *       domain == subdomain          -> slot `ssl` (SSL subdomain bawaan)
 *       tanpa argumen                -> custom domain bila ada, selain itu subdomain
 *   - validasi domain publik
 *   - jalankan certbot certonly (http webroot / dns-cloudflare)
 *   - sukses: update {slot}_status=active + {slot}_expires_at, tulis ulang config
 *     Nginx (dashboard tetap satu-satunya penulis .conf)
 *   - gagal: {slot}_status=failed + pesan error; HTTP tetap jalan, tombol Retry tampil
 * Log per-site: runtime/logs/ssl/{siteId}.log
 */

use app\library\Deploy\DeployerFactory;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslIssuer;
use app\library\Storage\SiteStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$siteId = $argv[1] ?? '';
$domainArg = $argv[2] ?? '';
if ($siteId === '') {
    fwrite(STDERR, "Usage: php cli/ssl.php <siteId> [domain]\n");
    exit(1);
}

$store = new SiteStore();
$site = $store->find($siteId);
if ($site === null) {
    fwrite(STDERR, "Site tidak ditemukan: {$siteId}\n");
    exit(1);
}

// Tentukan domain target & slot SSL (subdomain -> `ssl`, custom domain -> `custom_ssl`)
$subdomain = site_subdomain($site['name']);
$customDomain = (string) ($site['custom_domain'] ?? '');

if ($domainArg !== '') {
    if ($domainArg === $customDomain) {
        $slot = 'custom_ssl';
        $domain = $customDomain;
    } elseif ($domainArg === $subdomain) {
        $slot = 'ssl';
        $domain = $subdomain;
    } else {
        fwrite(STDERR, "Domain {$domainArg} bukan subdomain/custom domain site {$siteId}.\n");
        exit(1);
    }
} else {
    // tanpa argumen: prioritaskan custom domain bila ada, selain itu subdomain
    $slot = $customDomain !== '' ? 'custom_ssl' : 'ssl';
    $domain = $customDomain !== '' ? $customDomain : $subdomain;
}

$logDir = runtime_path('logs/ssl');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/' . $siteId . '.log';
file_put_contents($logFile, '[' . date('c') . "] start domain={$domain} slot={$slot}\n", FILE_APPEND);

/** @var callable(string,string):void */
$logger = function (string $stage, string $message) use ($store, $siteId, $logFile, $slot): void {
    file_put_contents($logFile, '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL, FILE_APPEND);
    $store->update($siteId, function (array &$s) use ($stage, $message, $slot): void {
        $s[$slot . '_status'] = 'pending';
        $s[$slot . '_stage'] = $stage;
        $s[$slot . '_message'] = $message;
        $s[$slot . '_error'] = null;
    });
};

try {
    if (!SslIssuer::isPublicDomain($domain)) {
        throw new RuntimeException("Domain {$domain} bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).");
    }

    $logger('issue', "Menerbitkan sertifikat untuk {$domain} ...");
    (new SslIssuer())->issue($domain);

    $expires = (new SslIssuer())->certExpiry($domain);
    $store->update($siteId, function (array &$s) use ($expires, $slot): void {
        $s[$slot . '_status'] = 'active';
        $s[$slot . '_stage'] = null;
        $s[$slot . '_message'] = null;
        $s[$slot . '_error'] = null;
        $s[$slot . '_expires_at'] = $expires;
        $s[$slot === 'custom_ssl' ? 'custom_needs_ssl' : 'needs_ssl'] = false;
    });

    // Tulis ulang config Nginx + reload nginx host (best-effort). Catatan: JANGAN
    // memakai $logger di sini — logger selalu mengeset status ke 'pending' dan
    // akan menimpa 'active' yang baru saja disimpan.
    file_put_contents($logFile, '[' . date('c') . "] [nginx] Menulis ulang config Nginx dengan SSL ...\n", FILE_APPEND);
    $site = $store->find($siteId);
    if ($site !== null) {
        DeployerFactory::create()->writeNginxConfig($site);
        try {
            $reload = (new NginxReloader())->reload();
            file_put_contents($logFile, '[' . date('c') . "] [nginx] " . ($reload['ok'] ? 'Reload Nginx berhasil.' : 'Reload Nginx GAGAL: ' . ($reload['error'] ?? '')) . "\n", FILE_APPEND);
        } catch (\Throwable $e) {
            file_put_contents($logFile, '[' . date('c') . "] [nginx] Reload Nginx gagal: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    file_put_contents($logFile, '[' . date('c') . "] done\n", FILE_APPEND);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    file_put_contents($logFile, '[' . date('c') . "] ERROR: {$msg}\n", FILE_APPEND);
    try {
        $store->update($siteId, function (array &$s) use ($msg, $slot): void {
            $s[$slot . '_status'] = 'failed';
            $s[$slot . '_stage'] = null;
            $s[$slot . '_message'] = null;
            $s[$slot . '_error'] = $msg;
        });
    } catch (\Throwable $ignored) {
        // abaikan jika store juga bermasalah
    }
    exit(1);
}
