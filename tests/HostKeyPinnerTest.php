<?php
declare(strict_types=1);

namespace Tests;

use app\library\Provision\HostKeyPinner;
use app\library\Provision\ServerSshKeyStore;
use app\library\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test {@see HostKeyPinner} (TOFU + pin host key sebelum Ansible berjalan).
 *
 * `ProcessRunner` diganti subclass tiruan: `ssh-keyscan` & `ssh-keygen`
 * **tidak** dijalankan nyata (tanpa jaringan, tanpa biner OpenSSH), dan
 * `ServerSshKeyStore` memakai direktori temp sehingga `database/keys` nyata
 * tidak pernah disentuh.
 *
 * Yang dikunci:
 *  (a) pin pertama menulis `[host]:port` ke known_hosts (+ fingerprint SHA256);
 *  (b) pin kedua idempoten: `pinned=false` dan berkas tidak berubah;
 *  (c) isi kunci BERBEDA ⇒ `host_key_changed=true` dan berkas **tidak** ditulis;
 *  (d) keluaran keyscan kosong ⇒ RuntimeException yang menyebut host/port;
 *  (e) host/port/slot tidak sah ditolak sebelum proses dijalankan.
 */
class HostKeyPinnerTest extends TestCase
{
    private const HOST = '10.0.0.5';
    private const PORT = 22;

    private const KEY_BODY = 'AAAAC3NzaC1lZDI1NTE5AAAAITESTKEYBODY0000000000000000000000000000';
    private const OTHER_KEY_BODY = 'AAAAC3NzaC1lZDI1NTE5AAAAIOTHERKEYBODY000000000000000000000000000';

    private const FINGERPRINT = 'SHA256:AbCdEf0123456789abcdefABCDEF0123456789abc';

    private string $dir = '';
    private ServerSshKeyStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rames-hostkey-' . bin2hex(random_bytes(5));
        $this->store = new ServerSshKeyStore($this->dir);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    public function testFirstPinWritesPlainHostEntryForPort22(): void
    {
        $runner = $this->runner();
        $result = (new HostKeyPinner($runner, $this->store))->pin('vps-jakarta', self::HOST, self::PORT);

        $this->assertTrue($result['pinned']);
        $this->assertFalse($result['host_key_changed']);
        $this->assertSame(self::FINGERPRINT, $result['fingerprint']);

        // Port default (22) WAJIB ditulis tanpa bracket: OpenSSH hanya mencari
        // `host` untuk port 22, sehingga entri `[host]:22` membuat koneksi gagal
        // "No ED25519 host key is known".
        $expected = self::HOST . ' ssh-ed25519 ' . self::KEY_BODY . "\n";
        $this->assertSame($expected, (string) file_get_contents($this->store->knownHostsPath()));

        // argv keyscan eksplisit (array, tanpa shell) — port & tipe kunci terbatas.
        $scan = $runner->calls[0]['command'];
        $this->assertSame(
            ['ssh-keyscan', '-T', '10', '-p', '22', '-t', 'ed25519,ecdsa,rsa', self::HOST],
            $scan
        );

        // Fingerprint dihitung dari stdin `<keytype> <base64>`, bukan argumen.
        $fingerprintCall = $runner->calls[1];
        $this->assertSame(['ssh-keygen', '-lf', '-'], $fingerprintCall['command']);
        $this->assertSame('ssh-ed25519 ' . self::KEY_BODY . "\n", $fingerprintCall['stdin']);

        // Penanda pin per server (dipakai pemanggil lain untuk StrictHostKeyChecking).
        $this->assertTrue($this->store->hasPin('vps-jakarta'));
    }

    public function testSecondPinWithSameKeyIsIdempotent(): void
    {
        (new HostKeyPinner($this->runner(), $this->store))->pin('vps-jakarta', self::HOST, self::PORT);
        $before = (string) file_get_contents($this->store->knownHostsPath());

        $result = (new HostKeyPinner($this->runner(), $this->store))->pin('vps-jakarta', self::HOST, self::PORT);

        $this->assertFalse($result['pinned'], 'Pin kedua tidak boleh menulis ulang berkas');
        $this->assertFalse($result['host_key_changed']);
        $this->assertSame(self::FINGERPRINT, $result['fingerprint']);
        $this->assertSame($before, (string) file_get_contents($this->store->knownHostsPath()));
    }

    public function testLegacyBracketedPinIsNormalizedOnRerun(): void
    {
        // Kondisi produksi: pin lama `[host]:22` (versi sebelumnya) membuat
        // Ansible/ssh menolak koneksi. Pin ulang dengan kunci yang SAMA harus
        // menormalkan bentuknya tanpa melaporkan perubahan kunci.
        mkdir($this->dir, 0700, true);
        $knownHosts = $this->store->knownHostsPath();
        file_put_contents(
            $knownHosts,
            '[' . self::HOST . ']:22 ssh-ed25519 ' . self::KEY_BODY . "\n"
        );

        $result = (new HostKeyPinner($this->runner(), $this->store))->pin('vps-jakarta', self::HOST, self::PORT);

        $this->assertFalse($result['host_key_changed'], 'kunci sama, bukan perubahan');
        $this->assertSame(
            self::HOST . ' ssh-ed25519 ' . self::KEY_BODY . "\n",
            (string) file_get_contents($knownHosts),
            'bentuk [host]:22 diganti host polos'
        );
    }

    public function testChangedHostKeyIsReportedWithoutWriting(): void
    {
        (new HostKeyPinner($this->runner(), $this->store))->pin('vps-jakarta', self::HOST, self::PORT);
        $before = (string) file_get_contents($this->store->knownHostsPath());

        $runner = $this->runner(self::OTHER_KEY_BODY);
        $result = (new HostKeyPinner($runner, $this->store))->pin('vps-jakarta', self::HOST, self::PORT);

        $this->assertTrue($result['host_key_changed'], 'Kunci berbeda harus dilaporkan sebagai perubahan');
        $this->assertFalse($result['pinned'], 'Kunci berubah tidak boleh ditulis otomatis');
        $this->assertSame($before, (string) file_get_contents($this->store->knownHostsPath()), 'known_hosts tidak boleh berubah');
    }

    public function testEmptyScanOutputThrows(): void
    {
        $runner = $this->runner('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tidak bisa membaca host key 10\.0\.0\.5:22/');

        (new HostKeyPinner($runner, $this->store))->pin('vps-jakarta', self::HOST, self::PORT);
    }

    public function testScanOutputWithoutKeyLineThrowsAndWritesNothing(): void
    {
        // Keluaran hanya komentar (host tidak menjawab) — sama dengan kegagalan.
        $runner = $this->runner("# 10.0.0.5:22 SSH-2.0-OpenSSH_9.6\n");

        try {
            (new HostKeyPinner($runner, $this->store))->pin('vps-jakarta', self::HOST, self::PORT);
            $this->fail('Keluaran tanpa baris kunci harus melempar RuntimeException');
        } catch (RuntimeException) {
            // diharapkan
        }

        $this->assertFileDoesNotExist($this->store->knownHostsPath());
    }

    public function testInvalidHostPortAndSlotAreRejectedBeforeScanning(): void
    {
        $cases = [
            'host kosong' => ['vps-1', '', 22],
            'host ber-opsi' => ['vps-1', '-oProxyCommand=id', 22],
            'host spasi' => ['vps-1', '10.0.0.5 10.0.0.6', 22],
            'port nol' => ['vps-1', self::HOST, 0],
            'port terlalu besar' => ['vps-1', self::HOST, 70000],
            'slot tidak sah' => ['vps/../../etc', self::HOST, 22],
        ];

        foreach ($cases as $label => [$slot, $host, $port]) {
            $runner = $this->runner();
            try {
                (new HostKeyPinner($runner, $this->store))->pin($slot, $host, $port);
                $this->fail('Kasus "' . $label . '" seharusnya ditolak');
            } catch (RuntimeException) {
                $this->assertSame([], $runner->calls, 'Kasus "' . $label . '" tidak boleh menjalankan proses');
            }
        }
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function runner(string $keyBody = self::KEY_BODY): FakeHostKeyRunner
    {
        $output = "# 10.0.0.5:22 SSH-2.0-OpenSSH_9.6\n";
        if ($keyBody !== '') {
            $output .= self::HOST . ' ssh-ed25519 ' . $keyBody . "\n";
        }

        return new FakeHostKeyRunner($output, '3072 ' . self::FINGERPRINT . ' root@target (ED25519)');
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

/**
 * Pengganti `ProcessRunner` untuk keyscan & ssh-keygen: merekam argv/stdin dan
 * mengembalikan keluaran yang sudah ditentukan (tidak ada proses nyata).
 */
class FakeHostKeyRunner extends ProcessRunner
{
    /** @var array<int,array{command:array<int,string>,stdin:?string}> */
    public array $calls = [];

    public function __construct(
        private readonly string $scanOutput,
        private readonly string $fingerprintOutput,
    ) {
    }

    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->calls[] = ['command' => $command, 'stdin' => $stdin];

        $isFingerprint = ($command[0] ?? '') === 'ssh-keygen';
        $stdout = $isFingerprint ? $this->fingerprintOutput : $this->scanOutput;

        return [
            'code' => $stdout === '' ? 1 : 0,
            'stdout' => $stdout,
            'stderr' => '',
            'timedOut' => false,
        ];
    }
}
