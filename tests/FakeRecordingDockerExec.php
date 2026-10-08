<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerExec;
use RuntimeException;

/**
 * Fake `DockerExec` untuk unit test deteksi dump: merekam setiap `runCommand()`
 * dan mengembalikan exit code tetap, tanpa `docker`/Engine nyata.
 *
 * Sengaja tidak memanggil konstruktor parent (tidak butuh binary/runtime dir);
 * seluruh metode parent yang menyentuh proses di-override.
 */
class FakeRecordingDockerExec extends DockerExec
{
    /** @var array<int,array{container:string,command:string,timeout:int,user:string}> */
    public array $calls = [];

    /** @var array<int,array{container:string,command:string,input:string,timeout:int,user:string}> */
    public array $inputCalls = [];

    /** @var array<int,array{appId:string,container:string,shell:string,user:string}> */
    public array $openCalls = [];

    public function __construct(private int $code = 0, private bool $throw = false)
    {
        // sengaja tidak memanggil parent (tanpa binary/runtime nyata).
    }

    /**
     * Tiruan `open()` dengan bentuk return sama seperti implementasi asli
     * (termasuk `user` efektif) — merekam opsi yang diterima controller.
     *
     * @param array{shell?:string, user?:string} $opts
     * @return array{token:string, pid:int, shell:string, user:string, container:string}
     */
    public function open(string $appId, string $container, array $opts = []): array
    {
        $shell = trim((string) ($opts['shell'] ?? ''));
        $user = trim((string) ($opts['user'] ?? ''));
        $this->openCalls[] = ['appId' => $appId, 'container' => $container, 'shell' => $shell, 'user' => $user];

        return [
            'token' => str_repeat('a', 32),
            'pid' => 4242,
            'shell' => $shell === '' ? 'sh' : $shell,
            'user' => $user,
            'container' => $container,
        ];
    }

    /**
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function runCommand(string $container, string $command, int $timeout = 0, string $user = ''): array
    {
        $this->calls[] = ['container' => $container, 'command' => $command, 'timeout' => $timeout, 'user' => $user];
        if ($this->throw) {
            throw new RuntimeException('fake docker exec gagal (test)');
        }

        return ['code' => $this->code, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }

    /**
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function runCommandWithInput(string $container, string $command, string $input, int $timeout = 0, string $user = ''): array
    {
        $this->inputCalls[] = ['container' => $container, 'command' => $command, 'input' => $input, 'timeout' => $timeout, 'user' => $user];
        if ($this->throw) {
            throw new RuntimeException('fake docker exec gagal (test)');
        }

        return ['code' => $this->code, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }
}
