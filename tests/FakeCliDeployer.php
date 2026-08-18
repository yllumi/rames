<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\DeployerInterface;
use RuntimeException;

/**
 * Fake DeployerInterface untuk test subproses cli/deploy.php — tidak menyentuh
 * Docker. Diaktifkan via env DEPLOYER_CLASS (lihat DeployerFactory).
 *
 * Mode rollback bisa dipaksa gagal lewat env FAKE_DEPLOYER_FAIL.
 */
class FakeCliDeployer implements DeployerInterface
{
    public static function create(): DeployerInterface
    {
        return new self();
    }

    public function deploy(array $app, callable $logger): array
    {
        return $this->finish($app, 'deploy');
    }

    public function rebuild(array $app, callable $logger): array
    {
        return $this->finish($app, 'rebuild');
    }

    public function rollback(array $app, string $ref, callable $logger): array
    {
        if ((string) getenv('FAKE_DEPLOYER_FAIL') !== '') {
            throw new RuntimeException('fake deployer gagal (test)');
        }
        $logger('rollback', "Rollback ke {$ref} ...");
        $app['deploy_history'] = $app['deploy_history'] ?? [];
        $app['deploy_history'][] = [
            'sha' => $ref,
            'short' => substr($ref, 0, 7),
            'action' => 'rollback',
            'status' => 'success',
            'created_at' => date('c'),
        ];
        return $this->finish($app, 'rollback');
    }

    public function stop(array $app): void
    {
    }

    public function start(array $app): void
    {
    }

    public function applyEnv(array $app, callable $logger): array
    {
        return $this->finish($app, 'apply-env');
    }

    public function teardown(array $app, ?array $preserveVolumes = null): void
    {
    }

    public function getProjectVolumes(string $project): array
    {
        return [];
    }

    public function getContainers(string $project): array
    {
        return [];
    }

    public function ensureWritable(): void
    {
    }

    public function renderNginxConfig(array $app): string
    {
        return 'mock';
    }

    public function writeNginxConfig(array $app): void
    {
    }

    public function removeNginxConfig(array $app): void
    {
    }

    private function finish(array $app, string $action): array
    {
        $app['status'] = 'running';
        $app['stage'] = null;
        $app['message'] = 'Running';
        $app['error'] = null;
        $app['containers'] = [];
        return $app;
    }
}
