<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerComposeRunner;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Build lewat `docker buildx bake` (SPECS §7.6c).
 *
 * Sejak Compose v5 build selalu memakai bake, dan Compose **tidak** meneruskan
 * `build.entitlements` sebagai `--allow`; buildx 0.38+ menolak request yang
 * meminta entitlement tanpa grant itu (`additional privileges requested: pass
 * "--allow=network.host"`). Karena Compose tidak punya flag `--allow`, build
 * dijalankan lewat `docker compose build --print` → `docker buildx bake --allow`.
 */
class DockerComposeRunnerBuildTest extends TestCase
{
    public function testBuildDijalankanLewatBakeDenganAllow(): void
    {
        $runner = new BakeFakeRunner();
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $compose->up('demo', '/apps/demo', ['docker-compose.yml'], true);

        $this->assertSame(
            [
                'docker compose -p demo --project-directory /apps/demo -f /apps/demo/docker-compose.yml build --print',
                'docker buildx bake -f ' . $runner->bakePath . ' --progress plain --allow network.host',
                'docker compose -p demo --project-directory /apps/demo -f /apps/demo/docker-compose.yml up -d',
            ],
            $runner->commands
        );
    }

    public function testDefinisiBakeDariComposeDiteruskanApaAdanyaKeBake(): void
    {
        $runner = new BakeFakeRunner();
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $compose->up('demo', '/apps/demo', ['docker-compose.yml'], true);

        // Definisi yang dibaca bake = keluaran Compose (context/args/tag asli),
        // bukan hasil rekonstruksi dashboard.
        $this->assertSame(
            '{"target":{"webman":{"context":"/apps/demo","tags":["demo-webman"]}}}',
            $runner->bakeDefinition
        );
    }

    public function testFileBakeSementaraDihapusSetelahBuild(): void
    {
        $runner = new BakeFakeRunner();
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $compose->up('demo', '/apps/demo', ['docker-compose.yml'], true);

        $this->assertNotSame('', $runner->bakePath);
        $this->assertFileDoesNotExist($runner->bakePath);
    }

    public function testTanpaServiceBuildTidakMemanggilBake(): void
    {
        $runner = new BakeFakeRunner();
        $runner->printStdout = '{"group":{},"target":{}}';
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $compose->up('demo', '/apps/demo', ['docker-compose.yml'], true);

        $this->assertCount(2, $runner->commands);
        $this->assertStringContainsString('build --print', $runner->commands[0]);
        $this->assertStringContainsString('up -d', $runner->commands[1]);
    }

    public function testKeluaranPrintKosongDianggapTanpaBuild(): void
    {
        $runner = new BakeFakeRunner();
        $runner->printStdout = '';
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $compose->up('demo', '/apps/demo', ['docker-compose.yml'], true);

        $this->assertCount(2, $runner->commands);
    }

    public function testDefinisiBakeTidakValidGagalCepat(): void
    {
        $runner = new BakeFakeRunner();
        $runner->printStdout = '{"target":}';
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak valid');
        $compose->up('demo', '/apps/demo', ['docker-compose.yml'], true);
    }

    public function testPrintGagalMenampilkanPesanCompose(): void
    {
        $runner = new BakeFakeRunner();
        $runner->printResult = ['code' => 1, 'stdout' => '', 'stderr' => 'no such file', 'timedOut' => false];
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no such file');
        $compose->up('demo', '/apps/demo', ['docker-compose.yml'], true);
    }

    public function testBakeGagalTidakMenciptakanContainer(): void
    {
        $runner = new BakeFakeRunner();
        $runner->bakeResult = ['code' => 1, 'stdout' => '', 'stderr' => 'network.host is not allowed', 'timedOut' => false];
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        try {
            $compose->up('demo', '/apps/demo', ['docker-compose.yml'], true);
            $this->fail('bake yang gagal harus menggagalkan up');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('network.host is not allowed', $e->getMessage());
        }

        // `up` tidak dijalankan → tidak ada container setengah jadi.
        foreach ($runner->commands as $command) {
            $this->assertStringNotContainsString('up -d', $command);
        }
        $this->assertFileDoesNotExist($runner->bakePath);
    }

    public function testTanpaBuildTidakAdaPrintMaupunBake(): void
    {
        $runner = new BakeFakeRunner();
        $compose = new DockerComposeRunner($runner, 'docker', 10);

        $compose->up('demo', '/apps/demo', ['docker-compose.yml'], false);

        $this->assertCount(1, $runner->commands);
        $this->assertStringContainsString('up -d', $runner->commands[0]);
        $this->assertStringNotContainsString('--build', $runner->commands[0]);
    }
}

/**
 * ProcessRunner tiruan untuk jalur build: `build --print` mengembalikan definisi
 * bake, `buildx bake` mengembalikan sukses, dan path file bake direkam.
 */
final class BakeFakeRunner extends ProcessRunner
{
    /** @var array<int,string> */
    public array $commands = [];

    public string $printStdout = '{"target":{"webman":{"context":"/apps/demo","tags":["demo-webman"]}}}';

    /** @var array{code:int, stdout:string, stderr:string, timedOut:bool} */
    public array $printResult = ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];

    /** @var array{code:int, stdout:string, stderr:string, timedOut:bool} */
    public array $bakeResult = ['code' => 0, 'stdout' => '', 'stderr' => '', 'timedOut' => false];

    public string $bakePath = '';

    public string $bakeDefinition = '';

    public function __construct()
    {
    }

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->commands[] = implode(' ', $command);

        if (in_array('--print', $command, true)) {
            $result = $this->printResult;
            return $result['code'] === 0
                ? ['code' => 0, 'stdout' => $this->printStdout, 'stderr' => '', 'timedOut' => false]
                : $result;
        }

        if (($command[1] ?? '') === 'buildx') {
            $pos = array_search('-f', $command, true);
            $path = $pos === false ? '' : (string) ($command[$pos + 1] ?? '');
            $this->bakePath = $path;
            $this->bakeDefinition = is_file($path) ? (string) file_get_contents($path) : '';
            return $this->bakeResult;
        }

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
        return $this->run($command, $cwd, $timeout, $env, $stdin);
    }
}
