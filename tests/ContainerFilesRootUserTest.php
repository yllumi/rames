<?php
declare(strict_types=1);

namespace Tests;

use app\library\Files\ContainerFiles;
use app\library\Files\FileError;
use Closure;
use PHPUnit\Framework\TestCase;

/**
 * Kebijakan file manager (§7.9): SEMUA operasi `docker exec` dijalankan sebagai
 * root (`-u 0`) supaya berkas milik user lain/root bisa diubah & dihapus, TAPI
 * kepemilikan entri BARU dikembalikan ke user default container agar perilaku
 * lama (dibuat user image) tidak berubah. Tanpa Docker — `DockerExec`/`DockerClient`
 * palsu.
 */
class ContainerFilesRootUserTest extends TestCase
{
    /**
     * @param callable(string):array{code?:int,stdout?:string,stderr?:string} $responder
     * @return array{0:ContainerFiles,1:FakeScriptedDockerExec}
     */
    private function make(callable $responder, string $configUser = 'www-data'): array
    {
        $exec = new FakeScriptedDockerExec($responder);
        $client = new FakeInspectDockerClient($configUser);
        $files = new ContainerFiles($client, $exec, sys_get_temp_dir() . '/rames-xfer-test', 'docker');

        return [$files, $exec];
    }

    /**
     * @param array<int,array{command:string,user:string}> $calls
     * @return array<int,array{command:string,user:string}>
     */
    private static function withPrefix(array $calls, string $needle): array
    {
        return array_values(array_filter($calls, static fn (array $c): bool => str_contains($c['command'], $needle)));
    }

    public function testDeleteRunsAsRoot(): void
    {
        [$files, $exec] = $this->make(static function (string $command): array {
            return str_contains($command, 'if [ -d') ? ['code' => 0, 'stdout' => "file\n"] : ['code' => 0];
        });

        $files->delete('c1', '/root-only.txt');

        $rm = self::withPrefix($exec->calls, 'rm -rf');
        $this->assertCount(1, $rm);
        $this->assertSame('0', $rm[0]['user'], 'operasi hapus wajib sebagai root');
    }

    public function testMkdirRunsAsRootAndRestoresContainerOwner(): void
    {
        [$files, $exec] = $this->make(static fn (string $command): array => ['code' => 0]);

        $files->mkdir('c1', '/shared/newdir');

        $mkdir = self::withPrefix($exec->calls, 'mkdir ');
        $this->assertCount(1, $mkdir);
        $this->assertSame('0', $mkdir[0]['user']);

        // `Config.User` = www-data → chown memakai `www-data:` (grup = login group),
        // tetap dijalankan sebagai root.
        $chown = self::withPrefix($exec->calls, 'chown');
        $this->assertCount(1, $chown);
        $this->assertSame('0', $chown[0]['user']);
        $this->assertStringContainsString("'www-data:'", $chown[0]['command']);
        $this->assertStringContainsString("'/shared/newdir'", $chown[0]['command']);
    }

    public function testWriteNewFileRunsAsRootAndRestoresOwner(): void
    {
        [$files, $exec] = $this->make(static function (string $command): array {
            return str_contains($command, 'if [ -d') ? ['code' => 0, 'stdout' => "missing\n"] : ['code' => 0];
        });

        $files->write('c1', '/shared/new.txt', 'isi');

        $this->assertCount(1, $exec->inputCalls);
        $this->assertSame('0', $exec->inputCalls[0]['user']);
        $this->assertSame('isi', $exec->inputCalls[0]['input']);
        $this->assertCount(1, self::withPrefix($exec->calls, 'chown'));
    }

    public function testWriteExistingFileDoesNotTouchOwner(): void
    {
        [$files, $exec] = $this->make(static function (string $command): array {
            return str_contains($command, 'if [ -d') ? ['code' => 0, 'stdout' => "file\n"] : ['code' => 0];
        });

        $files->write('c1', '/data/existing.txt', 'baru');

        $this->assertSame('0', $exec->inputCalls[0]['user']);
        // Berkas yang sudah ada: pemiliknya TIDAK diubah (bisa saja milik root).
        $this->assertSame([], self::withPrefix($exec->calls, 'chown'));
    }

    public function testRootUserContainerSkipsChown(): void
    {
        [$files, $exec] = $this->make(static fn (string $command): array => ['code' => 0], '');

        $files->mkdir('c1', '/newdir');

        $this->assertSame([], self::withPrefix($exec->calls, 'chown'), 'container user root tidak perlu chown');
    }

    public function testMoveValidatesThenRunsAsRoot(): void
    {
        [$files, $exec] = $this->make(static function (string $command): array {
            if (!str_contains($command, 'if [ -d')) {
                return ['code' => 0];
            }
            if (str_contains($command, "'/dst/src.txt'")) {
                return ['code' => 0, 'stdout' => "missing\n"];
            }
            if (str_contains($command, "'/dst'")) {
                return ['code' => 0, 'stdout' => "dir\n"];
            }

            return ['code' => 0, 'stdout' => "file\n"];
        });

        $moved = $files->move('c1', '/src.txt', '/dst');

        $this->assertSame(['from' => '/src.txt', 'to' => '/dst/src.txt'], $moved);
        $mv = self::withPrefix($exec->calls, 'mv ');
        $this->assertCount(1, $mv);
        $this->assertSame('0', $mv[0]['user']);
        $this->assertStringContainsString("'/src.txt' '/dst/src.txt'", $mv[0]['command']);
    }

    public function testMoveRejectsExistingTargetEntry(): void
    {
        [$files] = $this->make(static function (string $command): array {
            if (str_contains($command, 'if [ -d')) {
                return ['code' => 0, 'stdout' => str_contains($command, "'/dst/src.txt'") ? "file\n" : "dir\n"];
            }

            return ['code' => 0];
        });

        try {
            $files->move('c1', '/src.txt', '/dst');
            $this->fail('bentrok nama di tujuan harus ditolak (409)');
        } catch (FileError $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testMoveRejectsMissingSourceAndNonDirectoryTarget(): void
    {
        [$files] = $this->make(static function (string $command): array {
            if (str_contains($command, 'if [ -d')) {
                return ['code' => 0, 'stdout' => str_contains($command, "'/src.txt'") ? "missing\n" : "dir\n"];
            }

            return ['code' => 0];
        });

        try {
            $files->move('c1', '/src.txt', '/dst');
            $this->fail('sumber hilang harus 404');
        } catch (FileError $e) {
            $this->assertSame(404, $e->status);
        }

        [$files2] = $this->make(static function (string $command): array {
            if (str_contains($command, 'if [ -d')) {
                return ['code' => 0, 'stdout' => str_contains($command, "'/dst'") ? "file\n" : "file\n"];
            }

            return ['code' => 0];
        });

        try {
            $files2->move('c1', '/src.txt', '/dst');
            $this->fail('tujuan bukan direktori harus 400');
        } catch (FileError $e) {
            $this->assertSame(400, $e->status);
        }
    }

    public function testMoveRejectsDescendantTargetBeforeDockerCalls(): void
    {
        [$files, $exec] = $this->make(static fn (string $command): array => ['code' => 0]);

        try {
            $files->move('c1', '/a', '/a/b');
            $this->fail('pindah ke descendant sendiri harus ditolak');
        } catch (FileError $e) {
            $this->assertSame(400, $e->status);
        }
        $this->assertSame([], $exec->calls, 'validasi murni jalan sebelum menyentuh container');
    }
}
