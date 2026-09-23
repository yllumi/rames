<?php
declare(strict_types=1);

namespace app\library\Update;

/**
 * Pengecekan ketersediaan pembaruan dashboard (SPECS.md §7.8).
 *
 * Cara kerja: bandingkan SHA HEAD repo lokal (dibaca dari `.git`, tanpa proses)
 * dengan SHA ref branch di remote (`git ls-remote`). `git ls-remote` sengaja
 * dipilih daripada `git fetch` karena fetch MENULIS objek/ref ke `.git` sebagai
 * root (container) — file itu lalu menjadi milik root di repo milik user host
 * dan bisa menggagalkan `git pull` berikutnya dari SSH. `ls-remote` tidak
 * menyentuh repo lokal sama sekali.
 *
 * Konsekuensi yang disadari: tanpa fetch, jumlah commit tertinggal tidak bisa
 * dihitung (butuh objek commit). UI karenanya menampilkan tautan "lihat
 * perubahan" ke halaman compare remote (tidak butuh API, tanpa rate limit).
 *
 * Hasil ditulis ke `runtime/update/check.json`; badge nav topbar hanya membaca
 * berkas itu (tanpa jaringan) sehingga tidak memperlambat halaman.
 */
final class UpdateChecker
{
    public function __construct(
        private readonly RepoInfo $repo,
        private readonly UpdateState $state,
        private readonly string $branchOverride = '',
    ) {
    }

    /**
     * Hasil cek terakhir (tanpa jaringan).
     *
     * @return array<string,mixed>
     */
    public function cached(): array
    {
        return $this->state->check();
    }

    /**
     * Lakukan pengecekan (jaringan) dan simpan hasilnya.
     *
     * @param bool $write false = hitung saja (untuk unit test / dry-run)
     * @return array<string,mixed>
     */
    public function check(bool $write = true): array
    {
        $result = [
            'checked_at' => date('c'),
            'ok' => false,
            'error' => null,
            'branch' => '',
            'local_sha' => null,
            'remote_sha' => null,
            'update_available' => false,
            'compare_url' => null,
            'head' => null,
            'untracked' => [],
            'tracked_changes' => [],
        ];

        if (!$this->repo->isRepo()) {
            $result['error'] = 'Direktori dashboard bukan repo Git (`.git` tidak ditemukan): ' . $this->repo->root();
            return $this->finish($result, $write);
        }

        $result['head'] = $this->repo->headCommit();
        $result['local_sha'] = $this->repo->sha();
        $result['untracked'] = $this->repo->untrackedFiles();
        $result['tracked_changes'] = array_map(
            static fn (array $entry): string => $entry['code'] . ' ' . $entry['path'],
            $this->repo->trackedChanges()
        );

        $branch = trim($this->branchOverride) !== '' ? trim($this->branchOverride) : (string) $this->repo->branch();
        if ($branch === '') {
            $result['error'] = 'HEAD repo tidak berada di branch (detached). Set UPDATE_BRANCH untuk menentukan branch yang dicek.';
            return $this->finish($result, $write);
        }
        $result['branch'] = $branch;

        if ($result['local_sha'] === null) {
            $result['error'] = 'SHA lokal tidak terbaca dari `.git` (format ref tidak dikenali).';
            return $this->finish($result, $write);
        }

        $remoteUrl = $this->repo->remoteUrl();
        if ($remoteUrl === null || $remoteUrl === '') {
            $result['error'] = 'Remote `origin` tidak ditemukan di `.git/config`.';
            return $this->finish($result, $write);
        }

        $remote = $this->repo->remoteHead($branch);
        if ($remote === null) {
            $git = $this->repo->gitVersion() ?? 'git tidak tersedia';
            $result['error'] = 'Gagal membaca ref remote ' . $branch . ' (' . $remoteUrl . ') — periksa jaringan/DNS container. ' . $git;
            return $this->finish($result, $write);
        }

        $result['ok'] = true;
        $result['remote_sha'] = $remote;
        $result['update_available'] = $remote !== $result['local_sha'];
        // Tautan compare hanya berguna bila memang ada yang perlu dilihat.
        $result['compare_url'] = $result['update_available']
            ? RepoInfo::compareUrl($remoteUrl, (string) $result['local_sha'], $remote)
            : null;

        return $this->finish($result, $write);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function finish(array $result, bool $write): array
    {
        if ($write) {
            $this->state->writeCheck($result);
        }

        return $result;
    }
}
