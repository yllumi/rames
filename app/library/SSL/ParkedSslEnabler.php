<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Storage\ParkedDomainStore;
use app\library\Support\DetachedWorker;
use Throwable;

/**
 * Pengaktifan SSL untuk **domain parkir** (SPECS §8j) — mediator antara aksi
 * halaman `/domains` dan worker detached `cli/parked-ssl.php`.
 *
 * Controller hanya memanggil {@see self::enable()} lalu memetakan hasilnya ke
 * flash/JSON; seluruh validasi & efek samping ada di sini:
 *   1. entri parkir harus ada;
 *   2. domain harus publik (`SslIssuer::isPublicDomain`) — domain `.local`/IP
 *      tidak bisa menerima sertifikat Let's Encrypt;
 *   3. status `active`/`pending` tidak diproses ulang (info, bukan error);
 *   4. status entri diset `pending` + `ssl_stage='queued'` **sebelum** spawn;
 *   5. worker dijalankan detached (fork+exec lewat {@see DetachedWorker});
 *      gagal spawn ⇒ status `failed` + `ssl_error` (tombol Retry muncul).
 *
 * Seam uji `$spawn = fn(string $id): bool` menggantikan peluncuran worker nyata
 * sehingga tes tidak pernah menjalankan certbot. Store bisa disuntikkan agar
 * tes memakai path basis data temp.
 *
 * Tidak ada state lintas-request (worker Webman persistent): store & closure
 * hanya properti readonly satu instance per pemanggilan.
 */
final class ParkedSslEnabler
{
    /** @var (\Closure(string):bool)|null */
    private ?\Closure $spawn;

    private ParkedDomainStore $store;

    public function __construct(?\Closure $spawn = null, ?ParkedDomainStore $store = null)
    {
        $this->spawn = $spawn;
        $this->store = $store ?? new ParkedDomainStore();
    }

    /**
     * Aktifkan SSL satu entri domain parkir.
     *
     * @return array{ok:bool,error:?string,status:string,domain:string,message:string,flash:string}
     */
    public function enable(string $id): array
    {
        $entry = $this->store->find($id);
        if ($entry === null) {
            return $this->rejected('Domain parkir tidak ditemukan. Mungkin sudah dihapus — muat ulang halaman.');
        }

        $domain = strtolower(trim((string) ($entry['domain'] ?? '')));
        if (!SslIssuer::isPublicDomain($domain)) {
            return $this->rejected(
                "Domain {$domain} bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).",
                'rejected',
                $domain
            );
        }

        $status = (string) ($entry['ssl_status'] ?? 'disabled');
        if (in_array($status, ['active', 'pending'], true)) {
            // Bukan error: SSL memang sudah ada/sedang diproses. Flash `info`
            // (pola sama dengan `SslController::enableDomain()`).
            return $this->rejected("SSL untuk {$domain} sudah {$status}.", 'already', $domain, 'info');
        }

        try {
            $this->store->update($id, static function (array &$row): void {
                $row['ssl_status'] = 'pending';
                $row['ssl_stage'] = 'queued';
                $row['ssl_message'] = 'Menunggu worker SSL ...';
                $row['ssl_error'] = null;
            });
        } catch (Throwable $e) {
            return $this->rejected(
                'Gagal menyimpan status SSL domain parkir: ' . $e->getMessage(),
                'failed',
                $domain
            );
        }

        if (!$this->spawnWorker($id)) {
            try {
                $this->store->update($id, static function (array &$row): void {
                    $row['ssl_status'] = 'failed';
                    $row['ssl_stage'] = null;
                    $row['ssl_message'] = null;
                    $row['ssl_error'] = 'Gagal spawn worker SSL. Cek log runtime/logs/ssl/parked-' . $row['id'] . '.log.';
                });
            } catch (Throwable) {
                // Status `pending` tanpa worker bukan kegagalan yang bisa
                // diperbaiki di sini; pesan penolakan tetap dikembalikan.
            }

            return $this->rejected('Gagal menjalankan worker SSL.', 'failed', $domain);
        }

        return [
            'ok' => true,
            'error' => null,
            'status' => 'pending',
            'domain' => $domain,
            'message' => "Penerbitan SSL untuk {$domain} dijalankan.",
            'flash' => 'success',
        ];
    }

    /**
     * Hasil terstruktur penolakan bisnis (tanpa spawn worker).
     *
     * @return array{ok:bool,error:?string,status:string,domain:string,message:string,flash:string}
     */
    private function rejected(
        string $message,
        string $status = 'rejected',
        string $domain = '',
        string $flash = 'error',
    ): array {
        return [
            'ok' => false,
            'error' => $message,
            'status' => $status,
            'domain' => $domain,
            'message' => $message,
            'flash' => $flash,
        ];
    }

    /**
     * Jalankan worker `cli/parked-ssl.php <id>` secara detached.
     *
     * Direktori log dipastikan ada lebih dulu: worker menulis progresnya ke
     * `runtime/logs/ssl/parked-{id}.log` (id disanitasi di worker).
     */
    private function spawnWorker(string $id): bool
    {
        if ($this->spawn !== null) {
            return (bool) ($this->spawn)($id);
        }

        $logDir = runtime_path('logs/ssl');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        return DetachedWorker::spawn([PHP_BINARY, base_path('cli/parked-ssl.php'), $id]);
    }
}
