#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker SSL (Let's Encrypt) — docs/SPECS.md §8a.
 *
 *   php cli/ssl.php <appId> [domain]
 *
 * Dipanggil detached oleh SslController (proc_open) supaya request HTTP tidak
 * terblokir oleh proses certbot yang bisa lama. Pipeline:
 *   - muat app dari apps.json; tentukan domain target & slot SSL:
 *       domain == app.custom_domain          -> slot `custom_ssl` (domain utama)
 *       domain == subdomain efektif          -> slot `ssl` (subdomain bawaan)
 *       domain == salah satu `custom_domains`-> slot `alias:<domain>`
 *       tanpa argumen                        -> custom domain bila ada, selain itu subdomain
 *   - validasi domain publik
 *   - jalankan certbot certonly (http webroot / dns-cloudflare) **di server
 *     pemilik app** (multi-server F2): app lokal ⇒ certbot di host ini; app
 *     server `kind=agent` ⇒ job `ssl_issue` di server itu (certbot +
 *     /etc/letsencrypt server itu, Nginx server itu di-reload di sana) lalu
 *     state SSL-nya ditarik kembali ke control plane
 *   - sukses: status slot `active` + expires_at (lewat `AppDomains::writeStatus()`),
 *     tulis ulang config Nginx (dashboard tetap satu-satunya penulis .conf —
 *     di server tempat app berjalan)
 *   - gagal: status slot `failed` + pesan error; HTTP tetap jalan, tombol Retry tampil
 * Log per-app: runtime/logs/ssl/{appId}.log
 *
 * Kredensial DNS (Cloudflare) tidak pernah dikirim lewat HTTP/payload job: agent
 * memakai berkas kredensial yang sudah ada di server itu (CLOUDFLARE_CREDS pada
 * konfigurasi server tersebut) — jadi tidak ada secret di JSON/log/UI.
 */

use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\ServerResolver;
use app\library\Domain\AppDomains;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslChallengeVhost;
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

// Tentukan domain target & slot SSL (subdomain -> `ssl`, domain utama ->
// `custom_ssl`, domain tambahan -> `alias:<domain>`). Satu sumber pemetaan:
// `AppDomains::slotOf()`.
$domainArg = AppDomains::normalizeDomain($domainArg);
$customDomain = AppDomains::normalizeDomain((string) ($app['custom_domain'] ?? ''));

if ($domainArg !== '') {
    $slot = AppDomains::slotOf($app, $domainArg);
    if ($slot === null) {
        fwrite(STDERR, "Domain {$domainArg} bukan subdomain/custom domain/domain tambahan app {$appId}.\n");
        exit(1);
    }
    $domain = AppDomains::domainOfSlot($app, $slot) ?? $domainArg;
} else {
    // tanpa argumen: prioritaskan custom domain bila ada, selain itu subdomain
    $slot = $customDomain !== '' ? AppDomains::SLOT_PRIMARY : AppDomains::SLOT_SUBDOMAIN;
    $domain = $customDomain !== '' ? $customDomain : app_subdomain_of($app);
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
    $store->update($appId, static function (array &$s) use ($stage, $message, $slot): void {
        AppDomains::writeStatus($s, $slot, [
            'ssl_status' => 'pending',
            'ssl_stage' => $stage,
            'ssl_message' => $message,
            'ssl_error' => null,
        ]);
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
            // Status alias hidup di entri `custom_domains[]` — baca lewat
            // pemetaan slot yang sama dengan penulisnya.
            $pulled = AppDomains::status($state, $slot)['ssl_expires_at'] ?? null;
            $expires = is_string($pulled) && $pulled !== '' ? $pulled : null;
        } catch (\Throwable $e) {
            @file_put_contents($logFile, '[' . date('c') . "] [ssl] State SSL dari agent tidak bisa ditarik: " . $e->getMessage() . "\n", FILE_APPEND);
        }

        $store->update($appId, static function (array &$s) use ($expires, $slot): void {
            AppDomains::writeStatus($s, $slot, [
                'ssl_status' => 'active',
                'ssl_stage' => null,
                'ssl_message' => null,
                'ssl_error' => null,
                'ssl_expires_at' => $expires,
            ]);
            if ($slot === AppDomains::SLOT_PRIMARY) {
                $s['custom_needs_ssl'] = false;
            } elseif ($slot === AppDomains::SLOT_SUBDOMAIN) {
                $s['needs_ssl'] = false;
            }
        });

        @file_put_contents($logFile, '[' . date('c') . "] done\n", FILE_APPEND);
        exit(0);
    }

    $logger('issue', "Menerbitkan sertifikat untuk {$domain} ...");
    // Vhost ditulis ulang + Nginx host di-reload SEBELUM certbot: blok
    // `location ^~ /.well-known/acme-challenge/` harus dimuat dari webroot yang
    // dipakai certbot SEKARANG. Vhost basi (webroot lama) membuat nginx
    // menyajikan path challenge dari `root` lain ⇒ Let's Encrypt menerima 404
    // walau berkasnya ada. Reload gagal di sini sengaja FATAL.
    $deployer = DeployerFactory::createForApp($app);
    (new SslChallengeVhost())->ensure($app, $deployer, $logger);
    (new SslIssuer())->issue($domain);

    $expires = (new SslIssuer())->certExpiry($domain);
    $store->update($appId, static function (array &$s) use ($expires, $slot): void {
        AppDomains::writeStatus($s, $slot, [
            'ssl_status' => 'active',
            'ssl_stage' => null,
            'ssl_message' => null,
            'ssl_error' => null,
            'ssl_expires_at' => $expires,
        ]);
        if ($slot === AppDomains::SLOT_PRIMARY) {
            $s['custom_needs_ssl'] = false;
        } elseif ($slot === AppDomains::SLOT_SUBDOMAIN) {
            $s['needs_ssl'] = false;
        }
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
        $store->update($appId, static function (array &$s) use ($msg, $slot): void {
            AppDomains::writeStatus($s, $slot, [
                'ssl_status' => 'failed',
                'ssl_stage' => null,
                'ssl_message' => null,
                'ssl_error' => $msg,
            ]);
        });
    } catch (\Throwable $ignored) {
        // abaikan jika store juga bermasalah
    }
    // Diagnostik untuk jalur manual/kontainer (di produksi stderr juga masuk ke
    // log worker). Pesan sudah tersanitasi (tanpa token/PEM).
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
}
