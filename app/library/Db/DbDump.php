<?php
declare(strict_types=1);

namespace app\library\Db;

use app\library\Docker\DockerExec;
use app\library\Support\SigchldGuard;
use RuntimeException;

/**
 * Export/import database via client bawaan container (mysqldump / mariadb-dump /
 * mysql / mariadb) yang dieksekusi dengan `docker exec` — konsisten dengan
 * arsitektur terminal yang ada, tanpa membuka port tambahan.
 *
 * Kredensial dikirim lewat environment MYSQL_PWD (bukan argumen command line)
 * agar tidak terlihat di `ps`. Seluruh bagian variabel di-escape dengan
 * escapeshellarg; command tetap lewat `sh -c` di dalam container (tanpa shell host).
 */
class DbDump
{
    private DockerExec $exec;

    public function __construct(?DockerExec $exec = null)
    {
        $this->exec = $exec ?? new DockerExec();
    }

    /**
     * Export database ke file lokal. stdout mysqldump di-stream langsung ke file
     * (hemat memori — dump bisa berukuran besar).
     *
     * @param array{username:string, password:string, internal_port:int} $profile
     * @return array{bytes:int}
     */
    public function export(string $container, array $profile, ?string $db, string $outFile): array
    {
        if ($db !== null && !preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
            throw new RuntimeException('Nama database tidak valid.');
        }
        $this->runToFile($container, $this->dumpCommand($profile, $db), $outFile);
        return ['bytes' => (int) (is_file($outFile) ? filesize($outFile) : 0)];
    }

    /**
     * Import SQL (string) ke sebuah database. SQL diumpankan lewat stdin ke
     * client mysql/mariadb di dalam container.
     *
     * @param array{username:string, password:string, internal_port:int} $profile
     * @return array{code:int, stdout:string, stderr:string, timedOut:bool}
     */
    public function import(string $container, array $profile, string $db, string $sql): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
            throw new RuntimeException('Nama database tidak valid.');
        }
        return $this->exec->runCommandWithInput(
            $container,
            $this->importCommand($profile, $db),
            $sql,
            (int) config('deploy.db_import_timeout', 600)
        );
    }

    /**
     * Jalankan command dump dengan stdout langsung ke file.
     */
    private function runToFile(string $container, string $command, string $outFile): void
    {
        $dir = dirname($outFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Tidak dapat membuat direktori export: ' . $dir);
        }

        $args = [(string) config('deploy.docker_binary', 'docker'), 'exec', '-i', $container, 'sh', '-c', $command];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['pipe', 'w'],
        ];
        // Spawn + tunggu dalam SATU blok: SIGCHLD=SIG_DFL wajib bertahan sampai
        // proc_close() (lihat SigchldGuard) — bila worker meng-ignore SIGCHLD,
        // proc_close() selalu mengembalikan -1 dan dump yang sukses dilaporkan gagal.
        /** @var array{code:int, stderr:string} $result */
        $result = SigchldGuard::withDefault(static function () use ($args, $descriptors): array {
            $pipes = [];
            $proc = @proc_open($args, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($proc)) {
                throw new RuntimeException('Gagal menjalankan proses dump: ' . implode(' ', $args));
            }
            fclose($pipes[0]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            return ['code' => (int) proc_close($proc), 'stderr' => $stderr];
        });

        if ($result['code'] !== 0) {
            @unlink($outFile);
            throw new RuntimeException('Export gagal (exit ' . $result['code'] . '): ' . trim($result['stderr']));
        }
    }

    private function dumpCommand(array $profile, ?string $db): string
    {
        $tool = '$(command -v mysqldump || command -v mariadb-dump)';
        $parts = [
            'MYSQL_PWD=' . escapeshellarg((string) ($profile['password'] ?? '')),
            $tool,
            '--host=127.0.0.1',
            '--port=' . (int) ($profile['internal_port'] ?? 3306),
            '--user=' . escapeshellarg((string) ($profile['username'] ?? 'root')),
            '--single-transaction',
            '--routines',
            '--triggers',
        ];
        if ($db === null) {
            $parts[] = '--all-databases';
        } else {
            $parts[] = escapeshellarg($db);
        }
        return implode(' ', $parts);
    }

    private function importCommand(array $profile, string $db): string
    {
        $tool = '$(command -v mysql || command -v mariadb)';
        return implode(' ', [
            'MYSQL_PWD=' . escapeshellarg((string) ($profile['password'] ?? '')),
            $tool,
            '--host=127.0.0.1',
            '--port=' . (int) ($profile['internal_port'] ?? 3306),
            '--user=' . escapeshellarg((string) ($profile['username'] ?? 'root')),
            '--default-character-set=utf8mb4',
            escapeshellarg($db),
        ]);
    }
}
