<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Deploy\DeployerFactory;
use app\library\Storage\AppStore;
use Throwable;

/**
 * Penghenti app otomatis untuk kebijakan saldo kredit negatif (SPECS.md §7.12).
 *
 * Satu-satunya jalur stop = `DeployerInterface::stop()` lewat `DeployerFactory`
 * (satu sumber kebenaran) — kelas ini **tidak** memanggil docker/`docker compose`
 * mentah maupun men-spawn proses sendiri, sehingga bebas masalah SIGCHLD dan
 * tetap mudah diuji (inject `$stopper` palsu).
 *
 * Idempoten: app yang sudah `stopped` (atau status lain di luar running/deploying)
 * dilewati. Kegagalan satu app **tidak** menghentikan app lain — dicatat ke
 * `runtime/logs/billing/{Y-m-d}.log` (atau direktori `billing_log_path` bila
 * di-override saat tes).
 *
 * Tanpa state lintas-request: seluruh data dibaca segar dari `AppStore`.
 */
class AppStopper
{
    public const REASON = 'Dihentikan otomatis: saldo kredit negatif (billing).';

    /** Alasan stop karena kepemilikan dialihkan ke pemilik bebas tagihan. */
    public const REASON_TRANSFER = 'Dihentikan otomatis: app dialihkan ke pemilik bebas tagihan kredit.';

    /** Status app yang masih "hidup" dan boleh dihentikan otomatis. */
    private const STOPPABLE = ['running', 'deploying'];

    private AppStore $apps;

    /** @var callable|null fn(array $app): void */
    private $stopper;

    public function __construct(?AppStore $apps = null, ?callable $stopper = null)
    {
        $this->apps = $apps ?? new AppStore();
        $this->stopper = $stopper;
    }

    /**
     * Hentikan app milik `$userId` yang masih berjalan, lalu tandai
     * `status=stopped` + alasan di `message` dan bersihkan `stage`.
     *
     * @param array<int,array> $apps daftar app (biasanya sudah disaring pemanggil);
     *                               kosong = ambil semua app milik `$userId` dari store.
     * @param string|null $reason alasan yang ditulis ke `message`/log;
     *                            `null` = `REASON` (saldo kredit negatif).
     * @return int jumlah app yang berhasil dihentikan
     */
    public function stopOwnedBy(string $userId, array $apps = [], ?string $reason = null): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            return 0;
        }
        if ($apps === []) {
            $apps = $this->apps->ownedBy($userId);
        }

        $reason = ($reason !== null && trim($reason) !== '') ? trim($reason) : self::REASON;
        $stopper = $this->resolveStopper();
        $stopped = 0;

        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }
            $appId = (string) ($app['id'] ?? '');
            $status = (string) ($app['status'] ?? '');
            if ($appId === '' || !in_array($status, self::STOPPABLE, true)) {
                continue; // sudah stopped / error / tidak dikenal → idempoten
            }

            try {
                $stopper($app);
                $this->apps->update($appId, static function (array &$row) use ($reason): void {
                    $row['status'] = 'stopped';
                    $row['message'] = $reason;
                    $row['stage'] = null;
                });
                $stopped++;
                $this->log('app ' . $appId . ' → ' . $reason);
            } catch (Throwable $e) {
                // Satu app gagal tidak boleh menggagalkan app lain.
                $this->log('GAGAL menghentikan app ' . $appId . ': ' . $e->getMessage());
            }
        }

        return $stopped;
    }

    /**
     * Hentikan app yang **akan berjalan tanpa akrual** karena pengalihan
     * kepemilikan: app masih hidup dipindahkan dari pemilik yang ditagih (member)
     * ke pemilik bebas tagihan (admin). Dipakai `AppController::transferOwner()`.
     *
     * Tanpa aturan ini, anggota bisa "bebas biaya" dengan memindahkan app yang
     * sedang berjalan ke user admin (meteran hanya mengakru app milik member).
     *
     * @return bool true bila app dihentikan
     */
    public function stopForBillingEscape(array $app, bool $previousOwnerBillable, bool $newOwnerExempt): bool
    {
        if (!self::shouldStopOnTransfer($app, $previousOwnerBillable, $newOwnerExempt)) {
            return false;
        }

        // `$apps` diberikan eksplisit, jadi `$userId` di sini hanya untuk simetri
        // (dipakai `stopOwnedBy` saat `$apps` kosong); alasan stop dibedakan agar
        // `message` app tidak menyesatkan.
        return $this->stopOwnedBy((string) ($app['owner_id'] ?? ''), [$app], self::REASON_TRANSFER) > 0;
    }

    /**
     * Keputusan murni: apakah pengalihan kepemilikan ini menghapus dasar penagihan
     * sehingga app harus dihentikan? (app hidup + pemilik lama ditagih + pemilik
     * baru bebas tagihan).
     */
    public static function shouldStopOnTransfer(array $app, bool $previousOwnerBillable, bool $newOwnerExempt): bool
    {
        if (!$previousOwnerBillable || !$newOwnerExempt) {
            return false;
        }

        return in_array((string) ($app['status'] ?? ''), self::STOPPABLE, true);
    }

    /**
     * Varian massal untuk **penghapusan user**: app milik user yang dihapus
     * dialihkan ke admin (bebas tagihan) oleh `transferAllFrom()`, sedangkan
     * container-nya masih berjalan. Hentikan semua app yang masih hidup agar
     * tidak berjalan tanpa akrual (aturan sama dengan pengalihan manual).
     *
     * @param array<int,array> $appsBefore daftar app **sebelum** pengalihan
     * @return int jumlah app yang berhasil dihentikan
     */
    public function stopTransferredToExemptOwner(array $appsBefore, bool $previousOwnerBillable, bool $newOwnerExempt): int
    {
        if (!$previousOwnerBillable || !$newOwnerExempt) {
            return 0;
        }

        $stopped = 0;
        foreach ($appsBefore as $app) {
            $appId = is_array($app) ? (string) ($app['id'] ?? '') : '';
            if ($appId === '') {
                continue;
            }
            $current = $this->apps->find($appId);
            if ($current === null) {
                continue;
            }
            if ($this->stopForBillingEscape($current, true, true)) {
                $stopped++;
            }
        }

        return $stopped;
    }

    /**
     * Tulis satu baris log ke `runtime/logs/billing/{Y-m-d}.log` (aman
     * multi-proses: FILE_APPEND + LOCK_EX). Kegagalan menulis log diabaikan.
     */
    public function log(string $message): void
    {
        $dir = $this->logDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $dir . '/' . date('Y-m-d') . '.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Direktori log billing. Default `runtime/logs/billing`; `billing_log_path`
     * disediakan sebagai seam pengujian supaya tes memakai path temp.
     */
    private function logDir(): string
    {
        $configured = trim((string) config('deploy.billing_log_path', ''));

        return $configured !== '' ? rtrim($configured, '/') : runtime_path('logs/billing');
    }

    /**
     * Resolusi penghenti: pakai `$stopper` yang di-inject, selain itu
     * `DeployerFactory::create()->stop($app)` (di-resolve sekali per batch agar
     * tidak membuat deployer baru untuk setiap app).
     *
     * @return callable(array):void
     */
    private function resolveStopper(): callable
    {
        if ($this->stopper !== null) {
            return $this->stopper;
        }
        $deployer = DeployerFactory::create();

        return static function (array $app) use ($deployer): void {
            $deployer->stop($app);
        };
    }
}
