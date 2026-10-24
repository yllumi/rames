<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regresi Verifier untuk installer satu-perintah:
 *  - `install.sh` (bootstrap di root repo: deteksi distro, clone ke --dir,
 *    lalu `exec host/install.sh` dengan argumen diteruskan apa adanya);
 *  - `host/install.sh` (wizard `.env` lewat `--env-only`, validasi fail-fast,
 *    dan urutan langkah instalasi host).
 *
 * Semua pengujian berjalan di SANDBOX temp (`$tmp/host/install.sh` +
 * `$tmp/.env.example`): `.env` yang ditulis test tidak pernah menyentuh repo,
 * `/etc`, Docker, systemd, atau data runtime (`database/`, `apps/`,
 * `runtime/`, `nginx-status/`). `--env-only` memang berhenti sebelum
 * paket/Docker/systemd, dan bootstrap diuji dengan `id` palsu (mencetak 0)
 * sehingga tidak butuh root nyata.
 *
 * Kontrak yang dikunci:
 *  1. `--env-only --non-interactive` menulis nilai flag dengan benar (port
 *     `08123` ⇒ `8123`), SESSION_SECRET 64 hex acak (bukan `change-me`), dan
 *     `.env` 0600 (berisi secret).
 *  2. Idempoten: rerun dengan flag berbeda TIDAK mengubah `.env` (byte-identik).
 *  3. Validasi fail-fast: input tidak sah ⇒ exit 1 dan `.env` TIDAK dibuat
 *     (jangan tinggalkan `.env` setengah jadi yang mengabaikan flag pada rerun).
 *  4. Nilai berkarkter khusus (`?x=1&y=2`, `/+=|&`) tersimpan VERBATIM
 *     (`set_env` meng-escape `& | \` untuk `sed`, bukan mengubah nilainya).
 *  5. Bootstrap memakai ulang checkout Rames yang ada TANPA reset/checkout dan
 *     meneruskan seluruh argumen env ke `host/install.sh`; direktori non-Rames
 *     ditolak; bentuk `curl | bash` (`bash -s --`) tetap bisa `--help`.
 */
class InstallShHostEnvWizardTest extends TestCase
{
    private string $root;

    private string $tmp;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('installer host hanya untuk POSIX (bash)');
        }
        if (self::which('bash') === null) {
            $this->markTestSkipped('butuh bash untuk menguji installer');
        }

        $this->root = dirname(__DIR__);
        $this->tmp = sys_get_temp_dir() . '/rames-installsh-wizard-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            $this->removeRecursive($this->tmp);
        }
    }

    public function testSintaksSkripInstallerValid(): void
    {
        $scripts = [
            $this->root . '/install.sh',
            $this->root . '/host/install.sh',
            $this->root . '/host/create-admin.sh',
            $this->root . '/host/backup.sh',
            $this->root . '/host/certbot-renew.sh',
            $this->root . '/host/nginx-reload-watcher.sh',
        ];

        foreach ($scripts as $script) {
            $this->assertFileExists($script);
            $result = $this->runCommand(['bash', '-n', $script], $this->root);
            $this->assertSame(0, $result['code'], 'bash -n ' . basename($script) . ': ' . $result['stderr']);
            $this->assertSame('', trim($result['stderr']), 'bash -n harus senyap: ' . $script);
        }
    }

    public function testBootstrapExecutableDanHelpMemuatOpsiBaru(): void
    {
        $bootstrap = $this->root . '/install.sh';
        $this->assertTrue(is_executable($bootstrap), 'install.sh wajib executable (mode 0755)');

        $result = $this->runCommand([$bootstrap, '--help'], $this->root);
        $this->assertSame(0, $result['code'], 'bootstrap --help harus exit 0 tanpa root: ' . $result['stderr']);
        foreach (['--dir <path>', '--non-interactive', '--no-deps', '--port-range-start <p>', '--ssl-challenge', '--restic-repository'] as $needle) {
            $this->assertStringContainsString($needle, $result['stdout'], '--help bootstrap wajib menyebut opsi: ' . $needle);
        }

        $result = $this->runCommand([$bootstrap, '--opsi-tidak-dikenal'], $this->root);
        $this->assertSame(1, $result['code'], 'opsi tak dikenal wajib ditolak');

        $result = $this->runCommand([$this->root . '/host/install.sh', '--help'], $this->root);
        $this->assertSame(0, $result['code'], 'host/install.sh --help harus exit 0 tanpa root: ' . $result['stderr']);
        foreach (['--env-only', '--port-range-start <p>', '--port-range-end <p>', '--ssl-challenge', '--billing', '--session-secret'] as $needle) {
            $this->assertStringContainsString($needle, $result['stdout'], '--help host wajib menyebut opsi: ' . $needle);
        }
    }

    public function testEnvOnlyMenulisNilaiFlagSecretAcakDanPermission0600(): void
    {
        $sandbox = $this->sandbox();

        $result = $this->runCommand([
            $sandbox . '/host/install.sh', '--env-only', '--non-interactive',
            '--domain', 'rames.example.id',
            '--email', 'ops@rames.example.id',
            '--port', '08123',
            '--port-range-start', '31000',
            '--port-range-end', '31999',
        ], $sandbox);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);

        $env = (string) file_get_contents($sandbox . '/.env');
        $this->assertSame('rames.example.id', $this->envValue($env, 'APP_DOMAIN'));
        $this->assertSame('ops@rames.example.id', $this->envValue($env, 'ADMIN_EMAIL'));
        $this->assertSame('8123', $this->envValue($env, 'APP_PORT'), 'port berawalan nol dinormalisasi desimal');
        $this->assertSame('31000', $this->envValue($env, 'PORT_RANGE_START'));
        $this->assertSame('31999', $this->envValue($env, 'PORT_RANGE_END'));

        $secret = $this->envValue($env, 'SESSION_SECRET');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret, 'SESSION_SECRET wajib 64 hex acak');
        $this->assertNotSame('change-me', $secret);
        $this->assertStringNotContainsString($secret, $result['stdout'], 'SESSION_SECRET tidak boleh dicetak');

        $this->assertSame(0600, fileperms($sandbox . '/.env') & 0777, '.env berisi secret wajib 0600');
    }

    public function testEnvOnlyIdempotenTidakMengubahEnvSaatRerun(): void
    {
        $sandbox = $this->sandbox();
        $script = $sandbox . '/host/install.sh';

        $first = $this->runCommand([$script, '--env-only', '--non-interactive', '--domain', 'rames.example.id'], $sandbox);
        $this->assertSame(0, $first['code'], $first['stderr']);
        $before = hash_file('sha256', $sandbox . '/.env');
        $this->assertIsString($before);

        $second = $this->runCommand([
            $script, '--env-only', '--non-interactive',
            '--domain', 'lain.example.id', '--email', 'other@example.org', '--port', '9999',
        ], $sandbox);

        $this->assertSame(0, $second['code'], $second['stderr']);
        $this->assertStringContainsString('mempertahankan nilai yang ada', $second['stdout']);
        $this->assertSame($before, hash_file('sha256', $sandbox . '/.env'), '.env lama tidak boleh disentuh');
        $this->assertSame('rames.example.id', $this->envValue((string) file_get_contents($sandbox . '/.env'), 'APP_DOMAIN'));
    }

    public function testEnvOnlyMempertahankanNilaiBerkarakterKhususVerbatim(): void
    {
        $sandbox = $this->sandbox();
        $repo = 's3:https://s3.example.com/bucket?x=1&y=2';
        $keyId = 'AKIA/+=|&xKEY';
        $secretKey = 's+ec/ret=|&value';

        $result = $this->runCommand([
            $sandbox . '/host/install.sh', '--env-only', '--non-interactive',
            '--domain', 'rames.example.id',
            '--restic-repository', $repo,
            '--aws-access-key-id', $keyId,
            '--aws-secret-access-key', $secretKey,
            '--aws-default-region', 'eu-west-1',
        ], $sandbox);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);

        $env = (string) file_get_contents($sandbox . '/.env');
        $this->assertSame($repo, $this->envValue($env, 'RESTIC_REPOSITORY'));
        $this->assertSame($keyId, $this->envValue($env, 'AWS_ACCESS_KEY_ID'));
        $this->assertSame($secretKey, $this->envValue($env, 'AWS_SECRET_ACCESS_KEY'));
        $this->assertSame('eu-west-1', $this->envValue($env, 'AWS_DEFAULT_REGION'));
        $this->assertStringNotContainsString($secretKey, $result['stdout'], 'kredensial tidak boleh dicetak');
    }

    /** @return array<string,array{0:array<int,string>,1:string}> */
    public static function invalidConfigProvider(): array
    {
        $domain = ['--domain', 'rames.example.id'];

        return [
            'port 80 (dipakai Nginx)' => [[...$domain, '--port', '80'], 'tidak boleh'],
            'port 443 (dipakai Nginx)' => [[...$domain, '--port', '443'], 'tidak boleh'],
            'port di dalam range' => [[...$domain, '--port', '30050'], 'tidak boleh berada di dalam'],
            'range terbalik' => [[...$domain, '--port-range-start', '31999', '--port-range-end', '31000'], 'harus lebih kecil'],
            'tanpa --domain' => [['--email', 'ops@rames.example.id'], 'APP_DOMAIN wajib'],
            'email tidak valid' => [[...$domain, '--email', 'bukan-email'], 'tidak valid'],
            'dns-cloudflare tanpa kredensial' => [[...$domain, '--ssl-challenge', 'dns-cloudflare'], 'membutuhkan --cloudflare-creds'],
            'billing bukan boolean' => [[...$domain, '--billing', 'yes'], "'true' atau 'false'"],
            'session-secret change-me' => [[...$domain, '--session-secret', 'change-me'], "tidak boleh 'change-me'"],
        ];
    }

    /**
     * @param array<int,string> $args
     */
    #[DataProvider('invalidConfigProvider')]
    public function testKonfigurasiTidakValidGagalTanpaMembuatEnv(array $args, string $expectedMessage): void
    {
        $sandbox = $this->sandbox();

        $result = $this->runCommand([$sandbox . '/host/install.sh', '--env-only', '--non-interactive', ...$args], $sandbox);

        $this->assertSame(1, $result['code'], 'harus fail-fast | ' . $result['stdout'] . $result['stderr']);
        $this->assertFileDoesNotExist($sandbox . '/.env', 'validasi gagal tidak boleh meninggalkan .env');
        $this->assertStringContainsString('ERROR:', $result['stderr']);
        $this->assertStringContainsString($expectedMessage, $result['stderr']);
    }

    /**
     * `.env` ditulis SETELAH preflight lolos: kegagalan Docker/Nginx tidak boleh
     * meninggalkan `.env` setengah jadi yang pada rerun akan "mempertahankan
     * nilai yang ada" sehingga seluruh flag wizard diabaikan.
     */
    public function testPreflightGagalTidakMeninggalkanEnv(): void
    {
        $sandbox = $this->sandbox();
        mkdir($sandbox . '/bin', 0755, true);
        file_put_contents($sandbox . '/bin/id', "#!/bin/sh\necho 0\n");
        chmod($sandbox . '/bin/id', 0755);
        // `docker info` OK (lewati wait_docker), `docker compose version` gagal ⇒ preflight die.
        file_put_contents($sandbox . '/bin/docker', <<<'STUB'
#!/bin/sh
if [ "$1" = "info" ]; then exit 0; fi
if [ "$1" = "compose" ]; then exit 1; fi
exit 0
STUB);
        chmod($sandbox . '/bin/docker', 0755);
        $env = getenv();
        $env['PATH'] = $sandbox . '/bin:' . (string) getenv('PATH');

        $result = $this->runCommand([
            $sandbox . '/host/install.sh', '--no-deps', '--non-interactive',
            '--domain', 'rames.example.id', '--email', 'ops@rames.example.id',
        ], $sandbox, null, $env);

        $this->assertSame(1, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('docker compose', $result['stderr']);
        $this->assertFileDoesNotExist($sandbox . '/.env', 'preflight gagal tidak boleh menulis .env');
    }

    public function testBootstrapMemakaiUlangCheckoutDanMeneruskanArgumenUtuh(): void
    {
        $this->skipBootstrap();
        $sandbox = $this->tmp . '/boot-' . bin2hex(random_bytes(3));
        $checkout = $sandbox . '/checkout';
        $shim = $sandbox . '/bin';
        mkdir($checkout . '/host', 0755, true);
        mkdir($checkout . '/app', 0755, true);
        mkdir($shim, 0755, true);

        file_put_contents($checkout . '/docker-compose.yml', "services: {}\n");
        file_put_contents($checkout . '/app/functions.php', "<?php\n");
        file_put_contents($checkout . '/untracked-marker.txt', "SENTINEL\n");
        file_put_contents($checkout . '/host/install.sh', <<<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$@" > "$(dirname "${BASH_SOURCE[0]}")/argv.out"
exit 0
STUB);
        chmod($checkout . '/host/install.sh', 0755);

        // `id -u` palsu ⇒ bootstrap menganggap dirinya root (tanpa sudo nyata).
        file_put_contents($shim . '/id', "#!/bin/sh\necho 0\n");
        chmod($shim . '/id', 0755);

        $args = [
            '--no-deps', '--non-interactive',
            '--domain', 'rames.example.id', '--email', 'ops@rames.example.id',
            '--port', '8123', '--port-range-start', '31000', '--port-range-end', '31999',
            '--ssl-challenge', 'http', '--billing', 'false',
        ];

        $result = $this->runBootstrap(['--dir', $checkout, ...$args], $sandbox, $shim);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('memakai ulang TANPA reset/checkout', $result['stdout']);
        $this->assertFileExists($checkout . '/untracked-marker.txt', 'checkout lama tidak boleh direset');
        $this->assertSame($args, $this->lines((string) file_get_contents($checkout . '/host/argv.out')), 'argumen env diteruskan utuh & berurutan');
    }

    public function testBootstrapMenolakDirektoriYangBukanInstalasiRames(): void
    {
        $this->skipBootstrap();
        $sandbox = $this->tmp . '/boot-' . bin2hex(random_bytes(3));
        $dir = $sandbox . '/bukan-rames';
        $shim = $sandbox . '/bin';
        mkdir($dir, 0755, true);
        mkdir($shim, 0755, true);
        file_put_contents($dir . '/catatan.txt', "x\n");
        file_put_contents($shim . '/id', "#!/bin/sh\necho 0\n");
        chmod($shim . '/id', 0755);

        $result = $this->runBootstrap(['--dir', $dir, '--no-deps', '--non-interactive', '--domain', 'rames.example.id'], $sandbox, $shim);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('bukan instalasi Rames', $result['stderr']);
        $this->assertFileExists($dir . '/catatan.txt', 'direktori yang ditolak tidak boleh diubah');
    }

    public function testBootstrapBisaDijalankanLewatPipeCurlKeBash(): void
    {
        $script = (string) file_get_contents($this->root . '/install.sh');

        $result = $this->runCommand(['bash', '-s', '--', '--help'], $this->root, $script);

        $this->assertSame(0, $result['code'], 'bentuk `curl ... | sudo bash -s -- --help` harus tetap jalan: ' . $result['stderr']);
        $this->assertStringContainsString('Rames — Bootstrap installer', $result['stdout']);
    }

    /** Salin `host/install.sh` + `.env.example` ke direktori temp (tanpa menyentuh repo). */
    private function sandbox(): string
    {
        $dir = $this->tmp . '/sandbox-' . bin2hex(random_bytes(4));
        mkdir($dir . '/host', 0755, true);
        copy($this->root . '/host/install.sh', $dir . '/host/install.sh');
        chmod($dir . '/host/install.sh', 0755);
        copy($this->root . '/.env.example', $dir . '/.env.example');

        return $dir;
    }

    private function skipBootstrap(): void
    {
        $osRelease = is_readable('/etc/os-release') ? (string) file_get_contents('/etc/os-release') : '';
        if (!preg_match('/^ID(_LIKE)?=.*(ubuntu|debian)/mi', $osRelease)) {
            $this->markTestSkipped('bootstrap hanya mendukung Ubuntu/Debian (/etc/os-release tidak cocok)');
        }
        if (self::which('git') === null) {
            $this->markTestSkipped('bootstrap membutuhkan git pada PATH');
        }
    }

    /**
     * @param array<int,string> $args
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runBootstrap(array $args, string $cwd, string $shimDir): array
    {
        $env = getenv();
        $env['PATH'] = $shimDir . ':' . (string) getenv('PATH');
        unset($env['RAMES_PROMPT_FD']);

        return $this->runCommand([$this->root . '/install.sh', ...$args], $cwd, null, $env);
    }

    /**
     * @param array<int,string> $command
     * @param array<string,string>|null $env
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd, ?string $stdin = null, ?array $env = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            $this->fail('proc_open gagal: ' . implode(' ', $command));
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => (int) proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function envValue(string $env, string $key): string
    {
        foreach (explode("\n", $env) as $line) {
            if (str_starts_with($line, $key . '=')) {
                return substr($line, strlen($key) + 1);
            }
        }

        $this->fail('kunci ' . $key . ' tidak ditemukan di .env');
    }

    /** @return array<int,string> */
    private function lines(string $content): array
    {
        return $content === '' ? [] : explode("\n", rtrim($content, "\n"));
    }

    private static function which(string $bin): ?string
    {
        $path = (string) getenv('PATH');

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = $dir . DIRECTORY_SEPARATOR . $bin;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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
