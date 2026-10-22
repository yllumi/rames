<?php
declare(strict_types=1);

namespace app\library\Nginx;

use app\library\Domain\ParkedDomainContent;
use app\library\Domain\ParkedDomainValidator;
use RuntimeException;

/**
 * Render & tulis config Nginx untuk **domain parking** (entitas global, di luar
 * app). Satu entri parkir = satu file `_parked_{id}.conf` di direktori config
 * host (ditulis lewat {@see NginxConfigGenerator} agar prasyarat tulis +
 * symlink `sites-enabled` tidak diduplikasi).
 *
 * Prefiks `_parked_` tidak mungkin bentrok dengan config app: nama app
 * (`AppStore`/`ContainerNames`) tidak boleh berawalan `_`.
 *
 * SSL per entri (opsional): bila `ssl_status === 'active'` **dan** berkas
 * sertifikat ada, blok `listen 443 ssl` ikut dirender memakai
 * `{letsencryptPath}/live/{domain}/{fullchain,privkey}.pem`.
 * - Mode `html`: blok 80 (ACME) → `return 301 https://$host$request_uri`,
 *   blok 443 menyajikan `root` + `index index.html` + `try_files`.
 * - Mode `redirect`: kode & target yang sama di blok 80 **dan** 443; blok 80
 *   TIDAK dialihkan ke https agar tidak terjadi redirect ganda.
 *
 * Blok `location ^~ /.well-known/acme-challenge/ { root {webroot}; }` **hanya**
 * dirender di blok 80 (cukup untuk HTTP-01) — juga saat SSL aktif.
 *
 * Bila status `active` tetapi berkas cert hilang (mis. restore DB tanpa
 * `/etc/letsencrypt`), render **jatuh kembali ke HTTP-only**. Config
 * ber-`listen 443` dengan cert tak ada membuat `nginx -t` GAGAL untuk SELURUH
 * host (semua app ikut macet), jadi fallback ini disengaja dan tidak melempar.
 *
 * `render()` memvalidasi ULANG domain/target lewat {@see ParkedDomainValidator}
 * (pertahanan berlapis: data store bisa saja rusak/diedit manual) sehingga
 * tidak ada karakter yang bisa menyuntik direktif Nginx. Path `root` juga
 * diperiksa (tanpa spasi/kuote/titik-koma) sebelum masuk config.
 */
final class ParkedNginxConfig
{
    /** Komentar penanda berkas — dibaca operator, bukan direktif. */
    public const HEADER_MARKER = 'dikelola Rames; JANGAN edit manual.';

    public function __construct(private readonly NginxConfigGenerator $writer)
    {
    }

    /**
     * Nama berkas config (tanpa `.conf` — ditambahkan `NginxConfigGenerator::write()`).
     *
     * @throws RuntimeException bila id tidak berpola `pd_` + 12 hex (path traversal)
     */
    public static function fileName(string $id): string
    {
        if (preg_match(ParkedDomainContent::ID_PATTERN, $id) !== 1) {
            throw new RuntimeException(
                'Id domain parkir tidak valid: "' . $id . '". Id harus berbentuk "pd_" diikuti 12 karakter heksadesimal.'
            );
        }

        return '_parked_' . $id;
    }

    /**
     * Render satu server block domain parkir.
     *
     * @param array<string,mixed> $entry           entri dari {@see \app\library\Storage\ParkedDomainStore}
     * @param string              $contentDir      akar berkas HTML entri (dipakai mode `html`)
     * @param string              $webroot         webroot HTTP-01 (`location acme-challenge`)
     * @param string              $letsencryptPath direktori Let's Encrypt (`live/{domain}/{fullchain,privkey}.pem`)
     *
     * @throws RuntimeException bila id/domain/mode/target/path tidak aman
     */
    public static function render(
        array $entry,
        string $contentDir,
        string $webroot,
        string $letsencryptPath = '/etc/letsencrypt'
    ): string {
        self::fileName((string) ($entry['id'] ?? '')); // validasi id sekali di sini

        $domain = ParkedDomainValidator::normalizeDomain(
            is_scalar($entry['domain'] ?? null) ? (string) $entry['domain'] : ''
        );
        if (!ParkedDomainValidator::isValidDomain($domain)) {
            throw new RuntimeException(
                'Domain parkir tidak valid: "' . $domain . '". Gunakan FQDN publik seperti contoh.com.'
            );
        }

        $mode = is_scalar($entry['mode'] ?? null) ? strtolower(trim((string) $entry['mode'])) : '';
        $webrootPath = self::assertPath($webroot, 'webroot ACME');

        // Status SSL hanya dipercaya bila berkas cert benar-benar ada. Path LE
        // divalidasi HANYA saat status active (non-SSL tidak memakainya).
        $sslActive = strtolower(trim(
            is_scalar($entry['ssl_status'] ?? null) ? (string) $entry['ssl_status'] : ''
        )) === 'active';
        $cert = '';
        $key = '';
        if ($sslActive) {
            $lePath = self::assertPath($letsencryptPath, "direktori Let's Encrypt");
            $cert = $lePath . '/live/' . $domain . '/fullchain.pem';
            $key = $lePath . '/live/' . $domain . '/privkey.pem';
            $sslActive = is_file($cert) && is_file($key);
        }
        $sslDirectives = $sslActive
            ? "    ssl_certificate {$cert};\n"
                . "    ssl_certificate_key {$key};\n"
                . '    ssl_protocols TLSv1.2 TLSv1.3;'
            : '';

        $header = '# Domain parking ' . $domain . ' — ' . self::HEADER_MARKER . "\n";
        $acme = "    location ^~ /.well-known/acme-challenge/ {\n        root {$webrootPath};\n    }";

        if ($mode === 'html') {
            $root = self::assertPath($contentDir, 'direktori konten');

            if ($sslActive) {
                return $header . <<<NGINX
server {
    listen 80;
    server_name {$domain};

{$acme}

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    server_name {$domain};

{$sslDirectives}

    root {$root};
    index index.html;
    try_files \$uri \$uri/ =404;
}
NGINX . "\n";
            }

            return $header . <<<NGINX
server {
    listen 80;
    server_name {$domain};

    root {$root};
    index index.html;
    try_files \$uri \$uri/ =404;

{$acme}
}
NGINX . "\n";
        }

        if ($mode === 'redirect') {
            $target = ParkedDomainValidator::normalizeRedirectTarget(
                is_scalar($entry['redirect_to'] ?? null) ? (string) $entry['redirect_to'] : ''
            );
            if (!ParkedDomainValidator::isValidRedirectTarget($target)) {
                throw new RuntimeException(
                    'Target redirect tidak valid: "' . $target . '". Gunakan URL http(s):// tanpa userinfo, query, '
                    . 'fragmen, atau karakter kutip (mis. https://contoh.com).'
                );
            }
            $code = ParkedDomainValidator::normalizeRedirectCode($entry['redirect_code'] ?? null);

            $httpBlock = <<<NGINX
server {
    listen 80;
    server_name {$domain};

{$acme}

    location / {
        return {$code} {$target}\$request_uri;
    }
}
NGINX;

            if (!$sslActive) {
                return $header . $httpBlock . "\n";
            }

            // Blok 80 tetap mengembalikan kode/target yang sama (bukan 301 ke
            // https) supaya klien tidak mengalami redirect ganda.
            $httpsBlock = <<<NGINX
server {
    listen 443 ssl;
    server_name {$domain};

{$sslDirectives}

    location / {
        return {$code} {$target}\$request_uri;
    }
}
NGINX;

            return $header . $httpBlock . "\n\n" . $httpsBlock . "\n";
        }

        throw new RuntimeException(
            'Mode domain parkir tidak valid: "' . $mode . '". Pilih "html" atau "redirect".'
        );
    }

    /**
     * Tulis config satu entri (menambah `.conf` + symlink enabled lewat writer).
     *
     * @param array<string,mixed> $entry
     */
    public function write(
        array $entry,
        string $contentDir,
        string $webroot,
        string $letsencryptPath = '/etc/letsencrypt'
    ): void {
        $id = is_scalar($entry['id'] ?? null) ? (string) $entry['id'] : '';
        $this->writer->write(
            self::fileName($id),
            self::render($entry, $contentDir, $webroot, $letsencryptPath)
        );
    }

    /**
     * Hapus config satu entri (berkas + symlink). No-op bila tidak ada.
     */
    public function remove(string $id): void
    {
        $this->writer->remove(self::fileName($id));
    }

    /**
     * Path yang aman untuk direktif Nginx (`root`, `ssl_certificate`).
     *
     * Path **wajib absolut**: Nginx host me-resolve path relatif terhadap
     * `prefix`/cwd-nya sendiri, bukan terhadap `${PWD}` dashboard, sehingga
     * `root database/parked/...` berakhir 404 senyap. Karakter kontrol, spasi,
     * kutip, titik-koma, kurung kurawal, `#`, backslash, dan `$` (ekspansi
     * variabel Nginx) ditolak, begitu pula segmen `..` (traversal).
     */
    private static function assertPath(string $path, string $label): string
    {
        $clean = rtrim(trim($path), '/\\');
        if ($clean === '' && $path !== '/') {
            throw new RuntimeException(
                'Path ' . $label . ' kosong. Path ini wajib absolut (diawali "/") dan sama persis di host, '
                . 'mis. /srv/rames/database/parked.'
            );
        }
        if ($clean === '') {
            $clean = '/';
        }
        if (!str_starts_with($clean, '/')) {
            throw new RuntimeException(
                'Path ' . $label . ' wajib absolut (diawali "/") dan sama persis di host, '
                . 'mis. /srv/rames/database/parked. Path relatif di-resolve relatif oleh Nginx host '
                . 'sehingga halaman parkir 404; setel PARKED_ROOT ke path absolut (di bawah ${PWD}).'
            );
        }
        if (preg_match('/[\x00-\x20\x7f;{}\'"\\\\#$]/', $clean) === 1) {
            throw new RuntimeException(
                'Path ' . $label . ' tidak aman untuk config Nginx: "' . $path . '". '
                . 'Hindari spasi, kutip, kurung kurawal, titik-koma, karakter #, dan karakter $ '
                . '($ tidak diizinkan karena diekspansi sebagai variabel Nginx).'
            );
        }
        if (in_array('..', explode('/', $clean), true)) {
            throw new RuntimeException(
                'Path ' . $label . ' tidak boleh mengandung segmen "..": "' . $path . '". '
                . 'Segmen ".." tidak diizinkan; gunakan path absolut tanpa "..", '
                . 'mis. /srv/rames/database/parked.'
            );
        }

        return $clean;
    }
}
