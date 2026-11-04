<?php
declare(strict_types=1);

namespace Tests;

use app\library\Deploy\ServerResolver;
use app\library\Provision\ProvisionOnboarding;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Parser berkas onboarding agent + pendaftaran server ke control plane.
 *
 * Yang dikunci:
 *  - format berkas `agent/install.sh --onboarding-file` (`KUNCI=nilai` + blok
 *    PEM) terbaca, termasuk kunci pelengkap;
 *  - pesan galat **hanya** menyebut nama kunci — token/PEM tidak pernah bocor;
 *  - registrasi sukses memakai jalur validasi yang sama dengan form Add Server
 *    ({@see ServerResolver}) dan mengembalikan `agent_version`;
 *  - verifikasi `GET /version` gagal ⇒ baris server yang baru dibuat dihapus
 *    lagi (registry tidak menyimpan server yang tak bisa dihubungi);
 *  - nama duplikat ditolak **sebelum** apa pun ditulis.
 *
 * SQLite & berkas onboarding selalu di direktori temp (dibersihkan di
 * `tearDown`); `database/rames.sqlite` dan `database/keys` nyata tidak disentuh.
 */
class ProvisionOnboardingTest extends TestCase
{
    private const TOKEN = 'token-rahasia-9f2c';

    private string $tmp;
    private string $dbFile;
    private string $pem;

    protected function setUp(): void
    {
        SqliteDatabase::reset();
        $this->tmp = sys_get_temp_dir() . '/rames-onboarding-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
        $this->dbFile = $this->tmp . '/rames.sqlite';
        $this->pem = self::certificate();

        if ($this->pem === '') {
            $this->markTestSkipped('OpenSSL tidak bisa membuat sertifikat uji.');
        }
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        StoreDbFiles::remove($this->dbFile);
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testParseReadsKeysAndPemBlock(): void
    {
        $parsed = ProvisionOnboarding::parse($this->content());

        $this->assertSame('1', $parsed['version']);
        $this->assertSame('https://10.0.0.9:8443', $parsed['url']);
        $this->assertSame(self::TOKEN, $parsed['token']);
        $this->assertSame('agent.example.test', $parsed['domain']);
        $this->assertSame(41000, $parsed['port_range_start']);
        $this->assertSame(41999, $parsed['port_range_end']);
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $parsed['pem']);
        $this->assertStringEndsWith('-----END CERTIFICATE-----', $parsed['pem']);
        $this->assertSame('1.4.2', $parsed['agent_version']);
        $this->assertSame('2026-01-01T00:00:00Z', $parsed['generated_at']);
    }

    public function testMissingKeyMessageNamesOnlyTheKey(): void
    {
        $content = $this->content(['TOKEN' => null]);

        try {
            ProvisionOnboarding::parse($content);
            $this->fail('TOKEN yang hilang harus ditolak');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('TOKEN', $message);
            $this->assertStringNotContainsString('https://10.0.0.9:8443', $message, 'nilai URL tidak boleh ikut');
            $this->assertStringNotContainsString('agent.example.test', $message, 'nilai DOMAIN tidak boleh ikut');
            $this->assertStringNotContainsString('BEGIN CERTIFICATE', $message, 'PEM tidak boleh ikut');
        }
    }

    public function testMissingPemBlockIsReportedAsPemKey(): void
    {
        $content = str_replace(
            ProvisionOnboarding::PEM_BEGIN . "\n" . $this->pem . "\n" . ProvisionOnboarding::PEM_END,
            '',
            $this->content()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PEM/');
        ProvisionOnboarding::parse($content);
    }

    public function testUnsupportedVersionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/RAMES_ONBOARDING_VERSION/');
        ProvisionOnboarding::parse($this->content(['RAMES_ONBOARDING_VERSION' => '2']));
    }

    public function testNonNumericPortRangeIsRejectedByName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PORT_RANGE_START/');
        ProvisionOnboarding::parse($this->content(['PORT_RANGE_START' => 'tiga-puluh-ribu']));
    }

    public function testRegisterCreatesServerAndReturnsAgentVersion(): void
    {
        $stores = new ServerStore($this->dbFile);
        $file = $this->writeOnboarding();

        $document = ProvisionOnboarding::register($this->job(), $file, $stores, new ServerResolver($stores), static fn (array $server): object => new class {
            public function version(): array
            {
                return ['agent_version' => '1.4.2', 'capabilities' => ['deploy']];
            }
        });

        $this->assertSame('1.4.2', $document['agent_version']);
        $this->assertSame('agent-01', $document['name']);

        $stored = $stores->findByName('agent-01');
        $this->assertNotNull($stored, 'server harus tersimpan');
        $this->assertSame('https://10.0.0.9:8443', $stored['base_url']);
        $this->assertSame(41000, $stored['port_range']['start']);
        $this->assertSame('agent.example.test', $stored['domain']);
        $this->assertSame((string) $document['id'], (string) $stored['id']);
    }

    public function testVerificationFailureDeletesServerRowAndHidesToken(): void
    {
        $stores = new ServerStore($this->dbFile);
        $file = $this->writeOnboarding();
        $token = self::TOKEN;

        try {
            ProvisionOnboarding::register(
                $this->job(),
                $file,
                $stores,
                new ServerResolver($stores),
                static fn (array $server): object => new class($token) {
                    public function __construct(private readonly string $token)
                    {
                    }

                    public function version(): array
                    {
                        throw new RuntimeException('agent menolak token ' . $this->token . ' (401)');
                    }
                }
            );
            $this->fail('verifikasi gagal harus melempar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Verifikasi koneksi ke agent gagal', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage(), 'token tidak boleh bocor ke pesan');
        }

        $this->assertNull($stores->findByName('agent-01'), 'baris server yang gagal diverifikasi harus dihapus');
        $this->assertSame([ServerStore::LOCAL_ID], array_column($stores->all(), 'id'));
    }

    public function testDuplicateNameIsRejectedBeforeAnythingIsWritten(): void
    {
        $stores = new ServerStore($this->dbFile);
        $existing = $stores->create([
            'name' => 'agent-01',
            'base_url' => 'https://10.0.0.10:8443',
            'token' => 'token-lama',
            'tls_cert_pem' => $this->pem,
        ]);
        $file = $this->writeOnboarding();

        try {
            ProvisionOnboarding::register($this->job(), $file, $stores, new ServerResolver($stores));
            $this->fail('nama duplikat harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah terdaftar', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        $this->assertCount(2, $stores->all(), 'local + server lama, tidak ada baris baru');
        $this->assertSame('https://10.0.0.10:8443', $stores->find((string) $existing['id'])['base_url']);
    }

    public function testMissingOnboardingFileIsRejected(): void
    {
        $stores = new ServerStore($this->dbFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak ditemukan/');
        ProvisionOnboarding::register($this->job(), $this->tmp . '/tidak-ada.txt', $stores);
    }

    /**
     * Berkas onboarding persis seperti yang ditulis `agent/install.sh`
     * (`write_onboarding_file()`), dengan kunci opsional bisa dihilangkan.
     *
     * @param array<string,string|null> $overrides nilai `null` = kunci dihilangkan
     */
    private function content(array $overrides = []): string
    {
        $values = array_merge([
            'RAMES_ONBOARDING_VERSION' => '1',
            'URL' => 'https://10.0.0.9:8443',
            'TOKEN' => self::TOKEN,
            'FINGERPRINT_SHA256' => str_repeat('a', 64),
            'DOMAIN' => 'agent.example.test',
            'PORT_RANGE_START' => '41000',
            'PORT_RANGE_END' => '41999',
            'AGENT_VERSION' => '1.4.2',
            'GENERATED_AT' => '2026-01-01T00:00:00Z',
        ], $overrides);

        $lines = [];
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $lines[] = $key . '=' . $value;
        }

        return implode("\n", $lines) . "\n"
            . ProvisionOnboarding::PEM_BEGIN . "\n"
            . $this->pem . "\n"
            . ProvisionOnboarding::PEM_END . "\n";
    }

    private function writeOnboarding(): string
    {
        $file = $this->tmp . '/onboarding.txt';
        file_put_contents($file, $this->content());
        @chmod($file, 0600);

        return $file;
    }

    /**
     * @return array<string,mixed>
     */
    private function job(): array
    {
        return [
            'id' => 'job-1',
            'name' => 'agent-01',
            'host' => '10.0.0.9',
            'ssh_user' => 'root',
            'ssh_port' => 22,
        ];
    }

    /** Sertifikat X.509 self-signed (sekali per proses) — `validate()` menolak PEM palsu. */
    private static function certificate(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            return $pem = '';
        }
        $csr = @openssl_csr_new(['commonName' => 'rames-agent.test'], $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            return $pem = '';
        }
        $cert = @openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if ($cert === false || !@openssl_x509_export($cert, $out)) {
            return $pem = '';
        }

        return $pem = trim((string) $out);
    }
}
