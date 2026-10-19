<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\DeployCanceller;
use app\library\Deploy\DeployLog;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;

/**
 * Pembatalan deploy: pencarian worker yang aman, penghentian grup proses, dan
 * pemulihan status app.
 *
 * `/proc` dipalsukan di direktori temp (cmdline per PID) dan sinyal di-inject —
 * tidak ada proses nyata yang dibunuh, tidak ada `runtime/`/`database/` nyata.
 */
class DeployCancellerTest extends TestCase
{
    private string $dir;

    private string $proc;

    private string $logs;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-cancel-' . bin2hex(random_bytes(5));
        $this->proc = $this->dir . '/proc';
        $this->logs = $this->dir . '/logs';
        mkdir($this->proc, 0775, true);
        mkdir($this->logs, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testTerminateFindsWorkerByAppIdAndKillsGroup(): void
    {
        $this->fakeProc(4242, ['/usr/bin/php', '/srv/rames/cli/deploy.php', 'app-1', 'deploy']);
        $this->fakeProc(4243, ['/usr/bin/php', '/srv/rames/cli/deploy.php', 'app-2', 'deploy']);
        $this->fakeProc(4244, ['/usr/bin/php', '/srv/rames/cli/backup.php', 'app-1']);

        $kills = [];
        $alive = [4242 => true, 4243 => true, 4244 => true];
        $canceller = $this->canceller($kills, $alive);

        $result = $canceller->terminate('app-1', 0);

        $this->assertSame([4242], $result['pids'], 'hanya worker app-1 yang ditemukan');
        $this->assertSame(1, $result['killed']);
        $this->assertSame([], $result['running']);
        $this->assertContains([4242, SIGTERM], $kills);
        $this->assertNotContains([4243, SIGTERM], $kills, 'worker app lain tidak boleh dibunuh');
        $this->assertNotContains([4244, SIGTERM], $kills, 'proses non-deploy tidak boleh dibunuh');
    }

    public function testTerminateIgnoresPidThatDoesNotMatchTheApp(): void
    {
        $this->fakeProc(5000, ['/usr/bin/php', '/srv/rames/cli/deploy.php', 'app-lain', 'rebuild']);

        $kills = [];
        $alive = [5000 => true];
        $canceller = $this->canceller($kills, $alive);

        $result = $canceller->terminate('app-1', 5000);

        $this->assertSame([], $result['pids'], 'PID lama/tidak cocok tidak boleh dipakai');
        $this->assertSame([], $kills);
    }

    public function testTerminateEscalatesToSigkillWhenTermIsIgnored(): void
    {
        $this->fakeProc(6000, ['/usr/bin/php', '/srv/rames/cli/deploy.php', 'app-1', 'rebuild']);

        $kills = [];
        $alive = [6000 => true];
        $kill = function (int $pid, int $signal) use (&$kills, &$alive): bool {
            $kills[] = [$pid, $signal];
            if ($signal === 0) {
                return $alive[abs($pid)] ?? false;
            }
            if ($signal === SIGKILL) {
                $alive[abs($pid)] = false;
            }

            return true; // SIGTERM diabaikan (proses membandel)
        };
        $canceller = new DeployCanceller(null, null, $this->proc, $kill, function (int $p) use (&$alive): bool {
            return $alive[$p] ?? false;
        });

        $result = $canceller->terminate('app-1', 0);

        $this->assertSame(1, $result['killed']);
        $this->assertContains([6000, SIGTERM], $kills);
        $this->assertContains([6000, SIGKILL], $kills);
    }

    public function testCancelAppRestoresStatusAndWritesCancelTrace(): void
    {
        $this->fakeProc(7000, ['/usr/bin/php', '/srv/rames/cli/deploy.php', 'app-1', 'deploy']);

        $store = new AppStore($this->dir . '/rames.sqlite');
        $app = $store->create([
            'id' => 'app-1',
            'name' => 'demo',
            'status' => 'deploying',
            'stage' => 'build',
            'message' => 'docker compose up -d --build ...',
            'containers' => [['container_name' => 'demo-web-1']],
            'worker_pid' => 7000,
            'worker_mode' => 'deploy',
            'worker_started_at' => date('c'),
        ]);

        $kills = [];
        $alive = [7000 => true];
        $docker = new DeployCancellerFakeDocker(['running']);
        $canceller = new DeployCanceller(
            $store,
            $docker,
            $this->proc,
            $this->killFn($kills, $alive),
            function (int $p) use (&$alive): bool {
                return $alive[$p] ?? false;
            },
            new DeployLog($this->logs)
        );

        $result = $canceller->cancelApp($app, null, 'operator');

        $this->assertTrue($result['ok']);
        $this->assertSame('running', $result['status'], 'container lama masih hidup ⇒ app tetap running');
        $this->assertSame(1, $result['killed']);

        $fresh = (array) $store->find('app-1');
        $this->assertSame('running', $fresh['status']);
        $this->assertSame('Deploy dibatalkan.', $fresh['message']);
        $this->assertNull($fresh['stage']);
        $this->assertArrayNotHasKey('worker_pid', $fresh);
        $this->assertArrayNotHasKey('agent_job_id', $fresh);

        $this->assertStringContainsString(
            '[cancelled] Deploy dibatalkan oleh operator.',
            (string) file_get_contents($this->logs . '/app-1.log')
        );
    }

    public function testCancelAppFallsBackToStoppedWhenNoContainerRuns(): void
    {
        $store = new AppStore($this->dir . '/rames.sqlite');
        $app = $store->create([
            'id' => 'app-2',
            'name' => 'demo2',
            'status' => 'deploying',
            'stage' => 'queued',
            'containers' => [['container_name' => 'demo2-web-1']],
        ]);

        $docker = new DeployCancellerFakeDocker(['exited']);
        $canceller = new DeployCanceller($store, $docker, $this->proc, null, null, new DeployLog($this->logs));

        $result = $canceller->cancelApp($app, null);

        $this->assertSame('stopped', $result['status']);
        $this->assertSame(0, $result['killed'], 'tidak ada worker hidup — status tetap dipulihkan');
        $this->assertSame('stopped', (string) $store->find('app-2')['status']);
    }

    /**
     * @param array<int,array{0:int,1:int}> $kills
     * @param array<int,bool>               $alive
     */
    private function canceller(array &$kills, array &$alive): DeployCanceller
    {
        return new DeployCanceller(
            null,
            null,
            $this->proc,
            $this->killFn($kills, $alive),
            function (int $p) use (&$alive): bool {
                return $alive[$p] ?? false;
            }
        );
    }

    /**
     * @param array<int,array{0:int,1:int}> $kills
     * @param array<int,bool>               $alive
     */
    private function killFn(array &$kills, array &$alive): callable
    {
        return function (int $pid, int $signal) use (&$kills, &$alive): bool {
            $kills[] = [$pid, $signal];
            if ($signal === 0) {
                return $alive[abs($pid)] ?? false;
            }
            if (in_array($signal, [SIGTERM, SIGKILL], true)) {
                $alive[abs($pid)] = false;
            }

            return true;
        };
    }

    /**
     * @param array<int,string> $args
     */
    private function fakeProc(int $pid, array $args): void
    {
        $dir = $this->proc . '/' . $pid;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/cmdline', implode("\0", $args) . "\0");
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
