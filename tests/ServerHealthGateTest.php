<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\ServerHealthGate;
use PHPUnit\Framework\TestCase;

/** Uji berjalan di direktori temp — `runtime/` nyata tidak disentuh. */
class ServerHealthGateTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_health_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    private function gate(int &$now): ServerHealthGate
    {
        return new ServerHealthGate($this->tmp . '/health.json', 20, static function () use (&$now): int {
            return $now;
        });
    }

    public function testUnknownServerIsNotCoolingDown(): void
    {
        $now = 1000;
        $this->assertNull($this->gate($now)->coolingDown('srv-1'));
    }

    public function testFailureCoolsDownThenExpires(): void
    {
        $now = 1000;
        $gate = $this->gate($now);
        $gate->recordFailure('srv-1', 'agent mati');

        $now = 1019;
        $this->assertSame('agent mati', $gate->coolingDown('srv-1'));
        $this->assertNull($gate->coolingDown('srv-2'), 'server lain tidak terpengaruh');

        $now = 1020;
        $this->assertNull($gate->coolingDown('srv-1'));
    }

    public function testSuccessClearsCooldown(): void
    {
        $now = 1000;
        $gate = $this->gate($now);
        $gate->recordFailure('srv-1', 'agent mati');
        $gate->recordSuccess('srv-1');

        $this->assertNull($gate->coolingDown('srv-1'));
    }

    public function testSuccessWithoutPriorFailureWritesNothing(): void
    {
        $now = 1000;
        $this->gate($now)->recordSuccess('srv-1');

        $this->assertFileDoesNotExist($this->tmp . '/health.json');
    }
}
