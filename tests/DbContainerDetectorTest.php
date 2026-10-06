<?php
declare(strict_types=1);

namespace Tests;

use app\library\Db\DbContainerDetector;
use app\library\Docker\DockerClient;
use PHPUnit\Framework\TestCase;

/**
 * Fake DockerClient minimal — tidak butuh Engine/socket. Metode baru
 * `DbContainerDetector` tidak memakai `$docker`, jadi cukup no-op.
 */
class DbDetectorFakeDockerClient extends DockerClient
{
    public function __construct()
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
    }
}

/**
 * Detektor dengan `isDbContainer()` di-override menjadi selalu true untuk
 * container non-image/non-env — membuktikan `isDumpableForBackup()` mempercayai
 * `isDbContainer()` polimorfik (langkah 3) dan tidak memangsa container lain.
 */
class TrustingDbDetector extends DbContainerDetector
{
    public function __construct()
    {
        parent::__construct(new DbDetectorFakeDockerClient(), new FakeRecordingDockerExec(1));
    }

    public function isDbContainer(array $inspect): bool
    {
        return true;
    }
}

/**
 * Test `DbContainerDetector` — pemisahan tegas antara "container DB" (UI) dan
 * "DB layak-dump" (pemilihan strategi backup). Sinyal DB dari env saja tidak
 * cukup: container wajib punya binary dump (mysqldump/mariadb-dump).
 */
class DbContainerDetectorTest extends TestCase
{
    private function detector(FakeRecordingDockerExec $exec): DbContainerDetector
    {
        return new DbContainerDetector(new DbDetectorFakeDockerClient(), $exec);
    }

    public function testDbImageIsDumpableWithoutExec(): void
    {
        $exec = new FakeRecordingDockerExec();
        $detector = $this->detector($exec);

        $this->assertTrue($detector->isDumpableForBackup('c1', ['Config' => ['Image' => 'mysql:8.0']]));
        $this->assertSame([], $exec->calls, 'image DB tidak perlu verifikasi exec');
    }

    public function testEnvOnlyWithDumpToolIsDumpable(): void
    {
        $exec = new FakeRecordingDockerExec(0);
        $detector = $this->detector($exec);
        $inspect = ['Config' => ['Image' => 'ghost:6-alpine', 'Env' => ['MYSQL_PASSWORD=secret', 'PATH=/usr/bin']]];

        $this->assertTrue($detector->isDumpableForBackup('c-ghost', $inspect));
        $this->assertCount(1, $exec->calls);
        $this->assertSame('c-ghost', $exec->calls[0]['container']);
        $this->assertStringContainsString('mysqldump', $exec->calls[0]['command']);
        $this->assertStringContainsString('mariadb-dump', $exec->calls[0]['command']);
        $this->assertSame(10, $exec->calls[0]['timeout']);
    }

    public function testEnvOnlyWithoutDumpToolIsNotDumpable(): void
    {
        $exec = new FakeRecordingDockerExec(1);
        $detector = $this->detector($exec);
        $inspect = ['Config' => ['Image' => 'ghost:6-alpine', 'Env' => ['MYSQL_PASSWORD=secret']]];

        $this->assertFalse($detector->isDumpableForBackup('c-ghost', $inspect));
        $this->assertCount(1, $exec->calls);
    }

    public function testExecThrowsIsNotDumpable(): void
    {
        $exec = new FakeRecordingDockerExec(0, true);
        $detector = $this->detector($exec);
        $inspect = ['Config' => ['Image' => 'ghost:6-alpine', 'Env' => ['MYSQL_DATABASE=app']]];

        $this->assertFalse($detector->isDumpableForBackup('c-ghost', $inspect));
        $this->assertCount(1, $exec->calls);
    }

    public function testNonDbContainerIsNotDumpableWithoutExec(): void
    {
        $exec = new FakeRecordingDockerExec();
        $detector = $this->detector($exec);
        $inspect = ['Config' => ['Image' => 'nginx:alpine', 'Env' => ['PATH=/usr/bin']]];

        $this->assertFalse($detector->isDumpableForBackup('c-web', $inspect));
        $this->assertSame([], $exec->calls);
    }

    public function testResultIsMemoizedPerContainer(): void
    {
        $exec = new FakeRecordingDockerExec(0);
        $detector = $this->detector($exec);
        $inspect = ['Config' => ['Image' => 'ghost:6-alpine', 'Env' => ['MYSQL_PASSWORD=secret']]];

        $this->assertTrue($detector->isDumpableForBackup('c1', $inspect));
        $this->assertTrue($detector->isDumpableForBackup('c1', $inspect));
        $this->assertCount(1, $exec->calls, 'hasil per id di-memoize (exec sekali)');
    }

    public function testIsDbContainerEnvOnlyStaysTrueForUi(): void
    {
        // Behavior halaman /database tidak boleh berubah: env-only tetap "container DB".
        $detector = $this->detector(new FakeRecordingDockerExec());

        $this->assertTrue($detector->isDbContainer([
            'Config' => ['Image' => 'ghost:6-alpine', 'Env' => ['MYSQL_PASSWORD=secret']],
        ]));
        $this->assertTrue($detector->isDbContainer(['Config' => ['Image' => 'mariadb:11']]));
        $this->assertFalse($detector->isDbContainer([
            'Config' => ['Image' => 'ghost:6-alpine', 'Env' => ['PATH=/usr/bin']],
        ]));
    }

    public function testPureHelpersClassifyImageAndEnv(): void
    {
        $this->assertTrue(DbContainerDetector::isDbImage(['Config' => ['Image' => 'percona:8']]));
        $this->assertFalse(DbContainerDetector::isDbImage(['Config' => ['Image' => 'ghost:6-alpine']]));

        $this->assertTrue(DbContainerDetector::hasDbEnv(['Config' => ['Env' => ['MARIADB_ROOT_PASSWORD=x']]]));
        $this->assertFalse(DbContainerDetector::hasDbEnv(['Config' => ['Env' => ['MYSQL_NOT_A_KEY=x']]]));
    }

    public function testIsDumpableTrustsDetectorWhenNoImageOrEnvSignal(): void
    {
        // isDbContainer() di-override true, tapi image bukan image DB & tanpa env DB:
        // langkah 3 mempercayai detector → true, tanpa memanggil exec.
        $detector = new TrustingDbDetector();

        $this->assertTrue($detector->isDumpableForBackup('c1', ['Config' => ['Image' => 'nginx:alpine']]));
    }

    public function testEmptyContainerIdIsNotDumpable(): void
    {
        $detector = $this->detector(new FakeRecordingDockerExec());

        $this->assertFalse($detector->canRunDumpTool(''));
    }
}
