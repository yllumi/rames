<?php
declare(strict_types=1);

namespace Tests;

use app\library\Adminer\AdminerHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test AdminerHelper — pembentukan argv helper container (ARRAY, tanpa string
 * shell) + pemilihan network/host + fail-fast. Tidak menyentuh Docker Engine.
 *
 * Yang dijaga di sini adalah aturan keras hasil spike Fase 0: **tanpa** `--rm`
 * (konflik dengan `--restart`), label penanda, `PHP_CLI_SERVER_WORKERS`, network
 * helper, dan tidak ada rahasia di argv.
 */
class AdminerHelperTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function spec(array $overrides = []): array
    {
        return array_replace([
            'docker' => 'docker',
            'image' => 'adminer:6',
            'container' => 'rames-adminer',
            'network' => 'rames-helpers',
            'workers' => 8,
            'dashboard' => 'rames-webman',
            'command_timeout' => 120,
        ], $overrides);
    }

    // ==================================================================
    // argv `docker run`
    // ==================================================================

    public function testRunArgvIsPlainListOfTokens(): void
    {
        $argv = AdminerHelper::buildRunArgv($this->spec());

        $this->assertSame('docker', $argv[0]);
        $this->assertSame('run', $argv[1]);
        $this->assertSame('-d', $argv[2]);
        // argv ARRAY (tanpa string shell) → tidak ada token yang berisi spasi
        foreach ($argv as $token) {
            $this->assertIsString($token);
            $this->assertStringNotContainsString(' ', $token);
        }
        // Berakhir tepat di image: perintah `php -S [::]:8080` berasal dari image.
        $this->assertSame('adminer:6', $argv[array_key_last($argv)]);
    }

    public function testRunArgvCarriesNameLabelRestartNetworkAndWorkers(): void
    {
        $argv = AdminerHelper::buildRunArgv($this->spec());

        $this->assertSame('rames-adminer', $argv[$this->indexAfter($argv, '--name') + 1]);
        $this->assertSame(AdminerHelper::LABEL, $argv[$this->indexAfter($argv, '--label') + 1]);
        $this->assertSame('unless-stopped', $argv[$this->indexAfter($argv, '--restart') + 1]);
        $this->assertSame('rames-helpers', $argv[$this->indexAfter($argv, '--network') + 1]);
        $this->assertSame('PHP_CLI_SERVER_WORKERS=8', $argv[$this->indexAfter($argv, '-e') + 1]);
        $this->assertSame('json-file', $argv[$this->indexAfter($argv, '--log-driver') + 1]);
        $this->assertContains('max-size=10m', $argv);
        $this->assertContains('max-file=3', $argv);
    }

    public function testRunArgvNeverCombinesRmWithRestart(): void
    {
        $argv = AdminerHelper::buildRunArgv($this->spec());

        // `--rm` + `--restart` membuat `docker create` gagal (exit 125).
        $this->assertNotContains('--rm', $argv);
        $this->assertContains('--restart', $argv);
    }

    public function testRunArgvHasNoCredentials(): void
    {
        // Spec tidak pernah memuat kredensial; pastikan argv tetap bersih walau
        // pemanggil iseng menambahkan kunci tak dikenal.
        $argv = AdminerHelper::buildRunArgv($this->spec(['password' => 'rahasia-db']));

        foreach ($argv as $token) {
            $this->assertStringNotContainsString('rahasia-db', $token);
        }
        $this->assertNotContains('-p', $argv);
        $this->assertNotContains('--publish', $argv);
    }

    public function testRunArgvWorkersAreClamped(): void
    {
        $argv = AdminerHelper::buildRunArgv($this->spec(['workers' => 0]));
        $this->assertSame('PHP_CLI_SERVER_WORKERS=1', $argv[$this->indexAfter($argv, '-e') + 1]);
    }

    // ==================================================================
    // Fail-fast
    // ==================================================================

    public function testRunArgvRejectsEmptyImage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Image Adminer/');
        AdminerHelper::buildRunArgv($this->spec(['image' => '']));
    }

    public function testRunArgvRejectsEmptyContainerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/container helper Adminer/');
        AdminerHelper::buildRunArgv($this->spec(['container' => '']));
    }

    public function testRunArgvRejectsContainerNameWithShellMetacharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdminerHelper::buildRunArgv($this->spec(['container' => 'rames-adminer;rm -rf /']));
    }

    public function testRunArgvRejectsInvalidNetworkName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/network helper Adminer/');
        AdminerHelper::buildRunArgv($this->spec(['network' => 'bad network']));
    }

    // ==================================================================
    // Spec & URL
    // ==================================================================

    public function testNormalizeSpecFillsDefaultsFromConfig(): void
    {
        $spec = AdminerHelper::normalizeSpec([]);

        $this->assertSame('adminer:6', $spec['image']);
        $this->assertSame('rames-adminer', $spec['container']);
        $this->assertSame('rames-helpers', $spec['network']);
        $this->assertSame(8, $spec['workers']);
        $this->assertSame('docker', $spec['docker']);
        $this->assertGreaterThan(0, $spec['command_timeout']);
    }

    public function testNormalizeSpecIgnoresUnknownKeys(): void
    {
        $spec = AdminerHelper::normalizeSpec(['password' => 'secret', 'image' => 'adminer:6']);

        $this->assertArrayNotHasKey('password', $spec);
    }

    public function testBaseUrlUsesContainerNameAndHelperPort(): void
    {
        $helper = new AdminerHelper(null, null, $this->spec(['container' => 'rames-adminer']));

        $this->assertSame('http://rames-adminer:' . AdminerHelper::PORT, $helper->baseUrl());
    }

    // ==================================================================
    // Network & host target (statik murni)
    // ==================================================================

    public function testTargetNetworkPrefersAppComposeNetwork(): void
    {
        $inspect = [
            'NetworkSettings' => ['Networks' => [
                'shared-db' => ['IPAddress' => '172.20.0.9'],
                'myapp_default' => ['IPAddress' => '172.21.0.4'],
            ]],
        ];

        $this->assertSame('myapp_default', AdminerHelper::targetNetwork($inspect, 'myapp'));
    }

    public function testTargetNetworkIgnoresBuiltinNetworks(): void
    {
        $inspect = [
            'NetworkSettings' => ['Networks' => [
                'bridge' => ['IPAddress' => '172.17.0.3'],
                'host' => [],
                'none' => [],
            ]],
        ];

        $this->assertSame('', AdminerHelper::targetNetwork($inspect, 'myapp'));
    }

    public function testTargetNetworkFallsBackToFirstUserNetwork(): void
    {
        $inspect = [
            'NetworkSettings' => ['Networks' => [
                'shared-db' => ['IPAddress' => '172.20.0.9'],
                'other_net' => ['IPAddress' => '172.21.0.4'],
            ]],
        ];

        $this->assertSame('shared-db', AdminerHelper::targetNetwork($inspect, 'myapp'));
    }

    public function testDatabaseHostPrefersContainerName(): void
    {
        $inspect = [
            'Name' => '/myapp-db-1',
            'NetworkSettings' => ['Networks' => ['myapp_default' => ['IPAddress' => '172.21.0.4']]],
        ];

        $this->assertSame('myapp-db-1', AdminerHelper::databaseHost($inspect, 'myapp_default'));
    }

    public function testDatabaseHostFallsBackToNetworkIpThenId(): void
    {
        $byIp = ['NetworkSettings' => ['Networks' => ['myapp_default' => ['IPAddress' => '172.21.0.4']]]];
        $this->assertSame('172.21.0.4', AdminerHelper::databaseHost($byIp, 'myapp_default'));

        $byId = ['Id' => 'abcdef0123456789'];
        $this->assertSame('abcdef012345', AdminerHelper::databaseHost($byId, 'myapp_default'));
        $this->assertSame('', AdminerHelper::databaseHost([], 'myapp_default'));
    }

    /**
     * Indeks token pertama pada argv.
     *
     * @param array<int,string> $argv
     */
    private function indexAfter(array $argv, string $needle): int
    {
        $index = array_search($needle, $argv, true);
        $this->assertNotFalse($index, "argv tidak memuat {$needle}");

        return (int) $index;
    }
}
