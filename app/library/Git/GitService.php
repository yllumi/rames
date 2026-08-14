<?php
declare(strict_types=1);

namespace app\library\Git;

use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Operasi Git untuk clone & update repo site (SPECS.md §7.2 langkah 3).
 * Menggunakan bentuk array + bypass_shell => bebas command injection.
 */
class GitService
{
    private ProcessRunner $runner;
    private int $timeout;

    public function __construct(?ProcessRunner $runner = null, int $timeout = 300)
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->timeout = $timeout;
    }

    public function clone(string $repoUrl, string $branch, string $dest): void
    {
        $result = $this->runner->run(
            ['git', 'clone', '--branch', $branch, '--depth', '1', '--single-branch', $repoUrl, $dest],
            null,
            $this->timeout
        );
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal clone repo: ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
    }

    public function pull(string $dir, string $branch): void
    {
        $result = $this->runner->run(
            ['git', '-C', $dir, 'pull', '--ff-only', 'origin', $branch],
            null,
            $this->timeout
        );
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal pull repo: ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
    }

    /**
     * Nama file compose di root repo, atau null.
     */
    public function findComposeFile(string $dir): ?string
    {
        foreach (['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'] as $file) {
            if (is_file($dir . '/' . $file)) {
                return $file;
            }
        }
        return null;
    }
}
