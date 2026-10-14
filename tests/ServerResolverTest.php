<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Test {@see ServerResolver} (misi `ms-f0-resolver`): satu titik resolusi server
 * + validasi form server.
 *
 * Yang dikunci (plan §3 butir 4 & 5, §4.2, §11):
 *  - id server tak dikenal MELEMPAR (tidak pernah diam-diam jatuh ke server lokal);
 *  - app tanpa `server_id` tetap di server lokal (kompatibilitas app lama);
 *  - fallback `domain`/`port_range` ke config hanya saat dokumen server kosong;
 *  - accessor server agent (`base_url`/`token`/`tls_cert_pem`) fail-fast;
 *  - `validate()` menolak `http://`, token kosong, PEM sampah, `kind=local`,
 *    nama tidak valid, port range tumpang tindih dengan server lokal;
 *  - fingerprint SHA-256 dihitung dashboard (bukan diketik user).
 *
 * Semua uji memakai store SQLite di path temp (`sys_get_temp_dir()`) — tidak
 * pernah menyentuh `database/rames.sqlite`, `apps/`, atau `nginx-status/`.
 */
class ServerResolverTest extends TestCase
{
    /** Sertifikat self-signed dibuat sekali per proses (murah). */
    private static ?string $pem = null;

    private string $file;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->file = sys_get_temp_dir() . '/rames-resolver-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->file);
    }

    // ------------------------------------------------------------------
    // Resolusi
    // ------------------------------------------------------------------

    public function testLocalReturnsLocalSeedDocument(): void
    {
        $local = $this->resolver()->local();

        $this->assertSame(ServerStore::LOCAL_ID, $local['id']);
        $this->assertSame('local', $local['kind']);
        $this->assertTrue(ServerResolver::isLocal($local));
    }

    public function testResolveEmptyIdFallsBackToLocal(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(ServerStore::LOCAL_ID, $resolver->resolve('')['id']);
        $this->assertSame(ServerStore::LOCAL_ID, $resolver->resolve('   ')['id']);
    }

    public function testResolveUnknownIdThrowsInsteadOfFallingBackToLocal(): void
    {
        try {
            $this->resolver()->resolve('vps-hantu');
            $this->fail('id server tak dikenal harus MELEMPAR, bukan mengembalikan server lokal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vps-hantu', $e->getMessage());
            $this->assertStringContainsString('tidak dikenal', $e->getMessage());
            // Penjaga inti: pesan menegaskan operasi tidak dialihkan ke server lokal.
            $this->assertStringContainsString('server lokal', $e->getMessage());
        }
    }

    public function testResolveReturnsRegisteredAgentServer(): void
    {
        $store = new ServerStore($this->file);
        $created = $store->create(['name' => 'vps-1', 'kind' => 'agent', 'base_url' => 'https://10.0.0.5:8443']);

        $found = $this->resolver()->resolve($created['id']);
        $this->assertSame('vps-1', $found['name']);
        $this->assertFalse(ServerResolver::isLocal($found));
    }

    public function testResolveForAppUsesServerIdField(): void
    {
        $resolver = $this->resolver();

        // App lama (tanpa server_id) tetap di server lokal.
        $this->assertSame(ServerStore::LOCAL_ID, $resolver->resolveForApp(['name' => 'lama'])['id']);
        $this->assertSame(ServerStore::LOCAL_ID, $resolver->resolveForApp(['server_id' => ''])['id']);

        $store = new ServerStore($this->file);
        $created = $store->create(['name' => 'vps-2', 'kind' => 'agent']);
        $this->assertSame(
            $created['id'],
            $resolver->resolveForApp(['name' => 'remote', 'server_id' => $created['id']])['id']
        );
    }

    public function testResolveForAppWithUnknownServerIdThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak dikenal');
        $this->resolver()->resolveForApp(['name' => 'remote', 'server_id' => 'sudah-dihapus']);
    }

    // ------------------------------------------------------------------
    // Predikat & fallback config
    // ------------------------------------------------------------------

    public function testIsLocalIsBasedOnKindNotId(): void
    {
        $this->assertTrue(ServerResolver::isLocal(['id' => 'local', 'kind' => 'local']));
        $this->assertFalse(
            ServerResolver::isLocal(['id' => 'local', 'kind' => 'agent']),
            'dokumen yang mengaku id=local tapi kind=agent BUKAN server lokal'
        );
        $this->assertFalse(ServerResolver::isLocal([]));
    }

    public function testDomainForPrefersServerDocumentThenConfig(): void
    {
        $this->assertSame(
            strtolower(trim((string) config('deploy.app_domain', 'example.com'))),
            ServerResolver::domainFor(['kind' => 'agent', 'domain' => '']),
            'domain kosong ⇒ ikut APP_DOMAIN'
        );
        $this->assertSame(
            strtolower(trim((string) config('deploy.app_domain', 'example.com'))),
            ServerResolver::domainFor([])
        );
        $this->assertSame('domain2.com', ServerResolver::domainFor(['domain' => ' Domain2.com. ']));
    }

    public function testPortRangePrefersServerDocumentThenConfig(): void
    {
        // Ekspektasi diturunkan dari config dengan default yang sama seperti
        // implementasi (nilai env PORT_RANGE_* tetap dihormati bila config termuat).
        $expected = [
            'start' => (int) config('deploy.port_range.start', 30000),
            'end' => (int) config('deploy.port_range.end', 30999),
        ];

        $this->assertSame($expected, ServerResolver::portRange([]));
        $this->assertSame($expected, ServerResolver::portRange(['port_range' => []]));
        $this->assertSame($expected, ServerResolver::portRange(['port_range' => '']));
        $this->assertSame($expected, ServerResolver::portRange(['port_range' => null]));

        $this->assertSame(
            ['start' => 31000, 'end' => 31999],
            ServerResolver::portRange(['port_range' => ['start' => '31000', 'end' => 31999]])
        );
    }

    public function testPortRangeInvalidDocumentThrowsWithServerIdentity(): void
    {
        try {
            ServerResolver::portRange([
                'id' => 'srv1',
                'name' => 'vps-1',
                'kind' => 'agent',
                'port_range' => ['start' => 40000, 'end' => 30000],
            ]);
            $this->fail('port_range cacat harus MELEMPAR, bukan diam-diam memakai rentang config');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vps-1', $e->getMessage());
            $this->assertStringContainsString('srv1', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Accessor server agent
    // ------------------------------------------------------------------

    public function testAgentAccessorsFailFastWhenRequiredValuesMissing(): void
    {
        $agent = ['id' => 'srv1', 'name' => 'vps-1', 'kind' => 'agent'];

        $this->assertThrowsWith('vps-1', static fn () => ServerResolver::baseUrl($agent));
        $this->assertThrowsWith('vps-1', static fn () => ServerResolver::token($agent));
        $this->assertThrowsWith('vps-1', static fn () => ServerResolver::tlsCertPem($agent));
        $this->assertThrowsWith('vps-1', static fn () => ServerResolver::agentPort($agent));
    }

    public function testAgentAccessorsRejectPlainHttpAndBrokenPem(): void
    {
        $http = ['id' => 'srv1', 'name' => 'vps-1', 'kind' => 'agent', 'base_url' => 'http://10.0.0.5:8443'];
        $this->assertThrowsWith('https', static fn () => ServerResolver::baseUrl($http));

        $broken = ['id' => 'srv1', 'name' => 'vps-1', 'kind' => 'agent', 'base_url' => 'https://10.0.0.5:8443', 'tls_cert_pem' => 'bukan pem'];
        $this->assertThrowsWith('X.509', static fn () => ServerResolver::tlsCertPem($broken));
    }

    public function testAgentAccessorsReturnNormalizedValues(): void
    {
        $agent = [
            'id' => 'srv1',
            'name' => 'vps-1',
            'kind' => 'agent',
            'base_url' => 'https://10.0.0.5:8443/',
            'token' => ' agt_rahasia ',
            'tls_cert_pem' => self::pem(),
            'domain' => 'domain2.com',
            'port_range' => ['start' => 31000, 'end' => 31999],
        ];

        $this->assertSame('https://10.0.0.5:8443', ServerResolver::baseUrl($agent));
        $this->assertSame('agt_rahasia', ServerResolver::token($agent));
        $this->assertSame(trim(self::pem()), ServerResolver::tlsCertPem($agent));
        $this->assertSame(8443, ServerResolver::agentPort($agent));
        $this->assertSame('domain2.com', ServerResolver::domainFor($agent));
        $this->assertSame(['start' => 31000, 'end' => 31999], ServerResolver::portRange($agent));

        // Tanpa port eksplisit ⇒ default agent (§10.6).
        $this->assertSame(
            ServerResolver::DEFAULT_AGENT_PORT,
            ServerResolver::agentPort(['kind' => 'agent', 'base_url' => 'https://agent.test'])
        );
    }

    public function testLocalServerAccessorsAreLenient(): void
    {
        $local = ['id' => 'local', 'name' => 'local', 'kind' => 'local'];

        $this->assertSame('', ServerResolver::baseUrl($local));
        $this->assertSame('', ServerResolver::token($local));
        $this->assertSame('', ServerResolver::tlsCertPem($local));
        $this->assertSame(ServerResolver::DEFAULT_AGENT_PORT, ServerResolver::agentPort($local));
    }

    // ------------------------------------------------------------------
    // validate()
    // ------------------------------------------------------------------

    public function testValidateRejectsInvalidName(): void
    {
        $this->assertThrowsWith('Nama server wajib diisi', fn () => $this->validate(['name' => '   ']));
        $this->assertThrowsWith('Nama server hanya boleh', fn () => $this->validate(['name' => 'Server Baru']));
        $this->assertThrowsWith('maksimal 32 karakter', fn () => $this->validate(['name' => str_repeat('a', 33)]));
        $this->assertThrowsWith('Nama server hanya boleh', fn () => $this->validate(['name' => '-awalan']));
    }

    public function testValidateRejectsLocalKindFromUserInput(): void
    {
        $this->assertThrowsWith(
            'hanya milik sistem',
            fn () => $this->validate(['name' => 'vps-1', 'kind' => 'local'])
        );
        $this->assertThrowsWith(
            'tidak dikenal',
            fn () => $this->validate(['name' => 'vps-1', 'kind' => 'k8s'])
        );
    }

    public function testValidateRejectsPlainHttpBaseUrl(): void
    {
        $this->assertThrowsWith(
            'https',
            fn () => $this->validate($this->payload(['base_url' => 'http://10.0.0.5:8443']))
        );
        $this->assertThrowsWith(
            'wajib diisi',
            fn () => $this->validate($this->payload(['base_url' => '']))
        );
        $this->assertThrowsWith(
            'tidak valid',
            fn () => $this->validate($this->payload(['base_url' => 'https://']))
        );
    }

    public function testValidateRejectsEmptyToken(): void
    {
        $this->assertThrowsWith(
            'Token agent wajib diisi',
            fn () => $this->validate($this->payload(['token' => '   ']))
        );
    }

    public function testValidateRejectsGarbagePem(): void
    {
        $this->assertThrowsWith(
            'X.509',
            fn () => $this->validate($this->payload(['tls_cert_pem' => 'garbage']))
        );
        $this->assertThrowsWith(
            'PEM sertifikat TLS agent wajib diisi',
            fn () => $this->validate($this->payload(['tls_cert_pem' => '']))
        );
    }

    public function testValidateRejectsInvalidDomainAndNotes(): void
    {
        $this->assertThrowsWith(
            'Domain server hanya boleh',
            fn () => $this->validate($this->payload(['domain' => 'http://domain2.com']))
        );
        $this->assertThrowsWith(
            'Domain server hanya boleh',
            fn () => $this->validate($this->payload(['domain' => 'domain2.com:8443']))
        );
        $this->assertThrowsWith(
            'Catatan server harus berupa teks',
            fn () => $this->validate($this->payload(['notes' => ['array']]))
        );
    }

    public function testValidateRejectsEnabledGarbage(): void
    {
        $this->assertThrowsWith(
            '`enabled`',
            fn () => $this->validate($this->payload(['enabled' => 'mungkin']))
        );
        $this->assertFalse($this->validate($this->payload(['enabled' => '0']))['enabled']);
    }

    public function testValidateAcceptsAndNormalizesPayload(): void
    {
        $pem = self::pem();
        $free = $this->freePortRange();
        $normalized = $this->validate($this->payload([
            'name' => '  VPS-Jakarta-2 ',
            'domain' => ' Domain2.com. ',
            'port_range' => $free['start'] . '-' . $free['end'],
            'notes' => '  server uji  ',
            // Field asing (termasuk yang bisa memalsukan identitas) harus dibuang.
            'id' => 'dibajak',
            'created_at' => '1999-01-01T00:00:00+00:00',
            'token_ref' => 'diabaikan',
            'enabled' => 'true',
        ]));

        $this->assertSame('vps-jakarta-2', $normalized['name']);
        $this->assertSame('agent', $normalized['kind']);
        $this->assertTrue($normalized['enabled']);
        $this->assertSame('domain2.com', $normalized['domain']);
        $this->assertSame('server uji', $normalized['notes']);
        $this->assertSame('https://10.0.0.5:8443', $normalized['base_url']);
        $this->assertSame('agt_rahasia', $normalized['token']);
        $this->assertSame(trim($pem), $normalized['tls_cert_pem']);
        $this->assertSame($free, $normalized['port_range']);

        $fingerprint = $normalized['tls_fingerprint'] ?? '';
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $fingerprint);
        $this->assertSame(ServerResolver::fingerprintFromPem($pem), $fingerprint);

        $this->assertArrayNotHasKey('id', $normalized);
        $this->assertArrayNotHasKey('created_at', $normalized);
        $this->assertArrayNotHasKey('token_ref', $normalized);
    }

    public function testValidateDefaultsKindToAgentAndDomainToEmpty(): void
    {
        $normalized = $this->validate($this->payload(['kind' => '', 'domain' => '', 'port_range' => null]));

        $this->assertSame('agent', $normalized['kind']);
        $this->assertSame('', $normalized['domain']);
        $this->assertArrayNotHasKey('port_range', $normalized);
        $this->assertSame(
            strtolower(trim((string) config('deploy.app_domain', 'example.com'))),
            ServerResolver::domainFor($normalized),
            'domain kosong ⇒ mengikuti APP_DOMAIN'
        );
    }

    public function testValidateRejectsMalformedPortRange(): void
    {
        $this->assertThrowsWith('Rentang port server harus', fn () => $this->validate($this->payload(['port_range' => '31000'])));
        $this->assertThrowsWith('Rentang port server harus', fn () => $this->validate($this->payload(['port_range' => ['start' => 40000, 'end' => 30000]])));
        $this->assertThrowsWith('Rentang port server harus', fn () => $this->validate($this->payload(['port_range' => ['start' => 0, 'end' => 10]])));
        $this->assertThrowsWith('Rentang port server harus', fn () => $this->validate($this->payload(['port_range' => ['start' => 31000, 'end' => 70000]])));
        $this->assertThrowsWith('Rentang port server harus', fn () => $this->validate($this->payload(['port_range' => ['start' => 'a', 'end' => 'b']])));
    }

    public function testValidateRejectsPortRangeOverlappingLocalServer(): void
    {
        $local = $this->localPortRange();

        $this->assertThrowsWith(
            'tumpang tindih',
            fn () => $this->validate($this->payload(['port_range' => $local]))
        );

        $free = $this->freePortRange();
        $this->assertFalse($free['start'] <= $local['end'] && $free['end'] >= $local['start']);
        $this->assertSame($free, $this->validate($this->payload(['port_range' => $free]))['port_range']);
    }

    // ------------------------------------------------------------------
    // fingerprint
    // ------------------------------------------------------------------

    public function testFingerprintFromPemIsNormalizedSha256(): void
    {
        $pem = self::pem();
        $cert = openssl_x509_read($pem);
        $this->assertNotFalse($cert);
        $expected = strtolower(str_replace(':', '', (string) openssl_x509_fingerprint($cert, 'sha256')));

        $fingerprint = ServerResolver::fingerprintFromPem($pem);
        $this->assertSame($expected, $fingerprint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertStringNotContainsString(':', $fingerprint);
    }

    public function testFingerprintFromPemRejectsGarbage(): void
    {
        $this->assertThrowsWith('X.509', static fn () => ServerResolver::fingerprintFromPem('bukan sertifikat'));
        $this->assertThrowsWith('belum diisi', static fn () => ServerResolver::fingerprintFromPem('  '));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function resolver(): ServerResolver
    {
        return new ServerResolver(new ServerStore($this->file));
    }

    /**
     * Rentang efektif server lokal (config) — dihitung dari `config()` agar uji
     * tidak bergantung pada nilai env tertentu.
     *
     * @return array{start:int,end:int}
     */
    private function localPortRange(): array
    {
        return ServerResolver::portRange(['kind' => 'local']);
    }

    /**
     * Rentang port yang pasti TIDAK tumpang tindih dengan rentang server lokal
     * (validasi `validate()` menolak tumpang tindih).
     *
     * @return array{start:int,end:int}
     */
    private function freePortRange(): array
    {
        $local = $this->localPortRange();

        return $local['start'] > 2
            ? ['start' => $local['start'] - 100, 'end' => $local['start'] - 1]
            : ['start' => $local['end'] + 1, 'end' => min(65535, $local['end'] + 100)];
    }

    /**
     * Payload form server yang valid (server agent), siap di-override per uji.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'vps-1',
            'kind' => 'agent',
            'base_url' => 'https://10.0.0.5:8443',
            'token' => 'agt_rahasia',
            'tls_cert_pem' => self::pem(),
            'domain' => 'domain2.com',
        ], $overrides);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function validate(array $payload): array
    {
        return $this->resolver()->validate($payload);
    }

    /** @param callable():mixed $callback */
    private function assertThrowsWith(string $needle, callable $callback): void
    {
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Diharapkan RuntimeException memuat \"{$needle}\"");
    }

    private static function pem(): string
    {
        return self::$pem ??= self::selfSignedPem('agent.test');
    }

    /** Sertifikat self-signed sekali pakai untuk uji (tanpa menyentuh jaringan). */
    private static function selfSignedPem(string $commonName): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        return (string) $pem;
    }
}
