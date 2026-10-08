<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerExec;
use Closure;

/**
 * Fake `DockerExec` yang mengembalikan keluaran sesuai pola perintah.
 *
 * Dipakai untuk menguji kebijakan operasi file manager (exec sebagai root +
 * pemulihan kepemilikan) tanpa Docker/Engine nyata. Sengaja tidak memanggil
 * konstruktor parent (tanpa binary/runtime dir).
 */
class FakeScriptedDockerExec extends DockerExec
{
    /** @var array<int,array{container:string,command:string,timeout:int,user:string}> */
    public array $calls = [];

    /** @var array<int,array{container:string,command:string,input:string,timeout:int,user:string}> */
    public array $inputCalls = [];

    private Closure $responder;

    /**
     * @param callable(string):array{code?:int,stdout?:string,stderr?:string} $responder
     */
    public function __construct(callable $responder)
    {
        $this->responder = Closure::fromCallable($responder);
    }

    /**
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function runCommand(string $container, string $command, int $timeout = 0, string $user = ''): array
    {
        $this->calls[] = ['container' => $container, 'command' => $command, 'timeout' => $timeout, 'user' => $user];

        return $this->reply($command);
    }

    /**
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function runCommandWithInput(string $container, string $command, string $input, int $timeout = 0, string $user = ''): array
    {
        $this->inputCalls[] = ['container' => $container, 'command' => $command, 'input' => $input, 'timeout' => $timeout, 'user' => $user];

        return $this->reply($command);
    }

    /**
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function reply(string $command): array
    {
        $result = ($this->responder)($command);

        return [
            'code' => (int) ($result['code'] ?? 0),
            'stdout' => (string) ($result['stdout'] ?? ''),
            'stderr' => (string) ($result['stderr'] ?? ''),
            'timedOut' => false,
        ];
    }
}
