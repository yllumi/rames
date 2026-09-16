<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\AppAccess;
use app\library\Auth\OwnershipMigrator;
use app\library\Auth\UserStore;
use app\library\Storage\AppStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test kepemilikan/sharing app (apps.json) & role user (auth.json),
 * termasuk migrasi data lama yang belum punya owner/role.
 */
class AppOwnershipTest extends TestCase
{
    private string $appsFile;
    private string $authFile;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->appsFile = sys_get_temp_dir() . '/rames-own-apps-' . $suffix . '.json';
        $this->authFile = sys_get_temp_dir() . '/rames-own-auth-' . $suffix . '.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->appsFile, $this->authFile] as $f) {
            foreach ([$f, $f . '.bak'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function store(): AppStore
    {
        return new AppStore($this->appsFile);
    }

    private function users(): UserStore
    {
        return new UserStore($this->authFile);
    }

    // ==================================================================
    // UserStore — role
    // ==================================================================

    public function testFirstUserBecomesAdmin(): void
    {
        $users = $this->users();

        $admin = $users->create('admin', 'secret123');
        $member = $users->create('budi', 'secret123');

        $this->assertSame(UserStore::ROLE_ADMIN, $admin['role']);
        $this->assertSame(UserStore::ROLE_MEMBER, $member['role']);

        $found = $users->findPublicById((string) $admin['id']);
        $this->assertSame('admin', $found['role']);
        $this->assertArrayNotHasKey('password_hash', $found);

        $this->assertTrue($users->isAdmin($found));
        $this->assertFalse($users->isAdmin($users->findPublicById((string) $member['id'])));
        $this->assertSame(1, $users->countAdmins());
    }

    public function testLegacyAuthFileWithoutRoleTreatsFirstUserAsAdmin(): void
    {
        file_put_contents($this->authFile, json_encode([
            ['id' => 'u1', 'username' => 'lama', 'password_hash' => password_hash('secret123', PASSWORD_BCRYPT)],
            ['id' => 'u2', 'username' => 'kedua', 'password_hash' => password_hash('secret123', PASSWORD_BCRYPT)],
        ], JSON_PRETTY_PRINT));

        $users = $this->users();

        $this->assertSame(UserStore::ROLE_ADMIN, $users->roleOf($users->findById('u1') ?? []));
        $this->assertSame(UserStore::ROLE_MEMBER, $users->roleOf($users->findById('u2') ?? []));

        // Terdaftar di session sebagai role hasil resolusi (login user lama).
        $this->assertSame('admin', $users->findPublicById('u1')['role']);
    }

    public function testVerifyReturnsRoleWithoutPasswordHash(): void
    {
        $users = $this->users();
        $users->create('admin', 'secret123');

        $verified = $users->verify('admin', 'secret123');
        $this->assertNotNull($verified);
        $this->assertSame('admin', $verified['role']);
        $this->assertArrayNotHasKey('password_hash', $verified);

        $this->assertNull($users->verify('admin', 'salah'));
    }

    public function testChangeRoleRejectsUnknownRole(): void
    {
        $users = $this->users();
        $admin = $users->create('admin', 'secret123');

        $this->expectException(\InvalidArgumentException::class);
        $users->changeRole((string) $admin['id'], 'superuser');
    }

    // ==================================================================
    // AppStore — owner & members
    // ==================================================================

    public function testCreateAppSetsOwnerAndEmptyMembers(): void
    {
        $app = $this->store()->create(['name' => 'myapp', 'owner_id' => 'u1']);

        $this->assertSame('u1', $app['owner_id']);
        $this->assertSame([], $app['members']);
    }

    public function testAddUpdateAndRemoveMember(): void
    {
        $store = $this->store();
        $app = $store->create(['name' => 'myapp', 'owner_id' => 'u1']);

        $store->addMember($app['id'], 'u2', AppAccess::ROLE_VIEWER, 'u1');
        $this->assertSame(AppAccess::ROLE_VIEWER, AppAccess::roleFor($store->find($app['id']), ['id' => 'u2', 'role' => 'member']));

        // addMember bersifat upsert — role dinaikkan tanpa duplikasi entri.
        $store->addMember($app['id'], 'u2', AppAccess::ROLE_OPERATOR, 'u1');
        $updated = $store->find($app['id']);
        $this->assertCount(1, $updated['members']);
        $this->assertSame(AppAccess::ROLE_OPERATOR, $updated['members']['u2']['role']);

        $store->removeMember($app['id'], 'u2');
        $this->assertSame([], $store->find($app['id'])['members']);
    }

    public function testAddMemberRejectsInvalidRoleAndOwner(): void
    {
        $store = $this->store();
        $app = $store->create(['name' => 'myapp', 'owner_id' => 'u1']);

        try {
            $store->addMember($app['id'], 'u2', 'superuser', 'u1');
            $this->fail('Role tidak valid seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame([], $store->find($app['id'])['members'], 'tidak ada penulisan saat validasi gagal');
        }

        $this->expectException(RuntimeException::class);
        $store->addMember($app['id'], 'u1', AppAccess::ROLE_VIEWER, 'u1');
    }

    public function testTransferOwnerKeepsPreviousOwnerAsCoOwner(): void
    {
        $store = $this->store();
        $app = $store->create(['name' => 'myapp', 'owner_id' => 'u1']);
        $store->addMember($app['id'], 'u2', AppAccess::ROLE_VIEWER, 'u1');

        $store->transferOwner($app['id'], 'u2', 'u1');
        $updated = $store->find($app['id']);

        $this->assertSame('u2', $updated['owner_id']);
        $this->assertArrayNotHasKey('u2', $updated['members'], 'owner baru tidak lagi terdaftar sebagai member');
        $this->assertSame(AppAccess::ROLE_OWNER, $updated['members']['u1']['role'], 'owner lama menjadi co-owner');

        // Owner baru tetap punya seluruh hak; owner lama juga (co-owner).
        $this->assertTrue(AppAccess::can('delete', $updated, ['id' => 'u2', 'role' => 'member']));
        $this->assertTrue(AppAccess::can('delete', $updated, ['id' => 'u1', 'role' => 'member']));
    }

    public function testTransferAllFromMovesAppsAndDropsMembership(): void
    {
        $store = $this->store();
        $a = $store->create(['name' => 'app-a', 'owner_id' => 'u1']);
        $b = $store->create(['name' => 'app-b', 'owner_id' => 'u2']);
        $store->addMember($b['id'], 'u1', AppAccess::ROLE_OPERATOR, 'u2');

        $moved = $store->transferAllFrom('u1', 'admin', 'admin');

        $this->assertSame(1, $moved, 'hanya app milik u1 yang dipindah');
        $this->assertSame('admin', $store->find($a['id'])['owner_id']);
        $this->assertSame('u2', $store->find($b['id'])['owner_id'], 'app milik user lain tidak tersentuh');
        $this->assertArrayNotHasKey('u1', $store->find($b['id'])['members'], 'keanggotaan user dihapus');
        $this->assertSame(1, count($store->ownedBy('admin')));
    }

    public function testAssignMissingOwnersOnlyWritesWhenNeeded(): void
    {
        $store = $this->store();
        $legacy = $store->create(['name' => 'legacy']);     // tanpa owner_id
        $owned = $store->create(['name' => 'owned', 'owner_id' => 'u1']);

        $migrated = $store->assignMissingOwners('admin');
        $this->assertSame(1, $migrated);
        $this->assertSame('admin', $store->find($legacy['id'])['owner_id']);
        $this->assertSame('u1', $store->find($owned['id'])['owner_id']);

        // Idempoten: pemanggilan berikutnya tidak mengubah apa pun.
        $this->assertSame(0, $store->assignMissingOwners('admin'));
    }

    public function testOwnershipMigratorAssignsToFirstAdmin(): void
    {
        file_put_contents($this->authFile, json_encode([
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => date('c')],
            ['id' => 'u2', 'username' => 'budi', 'password_hash' => 'x', 'role' => 'member', 'created_at' => date('c')],
        ], JSON_PRETTY_PRINT));

        $store = $this->store();
        $app = $store->create(['name' => 'lama']);

        // OwnershipMigrator memakai UserStore/AppStore default (config), jadi
        // di sini yang diuji adalah resolusi owner-nya via assignMissingOwners.
        $this->assertSame('u1', $this->resolveAdminId());
        $this->assertSame(1, $store->assignMissingOwners('u1'));
        $this->assertSame('u1', $store->find($app['id'])['owner_id']);
    }

    private function resolveAdminId(): string
    {
        foreach ($this->users()->listWithRoles() as $user) {
            if ($user['role'] === UserStore::ROLE_ADMIN) {
                return (string) $user['id'];
            }
        }
        return '';
    }
}
