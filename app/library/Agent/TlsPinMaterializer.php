<?php
declare(strict_types=1);

namespace app\library\Agent;

/**
 * Materialisasi PEM sertifikat TLS agent ke berkas, untuk dipakai sebagai
 * **trust anchor-nya sendiri** (`verify` Guzzle → path berkas ini).
 *
 * Mengapa begini (plan §11 catatan 5): pinning dengan cara membandingkan
 * `CURLINFO_CERTINFO` sendiri rapuh; cURL memperlakukan self-signed cert sebagai
 * root CA bila path sertifikatnya diberikan di `verify`. Karena itu PEM disimpan
 * **di dalam dokumen `servers` (kolom `data`)** — sertifikat self-signed bersifat
 * publik, bukan secret — lalu ditulis per server ke `runtime/agent-tls/{id}.crt`
 * (pola sama dengan `runtime/files-transfer/`).
 *
 * Fingerprint SHA-256 tetap dihitung ({@see self::fingerprint()}) untuk
 * ditampilkan di UI + deteksi rotasi; nilainya dinormalisasi (huruf kecil, tanpa
 * pemisah `:`) supaya perbandingan antar sumber format tidak sensitif.
 *
 * Direktori dapat di-override lewat konstruktor (uji memakai `/tmp`; **jangan**
 * menulis ke `runtime/` nyata dari test). Tidak ada state statik — aman untuk
 * worker Webman persistent.
 */
final class TlsPinMaterializer
{
    private const DIR_MODE = 0700;
    private const FILE_MODE = 0600;

    private readonly string $dir;

    /**
     * @param string|null $dir direktori penyimpanan `.crt`; null = `runtime/agent-tls`
     */
    public function __construct(?string $dir = null)
    {
        $dir = trim((string) $dir);
        $this->dir = rtrim($dir !== '' ? $dir : self::defaultDir(), '/');
    }

    /** Direktori tempat berkas `.crt` ditulis (untuk uji/diagnostik). */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Tulis PEM server ke `{dir}/{serverId}.crt` dan kembalikan path-nya.
     *
     * Berkas **hanya ditulis bila isinya berubah** (dibandingkan lewat hash
     * SHA-256), sehingga tidak ada penulisan cakram berulang per request.
     * PEM yang bukan sertifikat ⇒ `AgentError('invalid_config')` — pemanggil
     * tidak boleh melanjutkan dengan `verify=false`.
     *
     * @param array<string,mixed> $server dokumen server (butuh `id` + `tls_cert_pem`)
     * @throws AgentError invalid_config (PEM hilang/rusak, id kosong, gagal tulis)
     */
    public function ensure(array $server): string
    {
        $pem = self::pemOf($server);
        $path = $this->dir . '/' . self::serverId($server) . '.crt';

        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        if ($existing !== '' && hash('sha256', $existing) === hash('sha256', $pem)) {
            return $path;
        }

        $this->ensureDir();

        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $pem, LOCK_EX) === false) {
            throw AgentError::invalidConfig("Gagal menulis sertifikat TLS agent ke {$tmp}");
        }
        @chmod($tmp, self::FILE_MODE);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw AgentError::invalidConfig("Gagal memasang sertifikat TLS agent ke {$path}");
        }

        return $path;
    }

    /**
     * Fingerprint SHA-256 sertifikat (hex huruf kecil, tanpa `:`).
     *
     * @param array<string,mixed> $server
     * @throws AgentError invalid_config
     */
    public function fingerprint(array $server): string
    {
        $cert = @openssl_x509_read(self::pemOf($server));
        if ($cert === false) {
            throw AgentError::invalidConfig('Sertifikat TLS agent tidak bisa diparse (PEM cacat).');
        }

        $fingerprint = @openssl_x509_fingerprint($cert, 'sha256');
        if (!is_string($fingerprint) || trim($fingerprint) === '') {
            throw AgentError::invalidConfig('Fingerprint SHA-256 sertifikat TLS agent tidak bisa dihitung.');
        }

        return strtolower(str_replace(':', '', trim($fingerprint)));
    }

    /**
     * Hapus berkas `.crt` milik server yang sudah tidak terdaftar, plus sisa
     * berkas sementara dari penulisan yang gagal (`*.crt.tmp.*`).
     *
     * @param array<int,string> $knownServerIds id server yang masih ada
     * @return int jumlah berkas yang dihapus
     */
    public function prune(array $knownServerIds): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }

        $known = [];
        foreach ($knownServerIds as $id) {
            $safe = self::safeName((string) $id);
            if ($safe !== '') {
                $known[$safe] = true;
            }
        }

        $removed = 0;
        foreach ((array) glob($this->dir . '/*') as $file) {
            if (!is_file($file)) {
                continue;
            }
            $base = basename((string) $file);

            if (str_ends_with($base, '.crt')) {
                if (isset($known[substr($base, 0, -4)])) {
                    continue;
                }
            } elseif (!preg_match('/\.crt\.tmp\.\d+$/', $base)) {
                continue; // berkas asing di direktori ini: jangan disentuh
            }

            if (@unlink((string) $file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * PEM sertifikat server — divalidasi benar-benar sertifikat X.509.
     *
     * @param array<string,mixed> $server
     * @throws AgentError invalid_config
     */
    private static function pemOf(array $server): string
    {
        $pem = trim((string) ($server['tls_cert_pem'] ?? ''));
        if ($pem === '') {
            throw AgentError::invalidConfig(
                'PEM sertifikat TLS agent (`tls_cert_pem`) belum diisi untuk server ini — ambil dari keluaran agent/install.sh.'
            );
        }
        if (!str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
            throw AgentError::invalidConfig('Nilai tls_cert_pem bukan PEM sertifikat (tidak ada header CERTIFICATE).');
        }
        if (@openssl_x509_read($pem) === false) {
            throw AgentError::invalidConfig('PEM sertifikat TLS agent tidak bisa dibaca (cacat atau bukan X.509).');
        }

        return $pem . "\n";
    }

    /**
     * Nama berkas aman untuk id server — hanya `[A-Za-z0-9_-]`, sehingga tidak
     * mungkin keluar dari direktori (`..`, `/`, dsb).
     *
     * @param array<string,mixed> $server
     * @throws AgentError invalid_config
     */
    private static function serverId(array $server): string
    {
        $safe = self::safeName((string) ($server['id'] ?? ''));
        if ($safe === '') {
            throw AgentError::invalidConfig('Server tanpa id — sertifikat TLS tidak bisa dimaterialisasi.');
        }

        return $safe;
    }

    private static function safeName(string $id): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]/', '_', trim($id)), '_');
    }

    /** @throws AgentError invalid_config bila direktori tidak bisa dibuat */
    private function ensureDir(): void
    {
        if (is_dir($this->dir)) {
            return;
        }
        if (!@mkdir($this->dir, self::DIR_MODE, true) && !is_dir($this->dir)) {
            throw AgentError::invalidConfig("Tidak bisa membuat direktori sertifikat agent {$this->dir}");
        }
        @chmod($this->dir, self::DIR_MODE);
    }

    /** `runtime/agent-tls` (fallback path repo bila helper webman tak tersedia). */
    private static function defaultDir(): string
    {
        if (function_exists('runtime_path')) {
            return (string) runtime_path('agent-tls');
        }

        return dirname(__DIR__, 3) . '/runtime/agent-tls';
    }
}
