<?php
declare(strict_types=1);

namespace Tests;

use app\library\Provision\ProvisionAgentKey;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Kunci provisioning **bersama** ({@see ProvisionAgentKey}).
 *
 * Yang dikunci:
 *  - `ensure()` idempoten: panggilan kedua TIDAK meregenerasi (public key yang
 *    sudah dipasang di VM tetap berlaku);
 *  - berkas: private 0600, public 0644, direktori 0700;
 *  - kelas tidak pernah mengembalikan isi private key (hanya path/public/fingerprint);
 *  - kasus rusak sebagian (private ada, public hilang) menurunkan public key
 *    dari private (`ssh-keygen -y`), bukan membuat pasangan baru;
 *  - kegagalan `ssh-keygen` ⇒ RuntimeException tanpa membocorkan isi kunci.
 *
 * `ssh-keygen` dijalankan sungguhan (tersedia di image dashboard); semua berkas
 * di direktori temp — `database/keys` nyata tidak pernah disentuh.
 */
class ProvisionAgentKeyTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agent-key-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    public function testEnsureCreatesEd25519PairWithRestrictedModes(): void
    {
        $key = new ProvisionAgentKey(null, $this->tmp);

        $this->assertFalse($key->exists());
        $this->assertSame('', $key->publicKey());
        $this->assertSame('', $key->fingerprint());

        $result = $key->ensure();

        $this->assertTrue($result['created']);
        $this->assertStringStartsWith('ssh-ed25519 ', $result['public_key']);
        $this->assertStringContainsString(ProvisionAgentKey::COMMENT, $result['public_key']);
        $this->assertStringStartsWith('SHA256:', $result['fingerprint']);

        $this->assertSame(0700, fileperms($key->dir()) & 0777);
        $this->assertSame(0600, fileperms($key->privatePath()) & 0777);
        $this->assertSame(0644, fileperms($key->publicPath()) & 0777);
        $this->assertFileExists($key->privatePath());
    }

    public function testEnsureIsIdempotentAndKeepsTheSameKey(): void
    {
        $key = new ProvisionAgentKey(null, $this->tmp);

        $first = $key->ensure();
        $second = $key->ensure();

        $this->assertFalse($second['created'], 'kunci yang sudah ada tidak diregenerasi');
        $this->assertSame($first['public_key'], $second['public_key']);
        $this->assertSame($first['fingerprint'], $second['fingerprint']);
    }

    public function testPublicKeyIsNotThePrivateKey(): void
    {
        $key = new ProvisionAgentKey(null, $this->tmp);
        $result = $key->ensure();

        $private = (string) file_get_contents($key->privatePath());

        $this->assertStringContainsString('PRIVATE KEY', $private);
        $this->assertStringNotContainsString('PRIVATE KEY', $result['public_key']);
        $this->assertStringNotContainsString($private, $result['public_key']);
    }

    public function testMissingPublicKeyIsDerivedFromPrivateInsteadOfRegenerated(): void
    {
        $key = new ProvisionAgentKey(null, $this->tmp);
        $first = $key->ensure();
        $privateBefore = (string) file_get_contents($key->privatePath());

        unlink($key->publicPath());
        $this->assertFalse($key->exists());

        $second = $key->ensure();

        $this->assertFalse($second['created'], 'private key lama dipertahankan');
        $this->assertSame($privateBefore, (string) file_get_contents($key->privatePath()));
        $this->assertSame($first['public_key'], $second['public_key'], 'public key diturunkan dari private yang sama');
        $this->assertSame(0644, fileperms($key->publicPath()) & 0777);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFailureOfSshKeygenIsReportedWithoutLeakingKeyMaterial(): void
    {
        $runner = new class extends ProcessRunner {
            public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
            {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'ssh-keygen: not found', 'timedOut' => false];
            }
        };

        $key = new ProvisionAgentKey($runner, $this->tmp);

        try {
            $key->ensure();
            $this->fail('ensure() harus melempar bila ssh-keygen gagal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Gagal membuat kunci', $e->getMessage());
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }

        $this->assertFalse($key->exists());
        $this->assertSame([], glob($key->dir() . '/.gen-*') ?: [], 'berkas sementara dibersihkan');
    }

    public function testSlotNameAndPathsAreStable(): void
    {
        $key = new ProvisionAgentKey(null, $this->tmp);

        $this->assertSame('rames-agent-key', ProvisionAgentKey::SLOT);
        $this->assertSame($this->tmp . '/provision', $key->dir());
        $this->assertSame($this->tmp . '/provision/rames-agent-key', $key->privatePath());
        $this->assertSame($this->tmp . '/provision/rames-agent-key.pub', $key->publicPath());
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? self::removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
