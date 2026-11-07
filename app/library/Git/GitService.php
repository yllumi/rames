<?php
declare(strict_types=1);

namespace app\library\Git;

use app\library\Support\ProcessRunner;
use RuntimeException;

/**
 * Operasi Git untuk clone & update repo app (docs/SPECS.md §7.2 langkah 3).
 * Clone **selalu shallow** (`--depth=1 --single-branch`, SPECS §7.2); rollback
 * mengambil SHA lama lewat `fetch`.
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

    /**
     * Clone repo app ke `$dest`.
     *
     * **Selalu shallow**: `--depth=1` (riwayat hanya commit terbaru) +
     * `--single-branch` (hanya branch yang dipilih) — clone app besar tidak
     * menarik seluruh riwayat/tag, jadi create app cepat & hemat disk. Konsekuensi
     * yang disengaja: SHA lama tidak ada di lokal, rollback memakainya
     * {@see fetchSha()} untuk mengambil commit itu dari remote.
     */
    public function clone(string $repoUrl, string $branch, string $dest, ?string $sshKeyPath = null): void
    {
        $result = $this->runner->run(
            ['git', 'clone', '--branch', $branch, '--depth=1', '--single-branch', $repoUrl, $dest],
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
     * Ambil commit lama (rollback) dari remote. Repo diklone shallow (--depth=1),
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
     * Validasi nama branch Git dengan aturan ref yang konservatif.
     *
     * @throws RuntimeException
     */
    public static function validateBranchName(string $branch): string
    {
        if ($branch === '' || trim($branch) !== $branch || strlen($branch) > 255
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $branch) !== 1
            || str_contains($branch, '..')
            || str_contains($branch, '//')
            || str_contains($branch, '@{')
            || str_ends_with($branch, '/')
            || str_ends_with($branch, '.')) {
            throw new RuntimeException('Nama branch Git tidak valid.');
        }

        foreach (explode('/', $branch) as $component) {
            if ($component === '' || str_starts_with($component, '.') || str_ends_with($component, '.lock')) {
                throw new RuntimeException('Nama branch Git tidak valid.');
            }
        }

        return $branch;
    }

    /**
     * Ambil satu remote branch secara eksplisit, lalu buat/geser local branch
     * ke ref yang baru di-fetch. Input branch hanya masuk sebagai argv; tidak ada
     * shell dan tidak ada refspec wildcard.
     *
     * @return string SHA commit yang diaktifkan
     */
    public function switchBranch(string $dir, string $branch, ?string $sshKeyPath = null): string
    {
        $branch = self::validateBranchName($branch);
        $check = $this->runner->run(['git', '-C', $dir, 'check-ref-format', 'refs/heads/' . $branch], $dir, 60);
        if ($check['code'] !== 0) {
            throw new RuntimeException('Nama branch Git tidak valid.');
        }

        $remoteRef = 'refs/remotes/origin/' . $branch;
        $refspec = '+refs/heads/' . $branch . ':' . $remoteRef;
        $fetch = $this->runner->run(
            ['git', '-C', $dir, 'fetch', '--depth=1', '--no-tags', 'origin', $refspec],
            null,
            $this->timeout,
            $this->sshEnv($sshKeyPath)
        );
        if ($fetch['code'] !== 0) {
            throw new RuntimeException(
                'Gagal fetch branch remote "' . $branch . '": '
                . trim($fetch['stderr'] !== '' ? $fetch['stderr'] : $fetch['stdout'])
            );
        }

        $commit = $this->runner->run(
            ['git', '-C', $dir, 'rev-parse', '--verify', $remoteRef . '^{commit}'],
            $dir,
            60
        );
        if ($commit['code'] !== 0) {
            throw new RuntimeException('Branch remote "' . $branch . '" tidak menunjuk commit yang valid.');
        }
        $sha = trim($commit['stdout']);

        $checkout = $this->runner->run(
            ['git', '-C', $dir, 'checkout', '-B', $branch, $remoteRef],
            $dir,
            120
        );
        if ($checkout['code'] !== 0) {
            throw new RuntimeException(
                'Gagal checkout branch "' . $branch . '": '
                . trim($checkout['stderr'] !== '' ? $checkout['stderr'] : $checkout['stdout'])
            );
        }

        return $sha;
    }

    /**
     * Branch aktif, atau null bila HEAD detached.
     *
     * @throws RuntimeException
     */
    public function currentBranch(string $dir): ?string
    {
        $result = $this->runner->run(['git', '-C', $dir, 'symbolic-ref', '--quiet', '--short', 'HEAD'], $dir, 60);
        if ($result['code'] === 1) {
            return null;
        }
        if ($result['code'] !== 0) {
            throw new RuntimeException('Gagal membaca branch aktif: ' . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }

        return trim($result['stdout']);
    }

    /**
     * Apakah path relatif sudah menjadi bagian dari index Git.
     */
    public function isTracked(string $dir, string $path): bool
    {
        $result = $this->runner->run(
            ['git', '-C', $dir, 'ls-files', '--error-unmatch', '--', $path],
            $dir,
            30
        );

        return $result['code'] === 0;
    }

    /**
     * Kembalikan checkout ke commit persis sebelumnya; branch sebelumnya
     * (bila ada) dipasang ulang pada SHA itu, tidak mengikuti remote yang baru.
     *
     * @param string|null $branch null berarti HEAD sebelumnya detached
     * @throws RuntimeException
     */
    public function restoreCheckout(string $dir, string $sha, ?string $branch): void
    {
        if (preg_match('/^[a-f0-9]{40,64}$/i', $sha) !== 1) {
            throw new RuntimeException('Commit restore tidak valid.');
        }

        if ($branch === null) {
            $result = $this->runner->run(['git', '-C', $dir, 'checkout', '--detach', $sha], $dir, 120);
        } else {
            $branch = self::validateBranchName($branch);
            $result = $this->runner->run(['git', '-C', $dir, 'checkout', '-B', $branch, $sha], $dir, 120);
        }
        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'Gagal memulihkan checkout sebelumnya: '
                . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
            );
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
     * path-nya berasal dari nilai tervalidasi (slug app), namun tetap di-escape
     * sebagai pertahanan berlapis (docs/SPECS.md §11).
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
