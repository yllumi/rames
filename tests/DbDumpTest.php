<?php
declare(strict_types=1);

namespace Tests;

use app\library\Db\DbDump;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regresi higiene kredensial + guard tool pada `DbDump` (jalur dump/import
 * Strategi A dan halaman `/database`):
 *  - password TIDAK boleh muncul di string command/argv (hanya lewat stdin);
 *  - binary dump/import yang absen harus memberi pesan jelas + exit 127
 *    (bukan error `sh` membingungkan dari ekspansi `$()` kosong);
 *  - urutan stdin import: baris pertama password, lalu SQL.
 */
class DbDumpTest extends TestCase
{
    private const SECRET = "p@ss w'ord\"x\$y";

    /** @return array{username:string, password:string, internal_port:int} */
    private function profile(): array
    {
        return [
            'username' => 'app u"ser',
            'password' => self::SECRET,
            'internal_port' => 3307,
        ];
    }

    public function testDumpCommandNeutralizesPasswordAndGuardsTool(): void
    {
        $dump = new DbDump(new FakeRecordingDockerExec());
        $cmd = $dump->dumpCommand($this->profile(), 'mydb');

        $this->assertStringNotContainsString(self::SECRET, $cmd, 'password tidak boleh ada di argv/string command');
        $this->assertStringContainsString('command -v mysqldump', $cmd);
        $this->assertStringContainsString('command -v mariadb-dump', $cmd);
        $this->assertStringContainsString('[ -n "$tool" ]', $cmd);
        $this->assertStringContainsString('IFS= read -r __pw', $cmd, 'password harus dibaca dari stdin');
        $this->assertStringContainsString('MYSQL_PWD="$__pw" "$tool"', $cmd);
        $this->assertStringContainsString('exit 127', $cmd);
        $this->assertStringContainsString('tidak ditemukan di container', $cmd);
        $this->assertStringContainsString('--host=127.0.0.1', $cmd);
        $this->assertStringContainsString('--port=3307', $cmd);
        $this->assertStringContainsString('--user=' . escapeshellarg('app u"ser'), $cmd);
        $this->assertStringNotContainsString('--all-databases', $cmd);

        $withDb = $dump->dumpCommand($this->profile(), 'mydb');
        $this->assertStringContainsString(escapeshellarg('mydb'), $withDb);
        $this->assertStringNotContainsString('--all-databases', $withDb);

        $all = $dump->dumpCommand($this->profile(), null);
        $this->assertStringContainsString('--all-databases', $all);
    }

    public function testImportCommandNeutralizesPasswordAndGuardsTool(): void
    {
        $dump = new DbDump(new FakeRecordingDockerExec());
        $cmd = $dump->importCommand($this->profile(), 'mydb');

        $this->assertStringNotContainsString(self::SECRET, $cmd, 'password tidak boleh ada di argv/string command');
        $this->assertStringContainsString('command -v mysql', $cmd);
        $this->assertStringContainsString('command -v mariadb', $cmd);
        $this->assertStringContainsString('[ -n "$tool" ]', $cmd);
        $this->assertStringContainsString('IFS= read -r __pw', $cmd, 'password harus dibaca dari stdin');
        $this->assertStringContainsString('MYSQL_PWD="$__pw" "$tool"', $cmd);
        $this->assertStringContainsString('exit 127', $cmd);
        $this->assertStringContainsString('tidak ditemukan di container', $cmd);
        $this->assertStringContainsString(escapeshellarg('mydb'), $cmd);
    }

    public function testImportSendsPasswordThenSqlOnStdin(): void
    {
        $exec = new FakeRecordingDockerExec(0);
        $dump = new DbDump($exec);
        $sql = "CREATE TABLE t (id INT);\nSELECT 'x';\n";

        $result = $dump->import('app-db-1', $this->profile(), 'mydb', $sql);

        $this->assertSame(0, $result['code']);
        $this->assertCount(1, $exec->inputCalls);
        $call = $exec->inputCalls[0];
        $this->assertSame('app-db-1', $call['container']);
        $this->assertSame(self::SECRET . "\n" . $sql, $call['input'], 'stdin harus diawali password+LF lalu SQL');
        $this->assertStringStartsWith(self::SECRET . "\n", $call['input']);
        $this->assertStringNotContainsString(self::SECRET, $call['command'], 'secret hanya di stdin, bukan command');
    }

    public function testImportRejectsInvalidDatabaseName(): void
    {
        $dump = new DbDump(new FakeRecordingDockerExec());
        $this->expectException(RuntimeException::class);
        $dump->import('c', $this->profile(), 'bad-name', 'SELECT 1;');
    }
}
