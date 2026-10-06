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
    /** @var array<int,array{container:string,command:string,timeout:int}> */
    public array $calls = [];

    /** @var array<int,array{container:string,command:string,input:string,timeout:int}> */
    public array $inputCalls = [];

    public function __construct(private int $code = 0, private bool $throw = false)
    {
        // sengaja tidak memanggil parent (tanpa binary/runtime nyata).
    }

    /**
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function runCommand(string $container, string $command, int $timeout = 0): array
    {
        $this->calls[] = ['container' => $container, 'command' => $command, 'timeout' => $timeout];
        if ($this->throw) {
            throw new RuntimeException('fake docker exec gagal (test)');
        }

        return ['code' => $this->code, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }

    /**
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function runCommandWithInput(string $container, string $command, string $input, int $timeout = 0): array
    {
        $this->inputCalls[] = ['container' => $container, 'command' => $command, 'input' => $input, 'timeout' => $timeout];
        if ($this->throw) {
            throw new RuntimeException('fake docker exec gagal (test)');
        }

        return ['code' => $this->code, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }
}
