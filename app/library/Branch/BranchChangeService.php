<?php
declare(strict_types=1);

namespace app\library\Branch;

use app\library\Deploy\AppBuildService;
use app\library\Deploy\DeploySpawner;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\ImportedApp;
use app\library\Deploy\ServerResolver;
use app\library\Git\GitService;
use app\library\Storage\AppStore;
use RuntimeException;

/**
 * Preflight and enqueue a Git branch switch followed by a deployment.
 *
 * The worker owns checkout/build/rollback and only persists the target branch
 * after successful deployment. This service deliberately leaves `branch`
 * metadata untouched.
 */
final class BranchChangeService
{
    public const MODE = 'switch_branch';
    public const BUSY_MESSAGE = 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.';
    public const SPAWN_FAILED_MESSAGE = 'Gagal menjalankan perpindahan branch.';

    private readonly ?AppStore $store;
    private readonly ?DeploySpawner $spawner;
    private readonly ?AppBuildService $builds;

    public function __construct(
        ?AppStore $store = null,
        ?DeploySpawner $spawner = null,
        ?AppBuildService $builds = null,
    ) {
        $this->store = $store;
        $this->spawner = $spawner;
        $this->builds = $builds;
    }

    /**
     * Validate, preflight, and queue a branch switch worker.
     *
     * @param array<string,mixed> $app
     * @param callable():void|null $guard billing callback; runs before app state changes
     * @return array{id:string,status:string,stage:string,message:string}
     */
    public function change(array $app, mixed $branch, ?callable $guard = null, string $actorId = ''): array
    {
        $id = trim((string) ($app['id'] ?? ''));
        if ($id === '') {
            throw new BranchChangeBlocked('App tidak memiliki id — tidak bisa mengganti branch.');
        }

        $branch = self::validatedBranch($branch);

        if (ImportedApp::isImported($app)) {
            throw new BranchChangeBlocked(ImportedApp::manageableError($app, 'Ganti branch'));
        }
        if (!ComposeSource::isGit($app) || trim((string) ($app['repo_url'] ?? '')) === '') {
            throw new BranchChangeBlocked('Perubahan branch hanya tersedia untuk app yang dibuat dari Git.');
        }
        if (($app['status'] ?? '') === 'deploying') {
            throw new BranchChangeBlocked(self::BUSY_MESSAGE, true);
        }

        $artifactError = ($this->builds ?? new AppBuildService())->artifactsError($app, 'Ganti branch');
        if ($artifactError !== null) {
            throw new BranchChangeBlocked($artifactError);
        }

        if ($guard !== null) {
            $guard();
        }

        // Claim the app atomically so concurrent requests cannot enqueue
        // multiple switch/deploy workers based on the same stale app snapshot.
        $store = $this->store ?? new AppStore();
        $store->update($id, static function (array &$state): void {
            if (($state['status'] ?? '') === 'deploying') {
                throw new BranchChangeBlocked(self::BUSY_MESSAGE, true);
            }
            $state['status'] = 'deploying';
            $state['stage'] = 'queued';
            $state['message'] = 'Menunggu worker perpindahan branch ...';
            $state['error'] = null;
        });

        $spawner = $this->spawner ?? new DeploySpawner(null, $store);
        if (!$spawner->spawn($id, self::MODE, $branch, $actorId)) {
            $store->update($id, static function (array &$state): void {
                $state['status'] = 'error';
                $state['stage'] = null;
                $state['message'] = 'Gagal menjalankan worker perpindahan branch.';
                $state['error'] = 'Gagal spawn worker perpindahan branch.';
            });
            throw new BranchChangeBlocked(self::SPAWN_FAILED_MESSAGE);
        }

        return [
            'id' => $id,
            'status' => 'deploying',
            'stage' => 'queued',
            'message' => 'Perpindahan branch ' . $branch . ' dan deploy dimulai.',
        ];
    }

    /**
     * Strict request validation before the branch is passed as a worker argv.
     */
    private static function validatedBranch(mixed $branch): string
    {
        if (!is_string($branch)) {
            throw new BranchChangeBlocked('Nama branch tidak valid.');
        }

        try {
            return GitService::validateBranchName($branch);
        } catch (RuntimeException $e) {
            throw new BranchChangeBlocked('Nama branch tidak valid: ' . $e->getMessage(), false);
        }
    }
}
