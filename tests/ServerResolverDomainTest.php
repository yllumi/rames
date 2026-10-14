<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Test domain-per-app + memo snapshot {@see ServerResolver} (misi `ms-f1-domain`,
 * plan §4.2, §9 keputusan 4).
 *
 * Yang dikunci:
 *  - `domainForApp()`/`serverForApp()` = domain dasar **server pemilik app**
 *    (`servers.domain`), fallback `config('deploy.app_domain')` bila kosong;
 *  - `domainForApp()` fail-fast untuk `server_id` tak dikenal, sedangkan
 *    `domainForAppOrLocal()` (jalur perakitan FQDN/display) tidak pernah melempar;
 *  - memo per-proses snapshot registry: terisi setelah pembacaan, dibuang
 *    `forgetMemo()`, **otomatis invalid** saat store berubah, dan tidak pernah
 *    menyilang antar berkas store (kunci memo = path berkas).
 *
 * Semua uji memakai berkas SQLite di `sys_get_temp_dir()` — tidak menyentuh
 * `database/rames.sqlite`, `apps/`, atau `nginx-status/`.
 */
class ServerResolverDomainTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->file = sys_get_temp_dir() . '/rames-domain-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        StoreDbFiles::remove($this->file);
    }

    private function resolver(?string $file = null): ServerResolver
    {
        return new ServerResolver(new ServerStore($file ?? $this->file));
    }

    private function store(?string $file = null): ServerStore
    {
        return new ServerStore($file ?? $this->file);
    }

    /** @param array<string,mixed> $extra */
    private function agent(string $domain, array $extra = [], ?string $file = null): array
    {
        return $this->store($file)->create($extra + [
            'name' => 'vps-' . bin2hex(random_bytes(2)),
            'kind' => 'agent',
            'domain' => $domain,
        ]);
    }

    // ------------------------------------------------------------------
    // domainForApp / serverForApp
    // ------------------------------------------------------------------

    public function testDomainForAppUsesOwnerServerDomain(): void
    {
        $server = $this->agent('a.example.com');
        $resolver = $this->resolver();

        $this->assertSame($server['id'], $resolver->serverForApp(['server_id' => $server['id']])['id']);
        $this->assertSame('a.example.com', $resolver->domainForApp(['server_id' => $server['id']]));
        // Domain disimpan tanpa normalisasi saat dibaca? — `domainFor()` lowercase + buang titik akhir.
        $this->assertSame('normal.example.com', $this->resolver()->domainForApp([
            'server_id' => $this->agent('Normal.Example.Com.')['id'],
        ]));
    }

    public function testDomainForAppFallsBackToConfigForEmptyServerDomain(): void
    {
        $noDomain = $this->agent('');

        $this->assertSame(ServerResolver::configDomain(), $this->resolver()->domainForApp(['server_id' => $noDomain['id']]));
        // App tanpa `server_id` = server lokal (baris seed ber-`domain` kosong).
        $this->assertSame(ServerResolver::configDomain(), $this->resolver()->domainForApp(['name' => 'lama']));
        $this->assertSame(ServerResolver::configDomain(), ServerResolver::domainFor([]));
    }

    public function testDomainForAppThrowsForUnknownServer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dikenal');
        $this->resolver()->domainForApp(['name' => 'remote', 'server_id' => 'sudah-dihapus']);
    }

    public function testDomainForAppOrLocalIsTolerant(): void
    {
        $server = $this->agent('b.example.com');
        $resolver = $this->resolver();

        $this->assertSame('b.example.com', ServerResolver::domainForAppOrLocal(['server_id' => $server['id']], $resolver));
        // Server tak dikenal → APP_DOMAIN, bukan galat.
        $this->assertSame(
            ServerResolver::configDomain(),
            ServerResolver::domainForAppOrLocal(['server_id' => 'sudah-dihapus'], $resolver)
        );
        // Tanpa resolver: store default dibangun di dalam try — bila registry tidak
        // bisa dibuka/tidak dikonfigurasi (mis. helper dipakai dari CLI), tetap APP_DOMAIN.
        $this->assertSame(ServerResolver::configDomain(), ServerResolver::domainForAppOrLocal([]));
        $this->assertSame(ServerResolver::configDomain(), ServerResolver::domainForAppOrLocal(['name' => 'lama']));
    }

    // ------------------------------------------------------------------
    // Memo snapshot registry
    // ------------------------------------------------------------------

    public function testMemoIsPopulatedAndCanBeCleared(): void
    {
        $server = $this->agent('a.example.com');
        $this->assertSame([], $this->memoState(), 'memo harus kosong sebelum ada pembacaan');

        $this->resolver()->domainForApp(['server_id' => $server['id']]);
        $this->assertArrayHasKey($this->file, $this->memoState(), 'pembacaan pertama harus mengisi memo');

        ServerResolver::forgetMemo();
        $this->assertSame([], $this->memoState());

        // Tetap benar sesudah memo dikosongkan (dibaca ulang dari store).
        $this->assertSame('a.example.com', $this->resolver()->domainForApp(['server_id' => $server['id']]));
    }

    public function testMemoIsInvalidatedWhenStoreChanges(): void
    {
        $server = $this->agent('a.example.com');
        $resolver = $this->resolver();
        $app = ['server_id' => $server['id']];

        $this->assertSame('a.example.com', $resolver->domainForApp($app));

        // Tulis store (panjang nilai sama + detik yang sama) → cap berkas berubah
        // lewat `-wal`, jadi memo TIDAK boleh menyajikan nilai lama.
        $this->store()->update($server['id'], static function (array &$row): void {
            $row['domain'] = 'b.example.com';
        });
        $this->assertSame('b.example.com', $resolver->domainForApp($app));

        // Baris lokal juga: override `servers.domain` server lokal terbaca.
        $this->store()->update(ServerStore::LOCAL_ID, static function (array &$row): void {
            $row['domain'] = 'lokal.example.org';
        });
        $this->assertSame('lokal.example.org', $resolver->domainForApp(['name' => 'lama']));
    }

    public function testMemoDoesNotCrossStoreFiles(): void
    {
        $other = sys_get_temp_dir() . '/rames-domain-' . bin2hex(random_bytes(4)) . '.sqlite';

        try {
            $satu = $this->agent('satu.example.com');
            $dua = $this->agent('dua.example.com', [], $other);

            $resolverSatu = $this->resolver();
            $resolverDua = $this->resolver($other);

            $this->assertSame('satu.example.com', $resolverSatu->domainForApp(['server_id' => $satu['id']]));
            $this->assertSame('dua.example.com', $resolverDua->domainForApp(['server_id' => $dua['id']]));
            // Baca ulang bergantian: memo berkas satu tidak bocor ke berkas lain.
            $this->assertSame('dua.example.com', $resolverDua->domainForApp(['server_id' => $dua['id']]));
            $this->assertSame('satu.example.com', $resolverSatu->domainForApp(['server_id' => $satu['id']]));

            $memo = $this->memoState();
            $this->assertArrayHasKey($this->file, $memo);
            $this->assertArrayHasKey($other, $memo);
            // Id server berkas lain tidak dikenal di berkas ini (bukan saling isi).
            $this->expectException(RuntimeException::class);
            $resolverSatu->domainForApp(['server_id' => $dua['id']]);
        } finally {
            StoreDbFiles::remove($other);
        }
    }

    /**
     * Isi memo per-proses (white-box: yang diuji perilaku snapshot internal).
     *
     * @return array<string,mixed>
     */
    private function memoState(): array
    {
        $property = new ReflectionProperty(ServerResolver::class, 'memo');
        $property->setAccessible(true);

        return (array) $property->getValue();
    }
}
