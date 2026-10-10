#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker deploy app.
 *
 *   php cli/deploy.php <appId> [deploy|rebuild|rollback|apply] [ref] [actorId]
 *
 * Dipanggil detached oleh AppController (pcntl_fork + pcntl_exec) supaya request
 * HTTP tidak terblokir oleh operasi build yang lama. Pipeline:
 *   - muat app dari apps.json
 *   - update status tiap tahap (deploying / running / error)
 *   - jalankan deployer (deploy/rebuild/rollback/apply)
 *   - simpan hasil (containers, deploy_history, status) kembali ke apps.json
 * Log per-app ditulis ke runtime/logs/deploy/{appId}.log.
 *
 * Mode `apply` dipakai app mode compose (tanpa repo Git): ciptakan ulang
 * container dari file compose yang ada + image prebuilt (tanpa build).
 */

use app\library\Auth\UserStore;
use app\library\Billing\BillingGate;
use app\library\Billing\InsufficientCredits;
use app\library\Deploy\DeployerFactory;
use app\library\Nginx\NginxReloader;
use app\library\Storage\AppStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$appId = $argv[1] ?? '';
$mode = $argv[2] ?? 'deploy';
$ref = $argv[3] ?? '';

if ($appId === '' || !in_array($mode, ['deploy', 'rebuild', 'rollback', 'apply'], true) || ($mode === 'rollback' && $ref === '')) {
    fwrite(STDERR, "Usage: php cli/deploy.php <appId> [deploy|rebuild|rollback|apply [ref]]\n");
    exit(1);
}

$store = new AppStore();
$app = $store->find($appId);
if ($app === null) {
    fwrite(STDERR, "App tidak ditemukan: {$appId}\n");
    exit(1);
}

$logDir = runtime_path('logs/deploy');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/' . $appId . '.log';
file_put_contents($logFile, '[' . date('c') . "] start mode={$mode}\n", FILE_APPEND);

// Gerbang kredit (pertahanan berlapis) — jalankan SEBELUM compose/dispatcher.
// Aturan SAMA dengan controller (AppController::billingAssertCanStart()) — DUA
// subjek berurutan, agar controller & worker tidak berbeda putusan:
//   - aktor admin (argv[4]) ATAU owner admin ⇒ tidak pernah diblokir kredit;
//   - (4) aktor dinilai lebih dulu (bila ada) — member tanpa saldo diblokir di
//     sini walau ownernya kaya; tanpa aktor (pemanggil lama) ⇒ owner saja;
//   - (5) pembayar/owner dinilai bila berbeda dari aktor — app yang dibagikan
//     tetap ditagih ke ownernya; owner tak diketahui ⇒ jatuh ke aktor (selaras
//     `billingPayerFor()`), dan tanpa aktor maupun owner ⇒ dilewati (jangan
//     mengunci app).
// Saldo kurang ⇒ app ditandai error & worker keluar non-zero tanpa menyentuh
// Docker. `findPublicById` me-resolve role (migrasi lazy user pertama = admin)
// agar admin legacy tetap bebas, sama seperti jalur HTTP. InsufficientCredits =
// blokir fungsional (bukan otorisasi — itu urusan AppAccess/AuthMiddleware).
try {
    $actorId = trim((string) ($argv[4] ?? ''));
    $ownerId = (string) ($app['owner_id'] ?? '');
    $users = new UserStore();
    $gate = new BillingGate(null, null, $users);
    $owner = $ownerId === '' ? null : $users->findPublicById($ownerId);
    $actor = $actorId === '' ? null : $users->findPublicById($actorId);

    $exempt = static fn (?array $u): bool => $u !== null && $gate->isExempt($u);

    if (!$exempt($actor) && !$exempt($owner)) {
        // (4) Aktor dinilai lebih dulu (bila ada); tanpa aktor ⇒ owner saja.
        $gate->assertCanStart($app, $actor ?? $owner);
        // (5) Pembayar/owner bila berbeda dari aktor.
        if ($owner !== null && $actor !== null && (string) $actor['id'] !== (string) $owner['id']) {
            $gate->assertCanStart($app, $owner);
        }
    }
} catch (InsufficientCredits $e) {
    $msg = $e->getMessage();
    file_put_contents($logFile, '[' . date('c') . "] BILLING: {$msg}\n", FILE_APPEND);
    try {
        $store->update($appId, function (array &$s) use ($msg): void {
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

/** @var callable(string,string):void */
$logger = function (string $stage, string $message) use ($store, $appId, $logFile): void {
    file_put_contents($logFile, '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL, FILE_APPEND);
    $store->update($appId, function (array &$s) use ($stage, $message): void {
        $s['status'] = 'deploying';
        $s['stage'] = $stage;
        $s['message'] = $message;
        $s['error'] = null;
    });
};

try {
    $deployer = DeployerFactory::create();
    $app = match ($mode) {
        'rebuild'  => $deployer->rebuild($app, $logger),
        'rollback' => $deployer->rollback($app, $ref, $logger),
        'apply'    => $deployer->apply($app, $logger),
        default    => $deployer->deploy($app, $logger),
    };

    $store->update($appId, function (array &$s) use ($app): void {
        $s['status'] = (string) ($app['status'] ?? 'running');
        $s['stage'] = null;
        $s['message'] = $s['status'] === 'running' ? 'Running' : (string) ($app['message'] ?? '');
        $s['error'] = null;
        $s['containers'] = $app['containers'] ?? [];
        $s['deploy_history'] = $app['deploy_history'] ?? $s['deploy_history'] ?? [];
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
        $store->update($appId, function (array &$s) use ($msg): void {
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
