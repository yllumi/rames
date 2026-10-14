#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker SSL (Let's Encrypt) — SPECS.md §8a.
 *
 *   php cli/ssl.php <appId> [domain]
 *
 * Dipanggil detached oleh SslController (proc_open) supaya request HTTP tidak
 * terblokir oleh proses certbot yang bisa lama. Pipeline:
 *   - muat app dari apps.json; tentukan domain target & slot SSL:
 *       domain == app.custom_domain -> slot `custom_ssl` (SSL custom domain)
 *       domain == subdomain          -> slot `ssl` (SSL subdomain bawaan)
 *       tanpa argumen                -> custom domain bila ada, selain itu subdomain
 *   - validasi domain publik
 *   - jalankan certbot certonly (http webroot / dns-cloudflare) **di server
 *     pemilik app** (multi-server F2): app lokal ⇒ certbot di host ini; app
 *     server `kind=agent` ⇒ job `ssl_issue` di server itu (certbot +
 *     /etc/letsencrypt server itu, Nginx server itu di-reload di sana) lalu
 *     state SSL-nya ditarik kembali ke control plane
 *   - sukses: update {slot}_status=active + {slot}_expires_at, tulis ulang config
 *     Nginx (dashboard tetap satu-satunya penulis .conf — di server tempat app
 *     berjalan)
 *   - gagal: {slot}_status=failed + pesan error; HTTP tetap jalan, tombol Retry tampil
 * Log per-app: runtime/logs/ssl/{appId}.log
 *
 * Kredensial DNS (Cloudflare) tidak pernah dikirim lewat HTTP/payload job: agent
 * memakai berkas kredensial yang sudah ada di server itu (CLOUDFLARE_CREDS pada
 * konfigurasi server tersebut) — jadi tidak ada secret di JSON/log/UI.
 */

use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\ServerResolver;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$appId = $argv[1] ?? '';
$domainArg = $argv[2] ?? '';
if ($appId === '') {
    fwrite(STDERR, "Usage: php cli/ssl.php <appId> [domain]\n");
    exit(1);
}

$store = new AppStore();
$app = $store->find($appId);
if ($app === null) {
    fwrite(STDERR, "App tidak ditemukan: {$appId}\n");
    exit(1);
}

// Tentukan domain target & slot SSL (subdomain -> `ssl`, custom domain -> `custom_ssl`)
$subdomain = app_subdomain_of($app);
$customDomain = (string) ($app['custom_domain'] ?? '');

if ($domainArg !== '') {
    if ($domainArg === $customDomain) {
        $slot = 'custom_ssl';
        $domain = $customDomain;
    } elseif ($domainArg === $subdomain) {
        $slot = 'ssl';
        $domain = $subdomain;
    } else {
        fwrite(STDERR, "Domain {$domainArg} bukan subdomain/custom domain app {$appId}.\n");
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
$logFile = $logDir . '/' . $appId . '.log';
// Log worker bersifat diagnostik (sumber kebenaran = store app). Penulisan log
// tidak boleh menggagalkan worker — direktori `runtime/logs/ssl` bisa saja milik
// uid lain (mis. dibuat proses dashboard, sementara worker dijalankan host user),
// dan galat fatal di sini akan membuat permintaan SSL tidak pernah dieksekusi.
@file_put_contents($logFile, '[' . date('c') . "] start domain={$domain} slot={$slot}\n", FILE_APPEND);

// Server pemilik app (multi-server F2) — `server_id` tak dikenal ⇒ melempar
// (fail-fast: jangan pernah menerbitkan sertifikat untuk app yang server-nya
// tidak jelas).
$resolver = new ServerResolver();
$server = $resolver->serverForApp($app);
$isLocalServer = ServerResolver::isLocal($server);

/** @var callable(string,string):void */
$logger = function (string $stage, string $message) use ($store, $appId, $logFile, $slot): void {
    @file_put_contents($logFile, '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL, FILE_APPEND);
    $store->update($appId, function (array &$s) use ($stage, $message, $slot): void {
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

    // -----------------------------------------------------------------------
    // Jalur server agent (multi-server F2): penerbitan terjadi DI SERVER ITU
    // (job `ssl_issue` → certbot + /etc/letsencrypt server itu, vhost & reload
    // Nginx di sana). Control plane hanya menyalin state SSL hasilnya.
    // -----------------------------------------------------------------------
    if (!$isLocalServer) {
        AgentDeployer::assertDomainBase($server, $app);
        $deployer = DeployerFactory::createForApp($app, $resolver);
        if (!$deployer instanceof AgentDeployer) {
            throw new RuntimeException('Server pemilik app bukan server agent — jalur SSL server agent tidak berlaku.');
        }

        $serverLabel = trim((string) ($server['name'] ?? '')) !== ''
            ? 'Server "' . trim((string) $server['name']) . '"'
            : 'Server agent';
        $logger('issue', "Menerbitkan sertifikat untuk {$domain} di {$serverLabel} ...");
        $deployer->issueSsl($app, $domain, $logger);

        // Expiry sesungguhnya hanya diketahui server itu — tarik state eksekusi
        // milik agent (best-effort: job sudah sukses, UI boleh menyusul).
        $expires = null;
        try {
            $state = $deployer->appState($app);
            $pulled = $state[$slot . '_expires_at'] ?? null;
            $expires = is_string($pulled) && $pulled !== '' ? $pulled : null;
        } catch (\Throwable $e) {
            @file_put_contents($logFile, '[' . date('c') . "] [ssl] State SSL dari agent tidak bisa ditarik: " . $e->getMessage() . "\n", FILE_APPEND);
        }

        $store->update($appId, function (array &$s) use ($expires, $slot): void {
            $s[$slot . '_status'] = 'active';
            $s[$slot . '_stage'] = null;
            $s[$slot . '_message'] = null;
            $s[$slot . '_error'] = null;
            $s[$slot . '_expires_at'] = $expires;
            $s[$slot === 'custom_ssl' ? 'custom_needs_ssl' : 'needs_ssl'] = false;
        });

        @file_put_contents($logFile, '[' . date('c') . "] done\n", FILE_APPEND);
        exit(0);
    }

    $logger('issue', "Menerbitkan sertifikat untuk {$domain} ...");
    (new SslIssuer())->issue($domain);

    $expires = (new SslIssuer())->certExpiry($domain);
    $store->update($appId, function (array &$s) use ($expires, $slot): void {
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
    @file_put_contents($logFile, '[' . date('c') . "] [nginx] Menulis ulang config Nginx dengan SSL ...\n", FILE_APPEND);
    $app = $store->find($appId);
    if ($app !== null) {
        DeployerFactory::createForApp($app)->writeNginxConfig($app);
        try {
            $reload = (new NginxReloader())->reload();
            @file_put_contents($logFile, '[' . date('c') . "] [nginx] " . ($reload['ok'] ? 'Reload Nginx berhasil.' : 'Reload Nginx GAGAL: ' . ($reload['error'] ?? '')) . "\n", FILE_APPEND);
        } catch (\Throwable $e) {
            @file_put_contents($logFile, '[' . date('c') . "] [nginx] Reload Nginx gagal: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    @file_put_contents($logFile, '[' . date('c') . "] done\n", FILE_APPEND);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    @file_put_contents($logFile, '[' . date('c') . "] ERROR: {$msg}\n", FILE_APPEND);
    try {
        $store->update($appId, function (array &$s) use ($msg, $slot): void {
            $s[$slot . '_status'] = 'failed';
            $s[$slot . '_stage'] = null;
            $s[$slot . '_message'] = null;
            $s[$slot . '_error'] = $msg;
        });
    } catch (\Throwable $ignored) {
        // abaikan jika store juga bermasalah
    }
    // Diagnostik untuk jalur manual/kontainer (di produksi stderr juga masuk ke
    // log worker). Pesan sudah tersanitasi (tanpa token/PEM).
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
}
