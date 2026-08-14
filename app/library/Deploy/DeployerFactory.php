<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\DockerClient;
use app\library\Docker\DockerComposeRunner;
use app\library\Nginx\NginxConfigGenerator;

/**
 * Factory deployer — satu-satunya titik pembuatan DeployerInterface.
 * Untuk fase berikutnya (agent HTTP multi-server), ganti implementasi di sini
 * tanpa mengubah controller.
 */
class DeployerFactory
{
    public static function create(): DeployerInterface
    {
        return new LocalDeployer(
            compose: new DockerComposeRunner(timeout: (int) config('deploy.deploy_timeout', 600)),
            dockerClient: new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock')),
            nginx: new NginxConfigGenerator(
                confPath: (string) config('deploy.nginx_conf_path', '/etc/nginx/sites-available'),
                enabledPath: (string) config('deploy.nginx_enabled_path', ''),
            ),
            sitesPath: (string) config('deploy.sites_path'),
            appDomain: (string) config('deploy.app_domain', 'example.com'),
        );
    }
}
