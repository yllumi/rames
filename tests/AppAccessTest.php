<?php
declare(strict_types=1);

namespace Tests;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use PHPUnit\Framework\TestCase;

/**
 * Test otorisasi kepemilikan/sharing app (AppAccess) — matriks hak per role.
 */
class AppAccessTest extends TestCase
{
    private const OWNER = ['id' => 'u1', 'username' => 'owner', 'role' => 'member'];
    private const OPERATOR = ['id' => 'u2', 'username' => 'operator', 'role' => 'member'];
    private const VIEWER = ['id' => 'u3', 'username' => 'viewer', 'role' => 'member'];
    private const ADMIN = ['id' => 'u9', 'username' => 'admin', 'role' => 'admin'];
    private const STRANGER = ['id' => 'u8', 'username' => 'stranger', 'role' => 'member'];

    /**
     * @return array<string,mixed>
     */
    private function app(): array
    {
        return [
            'id' => 'app1',
            'name' => 'myapp',
            'owner_id' => 'u1',
            'members' => [
                'u2' => ['role' => 'operator'],
                'u3' => ['role' => 'viewer'],
            ],
        ];
    }

    public function testRoleForEachUser(): void
    {
        $app = $this->app();

        $this->assertSame(AppAccess::ROLE_OWNER, AppAccess::roleFor($app, self::OWNER));
        $this->assertSame(AppAccess::ROLE_OPERATOR, AppAccess::roleFor($app, self::OPERATOR));
        $this->assertSame(AppAccess::ROLE_VIEWER, AppAccess::roleFor($app, self::VIEWER));
        $this->assertSame(AppAccess::ROLE_ADMIN, AppAccess::roleFor($app, self::ADMIN));
        $this->assertNull(AppAccess::roleFor($app, self::STRANGER));
        $this->assertNull(AppAccess::roleFor($app, null));
        $this->assertNull(AppAccess::roleFor($app, ['id' => '']));
    }

    public function testRoleForSupportsPlainStringMembers(): void
    {
        $app = ['id' => 'app1', 'owner_id' => 'u1', 'members' => ['u2' => 'operator']];
        $this->assertSame(AppAccess::ROLE_OPERATOR, AppAccess::roleFor($app, self::OPERATOR));
    }

    public function testUnknownMemberRoleIsIgnored(): void
    {
        $app = ['id' => 'app1', 'owner_id' => 'u1', 'members' => ['u2' => ['role' => 'superuser']]];
        $this->assertNull(AppAccess::roleFor($app, self::OPERATOR));
    }

    public function testViewerIsReadOnly(): void
    {
        $app = $this->app();

        $this->assertTrue(AppAccess::can('view', $app, self::VIEWER));
        $this->assertTrue(AppAccess::can('logs', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('operate', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('terminal', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('database', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('env', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('domain', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('delete', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('sharing', $app, self::VIEWER));
    }

    public function testOperatorCanOperateButNotDeleteOrShare(): void
    {
        $app = $this->app();

        $this->assertTrue(AppAccess::can('operate', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('deploy', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('terminal', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('database', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('env', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('network', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('domain', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('ssl', $app, self::OPERATOR));
        $this->assertFalse(AppAccess::can('delete', $app, self::OPERATOR));
        $this->assertFalse(AppAccess::can('sharing', $app, self::OPERATOR));
    }

    public function testOwnerAndAdminHaveFullAccess(): void
    {
        $app = $this->app();

        foreach ([self::OWNER, self::ADMIN] as $user) {
            foreach (['view', 'operate', 'deploy', 'terminal', 'database', 'env', 'network', 'domain', 'ssl', 'delete', 'sharing'] as $ability) {
                $this->assertTrue(
                    AppAccess::can($ability, $app, $user),
                    $ability . ' seharusnya boleh untuk ' . $user['username']
                );
            }
        }
    }

    public function testUnknownAbilityDefaultsToOwnerOnly(): void
    {
        $app = $this->app();

        $this->assertFalse(AppAccess::can('unknown-ability', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('unknown-ability', $app, self::OWNER));
    }

    public function testStrangerHasNoAccess(): void
    {
        $app = $this->app();
        $this->assertFalse(AppAccess::can('view', $app, self::STRANGER));
    }

    public function testVisibleFiltersApps(): void
    {
        $app = $this->app();
        $other = ['id' => 'app2', 'name' => 'other', 'owner_id' => 'u7', 'members' => []];

        $visible = AppAccess::visible([$app, $other], self::OPERATOR);
        $this->assertCount(1, $visible);
        $this->assertSame('app1', $visible[0]['id']);

        // Admin melihat semua.
        $this->assertCount(2, AppAccess::visible([$app, $other], self::ADMIN));

        // Stranger tidak melihat apa pun.
        $this->assertSame([], AppAccess::visible([$app, $other], self::STRANGER));
    }

    public function testAppWithoutOwnerIsVisibleOnlyToAdmin(): void
    {
        $legacy = ['id' => 'legacy', 'name' => 'legacy'];

        $this->assertSame([], AppAccess::visible([$legacy], self::OPERATOR));
        $this->assertCount(1, AppAccess::visible([$legacy], self::ADMIN));
        $this->assertSame(AppAccess::ROLE_ADMIN, AppAccess::roleFor($legacy, self::ADMIN));
    }

    public function testRequireThrows404ForDeniedAccess(): void
    {
        $app = $this->app();

        try {
            AppAccess::require('delete', $app, self::OPERATOR);
            $this->fail('AppAccessDenied seharusnya dilempar.');
        } catch (AppAccessDenied $e) {
            // 404 (bukan 403) supaya keberadaan app tidak bocor.
            $this->assertSame(404, $e->getCode());
            $this->assertSame('delete', $e->ability);
            $this->assertSame('app1', $e->appId);
        }

        // Owner lolos tanpa exception.
        AppAccess::require('delete', $app, self::OWNER);
        $this->assertTrue(true);
    }

    public function testAbilitiesForAndLabel(): void
    {
        $viewer = AppAccess::abilitiesFor(AppAccess::ROLE_VIEWER);
        $this->assertTrue($viewer['view']);
        $this->assertFalse($viewer['delete']);

        $none = AppAccess::abilitiesFor(null);
        $this->assertFalse($none['view']);

        $this->assertSame('Owner', AppAccess::label(AppAccess::ROLE_OWNER));
        $this->assertSame('Viewer', AppAccess::label(AppAccess::ROLE_VIEWER));
        $this->assertSame('-', AppAccess::label(''));
    }
}
