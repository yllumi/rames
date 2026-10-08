<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerExec;
use PHPUnit\Framework\TestCase;

/**
 * Test pemetaan hasil probing shell (`DockerExec::shellFromProbe()`).
 *
 * Regresi yang dijaga (SPECS §7.9 & terminal): `/bin/sh` pada Debian/Ubuntu
 * adalah `dash` yang tidak punya readline/completion (Tab tidak melengkapi
 * apa pun), sehingga terminal default harus memilih shell yang punya readline
 * (`bash`) bila container menyediakannya. Logika murni — tanpa Docker.
 */
class TerminalShellProbeTest extends TestCase
{
    public function testBashPathWins(): void
    {
        $this->assertSame('bash', DockerExec::shellFromProbe("/usr/bin/bash\n"));
        $this->assertSame('bash', DockerExec::shellFromProbe('/bin/bash'));
    }

    public function testFallsBackToAshThenSh(): void
    {
        $this->assertSame('ash', DockerExec::shellFromProbe("/bin/ash\n"));
        $this->assertSame('sh', DockerExec::shellFromProbe("sh\n"));
        $this->assertSame('sh', DockerExec::shellFromProbe('/bin/dash'));
    }

    public function testEmptyOrUnknownOutputFallsBackToSh(): void
    {
        $this->assertSame('sh', DockerExec::shellFromProbe(''));
        $this->assertSame('sh', DockerExec::shellFromProbe("   \n"));
        $this->assertSame('sh', DockerExec::shellFromProbe('/usr/bin/fish'));
    }

    public function testPriorityBashOverAshAndShRegardlessOfOrder(): void
    {
        // Keluaran multi-baris (mis. probe yang lebih longgar): bash tetap menang.
        $this->assertSame('bash', DockerExec::shellFromProbe("/bin/ash\n/bin/bash\n/bin/sh\n"));
    }

    public function testAutoConstantIsNotAWhitelistedShell(): void
    {
        // `auto` adalah penanda "pilih di server", bukan shell yang dieksekusi.
        $this->assertSame('auto', DockerExec::SHELL_AUTO);
        $this->assertSame('sh', DockerExec::shellFromProbe('auto'));
    }
}
