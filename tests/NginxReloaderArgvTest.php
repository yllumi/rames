<?php
declare(strict_types=1);

namespace Tests;

use app\library\Nginx\NginxReloader;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Webman\Config;

// Konstruktor NginxReloader mengevaluasi `base_path()` untuk default path file
// status; helper webman itu membaca konstanta BASE_PATH yang tidak ada di luar
// runtime webman. Sama seperti NginxConfigGeneratorTest, konstanta didefinisikan
// di sini (tanpa menyentuh data runtime).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Unit test argv helper container NginxReloader — TANPA Docker/nginx nyata.
 *
 * Mengunci temuan Verifier #2: argv `docker run` helper WAJIB memuat
 * `--network host`. Tanpa flag itu, helper yang me-chroot ke root host tetap
 * berjalan di netns container → membaca `/etc/resolv.conf` host
 * (`nameserver 127.0.0.1`) tetapi loopback container milik dirinya sendiri,
 * sehingga resolusi DNS gagal dan `nginx -t` menolak vhost sah dengan
 * `host not found in upstream` (false negative → rollback perubahan yang sah).
 *
 * Pengujian memakai refleksi ke `runInHelper()` (private) dengan ProcessRunner
 * palsu yang merekam argv mentah, plus config temp diarahkan lewat `Config::load`
 * (state global Config disnapshot & dipulihkan) sehingga:
 *  - tidak ada proses `docker run` / `nginx` yang dijalankan;
 *  - `reload()`/`writeStatus()` tidak pernah dipanggil → tidak ada file runtime
 *    (`nginx-status/last-reload.json`) yang tersentuh.
 */
class NginxReloaderArgvTest extends TestCase
{
    private const IMAGE = 'rames-nginx-reload-test:9.9.9';
    private const NGINX_BIN = '/usr/sbin/nginx';
    private const HTTP_CONF = '/etc/nginx/nginx.conf';
    private const SH_SCRIPT = 'chroot /host "$@"';

    private string $tmp;

    /**
     * Snapshot state statis Webman\Config agar pemuatan config temp di setUp
     * tidak bocor ke test lain yang berjalan di proses PHPUnit yang sama.
     *
     * @var array<string,mixed>
     */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-nginx-reload-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);

        $this->configState = $this->snapshotConfigState();

        // loadFromDir() hanya memuat berkas di direktori yang juga punya app.php.
        file_put_contents($this->tmp . '/app.php', "<?php\n\nreturn [];\n");
        $deploy = [
            'nginx_reload_image' => self::IMAGE,
            'nginx_bin' => self::NGINX_BIN,
            'nginx_http_conf' => self::HTTP_CONF,
            // Path file status NON-runtime: jaring pengaman bila ada jalur yang
            // tak sengaja memanggil writeStatus() — runtime tidak boleh tersentuh.
            'nginx_reload_status_file' => $this->tmp . '/last-reload.json',
        ];
        file_put_contents(
            $this->tmp . '/deploy.php',
            "<?php\n\nreturn " . var_export($deploy, true) . ";\n"
        );

        Config::load($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);

        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    // ==================================================================
    // Kasus 1 & 2 — tahap TEST (rw)
    // ==================================================================

    public function testTestPhaseArgvLocksNetworkHostPidHostAndPrivileged(): void
    {
        $argv = $this->captureHelperArgv('rw', ['-t', '-c', self::HTTP_CONF]);

        $this->assertSame(['docker', 'run', '--rm'], array_slice($argv, 0, 3));

        // Pasangan flag wajib berurutan pada argv test.
        $this->assertFlagFollowedBy($argv, '--network', 'host');
        $this->assertFlagFollowedBy($argv, '--pid', 'host');
        $this->assertContains('--privileged', $argv);

        // --network host harus mendahului --pid host dan --privileged.
        $network = array_search('--network', $argv);
        $pid = array_search('--pid', $argv);
        $privileged = array_search('--privileged', $argv);
        $this->assertNotFalse($network);
        $this->assertNotFalse($pid);
        $this->assertNotFalse($privileged);
        $this->assertLessThan($pid, $network, '--network host harus sebelum --pid host');
        $this->assertLessThan($privileged, $pid, '--pid host harus sebelum --privileged');

        // Kunci bentuk argv penuh (posisi & urutan seluruh elemen).
        $this->assertSame([
            'docker', 'run', '--rm',
            '--network', 'host',
            '--pid', 'host',
            '--privileged',
            '-v', '/:/host:rw',
            '--tmpfs', '/host/run',
            self::IMAGE,
            'sh', '-c', self::SH_SCRIPT, 'sh',
            self::NGINX_BIN,
            '-t', '-c', self::HTTP_CONF,
        ], $argv);
    }

    public function testTestPhaseMountsHostRootReadWriteAndTmpfsRun(): void
    {
        $argv = $this->captureHelperArgv('rw', ['-t', '-c', self::HTTP_CONF]);

        // nginx -t butuh menulis log & pid: root host rw + tmpfs melindungi pid asli.
        $this->assertFlagFollowedBy($argv, '-v', '/:/host:rw');
        $this->assertFlagFollowedBy($argv, '--tmpfs', '/host/run');
        $this->assertNotContains('/:/host:ro', $argv);
    }

    // ==================================================================
    // Kasus 3 — tahap RELOAD (ro), pengunci regresi utama
    // ==================================================================

    public function testReloadPhaseArgvLocksNetworkHostAndReadOnlyMount(): void
    {
        $argv = $this->captureHelperArgv('ro', ['-s', 'reload', '-c', self::HTTP_CONF]);

        // REGRESI UTAMA: tanpa --network host, resolusi DNS di helper gagal.
        $this->assertFlagFollowedBy($argv, '--network', 'host');
        $this->assertFlagFollowedBy($argv, '--pid', 'host');
        $this->assertContains('--privileged', $argv);

        // Reload hanya membaca pid lalu SIGHUP → mount ro, tanpa tmpfs rw.
        $this->assertFlagFollowedBy($argv, '-v', '/:/host:ro');
        $this->assertNotContains('/:/host:rw', $argv);
        $this->assertNotContains('--tmpfs', $argv);

        $this->assertSame([
            'docker', 'run', '--rm',
            '--network', 'host',
            '--pid', 'host',
            '--privileged',
            '-v', '/:/host:ro',
            self::IMAGE,
            'sh', '-c', self::SH_SCRIPT, 'sh',
            self::NGINX_BIN,
            '-s', 'reload', '-c', self::HTTP_CONF,
        ], $argv);
    }

    // ==================================================================
    // Kasus 4 — tanpa shell string: tiap argumen satu elemen argv
    // ==================================================================

    public function testArgsAreForwardedAsSeparateTokensWithoutShellString(): void
    {
        $argv = $this->captureHelperArgv('rw', ['-t', '-c', self::HTTP_CONF]);

        // Skrip chroot dikirim sebagai SATU argumen literal ke `sh -c`; nginx
        // menerima argumen via "$@" sehingga tidak ada string command di host.
        $this->assertSame(
            ['sh', '-c', self::SH_SCRIPT, 'sh', self::NGINX_BIN, '-t', '-c', self::HTTP_CONF],
            array_slice($argv, 13),
            'ekor argv (sh -c → chroot → nginx) harus utuh sebagai elemen terpisah'
        );

        // Argumen nginx adalah elemen tersendiri, bukan satu string gabungan.
        $this->assertContains('-t', $argv);
        $this->assertContains(self::HTTP_CONF, $argv);
        $this->assertNotContains('-t -c ' . self::HTTP_CONF, $argv);

        // Karakter shell pada argumen tetap utuh sebagai satu elemen (tanpa split/escaping host).
        $nasty = '/etc/nginx/conf.d/a b;rm -rf /.conf';
        $argv2 = $this->captureHelperArgv('rw', ['-t', '-c', $nasty]);
        $this->assertContains($nasty, $argv2);
        $this->assertSame($nasty, end($argv2), 'argumen berkarakter shell harus jadi elemen terakhir utuh');
    }

    // ==================================================================
    // Kasus 5 — image dari config, posisi sebelum `sh -c ...`
    // ==================================================================

    public function testImageComesFromConfigAndSitsBeforeShellCommand(): void
    {
        $runner = new RecordingArgvRunner();
        $reloader = new NginxReloader($runner);

        $imageProp = new ReflectionProperty(NginxReloader::class, 'image');
        $imageProp->setAccessible(true);
        $this->assertSame(self::IMAGE, $imageProp->getValue($reloader), 'image harus dibaca dari config deploy.nginx_reload_image');

        $argv = $this->captureHelperArgv('rw', ['-t', '-c', self::HTTP_CONF]);

        $imageIndex = array_search(self::IMAGE, $argv);
        $this->assertNotFalse($imageIndex, 'image dari config harus hadir di argv');

        $shIndex = array_search('sh', $argv);
        $this->assertNotFalse($shIndex);
        $this->assertLessThan($shIndex, $imageIndex, 'image harus sebelum `sh -c ...`');

        // Pola: {image} sh -c 'chroot /host "$@"' sh {nginx_bin} ...
        $this->assertSame(
            ['sh', '-c', self::SH_SCRIPT],
            array_slice($argv, (int) $imageIndex + 1, 3)
        );
        $this->assertSame(self::NGINX_BIN, $argv[(int) $imageIndex + 5], 'binary nginx host harus diteruskan setelah `sh`');
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Jalankan runInHelper() (private) lewat refleksi dengan runner palsu dan
     * kembalikan argv mentah yang terekam — tanpa mengeksekusi proses apa pun.
     *
     * @param array<int,string> $nginxArgs
     * @return array<int,string>
     */
    private function captureHelperArgv(string $mode, array $nginxArgs): array
    {
        $runner = new RecordingArgvRunner();
        $reloader = new NginxReloader($runner);

        $method = new ReflectionMethod(NginxReloader::class, 'runInHelper');
        $method->setAccessible(true);
        $method->invoke($reloader, $mode, $nginxArgs);

        $this->assertCount(1, $runner->commands, 'helper harus membentuk tepat satu command');

        return $runner->commands[0];
    }

    /**
     * @param array<int,string> $argv
     */
    private function assertFlagFollowedBy(array $argv, string $flag, string $value): void
    {
        $index = array_search($flag, $argv);
        $this->assertNotFalse($index, "flag {$flag} tidak ditemukan di argv");
        $this->assertArrayHasKey((int) $index + 1, $argv, "flag {$flag} tidak punya nilai");
        $this->assertSame($value, $argv[(int) $index + 1], "{$flag} harus diikuti {$value}");
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }
}

/**
 * ProcessRunner palsu: merekam argv PERSIS (bukan baris gabungan) supaya batas
 * antar-argumen bisa diuji, dan tidak pernah menjalankan proses nyata.
 */
class RecordingArgvRunner extends ProcessRunner
{
    /**
     * @var array<int,array<int,string>>
     */
    public array $commands = [];

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->commands[] = array_values($command);

        return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }
}
