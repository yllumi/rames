<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Penerbitan sertifikat SSL via Let's Encrypt (certbot) — SPECS.md §8a.
 *
 * Certbot dijalankan DI DALAM container dashboard (root) lewat ProcessRunner
 * (array + bypass_shell, tanpa shell). Nginx reverse proxy tetap native di host:
 * - HTTP-01 (webroot): certbot menulis challenge ke webroot bersama; nginx host
 *   melayani lewat `location /.well-known/acme-challenge/` yang dirender dashboard.
 * - DNS-01 (dns-cloudflare): certbot menambah record TXT via API DNS provider.
 *
 * Sertifikat disimpan di {deploy.letsencrypt_path}/live/{domain}/ (default
 * /etc/letsencrypt) — host nginx membaca dari path yang sama (di-mount).
 */
class SslIssuer
{
    private ProcessRunner $runner;
    private int $timeout;

    private string $lePath;
    private string $challenge;
    private string $caServer;
    private string $webroot;
    private string $cloudflareCreds;
    private string $adminEmail;

    public function __construct(?ProcessRunner $runner = null)
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->timeout = 300;
        $this->lePath = (string) config('deploy.letsencrypt_path', '/etc/letsencrypt');
        $this->challenge = (string) config('deploy.ssl_challenge', 'http');
        $this->caServer = (string) config('deploy.ssl_ca_server', 'production');
        $this->webroot = (string) config('deploy.ssl_webroot', base_path() . '/webroot');
        $this->cloudflareCreds = (string) config('deploy.cloudflare_creds', '');
        $this->adminEmail = (string) config('deploy.admin_email', '');
    }

    /**
     * Apakah domain bisa diberi sertifikat publik.
     * Menolak localhost, IP, dan TLD non-publik (.local/.test/.internal dll).
     */
    public static function isPublicDomain(string $domain): bool
    {
        $d = strtolower(trim($domain));
        if ($d === '' || preg_match('/\s/', $d) || $d === 'localhost') {
            return false;
        }
        if (filter_var($d, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach (['.local', '.test', '.localhost', '.invalid', '.example', '.internal', '.lan', '.home'] as $suffix) {
            if (str_ends_with($d, $suffix)) {
                return false;
            }
        }
        // format FQDN: label dipisah titik, TLD minimal 2 huruf
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d) === 1;
    }

    /**
     * Path sertifikat untuk sebuah domain.
     *
     * @return array{fullchain:string, privkey:string}
     */
    public function certPaths(string $domain): array
    {
        return [
            'fullchain' => $this->lePath . '/live/' . $domain . '/fullchain.pem',
            'privkey' => $this->lePath . '/live/' . $domain . '/privkey.pem',
        ];
    }

    public function certExists(string $domain): bool
    {
        $p = $this->certPaths($domain);
        return is_file($p['fullchain']) && is_file($p['privkey']);
    }

    /**
     * Tanggal kedaluwarsa sertifikat (ISO 8601), atau null bila belum ada/tidak terbaca.
     */
    public function certExpiry(string $domain): ?string
    {
        $p = $this->certPaths($domain);
        if (!is_file($p['fullchain'])) {
            return null;
        }
        $pem = @file_get_contents($p['fullchain']);
        if ($pem === false) {
            return null;
        }
        $parsed = @openssl_x509_parse($pem);
        if (!is_array($parsed) || empty($parsed['validTo_time_t'])) {
            return null;
        }
        return date('c', (int) $parsed['validTo_time_t']);
    }

    /**
     * Terbitkan/pertahankan sertifikat untuk sebuah domain.
     * Idempoten: `--keep-until-expiring` → bila cert masih valid, certbot exit 0
     * tanpa menerbitkan ulang.
     *
     * @throws RuntimeException bila gagal
     */
    public function issue(string $domain): void
    {
        $this->prepareDirs();
        $command = $this->buildCommand($domain);
        $result = $this->runner->run($command, null, $this->timeout);

        if ($result['code'] !== 0) {
            $err = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new RuntimeException('Gagal menerbitkan sertifikat SSL untuk ' . $domain . ': ' . $err);
        }
        if (!$this->certExists($domain)) {
            throw new RuntimeException('certbot selesai tetapi sertifikat tidak ditemukan untuk ' . $domain . '.');
        }
    }

    /**
     * Pastikan direktori webroot & direktori kerja certbot tersedia.
     */
    private function prepareDirs(): void
    {
        if (!is_dir($this->webroot) && !mkdir($this->webroot, 0755, true) && !is_dir($this->webroot)) {
            throw new RuntimeException('Tidak bisa membuat webroot: ' . $this->webroot);
        }
        foreach (['/var/lib/letsencrypt', '/var/log/letsencrypt'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Tidak bisa membuat direktori certbot: ' . $dir);
            }
        }
    }

    /**
     * Bangun command certbot sesuai mode challenge & CA server.
     *
     * @return array<int,string>
     */
    private function buildCommand(string $domain): array
    {
        if ($this->adminEmail === '') {
            throw new RuntimeException('ADMIN_EMAIL belum diatur — wajib untuk penerbitan sertifikat Let\'s Encrypt.');
        }

        $cmd = [
            'certbot', 'certonly',
            '--non-interactive',
            '--agree-tos',
            '-m', $this->adminEmail,
            '-d', $domain,
            '--keep-until-expiring',
            '--config-dir', $this->lePath,
            '--work-dir', '/var/lib/letsencrypt',
            '--logs-dir', '/var/log/letsencrypt',
        ];

        if ($this->caServer === 'staging') {
            $cmd[] = '--staging';
        }

        if ($this->challenge === 'dns-cloudflare') {
            if ($this->cloudflareCreds === '' || !is_file($this->cloudflareCreds)) {
                throw new RuntimeException('CLOUDFLARE_CREDS belum diatur / file tidak ditemukan (mode DNS-01 Cloudflare).');
            }
            $cmd[] = '--dns-cloudflare';
            $cmd[] = '--dns-cloudflare-credentials';
            $cmd[] = $this->cloudflareCreds;
        } else {
            $cmd[] = '--webroot';
            $cmd[] = '-w';
            $cmd[] = $this->webroot;
        }

        return $cmd;
    }
}
