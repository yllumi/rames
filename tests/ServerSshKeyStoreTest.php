<?php
declare(strict_types=1);

namespace Tests;

use app\library\Provision\ServerSshKeyStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test {@see ServerSshKeyStore} (kunci SSH server provisioning Ansible).
 *
 * Semua berkas di direktori temp (`database/keys` nyata tidak pernah
 * disentuh). Yang dikunci:
 *  - private key tersimpan 0600, direktori `servers/` 0700;
 *  - isi bukan PEM / terlalu besar / id tidak aman ditolak, dan pesan galat
 *    tidak pernah memuat isi kunci;
 *  - `delete()` idempoten;
 *  - known_hosts: entri `[host]:port` diganti (bukan diduplikasi), entri host
 *    lain dipertahankan; input ber-newline ditolak.
 */
class ServerSshKeyStoreTest extends TestCase
{
    private string $dir = '';
    private ServerSshKeyStore $store;

    private const PRIVATE_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----\n";

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-sshkeys-' . bin2hex(random_bytes(5));
        $this->store = new ServerSshKeyStore($this->dir);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    public function testSaveWritesPrivateKeyWithRestrictedPermissions(): void
    {
        $this->store->save('srv-1', self::PRIVATE_KEY);

        $path = $this->store->path('srv-1');
        $this->assertTrue($this->store->exists('srv-1'));
        $this->assertSame($this->dir . '/servers/srv-1', $path);
        $this->assertSame(self::PRIVATE_KEY, (string) file_get_contents($path));

        clearstatcache(true, $path);
        $this->assertSame(0600, fileperms($path) & 0777, 'private key wajib 0600');

        $serversDir = $this->dir . '/servers';
        clearstatcache(true, $serversDir);
        $this->assertSame(0700, fileperms($serversDir) & 0777, 'direktori kunci server wajib 0700');

        // Tidak ada berkas temp tertinggal.
        $this->assertSame([], glob($serversDir . '/*.tmp-*') ?: []);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function invalidContents(): array
    {
        return [
            'kosong' => [''],
            'teks biasa' => ['bukan private key sama sekali'],
            'hanya header begin' => ['-----BEGIN OPENSSH PRIVATE KEY-----'],
            'terlalu besar' => ['-----BEGIN OPENSSH PRIVATE KEY-----\n' . str_repeat('A', 70000) . '\n-----END OPENSSH PRIVATE KEY-----'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidContents')]
    public function testSaveRejectsInvalidContents(string $contents): void
    {
        try {
            $this->store->save('srv-1', $contents);
            $this->fail('isi kunci tidak sah harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak valid', $e->getMessage());
            // Pesan galat hanya menyebut path/nama kunci, bukan isi kunci.
            $this->assertStringNotContainsString('AAAA', $e->getMessage());
            $this->assertStringNotContainsString(str_repeat('A', 32), $e->getMessage());
        }

        $this->assertFalse($this->store->exists('srv-1'));
    }

    public function testSaveRejectsUnsafeServerId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Id server');

        $this->store->save('../keluar', self::PRIVATE_KEY);
    }

    public function testPort22EntryUsesPlainHostAndReplacesLegacyBracketForm(): void
    {
        // Kondisi produksi lama: `[host]:22` ditulis versi sebelumnya. OpenSSH
        // TIDAK mencocokkannya untuk port default ⇒ koneksi gagal
        // "No ED25519 host key is known". Pin baru harus menormalkannya.
        $knownHosts = $this->store->knownHostsPath();
        mkdir($this->dir, 0700, true);
        file_put_contents($knownHosts, "[103.82.92.133]:22 ssh-ed25519 AAAA-legacy\n");
        chmod($knownHosts, 0600);

        $this->store->saveKnownHost('idch', '103.82.92.133', 22, 'ssh-ed25519 AAAA-baru');

        $lines = $this->knownHostsLines();
        $this->assertSame(['103.82.92.133 ssh-ed25519 AAAA-baru'], $lines,
            'bentuk legacy [host]:22 diganti host polos, tanpa duplikat');
        $this->assertStringNotContainsString(']:22', implode("\n", $lines));
    }

    public function testForgetKnownHostRemovesOnlyThatHostPort(): void
    {
        $this->store->saveKnownHost('srv-1', '103.82.92.133', 22, 'ssh-ed25519 AAAA-target');
        $this->store->saveKnownHost('srv-2', '10.0.0.6', 2222, 'ssh-ed25519 AAAA-lain');
        $this->store->saveKnownHost('srv-3', 'agent.example.com', 22, 'ssh-ed25519 AAAA-domain');

        $removed = $this->store->forgetKnownHost('103.82.92.133', 22);

        $this->assertSame(1, $removed);
        $this->assertSame([
            '[10.0.0.6]:2222 ssh-ed25519 AAAA-lain',
            'agent.example.com ssh-ed25519 AAAA-domain',
        ], $this->knownHostsLines());

        $this->assertSame(0, $this->store->forgetKnownHost('103.82.92.133', 22), 'idempoten');
    }

    public function testForgetKnownHostAlsoRemovesLegacyBracketForm(): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents(
            $this->store->knownHostsPath(),
            "[103.82.92.133]:22 ssh-ed25519 AAAA-legacy\n"
        );

        $this->assertSame(1, $this->store->forgetKnownHost('103.82.92.133', 22));
        $this->assertSame([], $this->knownHostsLines());
    }

    public function testDeleteIsIdempotentAndRemovesPin(): void
    {
        $this->store->save('srv-1', self::PRIVATE_KEY);
        $this->store->saveKnownHost('srv-1', '10.0.0.5', 22, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA');

        $this->assertTrue($this->store->exists('srv-1'));
        $this->assertTrue($this->store->hasPin('srv-1'));

        $this->store->delete('srv-1');
        $this->assertFalse($this->store->exists('srv-1'));
        $this->assertFalse($this->store->hasPin('srv-1'));
        $this->assertFileDoesNotExist($this->store->path('srv-1'));

        // Panggilan kedua tidak melempar.
        $this->store->delete('srv-1');
        $this->assertFalse($this->store->exists('srv-1'));
    }

    public function testDeleteRemovesOnlyItsOwnKnownHostsEntry(): void
    {
        $this->store->saveKnownHost('srv-1', '10.0.0.5', 22, 'ssh-ed25519 AAAA-srv1');
        $this->store->saveKnownHost('srv-2', '10.0.0.6', 2222, 'ssh-ed25519 AAAA-srv2');

        $this->store->delete('srv-1');

        $lines = $this->knownHostsLines();
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('[10.0.0.6]:2222 ', $lines[0]);
    }

    public function testKnownHostAppendIsIdempotentPerHostPort(): void
    {
        $knownHosts = $this->store->knownHostsPath();
        $this->assertSame($this->dir . '/known_hosts', $knownHosts);

        $this->store->saveKnownHost('srv-1', '10.0.0.5', 22, 'ssh-ed25519 AAAA-lama');
        $this->store->saveKnownHost('srv-1', '10.0.0.5', 22, 'ssh-ed25519 AAAA-baru');

        $lines = $this->knownHostsLines();
        $this->assertCount(1, $lines, 'host:port yang sama tidak diduplikasi');
        $this->assertSame('10.0.0.5 ssh-ed25519 AAAA-baru', $lines[0], 'port 22 ditulis tanpa bracket (bentuk OpenSSH)');

        $this->store->saveKnownHost('srv-2', '10.0.0.6', 2222, 'ssh-ed25519 AAAA-srv2');
        $this->store->saveKnownHost('srv-3', 'agent.example.com', 22, 'ssh-ed25519 AAAA-domain');

        $lines = $this->knownHostsLines();
        $this->assertSame([
            '10.0.0.5 ssh-ed25519 AAAA-baru',
            '[10.0.0.6]:2222 ssh-ed25519 AAAA-srv2',
            'agent.example.com ssh-ed25519 AAAA-domain',
        ], $lines, 'entri host lain dipertahankan pada urutan aslinya');

        $this->assertTrue($this->store->hasPin('srv-1'));
        $this->assertTrue($this->store->hasPin('srv-2'));
        $this->assertFalse($this->store->hasPin('srv-tidak-ada'));

        clearstatcache(true, $knownHosts);
        $this->assertSame(0600, fileperms($knownHosts) & 0777);
    }

    /**
     * @return array<string,array{0:string,1:int,2:string}>
     */
    public static function invalidKnownHosts(): array
    {
        return [
            'host newline' => ["10.0.0.5\nHost jahat", 22, 'ssh-ed25519 AAAA'],
            'host spasi' => ['10.0.0.5 lain', 22, 'ssh-ed25519 AAAA'],
            'host kosong' => ['', 22, 'ssh-ed25519 AAAA'],
            'port 0' => ['10.0.0.5', 0, 'ssh-ed25519 AAAA'],
            'port terlalu besar' => ['10.0.0.5', 70000, 'ssh-ed25519 AAAA'],
            'host key newline' => ['10.0.0.5', 22, "ssh-ed25519 AAAA\nssh-rsa BBBB"],
            'host key kosong' => ['10.0.0.5', 22, '   '],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKnownHosts')]
    public function testSaveKnownHostRejectsUnsafeInput(string $host, int $port, string $hostKeyLine): void
    {
        $this->expectException(RuntimeException::class);

        $this->store->saveKnownHost('srv-1', $host, $port, $hostKeyLine);
    }

    /**
     * @return array<int,string>
     */
    private function knownHostsLines(): array
    {
        $path = $this->store->knownHostsPath();
        if (!is_file($path)) {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
