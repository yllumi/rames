<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Domain\ParkedDomainContent;
use app\library\Domain\ParkedDomainValidator;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\ParkedNginxConfig;
use app\library\Storage\ParkedDomainStore;
use Throwable;

/**
 * Penerbitan sertifikat Let's Encrypt untuk **domain parkir** (SPECS §8j).
 *
 * Alur (dipanggil worker detached `cli/parked-ssl.php`, UI tidak menunggu):
 *   validasi entri/domain → certbot (lewati bila cert sudah ada) → simpan
 *   `ssl_status='active'` + `ssl_expires_at` → tulis ulang config Nginx
 *   (`listen 443 ssl`) → reload Nginx.
 *
 * Pemisahan tanggung jawab penting:
 * - **Kegagalan penerbitan** ⇒ status `failed` + `ssl_error` (UI menampilkan
 *   Retry), kembalikan `ok=false`. HTTP (blok 80) tetap jalan.
 * - **Kegagalan tulis config/reload setelah cert terbit** ⇒ status `failed`
 *   (bukan `active` + peringatan) supaya UI menampilkan penyebab + tombol
 *   Retry; `ssl_expires_at` sertifikat yang baru tetap dipertahankan dan
 *   kembalian `ok=false` memuat pesan kegagalan. Config lama tetap berlaku
 *   sampai Retry berikutnya berhasil.
 *
 * Seluruh method **tidak pernah melempar** (`Throwable` ditangkap); pesan
 * berbahasa Indonesia: penyebab + tindakan perbaikan. Tidak ada secret yang
 * dibaca/ditulis di sini (certbot menulis berkasnya sendiri).
 */
final class ParkedSslIssuer
{
    public function __construct(
        private readonly ParkedDomainStore $store,
        private readonly ParkedDomainContent $content,
        private readonly ParkedNginxConfig $nginx,
        private readonly NginxReloader $reloader,
        private readonly SslIssuer $issuer,
        private readonly string $webroot,
        private readonly string $letsencryptPath,
    ) {
    }

    /**
     * Terbitkan (atau pertahankan) sertifikat untuk satu entri domain parkir.
     *
     * @param callable(string,string):void|null $logger pelapor progres; TIDAK
     *        dipanggil setelah status final (`active`/`failed`) tersimpan.
     * @return array{ok:bool,error:string,domain:string}
     */
    public function issue(string $id, ?callable $logger = null): array
    {
        try {
            $entry = $this->store->find($id);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Gagal membaca entri domain parkir: ' . $e->getMessage()
                    . ' — sertifikat tidak diterbitkan.',
                'domain' => '',
            ];
        }
        if ($entry === null) {
            return [
                'ok' => false,
                'error' => 'Entri domain parkir tidak ditemukan: ' . $id
                    . '. Mungkin sudah dihapus — muat ulang halaman.',
                'domain' => '',
            ];
        }

        $domain = ParkedDomainValidator::normalizeDomain(
            is_scalar($entry['domain'] ?? null) ? (string) $entry['domain'] : ''
        );
        if (!ParkedDomainValidator::isValidDomain($domain)) {
            return [
                'ok' => false,
                'error' => 'Domain parkir tidak valid: "' . $domain
                    . '". Gunakan FQDN publik seperti contoh.com.',
                'domain' => $domain,
            ];
        }
        if (!SslIssuer::isPublicDomain($domain)) {
            return [
                'ok' => false,
                'error' => 'Domain ' . $domain . ' bukan domain publik — SSL otomatis tidak didukung '
                    . '(mis. TLD .local atau alamat IP).',
                'domain' => $domain,
            ];
        }

        // Idempoten: cert masih ada & status active ⇒ tidak perlu certbot.
        // Config tetap ditulis ulang + reload supaya tombol Retry juga
        // merekonsiliasi kegagalan tulis/reload sebelumnya.
        $status = strtolower(trim(
            is_scalar($entry['ssl_status'] ?? null) ? (string) $entry['ssl_status'] : ''
        ));
        if ($status === 'active') {
            try {
                if ($this->issuer->certExists($domain)) {
                    $warning = $this->rewriteConfig($id);
                    $expires = null;
                    try {
                        $expires = $this->issuer->certExpiry($domain);
                    } catch (Throwable) {
                        // Tanggal kedaluwarsa bersifat informatif.
                    }

                    if ($warning !== '') {
                        // Reconcile gagal: status `active` + error tidak memunculkan
                        // tombol Retry di UI, jadi turunkan ke `failed`. Cert tetap
                        // ada ⇒ tanggal kedaluwarsa yang valid dipertahankan.
                        $this->markConfigFailed($id, $warning, $expires);

                        return ['ok' => false, 'error' => $warning, 'domain' => $domain];
                    }

                    try {
                        $this->store->update($id, static function (array &$row) use ($expires): void {
                            $row['ssl_error'] = null;
                            if ($expires !== null) {
                                $row['ssl_expires_at'] = $expires;
                            }
                        });
                    } catch (Throwable) {
                        // Status active sudah tersimpan; peringatan tetap dikembalikan.
                    }

                    return ['ok' => true, 'error' => '', 'domain' => $domain];
                }
            } catch (Throwable) {
                // certExists gagal (mis. berkas tak terbaca) → lanjutkan issue.
            }
        }

        if ($logger !== null) {
            $logger('issue', 'Menerbitkan sertifikat untuk ' . $domain . ' ...');
        }

        try {
            $this->issuer->issue($domain);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $this->markFailed($id, $message);

            return ['ok' => false, 'error' => $message, 'domain' => $domain];
        }

        $expires = null;
        try {
            $expires = $this->issuer->certExpiry($domain);
        } catch (Throwable) {
            // Tanggal kedaluwarsa bersifat informatif; jangan gagalkan alur.
        }

        try {
            $this->store->update($id, static function (array &$row) use ($expires): void {
                $row['ssl_status'] = 'active';
                $row['ssl_stage'] = null;
                $row['ssl_message'] = null;
                $row['ssl_error'] = null;
                $row['ssl_expires_at'] = $expires;
            });
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Sertifikat terbit tetapi state SSL gagal disimpan: ' . $e->getMessage()
                    . ' — jalankan ulang penerbitan.',
                'domain' => $domain,
            ];
        }

        $warning = $this->rewriteConfig($id);
        if ($warning !== '') {
            // Sertifikat sudah terbit tetapi config/reload gagal: jangan simpan
            // `active` + error (UI tidak menawarkan Retry). Turunkan ke `failed`
            // dan pertahankan tanggal kedaluwarsa cert yang baru.
            $this->markConfigFailed($id, $warning, $expires);

            return ['ok' => false, 'error' => $warning, 'domain' => $domain];
        }

        return ['ok' => true, 'error' => '', 'domain' => $domain];
    }

    /**
     * Tulis ulang config dengan entry SEGAR dari store lalu reload.
     * Mengembalikan pesan kegagalan (string kosong = sukses); pemanggil yang
     * menurunkan status ke `failed` saat pesan tidak kosong. Status `active`
     * yang sudah tersimpan **tidak** diubah di sini.
     */
    private function rewriteConfig(string $id): string
    {
        try {
            $entry = $this->store->find($id);
            if ($entry === null) {
                throw new \RuntimeException('entri domain parkir hilang setelah sertifikat terbit');
            }
            $this->nginx->write(
                $entry,
                $this->content->dirFor($id),
                $this->webroot,
                $this->letsencryptPath
            );

            $reload = $this->reloader->reload();
            if (($reload['ok'] ?? false) === true) {
                return '';
            }
            $reason = trim((string) ($reload['error'] ?? ''));
            if ($reason === '') {
                $reason = 'nginx menolak config baru';
            }

            return 'Sertifikat terbit tetapi config Nginx gagal dimuat: ' . $reason
                . ' — tekan Retry setelah memperbaiki Nginx.';
        } catch (Throwable $e) {
            return 'Sertifikat terbit tetapi config Nginx gagal ditulis: '
                . $e->getMessage() . ' — tekan Retry setelah memperbaiki penyebabnya.';
        }
    }

    private function markFailed(string $id, string $message): void
    {
        try {
            $this->store->update($id, static function (array &$row) use ($message): void {
                $row['ssl_status'] = 'failed';
                $row['ssl_stage'] = null;
                $row['ssl_message'] = null;
                $row['ssl_error'] = $message;
                // Penerbitan gagal ⇒ tidak ada sertifikat baru yang valid.
                $row['ssl_expires_at'] = null;
            });
        } catch (Throwable) {
            // Store bermasalah: kegagalan asli tetap yang dilaporkan.
        }
    }

    /**
     * Status `failed` untuk kasus sertifikat **sudah terbit** tetapi config /
     * reload Nginx gagal. Berbeda dengan {@see markFailed()}, tanggal
     * kedaluwarsa sertifikat yang baru tetap dipertahankan (bila diketahui)
     * karena sertifikatnya valid dan hanya pemuatan config yang perlu Retry.
     */
    private function markConfigFailed(string $id, string $message, ?string $expires): void
    {
        try {
            $this->store->update($id, static function (array &$row) use ($message, $expires): void {
                $row['ssl_status'] = 'failed';
                $row['ssl_stage'] = null;
                $row['ssl_message'] = null;
                $row['ssl_error'] = $message;
                if ($expires !== null) {
                    $row['ssl_expires_at'] = $expires;
                }
            });
        } catch (Throwable) {
            // Store bermasalah: kegagalan asli tetap yang dilaporkan.
        }
    }
}
