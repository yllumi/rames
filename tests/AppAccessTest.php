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

    public function testLimitsIsAdminOnly(): void
    {
        $app = $this->app();

        // Batas CPU/memori per service = kewenangan admin global saja.
        $this->assertTrue(AppAccess::can('limits', $app, self::ADMIN));
        $this->assertFalse(AppAccess::can('limits', $app, self::OWNER));
        $this->assertFalse(AppAccess::can('limits', $app, self::OPERATOR));
        $this->assertFalse(AppAccess::can('limits', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('limits', $app, self::STRANGER));

        // abilitiesFor() konsisten: `limits` tidak bocor ke role non-admin.
        $this->assertTrue(AppAccess::abilitiesFor(AppAccess::ROLE_ADMIN)['limits']);
        foreach ([AppAccess::ROLE_OWNER, AppAccess::ROLE_OPERATOR, AppAccess::ROLE_VIEWER, null] as $role) {
            $this->assertArrayHasKey('limits', AppAccess::abilitiesFor($role));
            $this->assertFalse(
                AppAccess::abilitiesFor($role)['limits'],
                'limits seharusnya tidak dimiliki role ' . ($role ?? 'null')
            );
        }
    }

    public function testRequireLimitsThrows404ForNonAdmin(): void
    {
        $app = $this->app();

        foreach ([self::OWNER, self::OPERATOR, self::VIEWER] as $user) {
            try {
                AppAccess::require('limits', $app, $user);
                $this->fail('AppAccessDenied seharusnya dilempar untuk ' . $user['username']);
            } catch (AppAccessDenied $e) {
                // 404 (bukan 403) — satu pintu AppAccess.
                $this->assertSame(404, $e->getCode());
                $this->assertSame('limits', $e->ability);
                $this->assertSame('app1', $e->appId);
            }
        }

        // Admin lolos tanpa exception.
        AppAccess::require('limits', $app, self::ADMIN);
        $this->assertTrue(true);
    }

    public function testBackupIsAllowedForOperatorOwnerAndAdminOnly(): void
    {
        $app = $this->app();

        // backup volume = operator ke atas (termasuk admin global).
        $this->assertTrue(AppAccess::can('backup', $app, self::OPERATOR));
        $this->assertTrue(AppAccess::can('backup', $app, self::OWNER));
        $this->assertTrue(AppAccess::can('backup', $app, self::ADMIN));

        // Ditolak untuk viewer dan user tanpa akses.
        $this->assertFalse(AppAccess::can('backup', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('backup', $app, self::STRANGER));
        $this->assertFalse(AppAccess::can('backup', $app, null));

        // abilitiesFor() konsisten: `backup` dimiliki operator ke atas.
        $this->assertTrue(AppAccess::abilitiesFor(AppAccess::ROLE_OPERATOR)['backup']);
        $this->assertTrue(AppAccess::abilitiesFor(AppAccess::ROLE_OWNER)['backup']);
        $this->assertTrue(AppAccess::abilitiesFor(AppAccess::ROLE_ADMIN)['backup']);
        $this->assertFalse(AppAccess::abilitiesFor(AppAccess::ROLE_VIEWER)['backup']);
        $this->assertFalse(AppAccess::abilitiesFor(null)['backup']);
    }

    public function testRestoreIsOwnerAndAdminOnly(): void
    {
        $app = $this->app();

        // restore volume = destruktif → eksklusif owner (dan admin global).
        $this->assertTrue(AppAccess::can('restore', $app, self::OWNER));
        $this->assertTrue(AppAccess::can('restore', $app, self::ADMIN));

        // Ditolak untuk operator dan viewer (juga user tanpa akses).
        $this->assertFalse(AppAccess::can('restore', $app, self::OPERATOR));
        $this->assertFalse(AppAccess::can('restore', $app, self::VIEWER));
        $this->assertFalse(AppAccess::can('restore', $app, self::STRANGER));
        $this->assertFalse(AppAccess::can('restore', $app, null));

        // abilitiesFor() konsisten: `restore` tidak bocor ke operator/viewer.
        $this->assertTrue(AppAccess::abilitiesFor(AppAccess::ROLE_OWNER)['restore']);
        $this->assertTrue(AppAccess::abilitiesFor(AppAccess::ROLE_ADMIN)['restore']);
        $this->assertFalse(AppAccess::abilitiesFor(AppAccess::ROLE_OPERATOR)['restore']);
        $this->assertFalse(AppAccess::abilitiesFor(AppAccess::ROLE_VIEWER)['restore']);
        $this->assertFalse(AppAccess::abilitiesFor(null)['restore']);
    }

    public function testRequireBackupAndRestoreThrow404WhenDenied(): void
    {
        $app = $this->app();

        // `backup` ditolak untuk viewer → 404 (bukan 403).
        try {
            AppAccess::require('backup', $app, self::VIEWER);
            $this->fail('AppAccessDenied seharusnya dilempar untuk backup/viewer.');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('backup', $e->ability);
            $this->assertSame('app1', $e->appId);
        }

        // `restore` ditolak untuk operator → 404 (bukan 403).
        try {
            AppAccess::require('restore', $app, self::OPERATOR);
            $this->fail('AppAccessDenied seharusnya dilempar untuk restore/operator.');
        } catch (AppAccessDenied $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('restore', $e->ability);
            $this->assertSame('app1', $e->appId);
        }

        // Jalur yang diizinkan lolos tanpa exception.
        AppAccess::require('backup', $app, self::OPERATOR);
        AppAccess::require('restore', $app, self::OWNER);
        $this->assertTrue(true);
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
