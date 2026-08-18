<?php
declare(strict_types=1);

namespace Tests;

/**
 * Fixture repo git lokal: origin berisi 2 commit (v1, v2) di branch `main`.
 *
 * Dipakai test GitService & LocalDeployer tanpa jaringan:
 *   - clone shallow dari path lokal (seperti sistem saat create app)
 *   - fetch arbitrary SHA — origin dikonfigurasi
 *     `uploadpack.allowReachableSHA1InWant` / `allowAnySHA1InWant`, prasyarat
 *     rollback pada clone shallow (SPECS.md §7.5).
 */
final class GitTestFixture
{
    public string $workDir;
    public string $origin;
    public string $v1;
    public string $v2;

    public static function create(): self
    {
        $fx = new self();
        $fx->workDir = sys_get_temp_dir() . '/rames-test-' . bin2hex(random_bytes(4));
        mkdir($fx->workDir, 0777, true);
        $fx->origin = $fx->workDir . '/origin';
        $fx->initOrigin();

        $fx->v2 = trim((string) shell_exec('git -C ' . escapeshellarg($fx->origin) . ' rev-parse HEAD'));
        $fx->v1 = trim((string) shell_exec('git -C ' . escapeshellarg($fx->origin) . ' rev-parse HEAD~1'));
        return $fx;
    }

    public function cloneShallow(string $dest, string $branch = 'main'): void
    {
        $this->git($this->workDir, [
            'clone', '--quiet', '--branch', $branch, '--depth', '1', '--single-branch', $this->origin, $dest,
        ]);
    }

    public function cleanup(): void
    {
        self::removeDir($this->workDir);
    }

    private function initOrigin(): void
    {
        mkdir($this->origin, 0777, true);
        $this->git($this->origin, ['init', '-q', '-b', 'main']);
        $this->git($this->origin, ['config', 'user.email', 'test@rames.local']);
        $this->git($this->origin, ['config', 'user.name', 'Rames Test']);
        // izinkan fetch arbitrary SHA (kebutuhan rollback)
        $this->git($this->origin, ['config', 'uploadpack.allowReachableSHA1InWant', 'true']);
        $this->git($this->origin, ['config', 'uploadpack.allowAnySHA1InWant', 'true']);

        file_put_contents($this->origin . '/v1.txt', "v1\n");
        $this->git($this->origin, ['add', '.']);
        $this->git($this->origin, ['commit', '-q', '-m', 'v1']);

        file_put_contents($this->origin . '/v2.txt', "v2\n");
        $this->git($this->origin, ['add', '.']);
        $this->git($this->origin, ['commit', '-q', '-m', 'v2']);
    }

    /**
     * @param array<int,string> $args
     */
    private function git(string $cwd, array $args): void
    {
        $cmd = 'git -C ' . escapeshellarg($cwd) . ' ' . implode(' ', array_map('escapeshellarg', $args));
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException("git gagal ({$code}): {$cmd}\n" . implode("\n", $output));
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
