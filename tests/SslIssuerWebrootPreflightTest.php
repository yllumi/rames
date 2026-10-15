<?php
declare(strict_types=1);

namespace Tests;

use app\library\SSL\SslIssuer;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

// Konstruktor SslIssuer mengevaluasi `base_path()` untuk default webroot; helper
// webman itu butuh konstanta BASE_PATH yang tidak ada di luar runtime webman
// (pola sama seperti SslIssuerFailureTextTest/NginxReloaderArgvTest).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Preflight HTTP-01 `SslIssuer::assertChallengePathServable()` + seam direktori
 * certbot (`deploy.certbot_work_dir` / `deploy.certbot_logs_dir`) + hint `403`.
 *
 * Kenapa preflight ini ada: certbot menulis berkas challenge ke webroot, tetapi
 * yang MENYAJIKANNYA adalah worker Nginx **host** (user non-root). Webroot di
 * balik direktori `0700` (mis. instalasi di `/root/...`) baru ketahuan setelah
 * certbot membuang jatah rate limit Let's Encrypt dan hanya menghasilkan
 * `Detail: … 403`. Preflight memakai **bit mode** (`fileperms()&0777`), bukan
 * `is_readable()` proses ini (root) — karena itu `0750 root:www-data` harus
 * LOLOS sedangkan `0700` harus DITOLAK.
 *
 * Semua jalur diarahkan ke direktori TEMP (config `deploy.*` + `ProcessRunner`
 * palsu): test TIDAK memanggil certbot, TIDAK menyentuh `/var/lib`,
 * `/var/log`, atau data runtime repo.
 */
class SslIssuerWebrootPreflightTest extends TestCase
{
    private const DOMAIN = 'ssl-preflight.example.com';
    private const MARKER = "\n…(keluaran certbot dipotong)";
    private const HINT_PREFIX = 'Hint 403: permintaan berkas challenge DITOLAK SAAT DISAJIKAN';
    private const HINT_TAIL = 'path di dalam repo).';
    private const FAIL_PREFIX = 'Gagal menerbitkan sertifikat SSL untuk ' . self::DOMAIN . ': ';

    private string $tmp;
    private string $webroot;
    private string $work;
    private string $logs;
    private int $oldUmask = 0;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        // Mode hasil `mkdir()` harus deterministik (webroot/challenge 0755
        // diasumsikan preflight) — umask host tidak boleh mengubah hasil test.
        $this->oldUmask = umask(0022);

        $this->tmp = sys_get_temp_dir() . '/rames-sslpf-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
        mkdir($this->tmp . '/config', 0755, true);
        $this->webroot = $this->tmp . '/webroot';
        $this->work = $this->tmp . '/certbot-work';
        $this->logs = $this->tmp . '/certbot-logs';

        $this->configState = $this->snapshotConfigState();
        $this->loadDeploy();
    }

    protected function tearDown(): void
    {
        Config::clear();
        $this->restoreConfigState($this->configState);
        $this->removeRecursive($this->tmp);
        umask($this->oldUmask);
    }

    // ---------------------------------------------------------------- dirSetting
    /** @return array<string,array{0:string,1:string,2:string}> nilai, default, harapan */
    public static function dirSettingProvider(): array
    {
        return [
            'kosong' => ['', '/var/lib/letsencrypt', '/var/lib/letsencrypt'],
            'hanya spasi' => ['   ', '/var/log/letsencrypt', '/var/log/letsencrypt'],
            'spasi+tab' => [" \t ", '/var/lib/letsencrypt', '/var/lib/letsencrypt'],
            'relatif' => ['var/lib/letsencrypt', '/var/lib/letsencrypt', '/var/lib/letsencrypt'],
            'relatif titik' => ['./runtime/logs', '/var/log/letsencrypt', '/var/log/letsencrypt'],
            'ber-NUL di tengah' => ["/tmp/ber\0nul", '/var/log/letsencrypt', '/var/log/letsencrypt'],
            'absolut apa adanya' => ['/srv/rames/letsencrypt-work', '/var/lib/letsencrypt', '/srv/rames/letsencrypt-work'],
            'trailing slash' => ['/srv/rames/letsencrypt-work/', '/var/lib/letsencrypt', '/srv/rames/letsencrypt-work'],
            'trailing slash ganda' => ['/srv/rames//', '/var/lib/letsencrypt', '/srv/rames'],
            'root saja' => ['/', '/var/lib/letsencrypt', '/'],
            'trailing slash + spasi' => ['  /srv/x/  ', '/var/lib/letsencrypt', '/srv/x'],
        ];
    }

    /**
     * @dataProvider dirSettingProvider
     */
    public function testDirSettingMenormalkanNilai(string $value, string $default, string $expected): void
    {
        $this->assertSame($expected, self::callDirSetting($value, $default));
        $this->assertStringNotContainsString("\0", self::callDirSetting($value, $default));
    }

    public function testDirSettingTrailingNulTidakIkutTerpakai(): void
    {
        // `trim()` PHP juga membuang NUL di ujung — nilai hasil wajib bersih.
        $result = self::callDirSetting("/tmp/x\0", '/var/log/letsencrypt');

        $this->assertSame('/tmp/x', $result);
        $this->assertStringNotContainsString("\0", $result);
    }

    // ---------------------------------------------------------------- work/log dir
    /** Kunci config tidak ada (instalasi lama) ⇒ default literal lama. */
    public function testWorkDirLogsDirDefaultLiteralBilaKunciConfigTidakAda(): void
    {
        $this->loadDeployBare();

        $issuer = $this->issuer();
        $this->assertSame('/var/lib/letsencrypt', $this->privateString($issuer, 'workDir'));
        $this->assertSame('/var/log/letsencrypt', $this->privateString($issuer, 'logsDir'));
    }

    /** Nilai config dipakai apa adanya (setelah normalisasi) + dipakai di argv. */
    public function testWorkDirLogsDirDipakaiDiArgvIssue(): void
    {
        $this->loadDeploy([
            'certbot_work_dir' => '  ' . $this->work . '/  ',   // spasi + trailing slash
            'certbot_logs_dir' => $this->logs,
        ]);

        [$msg, $calls] = $this->issueFails($this->certbotFailure());

        $this->assertCount(1, $calls, 'preflight lolos ⇒ certbot sekali dipanggil');
        $this->assertStringContainsString('--work-dir ' . $this->work . ' --logs-dir ' . $this->logs, $calls[0]);
        $this->assertStringNotContainsString('/var/lib/letsencrypt', $calls[0], 'tidak boleh lagi hardcoded');
        $this->assertStringNotContainsString('/var/log/letsencrypt', $calls[0], 'tidak boleh lagi hardcoded');
        $this->assertDirectoryExists($this->work, 'prepareDirs() membuat direktori kerja dari config');
        $this->assertDirectoryExists($this->logs);
        $this->assertStringContainsString('Gagal menerbitkan sertifikat SSL', $msg);
    }

    /** Nilai env tak masuk akal dinormalkan ke default (bukan `--work-dir ''`). */
    public function testWorkDirLogsDirNormalisasiNilaiConfigRusak(): void
    {
        $this->loadDeploy(['certbot_work_dir' => '', 'certbot_logs_dir' => 'relatif/log']);

        $issuer = $this->issuer();
        $this->assertSame('/var/lib/letsencrypt', $this->privateString($issuer, 'workDir'));
        $this->assertSame('/var/log/letsencrypt', $this->privateString($issuer, 'logsDir'));
    }

    /** `config/deploy.php` memetakan CERTBOT_WORK_DIR/CERTBOT_LOGS_DIR, default tetap. */
    public function testConfigDeployMemetakanEnvCertbotDir(): void
    {
        $prevWork = getenv('CERTBOT_WORK_DIR');
        $prevLogs = getenv('CERTBOT_LOGS_DIR');
        try {
            putenv('CERTBOT_WORK_DIR=');
            putenv('CERTBOT_LOGS_DIR=');
            $cfg = require BASE_PATH . '/config/deploy.php';
            $this->assertSame('/var/lib/letsencrypt', $cfg['certbot_work_dir']);
            $this->assertSame('/var/log/letsencrypt', $cfg['certbot_logs_dir']);

            putenv('CERTBOT_WORK_DIR=' . $this->tmp . '/env-work');
            putenv('CERTBOT_LOGS_DIR=' . $this->tmp . '/env-logs');
            $cfgEnv = require BASE_PATH . '/config/deploy.php';
            $this->assertSame($this->tmp . '/env-work', $cfgEnv['certbot_work_dir']);
            $this->assertSame($this->tmp . '/env-logs', $cfgEnv['certbot_logs_dir']);
        } finally {
            putenv('CERTBOT_WORK_DIR=' . ($prevWork === false ? '' : $prevWork));
            putenv('CERTBOT_LOGS_DIR=' . ($prevLogs === false ? '' : $prevLogs));
        }
    }

    // ---------------------------------------------------------------- preflight
    /** Induk (di atas webroot) tanpa bit `x` untuk other+group ⇒ tolak SEBELUM certbot. */
    public function testPreflightMenolakIndukTanpaBitX(): void
    {
        $locked = $this->tmp . '/locked';
        mkdir($locked, 0755, true);
        chmod($locked, 0700);
        $this->webroot = $locked . '/webroot';
        $this->loadDeploy();

        [$msg, $calls] = $this->issueFails($this->certbotFailure());

        $this->assertSame([], $calls, 'preflight gagal ⇒ certbot TIDAK boleh dipanggil (rate limit LE)');
        $this->assertStringContainsString('webroot HTTP-01 tidak bisa dilayani', $msg);
        $this->assertStringContainsString($locked . ' (mode 0700)', $msg, 'path + mode pelanggar disebut');
        $this->assertStringContainsString('SSL_WEBROOT', $msg, 'pesan wajib memuat jalan keluar operator');
        $this->assertStringContainsString('Deploy Ulang', $msg);
    }

    /** Direktori challenge tanpa `r`+`x` ⇒ tolak. */
    public function testPreflightMenolakDirChallengeTanpaBitRx(): void
    {
        $challenge = $this->webroot . '/.well-known/acme-challenge';
        mkdir($challenge, 0755, true);
        chmod($challenge, 0700);

        [$msg, $calls] = $this->issueFails($this->certbotFailure());

        $this->assertSame([], $calls);
        $this->assertStringContainsString($challenge . ' (mode 0700)', $msg);
    }

    /** Berkas sisa dari percobaan sebelumnya tanpa bit `r` (mis. 0600/0200) ⇒ tolak. */
    public function testPreflightMenolakBerkasSisaTanpaBitR(): void
    {
        $challenge = $this->webroot . '/.well-known/acme-challenge';
        mkdir($challenge, 0755, true);
        $leftover = $challenge . '/token-lama';
        file_put_contents($leftover, 'x');
        chmod($leftover, 0600);

        [$msg, $calls] = $this->issueFails($this->certbotFailure());

        $this->assertSame([], $calls);
        $this->assertStringContainsString($leftover . ' (mode 0600)', $msg);
    }

    /** 0755 (other bisa `r`+`x`) ⇒ lolos; certbot tetap dipanggil. */
    public function testPreflightLolosUntukMode0755(): void
    {
        mkdir($this->webroot, 0755, true);
        chmod($this->webroot, 0755);

        [$msg, $calls] = $this->issueFails($this->certbotFailure('Detail: Invalid response … 404'));

        $this->assertCount(1, $calls);
        $this->assertStringNotContainsString('tidak bisa dilayani', $msg);
        $this->assertStringStartsWith(self::FAIL_PREFIX, $msg);
    }

    /**
     * 0750 `root:www-data` ⇒ LOLOS: grup user Nginx punya bit `x`. Ini justru
     * setup lazim di server (webroot milik grup Nginx) — preflight yang memakai
     * `is_readable()`/`other` saja akan salah menolak.
     */
    public function testPreflightLolosUntuk0750GroupPunyaBitX(): void
    {
        $grouped = $this->tmp . '/grouped';
        mkdir($grouped, 0755, true);
        chmod($grouped, 0750);
        $this->webroot = $grouped . '/webroot';
        $this->loadDeploy();

        [, $calls] = $this->issueFails($this->certbotFailure());

        $this->assertCount(1, $calls, '0750 (group punya x) tidak boleh ditolak preflight');
    }

    /** 0711 (other `x` tanpa `r`) juga traversable untuk Nginx ⇒ lolos. */
    public function testPreflightLolosUntukMode0711(): void
    {
        $traverse = $this->tmp . '/traverse';
        mkdir($traverse, 0755, true);
        chmod($traverse, 0711);
        $this->webroot = $traverse . '/webroot';
        $this->loadDeploy();

        [, $calls] = $this->issueFails($this->certbotFailure());

        $this->assertCount(1, $calls);
    }

    /** Mode DNS-01 tidak disajikan Nginx host ⇒ preflight DILEWATI (webroot 0700 pun). */
    public function testPreflightDilewatiUntukDnsCloudflare(): void
    {
        $locked = $this->tmp . '/locked-dns';
        mkdir($locked, 0755, true);
        chmod($locked, 0700);
        $creds = $this->tmp . '/cloudflare.ini';
        file_put_contents($creds, "dns_cloudflare_api_token = xxx\n");
        $this->webroot = $locked . '/webroot';
        $this->loadDeploy(['ssl_challenge' => 'dns-cloudflare', 'cloudflare_creds' => $creds]);

        [$msg, $calls] = $this->issueFails($this->certbotFailure());

        $this->assertCount(1, $calls, 'DNS-01: preflight webroot tidak relevan');
        $this->assertStringNotContainsString('tidak bisa dilayani', $msg);
        $this->assertStringContainsString('--dns-cloudflare', $calls[0]);
        $this->assertStringNotContainsString('--webroot', $calls[0]);
    }

    /** `prepareDirs()` membuat direktori challenge 0755 (traversable Nginx non-root). */
    public function testPrepareDirsMembuatDirChallenge0755(): void
    {
        $this->assertDirectoryDoesNotExist($this->webroot);

        [, $calls] = $this->issueFails($this->certbotFailure());

        $this->assertCount(1, $calls);
        $challenge = $this->webroot . '/.well-known/acme-challenge';
        $this->assertDirectoryExists($challenge);
        $this->assertSame(0755, fileperms($challenge) & 0777, 'challenge dir wajib traversable non-root');
        $this->assertDirectoryExists($this->webroot . '/.well-known');
    }

    // ---------------------------------------------------------------- hint 403
    public function testHint403MunculUntukInvalidResponse403(): void
    {
        [$msg] = $this->issueFails($this->certbotFailure(
            "Certbot failed to authenticate some domains (authenticator: webroot).\n"
            . 'Detail: Invalid response from http://' . self::DOMAIN . '/.well-known/acme-challenge/AbC:'
            . "\n<html><head><title>403 Forbidden</title></head></html>"
        ));

        $this->assertStringContainsString(self::HINT_PREFIX, $msg);
        $this->assertStringContainsString('tail -n 50 /var/log/nginx/error.log', $msg);
        $this->assertStringContainsString('namei -l ' . $this->webroot . '/.well-known/acme-challenge', $msg);
        $this->assertStringEndsWith(self::HINT_TAIL, $msg, 'hint utuh di ekor pesan');
    }

    /** @return array<string,array{0:string}> keluaran certbot yang TIDAK boleh memicu hint */
    public static function noHintProvider(): array
    {
        return [
            '404' => ["Detail: Invalid response from http://x/.well-known/acme-challenge/a: 404"],
            '403 tanpa Invalid response' => ["Saving debug log …\n<html>403 Forbidden</html>"],
            'DNS problem' => ['DNS problem: NXDOMAIN looking up A for x.example.com — check that a DNS record exists'],
            'timeout' => ['Timeout during connect (likely firewall problem)'],
            'koneksi ditolak' => ['Fetching http://x/.well-known/acme-challenge/a: Connection refused'],
            'keluaran kosong' => [''],
        ];
    }

    /**
     * @dataProvider noHintProvider
     */
    public function testHintTidakMunculUntukKasusLain(string $output): void
    {
        [$msg] = $this->issueFails([
            'code' => 1,
            'stdout' => $output,
            'stderr' => 'Some challenges have failed.',
            'timedOut' => false,
        ]);

        $this->assertStringStartsWith(self::FAIL_PREFIX, $msg, 'jalur kegagalan certbot yang diuji, bukan galat lain');
        $this->assertStringNotContainsString(self::HINT_PREFIX, $msg);
        $this->assertStringNotContainsString('Hint 403', $msg);
    }

    /**
     * Anggaran pesan: badan ≤ MAX_FAILURE_TEXT (2000 byte) dan hint TIDAK boleh
     * terpotong walau keluaran certbot 4 KB (pola 403 diuji pada keluaran utuh).
     */
    public function testHintUtuhDanBadanMaks2000SaatKeluaran4KB(): void
    {
        $long = "Certbot failed to authenticate some domains (authenticator: webroot).\n"
            . 'Detail: Invalid response from http://' . self::DOMAIN . "/.well-known/acme-challenge/AbC: 403\n"
            . str_repeat("noise line from certbot\n", 200); // total ≈ 4 KB

        [$msg] = $this->issueFails(['code' => 1, 'stdout' => $long, 'stderr' => 'Some challenges have failed.']);

        $this->assertStringStartsWith(self::FAIL_PREFIX, $msg);
        $body = substr($msg, strlen(self::FAIL_PREFIX));
        $this->assertLessThanOrEqual(2000, strlen($body), 'badan pesan wajib ≤ 2000 byte');
        $this->assertGreaterThan(1000, strlen($body), 'keluaran certbot harus tetap terbaca (bukan hanya hint)');
        $this->assertStringContainsString(self::MARKER, $body, 'keluaran panjang ditandai terpotong');
        $this->assertStringContainsString(self::HINT_PREFIX, $body);
        $this->assertStringEndsWith(self::HINT_TAIL, $body, 'hint tidak boleh terpotong');
        $this->assertStringContainsString('Invalid response', $body, 'baris penyebab tetap di depan');
    }

    /**
     * Pola 403 diuji pada keluaran **utuh** (sebelum dipotong): walau baris
     * penyebab berada di ekor keluaran 4 KB sehingga IKUT terpotong dari badan
     * pesan, hint tetap dipasang (dan utuh).
     */
    public function testHintTetapMunculWalauPola403DiEkorKeluaran(): void
    {
        $long = str_repeat("noise line from certbot\n", 200)
            . 'Detail: Invalid response from http://' . self::DOMAIN . '/.well-known/acme-challenge/AbC: 403';

        [$msg] = $this->issueFails(['code' => 1, 'stdout' => $long, 'stderr' => 'Some challenges have failed.']);

        $body = substr($msg, strlen(self::FAIL_PREFIX));
        $this->assertLessThanOrEqual(2000, strlen($body));
        $this->assertStringNotContainsString('Invalid response', $body, 'ekor keluaran memang terpotong');
        $this->assertStringEndsWith(self::HINT_TAIL, $body, 'hint dipasang walau pola ada di bagian terpotong');
    }

    public function testTanpaHintBadanDipotongDiBatasLama(): void
    {
        [$msg] = $this->issueFails(['code' => 1, 'stdout' => str_repeat('A', 5000), 'stderr' => '']);

        $body = substr($msg, strlen(self::FAIL_PREFIX));
        $this->assertSame(2000 + strlen(self::MARKER), strlen($body));
        $this->assertStringEndsWith(self::MARKER, $body);
        $this->assertStringNotContainsString('Hint 403', $body);
    }

    /** `revoke()` tetap memakai `failureText()` polos — tanpa hint 403. */
    public function testRevokeTidakMemakaiHint403(): void
    {
        $cert = $this->tmp . '/letsencrypt/live/' . self::DOMAIN;
        mkdir($cert, 0755, true);
        file_put_contents($cert . '/fullchain.pem', "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----\n");
        file_put_contents($cert . '/privkey.pem', "-----BEGIN PRIVATE KEY-----\nY\n-----END PRIVATE KEY-----\n");

        $runner = new SslIssuerPreflightRunner([
            'code' => 1,
            'stdout' => "Detail: Invalid response from http://x/.well-known/acme-challenge/a: 403",
            'stderr' => 'Some challenges have failed.',
        ]);

        try {
            (new SslIssuer($runner))->revoke(self::DOMAIN);
            $this->fail('revoke() harus melempar RuntimeException saat certbot exit != 0');
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
        }

        $this->assertStringContainsString('Gagal revoke sertifikat untuk ' . self::DOMAIN . ': ', $msg);
        $this->assertStringContainsString('Invalid response', $msg);
        $this->assertStringNotContainsString('Hint 403', $msg, 'revoke() tidak memakai hint tindakan');
        $this->assertCount(1, $runner->calls);
        $this->assertStringContainsString('--work-dir ' . $this->work . ' --logs-dir ' . $this->logs, $runner->calls[0]);
        $this->assertFileExists($cert . '/fullchain.pem', 'gagal revoke tidak menghapus cert');
    }

    // ---------------------------------------------------------------- helper
    /** @return array{0:string,1:array<int,string>} [pesan galat, argv certbot terekam] */
    private function issueFails(array $result): array
    {
        $runner = new SslIssuerPreflightRunner($result);
        try {
            (new SslIssuer($runner))->issue(self::DOMAIN);
        } catch (RuntimeException $e) {
            return [$e->getMessage(), $runner->calls];
        }

        $this->fail('issue() harus melempar RuntimeException (runner palsu selalu exit != 0)');
    }

    /** @return array{code:int,stdout:string,stderr:string,timedOut:bool} */
    private function certbotFailure(string $stdout = 'Detail: Invalid response …'): array
    {
        return [
            'code' => 1,
            'stdout' => $stdout,
            'stderr' => 'Saving debug log to ' . $this->logs . "/letsencrypt.log\nSome challenges have failed.",
            'timedOut' => false,
        ];
    }

    private function issuer(): SslIssuer
    {
        return new SslIssuer(new SslIssuerPreflightRunner(['code' => 0, 'stdout' => '', 'stderr' => '']));
    }

    private function privateString(SslIssuer $issuer, string $property): string
    {
        $prop = new ReflectionProperty(SslIssuer::class, $property);
        $prop->setAccessible(true);

        return (string) $prop->getValue($issuer);
    }

    private static function callDirSetting(string $value, string $default): string
    {
        $method = new ReflectionMethod(SslIssuer::class, 'dirSetting');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $value, $default);
    }

    /** @param array<string,mixed> $overrides */
    private function loadDeploy(array $overrides = []): void
    {
        $this->writeDeployConfig(array_merge([
            'letsencrypt_path' => $this->tmp . '/letsencrypt',
            'ssl_webroot' => $this->webroot,
            'ssl_challenge' => 'http',
            'ssl_ca_server' => 'production',
            'admin_email' => 'ops@example.com',
            'certbot_work_dir' => $this->work,
            'certbot_logs_dir' => $this->logs,
        ], $overrides));
    }

    /** Konfigurasi gaya "instalasi lama": kunci certbot_* memang tidak ada. */
    private function loadDeployBare(): void
    {
        $this->writeDeployConfig([
            'letsencrypt_path' => $this->tmp . '/letsencrypt',
            'ssl_webroot' => $this->webroot,
            'ssl_challenge' => 'http',
            'ssl_ca_server' => 'production',
            'admin_email' => 'ops@example.com',
        ]);
    }

    /** @param array<string,mixed> $deploy */
    private function writeDeployConfig(array $deploy): void
    {
        Config::clear();
        // `Config::load()` hanya memuat berkas di direktori yang juga punya app.php.
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export($deploy, true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
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
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}

/** ProcessRunner palsu: hasil tetap + merekam argv (tanpa certbot/proses nyata). */
final class SslIssuerPreflightRunner extends ProcessRunner
{
    /** @var array<int,string> */
    public array $calls = [];

    /** @param array{code:int,stdout:string,stderr:string,timedOut?:bool} $result */
    public function __construct(private array $result)
    {
    }

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->calls[] = implode(' ', $command);

        return [
            'code' => (int) $this->result['code'],
            'stdout' => (string) $this->result['stdout'],
            'stderr' => (string) $this->result['stderr'],
            'timedOut' => (bool) ($this->result['timedOut'] ?? false),
        ];
    }
}
