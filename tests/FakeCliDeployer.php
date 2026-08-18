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

    public function deploy(array $site, callable $logger): array
    {
        return $this->finish($site, 'deploy');
    }

    public function rebuild(array $site, callable $logger): array
    {
        return $this->finish($site, 'rebuild');
    }

    public function rollback(array $site, string $ref, callable $logger): array
    {
        if ((string) getenv('FAKE_DEPLOYER_FAIL') !== '') {
            throw new RuntimeException('fake deployer gagal (test)');
        }
        $logger('rollback', "Rollback ke {$ref} ...");
        $site['deploy_history'] = $site['deploy_history'] ?? [];
        $site['deploy_history'][] = [
            'sha' => $ref,
            'short' => substr($ref, 0, 7),
            'action' => 'rollback',
            'status' => 'success',
            'created_at' => date('c'),
        ];
        return $this->finish($site, 'rollback');
    }

    public function stop(array $site): void
    {
    }

    public function start(array $site): void
    {
    }

    public function teardown(array $site, ?array $preserveVolumes = null): void
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

    public function renderNginxConfig(array $site): string
    {
        return 'mock';
    }

    public function writeNginxConfig(array $site): void
    {
    }

    public function removeNginxConfig(array $site): void
    {
    }

    private function finish(array $site, string $action): array
    {
        $site['status'] = 'running';
        $site['stage'] = null;
        $site['message'] = 'Running';
        $site['error'] = null;
        $site['containers'] = [];
        return $site;
    }
}
