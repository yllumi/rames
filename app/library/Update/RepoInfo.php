<?php
declare(strict_types=1);

namespace app\library\Update;

use app\library\Support\ProcessRunner;

/**
 * Pembacaan keadaan repo Git dashboard (SPECS.md §7.8 / ARCHITECTURE.md §5.14).
 *
 * Kenapa kelas ini ada (bukan langsung `GitService`):
 *  - Dashboard berjalan sebagai **root** di dalam container, sedangkan file repo
 *    dimiliki user host (uid 1000). Git menolak repo seperti itu
 *    (`fatal: detected dubious ownership in repository`) → setiap pemanggilan git
 *    di sini memakai `-c safe.directory=<root>` (per-invokasi, TIDAK menulis
 *    config global milik user host).
 *  - Operasi yang mengubah repo (`fetch`/`pull`/`reset`) TIDAK ada di sini —
 *    itu tugas helper container yang berjalan sebagai pemilik repo (lihat
 *    `cli/self-update.sh`), supaya file hasil update tetap milik user host.
 *  - Pembacaan SHA/branch/remote dilakukan dari file `.git` langsung (read-only,
 *    tanpa proses git sama sekali) sehingga murah dipakai endpoint `/healthz`.
 *
 * Seluruh parser statik dipisah agar bisa diuji tanpa repo nyata.
 */
final class RepoInfo
{
    private ProcessRunner $runner;

    public function __construct(
        private readonly string $root,
        ?ProcessRunner $runner = null,
    ) {
        $this->runner = $runner ?? new ProcessRunner();
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Repo git yang bisa dibaca (`.git` ada) — bukan jaminan binary `git` ada.
     */
    public function isRepo(): bool
    {
        return is_dir($this->root . '/.git');
    }

    // ==================================================================
    // Pembacaan `.git` langsung (tanpa proses)
    // ==================================================================

    /**
     * SHA commit aktif (HEAD). `null` bila tidak terbaca.
     */
    public function sha(): ?string
    {
        $head = $this->gitFile('.git/HEAD');
        if ($head === null) {
            return null;
        }
        $head = trim($head);
        if ($head === '') {
            return null;
        }

        // Normal: "ref: refs/heads/main" → resolusi ref (loose lalu packed).
        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            if ($ref === '') {
                return null;
            }

            return $this->resolveRef($ref);
        }

        // Detached HEAD: isinya SHA langsung.
        return preg_match('/^[0-9a-f]{7,64}$/i', $head) === 1 ? $head : null;
    }

    /**
     * Nama branch aktif (`null` bila detached HEAD).
     */
    public function branch(): ?string
    {
        $head = $this->gitFile('.git/HEAD');
        if ($head === null) {
            return null;
        }
        $ref = self::parseHeadRef(trim($head));
        if ($ref === null || !str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        return substr($ref, strlen('refs/heads/'));
    }

    /**
     * URL remote (default `origin`) dari `.git/config`.
     */
    public function remoteUrl(string $remote = 'origin'): ?string
    {
        $config = $this->gitFile('.git/config');
        if ($config === null) {
            return null;
        }

        return self::parseRemoteUrl($config, $remote);
    }

    private function resolveRef(string $ref): ?string
    {
        $loose = $this->gitFile('.git/' . $ref);
        if ($loose !== null && trim($loose) !== '') {
            return trim($loose);
        }

        $packed = $this->gitFile('.git/packed-refs');
        if ($packed === null) {
            return null;
        }

        return self::parsePackedRefs($packed, $ref);
    }

    /**
     * Baca file di dalam `.git`; `null` bila bukan file atau tidak terbaca.
     * `.git` bisa berupa file (worktree/submodule) — di kasus itu pembacaan
     * langsung tidak didukung dan pemanggil akan memakai jalur git biasa.
     */
    private function gitFile(string $relative): ?string
    {
        $path = $this->root . '/' . $relative;
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    // ==================================================================
    // Operasi git (read-only, via proses — butuh binary git)
    // ==================================================================

    /**
     * Informasi commit HEAD: sha, tanggal commit (ISO-8601), judul.
     *
     * @return array{sha:string,date:string,subject:string}|null
     */
    public function headCommit(): ?array
    {
        $result = $this->git(['log', '-1', '--format=%H%x09%cI%x09%s'], 20);
        if ($result === null) {
            return null;
        }

        return self::parseLogLine($result);
    }

    /**
     * Jarak commit lokal vs target (`git rev-list --count <a>..<b>`). Dipakai
     * untuk menampilkan berapa commit tertinggal SETELAH ada ref lokal
     * (mis. setelah update), bukan sumber kebenaran "ada pembaruan".
     */
    public function countBetween(string $from, string $to): ?int
    {
        $result = $this->git(['rev-list', '--count', $from . '..' . $to], 20);
        if ($result === null) {
            return null;
        }
        $out = trim($result);

        return ctype_digit($out) ? (int) $out : null;
    }

    /**
     * Perubahan pada file yang DILACAK (staged maupun belum) — penghalang update.
     * File untracked tidak dihitung (tidak bentrok dengan `git pull` kecuali
     * `pull` menulis path yang sama, dan itu dilaporkan git sendiri).
     *
     * @return array<int,array{code:string,path:string}>
     */
    public function trackedChanges(): array
    {
        $result = $this->git(['status', '--porcelain', '--untracked-files=no'], 30);
        if ($result === null) {
            return [];
        }

        return self::parsePorcelain($result);
    }

    /**
     * File yang belum dilacak git (hanya untuk informasi di UI).
     *
     * @return array<int,string>
     */
    public function untrackedFiles(): array
    {
        $result = $this->git(['status', '--porcelain', '--untracked-files=normal'], 30);
        if ($result === null) {
            return [];
        }

        $paths = [];
        foreach (self::parsePorcelain($result) as $entry) {
            if ($entry['code'] === '??') {
                $paths[] = $entry['path'];
            }
        }

        return $paths;
    }

    /**
     * SHA HEAD remote TANPA menyentuh repo lokal (`git ls-remote`).
     *
     * Ini kunci keamanan kepemilikan file: `git fetch` akan menulis objek/ref
     * ke `.git` sebagai root dan meninggalkan file milik root di repo milik user
     * host — `ls-remote` hanya membaca ref di sisi remote.
     */
    public function remoteHead(string $branch, int $timeout = 30): ?string
    {
        $url = $this->remoteUrl();
        if ($url === null || $url === '') {
            return null;
        }
        $branch = trim($branch);
        if ($branch === '') {
            return null;
        }

        // Dijalankan dari direktori netral: tidak butuh repo lokal sama sekali.
        $command = ['git', 'ls-remote', '--heads', $url, 'refs/heads/' . $branch];
        $result = $this->runner->run($command, sys_get_temp_dir(), $timeout);
        if ($result['code'] !== 0) {
            return null;
        }

        return self::parseLsRemote($result['stdout'], 'refs/heads/' . $branch);
    }

    /**
     * Versi git singkat (untuk diagnosa di UI).
     */
    public function gitVersion(): ?string
    {
        $result = $this->runner->run(['git', '--version'], $this->root, 15);
        if ($result['code'] !== 0) {
            return null;
        }

        return trim($result['stdout']) !== '' ? trim($result['stdout']) : null;
    }

    /**
     * Jalankan git di dalam repo dengan `safe.directory` per-invokasi.
     *
     * @param array<int,string> $args
     * @return string|null stdout bila sukses, `null` bila gagal
     */
    private function git(array $args, int $timeout): ?string
    {
        $command = array_merge(
            ['git', '-C', $this->root, '-c', 'safe.directory=' . $this->root],
            $args
        );
        $result = $this->runner->run($command, $this->root, $timeout);
        if ($result['code'] !== 0) {
            return null;
        }

        return $result['stdout'];
    }

    // ==================================================================
    // Parser statik (diuji tanpa repo)
    // ==================================================================

    /**
     * Isi `.git/HEAD` → nama ref (`refs/heads/main`) atau SHA (detached).
     */
    public static function parseHeadRef(string $content): ?string
    {
        $line = trim($content);
        if ($line === '') {
            return null;
        }
        if (str_starts_with($line, 'ref:')) {
            $ref = trim(substr($line, 4));

            return $ref !== '' ? $ref : null;
        }

        return preg_match('/^[0-9a-f]{7,64}$/i', $line) === 1 ? $line : null;
    }

    /**
     * Cari ref di `.git/packed-refs` (dipakai saat ref loose belum ada).
     */
    public static function parsePackedRefs(string $content, string $ref): ?string
    {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }
            $space = strpos($line, ' ');
            if ($space === false) {
                continue;
            }
            $sha = substr($line, 0, $space);
            $name = trim(substr($line, $space + 1));
            if ($name === $ref && preg_match('/^[0-9a-f]{7,64}$/i', $sha) === 1) {
                return $sha;
            }
        }

        return null;
    }

    /**
     * URL remote dari isi `.git/config` (format git-config sederhana).
     */
    public static function parseRemoteUrl(string $config, string $remote = 'origin'): ?string
    {
        $inSection = false;
        foreach (explode("\n", $config) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '[') {
                $inSection = false;
                if (preg_match('/^\[remote\s+"([^"]+)"\]$/', $line, $m) === 1) {
                    $inSection = $m[1] === $remote;
                }
                continue;
            }
            if (!$inSection) {
                continue;
            }
            if (preg_match('/^url\s*=\s*(.+)$/', $line, $m) === 1) {
                $url = trim($m[1]);
                // Nilai boleh di-quote oleh git saat menulis config.
                if (strlen($url) > 1 && $url[0] === '"' && $url[strlen($url) - 1] === '"') {
                    $url = str_replace('\\"', '"', substr($url, 1, -1));
                }

                return $url !== '' ? $url : null;
            }
        }

        return null;
    }

    /**
     * Keluaran `git status --porcelain` → daftar perubahan.
     *
     * @return array<int,array{code:string,path:string}>
     */
    public static function parsePorcelain(string $output): array
    {
        $entries = [];
        foreach (explode("\n", $output) as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            // Kolom status: 2 karakter, sering berisi spasi di sisi yang kosong
            // (" M" = dimodifikasi & belum di-stage) → disajikan tanpa spasi
            // supaya enak dibaca di daftar prasyarat panel update.
            $code = preg_replace('/\s+/', '', substr($line, 0, 2)) ?? '';
            $path = trim(substr($line, 3));
            if ($path === '') {
                continue;
            }
            $entries[] = ['code' => $code === '' ? '??' : $code, 'path' => $path];
        }

        return $entries;
    }

    /**
     * Keluaran `git ls-remote` → SHA untuk ref yang diminta.
     */
    public static function parseLsRemote(string $output, string $ref): ?string
    {
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 2) {
                continue;
            }
            if ($parts[1] === $ref && preg_match('/^[0-9a-f]{7,64}$/i', $parts[0]) === 1) {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * Keluaran `git log -1 --format=%H%x09%cI%x09%s` → array terurai.
     *
     * @return array{sha:string,date:string,subject:string}|null
     */
    public static function parseLogLine(string $output): ?array
    {
        $line = trim($output);
        if ($line === '') {
            return null;
        }
        $parts = explode("\t", $line, 3);
        if (count($parts) < 2) {
            return null;
        }
        $sha = trim($parts[0]);
        if (preg_match('/^[0-9a-f]{7,64}$/i', $sha) !== 1) {
            return null;
        }

        return [
            'sha' => $sha,
            'date' => trim($parts[1]),
            'subject' => trim($parts[2] ?? ''),
        ];
    }

    /**
     * URL halaman perbandingan versi pada web remote (GitHub/GitLab/Gitea) —
     * dipakai UI untuk membuka daftar perubahan tanpa API (tanpa rate limit).
     * `null` bila host tidak dikenali.
     */
    public static function compareUrl(?string $remoteUrl, string $from, string $to): ?string
    {
        $remote = trim((string) $remoteUrl);
        if ($remote === '' || $from === '' || $to === '') {
            return null;
        }

        // Normalisasi bentuk scp-like (git@github.com:owner/repo.git) & ssh://
        $web = null;
        if (preg_match('#^[\w.+-]+@([^:/]+):(.+)$#', $remote, $m) === 1) {
            $web = 'https://' . $m[1] . '/' . $m[2];
        } elseif (preg_match('#^https?://([^/]+)/(.+)$#', $remote, $m) === 1) {
            $web = 'https://' . $m[1] . '/' . $m[2];
        } elseif (preg_match('#^ssh://[\w.+-]+@([^:/]+)(?::\d+)?/(.+)$#', $remote, $m) === 1) {
            $web = 'https://' . $m[1] . '/' . $m[2];
        }
        if ($web === null) {
            return null;
        }

        $web = preg_replace('/\.git$/', '', $web) ?? $web;
        $host = (string) parse_url($web, PHP_URL_HOST);
        if ($host === '' || $host === null) {
            return null;
        }

        $range = rawurlencode($from) . '...' . rawurlencode($to);
        if (str_contains($host, 'gitlab')) {
            return $web . '/-/compare/' . $range;
        }
        if (str_contains($host, 'github') || str_contains($host, 'gitea') || str_contains($host, 'codeberg')) {
            return $web . '/compare/' . $range;
        }

        return null;
    }
}
