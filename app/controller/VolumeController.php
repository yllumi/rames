<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Storage\SiteStore;
use support\Request;

/**
 * Halaman Volume — melihat volume ber-label compose & membersihkan volume yatim
 * (ditinggalkan site yang dihapus dengan mode "pertahankan volume"). Controller
 * hanya mediator — akses Docker lewat library (DockerClient / DockerComposeRunner).
 */
class VolumeController
{
    /**
     * Daftar volume ber-label com.docker.compose.project. Volume "yatim" =
     * project-nya sudah tidak ada di sites.json (site dihapus) dan bisa dibersihkan.
     */
    public function index(Request $request)
    {
        $projectNames = $this->activeProjectNames();
        $engineError = null;
        $volumes = [];

        try {
            $volumes = (new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock')))
                ->listVolumes(['label' => ['com.docker.compose.project']]);
        } catch (\Throwable $e) {
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage();
        }

        $rows = [];
        foreach ($volumes as $v) {
            $labels = $v['Labels'] ?? [];
            $project = (string) ($labels['com.docker.compose.project'] ?? '');
            if ($project === '') {
                continue; // hanya volume ber-label compose yang dikelola dashboard
            }
            $rows[] = [
                'name' => (string) ($v['Name'] ?? ''),
                'project' => $project,
                'driver' => (string) ($v['Driver'] ?? ''),
                'mountpoint' => (string) ($v['Mountpoint'] ?? ''),
                'created_at' => (string) ($v['CreatedAt'] ?? ''),
                'orphaned' => !isset($projectNames[$project]),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['orphaned'] !== $b['orphaned']) {
                return $a['orphaned'] ? -1 : 1; // volume yatim tampil lebih dulu
            }
            return strcmp((string) $a['project'], (string) $b['project'])
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        return view('volume/index', [
            'rows' => $rows,
            'engineError' => $engineError,
        ]);
    }

    /**
     * Hapus volume yatim. Menerima `volumes[]` (pilihan) atau `purge_orphans=1`
     * (semua yatim). Volume milik site yang masih aktif DITOLAK (pengaman).
     */
    public function purge(Request $request)
    {
        $projectNames = $this->activeProjectNames();
        $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));

        try {
            $volumes = $docker->listVolumes(['label' => ['com.docker.compose.project']]);
        } catch (\Throwable $e) {
            flash_set('error', 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage());
            return redirect('/volumes');
        }

        // peta nama volume → project
        $volumeProject = [];
        foreach ($volumes as $v) {
            $labels = $v['Labels'] ?? [];
            $p = (string) ($labels['com.docker.compose.project'] ?? '');
            if ($p !== '') {
                $volumeProject[(string) ($v['Name'] ?? '')] = $p;
            }
        }

        if ((string) $request->post('purge_orphans', '') === '1') {
            $targets = array_keys(array_filter(
                $volumeProject,
                static fn (string $p): bool => !isset($projectNames[$p])
            ));
        } else {
            $targets = array_values(array_filter(
                array_map('strval', (array) $request->post('volumes', [])),
                static fn (string $n): bool => $n !== ''
            ));
        }

        // validasi ketat: pola nama volume & harus yatim
        $valid = [];
        foreach ($targets as $name) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
                flash_set('error', "Nama volume tidak valid: {$name}");
                return redirect('/volumes');
            }
            if (!isset($volumeProject[$name]) || isset($projectNames[$volumeProject[$name]])) {
                flash_set('error', "Volume {$name} tidak yatim atau masih dipakai site aktif — dilewati.");
                continue;
            }
            $valid[] = $name;
        }

        if ($valid !== []) {
            try {
                (new DockerComposeRunner())->removeVolumes($valid);
                flash_set('success', count($valid) . ' volume yatim dihapus.');
            } catch (\Throwable $e) {
                flash_set('error', 'Gagal purge volume: ' . $e->getMessage());
            }
        } else {
            flash_set('info', 'Tidak ada volume yatim yang dihapus.');
        }
        return redirect('/volumes');
    }

    /**
     * @return array<string,bool> nama project site yang masih ada di sites.json
     */
    private function activeProjectNames(): array
    {
        $names = [];
        foreach ((new SiteStore())->all() as $site) {
            $names[(string) ($site['name'] ?? '')] = true;
        }
        return $names;
    }
}
