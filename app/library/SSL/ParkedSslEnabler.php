<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Storage\ParkedDomainStore;
use Throwable;

/**
 * Pengaktifan SSL untuk **domain parkir** (SPECS §8j) — mediator antara aksi
 * halaman `/domains` dan worker `cli/parked-ssl.php` yang dijalankan antrean
 * global ({@see SslQueue}, proses `ssl-queue`).
 *
 * Controller hanya memanggil {@see self::enable()} lalu memetakan hasilnya ke
 * flash/JSON; seluruh validasi & efek samping ada di sini:
 *   1. entri parkir harus ada;
 *   2. domain harus publik (`SslIssuer::isPublicDomain`) — domain `.local`/IP
 *      tidak bisa menerima sertifikat Let's Encrypt;
 *   3. status `active`/`pending` tidak diproses ulang (info, bukan error);
 *   4. status entri diset `pending` + `ssl_stage='queued'` **sebelum** enqueue;
 *   5. permintaan masuk antrean SSL global (`ssl_jobs`); gagal enqueue ⇒ status
 *      `failed` + `ssl_error` (tombol Retry muncul).
 *
 * Sertifikat TIDAK diterbitkan di sini dan tidak paralel: antrean menjalankan
 * satu job pada satu waktu (certbot lock global + rate limit Let's Encrypt).
 *
 * Seam uji `?SslQueue $queue` menggantikan antrean nyata (basis data temp)
 * sehingga tes tidak pernah menjalankan certbot. Store bisa disuntikkan agar
 * tes memakai path basis data temp.
 *
 * Tidak ada state lintas-request (worker Webman persistent): store & antrean
 * hanya properti readonly satu instance per pemanggilan.
 */
final class ParkedSslEnabler
{
    private ?SslQueue $queue;

    private ParkedDomainStore $store;

    public function __construct(?SslQueue $queue = null, ?ParkedDomainStore $store = null)
    {
        $this->queue = $queue;
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
                $row['ssl_message'] = 'Menunggu antrean SSL ...';
                $row['ssl_error'] = null;
            });
        } catch (Throwable $e) {
            return $this->rejected(
                'Gagal menyimpan status SSL domain parkir: ' . $e->getMessage(),
                'failed',
                $domain
            );
        }

        try {
            $enqueued = ($this->queue ?? new SslQueue())->enqueueParked($entry, $this->actorId());
        } catch (Throwable $e) {
            try {
                $this->store->update($id, static function (array &$row) use ($e): void {
                    $row['ssl_status'] = 'failed';
                    $row['ssl_stage'] = null;
                    $row['ssl_message'] = null;
                    $row['ssl_error'] = 'Gagal memasukkan permintaan SSL ke antrean: ' . $e->getMessage();
                });
            } catch (Throwable) {
                // Status `pending` tanpa job bukan kegagalan yang bisa
                // diperbaiki di sini; pesan penolakan tetap dikembalikan.
            }

            return $this->rejected('Gagal memasukkan permintaan SSL ke antrean.', 'failed', $domain);
        }

        $position = (int) ($enqueued['position'] ?? 0);
        $size = (int) ($enqueued['size'] ?? 0);

        return [
            'ok' => true,
            'error' => null,
            'status' => 'pending',
            'domain' => $domain,
            'message' => "SSL untuk {$domain} masuk antrean (posisi {$position} dari {$size}).",
            'flash' => 'success',
        ];
    }

    /**
     * Id user yang meminta (audit trail) — null bila di luar sesi HTTP
     * (CLI/uji) atau helper belum tersedia.
     */
    private function actorId(): ?string
    {
        if (!function_exists('current_user')) {
            return null;
        }
        try {
            $user = current_user();
        } catch (Throwable) {
            return null;
        }

        return is_array($user) ? (string) ($user['id'] ?? '') : null;
    }

    /**
     * Hasil terstruktur penolakan bisnis (tanpa efek samping).
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
}
