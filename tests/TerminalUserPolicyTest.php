<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerExec;
use app\library\Support\SigchldGuard;
use PHPUnit\Framework\TestCase;
use support\Request;
use Webman\Http\Response;

/**
 * Kebijakan user eksekusi terminal (regresi temuan UI).
 *
 * `DockerExec` harus MELAPORKAN user efektif (`docker exec -u`) supaya UI/audit
 * tidak menebak dari permintaan klien; sedangkan default `root` adalah kebijakan
 * CONTROLLER (ability `terminal` = operator+ yang toh bisa membuka shell root),
 * bukan di layer `DockerExec` (di sana `''` tetap berarti "user default image").
 *
 * Jalur "Run" (one-shot) memakai aturan user yang sama dengan sesi interaktif —
 * dulu "Run" ikut user image sehingga operator mendapat `permission denied` atas
 * berkas milik root padahal sesi terminalnya root.
 *
 * Tanpa Docker/HTTP/apps.json: `DockerExec` palsu + subclass controller.
 */
class TerminalUserPolicyTest extends TestCase
{
    /** @var array<int,string> direktori sesi sementara yang wajib dibersihkan */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sessionDir) {
                foreach (glob($sessionDir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($sessionDir);
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];

        // `DockerExec::open()` sengaja meng-ignore SIGCHLD (proses detached tidak
        // boleh jadi zombie). Test TIDAK boleh mewariskan disposisi itu ke test
        // lain: `proc_close()`/`proc_get_status()` akan selalu melihat -1.
        SigchldGuard::disableIgnore();
    }

    /**
     * @param array<string,string> $fields
     */
    private function post(array $fields, string $path = '/api/apps/a1/terminal/run'): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Response $response): array
    {
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------
    // Jalur "Run" (one-shot)
    // ------------------------------------------------------------------

    public function testRunDefaultsToRootWhenClientOmitsUser(): void
    {
        $exec = new FakeRecordingDockerExec();
        $controller = new FakeTerminalController($exec);

        // Klien lama (view terpasang) tidak mengirim field `user` sama sekali.
        $response = $controller->run($this->post(['container' => 'demo-web-1', 'command' => 'id']), 'a1');

        $this->assertSame(0, $this->payload($response)['code']);
        $this->assertCount(1, $exec->calls);
        $this->assertSame('root', $exec->calls[0]['user'], '"Run" wajib default root (sama dengan sesi interaktif).');
        $this->assertStringContainsString('(user root)', $controller->audits[0]['action']);
    }

    public function testRunHonorsExplicitUser(): void
    {
        $exec = new FakeRecordingDockerExec();
        $controller = new FakeTerminalController($exec);

        $controller->run($this->post(['container' => 'demo-web-1', 'command' => 'id', 'user' => 'www-data']), 'a1');

        $this->assertSame('www-data', $exec->calls[0]['user']);
        $this->assertStringContainsString('(user www-data)', $controller->audits[0]['action']);
    }

    public function testRunEmptyUserFallsBackToRoot(): void
    {
        $exec = new FakeRecordingDockerExec();
        $controller = new FakeTerminalController($exec);

        // Field `user` ada tapi kosong (form selalu mengirim entri kosong).
        $controller->run($this->post(['container' => 'demo-web-1', 'command' => 'id', 'user' => '']), 'a1');

        $this->assertSame('root', $exec->calls[0]['user']);
    }

    // ------------------------------------------------------------------
    // Jalur sesi interaktif (open)
    // ------------------------------------------------------------------

    public function testOpenDefaultsToRootAndReportsEffectiveUser(): void
    {
        $exec = new FakeRecordingDockerExec();
        $controller = new FakeTerminalController($exec);

        $response = $controller->open($this->post(['container' => 'demo-web-1', 'shell' => 'bash'], '/api/apps/a1/terminal/open'), 'a1');
        $data = $this->payload($response)['data'];

        $this->assertSame('root', $exec->openCalls[0]['user']);
        $this->assertSame('root', $data['user'], 'Respons open wajib memuat user efektif (bukan tebakan klien).');
        $this->assertStringContainsString('(user root)', $controller->audits[0]['action']);
    }

    public function testOpenHonorsExplicitUserInResponse(): void
    {
        $exec = new FakeRecordingDockerExec();
        $controller = new FakeTerminalController($exec);

        $response = $controller->open(
            $this->post(['container' => 'demo-web-1', 'shell' => 'sh', 'user' => 'www-data'], '/api/apps/a1/terminal/open'),
            'a1'
        );

        $this->assertSame('www-data', $exec->openCalls[0]['user']);
        $this->assertSame('www-data', $this->payload($response)['data']['user']);
    }

    // ------------------------------------------------------------------
    // Kontrak DockerExec::open() (implementasi asli, tanpa daemon Docker)
    // ------------------------------------------------------------------

    public function testDockerExecOpenReturnsEffectiveUserAndStoresItInSessionFile(): void
    {
        $dir = sys_get_temp_dir() . '/rames-term-user-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->tmpDirs[] = $dir;
        // `/bin/true` berdiri sebagai `docker` + `script` tiruan: argv diabaikan,
        // spawn sukses tanpa daemon (shell eksplisit → tidak ada probing).
        $exec = new DockerExec('/bin/true', $dir, '/bin/true');

        $root = $exec->open('a1', 'c1', ['shell' => 'sh', 'user' => 'root']);
        $this->assertSame('root', $root['user']);
        $this->assertSame('root', $this->sessionMeta($dir, $root['token'])['user']);

        // `''` = user default image (tanpa flag -u); layer ini TIDAK mengubahnya
        // menjadi root — pemetaan itu kebijakan controller.
        $image = $exec->open('a1', 'c1', ['shell' => 'sh', 'user' => '']);
        $this->assertSame('', $image['user']);
        $this->assertSame('', $this->sessionMeta($dir, $image['token'])['user']);
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionMeta(string $dir, string $token): array
    {
        $file = $dir . '/' . $token . '/session.json';
        $this->assertFileExists($file);

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
}
