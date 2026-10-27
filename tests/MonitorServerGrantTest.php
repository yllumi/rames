<?php
declare(strict_types=1);

namespace Tests;

use app\controller\MonitorController;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `/api/monitor/overview` tidak boleh mengirim metrik **host** server yang hanya
 * "boleh dipilih" karena grant (`server_select`): non-admin hanya melihat
 * server yang menaungi minimal satu app yang boleh ia lihat.
 *
 * Kelas ini menguji penyaringan murni `visibleAgentServers()` (tanpa Engine,
 * jaringan, atau HTTP) memakai registry SQLite temp.
 */
class MonitorServerGrantTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];
    private const GRANT_A = ['id' => 'u3', 'username' => 'tono', 'role' => 'member', 'server_select' => true, 'server_ids' => ['srv-a']];
    private const GRANT_ALL = ['id' => 'u4', 'username' => 'sinta', 'role' => 'member', 'server_select' => 'on', 'server_ids' => []];

    private string $tmp;
    private ServerStore $servers;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/rames-monitor-grant-' . bin2hex(random_bytes(5)) . '.sqlite';
        $this->servers = new ServerStore($this->tmp);
        $this->servers->local();
        foreach (['srv-a', 'srv-b'] as $id) {
            $this->servers->create([
                'id' => $id,
                'name' => $id,
                'kind' => 'agent',
                'enabled' => true,
                'base_url' => 'https://' . $id . '.internal.test:8443',
                'token' => 'tok-' . $id,
                'status' => 'online',
                'capabilities' => ['deploy'],
            ]);
        }
        ServerResolver::forgetMemo();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        foreach (glob($this->tmp . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function controller(?array $user): TestableMonitorGrantController
    {
        return new TestableMonitorGrantController($user, new ServerResolver($this->servers));
    }

    /**
     * @param array<int,array<string,mixed>> $visibleApps
     * @return array<int,string>
     */
    private function visibleServerIds(?array $user, array $visibleApps): array
    {
        $method = new ReflectionMethod(MonitorController::class, 'visibleAgentServers');
        $method->setAccessible(true);

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $method->invoke($this->controller($user), $visibleApps);

        return array_column($rows, 'id');
    }

    public function testGrantedServerWithoutAnyVisibleAppIsNotMonitored(): void
    {
        $this->assertSame(
            [],
            $this->visibleServerIds(self::GRANT_A, []),
            'grant/allowlist boleh membuka /networks dsb., tetapi metrik host tidak'
        );
        $this->assertSame([], $this->visibleServerIds(self::GRANT_ALL, []));
    }

    public function testGrantedServerAppearsOnlyAfterHoldingAVisibleApp(): void
    {
        $app = ['id' => 'a1', 'name' => 'app-a', 'owner_id' => 'u3', 'server_id' => 'srv-a'];

        $this->assertSame(['srv-a'], $this->visibleServerIds(self::GRANT_A, [$app]));
        $this->assertSame(
            ['srv-a'],
            $this->visibleServerIds(self::GRANT_ALL, [$app]),
            'grant allowlist kosong tetap tidak membuka srv-b tanpa app'
        );
    }

    public function testPlainMemberAndAdminBehaviourIsUnchanged(): void
    {
        $app = ['id' => 'a1', 'name' => 'app-a', 'owner_id' => 'u2', 'server_id' => 'srv-a'];

        $this->assertSame(['srv-a'], $this->visibleServerIds(self::MEMBER, [$app]));
        $this->assertSame([], $this->visibleServerIds(self::MEMBER, []));
        $this->assertSame(
            ['srv-a', 'srv-b'],
            $this->visibleServerIds(self::ADMIN, []),
            'admin tetap melihat seluruh server agent'
        );
    }
}

/** `MonitorController` dengan seam user/registry (tanpa Engine/HTTP). */
final class TestableMonitorGrantController extends MonitorController
{
    public function __construct(
        private ?array $user,
        private ServerResolver $resolver,
    ) {
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function serverResolver(): ServerResolver
    {
        return $this->resolver;
    }
}
