<?php
declare(strict_types=1);

namespace Tests;

use app\controller\app\BranchController;
use app\library\Billing\BillingGate;
use app\library\Branch\BranchChangeService;
use app\library\Deploy\AppBuildService;
use app\library\Deploy\DeploySpawner;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Auth\UserStore;
use PHPUnit\Framework\TestCase;
use support\Request;
use Tests\Support\StoreDbFiles;
use Webman\Http\Response;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

final class BranchControllerHarness extends BranchController
{
    /** @var array<string,mixed> */
    public array $app = [];
    public string $foundAbility = '';
    public int $billingCalls = 0;
    public ?BranchChangeService $service = null;

    protected function findApp(string $id, string $ability): array
    {
        $this->foundAbility = $ability;

        return $this->app;
    }

    protected function billingAssertCanStart(
        array $app,
        ?array $user,
        ?BillingGate $gate = null,
        ?UserStore $users = null
    ): void {
        $this->billingCalls++;
    }

    protected function currentActorId(): string
    {
        return 'controller-actor';
    }

    protected function branchChangeService(): BranchChangeService
    {
        return $this->service ?? new BranchChangeService();
    }
}

class BranchControllerTest extends TestCase
{
    private string $db = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rames-branch-controller-' . bin2hex(random_bytes(5));
        $this->db = $this->root . '.sqlite';
        mkdir($this->root, 0777, true);
        mkdir($this->root . '/apps/demo', 0777, true);
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
        $this->removeTree($this->root);
    }

    public function testControllerRequiresDeployAbilityAndReturnsAgreedJsonSuccess(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store);
        $spawned = [];
        $controller = $this->controller($app, $store, $spawned);

        $response = $controller->change($this->post('/apps/' . $app['id'] . '/branch', ['branch' => 'release']), $app['id']);

        self::assertSame('deploy', $controller->foundAbility);
        self::assertSame(1, $controller->billingCalls);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'code' => 0,
            'status' => 'deploying',
            'stage' => 'queued',
            'message' => 'Perpindahan branch release dan deploy dimulai.',
        ], json_decode($response->rawBody(), true));
        self::assertSame(BranchChangeService::MODE, $spawned[0][3]);
        self::assertSame('release', $spawned[0][4]);
        self::assertSame('main', $store->find($app['id'])['branch']);
    }

    public function testControllerReturnsBusyJsonAndDoesNotSpawnDuplicateWorker(): void
    {
        $store = new AppStore($this->db);
        $app = $this->app($store);
        $store->update($app['id'], static function (array &$state): void {
            $state['status'] = 'deploying';
        });
        $spawned = [];
        $controller = $this->controller($app, $store, $spawned);

        $response = $controller->change($this->post('/apps/' . $app['id'] . '/branch', ['branch' => 'release']), $app['id']);
        $body = json_decode($response->rawBody(), true);

        self::assertSame('deploy', $controller->foundAbility);
        self::assertSame(0, $controller->billingCalls);
        self::assertSame(1, $body['code']);
        self::assertTrue($body['busy']);
        self::assertArrayHasKey('error', $body);
        self::assertSame([], $spawned);
    }

    /**
     * @param array<string,mixed> $app
     * @param array<int,array<int,string>> $spawned
     */
    private function controller(array $app, AppStore $store, array &$spawned): BranchControllerHarness
    {
        $launcher = new DeployWorkerLauncher(
            static function (array $argv, ?int &$pid = null, array $env = []) use (&$spawned): bool {
                $spawned[] = $argv;
                $pid = 6789;

                return true;
            },
            $this->root . '/cli/deploy.php',
            $this->root . '/logs'
        );
        $servers = new ServerStore($this->db);
        $service = new BranchChangeService(
            $store,
            new DeploySpawner($launcher, $store),
            new AppBuildService(
                $store,
                null,
                $this->root . '/apps',
                new ServerResolver($servers)
            )
        );
        $controller = new BranchControllerHarness();
        $controller->app = $app;
        $controller->service = $service;

        return $controller;
    }

    /** @param array<string,mixed> $extra */
    private function app(AppStore $store, array $extra = []): array
    {
        return $store->create($extra + [
            'name' => 'demo',
            'owner_id' => 'u1',
            'source' => 'git',
            'repo_url' => 'https://example.test/team/demo.git',
            'branch' => 'main',
            'status' => 'running',
        ]);
    }

    /** @param array<string,string> $fields */
    private function post(string $path, array $fields): Request
    {
        $body = http_build_query($fields);

        return new Request(
            "POST {$path} HTTP/1.1\r\nHost: dashboard.test\r\nAccept: application/json\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n"
            . $body
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
