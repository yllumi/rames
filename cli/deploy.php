#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker deploy app.
 *
 *   php cli/deploy.php <appId> [deploy|rebuild|rollback|apply|stop|start|teardown|apply_env|nginx_write|nginx_remove|nginx_reload|ssl_issue|ssl_revoke] [ref] [actorId]
 *
 * Dipanggil detached oleh AppController (pcntl_fork + pcntl_exec) supaya request
 * HTTP tidak terblokir oleh operasi build yang lama. Pipeline:
 *   - muat app dari apps.json
 *   - update status tiap tahap (deploying / running / error)
 *   - jalankan deployer (deploy/rebuild/rollback/apply/apply_env/stop/start/teardown)
 *   - simpan hasil (containers, deploy_history, status) kembali ke apps.json
 * Log per-app ditulis ke runtime/logs/deploy/{appId}.log.
 *
 * Mode `apply` dipakai app mode compose (tanpa repo Git): ciptakan ulang
 * container dari file compose yang ada + image prebuilt (tanpa build).
 *
 * Sejak multi-server F1 worker ini juga dipakai **di server agent** (spawn oleh
 * `AgentJobController`): env `RAMES_JOB_ID` berisi id job agent sehingga status
 * job di `AgentJobStore` ditandai `running` → `done`/`error`. Tanpa env itu
 * (jalur control plane/manual) kelas `AgentJobStore` **tidak disentuh sama
 * sekali**. Mode `stop|start|teardown|apply_env` adalah op job yang sama —
 * daftar mode di bawah adalah **satu-satunya** sumbernya
 * (`DeployWorkerLauncher::MODES` mengikuti berkas ini, diverifikasi
 * `DeployWorkerLauncherTest`).
 *
 * F2 multi-server (Nginx/SSL): mode `nginx_write|nginx_remove|nginx_reload|
 * ssl_issue|ssl_revoke` menjalankan library Nginx/SSL **di server tempat worker
 * ini berjalan** (di agent: server itu sendiri). Tidak ada operasi container,
 * jadi mode ini tidak melewati gerbang kredit. Domain SSL dibaca dari
 * **payload job** (`domain`), dan kredensial DNS tidak pernah ikut payload —
 * agent memakai berkas kredensialnya sendiri.
 */

use app\library\Agent\AgentJobStore;
use app\library\Auth\UserStore;
use app\library\Billing\BillingGate;
use app\library\Billing\InsufficientCredits;
use app\library\Deploy\DeployerFactory;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$appId = $argv[1] ?? '';
$mode = $argv[2] ?? 'deploy';
$ref = $argv[3] ?? '';

// Daftar mode = satu sumber untuk `DeployWorkerLauncher::MODES` (capabilities
// agent) — jangan menyalinnya di tempat lain.
if ($appId === '' || !in_array($mode, ['deploy', 'rebuild', 'rollback', 'apply', 'stop', 'start', 'teardown', 'apply_env', 'nginx_write', 'nginx_remove', 'nginx_reload', 'ssl_issue', 'ssl_revoke'], true) || ($mode === 'rollback' && $ref === '')) {
    fwrite(STDERR, "Usage: php cli/deploy.php <appId> [deploy|rebuild|rollback|apply|stop|start|teardown|apply_env|nginx_write|nginx_remove|nginx_reload|ssl_issue|ssl_revoke] [ref] [actorId]\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Job-awareness agent (multi-server F1) — hanya aktif bila worker di-spawn oleh
// AgentJobController (env RAMES_JOB_ID). Jalur control plane/manual: env kosong
// ⇒ AgentJobStore TIDAK PERNAH disentuh (termasuk tidak dibaca/dibuat).
// `RAMES_JOB_DIR` (opsional) mengarahkan store ke direktori lain — dipakai uji
// supaya tidak menyentuh `runtime/agent-jobs` nyata; produksi memakai default.
// Seluruh penandaan bersifat best-effort: kegagalan menulis rekaman job tidak
// boleh menggagalkan deploy yang sedang berjalan.
// ---------------------------------------------------------------------------
$jobId = trim((string) getenv('RAMES_JOB_ID'));
$jobStore = null;
if ($jobId !== '') {
    $jobDir = trim((string) getenv('RAMES_JOB_DIR'));
    try {
        $jobStore = new AgentJobStore($jobDir !== '' ? $jobDir : null);
    } catch (\Throwable $ignored) {
        $jobStore = null;
    }
}

/**
 * Tandai rekaman job agent (no-op bila worker bukan bagian dari job agent).
 *
 * @param array<string,mixed> $patch
 */
$markJob = static function (array $patch) use ($jobStore, $jobId): void {
    if ($jobStore === null || $jobId === '') {
        return;
    }
    try {
        $jobStore->update($jobId, static function (array &$record) use ($patch): void {
            foreach ($patch as $key => $value) {
                $record[$key] = $value;
            }
        });
    } catch (\Throwable $ignored) {
        // job-awareness bukan bagian dari hasil deploy — jangan menggagalkannya.
    }
};

$markJob(['status' => 'running', 'started_at' => date('c')]);

// Payload job agent (mis. `preserve_volumes` untuk mode teardown) — hanya ada
// bila worker ini milik job agent.
$jobPayload = [];
if ($jobStore !== null && $jobId !== '') {
    try {
        $record = $jobStore->find($jobId);
        $jobPayload = is_array($record['payload'] ?? null) ? $record['payload'] : [];
    } catch (\Throwable $ignored) {
        $jobPayload = [];
    }
}

$store = new AppStore();
$app = $store->find($appId);
if ($app === null) {
    $markJob(['status' => 'error', 'error' => "App tidak ditemukan: {$appId}", 'message' => "App tidak ditemukan: {$appId}", 'finished_at' => date('c')]);
    fwrite(STDERR, "App tidak ditemukan: {$appId}\n");
    exit(1);
}

$logDir = runtime_path('logs/deploy');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/' . $appId . '.log';
file_put_contents($logFile, '[' . date('c') . "] start mode={$mode}\n", FILE_APPEND);

// ---------------------------------------------------------------------------
// Operasi Nginx & SSL (multi-server F2) — dijalankan DI server tempat worker ini
// berjalan (di server agent: server itu sendiri), memakai library Nginx/SSL yang
// sama dengan jalur deploy (`DeployerFactory::createForApp()` ⇒ `LocalDeployer`
// + `NginxReloader`/`SslIssuer`). Tidak menyentuh container ⇒ tidak melewati
// gerbang kredit (gerbang itu menilai operasi build/up).
//
// Domain SSL datang dari **payload job** (`domain`); kredensial DNS tidak pernah
// ikut payload — agent memakai berkas kredensial yang sudah ada di server itu
// (`CLOUDFLARE_CREDS` pada konfigurasi server tersebut).
// ---------------------------------------------------------------------------
if (in_array($mode, ['nginx_write', 'nginx_remove', 'nginx_reload', 'ssl_issue', 'ssl_revoke'], true)) {
    /** @var callable(string,string):void */
    $logOp = function (string $stage, string $message) use ($logFile, $markJob): void {
        file_put_contents($logFile, '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL, FILE_APPEND);
        $markJob(['status' => 'running', 'stage' => $stage, 'message' => $message]);
    };

    /** Reload Nginx host ini (best-effort; kegagalan dilaporkan, tidak melempar). */
    $reloadNginx = function () use ($logOp): bool {
        try {
            $reload = (new NginxReloader())->reload();
            $logOp('nginx', $reload['ok'] ? 'Reload Nginx berhasil.' : 'Reload Nginx GAGAL: ' . ($reload['error'] ?? ''));

            return (bool) $reload['ok'];
        } catch (\Throwable $e) {
            $logOp('nginx', 'Reload Nginx gagal: ' . $e->getMessage());

            return false;
        }
    };

    /** Domain target SSL dari payload job (dinormalisasi). */
    $jobDomain = strtolower(rtrim(trim((string) ($jobPayload['domain'] ?? '')), '.'));

    /** Slot SSL app untuk domain tersebut; melempar bila domain bukan milik app ini. */
    $sslSlotFor = static function (array $state) use ($jobDomain, $appId): string {
        if ($jobDomain === '') {
            throw new RuntimeException('Operasi SSL tanpa `domain` di payload job — penerbitan dibatalkan.');
        }

        $customDomain = strtolower(trim((string) ($state['custom_domain'] ?? '')));
        if ($customDomain !== '' && $jobDomain === $customDomain) {
            return 'custom_ssl';
        }

        if ($jobDomain === strtolower(app_subdomain_of($state))) {
            return 'ssl';
        }

        throw new RuntimeException("Domain {$jobDomain} bukan subdomain/custom domain app {$appId}.");
    };

    try {
        $deployer = DeployerFactory::createForApp($app);

        if ($mode === 'nginx_write') {
            $logOp('nginx', 'Menulis config Nginx ...');
            $deployer->writeNginxConfig($app);
            $reloadNginx();
        } elseif ($mode === 'nginx_remove') {
            $logOp('nginx', 'Menghapus config Nginx ...');
            $deployer->removeNginxConfig($app);
            $reloadNginx();
        } elseif ($mode === 'nginx_reload') {
            $logOp('nginx', 'Reload Nginx ...');
            if (!$reloadNginx()) {
                throw new RuntimeException('Reload Nginx di server ini GAGAL (lihat log di atas).');
            }
        } else {
            // ssl_issue / ssl_revoke — sertifikat diterbitkan/dicabut DI SERVER INI
            // (certbot + {deploy.letsencrypt_path} milik server ini).
            $slot = $sslSlotFor($app);
            $issuer = new SslIssuer();

            if ($mode === 'ssl_issue') {
                if (!SslIssuer::isPublicDomain($jobDomain)) {
                    throw new RuntimeException("Domain {$jobDomain} bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).");
                }
                $logOp('issue', "Menerbitkan sertifikat untuk {$jobDomain} ...");
                $issuer->issue($jobDomain);
                $expires = $issuer->certExpiry($jobDomain);
                $store->update($appId, function (array &$s) use ($slot, $expires): void {
                    $s[$slot . '_status'] = 'active';
                    $s[$slot . '_stage'] = null;
                    $s[$slot . '_message'] = null;
                    $s[$slot . '_error'] = null;
                    $s[$slot . '_expires_at'] = $expires;
                    $s[$slot === 'custom_ssl' ? 'custom_needs_ssl' : 'needs_ssl'] = false;
                });
            } else {
                $logOp('revoke', "Mencabut sertifikat {$jobDomain} ...");
                $issuer->revoke($jobDomain);
                $store->update($appId, function (array &$s) use ($slot): void {
                    $s[$slot . '_status'] = 'disabled';
                    $s[$slot . '_stage'] = null;
                    $s[$slot . '_message'] = null;
                    $s[$slot . '_error'] = null;
                    $s[$slot . '_expires_at'] = null;
                });
            }

            // Config ditulis ulang dari state SSL yang baru (vhost dengan/tanpa
            // 443) lalu di-reload. Pakai state terbaru, bukan $app lama.
            $fresh = $store->find($appId) ?? $app;
            $logOp('nginx', 'Menulis ulang config Nginx ...');
            $deployer->writeNginxConfig($fresh);
            $reloadNginx();
        }

        $markJob(['status' => 'done', 'stage' => null, 'message' => 'Selesai', 'finished_at' => date('c')]);
        file_put_contents($logFile, '[' . date('c') . "] done\n", FILE_APPEND);
        exit(0);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        file_put_contents($logFile, '[' . date('c') . "] ERROR: {$msg}\n", FILE_APPEND);
        $markJob(['status' => 'error', 'stage' => null, 'message' => $msg, 'error' => $msg, 'finished_at' => date('c')]);
        exit(1);
    }
}

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
    $markJob(['status' => 'error', 'stage' => null, 'message' => $msg, 'error' => $msg, 'finished_at' => date('c')]);
    exit(1);
}

/** @var callable(string,string):void */
$logger = function (string $stage, string $message) use ($store, $appId, $logFile, $markJob): void {
    file_put_contents($logFile, '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL, FILE_APPEND);
    $store->update($appId, function (array &$s) use ($stage, $message): void {
        $s['status'] = 'deploying';
        $s['stage'] = $stage;
        $s['message'] = $message;
        $s['error'] = null;
    });
    $markJob(['status' => 'running', 'stage' => $stage, 'message' => $message]);
};

/**
 * Logger tanpa efek samping status app (mode stop/start/teardown: app tidak
 * sedang "deploying") — hanya menulis log + menyegarkan rekaman job agent.
 *
 * @var callable(string,string):void
 */
$logOnly = function (string $stage, string $message) use ($logFile, $markJob): void {
    file_put_contents($logFile, '[' . date('c') . "] [{$stage}] {$message}" . PHP_EOL, FILE_APPEND);
    $markJob(['status' => 'running', 'stage' => $stage, 'message' => $message]);
};

try {
    $deployer = DeployerFactory::createForApp($app);

    // Mode yang tidak menghasilkan dokumen app (void): stop/start/teardown.
    if (in_array($mode, ['stop', 'start', 'teardown'], true)) {
        if ($mode === 'stop') {
            $deployer->stop($app);
            $patch = ['status' => 'stopped', 'stage' => null, 'message' => 'Stopped', 'error' => null];
        } elseif ($mode === 'start') {
            $deployer->start($app);
            $patch = ['status' => 'running', 'stage' => null, 'message' => 'Running', 'error' => null];
        } else {
            // `preserve_volumes` dari payload job agent: null = hapus semua
            // volume (`down -v`); array = volume yang dipertahankan.
            $preserve = is_array($jobPayload['preserve_volumes'] ?? null)
                ? array_values(array_map('strval', $jobPayload['preserve_volumes']))
                : null;
            $deployer->teardown($app, $preserve, $logOnly);
            $patch = ['status' => 'stopped', 'stage' => null, 'message' => 'Container app dihentikan & dihapus.', 'error' => null];
        }

        $store->update($appId, function (array &$s) use ($patch): void {
            foreach ($patch as $key => $value) {
                $s[$key] = $value;
            }
        });

        $markJob(['status' => 'done', 'stage' => null, 'message' => (string) $patch['message'], 'finished_at' => date('c')]);
        file_put_contents($logFile, '[' . date('c') . "] done\n", FILE_APPEND);
        exit(0);
    }

    $app = match ($mode) {
        'rebuild'   => $deployer->rebuild($app, $logger),
        'rollback'  => $deployer->rollback($app, $ref, $logger),
        'apply'     => $deployer->apply($app, $logger),
        'apply_env' => $deployer->applyEnv($app, $logger),
        default     => $deployer->deploy($app, $logger),
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

    $markJob(['status' => 'done', 'stage' => null, 'message' => (string) ($app['message'] ?? ''), 'error' => '', 'finished_at' => date('c')]);
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
    $markJob(['status' => 'error', 'stage' => null, 'message' => $msg, 'error' => $msg, 'finished_at' => date('c')]);
    exit(1);
}
