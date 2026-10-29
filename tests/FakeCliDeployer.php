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
 *
 * Setiap pemanggilan method dicatat ke berkas `FAKE_DEPLOYER_LOG` (bila env itu
 * diisi) sehingga test subproses bisa memverifikasi **method & argumen** yang
 * benar-benar dipanggil `cli/deploy.php` (mis. mode `teardown` dengan daftar
 * `preserve_volumes` dari payload job agent). Statik kelas tidak bisa dibaca
 * lintas proses, jadi jejaknya lewat berkas.
 */
class FakeCliDeployer implements DeployerInterface
{
    public static function create(): DeployerInterface
    {
        return new self();
    }

    /** Catat jejak pemanggilan (tanpa efek bila `FAKE_DEPLOYER_LOG` kosong). */
    private function note(string $entry): void
    {
        $path = (string) getenv('FAKE_DEPLOYER_LOG');
        if ($path !== '') {
            @file_put_contents($path, $entry . "\n", FILE_APPEND);
        }
    }

    public function deploy(array $app, callable $logger): array
    {
        $this->note('deploy');

        if ((string) getenv('FAKE_DEPLOYER_OUTPUT') !== '') {
            $logger('output', "Step 1/2 : FROM alpine\n");
            $logger('output', 'Step 2/2 : RUN echo hi');
        }

        return $this->finish($app, 'deploy');
    }

    public function rebuild(array $app, callable $logger): array
    {
        $this->note('rebuild');

        return $this->finish($app, 'rebuild');
    }

    public function rollback(array $app, string $ref, callable $logger): array
    {
        $this->note('rollback:' . $ref);
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
        $this->note('stop');
    }

    public function start(array $app): void
    {
        $this->note('start');
    }

    public function apply(array $app, callable $logger): array
    {
        $this->note('apply');

        return $this->finish($app, 'apply');
    }

    public function applyEnv(array $app, callable $logger): array
    {
        $this->note('apply-env');

        return $this->finish($app, 'apply-env');
    }

    public function applyLimits(array $app, callable $logger): array
    {
        $this->note('apply-limits');

        return $this->finish($app, 'apply-limits');
    }

    /**
     * @param array<int,string>|null $preserveVolumes
     */
    public function teardown(array $app, ?array $preserveVolumes = null, ?callable $logger = null): void
    {
        $this->note('teardown:' . json_encode($preserveVolumes));
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
        $this->note('nginx-ensure-writable');
    }

    public function renderNginxConfig(array $app): string
    {
        $this->note('nginx-render');

        return 'mock';
    }

    public function writeNginxConfig(array $app): void
    {
        $this->note('nginx-write');
    }

    public function removeNginxConfig(array $app): void
    {
        $this->note('nginx-remove');
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
