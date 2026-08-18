<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Git\GitService;
use app\library\Nginx\NginxConfigGenerator;
use RuntimeException;

/**
 * Implementasi deployer lokal: eksekusi docker compose di mesin yang sama.
 */
class LocalDeployer implements DeployerInterface
{
    public function __construct(
        private readonly DockerComposeRunner $compose,
        private readonly DockerClient $dockerClient,
        private readonly NginxConfigGenerator $nginx,
        private readonly string $sitesPath,
    ) {
    }

    public function deploy(array $site, callable $logger): array
    {
        // fail-fast: cek prasyarat tulis Nginx SEBELUM build yang lama
        $this->nginx->ensureWritable();

        $project = $site['name'];
        $dir = $this->siteDir($site);
        $files = $site['compose_files'] ?? ['docker-compose.yml'];

        $logger('build', 'Menjalankan docker compose up -d --build ...');
        $this->compose->up($project, $dir, $files, true);

        $logger('collect', 'Mengumpulkan info container ...');
        $site['containers'] = $this->getContainers($project);

        $logger('nginx', 'Menulis config Nginx ...');
        $this->writeNginxConfig($site);

        $site['status'] = 'running';
        $logger('done', 'Deploy selesai.');
        $site = $this->recordHistory($site, (new GitService())->revParse($dir), 'deploy', 'success');
        return $site;
    }

    public function rebuild(array $site, callable $logger): array
    {
        $this->nginx->ensureWritable();

        $project = $site['name'];
        $dir = $this->siteDir($site);
        $files = $site['compose_files'] ?? ['docker-compose.yml'];
        $branch = $site['branch'] ?? 'main';
        $git = new GitService();

        $logger('pull', 'git pull --ff-only ...');
        // Re-attach branch dulu bila HEAD detached (sisa rollback), agar pull valid.
        $git->ensureBranch($dir, $branch);
        $git->pull($dir, $branch, $this->siteSshKeyPath($site));

        $logger('build', 'docker compose up -d --build ...');
        $this->compose->up($project, $dir, $files, true);

        $site['containers'] = $this->getContainers($project);
        $this->writeNginxConfig($site);

        $site['status'] = 'running';
        $logger('done', 'Rebuild selesai.');
        $site = $this->recordHistory($site, $git->revParse($dir), 'rebuild', 'success');
        return $site;
    }

    public function rollback(array $site, string $ref, callable $logger): array
    {
        $this->nginx->ensureWritable();

        $project = $site['name'];
        $dir = $this->siteDir($site);
        $files = $site['compose_files'] ?? ['docker-compose.yml'];
        $git = new GitService();

        // Versi aktif saat ini — dijadikan target restore bila rollback gagal.
        $prevRef = $git->revParse($dir);
        if ($ref === $prevRef) {
            throw new RuntimeException('Ref yang dipilih sama dengan versi yang sedang aktif.');
        }

        $logger('rollback', "Mengembalikan source ke {$ref} ...");
        try {
            $git->fetchSha($dir, $ref, $this->siteSshKeyPath($site));
            $git->checkout($dir, $ref);

            $logger('build', 'docker compose up -d --build ...');
            $this->compose->up($project, $dir, $files, true);

            $site['containers'] = $this->getContainers($project);
            $this->writeNginxConfig($site);

            $site['status'] = 'running';
            $logger('done', 'Rollback selesai.');
            return $this->recordHistory($site, $ref, 'rollback', 'success');
        } catch (\Throwable $e) {
            // Auto-fallback: kembali ke versi yang tadinya aktif (best-effort).
            $logger('restore', "Rollback gagal, mencoba kembali ke {$prevRef} ...");
            try {
                $git->checkout($dir, $prevRef);
                $this->compose->up($project, $dir, $files, true);
                $site['containers'] = $this->getContainers($project);
                $this->writeNginxConfig($site);
                $site['status'] = 'running';
                $logger('restore', 'Restore ke versi sebelumnya berhasil.');
                return $this->recordHistory($site, $prevRef, 'rollback', 'restored', 'Rollback gagal (' . $e->getMessage() . '); dikembalikan ke versi sebelumnya.');
            } catch (\Throwable $restoreError) {
                $site['status'] = 'error';
                $logger('error', 'Rollback dan restore keduanya gagal: ' . $restoreError->getMessage());
                $this->recordHistory($site, $prevRef, 'rollback', 'error', 'Rollback & restore gagal: ' . $restoreError->getMessage());
                throw $e;
            }
        }
    }

    public function stop(array $site): void
    {
        $this->compose->stop($site['name'], $this->siteDir($site), $site['compose_files'] ?? ['docker-compose.yml']);
    }

    public function ensureWritable(): void
    {
        $this->nginx->ensureWritable();
    }

    public function start(array $site): void
    {
        $this->compose->start($site['name'], $this->siteDir($site), $site['compose_files'] ?? ['docker-compose.yml']);
    }

    public function teardown(array $site, ?array $preserveVolumes = null): void
    {
        $project = $site['name'];
        $dir = $this->siteDir($site);
        $files = $site['compose_files'] ?? ['docker-compose.yml'];

        if ($preserveVolumes === null) {
            // Hapus total: down -v (semua named + anonymous volume terhapus).
            $this->compose->down($project, $dir, $files, true);
        } else {
            // Pertahankan volume terpilih: down tanpa -v (semua named volume
            // tetap ada), lalu hapus hanya volume project yang TIDAK
            // dipertahankan. Volume yang dipertahankan akan dipakai ulang saat
            // site dibuat ulang dengan nama yang sama (project = nama site).
            $this->compose->down($project, $dir, $files, false);
            $remove = array_values(array_diff(
                $this->volumeNamesForProject($project),
                $preserveVolumes
            ));
            if ($remove !== []) {
                $this->compose->removeVolumes($remove);
            }
        }
        $this->removeNginxConfig($site);
    }

    /**
     * Nama-nama named volume milik project compose (untuk modal delete).
     *
     * @return array<int,string>
     */
    public function getProjectVolumes(string $project): array
    {
        return $this->volumeNamesForProject($project);
    }

    public function getContainers(string $project): array
    {
        $raw = $this->dockerClient->listContainersForProject($project);
        $containers = [];
        foreach ($raw as $c) {
            $labels = $c['Labels'] ?? [];
            $ports = [];
            foreach ($c['Ports'] ?? [] as $p) {
                if (isset($p['PublicPort'], $p['PrivatePort'])) {
                    $ports[] = ['host' => (int) $p['PublicPort'], 'container' => (int) $p['PrivatePort']];
                }
            }
            $names = $c['Names'] ?? [];
            $first = is_array($names) ? ($names[0] ?? '') : (string) $c['Id'];
            $containers[] = [
                'service_name' => (string) ($labels['com.docker.compose.service'] ?? 'unknown'),
                'container_name' => (string) trim($first, '/'),
                'image' => (string) ($c['Image'] ?? ''),
                'internal_port' => $ports[0]['container'] ?? null,
                'host_port' => $ports[0]['host'] ?? null,
                'status' => (string) ($c['State'] ?? 'unknown'),
            ];
        }
        return $containers;
    }

    public function renderNginxConfig(array $site): string
    {
        $hostPort = $this->primaryHostPort($site);
        $subdomain = site_subdomain($site['name']);
        $customDomain = (string) ($site['custom_domain'] ?? '');

        // Tanpa custom domain: subdomain melayani app (perilaku default).
        if ($customDomain === '') {
            $ssl = ($site['ssl_status'] ?? null) === 'active';
            return $this->nginx->render($hostPort, [
                ['server_name' => $subdomain, 'ssl' => $ssl],
            ]);
        }

        // Dengan custom domain: subdomain redirect (301) ke custom domain,
        // custom domain melayani app. Redirect pakai https bila SSL custom aktif,
        // selain itu http agar tidak memutus akses sebelum cert terpasang.
        $customSsl = ($site['custom_ssl_status'] ?? null) === 'active';
        $target = ($customSsl ? 'https://' : 'http://') . $customDomain;

        return $this->nginx->render($hostPort, [
            ['server_name' => $subdomain, 'redirect_to' => $target],
            ['server_name' => $customDomain, 'ssl' => $customSsl],
        ]);
    }

    public function writeNginxConfig(array $site): void
    {
        $this->nginx->write($site['name'], $this->renderNginxConfig($site));
    }

    public function removeNginxConfig(array $site): void
    {
        $this->nginx->remove($site['name']);
    }

    /**
     * Tambahkan entri ke deploy_history site (dipertahankan max 20 entri).
     * Entry dengan status sukses/restored menjadi kandidat rollback.
     *
     * @return array site yang sudah diperbarui (deploy_history ditambah)
     */
    private function recordHistory(array $site, string $sha, string $action, string $status, string $message = ''): array
    {
        $history = $site['deploy_history'] ?? [];
        $history[] = [
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'created_at' => date('c'),
        ];
        $site['deploy_history'] = array_slice($history, -20);
        return $site;
    }

    private function siteDir(array $site): string
    {
        return $this->sitesPath . '/' . $site['name'];
    }

    /**
     * Nama-nama named volume milik project compose (deteksi via Engine API).
     *
     * @return array<int,string>
     */
    private function volumeNamesForProject(string $project): array
    {
        $volumes = $this->dockerClient->listVolumesForProject($project);
        return array_values(array_filter(array_map(
            static fn (array $v): string => (string) ($v['Name'] ?? ''),
            $volumes
        ), static fn (string $name): bool => $name !== ''));
    }

    /**
     * Path private key SSH deploy key site, atau null untuk repo publik.
     * Site menyimpan path relatif (mis. "keys/{name}") terhadap database_path.
     */
    private function siteSshKeyPath(array $site): ?string
    {
        if (($site['auth_method'] ?? 'none') !== 'ssh') {
            return null;
        }
        $key = (string) ($site['ssh_key'] ?? '');
        if ($key === '') {
            return null;
        }
        $path = (string) config('deploy.database_path') . '/' . $key;
        return is_file($path) ? $path : null;
    }

    private function primaryHostPort(array $site): int
    {
        $primary = $site['primary_service'] ?? null;
        foreach ($site['containers'] ?? [] as $c) {
            if ($primary === null || $c['service_name'] === $primary) {
                if (($c['host_port'] ?? null) !== null) {
                    return (int) $c['host_port'];
                }
            }
        }
        throw new RuntimeException('Tidak ada host port untuk primary service site "' . ($site['name'] ?? '') . '".');
    }
}
