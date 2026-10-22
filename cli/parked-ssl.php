#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker SSL **domain parkir** — SPECS §8j (Let's Encrypt per entri).
 *
 *   php cli/parked-ssl.php <parkedId>
 *
 * Dipanggil detached (mis. dari route `/domains/{id}/ssl`) agar request HTTP
 * tidak terblokir certbot. Worker hanya merakit dependensi + pelaporan progres:
 * seluruh alur penerbitan ada di {@see \app\library\SSL\ParkedSslIssuer}.
 *
 * Status yang ditulis ke store (sumber kebenaran UI):
 *   - selama berjalan: `ssl_status='pending'`, `ssl_stage`, `ssl_message`;
 *   - sukses: `active` + `ssl_expires_at` (oleh `ParkedSslIssuer`);
 *   - gagal: `failed` + `ssl_error` (oleh `ParkedSslIssuer`).
 * Logger TIDAK dipanggil setelah status final — logger selalu mengeset
 * `pending` dan akan menimpa `active` (pola sama dengan `cli/ssl.php`).
 *
 * Log diagnostik: runtime/logs/ssl/parked-{id}.log (id disanitasi; tanpa
 * env/secret).
 */

use app\library\Domain\ParkedDomainContent;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\ParkedNginxConfig;
use app\library\SSL\ParkedSslIssuer;
use app\library\SSL\SslIssuer;
use app\library\Storage\ParkedDomainStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$parkedId = $argv[1] ?? '';
if ($parkedId === '') {
    fwrite(STDERR, "Usage: php cli/parked-ssl.php <parkedId>\n");
    exit(1);
}

$logDir = runtime_path('logs/ssl');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
// Id dari request dipakai sebagai nama berkas → sanitasi (id sah tetap utuh).
$safeId = preg_replace('/[^A-Za-z0-9_-]/', '_', $parkedId);
$logFile = $logDir . '/parked-' . ($safeId === null || $safeId === '' ? 'invalid' : $safeId) . '.log';
@file_put_contents($logFile, '[' . date('c') . "] start id={$parkedId}\n", FILE_APPEND);

/** @var ParkedDomainStore $store */
$store = new ParkedDomainStore();
$webroot = (string) config('deploy.ssl_webroot', base_path() . '/webroot');
$letsencryptPath = (string) config('deploy.letsencrypt_path', '/etc/letsencrypt');
$parkedRoot = (string) config('deploy.parked_root', base_path() . '/database/parked');

$content = new ParkedDomainContent($parkedRoot);
$nginx = new ParkedNginxConfig(new NginxConfigGenerator(
    (string) config('deploy.nginx_conf_path', '/etc/nginx/sites-available'),
    (string) config('deploy.nginx_enabled_path', '/etc/nginx/sites-enabled')
));

/** @var callable(string,string):void $logger */
$logger = function (string $stage, string $message) use ($store, $parkedId, $logFile): void {
    @file_put_contents(
        $logFile,
        '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL,
        FILE_APPEND
    );
    try {
        $store->update($parkedId, static function (array &$row) use ($stage, $message): void {
            $row['ssl_status'] = 'pending';
            $row['ssl_stage'] = $stage;
            $row['ssl_message'] = $message;
            $row['ssl_error'] = null;
        });
    } catch (\Throwable $e) {
        // Progres hanya informatif; jangan hentikan penerbitan karenanya.
        @file_put_contents(
            $logFile,
            '[' . date('c') . '] [state] Gagal menyimpan progres: ' . $e->getMessage() . PHP_EOL,
            FILE_APPEND
        );
    }
};

try {
    $service = new ParkedSslIssuer(
        $store,
        $content,
        $nginx,
        new NginxReloader(),
        new SslIssuer(),
        $webroot,
        $letsencryptPath
    );

    $result = $service->issue($parkedId, $logger);

    if (!$result['ok']) {
        @file_put_contents($logFile, '[' . date('c') . '] ERROR: ' . $result['error'] . PHP_EOL, FILE_APPEND);
        fwrite(STDERR, $result['error'] . PHP_EOL);
        exit(1);
    }

    if ($result['error'] !== '') {
        // Cadangan: peringatan non-fatal (kegagalan config/reload kini
        // dilaporkan sebagai ok=false dan ditangani sebagai ERROR di atas).
        @file_put_contents($logFile, '[' . date('c') . '] [nginx] ' . $result['error'] . PHP_EOL, FILE_APPEND);
    }

    @file_put_contents($logFile, '[' . date('c') . "] done domain={$result['domain']}\n", FILE_APPEND);
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    @file_put_contents($logFile, '[' . date('c') . "] ERROR: {$msg}\n", FILE_APPEND);
    try {
        $store->update($parkedId, static function (array &$row) use ($msg): void {
            $row['ssl_status'] = 'failed';
            $row['ssl_stage'] = null;
            $row['ssl_message'] = null;
            $row['ssl_error'] = $msg;
        });
    } catch (\Throwable $ignored) {
        // abaikan: kegagalan store tidak boleh menutupi galat asli
    }
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
}
