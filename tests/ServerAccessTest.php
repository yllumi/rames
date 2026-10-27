<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use PHPUnit\Framework\TestCase;

/**
 * Otorisasi registry server (plan §4.2 & keputusan user §9.3).
 *
 * Kelola registry tetap **admin-only** (penolakan 404, bukan 403), sedangkan
 * **memilih** server boleh diberikan admin per user lewat flag
 * `server_select` (+ allowlist `server_ids`). Kelas ini statik murni (tanpa
 * store/HTTP), jadi uji cukup matriks role & flag — tidak menyentuh data
 * runtime apa pun.
 */
class ServerAccessTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => AppAccess::ROLE_ADMIN];
    private const MEMBER = ['id' => 'u2', 'username' => 'member', 'role' => 'member'];
    private const OWNER = ['id' => 'u3', 'username' => 'owner', 'role' => 'member'];
    private const OPERATOR = ['id' => 'u4', 'username' => 'operator', 'role' => 'member'];
    private const VIEWER = ['id' => 'u5', 'username' => 'viewer', 'role' => 'member'];

    /**
     * Role non-admin: member biasa, pemilik app, operator app, viewer app,
     * “stranger” (role di luar daftar) dan tamu (null).
     *
     * @return array<string,array{0:?array}>
     */
    public static function nonAdminUsers(): array
    {
        return [
            'member' => [self::MEMBER],
            'owner' => [self::OWNER],
            'operator' => [self::OPERATOR],
            'viewer' => [self::VIEWER],
            'stranger' => [['id' => 'u9', 'username' => 'stranger', 'role' => 'stranger']],
            'anonymous' => [null],
        ];
    }

    // ------------------------------------------------------------------
    // canManageServers / requireManageServers
    // ------------------------------------------------------------------

    public function testAdminCanManageServers(): void
    {
        $this->assertTrue(ServerAccess::canManageServers(self::ADMIN));
        $this->assertTrue(ServerAccess::canSelectServer(self::ADMIN));
    }

    /**
     * @dataProvider nonAdminUsers
     */
    public function testNonAdminCannotManageServers(?array $user): void
    {
        $this->assertFalse(ServerAccess::canManageServers($user));
    }

    /**
     * @dataProvider nonAdminUsers
     */
    public function testRequireManageServersThrowsNotFoundForNonAdmin(?array $user): void
    {
        try {
            ServerAccess::requireManageServers($user);
            self::fail('non-admin seharusnya ditolak');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode(), 'penolakan harus 404 (bukan 403)');
            $this->assertSame(ServerAccess::ABILITY_MANAGE, $e->ability);
            $this->assertNull($e->appId, 'ability server bukan ability per-app');
        }
    }

    public function testRequireManageServersPassesForAdmin(): void
    {
        ServerAccess::requireManageServers(self::ADMIN);
        $this->addToAssertionCount(1); // sampai sini = tidak ada exception
    }

    // ------------------------------------------------------------------
    // canSelectServer
    // ------------------------------------------------------------------

    /**
     * @dataProvider nonAdminUsers
     */
    public function testNonAdminCannotSelectServer(?array $user): void
    {
        $this->assertFalse(ServerAccess::canSelectServer($user));
    }

    // ------------------------------------------------------------------
    // visibleServers
    // ------------------------------------------------------------------

    public function testAdminSeesEveryServer(): void
    {
        $visible = ServerAccess::visibleServers($this->servers(), self::ADMIN, $this->apps());

        $this->assertSame(['s1', 's2', 's3'], array_column($visible, 'id'));
    }

    public function testAdminSeesServersEvenWithoutAnyApp(): void
    {
        $visible = ServerAccess::visibleServers($this->servers(), self::ADMIN, []);

        $this->assertCount(3, $visible);
    }

    public function testMemberSeesOnlyServersHostingTheirVisibleApps(): void
    {
        // OWNER memiliki app di s2 (dan anggota app di s3 sebagai viewer).
        $visible = ServerAccess::visibleServers($this->servers(), self::OWNER, $this->apps());

        $this->assertSame(['s2', 's3'], array_column($visible, 'id'));
    }

    public function testViewerOfOneAppSeesOnlyThatAppsServer(): void
    {
        $visible = ServerAccess::visibleServers($this->servers(), self::VIEWER, $this->apps());

        $this->assertSame(['s2'], array_column($visible, 'id'));
    }

    public function testOperatorSeesServerOfOperatedApp(): void
    {
        $visible = ServerAccess::visibleServers($this->servers(), self::OPERATOR, $this->apps());

        $this->assertSame(['s3'], array_column($visible, 'id'));
    }

    public function testMemberWithoutVisibleAppSeesNothing(): void
    {
        // MEMBER tidak punya app sama sekali; app milik orang lain tidak boleh
        // "membuka" visibilitas server (jangan menambah kuasa baru).
        $visible = ServerAccess::visibleServers($this->servers(), self::MEMBER, $this->apps());

        $this->assertSame([], $visible);
    }

    /**
     * @dataProvider nonAdminUsers
     */
    public function testNonAdminNeverGetsFullServerList(?array $user): void
    {
        $visible = ServerAccess::visibleServers($this->servers(), $user, []);

        $this->assertSame([], $visible, 'tanpa app terlihat, daftar server harus kosong (fail-closed)');
    }

    public function testUnknownServerIdsAreIgnored(): void
    {
        $apps = [
            ['id' => 'a9', 'owner_id' => 'u3', 'server_id' => 's404'],
            ['id' => 'a8', 'owner_id' => 'u3', 'server_id' => ''],
        ];

        $this->assertSame([], ServerAccess::visibleServers($this->servers(), self::OWNER, $apps));
    }

    public function testDuplicateAppsOnSameServerYieldSingleServer(): void
    {
        $apps = [
            ['id' => 'a1', 'owner_id' => 'u3', 'server_id' => 's2'],
            ['id' => 'a2', 'owner_id' => 'u3', 'server_id' => 's2'],
        ];
        $servers = $this->servers();

        $this->assertSame(['s2'], array_column(ServerAccess::visibleServers($servers, self::OWNER, $apps), 'id'));
        $this->assertCount(3, ServerAccess::visibleServers($servers, self::ADMIN, $apps));
    }

    public function testVisibleServersIsStatelessBetweenCalls(): void
    {
        $first = ServerAccess::visibleServers($this->servers(), self::OWNER, $this->apps());
        $second = ServerAccess::visibleServers($this->servers(), self::MEMBER, $this->apps());

        $this->assertNotSame([], $first);
        $this->assertSame([], $second, 'panggilan sebelumnya tidak boleh meninggalkan state');
    }

    // ------------------------------------------------------------------
    // Grant pemilihan server per user (server_select + server_ids)
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function grantUser(array $allowlist = [], mixed $flag = true): array
    {
        return [
            'id' => 'u6',
            'username' => 'budi',
            'role' => 'member',
            'server_select' => $flag,
            'server_ids' => $allowlist,
        ];
    }

    public function testGrantFlagEnablesSelectionWithoutOpeningManagement(): void
    {
        $user = $this->grantUser();

        $this->assertTrue(ServerAccess::hasServerSelectGrant($user));
        $this->assertTrue(ServerAccess::canSelectServer($user));
        $this->assertFalse(ServerAccess::canManageServers($user), 'grant bukan hak kelola registry');

        // Flag dinormalisasi `UserStore` (string "on"/"1"/"true").
        foreach (['on', '1', 'true', 1, true] as $flag) {
            $this->assertTrue(
                ServerAccess::canSelectServer($this->grantUser([], $flag)),
                'flag ' . var_export($flag, true) . ' harus dianggap aktif'
            );
        }
        foreach (['0', 'off', '', 0, false, 'sampah'] as $flag) {
            $this->assertFalse(
                ServerAccess::canSelectServer($this->grantUser([], $flag)),
                'flag ' . var_export($flag, true) . ' harus nonaktif'
            );
        }
    }

    public function testGrantHolderStillGets404ForServerManagement(): void
    {
        $this->expectException(AppAccessDenied::class);

        ServerAccess::requireManageServers($this->grantUser(['s2']));
    }

    public function testAllowlistRestrictsMaySelectWhileLocalIsAlwaysAllowed(): void
    {
        $user = $this->grantUser(['s2']);

        $this->assertSame(['s2'], ServerAccess::serverAllowlist($user));
        $this->assertTrue(ServerAccess::maySelect($user, 's2'));
        $this->assertFalse(ServerAccess::maySelect($user, 's3'), 'server di luar allowlist ditolak');
        $this->assertTrue(ServerAccess::maySelect($user, 'local'), 'server lokal selalu boleh');
        $this->assertTrue(ServerAccess::maySelect($user, ''), 'id kosong = server lokal');
    }

    public function testEmptyAllowlistMeansAllServersForGrantHolder(): void
    {
        $user = $this->grantUser();

        $this->assertTrue(ServerAccess::maySelect($user, 's1'));
        $this->assertTrue(ServerAccess::maySelect($user, 's-sembarang'));
    }

    public function testSelectableServersForGrantHolderAlwaysIncludesLocal(): void
    {
        $servers = [
            ['id' => 'local', 'name' => 'local', 'kind' => 'local'],
            ['id' => 's2', 'name' => 'agent-a', 'kind' => 'agent'],
            ['id' => 's3', 'name' => 'agent-b', 'kind' => 'agent'],
        ];

        $this->assertSame(
            ['local', 's2'],
            array_column(ServerAccess::selectableServers($servers, $this->grantUser(['s2'])), 'id')
        );
        $this->assertSame(
            ['local', 's2', 's3'],
            array_column(ServerAccess::selectableServers($servers, $this->grantUser()), 'id'),
            'allowlist kosong ⇒ semua server'
        );
        $this->assertSame([], ServerAccess::selectableServers($servers, self::MEMBER), 'tanpa grant ⇒ kosong');
        $this->assertSame(['local', 's2', 's3'], array_column(ServerAccess::selectableServers($servers, self::ADMIN), 'id'));
    }

    public function testVisibleServersIsUnionOfVisibleAppServersAndGrantAllowlist(): void
    {
        // OWNER punya app di s2. Grant allowlist hanya s3 ⇒ union s2 + s3.
        $user = array_merge(self::OWNER, ['server_select' => true, 'server_ids' => ['s3']]);

        $this->assertSame(
            ['s2', 's3'],
            array_column(ServerAccess::visibleServers($this->servers(), $user, $this->apps()), 'id')
        );
    }

    public function testGrantDoesNotExposeForeignAppServers(): void
    {
        // Allowlist s3 saja. App asing di s1 TIDAK boleh ikut terlihat hanya
        // karena user punya grant server.
        $user = $this->grantUser(['s3']);
        $apps = [
            ['id' => 'foreign', 'owner_id' => 'u8', 'server_id' => 's1'],
            ['id' => 'own', 'owner_id' => 'u6', 'server_id' => 's2'],
        ];

        $this->assertSame(
            ['s2', 's3'],
            array_column(ServerAccess::visibleServers($this->servers(), $user, $apps), 'id'),
            'app asing tetap tersaring AppAccess; grant hanya menambah server'
        );
    }

    public function testNonGrantMemberBehaviourIsUnchanged(): void
    {
        $this->assertSame([], ServerAccess::selectableServers($this->servers(), self::MEMBER));
        $this->assertFalse(ServerAccess::maySelect(self::MEMBER, 's2'));
        $this->assertSame(
            ['s2', 's3'],
            array_column(ServerAccess::visibleServers($this->servers(), self::OWNER, $this->apps()), 'id')
        );
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    /** @return array<int,array<string,string>> */
    private function servers(): array
    {
        return [
            ['id' => 's1', 'name' => 'local'],
            ['id' => 's2', 'name' => 'agent-a'],
            ['id' => 's3', 'name' => 'agent-b'],
        ];
    }

    /**
     * `u3` (OWNER) memiliki app di `s2` + app di `s3` yang dibagikan sebagai
     * viewer; app di `s1` milik user lain. `u4` (OPERATOR) operator di app `s3`.
     *
     * @return array<int,array<string,mixed>>
     */
    private function apps(): array
    {
        return [
            ['id' => 'a1', 'owner_id' => 'u8', 'server_id' => 's1'],
            ['id' => 'a2', 'owner_id' => 'u3', 'server_id' => 's2'],
            [
                'id' => 'a3',
                'owner_id' => 'u8',
                'server_id' => 's3',
                'members' => [
                    'u3' => ['role' => AppAccess::ROLE_VIEWER],
                    'u4' => ['role' => AppAccess::ROLE_OPERATOR],
                ],
            ],
            [
                'id' => 'a4',
                'owner_id' => 'u8',
                'server_id' => 's2',
                'members' => [
                    'u5' => AppAccess::ROLE_VIEWER, // bentuk lama: role sebagai string
                ],
            ],
        ];
    }
}
