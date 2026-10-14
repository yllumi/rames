<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentArtifactMaterializer;
use app\library\Agent\AgentArtifactPackager;
use app\library\Deploy\ComposeSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Test `AgentArtifactPackager` — sisi **control plane** misi `ms-f1-artifacts`.
 *
 * Yang dikunci (plan §4.3 + §11 catatan 4):
 *  1. app mode compose ⇒ isi file compose utama **beserta namanya** (dari
 *     `compose_files`, bukan tebakan `docker-compose.yml`); file hilang ⇒
 *     melempar dengan pesan yang menjelaskan sebabnya (job tidak dikirim supaya
 *     agent tidak gagal di tengah);
 *  2. app repo + `auth_method=ssh` ⇒ isi private key deploy dibaca dari lokasi
 *     yang **sama** dengan yang dibaca `LocalDeployer` di agent
 *     (`{database_path}/{ssh_key}`, fallback `SshKeyManager::keysDir()`);
 *     berkas absen ⇒ `null` (kontrak);
 *  3. app ber-template ⇒ hanya **slug** yang dikirim (agent membaca
 *     `templates/<slug>/` miliknya sendiri); slug tak sah ⇒ ditolak;
 *  4. rahasia (isi kunci) **tidak pernah** muncul di pesan galat;
 *  5. bentuk keluaran = kontrak `AgentArtifactPackager::KEYS` (kunci lengkap,
 *     `files` kosong karena berkas pendukung belum dilacak per app).
 *
 * Seluruh uji memakai direktori **temp** (tidak menyentuh `apps/`, `database/`,
 * atau git/docker nyata).
 */
class AgentArtifactPackagerTest extends TestCase
{
    private const KEY_PEM = "-----BEGIN OPENSSH PRIVATE KEY-----\nZml4dHVyZS1rZXktaXNpLXJhaGFzaWE=\n-----END OPENSSH PRIVATE KEY-----\n";

    private string $tmp = '';
    private AgentArtifactPackager $packager;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-packager-' . bin2hex(random_bytes(4));
        foreach (['/apps/demo', '/database/keys', '/database/env'] as $dir) {
            if (!mkdir($this->tmp . $dir, 0777, true) && !is_dir($this->tmp . $dir)) {
                throw new RuntimeException('gagal menyiapkan direktori temp');
            }
        }

        $this->packager = new AgentArtifactPackager(
            $this->tmp . '/apps',
            $this->tmp . '/database/keys',
            $this->tmp . '/database'
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // Mode compose
    // ==================================================================

    public function testComposeAppPacksMainFileContentAndItsDeclaredName(): void
    {
        $content = "services:\n  web:\n    image: nginx:alpine\n";
        file_put_contents($this->tmp . '/apps/demo/compose.yaml', $content);

        $artifacts = $this->packager->pack($this->app([
            'source' => 'compose',
            'compose_files' => ['compose.yaml', 'docker-compose.override.env.yml'],
            'repo_url' => null,
            'branch' => null,
        ]));

        $this->assertSame($content, $artifacts['compose']);
        $this->assertSame('compose.yaml', $artifacts['compose_name']);
        $this->assertNull($artifacts['template']);
        $this->assertNull($artifacts['ssh_key']);
        $this->assertSame([], $artifacts['files']);
    }

    /** Bentuk keluaran = kontrak lintas-sisi (kunci lengkap, tidak lebih). */
    public function testPackReturnsExactlyTheContractKeys(): void
    {
        $artifacts = $this->packager->pack($this->app());

        $this->assertEqualsCanonicalizing(AgentArtifactPackager::KEYS, array_keys($artifacts));
    }

    public function testComposeAppWithoutComposeFileIsRejectedWithClearMessage(): void
    {
        try {
            $this->packager->pack($this->app([
                'source' => 'compose',
                'compose_files' => ['docker-compose.yml'],
                'auth_method' => 'ssh',
                'ssh_key' => 'keys/demo',
            ]));
            $this->fail('file compose hilang harus ditolak sebelum job dikirim');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('docker-compose.yml', $e->getMessage());
            $this->assertStringContainsString('tidak ada di server ini', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY_PEM, $e->getMessage(), 'isi kunci tidak boleh di pesan galat');
        }
    }

    public function testOversizedComposeIsRejected(): void
    {
        file_put_contents(
            $this->tmp . '/apps/demo/docker-compose.yml',
            "services:\n  web:\n    image: nginx:alpine\n" . str_repeat('# padding' . "\n", 130000)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('terlalu besar');
        $this->packager->pack($this->app(['source' => 'compose', 'compose_files' => ['docker-compose.yml']]));
    }

    // ==================================================================
    // Deploy key SSH
    // ==================================================================

    public function testGitSshAppPacksKeyFromDatabasePathRelativeSshKey(): void
    {
        file_put_contents($this->tmp . '/database/keys/demo', self::KEY_PEM);

        $artifacts = $this->packager->pack($this->app([
            'source' => 'git',
            'auth_method' => 'ssh',
            'ssh_key' => 'keys/demo',
        ]));

        $this->assertSame(self::KEY_PEM, $artifacts['ssh_key'], 'kunci dibaca dari {database_path}/{ssh_key} seperti LocalDeployer agent');
        $this->assertNull($artifacts['compose']);
        $this->assertSame([], $artifacts['files']);
    }

    /** App tanpa `ssh_key` ⇒ lokasi kanonik `SshKeyManager::keysDir()/{name}`. */
    public function testGitSshAppWithoutSshKeyFieldUsesCanonicalKeysDir(): void
    {
        file_put_contents($this->tmp . '/database/keys/demo', self::KEY_PEM);

        $artifacts = $this->packager->pack($this->app([
            'source' => 'git',
            'auth_method' => 'ssh',
            'ssh_key' => null,
        ]));

        $this->assertSame(self::KEY_PEM, $artifacts['ssh_key']);
    }

    /** Repo publik (tanpa deploy key) ⇒ tidak ada artefak sama sekali. */
    public function testGitPublicAppPacksNothing(): void
    {
        $artifacts = $this->packager->pack($this->app([
            'source' => 'git',
            'auth_method' => 'none',
        ]));

        $this->assertSame([
            'compose' => null,
            'compose_name' => null,
            'template' => null,
            'ssh_key' => null,
            'files' => [],
        ], $artifacts);
    }

    public function testMissingKeyFilePacksNullInsteadOfGuessing(): void
    {
        $artifacts = $this->packager->pack($this->app([
            'source' => 'git',
            'auth_method' => 'ssh',
            'ssh_key' => 'keys/demo',
        ]));

        $this->assertNull($artifacts['ssh_key']);
    }

    public function testSshKeyPathTraversalIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->packager->pack($this->app([
            'source' => 'git',
            'auth_method' => 'ssh',
            'ssh_key' => '../../etc/passwd',
        ]));
    }

    // ==================================================================
    // Template (slug, bukan isi berkas)
    // ==================================================================

    public function testTemplateAppPacksSlugOnly(): void
    {
        $artifacts = $this->packager->pack($this->app([
            'source' => 'compose',
            'compose_files' => ['docker-compose.yml'],
            'template' => ['slug' => 'mysql', 'title' => 'MySQL'],
        ]));

        $this->assertSame('mysql', $artifacts['template']);
        $this->assertNull($artifacts['compose'], 'isi compose tidak dikirim: agent memakai templates/<slug>/ miliknya');
        $this->assertSame([], $artifacts['files']);
    }

    /** Data lama menyimpan slug sebagai string — tetap diterima. */
    public function testTemplateSlugFromStringFormIsAccepted(): void
    {
        $artifacts = $this->packager->pack($this->app(['template' => 'n8n']));

        $this->assertSame('n8n', $artifacts['template']);
    }

    public function testInvalidTemplateSlugIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug template yang tidak sah');
        $this->packager->pack($this->app(['template' => ['slug' => '../../etc']]));
    }

    // ==================================================================
    // Nama app (segmen direktori di agent)
    // ==================================================================

    public function testAppNameWithPathSeparatorIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->packager->pack($this->app(['name' => 'a/b']));
    }

    // ==================================================================
    // Master compose dari metadata control plane (misi F1 celah A)
    // ==================================================================

    /**
     * App mode compose di server **agent** tidak punya direktori artefak di host
     * ini: master compose-nya hidup di metadata app (`compose_content`, ditulis
     * editor Compose) — dan itulah yang dikirim, dengan nama file dari aturan
     * repo (`compose_files[0]`), bukan tebakan.
     */
    public function testComposeContentFromMetadataIsSentWithoutLocalFile(): void
    {
        $content = "services:\n  web:\n    image: nginx:alpine\n";

        $artifacts = $this->packager->pack($this->app([
            'source' => 'compose',
            'compose_files' => ['compose.yaml'],
            'compose_content' => $content,
        ]));

        $this->assertSame($content, $artifacts['compose']);
        $this->assertSame('compose.yaml', $artifacts['compose_name']);
    }

    // ==================================================================
    // Override port (pilihan host port user) — misi F1 celah B
    // ==================================================================

    /**
     * Rencana host port app (`host_ports`) dibangkitkan menjadi **dua lapis**
     * override port yang sama dengan jalur lokal: reset (`!reset []`) lalu port
     * final (`host:container`) — supaya agent tidak memakai port bawaan repo.
     */
    public function testHostPortPlanRendersPortOverrideFiles(): void
    {
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");

        $artifacts = $this->packager->pack($this->app([
            'source' => 'compose',
            'compose_files' => ['docker-compose.yml'],
            'host_ports' => [
                ['service' => 'web', 'container' => 80, 'host' => 30500],
                ['service' => 'api', 'container' => 8080, 'host' => 30501],
            ],
        ]));

        $this->assertSame(
            [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            array_keys($artifacts['files']),
            'hanya dua lapis override port yang dikirim (bukan env/nama/limits)'
        );

        $reset = $artifacts['files'][ComposeSource::RESET_OVERRIDE_FILE];
        $ports = $artifacts['files'][ComposeSource::PORTS_OVERRIDE_FILE];

        // Bentuk YAML identik dengan jalur lokal: `ports: !reset` per service, lalu
        // port final `host:container` (di-quote sebagai string oleh dump).
        $resetParsed = Yaml::parse($reset, Yaml::PARSE_CUSTOM_TAGS);
        foreach (['web', 'api'] as $service) {
            $this->assertInstanceOf(TaggedValue::class, $resetParsed['services'][$service]['ports'] ?? null, $service);
            $this->assertSame('reset', $resetParsed['services'][$service]['ports']->getTag());
            $this->assertSame([], $resetParsed['services'][$service]['ports']->getValue());
        }

        $portsParsed = Yaml::parse($ports);
        $this->assertSame(['30500:80'], $portsParsed['services']['web']['ports']);
        $this->assertSame(['30501:8080'], $portsParsed['services']['api']['ports']);

        // Hasilnya harus lolos validasi sisi agent (kontrak lintas-sisi).
        $this->assertIsArray(AgentArtifactMaterializer::validate($artifacts));
    }

    /** Port yang hanya diekspos (tanpa host publish) tetap di-reset, tanpa `host:container`. */
    public function testHostPortPlanKeepsExposedOnlyPorts(): void
    {
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");

        $artifacts = $this->packager->pack($this->app([
            'source' => 'compose',
            'compose_files' => ['docker-compose.yml'],
            'host_ports' => [['service' => 'web', 'container' => 3000, 'host' => null]],
        ]));

        $portsParsed = Yaml::parse($artifacts['files'][ComposeSource::PORTS_OVERRIDE_FILE]);
        $this->assertSame(['3000'], $portsParsed['services']['web']['ports']);
    }

    /**
     * Tanpa rencana di metadata, berkas override yang **sudah** ditulis jalur
     * lokal (app compose/template lama) tetap diteruskan ke agent — kalau tidak,
     * app itu kehilangan port pilihan user saat pindah ke agent.
     */
    public function testPortOverrideFallsBackToLocalGeneratedFiles(): void
    {
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        file_put_contents($this->tmp . '/apps/demo/' . ComposeSource::RESET_OVERRIDE_FILE, "services: {}\n");
        file_put_contents($this->tmp . '/apps/demo/' . ComposeSource::PORTS_OVERRIDE_FILE, "services:\n  web:\n    ports:\n      - 30500:80\n");

        $artifacts = $this->packager->pack($this->app([
            'source' => 'compose',
            'compose_files' => ['docker-compose.yml'],
        ]));

        $this->assertSame(
            [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            array_keys($artifacts['files'])
        );
    }

    /** Rencana port yang tidak sah **menolak** job (jangan menulis port yang salah ke agent). */
    public function testInvalidHostPortPlanIsRejected(): void
    {
        foreach ([
            'bukan daftar' => 'web',
            'entri bukan map' => ['web'],
            'service kosong' => [['service' => '', 'container' => 80, 'host' => 30500]],
            'service berjalur' => [['service' => '../web', 'container' => 80, 'host' => 30500]],
            'port container nol' => [['service' => 'web', 'container' => 0, 'host' => 30500]],
            'port host di luar rentang' => [['service' => 'web', 'container' => 80, 'host' => 70000]],
        ] as $label => $plan) {
            try {
                $this->packager->pack($this->app(['host_ports' => $plan]));
                $this->fail("rencana port tidak sah ({$label}) harus ditolak");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('host_ports', $e->getMessage(), $label);
            }
        }
    }

    /** App ber-template juga membawa override port (agent membaca template-nya sendiri). */
    public function testTemplateAppStillCarriesPortOverrides(): void
    {
        $artifacts = $this->packager->pack($this->app([
            'template' => ['slug' => 'n8n'],
            'host_ports' => [['service' => 'n8n', 'container' => 5678, 'host' => 30700]],
        ]));

        $this->assertSame('n8n', $artifacts['template']);
        $this->assertSame(
            [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE],
            array_keys($artifacts['files'])
        );
    }

    /**
     * App asal template yang compose-nya **disunting** (tab Compose) mengirim
     * compose hasil edit — bukan slug template (agent akan menimpa edit itu bila
     * template dimaterialisasi ulang).
     */
    public function testEditedComposeWinsOverTemplateSlug(): void
    {
        $edited = "services:\n  n8n:\n    image: n8nio/n8n:1.0\n";

        $artifacts = $this->packager->pack($this->app([
            'source' => 'compose',
            'template' => ['slug' => 'n8n'],
            'compose_files' => ['docker-compose.yml'],
            'compose_content' => $edited,
        ]));

        $this->assertNull($artifacts['template']);
        $this->assertSame($edited, $artifacts['compose']);
        $this->assertSame('docker-compose.yml', $artifacts['compose_name']);
    }

    // ==================================================================
    // Kontrak dengan materializer (bentuk hasil pack selalu bisa divalidasi)
    // ==================================================================

    public function testPackedArtifactsPassAgentValidation(): void
    {
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        file_put_contents($this->tmp . '/database/keys/demo', self::KEY_PEM);

        foreach ([
            $this->app(['source' => 'compose', 'compose_files' => ['docker-compose.yml']]),
            $this->app(['source' => 'git', 'auth_method' => 'ssh', 'ssh_key' => 'keys/demo']),
            $this->app(['template' => ['slug' => 'mysql']]),
            $this->app(),
        ] as $app) {
            $this->assertIsArray(AgentArtifactMaterializer::validate($this->packager->pack($app)));
        }
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function app(array $overrides = []): array
    {
        return array_merge([
            'id' => 'a1b2c3d4e5f60718',
            'name' => 'demo',
            'source' => 'git',
            'repo_url' => 'https://example.test/a.git',
            'branch' => 'main',
            'auth_method' => 'none',
            'ssh_key' => null,
            'compose_files' => ['docker-compose.yml'],
        ], $overrides);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
