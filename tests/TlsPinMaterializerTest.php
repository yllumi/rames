<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use PHPUnit\Framework\TestCase;

/**
 * Test TlsPinMaterializer (misi F0, kontrol pinning TLS control plane → agent).
 *
 * Yang dikunci: materialisasi PEM ke berkas (isi + izin), tidak menulis ulang
 * bila isinya sama, penolakan PEM hilang/sampah (**tanpa** fallback tidak aman),
 * fingerprint SHA-256 ternormalisasi, pemangkasan berkas server yang sudah tidak
 * ada, dan pengamanan nama berkas (id server tidak boleh keluar direktori).
 *
 * Seluruh uji memakai direktori `/tmp` — tidak pernah menyentuh `runtime/` nyata.
 */
class TlsPinMaterializerTest extends TestCase
{
    /** Sertifikat self-signed dibuat sekali per proses (murah: ~100 ms). */
    private static ?string $pem = null;
    private static ?string $otherPem = null;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/tls_pin_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    private function materializer(): TlsPinMaterializer
    {
        return new TlsPinMaterializer($this->tmp . '/tls');
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function server(array $overrides = []): array
    {
        return array_merge([
            'id' => 'srv1',
            'base_url' => 'https://agent.test:8443',
            'tls_cert_pem' => self::pem(),
        ], $overrides);
    }

    public function testEnsureMaterializesCertWithRestrictedPermissions(): void
    {
        $materializer = $this->materializer();
        $path = $materializer->ensure($this->server());

        $this->assertSame($this->tmp . '/tls/srv1.crt', $path);
        $this->assertFileExists($path);
        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', (string) file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(dirname($path)) & 0777);
    }

    public function testEnsureDoesNotRewriteUnchangedCert(): void
    {
        $materializer = $this->materializer();
        $path = $materializer->ensure($this->server());

        $old = time() - 3600;
        touch($path, $old);
        clearstatcache();

        $this->assertSame($path, $materializer->ensure($this->server()));

        clearstatcache();
        $this->assertSame($old, filemtime($path), 'PEM identik tidak boleh ditulis ulang');
        $this->assertSame([], glob($this->tmp . '/tls/*.tmp.*') ?: [], 'tidak ada berkas sementara tertinggal');
    }

    public function testEnsureRewritesChangedCert(): void
    {
        $materializer = $this->materializer();
        $path = $materializer->ensure($this->server());
        $before = (string) file_get_contents($path);

        $materializer->ensure($this->server(['tls_cert_pem' => self::otherPem()]));
        clearstatcache();

        $after = (string) file_get_contents($path);
        $this->assertNotSame($before, $after);
        $this->assertSame(trim(self::otherPem()), trim($after));
    }

    public function testEnsureRejectsMissingPem(): void
    {
        $error = $this->expectAgentError(AgentError::INVALID_CONFIG, function (): void {
            $this->materializer()->ensure(['id' => 'srv1']);
        });

        $this->assertStringContainsString('tls_cert_pem', $error->getMessage());
        $this->assertDirectoryDoesNotExist($this->tmp . '/tls', 'PEM tidak valid tidak boleh menulis apa pun');
    }

    public function testEnsureRejectsGarbagePem(): void
    {
        foreach (['bukan sertifikat sama sekali', "-----BEGIN CERTIFICATE-----\nnb\n-----END CERTIFICATE-----\n"] as $garbage) {
            try {
                $this->materializer()->ensure($this->server(['tls_cert_pem' => $garbage]));
                $this->fail('PEM sampah harus ditolak fail-fast');
            } catch (AgentError $e) {
                $this->assertSame(AgentError::INVALID_CONFIG, $e->reason);
            }
        }

        $this->assertDirectoryDoesNotExist($this->tmp . '/tls');
    }

    public function testFingerprintIsSha256LowercaseWithoutSeparators(): void
    {
        $fingerprint = $this->materializer()->fingerprint($this->server());
        $expected = (string) openssl_x509_fingerprint(self::pem(), 'sha256');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertSame(strtolower(str_replace(':', '', $expected)), $fingerprint);
    }

    public function testFingerprintRejectsMissingPem(): void
    {
        $this->expectAgentError(AgentError::INVALID_CONFIG, function (): void {
            $this->materializer()->fingerprint(['id' => 'srv1', 'tls_cert_pem' => '']);
        });
    }

    public function testPruneRemovesCertificatesOfUnknownServers(): void
    {
        $materializer = $this->materializer();
        $materializer->ensure($this->server(['id' => 'srv1']));
        $materializer->ensure($this->server(['id' => 'srv2']));
        file_put_contents($this->tmp . '/tls/srv3.crt.tmp.99', 'sisa tulisan gagal');

        $this->assertSame(2, $materializer->prune(['srv1']));
        $this->assertFileExists($this->tmp . '/tls/srv1.crt');
        $this->assertFileDoesNotExist($this->tmp . '/tls/srv2.crt');
        $this->assertFileDoesNotExist($this->tmp . '/tls/srv3.crt.tmp.99');
    }

    public function testPruneWithoutDirectoryIsNoop(): void
    {
        $this->assertSame(0, (new TlsPinMaterializer($this->tmp . '/belum-ada'))->prune(['srv1']));
    }

    public function testServerIdCannotEscapeDirectory(): void
    {
        $path = $this->materializer()->ensure($this->server(['id' => '../../etc/passwd']));

        $this->assertSame($this->tmp . '/tls', dirname($path));
        $this->assertStringNotContainsString('..', basename($path));
        $this->assertStringNotContainsString('/', basename($path));
    }

    public function testServerWithoutIdIsRejected(): void
    {
        $this->expectAgentError(AgentError::INVALID_CONFIG, function (): void {
            $this->materializer()->ensure($this->server(['id' => '  ']));
        });
    }

    public function testDirIsOverridableAndDefaultPointsToRuntime(): void
    {
        $this->assertSame($this->tmp . '/tls', $this->materializer()->dir());
        $this->assertStringEndsWith('/agent-tls', (new TlsPinMaterializer())->dir());
        // Override dengan string kosong = pakai default (bukan direktori kosong).
        $this->assertStringEndsWith('/agent-tls', (new TlsPinMaterializer('  '))->dir());
    }

    /** @param callable():void $callback */
    private function expectAgentError(string $reason, callable $callback): AgentError
    {
        try {
            $callback();
        } catch (AgentError $e) {
            $this->assertSame($reason, $e->reason);

            return $e;
        }

        $this->fail("Diharapkan AgentError({$reason})");
    }

    private static function pem(): string
    {
        return self::$pem ??= self::selfSignedPem('agent.test');
    }

    private static function otherPem(): string
    {
        return self::$otherPem ??= self::selfSignedPem('other.test');
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

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
