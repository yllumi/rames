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
// (pola sama seperti NginxReloaderArgvTest/SslControllerServerTest).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Regresi Verifier: pesan galat certbot pada `SslIssuer::issue()`/`revoke()`
 * WAJIB memuat **kedua** stream.
 *
 * Sebelum perbaikan, keduanya memakai
 * `$result['stderr'] !== '' ? $result['stderr'] : $result['stdout']` sehingga
 * blok diagnosa certbot ("Certbot failed to authenticate some domains … Detail:
 * … 404 / Timeout during connect / DNS problem") — yang ditulis ke **stdout** —
 * dibuang saat stderr juga terisi. Yang sampai ke UI (`{slot}_error` di
 * `apps.json`) & log job hanya "Saving debug log …/Some challenges have
 * failed.", sehingga sebab kegagalan tidak bisa dibedakan.
 *
 * Catatan cakupan: `issue()` tidak bisa dijalankan di unit test karena
 * `prepareDirs()` mem-`mkdir` jalur keras `/var/lib/letsencrypt` &
 * `/var/log/letsencrypt` (hanya ada di container dashboard). Karena itu jalur
 * end-to-end diuji lewat `revoke()` — pemakai `failureText()` yang sama —
 * dengan `deploy.letsencrypt_path`/`ssl_webroot` diarahkan ke direktori TEMP
 * dan `ProcessRunner` palsu; tidak ada certbot/Docker dan tidak ada berkas
 * runtime nyata yang tersentuh.
 */
class SslIssuerFailureTextTest extends TestCase
{
    private const MARKER = "\n…(keluaran certbot dipotong)";
    private const DOMAIN = 'ssl-probe.example.com';

    private string $tmp;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-sslissuer-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/config', 0777, true);
        mkdir($this->tmp . '/webroot', 0777, true);

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        // `Config::load()` hanya memuat berkas di direktori yang juga punya app.php.
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'letsencrypt_path' => $this->tmp . '/letsencrypt',
            'ssl_webroot' => $this->tmp . '/webroot',
            'ssl_challenge' => 'http',
            'ssl_ca_server' => 'production',
            'admin_email' => 'ops@example.com',
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
    }

    protected function tearDown(): void
    {
        Config::clear();
        $this->restoreConfigState($this->configState);
        $this->removeRecursive($this->tmp);
    }

    /**
     * Galat revoke nyata: pesan harus memuat blok stdout (Detail challenge) DAN
     * ringkasan stderr, dengan prefix pesan lama tetap utuh.
     */
    public function testPesanGalatRevokeMemuatStdoutDanStderr(): void
    {
        $this->seedCert();
        $runner = new SslIssuerRecordingRunner([
            'code' => 1,
            'stdout' => "Certbot failed to authenticate some domains (authenticator: webroot).\n"
                . 'Detail: Invalid response from http://' . self::DOMAIN . '/.well-known/acme-challenge/x: 404',
            'stderr' => "Saving debug log to /var/log/letsencrypt/letsencrypt.log\nSome challenges have failed.",
            'timedOut' => false,
        ]);

        try {
            (new SslIssuer($runner))->revoke(self::DOMAIN);
            $this->fail('revoke() harus melempar RuntimeException saat certbot exit != 0');
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
        }

        $this->assertStringStartsWith('Gagal revoke sertifikat untuk ' . self::DOMAIN . ': ', $msg);
        $this->assertStringContainsString('Detail: Invalid response', $msg, 'sebab asli dari stdout WAJIB ikut');
        $this->assertStringContainsString('Some challenges have failed.', $msg);
        $this->assertLessThan(
            strpos($msg, 'Some challenges have failed.'),
            strpos($msg, 'Detail: Invalid response'),
            'stdout didahulukan agar baris Detail terbaca di depan'
        );
        $this->assertCount(1, $runner->calls);
        $this->assertSame(
            'certbot revoke --non-interactive --cert-name ' . self::DOMAIN
                . ' --config-dir ' . $this->tmp . '/letsencrypt'
                . ' --work-dir /var/lib/letsencrypt --logs-dir /var/log/letsencrypt',
            $runner->calls[0]
        );
        $this->assertFileExists($this->tmp . '/letsencrypt/live/' . self::DOMAIN . '/fullchain.pem', 'gagal revoke tidak boleh menghapus cert');
    }

    public function testFailureTextMenggabungkanKeduaStream(): void
    {
        $text = $this->failureText(['code' => 1, 'stdout' => "OUT-1\nOUT-2", 'stderr' => 'ERR-1']);

        $this->assertSame("OUT-1\nOUT-2\nERR-1", $text);
    }

    public function testFailureTextMemakaiStreamYangAdaSaja(): void
    {
        $this->assertSame('ERR-1', $this->failureText(['code' => 1, 'stdout' => '', 'stderr' => 'ERR-1']));
        $this->assertSame('OUT-1', $this->failureText(['code' => 1, 'stdout' => 'OUT-1', 'stderr' => '   ']));
    }

    public function testFailureTextTidakMenduplikasiStreamIdentik(): void
    {
        $same = 'Some challenges have failed.';

        $this->assertSame($same, $this->failureText(['code' => 1, 'stdout' => $same, 'stderr' => $same]));
        // identik setelah trim (spasi/newline ekstra dari proc_open) juga tidak digandakan
        $this->assertSame($same, $this->failureText(['code' => 1, 'stdout' => $same, 'stderr' => "  {$same}\n"]));
    }

    public function testFailureTextFallbackBilaKeduaStreamKosong(): void
    {
        foreach ([
            ['code' => 1, 'stdout' => '', 'stderr' => ''],
            ['code' => 1, 'stdout' => " \n\t", 'stderr' => "\n"],
            ['code' => 1], // defensif: kunci stream tidak ada
        ] as $result) {
            $text = $this->failureText($result);
            $this->assertNotSame('', $text, 'pesan galat tidak boleh kosong');
            $this->assertStringContainsString('certbot keluar tanpa keluaran', $text);
        }
    }

    public function testFailureTextMemotongKeluaranPanjang(): void
    {
        $long = str_repeat('A', 1500) . "\nDetail: " . str_repeat('B', 1900);
        $text = $this->failureText(['code' => 1, 'stdout' => $long, 'stderr' => 'ERR-TAIL']);

        $this->assertStringEndsWith(self::MARKER, $text);
        $this->assertSame(2000, strlen($text) - strlen(self::MARKER), 'badan pesan dipotong tepat di 2000 char');
        $this->assertStringNotContainsString('ERR-TAIL', $text, 'sisa di luar batas memang dibuang');
        $this->assertStringContainsString('Detail: ', $text, 'sebab utama yang dekat awal tetap terbaca');

        // Tepat 2000 char: belum dipotong (batas eksklusif).
        $exact = str_repeat('C', 2000);
        $this->assertSame($exact, $this->failureText(['code' => 1, 'stdout' => $exact, 'stderr' => '']));
        // 2001 char: dipotong.
        $this->assertStringEndsWith(self::MARKER, $this->failureText(['code' => 1, 'stdout' => str_repeat('C', 2001), 'stderr' => '']));
    }

    /** Jalur sukses (exit 0) tidak berubah: tidak melempar, artefak dibersihkan. */
    public function testRevokeSuksesTetapMembersihkanArtefak(): void
    {
        $this->seedCert();
        mkdir($this->tmp . '/letsencrypt/archive/' . self::DOMAIN, 0777, true);
        file_put_contents($this->tmp . '/letsencrypt/archive/' . self::DOMAIN . '/cert1.pem', 'x');
        mkdir($this->tmp . '/letsencrypt/renewal', 0777, true);
        file_put_contents($this->tmp . '/letsencrypt/renewal/' . self::DOMAIN . '.conf', 'x');

        $runner = new SslIssuerRecordingRunner(['code' => 0, 'stdout' => 'revoke ok', 'stderr' => '', 'timedOut' => false]);
        (new SslIssuer($runner))->revoke(self::DOMAIN);

        $this->assertCount(1, $runner->calls);
        $this->assertFileDoesNotExist($this->tmp . '/letsencrypt/live/' . self::DOMAIN);
        $this->assertFileDoesNotExist($this->tmp . '/letsencrypt/archive/' . self::DOMAIN);
        $this->assertFileDoesNotExist($this->tmp . '/letsencrypt/renewal/' . self::DOMAIN . '.conf');
    }

    /** `certExists()` tetap no-op gate: tanpa cert, certbot tidak dijalankan. */
    public function testRevokeNoOpBilaCertTidakAda(): void
    {
        $runner = new SslIssuerRecordingRunner(['code' => 1, 'stdout' => 'X', 'stderr' => '', 'timedOut' => false]);

        (new SslIssuer($runner))->revoke(self::DOMAIN);

        $this->assertSame([], $runner->calls);
    }

    /** @param array{code:int,stdout:string,stderr:string,timedOut?:bool} $result */
    private function failureText(array $result): string
    {
        $method = new ReflectionMethod(SslIssuer::class, 'failureText');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $result);
    }

    private function seedCert(): void
    {
        $dir = $this->tmp . '/letsencrypt/live/' . self::DOMAIN;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/fullchain.pem', "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----\n");
        file_put_contents($dir . '/privkey.pem', "-----BEGIN PRIVATE KEY-----\nY\n-----END PRIVATE KEY-----\n");
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

/** ProcessRunner palsu: mengembalikan hasil tetap & merekam argv (tanpa proses nyata). */
final class SslIssuerRecordingRunner extends ProcessRunner
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
            'code' => $this->result['code'],
            'stdout' => $this->result['stdout'],
            'stderr' => $this->result['stderr'],
            'timedOut' => $this->result['timedOut'] ?? false,
        ];
    }
}
