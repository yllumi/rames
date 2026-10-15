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
    /**
     * Batas panjang pesan galat certbot yang disimpan di berkas app
     * (`{slot}_error`) / log job — cukup untuk memuat baris `Detail:`.
     */
    private const MAX_FAILURE_TEXT = 2000;

    /** Penanda keluaran certbot yang dipotong (menempel di ekor keluaran). */
    private const TRUNCATION_MARKER = "\n…(keluaran certbot dipotong)";

    /** Jatah minimum keluaran certbot saat paragraf hint ikut dipasang. */
    private const MIN_FAILURE_TEXT = 400;

    /** Pemisah keluaran certbot dengan paragraf hint tindakan. */
    private const HINT_SEPARATOR = "\n\n";

    private ProcessRunner $runner;
    private int $timeout;

    /**
     * Direktori kerja/log certbot (`deploy.certbot_work_dir` /
     * `deploy.certbot_logs_dir`). Seam uji — jalur temp membuat `issue()` bisa
     * dijalankan tanpa `/var/lib` & `/var/log` — sekaligus jalur mempersist log
     * debug certbot (mis. `CERTBOT_LOGS_DIR=${PWD}/runtime/logs/letsencrypt`)
     * supaya tidak hilang saat container di-recreate.
     */
    private string $workDir;
    private string $logsDir;

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
        $this->workDir = self::dirSetting(
            (string) config('deploy.certbot_work_dir', '/var/lib/letsencrypt'),
            '/var/lib/letsencrypt'
        );
        $this->logsDir = self::dirSetting(
            (string) config('deploy.certbot_logs_dir', '/var/log/letsencrypt'),
            '/var/log/letsencrypt'
        );
        $this->challenge = (string) config('deploy.ssl_challenge', 'http');
        $this->caServer = (string) config('deploy.ssl_ca_server', 'production');
        $this->webroot = (string) config('deploy.ssl_webroot', base_path() . '/webroot');
        $this->cloudflareCreds = (string) config('deploy.cloudflare_creds', '');
        $this->adminEmail = (string) config('deploy.admin_email', '');
    }

    /**
     * Nilai direktori dari config: kosong/hanya spasi, ber-NUL byte, atau bukan
     * path absolut ⇒ jatuh ke `$default`. Melindungi certbot dari `--work-dir ''`
     * (env salah tulis) tanpa mengubah perilaku default.
     */
    private static function dirSetting(string $value, string $default): string
    {
        $value = trim($value);
        if ($value === '' || !str_starts_with($value, '/') || str_contains($value, "\0")) {
            return $default;
        }
        $trimmed = rtrim($value, '/');

        return $trimmed === '' ? '/' : $trimmed;
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
        // Preflight SEBELUM efek samping apa pun (certbot & rate limit Let's
        // Encrypt): kelas kegagalan `403` — webroot tidak bisa ditembus/dibaca
        // user non-root milik nginx host — sudah bisa dipastikan di sini.
        $this->assertChallengePathServable($domain);
        $command = $this->buildCommand($domain);
        $result = $this->runner->run($command, null, $this->timeout);

        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Gagal menerbitkan sertifikat SSL untuk ' . $domain . ': ' . $this->issueFailureText($result)
            );
        }
        if (!$this->certExists($domain)) {
            throw new RuntimeException('certbot selesai tetapi sertifikat tidak ditemukan untuk ' . $domain . '.');
        }
    }

    /**
     * Ringkas keluaran certbot untuk pesan galat — **kedua** stream dibaca.
     *
     * Blok diagnosa certbot ("Certbot failed to authenticate some domains …
     * Detail: …") ditulis ke **stdout**, sedangkan ringkasannya ("Saving debug
     * log … / Some challenges have failed.") ke **stderr**. Memilih hanya salah
     * satu membuat pesan yang sampai ke UI/log job kehilangan sebab aslinya —
     * `Detail: … 404`, `Timeout during connect`, dan `DNS problem` semuanya
     * tampak sama saja.
     *
     * @param array{code:int,stdout:string,stderr:string,timedOut?:bool} $result
     * @param int $limit batas karakter badan pesan (default `MAX_FAILURE_TEXT`)
     */
    private static function failureText(array $result, int $limit = self::MAX_FAILURE_TEXT): string
    {
        $parts = [];
        foreach (['stdout', 'stderr'] as $stream) {
            $text = trim((string) ($result[$stream] ?? ''));
            if ($text !== '' && !in_array($text, $parts, true)) {
                $parts[] = $text;
            }
        }

        $text = implode("\n", $parts);
        if ($text === '') {
            return 'certbot keluar tanpa keluaran (lihat log certbot di server ini).';
        }

        return strlen($text) > $limit
            ? substr($text, 0, $limit) . self::TRUNCATION_MARKER
            : $text;
    }

    /**
     * Keluaran certbot untuk `issue()` **plus** paragraf hint tindakan bila
     * polanya kegagalan penyajian berkas challenge dengan `403`
     * (ARCHITECTURE §8 GOTCHA no. 28).
     *
     * Urutan penggabungan dibuat agar hint tidak pernah terpotong: jatah
     * keluaran certbot dikurangi lebih dulu sebesar panjang hint (+ pemisah +
     * penanda potong) sehingga panjang pesan total tetap ≤ `MAX_FAILURE_TEXT`.
     * Pemeriksaan pola 403 memakai keluaran **utuh** (sebelum dipotong), jadi
     * hint tidak hilang saat keluaran certbot sangat panjang.
     *
     * @param array{code:int,stdout:string,stderr:string,timedOut?:bool} $result
     */
    private function issueFailureText(array $result): string
    {
        $hint = $this->challengeForbiddenHint($result);
        if ($hint === '') {
            return self::failureText($result);
        }

        $budget = self::MAX_FAILURE_TEXT
            - strlen(self::HINT_SEPARATOR)
            - strlen(self::TRUNCATION_MARKER)
            - strlen($hint);

        return self::failureText($result, max($budget, self::MIN_FAILURE_TEXT))
            . self::HINT_SEPARATOR
            . $hint;
    }

    /**
     * Paragraf hint tindakan untuk kegagalan HTTP-01 ber-`403`: berkas challenge
     * **ada** (certbot berhasil menulisnya) tetapi nginx host menolak
     * menyajikannya — biasanya izin/path webroot, bukan certbot.
     *
     * Heuristik pemantik (sengaja konservatif — hint yang salah lebih buruk
     * daripada tidak ada hint): keluaran certbot memuat `Invalid response`
     * **dan** token `403`. Kegagalan `404`, `Timeout during connect`, atau
     * `DNS problem` tidak memicunya.
     *
     * @param array{code:int,stdout:string,stderr:string,timedOut?:bool} $result
     */
    private function challengeForbiddenHint(array $result): string
    {
        $text = (string) ($result['stdout'] ?? '') . "\n" . (string) ($result['stderr'] ?? '');
        if (preg_match('/invalid\s+response/i', $text) !== 1 || preg_match('/\b403\b/', $text) !== 1) {
            return '';
        }

        return 'Hint 403: permintaan berkas challenge DITOLAK SAAT DISAJIKAN (berkasnya ada — '
            . 'certbot yang menuliskannya). Periksa: (1) `tail -n 50 /var/log/nginx/error.log` '
            . '(cari `Permission denied`); (2) `namei -l ' . $this->challengeDir() . '` — semua komponen '
            . 'path wajib punya bit `x` untuk user non-root milik nginx host; (3) webroot wajib terlihat '
            . 'di DUA sisi: certbot menulis berkas dari DALAM container, nginx host menyajikannya dari '
            . 'luar container (path di luar repo butuh bind mount tambahan; `${PWD}:${PWD}` hanya menutup '
            . 'path di dalam repo).';
    }

    /**
     * Cabut (revoke) sertifikat Let's Encrypt untuk sebuah domain lalu hapus
     * direktori cert-nya. No-op bila sertifikat tidak (lagi) ada di disk.
     *
     * Dipakai saat custom domain dihapus/diganti dari app — sertifikat lama
     * tidak boleh dibiarkan aktif untuk domain yang tidak lagi dikelola.
     *
     * @throws RuntimeException bila certbot revoke gagal
     */
    public function revoke(string $domain): void
    {
        if (!$this->certExists($domain)) {
            return;
        }

        $cmd = [
            'certbot', 'revoke',
            '--non-interactive',
            '--cert-name', $domain,
            '--config-dir', $this->lePath,
            '--work-dir', $this->workDir,
            '--logs-dir', $this->logsDir,
        ];
        if ($this->caServer === 'staging') {
            $cmd[] = '--staging';
        }

        $result = $this->runner->run($cmd, null, 120);
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal revoke sertifikat untuk ' . $domain . ': ' . self::failureText($result));
        }

        // Bersihkan artefak certbot domain (live symlink, archive, renewal config)
        foreach ([
            $this->lePath . '/live/' . $domain,
            $this->lePath . '/archive/' . $domain,
            $this->lePath . '/renewal/' . $domain . '.conf',
        ] as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeRecursive($path);
            }
        }
    }

    private function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Pastikan direktori webroot, direktori challenge HTTP-01, & direktori kerja
     * certbot tersedia.
     */
    private function prepareDirs(): void
    {
        if (!is_dir($this->webroot) && !mkdir($this->webroot, 0755, true) && !is_dir($this->webroot)) {
            throw new RuntimeException('Tidak bisa membuat webroot: ' . $this->webroot);
        }
        // Certbot menulis berkas challenge ke <webroot>/.well-known/acme-challenge
        // dan nginx host (user non-root) harus bisa menembus + membacanya. Dibuat
        // lebih awal supaya preflight izin di `assertChallengePathServable()`
        // memeriksa direktori yang sesungguhnya dipakai certbot.
        $challengeDir = $this->challengeDir();
        if (!is_dir($challengeDir) && !mkdir($challengeDir, 0755, true) && !is_dir($challengeDir)) {
            throw new RuntimeException('Tidak bisa membuat direktori challenge HTTP-01: ' . $challengeDir);
        }
        foreach ([$this->workDir, $this->logsDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Tidak bisa membuat direktori certbot: ' . $dir);
            }
        }
    }

    /**
     * Direktori tujuan berkas challenge HTTP-01 — ditulis certbot (di dalam
     * container) dan disajikan nginx host lewat
     * `location ^~ /.well-known/acme-challenge/ { root <webroot>; }`.
     */
    private function challengeDir(): string
    {
        return rtrim($this->webroot, '/') . '/.well-known/acme-challenge';
    }

    /**
     * Preflight HTTP-01: pastikan **setiap** komponen path challenge bisa
     * ditembus/dibaca user non-root — worker nginx host berjalan sebagai
     * `www-data`/`nginx`, bukan root — SEBELUM certbot dipanggil. Tanpa ini,
     * webroot di balik direktori `0700` (mis. instalasi di `/root/...`) baru
     * ketahuan setelah certbot membuang jatah rate limit Let's Encrypt dan
     * hanya menghasilkan `Detail: … 403`.
     *
     * Heuristik izin (tanpa asumsi uid/gid nginx — grup user nginx bisa
     * bervariasi, jadi jangan memakai `is_readable()` milik proses ini):
     * - komponen direktori induk hanya dianggap penghalang bila `other` **dan**
     *   `group` dua-duanya tanpa bit `x` → `0750 root:www-data` tetap lolos;
     * - direktori challenge dianggap penghalang bila `other` **dan** `group`
     *   dua-duanya tanpa `r`+`x`; berkas di dalamnya bila dua-duanya tanpa `r`.
     *
     * Mode DNS-01 tidak menyajikan berkas challenge lewat nginx host, jadi
     * path webroot tidak relevan dan tidak diperiksa (jangan menggagalkan
     * jalur yang sehat).
     *
     * @throws RuntimeException bila secara pasti tidak ada user non-root yang bisa membaca
     */
    private function assertChallengePathServable(string $domain): void
    {
        if ($this->challenge === 'dns-cloudflare') {
            return;
        }

        $challengeDir = $this->challengeDir();
        $offenders = [];

        foreach (self::pathComponents($challengeDir) as $component) {
            if ($component === $challengeDir) {
                continue; // diperiksa dengan aturan `r`+`x` di bawah
            }
            $perms = self::permissionBits($component);
            if ($perms !== null && ($perms & 0011) === 0) {
                $offenders[] = [$component, $perms];
            }
        }

        $perms = self::permissionBits($challengeDir);
        if ($perms !== null && ($perms & 0055) === 0) {
            $offenders[] = [$challengeDir, $perms];
        }
        // Sisa berkas dari percobaan sebelumnya (mis. ditulis `0600`) juga harus
        // terdeteksi — nginx tidak akan bisa membacanya.
        foreach (self::regularFiles($challengeDir) as $file) {
            $perms = self::permissionBits($file);
            if ($perms !== null && ($perms & 0044) === 0) {
                $offenders[] = [$file, $perms];
            }
        }

        if ($offenders !== []) {
            throw new RuntimeException($this->webrootNotServableMessage($domain, $offenders));
        }
    }

    /**
     * Pesan galat preflight: path bermasalah + mode oktalnya, sebab singkat, dan
     * **dua** jalan keluar yang bisa langsung dikerjakan operator.
     *
     * @param array<int,array{0:string,1:int}> $offenders
     */
    private function webrootNotServableMessage(string $domain, array $offenders): string
    {
        $list = [];
        foreach ($offenders as [$path, $perms]) {
            $list[] = $path . ' (mode ' . sprintf('%04o', $perms) . ')';
        }

        return 'Gagal menerbitkan sertifikat SSL untuk ' . $domain
            . ': webroot HTTP-01 tidak bisa dilayani — ' . implode(', ', $list)
            . ': nginx host berjalan sebagai user non-root dan tidak bisa menembus direktori ini, '
            . 'sehingga permintaan berkas challenge akan dijawab 403 (certbot menulis berkasnya, nginx host menolak menyajikannya). '
            . 'Jalan keluar (pilih salah satu): '
            . '(1) pindahkan instalasi dashboard ke path yang traversable oleh user non-root '
            . '(mis. /srv/… alih-alih /root/…); ATAU '
            . '(2) set env SSL_WEBROOT ke direktori yang traversable DAN terlihat di dalam container '
            . '(path di dalam repo sudah di-bind `${PWD}:${PWD}`; path di luar repo butuh bind mount tambahan), '
            . 'lalu tulis ulang vhost (Deploy Ulang) supaya blok `location ^~ /.well-known/acme-challenge/` '
            . 'menunjuk direktori baru.';
    }

    /**
     * Rantai komponen path dari root sampai `$path` (inklusif). Komponen yang
     * tidak ada di disk tetap disertakan — pemeriksa izin melewatinya.
     *
     * @return array<int,string>
     */
    private static function pathComponents(string $path): array
    {
        $chain = [];
        $current = $path;
        while ($current !== '' && $current !== '.' && $current !== '/') {
            $chain[] = $current;
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
        $chain[] = '/';

        return array_reverse($chain);
    }

    /** Mode izin 3 digit (mis. 0755), atau null bila path tidak ada/tak terbaca. */
    private static function permissionBits(string $path): ?int
    {
        $perms = @fileperms($path);

        return $perms === false ? null : ($perms & 0777);
    }

    /**
     * Berkas reguler di dalam sebuah direktori (tanpa subdirektori).
     *
     * @return array<int,string>
     */
    private static function regularFiles(string $dir): array
    {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($dir . '/' . $entry)) {
                $files[] = $dir . '/' . $entry;
            }
        }

        return $files;
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
            '--work-dir', $this->workDir,
            '--logs-dir', $this->logsDir,
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
