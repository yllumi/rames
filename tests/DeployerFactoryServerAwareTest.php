<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\LocalDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use GuzzleHttp\Handler\MockHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Test `DeployerFactory` sadar server (misi `ms-f0-resolver`, diperbarui misi
 * `ms-f1-deploy`).
 *
 * Penjaga utama (plan §3 butir 4): app di server agent **tidak pernah** jatuh ke
 * `LocalDeployer` (eksekusi docker compose di server yang salah):
 *  - `createFor(kind=agent)` ⇒ `AgentDeployer` (seluruh eksekusi lewat HTTP ke
 *    agent — F1);
 *  - konfigurasi server agent yang cacat (mis. tanpa `base_url`/token/PEM) ⇒
 *    **AgentError** ber-identitas server, bukan fallback lokal;
 *  - `kind` tak dikenal / kosong ⇒ RuntimeException fail-fast.
 * `create()` lama harus tetap berperilaku sama, termasuk hook `DEPLOYER_CLASS`.
 *
 * Store server selalu di path temp (`sys_get_temp_dir()`); `create()`/
 * `createFor(kind=local)` tidak menyentuh store sama sekali. Klien agent
 * (Guzzle handler tiruan + sertifikat temp) disuntikkan supaya tidak ada
 * jaringan, token nyata, maupun berkas `runtime/agent-tls` yang tersentuh.
 */
class DeployerFactoryServerAwareTest extends TestCase
{
    private string $file;

    /** Direktori sertifikat TLS temp (jangan menyentuh `runtime/agent-tls`). */
    private string $tlsDir;

    /** Nilai `DEPLOYER_CLASS` sebelum tes (dipulihkan di tearDown). */
    private string|false $deployerClass;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->file = sys_get_temp_dir() . '/rames-deployer-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->tlsDir = sys_get_temp_dir() . '/rames-deployer-tls-' . bin2hex(random_bytes(4));
        $this->deployerClass = getenv('DEPLOYER_CLASS');
    }

    protected function tearDown(): void
    {
        $this->restoreDeployerClass();
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->file);
        $this->removeTree($this->tlsDir);
    }

    // ------------------------------------------------------------------
    // create() lama — perilaku tidak boleh berubah
    // ------------------------------------------------------------------

    public function testCreateStillReturnsLocalDeployer(): void
    {
        $this->withoutOverride(function (): void {
            $this->assertInstanceOf(LocalDeployer::class, DeployerFactory::create());
        });
    }

    public function testCreateStillHonorsDeployerClassOverride(): void
    {
        putenv('DEPLOYER_CLASS=' . FakeCliDeployer::class);

        $this->assertInstanceOf(FakeCliDeployer::class, DeployerFactory::create());
    }

    // ------------------------------------------------------------------
    // createFor()
    // ------------------------------------------------------------------

    public function testCreateForLocalServerReturnsLocalDeployer(): void
    {
        $this->withoutOverride(function (): void {
            $local = ['id' => ServerStore::LOCAL_ID, 'name' => 'local', 'kind' => 'local', 'domain' => ''];
            $this->assertInstanceOf(LocalDeployer::class, DeployerFactory::createFor($local));

            // kind=local tetap lokal walau id-nya aneh.
            $odd = ['id' => 'apa-saja', 'name' => 'server-ini', 'kind' => 'LOCAL'];
            $this->assertInstanceOf(LocalDeployer::class, DeployerFactory::createFor($odd));
        });
    }

    public function testCreateForAgentServerReturnsAgentDeployer(): void
    {
        $deployer = DeployerFactory::createFor($this->agentServer(), $this->agentClient());

        $this->assertInstanceOf(AgentDeployer::class, $deployer);
        $this->assertNotInstanceOf(LocalDeployer::class, $deployer);
    }

    /**
     * Konfigurasi server agent cacat (mis. `base_url` hilang — server dibuat
     * lewat store tanpa diisi) ⇒ AgentError ber-identitas server. Yang WAJIB
     * dijaga: bukan `LocalDeployer`, dan bukan galat tanpa jejak server mana.
     */
    public function testCreateForAgentServerWithBrokenConfigThrowsWithServerIdentity(): void
    {
        foreach ([
            ['id' => 'srv-2', 'name' => 'vps-jakarta-2', 'kind' => 'agent'],                     // base_url kosong
            ['id' => 'srv-3', 'name' => 'vps-jakarta-3', 'kind' => 'agent', 'base_url' => 'http://agent.test'], // bukan https
        ] as $server) {
            try {
                DeployerFactory::createFor($server);
                $this->fail('konfigurasi server agent cacat harus ditolak, bukan dijalankan lokal');
            } catch (AgentError $e) {
                $this->assertStringContainsString((string) $server['id'], $e->getMessage());
                $this->assertStringContainsString((string) $server['name'], $e->getMessage());
            }
        }
    }

    public function testCreateForAgentServerThrowsEvenWhenNameMissing(): void
    {
        try {
            DeployerFactory::createFor(['id' => 'srv-3', 'kind' => 'agent']);
            $this->fail('kind=agent tanpa konfigurasi harus ditolak walau tanpa nama');
        } catch (AgentError $e) {
            $this->assertStringContainsString('srv-3', $e->getMessage());
        }
    }

    public function testCreateForUnknownKindThrows(): void
    {
        try {
            DeployerFactory::createFor(['id' => 'srv-4', 'name' => 'aneh', 'kind' => 'kubernetes']);
            $this->fail('kind tak dikenal harus ditolak (fail-fast), bukan dianggap lokal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('aneh', $e->getMessage());
            $this->assertStringContainsString('kubernetes', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        DeployerFactory::createFor(['id' => 'srv-5', 'kind' => '']);
    }

    // ------------------------------------------------------------------
    // createForApp()
    // ------------------------------------------------------------------

    public function testCreateForAppWithoutServerIdUsesLocal(): void
    {
        $this->withoutOverride(function (): void {
            $resolver = $this->resolver();
            $this->assertInstanceOf(LocalDeployer::class, DeployerFactory::createForApp(['name' => 'lama'], $resolver));
            $this->assertInstanceOf(
                LocalDeployer::class,
                DeployerFactory::createForApp(['name' => 'lama', 'server_id' => ServerStore::LOCAL_ID], $resolver)
            );
        });
    }

    public function testCreateForAppWithAgentServerIdReturnsAgentDeployerAndNeverLocal(): void
    {
        $server = $this->serverStore()->create(['name' => 'vps-bandung', 'kind' => 'agent'] + $this->agentConfig());

        $deployer = DeployerFactory::createForApp(
            ['name' => 'remote-app', 'server_id' => $server['id']],
            $this->resolver(),
            $this->agentClient()
        );

        $this->assertInstanceOf(AgentDeployer::class, $deployer);
        $this->assertNotInstanceOf(LocalDeployer::class, $deployer);
    }

    public function testCreateForAppWithUnknownServerIdThrows(): void
    {
        try {
            DeployerFactory::createForApp(['name' => 'remote-app', 'server_id' => 'server-hantu'], $this->resolver());
            $this->fail('server_id tak dikenal harus MELEMPAR (tidak diam-diam lokal)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('server-hantu', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function serverStore(): ServerStore
    {
        return new ServerStore($this->file);
    }

    private function resolver(): ServerResolver
    {
        return new ServerResolver($this->serverStore());
    }

    /**
     * Dokumen server agent lengkap (base_url https + token + PEM) — sama seperti
     * baris yang ditulis `ServerController` di produksi.
     *
     * @return array<string,mixed>
     */
    private function agentServer(): array
    {
        return ['id' => 'srv-2', 'name' => 'vps-jakarta-2', 'kind' => 'agent'] + $this->agentConfig();
    }

    /**
     * Konfigurasi koneksi agent (tanpa identitas server).
     *
     * @return array<string,string>
     */
    private function agentConfig(): array
    {
        return [
            'base_url' => 'https://agent.test:8443',
            'token' => 'tok-agent-uji-f1',
            'tls_cert_pem' => $this->selfSignedPem(),
        ];
    }

    /** Klien agent dengan handler tiruan (tidak pernah ada request di tes ini). */
    private function agentClient(): AgentClient
    {
        return new AgentClient(
            $this->agentServer(),
            new MockHandler([]),
            25,
            new TlsPinMaterializer($this->tlsDir)
        );
    }

    /** Sertifikat self-signed sekali pakai (pola `AgentClientTest`). */
    private function selfSignedPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        return (string) $pem;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Jalankan tanpa hook `DEPLOYER_CLASS` (seperti proses produksi), lalu
     * pulihkan nilainya — supaya tes lain yang menyetel env itu tidak terpengaruh.
     *
     * @param callable():void $callback
     */
    private function withoutOverride(callable $callback): void
    {
        putenv('DEPLOYER_CLASS');
        try {
            $callback();
        } finally {
            $this->restoreDeployerClass();
        }
    }

    private function restoreDeployerClass(): void
    {
        if ($this->deployerClass === false || $this->deployerClass === '') {
            putenv('DEPLOYER_CLASS');
            return;
        }

        putenv('DEPLOYER_CLASS=' . $this->deployerClass);
    }
}
