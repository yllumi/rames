<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ContainerNames;
use app\library\Storage\ServerStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Scope **server** pada {@see ContainerNames} (multi-server F1): nama container
 * (unik se-host) hanya boleh dianggap bentrok di server yang sama.
 *
 * Dua hal yang dijaga di sini:
 *  1. `usedFromApps($apps, $project, $serverId)` menyaring app per server
 *     (tanpa `$serverId` = perilaku lama: semua server);
 *  2. `usedFromEngine()` yang membaca Engine **lokal** **fail-fast** untuk
 *     server non-lokal — tidak pernah diam-diam membaca Engine lokal untuk app
 *     server remote (plan §3 butir 4).
 */
class ContainerNamesServerScopeTest extends TestCase
{
    /** @return array<int,array> */
    private function apps(): array
    {
        return [
            [
                'id' => 'a',
                'name' => 'alpha',
                'server_id' => 'local',
                'containers' => [['service_name' => 'web', 'container_name' => 'hermes-web']],
            ],
            [
                'id' => 'b',
                'name' => 'beta',
                'server_id' => 'vps-1',
                'containers' => [['service_name' => 'web', 'container_name' => 'hermes-web']],
            ],
            [
                'id' => 'c',
                'name' => 'gamma', // tanpa server_id ⇒ server lokal
                'containers' => [['service_name' => 'web', 'container_name' => 'gamma-web']],
            ],
        ];
    }

    // ==================================================================
    // usedFromApps() — per server
    // ==================================================================

    public function testUsedFromAppsServerLokalHanyaMelihatAppLokal(): void
    {
        $used = ContainerNames::usedFromApps($this->apps(), 'delta', 'local');

        $this->assertSame(
            ['hermes-web' => 'app "alpha"', 'gamma-web' => 'app "gamma"'],
            $used
        );
    }

    public function testUsedFromAppsServerRemoteTerpisahDariLokal(): void
    {
        $used = ContainerNames::usedFromApps($this->apps(), 'delta', 'vps-1');

        $this->assertSame(['hermes-web' => 'app "beta"'], $used);
    }

    public function testUsedFromAppsServerIdKosongBerartiLokal(): void
    {
        $this->assertSame(
            ContainerNames::usedFromApps($this->apps(), 'delta', 'local'),
            ContainerNames::usedFromApps($this->apps(), 'delta', '')
        );
    }

    public function testUsedFromAppsTanpaScopeSamaDenganPerilakuLama(): void
    {
        $used = ContainerNames::usedFromApps($this->apps(), 'delta');

        $this->assertSame(
            ['hermes-web' => 'app "beta"', 'gamma-web' => 'app "gamma"'],
            $used,
            'nama sama dua server: yang terakhir menang di map (perilaku lama, semua server)'
        );
        $this->assertSame($used, ContainerNames::usedFromApps($this->apps(), 'delta', null));
    }

    public function testUsedFromAppsTetapMelewatiAppSendiri(): void
    {
        $used = ContainerNames::usedFromApps($this->apps(), 'alpha', 'local');

        $this->assertArrayNotHasKey('hermes-web', $used, 'app yang digantikan tidak menghalangi dirinya');
        $this->assertSame(['gamma-web' => 'app "gamma"'], $used);
    }

    public function testUsedFromAppsServerTidakDikenalKosong(): void
    {
        $this->assertSame([], ContainerNames::usedFromApps($this->apps(), 'delta', 'vps-9'));
    }

    // ==================================================================
    // usedFromEngine() — fail-fast untuk server non-lokal
    // ==================================================================

    public function testUsedFromEngineServerRemoteFailFast(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/belum aktif/');
        $this->expectExceptionMessageMatches('/vps-1/');

        ContainerNames::usedFromEngine([], 'myapp', 'vps-1');
    }

    public function testUsedFromEngineServerRemoteTidakMengembalikanNamaApaPun(): void
    {
        // Kontainer milik app remote TIDAK boleh dilaporkan sebagai "terpakai di
        // host ini" — kalau tidak, deploy app remote akan diblokir nama container
        // server lain (kebalikannya: jangan pernah membaca Engine lokal diam-diam).
        try {
            $used = ContainerNames::usedFromEngine([
                ['Names' => ['/other-web'], 'Labels' => ['com.docker.compose.project' => 'other']],
            ], 'myapp', 'vps-1');
            $this->fail('seharusnya fail-fast, bukan mengembalikan: ' . json_encode($used));
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Docker Engine', $e->getMessage());
        }
    }

    public function testUsedFromEngineServerLokalDanTanpaScopeBerperilakuLama(): void
    {
        $containers = [
            [
                'Names' => ['/myapp-web'],
                'Labels' => ['com.docker.compose.project' => 'myapp'],
            ],
            [
                'Names' => ['/other-web'],
                'Labels' => ['com.docker.compose.project' => 'other'],
            ],
        ];

        $legacy = ContainerNames::usedFromEngine($containers, 'myapp');
        $this->assertSame(['other-web' => 'app "other"'], $legacy);
        $this->assertSame($legacy, ContainerNames::usedFromEngine($containers, 'myapp', null));
        $this->assertSame($legacy, ContainerNames::usedFromEngine($containers, 'myapp', ServerStore::LOCAL_ID));
    }

    // ==================================================================
    // Aturan lain tidak berubah
    // ==================================================================

    public function testAssertAvailableTetapMenolakBentrokNama(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nama container sudah terpakai');

        ContainerNames::assertAvailable(
            ContainerNames::mapFor(['web'], 'hermes'),
            ContainerNames::usedFromApps($this->apps(), 'delta', 'local')
        );
    }

    public function testAssertNotReplicatedTetapMenolakReplicaLebihDariSatu(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/replica/');

        ContainerNames::assertNotReplicated(['web' => ['replicas' => 2]]);
    }

    public function testAlurEndToEndPerServerTidakBentrokLintasServer(): void
    {
        // App di vps-1 memakai prefix yang sama dengan app lokal: sah, karena
        // nama container unik **per server** (Engine-nya pun berbeda).
        $names = ContainerNames::mapFor(['web'], 'hermes');

        ContainerNames::assertAvailable($names, ContainerNames::usedFromApps($this->apps(), 'beta', 'vps-1'));
        $this->addToAssertionCount(1);
    }
}
