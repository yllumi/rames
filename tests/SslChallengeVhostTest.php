<?php
declare(strict_types=1);

namespace Tests;

use app\library\Nginx\NginxReloader;
use app\library\SSL\SslChallengeVhost;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Vhost + reload Nginx SEBELUM penerbitan SSL (HTTP-01).
 *
 * Kontrak yang dikunci:
 *  1. `ensure()` menulis ulang config Nginx LALU reload — urutan ini wajib,
 *     karena nginx menyajikan `/.well-known/acme-challenge/` dari `root` vhost
 *     yang sedang DIMUAT; vhost basi ⇒ `Invalid response … 404`;
 *  2. reload gagal di jalur ini **fatal** (berbeda dari mode `nginx_write` yang
 *     best-effort) — certbot tidak boleh dipanggil dengan blok acme yang salah;
 *  3. app tanpa host port **warn + skip** (bukan fatal): `writeNginxConfig()`
 *     justru menghapus vhost app yang tidak punya target `proxy_pass`, jadi
 *     vhost tidak ditulis ulang dan Nginx tidak di-reload, tetapi penerbitan
 *     tetap boleh berjalan memakai vhost yang sudah dimuat (data container bisa
 *     basi).
 *
 * Test tidak memanggil Docker/nginx/certbot: `NginxReloader` diganti subclass
 * yang meng-override `reload()` (tanpa memanggil konstruktor induk), dan
 * `DeployerInterface` diganti fake yang merekam urutan pemanggilan.
 */
class SslChallengeVhostTest extends TestCase
{
    public function testMenulisVhostLaluReload(): void
    {
        $log = new SslChallengeVhostCallLog();
        $deployer = new SslChallengeVhostFakeDeployer($log);
        $vhost = new SslChallengeVhost(
            new SslChallengeVhostFakeReloader($log, ['ok' => true, 'message' => 'Reload Nginx berhasil.', 'error' => null, 'output' => ''])
        );
        $stages = [];

        $vhost->ensure($this->appWithPort(), $deployer, function (string $stage, string $message) use (&$stages): void {
            $stages[] = [$stage, $message];
        });

        $this->assertSame(
            ['writeNginxConfig', 'reload'],
            $log->entries,
            'vhost wajib ditulis DULU, baru Nginx di-reload'
        );
        $this->assertCount(1, $stages);
        $this->assertSame('nginx', $stages[0][0]);
        $this->assertStringContainsString('Menulis ulang config Nginx sebelum penerbitan', $stages[0][1]);
        $this->assertStringContainsString('blok acme', $stages[0][1]);
    }

    public function testReloadGagalMelemparDanMembatalkanPenerbitan(): void
    {
        $log = new SslChallengeVhostCallLog();
        $deployer = new SslChallengeVhostFakeDeployer($log);
        $vhost = new SslChallengeVhost(new SslChallengeVhostFakeReloader($log, [
            'ok' => false,
            'message' => 'Config Nginx tidak valid.',
            'error' => 'nginx: [emerg] unknown directive "ssl_certificate"',
            'output' => 'nginx: [emerg] unknown directive "ssl_certificate"',
        ]));

        try {
            $vhost->ensure($this->appWithPort(), $deployer, static function (string $stage, string $message): void {
            });
            $this->fail('reload gagal pada jalur ini wajib fatal (certbot belum boleh dipanggil)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Reload Nginx sebelum penerbitan SSL GAGAL', $e->getMessage());
            $this->assertStringContainsString('blok acme tidak akan dimuat', $e->getMessage());
            $this->assertStringContainsString('unknown directive "ssl_certificate"', $e->getMessage(), 'detail reload ikut dilaporkan');
        }

        $this->assertSame(['writeNginxConfig', 'reload'], $log->entries, 'config tetap ditulis lebih dulu');
    }

    public function testReloadGagalTanpaDetailMemakaiPenandaTanpaDetail(): void
    {
        $log = new SslChallengeVhostCallLog();
        $vhost = new SslChallengeVhost(new SslChallengeVhostFakeReloader($log, [
            'ok' => false,
            'message' => 'Reload Nginx gagal.',
            'error' => "   \n",
            'output' => '',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Reload Nginx sebelum penerbitan SSL GAGAL.*tanpa detail$/');

        $vhost->ensure($this->appWithPort(), new SslChallengeVhostFakeDeployer($log), static function (string $stage, string $message): void {
        });
    }

    /**
     * App tanpa host port (mis. app mode compose tanpa `ports:`, atau data
     * container yang basi): vhost TIDAK ditulis ulang dan Nginx TIDAK di-reload,
     * tetapi `ensure()` **tidak melempar** — penerbitan SSL tidak boleh gagal
     * keras hanya karena `AppPorts::hasHostPort()` belum bisa membaca port.
     */
    public function testAppTanpaHostPortWarnDanTidakMenulisVhost(): void
    {
        $log = new SslChallengeVhostCallLog();
        $deployer = new SslChallengeVhostFakeDeployer($log);
        $vhost = new SslChallengeVhost(
            new SslChallengeVhostFakeReloader($log, ['ok' => true, 'message' => '', 'error' => null, 'output' => ''])
        );
        $stages = [];

        $vhost->ensure(
            ['id' => 'app-db', 'name' => 'dbonly', 'containers' => []],
            $deployer,
            function (string $stage, string $message) use (&$stages): void {
                $stages[] = [$stage, $message];
            }
        );

        $this->assertSame([], $log->entries, 'writeNginxConfig() justru menghapus vhost ⇒ tidak boleh dipanggil');
        $this->assertCount(1, $stages, 'tepat satu peringatan, tanpa pesan "menulis ulang"');
        $this->assertSame('nginx', $stages[0][0]);
        $this->assertStringContainsString('vhost TIDAK ditulis ulang', $stages[0][1]);
        $this->assertStringNotContainsString('Menulis ulang config Nginx', $stages[0][1]);
    }

    /** App dengan container yang port host-nya 0 (belum dipublikasikan) juga warn + skip. */
    public function testAppDenganPortHostNolWarnDanTidakMenulisVhost(): void
    {
        $log = new SslChallengeVhostCallLog();
        $stages = [];

        (new SslChallengeVhost(new SslChallengeVhostFakeReloader($log, ['ok' => true, 'message' => '', 'error' => null, 'output' => ''])))
            ->ensure(
                [
                    'id' => 'app-noport',
                    'containers' => [['service_name' => 'web', 'internal_port' => 80, 'host_port' => 0, 'ports' => [['host' => 0, 'container' => 80]]]],
                ],
                new SslChallengeVhostFakeDeployer($log),
                function (string $stage, string $message) use (&$stages): void {
                    $stages[] = [$stage, $message];
                }
            );

        $this->assertSame([], $log->entries, 'port host 0 ⇒ tidak ada target proxy_pass ⇒ vhost tidak ditulis');
        $this->assertCount(1, $stages);
        $this->assertStringContainsString('vhost TIDAK ditulis ulang', $stages[0][1]);
    }

    /** @return array<string,mixed> */
    private function appWithPort(): array
    {
        return [
            'id' => 'app-ssl-1',
            'name' => 'sslapp',
            'primary_service' => 'web',
            'primary_port' => 80,
            'containers' => [[
                'service_name' => 'web',
                'container_name' => 'sslapp-web-1',
                'image' => 'nginx:alpine',
                'internal_port' => 80,
                'host_port' => 18080,
                'status' => 'running',
                'ports' => [['host' => 18080, 'container' => 80]],
            ]],
        ];
    }
}

/** Jejak urutan pemanggilan lintas fake (deployer + reloader). */
final class SslChallengeVhostCallLog
{
    /** @var array<int,string> */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

/**
 * Deployer palsu: hanya merekam pemanggilan vhost (tidak menyentuh Nginx).
 * Mewarisi `FakeCliDeployer` supaya kontrak `DeployerInterface` tidak perlu
 * diulang di test ini.
 */
final class SslChallengeVhostFakeDeployer extends FakeCliDeployer
{
    public function __construct(private SslChallengeVhostCallLog $log)
    {
    }

    public function writeNginxConfig(array $app): void
    {
        $this->log->add('writeNginxConfig');
    }

    public function removeNginxConfig(array $app): void
    {
        $this->log->add('removeNginxConfig');
    }
}

/**
 * Reloader palsu: `reload()` dioverride (bukan final) sehingga tidak ada
 * `docker run`/`nginx` nyata; konstruktor induk sengaja tidak dipanggil
 * (properti config-nya tidak dipakai oleh method yang dioverride).
 */
final class SslChallengeVhostFakeReloader extends NginxReloader
{
    /**
     * @param array{ok:bool,message:string,error:?string,output:string} $result
     */
    public function __construct(private SslChallengeVhostCallLog $log, private array $result)
    {
    }

    /**
     * @return array{ok:bool,message:string,error:?string,output:string}
     */
    public function reload(): array
    {
        $this->log->add('reload');

        return $this->result;
    }
}
