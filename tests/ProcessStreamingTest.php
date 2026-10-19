<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerComposeRunner;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Output live `docker compose` (log build) — `ProcessRunner::runStreaming()` dan
 * jalur `DockerComposeRunner::up(..., $onOutput)`.
 *
 * Tanpa Docker nyata: proses yang dijalankan hanya `php -r`, sedangkan compose
 * memakai ProcessRunner tiruan.
 */
class ProcessStreamingTest extends TestCase
{
    public function testRunStreamingForwardsStdoutAndStderrChunks(): void
    {
        $chunks = [];
        $result = (new ProcessRunner())->runStreaming(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "out-line\n"); fwrite(STDERR, "err-line\n");'],
            null,
            10,
            [],
            null,
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            }
        );

        $this->assertSame(0, $result['code']);
        $this->assertSame("out-line\n", $result['stdout']);
        $this->assertSame("err-line\n", $result['stderr']);
        $joined = implode('', $chunks);
        $this->assertStringContainsString('out-line', $joined);
        $this->assertStringContainsString('err-line', $joined);
    }

    public function testSinkFailureDoesNotBreakTheChildProcess(): void
    {
        $result = (new ProcessRunner())->runStreaming(
            [PHP_BINARY, '-r', 'echo "ok";'],
            null,
            10,
            [],
            null,
            static function (): void {
                throw new RuntimeException('sink mati');
            }
        );

        $this->assertSame(0, $result['code']);
        $this->assertSame('ok', $result['stdout']);
    }

    public function testComposeUpStreamsBuildOutputWhenSinkGiven(): void
    {
        $runner = new StreamingFakeRunner();
        $compose = new DockerComposeRunner($runner, 'docker', 10);
        $chunks = [];

        $compose->up('demo', sys_get_temp_dir(), ['docker-compose.yml'], true, null, static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        $this->assertSame(['Step 1/2', 'Step 2/2'], $chunks);
        $this->assertStringContainsString('up', $runner->lastCommand);
        $this->assertStringContainsString('--build', $runner->lastCommand);
        $this->assertSame(sys_get_temp_dir(), $runner->lastEnv['PWD'] ?? null);
    }

    public function testComposeUpWithoutSinkKeepsBufferedRunner(): void
    {
        $runner = new StreamingFakeRunner();
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $compose->up('demo', sys_get_temp_dir(), ['docker-compose.yml'], false);

        $this->assertFalse($runner->streamed);
        $this->assertStringNotContainsString('--build', $runner->lastCommand);
    }
}

/**
 * ProcessRunner tiruan: `runStreaming()` mengirim dua potongan, `run()` tidak.
 */
final class StreamingFakeRunner extends ProcessRunner
{
    public bool $streamed = false;

    public string $lastCommand = '';

    /** @var array<string,string> */
    public array $lastEnv = [];

    public function __construct()
    {
        // Tidak ada state induk yang dipakai.
    }

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->lastCommand = implode(' ', $command);
        $this->lastEnv = $env;

        return ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];
    }

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function runStreaming(
        array $command,
        ?string $cwd,
        int $timeout,
        array $env,
        ?string $stdin,
        callable $onChunk
    ): array {
        $this->streamed = true;
        $this->lastCommand = implode(' ', $command);
        $this->lastEnv = $env;
        $onChunk('Step 1/2');
        $onChunk('Step 2/2');

        return ['code' => 0, 'stdout' => 'Step 1/2Step 2/2', 'stderr' => '', 'timedOut' => false];
    }
}
