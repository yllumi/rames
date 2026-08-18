<?php
declare(strict_types=1);

namespace app\library\Docker;

use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Menjalankan docker compose CLI untuk orkestrasi (up/down/build/stop/start).
 *
 * Menggunakan bentuk array + bypass_shell (tanpa shell) sehingga bebas dari
 * command injection — project name & path dipakai sebagai argumen langsung.
 */
class DockerComposeRunner
{
    private ProcessRunner $runner;
    private string $composeBinary;
    private int $timeout;

    public function __construct(?ProcessRunner $runner = null, string $composeBinary = 'docker', int $timeout = 600)
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->composeBinary = $composeBinary;
        $this->timeout = $timeout;
    }

    /**
     * @return array<int,string>
     */
    private function baseArgs(string $project, string $dir, array $files): array
    {
        $args = [$this->composeBinary, 'compose', '-p', $project, '--project-directory', $dir];
        foreach ($files as $file) {
            $args[] = '-f';
            $args[] = $dir . '/' . $file;
        }
        return $args;
    }

    public function up(string $project, string $dir, array $files, bool $build = true): void
    {
        $args = $this->baseArgs($project, $dir, $files);
        $args[] = 'up';
        $args[] = '-d';
        if ($build) {
            $args[] = '--build';
        }
        $this->mustRun($args, $dir, 'docker compose up', $this->timeout);
    }

    public function down(string $project, string $dir, array $files, bool $volumes = true): void
    {
        $args = $this->baseArgs($project, $dir, $files);
        $args[] = 'down';
        if ($volumes) {
            $args[] = '-v';
        }
        $this->mustRun($args, $dir, 'docker compose down', $this->timeout);
    }

    /**
     * Hapus volume bernama (docker volume rm). Dipakai teardown selektif:
     * menghapus volume project yang TIDAK dipertahankan saat site dihapus.
     * Bentuk array + bypass_shell → bebas command injection (nama volume argumen).
     *
     * @param array<int,string> $names
     */
    public function removeVolumes(array $names): void
    {
        if ($names === []) {
            return;
        }
        $args = [$this->composeBinary, 'volume', 'rm'];
        foreach ($names as $name) {
            $args[] = (string) $name;
        }
        $this->mustRun($args, sys_get_temp_dir(), 'docker volume rm', 120);
    }

    public function stop(string $project, string $dir, array $files): void
    {
        $args = $this->baseArgs($project, $dir, $files);
        $args[] = 'stop';
        $this->mustRun($args, $dir, 'docker compose stop', 120);
    }

    public function start(string $project, string $dir, array $files): void
    {
        $args = $this->baseArgs($project, $dir, $files);
        $args[] = 'start';
        $this->mustRun($args, $dir, 'docker compose start', 120);
    }

    public function pull(string $project, string $dir, array $files): void
    {
        $args = $this->baseArgs($project, $dir, $files);
        $args[] = 'pull';
        $this->mustRun($args, $dir, 'docker compose pull', $this->timeout);
    }

    /**
     * @return array<int,array>
     */
    public function ps(string $project, string $dir, array $files): array
    {
        $args = $this->baseArgs($project, $dir, $files);
        $args[] = 'ps';
        $args[] = '--format';
        $args[] = 'json';
        $result = $this->runner->run($args, $dir, 60);
        if ($result['code'] !== 0) {
            throw new RuntimeException('docker compose ps gagal: ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
        $rows = [];
        foreach (explode("\n", $result['stdout']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function mustRun(array $args, string $dir, string $label, int $timeout): void
    {
        $result = $this->runner->run($args, $dir, $timeout);
        if ($result['code'] !== 0) {
            $msg = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            if ($result['timedOut']) {
                $msg = trim($result['stderr']);
            }
            throw new RuntimeException("{$label} gagal: " . ($msg !== '' ? $msg : '(tanpa pesan)'));
        }
    }
}
