<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Git\GitService;
use app\library\Nginx\NginxConfigGenerator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

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
        private readonly EnvManager $env,
    ) {
    }

    public function deploy(array $site, callable $logger): array
    {
        // fail-fast: cek prasyarat tulis Nginx SEBELUM build yang lama
        $this->nginx->ensureWritable();

        $project = $site['name'];
        $dir = $this->siteDir($site);
        $files = $this->resolveComposeFiles($site, $dir);

        // Sinkronkan managed env file + override env (idempoten, aman bila kosong)
        $this->env->sync($site, $dir, $files);

        $logger('build', 'Menjalankan docker compose up -d --build ...');
        $this->compose->up($project, $dir, $files, true, $this->siteEnvFile($site));

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
        $files = $this->resolveComposeFiles($site, $dir);
        $branch = $site['branch'] ?? 'main';
        $git = new GitService();

        $logger('pull', 'git pull --ff-only ...');
        // Re-attach branch dulu bila HEAD detached (sisa rollback), agar pull valid.
        $git->ensureBranch($dir, $branch);
        $git->pull($dir, $branch, $this->siteSshKeyPath($site));

        // Sinkronkan managed env file + override env (idempoten, aman bila kosong)
        $this->env->sync($site, $dir, $files);

        $logger('build', 'docker compose up -d --build ...');
        $this->compose->up($project, $dir, $files, true, $this->siteEnvFile($site));

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
        $files = $this->resolveComposeFiles($site, $dir);
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

            // Sinkronkan managed env file + override env (idempoten, aman bila kosong)
            $this->env->sync($site, $dir, $files);

            $logger('build', 'docker compose up -d --build ...');
            $this->compose->up($project, $dir, $files, true, $this->siteEnvFile($site));

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
                $this->compose->up($project, $dir, $files, true, $this->siteEnvFile($site));
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
        $dir = $this->siteDir($site);
        $this->compose->stop($site['name'], $dir, $this->resolveComposeFiles($site, $dir), $this->siteEnvFile($site));
    }

    public function ensureWritable(): void
    {
        $this->nginx->ensureWritable();
    }

    public function start(array $site): void
    {
        $dir = $this->siteDir($site);
        $this->compose->start($site['name'], $dir, $this->resolveComposeFiles($site, $dir), $this->siteEnvFile($site));
    }

    /**
     * Terapkan perubahan environment variable tanpa rebuild source:
     * tulis ulang managed env file + override, lalu `docker compose up -d`
     * (tanpa --build) — compose menciptakan ulang hanya container yang
     * environment-nya berubah.
     */
    public function applyEnv(array $site, callable $logger): array
    {
        $project = $site['name'];
        $dir = $this->siteDir($site);
        $files = $this->resolveComposeFiles($site, $dir);

        $this->env->sync($site, $dir, $files);

        $logger('build', 'Menciptakan ulang container dengan environment baru ...');
        $this->compose->up($project, $dir, $files, false, $this->siteEnvFile($site));

        $site['containers'] = $this->getContainers($project);
        $site['status'] = 'running';
        $logger('done', 'Environment diterapkan.');
        return $site;
    }

    public function teardown(array $site, ?array $preserveVolumes = null): void
    {
        $project = $site['name'];
        $dir = $this->siteDir($site);
        $files = $this->resolveComposeFiles($site, $dir);

        // Hapus project lewat docker compose. Bila project TIDAK bisa dimuat
        // (mis. override stale mereferensikan service yang sudah tidak ada di
        // docker-compose.yml setelah git pull/rollback → "service X has neither
        // an image nor a build context specified"), `down` melempar exception;
        // lanjut ke pembersihan manual via Engine API di bawah.
        try {
            $this->compose->down($project, $dir, $files, $preserveVolumes === null, $this->siteEnvFile($site));
        } catch (\Throwable $e) {
            // abaikan — teardownViaApi() menyelesaikan sisanya.
        }

        // Sapu bersih sisa container & network project via Engine API (termasuk
        // container orphan yang sudah tidak ada di config compose saat ini) dan
        // volume bila mode purge. Idempoten — hanya menghapus yang masih ada.
        $this->teardownViaApi($project, $preserveVolumes === null);

        if ($preserveVolumes !== null) {
            // Pertahankan volume terpilih: down tanpa -v, lalu hapus hanya volume
            // project yang TIDAK dipertahankan. Volume yang dipertahankan akan
            // dipakai ulang saat site dibuat ulang dengan nama sama (project = nama site).
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
     * Daftar compose_files site dengan perbaikan override stale bila direktori
     * site ada. Lihat repairStaleOverrides().
     *
     * @return array<int,string>
     */
    private function resolveComposeFiles(array $site, string $dir): array
    {
        $files = $site['compose_files'] ?? ['docker-compose.yml'];
        if (is_dir($dir)) {
            $this->repairStaleOverrides($dir, $files);
        }
        return $files;
    }

    /**
     * Perbaiki override file yang stale: mereferensikan service yang sudah
     * tidak ada di base docker-compose.yml (terjadi saat repo berubah lewat
     * git pull/rollback). Compose menolak project bila override berisi service
     * tanpa image/build ("service X has neither an image nor a build context
     * specified: invalid compose project").
     *
     * Hanya menyaring isi file (service tak valid dibuang); file TIDAK pernah
     * dihapus sehingga daftar compose_files tetap valid.
     *
     * @param array<int,string> $files
     */
    private function repairStaleOverrides(string $dir, array $files): void
    {
        // base compose = file pertama yang bukan override (mis. docker-compose.yml)
        $baseFile = null;
        foreach ($files as $f) {
            if (!str_starts_with($f, 'docker-compose.override')) {
                $baseFile = $f;
                break;
            }
        }
        if ($baseFile === null || !is_file($dir . '/' . $baseFile)) {
            return;
        }
        try {
            $base = Yaml::parseFile($dir . '/' . $baseFile, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable) {
            return;
        }
        $valid = array_keys(is_array($base['services'] ?? null) ? $base['services'] : []);
        if ($valid === []) {
            return;
        }

        foreach ($files as $f) {
            if (!str_starts_with($f, 'docker-compose.override')) {
                continue;
            }
            $path = $dir . '/' . $f;
            if (!is_file($path)) {
                continue;
            }
            try {
                $data = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable) {
                continue;
            }
            $svc = $data['services'] ?? [];
            if (!is_array($svc)) {
                continue;
            }
            $filtered = array_intersect_key($svc, array_flip($valid));
            if ($filtered === $svc) {
                continue; // tidak ada service stale
            }
            $data['services'] = $filtered;
            @file_put_contents($path, Yaml::dump($data, 4, 2), LOCK_EX);
        }
    }

    /**
     * Teardown manual project via Docker Engine API (tanpa compose file).
     * Dipakai sebagai fallback saat compose project tidak bisa dimuat, dan
     * sebagai sapuan pembersih sisa container orphan setelah down.
     */
    private function teardownViaApi(string $project, bool $removeVolumes): void
    {
        // 1) Stop & hapus semua container project.
        foreach ($this->dockerClient->listContainersForProject($project) as $c) {
            $id = (string) ($c['Id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (($c['State'] ?? '') === 'running') {
                $this->dockerClient->stopContainer($id);
            }
            $this->dockerClient->removeContainer($id, true);
        }

        // 2) Hapus network project.
        foreach ($this->dockerClient->listNetworksForProject($project) as $n) {
            $id = (string) ($n['Id'] ?? ($n['Name'] ?? ''));
            if ($id === '') {
                continue;
            }
            $this->dockerClient->removeNetwork($id);
        }

        // 3) Hapus volume project (hanya mode purge — preserve dikelola pemanggil).
        if ($removeVolumes) {
            $names = $this->volumeNamesForProject($project);
            if ($names !== []) {
                $this->compose->removeVolumes($names);
            }
        }
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
     * Path managed env file site bila site punya env vars (file sudah ditulis),
     * atau null. Dipakai sebagai argumen `--env-file` docker compose.
     */
    private function siteEnvFile(array $site): ?string
    {
        if (empty($site['env'])) {
            return null;
        }
        $path = $this->env->managedPath((string) $site['name']);
        return is_file($path) ? $path : null;
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
