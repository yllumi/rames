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

    public function clone(string $repoUrl, string $branch, string $dest, ?string $sshKeyPath = null): void
    {
        $result = $this->runner->run(
            ['git', 'clone', '--branch', $branch, '--depth', '1', '--single-branch', $repoUrl, $dest],
            null,
            $this->timeout,
            $this->sshEnv($sshKeyPath)
        );
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal clone repo: ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
    }

    public function pull(string $dir, string $branch, ?string $sshKeyPath = null): void
    {
        $result = $this->runner->run(
            ['git', '-C', $dir, 'pull', '--ff-only', 'origin', $branch],
            null,
            $this->timeout,
            $this->sshEnv($sshKeyPath)
        );
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal pull repo: ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
    }

    /**
     * SHA-1 commit yang sedang aktif (HEAD) di working tree.
     */
    public function revParse(string $dir): string
    {
        $result = $this->runner->run(['git', '-C', $dir, 'rev-parse', 'HEAD'], $dir, 60);
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal membaca HEAD repo: ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
        return trim($result['stdout']);
    }

    /**
     * Ambil commit lama (rollback) dari remote. Repo diklone shallow (--depth 1),
     * sehingga SHA yang tidak ada di riwayat lokal perlu di-fetch dulu.
     * Butuh server git yang mengizinkan fetch arbitrary SHA
     * (GitHub/GitLab umumnya mendukung via reachable SHA).
     */
    public function fetchSha(string $dir, string $sha, ?string $sshKeyPath = null): void
    {
        $result = $this->runner->run(
            ['git', '-C', $dir, 'fetch', 'origin', $sha],
            null,
            $this->timeout,
            $this->sshEnv($sshKeyPath)
        );
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal fetch commit ' . substr($sha, 0, 7) . ': ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
    }

    /**
     * Checkout ke ref tertentu (detached HEAD). Dipakai untuk rollback.
     */
    public function checkout(string $dir, string $ref): void
    {
        $result = $this->runner->run(['git', '-C', $dir, 'checkout', $ref], $dir, 120);
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal checkout ' . substr($ref, 0, 7) . ': ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
    }

    /**
     * Pastikan working tree berada di branch (re-attach dari detached HEAD).
     * Diperlukan setelah rollback (checkout SHA membuat detached HEAD) agar
     * `git pull --ff-only` pada rebuild berikutnya tetap valid.
     */
    public function ensureBranch(string $dir, string $branch): void
    {
        $result = $this->runner->run(['git', '-C', $dir, 'checkout', $branch], $dir, 120);
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal pindah ke branch ' . $branch . ': ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }
    }

    /**
     * Environment tambahan untuk transport SSH bila repo memakai deploy key.
     * Repo publik (tanpa deploy key) => env kosong, perilaku tetap seperti dulu.
     *
     * @return array<string,string>
     */
    private function sshEnv(?string $sshKeyPath): array
    {
        if ($sshKeyPath === null || $sshKeyPath === '') {
            return [];
        }
        return ['GIT_SSH_COMMAND' => $this->buildSshCommand($sshKeyPath)];
    }

    /**
     * Bangun GIT_SSH_COMMAND. Variabel ini dieksekusi lewat shell oleh git;
     * path-nya berasal dari nilai tervalidasi (slug site), namun tetap di-escape
     * sebagai pertahanan berlapis (SPECS.md §11).
     *
     * - IdentitiesOnly=yes            : hanya pakai key ini (abaikan ~/.ssh lain)
     * - StrictHostKeyChecking=accept-new: terima host key baru (mis. github.com)
     * - UserKnownHostsFile            : simpan host key ke file yang dikelola sistem
     */
    private function buildSshCommand(string $keyPath): string
    {
        $knownHosts = (string) config('deploy.git_known_hosts', base_path() . '/database/keys/known_hosts');
        $args = [
            'ssh',
            '-i', $keyPath,
            '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile=' . $knownHosts,
        ];
        return implode(' ', array_map('escapeshellarg', $args));
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
