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
        private readonly string $appDomain,
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
        return $site;
    }

    public function rebuild(array $site, callable $logger): array
    {
        $this->nginx->ensureWritable();

        $project = $site['name'];
        $dir = $this->siteDir($site);
        $files = $site['compose_files'] ?? ['docker-compose.yml'];

        $logger('pull', 'git pull --ff-only ...');
        (new GitService())->pull($dir, $site['branch'] ?? 'main', $this->siteSshKeyPath($site));

        $logger('build', 'docker compose up -d --build ...');
        $this->compose->up($project, $dir, $files, true);

        $site['containers'] = $this->getContainers($project);
        $this->writeNginxConfig($site);

        $site['status'] = 'running';
        $logger('done', 'Rebuild selesai.');
        return $site;
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

    public function teardown(array $site): void
    {
        $this->compose->down($site['name'], $this->siteDir($site), $site['compose_files'] ?? ['docker-compose.yml'], true);
        $this->removeNginxConfig($site);
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
        $ssl = ($site['ssl_status'] ?? null) === 'active';
        return $this->nginx->render($site['name'], $this->appDomain, $hostPort, $ssl);
    }

    public function writeNginxConfig(array $site): void
    {
        $this->nginx->write($site['name'], $this->renderNginxConfig($site));
    }

    public function removeNginxConfig(array $site): void
    {
        $this->nginx->remove($site['name']);
    }

    private function siteDir(array $site): string
    {
        return $this->sitesPath . '/' . $site['name'];
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
