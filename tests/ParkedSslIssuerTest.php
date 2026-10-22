<?php
declare(strict_types=1);

namespace Tests;

use app\library\Domain\ParkedDomainContent;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Nginx\NginxReloader;
use app\library\Nginx\ParkedNginxConfig;
use app\library\SSL\ParkedSslIssuer;
use app\library\SSL\SslIssuer;
use app\library\Storage\ParkedDomainStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

/**
 * Issuer SSL fake — mencatat `issue()` dan (opsional) membuat berkas cert
 * palsu di direktori Let's Encrypt temp. Konstruktor induk sengaja dilewati
 * (butuh `config()` runtime & certbot).
 */
final class ParkedSslFakeIssuer extends SslIssuer
{
    /** @var array<int,string> */
    public array $issued = [];

    public int $certExistsCalls = 0;

    public bool $certExistsResult = false;

    public ?RuntimeException $issueError = null;

    public ?string $expiry = '2027-01-01T00:00:00+00:00';

    /** Bila diisi, `issue()` membuat `{le}/live/{domain}/{fullchain,privkey}.pem`. */
    public ?string $letsencryptPath = null;

    public function __construct()
    {
        // Sengaja tidak memanggil parent::__construct() — tidak ada certbot.
    }

    public function issue(string $domain): void
    {
        $this->issued[] = $domain;
        if ($this->issueError !== null) {
            throw $this->issueError;
        }
        if ($this->letsencryptPath !== null) {
            $dir = $this->letsencryptPath . '/live/' . $domain;
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir . '/fullchain.pem', "-----BEGIN CERTIFICATE-----\n");
            file_put_contents($dir . '/privkey.pem', "-----BEGIN PRIVATE KEY-----\n");
        }
        $this->certExistsResult = true;
    }

    public function certExists(string $domain): bool
    {
        $this->certExistsCalls++;

        return $this->certExistsResult;
    }

    public function certExpiry(string $domain): ?string
    {
        return $this->expiry;
    }
}

/**
 * Reloader fake — tanpa Docker/Nginx host.
 */
final class ParkedSslFakeReloader extends NginxReloader
{
    public int $calls = 0;

    /** @var array<string,mixed> */
    public array $result = ['ok' => true, 'error' => null];

    public function __construct()
    {
        // Sengaja tidak memanggil parent::__construct() — reload() di-override.
    }

    public function reload(): array
    {
        $this->calls++;

        return $this->result;
    }
}

/**
 * Penulis config yang bisa dipaksa gagal pada pemanggilan ke-N.
 */
final class ParkedSslTestWriter extends NginxConfigGenerator
{
    public int $calls = 0;

    public ?int $failOnCall = null;

    public function write(string $name, string $content): void
    {
        $this->calls++;
        if ($this->failOnCall !== null && $this->calls === $this->failOnCall) {
            throw new RuntimeException('simulasi gagal tulis config (test)');
        }

        parent::write($name, $content);
    }
}

/**
 * Test orkestrasi penerbitan SSL domain parkir (`ParkedSslIssuer`).
 *
 * Semua data di direktori/berkas temp — `database/`, `nginx-status/`, dan
 * `/etc/letsencrypt` nyata tidak disentuh; certbot tidak pernah dijalankan.
 */
class ParkedSslIssuerTest extends TestCase
{
    private string $base;
    private string $conf;
    private string $enabled;
    private string $root;
    private string $le;
    private string $db;

    private ParkedDomainStore $store;
    private ParkedDomainContent $content;
    private ParkedSslFakeIssuer $issuer;
    private ParkedSslFakeReloader $reloader;
    private ParkedSslTestWriter $writer;
    private ParkedSslIssuer $service;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rames-parked-ssl-' . bin2hex(random_bytes(4));
        $this->conf = $this->base . '/sites-available';
        $this->enabled = $this->base . '/sites-enabled';
        $this->root = $this->base . '/parked';
        $this->le = $this->base . '/letsencrypt';
        $this->db = $this->base . '/rames.sqlite';
        mkdir($this->conf, 0775, true);
        mkdir($this->enabled, 0775, true);
        mkdir($this->le, 0775, true);

        $this->store = new ParkedDomainStore($this->db);
        $this->content = new ParkedDomainContent($this->root);
        $this->issuer = new ParkedSslFakeIssuer();
        $this->issuer->letsencryptPath = $this->le;
        $this->reloader = new ParkedSslFakeReloader();
        $this->writer = new ParkedSslTestWriter($this->conf, $this->enabled);

        $this->service = new ParkedSslIssuer(
            $this->store,
            $this->content,
            new ParkedNginxConfig($this->writer),
            $this->reloader,
            $this->issuer,
            $this->base . '/webroot',
            $this->le
        );
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
        self::rmTree($this->base);
    }

    // ==================================================================
    // Sukses & noop
    // ==================================================================

    public function testIssueWritesActiveStateConfigWith443AndReloadsOnce(): void
    {
        $entry = $this->entry();
        $id = (string) $entry['id'];

        $result = $this->service->issue($id);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame('', $result['error']);
        $this->assertSame('parked.example.com', $result['domain']);
        $this->assertSame(['parked.example.com'], $this->issuer->issued);

        $stored = $this->store->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('active', $stored['ssl_status']);
        $this->assertSame('2027-01-01T00:00:00+00:00', $stored['ssl_expires_at']);
        $this->assertNull($stored['ssl_stage']);
        $this->assertNull($stored['ssl_message']);
        $this->assertNull($stored['ssl_error']);

        $this->assertSame(1, $this->reloader->calls);
        $config = (string) file_get_contents($this->conf . '/_parked_' . $id . '.conf');
        $this->assertStringContainsString('listen 443 ssl;', $config);
        $this->assertStringContainsString(
            'ssl_certificate ' . $this->le . '/live/parked.example.com/fullchain.pem;',
            $config
        );
    }

    public function testIssueIsNoopWhenActiveAndCertExists(): void
    {
        $entry = $this->entry();
        $id = (string) $entry['id'];
        $this->store->update($id, static function (array &$row): void {
            $row['ssl_status'] = 'active';
            $row['ssl_expires_at'] = '2026-12-01T00:00:00+00:00';
        });
        $this->issuer->certExistsResult = true;

        $result = $this->service->issue($id);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame('', $result['error']);
        $this->assertSame([], $this->issuer->issued, 'certbot tidak boleh dipanggil ulang');
        $this->assertSame(1, $this->issuer->certExistsCalls);
        // Noop tetap merekonsiliasi config + reload (Retry setelah kegagalan
        // tulis/reload sebelumnya), tanpa menjalankan certbot.
        $this->assertSame(1, $this->reloader->calls);
        $this->assertSame(1, $this->writer->calls);

        $stored = $this->store->find($id);
        $this->assertSame('active', $stored['ssl_status'] ?? null);
        $this->assertSame('2027-01-01T00:00:00+00:00', $stored['ssl_expires_at'] ?? null);
    }

    // ==================================================================
    // Kegagalan
    // ==================================================================

    public function testIssueFailureMarksFailedWithoutTouchingConfig(): void
    {
        $entry = $this->entry();
        $id = (string) $entry['id'];
        // Tanggal lama (mis. dari penerbitan sebelumnya) harus dibersihkan saat
        // penerbitan ulang gagal.
        $this->store->update($id, static function (array &$row): void {
            $row['ssl_expires_at'] = '2026-12-01T00:00:00+00:00';
        });
        $this->issuer->issueError = new RuntimeException(
            'Gagal menerbitkan sertifikat SSL untuk parked.example.com: detail certbot'
        );

        $result = $this->service->issue($id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Gagal menerbitkan sertifikat SSL', $result['error']);
        $this->assertSame('parked.example.com', $result['domain']);
        $this->assertSame(0, $this->reloader->calls, 'reload tidak boleh dipanggil');
        $this->assertSame(0, $this->writer->calls, 'config tidak boleh ditulis');

        $stored = $this->store->find($id);
        $this->assertSame('failed', $stored['ssl_status'] ?? null);
        $this->assertSame($result['error'], $stored['ssl_error'] ?? null);
        $this->assertNull($stored['ssl_stage'] ?? null);
        $this->assertNull($stored['ssl_message'] ?? null);
        $this->assertNull($stored['ssl_expires_at'] ?? null, 'penerbitan gagal ⇒ expiry lama dibersihkan');
        $this->assertFileDoesNotExist($this->conf . '/_parked_' . $id . '.conf');
    }

    public function testReloadFailureAfterCertIssuedMarksFailedAndKeepsExpiry(): void
    {
        $entry = $this->entry();
        $id = (string) $entry['id'];
        $this->reloader->result = ['ok' => false, 'error' => 'nginx: [emerg] invalid config (test)'];

        $result = $this->service->issue($id);

        $this->assertFalse($result['ok'], 'config gagal dimuat ⇒ UI harus menampilkan Retry');
        $this->assertStringContainsString('Sertifikat terbit tetapi config Nginx gagal dimuat', $result['error']);
        $this->assertSame('parked.example.com', $result['domain']);
        $this->assertSame(1, $this->reloader->calls);

        $stored = $this->store->find($id);
        $this->assertSame('failed', $stored['ssl_status'] ?? null);
        $this->assertNull($stored['ssl_stage'] ?? null);
        $this->assertNull($stored['ssl_message'] ?? null);
        // Cert terbit ⇒ tanggal kedaluwarsa tetap disimpan walau status failed.
        $this->assertSame('2027-01-01T00:00:00+00:00', $stored['ssl_expires_at'] ?? null);
        $this->assertStringContainsString(
            'Sertifikat terbit tetapi config Nginx gagal dimuat',
            (string) ($stored['ssl_error'] ?? '')
        );
        $this->assertStringContainsString('tekan Retry', (string) ($stored['ssl_error'] ?? ''));

        // Config dengan 443 memang sudah ditulis sebelum reload.
        $config = (string) file_get_contents($this->conf . '/_parked_' . $id . '.conf');
        $this->assertStringContainsString('listen 443 ssl;', $config);
    }

    public function testConfigWriteFailureAfterCertIssuedMarksFailedAndSkipsReload(): void
    {
        $entry = $this->entry();
        $id = (string) $entry['id'];
        $this->writer->failOnCall = 1;

        $result = $this->service->issue($id);

        $this->assertFalse($result['ok'], 'config gagal ditulis ⇒ UI harus menampilkan Retry');
        $this->assertStringContainsString('Sertifikat terbit tetapi config Nginx gagal ditulis', $result['error']);
        $this->assertSame(0, $this->reloader->calls, 'reload tidak boleh dipanggil bila tulis config gagal');

        $stored = $this->store->find($id);
        $this->assertSame('failed', $stored['ssl_status'] ?? null);
        $this->assertNull($stored['ssl_stage'] ?? null);
        $this->assertNull($stored['ssl_message'] ?? null);
        $this->assertSame('2027-01-01T00:00:00+00:00', $stored['ssl_expires_at'] ?? null);
        $this->assertStringContainsString(
            'Sertifikat terbit tetapi config Nginx gagal ditulis',
            (string) ($stored['ssl_error'] ?? '')
        );
        $this->assertStringContainsString('tekan Retry', (string) ($stored['ssl_error'] ?? ''));
        $this->assertFileDoesNotExist($this->conf . '/_parked_' . $id . '.conf');
    }

    public function testNoopReconcileFailureMarksFailedButKeepsExpiry(): void
    {
        $entry = $this->entry();
        $id = (string) $entry['id'];
        $this->store->update($id, static function (array &$row): void {
            $row['ssl_status'] = 'active';
            $row['ssl_expires_at'] = '2026-12-01T00:00:00+00:00';
        });
        $this->issuer->certExistsResult = true;
        $this->reloader->result = ['ok' => false, 'error' => 'nginx: [emerg] invalid config (test)'];

        $result = $this->service->issue($id);

        $this->assertFalse($result['ok'], 'reconcile gagal ⇒ jangan pertahankan active+warning');
        $this->assertStringContainsString('Sertifikat terbit tetapi config Nginx gagal dimuat', $result['error']);
        $this->assertSame([], $this->issuer->issued, 'certbot tidak boleh dipanggil ulang');
        $this->assertSame(1, $this->reloader->calls);

        $stored = $this->store->find($id);
        $this->assertSame('failed', $stored['ssl_status'] ?? null);
        $this->assertNull($stored['ssl_stage'] ?? null);
        $this->assertNull($stored['ssl_message'] ?? null);
        $this->assertSame('2027-01-01T00:00:00+00:00', $stored['ssl_expires_at'] ?? null);
        $this->assertStringContainsString(
            'Sertifikat terbit tetapi config Nginx gagal dimuat',
            (string) ($stored['ssl_error'] ?? '')
        );
    }

    // ==================================================================
    // Validasi & entri hilang
    // ==================================================================

    public function testNonPublicDomainIsRejectedWithoutSideEffects(): void
    {
        $entry = $this->entry('parked.local');
        $id = (string) $entry['id'];

        $result = $this->service->issue($id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('bukan domain publik', $result['error']);
        $this->assertSame([], $this->issuer->issued);
        $this->assertSame(0, $this->reloader->calls);
        $this->assertSame(0, $this->writer->calls);

        $stored = $this->store->find($id);
        $this->assertSame('disabled', $stored['ssl_status'] ?? null, 'tanpa efek samping');
    }

    public function testMissingEntryReturnsErrorWithoutSideEffects(): void
    {
        $result = $this->service->issue('pd_ffffffffffff');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('tidak ditemukan', $result['error']);
        $this->assertSame('', $result['domain']);
        $this->assertSame([], $this->issuer->issued);
        $this->assertSame(0, $this->reloader->calls);
        $this->assertSame(0, $this->writer->calls);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function entry(string $domain = 'parked.example.com', string $mode = 'html', array $extra = []): array
    {
        return $this->store->create(array_merge([
            'domain' => $domain,
            'mode' => $mode,
            'server_id' => 'local',
            'ssl_status' => 'disabled',
            'ssl_stage' => null,
            'ssl_message' => null,
            'ssl_error' => null,
            'ssl_expires_at' => null,
        ], $extra));
    }

    private static function rmTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::rmTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
