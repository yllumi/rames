<?php
declare(strict_types=1);

namespace Tests;

use app\library\Domain\ParkedDomainContent;
use app\library\Domain\ParkedDomainManager;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\StoreDbFiles;

// Helper webman (`base_path()`, `config()`, `app_subdomain_of()`) tidak dimuat
// di luar runtime webman: definisikan konstanta + fungsi (pola SubdomainTest).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain_of')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Reloader fake — hasil bisa diatur per-tes, tanpa menyentuh Docker/Nginx host.
 */
class ParkedFakeReloader extends NginxReloader
{
    public int $calls = 0;

    /** @var array<string,mixed> */
    public array $result = ['ok' => true, 'error' => null];

    public function __construct()
    {
        // Sengaja tidak memanggil parent::__construct — reload() di-override.
    }

    public function reload(): array
    {
        $this->calls++;

        return $this->result;
    }
}

/**
 * Penulis config yang bisa dipaksa gagal pada pemanggilan ke-N (simulasi disk
 * penuh / direktori hilang) tanpa menyentuh config Nginx host nyata.
 */
class ParkedTestWriter extends NginxConfigGenerator
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
 * Issuer SSL fake — mencatat revoke tanpa menyentuh certbot/disk.
 * Konstruktor induk sengaja dilewati (butuh `config()` runtime).
 */
class ParkedFakeSslIssuer extends SslIssuer
{
    /** @var array<int,string> */
    public array $revoked = [];

    public int $certExistsCalls = 0;

    public bool $certExistsResult = false;

    public ?RuntimeException $revokeError = null;

    public function __construct()
    {
        // Sengaja tidak memanggil parent::__construct — method di-override.
    }

    public function revoke(string $domain): void
    {
        if ($this->revokeError !== null) {
            throw $this->revokeError;
        }
        $this->revoked[] = $domain;
    }

    public function certExists(string $domain): bool
    {
        $this->certExistsCalls++;

        return $this->certExistsResult;
    }
}

/**
 * Test orkestrasi domain parking: create/update/delete dengan rollback
 * transaksional (store + konten + config) dan reload tepat sekali.
 *
 * Semua data di direktori/berkas temp — `database/`, `apps/`, dan
 * `nginx-status/` nyata tidak disentuh.
 */
class ParkedDomainManagerTest extends TestCase
{
    private string $base;
    private string $conf;
    private string $enabled;
    private string $root;
    private string $db;
    private ParkedFakeReloader $reloader;
    private ParkedTestWriter $writer;
    private ParkedDomainContent $content;
    private ParkedDomainStore $store;
    private AppStore $apps;
    private ParkedDomainManager $manager;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rames-parked-manager-' . bin2hex(random_bytes(4));
        $this->conf = $this->base . '/sites-available';
        $this->enabled = $this->base . '/sites-enabled';
        $this->root = $this->base . '/parked';
        $this->db = $this->base . '/rames.sqlite';
        mkdir($this->conf, 0775, true);
        mkdir($this->enabled, 0775, true);

        $this->reloader = new ParkedFakeReloader();
        $this->writer = new ParkedTestWriter($this->conf, $this->enabled);
        $this->content = new ParkedDomainContent($this->root);
        $this->store = new ParkedDomainStore($this->db);
        $this->apps = new AppStore($this->db);
        $this->manager = new ParkedDomainManager(
            $this->store,
            $this->apps,
            $this->reloader,
            $this->writer,
            $this->content
        );
    }

    protected function tearDown(): void
    {
        StoreDbFiles::remove($this->db);
        self::rmTree($this->base);
    }

    // ==================================================================
    // CREATE
    // ==================================================================

    public function testCreateHtmlWritesEntryContentConfigAndReloadsOnce(): void
    {
        $result = $this->manager->create([
            'domain' => 'Parked.Example.COM.',
            'mode' => 'html',
            'html' => '<h1>Halo</h1>',
        ], 'user-1');

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertTrue($result['reloaded']);
        $this->assertFalse($result['rolled_back']);
        $this->assertCount(1, $result['created']);
        $this->assertSame(1, $this->reloader->calls);

        $entry = $result['created'][0];
        $id = (string) $entry['id'];
        $this->assertSame('parked.example.com', $entry['domain']);
        $this->assertSame('html', $entry['mode']);
        $this->assertSame('local', $entry['server_id']);
        $this->assertSame('user-1', $entry['created_by']);

        $stored = $this->store->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('parked.example.com', $stored['domain']);
        $this->assertSame('<h1>Halo</h1>', $this->content->read($id));
        $this->assertConfigContains($id, 'root ' . $this->root . '/' . $id . ';');
        $this->assertEnabled($id);
    }

    public function testCreateRedirectWritesConfigWithoutContentDirectory(): void
    {
        $result = $this->manager->create([
            'domain' => 'lama.example.com',
            'mode' => 'redirect',
            'redirect_to' => 'https://baru.example.com',
            'redirect_code' => '302',
            'html' => '<p>diabaikan</p>',
        ]);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(1, $this->reloader->calls);

        $entry = $result['created'][0];
        $id = (string) $entry['id'];
        $this->assertSame('redirect', $entry['mode']);
        $this->assertSame('https://baru.example.com', $entry['redirect_to']);
        $this->assertSame(302, $entry['redirect_code']);
        $this->assertNull($this->content->read($id), 'mode redirect tidak menulis berkas konten');
        $this->assertDirectoryDoesNotExist($this->content->dirFor($id));
        $this->assertConfigContains($id, 'return 302 https://baru.example.com$request_uri;');
    }

    public function testCreateAddWwwMakesSecondEntryWithOwnContent(): void
    {
        $result = $this->manager->create([
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<h1>Halo www</h1>',
            'add_www' => '1',
        ]);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertCount(2, $result['created']);
        $this->assertSame(1, $this->reloader->calls);
        $this->assertSame(2, count($this->store->all()));

        $domains = array_map(
            static fn (array $row): string => (string) $row['domain'],
            $this->store->all()
        );
        sort($domains);
        $this->assertSame(['parked.example.com', 'www.parked.example.com'], $domains);

        $ids = [];
        foreach ($result['created'] as $entry) {
            $id = (string) $entry['id'];
            $ids[] = $id;
            $this->assertSame('<h1>Halo www</h1>', $this->content->read($id));
            $this->assertFileExists($this->conf . '/_parked_' . $id . '.conf');
        }
        $this->assertNotSame($ids[0], $ids[1], 'www = entri terpisah, bukan entri yang sama');
    }

    public function testCreateStoresDisabledSslStateForHtmlAndRedirect(): void
    {
        $html = $this->manager->create([
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<h1>Halo</h1>',
        ]);
        $this->assertTrue($html['ok'], $html['error']);

        $redirect = $this->manager->create([
            'domain' => 'redirect.example.com',
            'mode' => 'redirect',
            'redirect_to' => 'https://tujuan.example',
        ]);
        $this->assertTrue($redirect['ok'], $redirect['error']);

        foreach ([$html['created'][0], $redirect['created'][0]] as $entry) {
            $this->assertSame('disabled', $entry['ssl_status']);
            foreach (['ssl_stage', 'ssl_message', 'ssl_error', 'ssl_expires_at'] as $key) {
                $this->assertArrayHasKey($key, $entry);
                $this->assertNull($entry[$key], $key . ' harus null sejak create');
            }

            $stored = $this->store->find((string) $entry['id']);
            $this->assertNotNull($stored);
            $this->assertSame('disabled', $stored['ssl_status']);
            foreach (['ssl_stage', 'ssl_message', 'ssl_error', 'ssl_expires_at'] as $key) {
                $this->assertArrayHasKey($key, $stored);
                $this->assertNull($stored[$key]);
            }
        }
    }

    public function testCreateReloadFailureRollsBackStoreContentAndConfig(): void
    {
        $this->reloader->result = ['ok' => false, 'error' => 'emerg: host not found'];

        $result = $this->manager->create([
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<h1>Halo</h1>',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['reloaded']);
        $this->assertTrue($result['rolled_back'], $result['error']);
        $this->assertStringContainsString('host not found', $result['error']);
        $this->assertSame([], $result['created']);
        $this->assertSame(1, $this->reloader->calls);
        $this->assertSame([], $this->store->all());
        $this->assertSame([], glob($this->conf . '/_parked_*.conf') ?: []);
        $this->assertSame([], glob($this->enabled . '/_parked_*.conf') ?: []);
        $this->assertSame([], glob($this->root . '/pd_*') ?: []);
    }

    public function testCreateDuplicateDomainHasNoSideEffects(): void
    {
        $first = $this->manager->create([
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<h1>Halo</h1>',
        ]);
        $this->assertTrue($first['ok'], $first['error']);
        $writerCalls = $this->writer->calls;

        $second = $this->manager->create([
            'domain' => 'PARKED.example.com',
            'mode' => 'html',
            'html' => '<h1>Lain</h1>',
        ]);

        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('sudah terparkir', $second['error']);
        $this->assertSame(1, $this->reloader->calls, 'tidak boleh reload untuk permintaan yang ditolak');
        $this->assertSame($writerCalls, $this->writer->calls);
        $this->assertCount(1, $this->store->all());
    }

    public function testCreateInvalidInputHasNoSideEffects(): void
    {
        $cases = [
            ['domain' => 'bukan domain', 'mode' => 'html', 'html' => '<p>x</p>'],
            ['domain' => 'parked.example.com', 'mode' => '', 'html' => '<p>x</p>'],
            ['domain' => 'parked.example.com', 'mode' => 'html', 'html' => '   '],
            ['domain' => 'parked.example.com', 'mode' => 'redirect', 'redirect_to' => 'javascript:alert(1)'],
            ['domain' => 'parked.example.com', 'mode' => 'redirect', 'redirect_to' => 'https://ok.example', 'redirect_code' => 308],
        ];

        foreach ($cases as $input) {
            $result = $this->manager->create($input);
            $this->assertFalse($result['ok'], 'input harus ditolak: ' . json_encode($input));
            $this->assertNotSame('', $result['error']);
        }

        $this->assertSame(0, $this->reloader->calls);
        $this->assertSame(0, $this->writer->calls);
        $this->assertSame([], $this->store->all());
        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function testCreateNonLocalServerRejectedWithoutSideEffects(): void
    {
        $result = $this->manager->create([
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<p>x</p>',
            'server_id' => 'agent-1',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(ParkedDomainManager::LOCAL_ONLY_MESSAGE, $result['error']);
        $this->assertSame(0, $this->reloader->calls);
        $this->assertSame(0, $this->writer->calls);
        $this->assertSame([], $this->store->all());
    }

    public function testCreateAddWwwRejectsWwwPrefixedDomain(): void
    {
        $result = $this->manager->create([
            'domain' => 'www.parked.example.com',
            'mode' => 'html',
            'html' => '<p>x</p>',
            'add_www' => true,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('berawalan "www."', $result['error']);
        $this->assertSame([], $this->store->all());
        $this->assertSame(0, $this->writer->calls);
    }

    public function testCreateAddWwwFailsWhenWwwVariantAlreadyParked(): void
    {
        $existing = $this->manager->create([
            'domain' => 'www.parked.example.com',
            'mode' => 'redirect',
            'redirect_to' => 'https://tujuan.example',
        ]);
        $this->assertTrue($existing['ok'], $existing['error']);
        $writerCalls = $this->writer->calls;

        $result = $this->manager->create([
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<p>x</p>',
            'add_www' => 'on',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('www.parked.example.com', $result['error']);
        $this->assertCount(1, $this->store->all(), 'domain utama tidak boleh tersimpan bila www bentrok');
        $this->assertSame($writerCalls, $this->writer->calls);
        $this->assertSame(1, $this->reloader->calls);
    }

    // ==================================================================
    // UPDATE
    // ==================================================================

    public function testUpdateHtmlToRedirectRemovesOldContentAfterSuccess(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Lama</h1>');
        $id = (string) $created['id'];

        $result = $this->manager->update($id, [
            'domain' => 'parked.example.com',
            'mode' => 'redirect',
            'redirect_to' => 'https://tujuan.example',
            'redirect_code' => 302,
        ]);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(2, $this->reloader->calls);
        $this->assertNotNull($result['domain']);
        $this->assertSame('redirect', $result['domain']['mode']);
        $this->assertSame(302, $result['domain']['redirect_code']);
        $this->assertSame('https://tujuan.example', $result['domain']['redirect_to']);
        $this->assertDirectoryDoesNotExist($this->content->dirFor($id), 'konten html lama dihapus setelah sukses');
        $this->assertConfigContains($id, 'return 302 https://tujuan.example$request_uri;');
        $this->assertStringNotContainsString(
            'root ' . $this->root . '/' . $id . ';',
            (string) file_get_contents($this->conf . '/_parked_' . $id . '.conf')
        );
    }

    public function testUpdateSameDomainPreservesSslState(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Lama</h1>');
        $id = (string) $created['id'];
        $this->seedSsl($id, [
            'ssl_status' => 'active',
            'ssl_stage' => 'issued',
            'ssl_message' => 'Sertifikat terbit',
            'ssl_error' => null,
            'ssl_expires_at' => '2026-12-01T00:00:00+00:00',
        ]);

        $issuer = new ParkedFakeSslIssuer();
        $result = $this->managerWithIssuer($issuer)->update($id, [
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<h1>Baru</h1>',
        ]);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame([], $issuer->revoked, 'domain sama ⇒ sertifikat tidak boleh di-revoke');
        $this->assertNotNull($result['domain']);
        $this->assertSame('active', $result['domain']['ssl_status']);
        $this->assertSame('2026-12-01T00:00:00+00:00', $result['domain']['ssl_expires_at']);

        $stored = $this->store->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('active', $stored['ssl_status']);
        $this->assertSame('issued', $stored['ssl_stage']);
        $this->assertSame('Sertifikat terbit', $stored['ssl_message']);
        $this->assertSame('2026-12-01T00:00:00+00:00', $stored['ssl_expires_at']);
    }

    public function testUpdateRenameResetsSslStateAndRevokesOldDomain(): void
    {
        $created = $this->createHtml('lama.example.com', '<h1>Lama</h1>');
        $id = (string) $created['id'];
        $this->seedSsl($id, [
            'ssl_status' => 'active',
            'ssl_stage' => 'issued',
            'ssl_message' => 'Sertifikat terbit',
            'ssl_error' => null,
            'ssl_expires_at' => '2026-12-01T00:00:00+00:00',
        ]);

        $issuer = new ParkedFakeSslIssuer();
        $result = $this->managerWithIssuer($issuer)->update($id, [
            'domain' => 'baru.example.com',
            'mode' => 'html',
            'html' => '<h1>Baru</h1>',
        ]);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame('', $result['error']);
        $this->assertSame(['lama.example.com'], $issuer->revoked, 'revoke domain lama tepat sekali');

        $stored = $this->store->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('baru.example.com', $stored['domain']);
        $this->assertSame('disabled', $stored['ssl_status']);
        foreach (['ssl_stage', 'ssl_message', 'ssl_error', 'ssl_expires_at'] as $key) {
            $this->assertArrayHasKey($key, $stored);
            $this->assertNull($stored[$key], $key . ' harus di-reset saat rename');
        }

        $config = (string) file_get_contents($this->conf . '/_parked_' . $id . '.conf');
        $this->assertStringContainsString('server_name baru.example.com;', $config);
        $this->assertStringNotContainsString('lama.example.com', $config);
    }

    public function testUpdateRenameRevokeFailureKeepsChangeAndWarns(): void
    {
        $created = $this->createHtml('lama.example.com', '<h1>Lama</h1>');
        $id = (string) $created['id'];
        $this->seedSsl($id, ['ssl_status' => 'active']);

        $issuer = new ParkedFakeSslIssuer();
        $issuer->revokeError = new RuntimeException('certbot revoke gagal (test)');

        $result = $this->managerWithIssuer($issuer)->update($id, [
            'domain' => 'baru.example.com',
            'mode' => 'html',
            'html' => '<h1>Baru</h1>',
        ]);

        $this->assertTrue($result['ok'], 'kegagalan revoke tidak membatalkan perubahan');
        $this->assertStringContainsString('PERINGATAN', $result['error']);
        $this->assertStringContainsString('lama.example.com', $result['error']);
        $this->assertFalse($result['rolled_back']);

        $stored = $this->store->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('baru.example.com', $stored['domain']);
        $this->assertSame('disabled', $stored['ssl_status']);
        $this->assertConfigContains($id, 'server_name baru.example.com;');
    }

    public function testUpdateRenameReloadFailureRestoresSslStateWithoutRevoke(): void
    {
        $created = $this->createHtml('lama.example.com', '<h1>Lama</h1>');
        $id = (string) $created['id'];
        $this->seedSsl($id, [
            'ssl_status' => 'active',
            'ssl_stage' => 'issued',
            'ssl_expires_at' => '2026-12-01T00:00:00+00:00',
        ]);
        $this->reloader->result = ['ok' => false, 'error' => 'nginx menolak config'];

        $issuer = new ParkedFakeSslIssuer();
        $result = $this->managerWithIssuer($issuer)->update($id, [
            'domain' => 'baru.example.com',
            'mode' => 'html',
            'html' => '<h1>Baru</h1>',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back'], $result['error']);
        $this->assertSame([], $issuer->revoked, 'rollback ⇒ domain lama tidak boleh di-revoke');

        $stored = $this->store->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('lama.example.com', $stored['domain']);
        $this->assertSame('active', $stored['ssl_status']);
        $this->assertSame('issued', $stored['ssl_stage']);
        $this->assertSame('2026-12-01T00:00:00+00:00', $stored['ssl_expires_at']);
        $this->assertConfigContains($id, 'server_name lama.example.com;');
    }

    public function testUpdateReloadFailureRestoresOldEntryAndContent(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Lama</h1>');
        $id = (string) $created['id'];
        $this->reloader->result = ['ok' => false, 'error' => 'nginx menolak config'];

        $result = $this->manager->update($id, [
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<h1>Baru</h1>',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['reloaded']);
        $this->assertTrue($result['rolled_back'], $result['error']);
        $this->assertStringContainsString('dibatalkan', $result['error']);
        $this->assertSame('<h1>Lama</h1>', $this->content->read($id), 'konten lama harus dipulihkan');
        $this->assertSame('html', $this->store->find($id)['mode']);
        $this->assertConfigContains($id, 'root ' . $this->root . '/' . $id . ';');
        $this->assertSame(2, $this->reloader->calls);
    }

    public function testUpdateConfigWriteFailureRestoresOldContent(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Lama</h1>');
        $id = (string) $created['id'];

        $this->writer->failOnCall = 2; // create=1, config update=2 (gagal), rollback=3
        $result = $this->manager->update($id, [
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<h1>Baru</h1>',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['reloaded'], 'reload tidak boleh dipanggil bila config gagal ditulis');
        $this->assertTrue($result['rolled_back'], $result['error']);
        $this->assertStringContainsString('simulasi gagal tulis config', $result['error']);
        $this->assertSame('<h1>Lama</h1>', $this->content->read($id), 'konten lama harus pulih');
        $this->assertSame(1, $this->reloader->calls);
        $this->assertSame(3, $this->writer->calls, 'config lama harus ditulis ulang saat rollback');
        $this->assertConfigContains($id, 'root ' . $this->root . '/' . $id . ';');
    }

    public function testUpdateHtmlToRedirectRollbackRestoresContentAndConfig(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Lama</h1>');
        $id = (string) $created['id'];
        $this->reloader->result = ['ok' => false, 'error' => 'config invalid'];

        $result = $this->manager->update($id, [
            'domain' => 'parked.example.com',
            'mode' => 'redirect',
            'redirect_to' => 'https://tujuan.example',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back'], $result['error']);
        $this->assertSame('html', $this->store->find($id)['mode']);
        $this->assertSame('<h1>Lama</h1>', $this->content->read($id));
        $this->assertConfigContains($id, 'root ' . $this->root . '/' . $id . ';');
        $this->assertStringNotContainsString(
            'return 301',
            (string) file_get_contents($this->conf . '/_parked_' . $id . '.conf')
        );
    }

    public function testUpdateNonLocalEntryRejected(): void
    {
        $entry = $this->store->create([
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'server_id' => 'agent-7',
        ]);

        $result = $this->manager->update((string) $entry['id'], [
            'domain' => 'baru.example.com',
            'mode' => 'html',
            'html' => '<p>x</p>',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(ParkedDomainManager::LOCAL_ONLY_MESSAGE, $result['error']);
        $stored = $this->store->find((string) $entry['id']);
        $this->assertSame('parked.example.com', $stored['domain']);
        $this->assertSame(0, $this->writer->calls);
        $this->assertSame(0, $this->reloader->calls);
    }

    // ==================================================================
    // DELETE
    // ==================================================================

    public function testDeleteSuccessRemovesConfigAndContent(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Halo</h1>');
        $id = (string) $created['id'];

        $result = $this->manager->delete($id);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertTrue($result['reloaded']);
        $this->assertFalse($result['rolled_back']);
        $this->assertSame(2, $this->reloader->calls);
        $this->assertNull($this->store->find($id));
        $this->assertFileDoesNotExist($this->conf . '/_parked_' . $id . '.conf');
        $this->assertFalse(is_link($this->enabled . '/_parked_' . $id . '.conf'));
        $this->assertDirectoryDoesNotExist($this->content->dirFor($id));
    }

    public function testDeleteActiveSslEntryRevokesCertificate(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Halo</h1>');
        $id = (string) $created['id'];
        $this->seedSsl($id, ['ssl_status' => 'active', 'ssl_expires_at' => '2026-12-01T00:00:00+00:00']);

        $issuer = new ParkedFakeSslIssuer();
        $result = $this->managerWithIssuer($issuer)->delete($id);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame('', $result['error']);
        $this->assertSame(['parked.example.com'], $issuer->revoked);
        $this->assertNull($this->store->find($id));
        $this->assertFileDoesNotExist($this->conf . '/_parked_' . $id . '.conf');
    }

    public function testDeleteRevokesWhenCertificateExistsDespiteInactiveState(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Halo</h1>');
        $id = (string) $created['id'];

        $issuer = new ParkedFakeSslIssuer();
        $issuer->certExistsResult = true;
        $result = $this->managerWithIssuer($issuer)->delete($id);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(['parked.example.com'], $issuer->revoked);
        $this->assertSame(1, $issuer->certExistsCalls);
    }

    public function testDeleteSslRevokeFailureKeepsDeletionAndWarns(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Halo</h1>');
        $id = (string) $created['id'];
        $this->seedSsl($id, ['ssl_status' => 'active']);

        $issuer = new ParkedFakeSslIssuer();
        $issuer->revokeError = new RuntimeException('certbot revoke gagal (test)');
        $result = $this->managerWithIssuer($issuer)->delete($id);

        $this->assertTrue($result['ok'], 'kegagalan revoke tidak membatalkan penghapusan');
        $this->assertStringContainsString('PERINGATAN', $result['error']);
        $this->assertStringContainsString('parked.example.com', $result['error']);
        $this->assertNull($this->store->find($id));
        $this->assertFileDoesNotExist($this->conf . '/_parked_' . $id . '.conf');
    }

    public function testDeleteReloadFailureDoesNotRevokeAndRestoresEntry(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Halo</h1>');
        $id = (string) $created['id'];
        $this->seedSsl($id, [
            'ssl_status' => 'active',
            'ssl_stage' => 'issued',
            'ssl_expires_at' => '2026-12-01T00:00:00+00:00',
        ]);
        $this->reloader->result = ['ok' => false, 'error' => 'nginx menolak config'];

        $issuer = new ParkedFakeSslIssuer();
        $result = $this->managerWithIssuer($issuer)->delete($id);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['rolled_back'], $result['error']);
        $this->assertSame([], $issuer->revoked, 'reload gagal ⇒ tidak boleh revoke');

        $stored = $this->store->find($id);
        $this->assertNotNull($stored, 'entri harus dipulihkan');
        $this->assertSame('active', $stored['ssl_status']);
        $this->assertSame('2026-12-01T00:00:00+00:00', $stored['ssl_expires_at']);
        $this->assertConfigContains($id, 'server_name parked.example.com;');
    }

    public function testDeleteReloadFailureRestoresEntryAndConfig(): void
    {
        $created = $this->createHtml('parked.example.com', '<h1>Halo</h1>');
        $id = (string) $created['id'];
        $this->reloader->result = ['ok' => false, 'error' => 'nginx menolak config'];

        $result = $this->manager->delete($id);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['reloaded']);
        $this->assertTrue($result['rolled_back'], $result['error']);
        $this->assertStringContainsString('nginx menolak config', $result['error']);
        $this->assertNotNull($this->store->find($id), 'entri harus dipulihkan');
        $this->assertConfigContains($id, 'root ' . $this->root . '/' . $id . ';');
        $this->assertSame('<h1>Halo</h1>', $this->content->read($id));
        $this->assertSame(2, $this->reloader->calls);
    }

    public function testDeleteNonLocalEntryRejected(): void
    {
        $entry = $this->store->create([
            'domain' => 'parked.example.com',
            'mode' => 'redirect',
            'redirect_to' => 'https://tujuan.example',
            'server_id' => 'agent-7',
        ]);

        $result = $this->manager->delete((string) $entry['id']);

        $this->assertFalse($result['ok']);
        $this->assertSame(ParkedDomainManager::LOCAL_ONLY_MESSAGE, $result['error']);
        $this->assertNotNull($this->store->find((string) $entry['id']));
        $this->assertSame(0, $this->reloader->calls);
    }

    public function testDeleteUnknownEntryReportsClearError(): void
    {
        $result = $this->manager->delete('pd_ffffffffffff');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('tidak ditemukan', $result['error']);
        $this->assertSame(0, $this->reloader->calls);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @return array<string,mixed> entri hasil create
     */
    private function createHtml(string $domain, string $html): array
    {
        $result = $this->manager->create(['domain' => $domain, 'mode' => 'html', 'html' => $html]);
        $this->assertTrue($result['ok'], $result['error']);

        return $result['created'][0];
    }

    /**
     * Manager dengan issuer fake (parameter baru opsional, paling akhir).
     */
    private function managerWithIssuer(SslIssuer $issuer): ParkedDomainManager
    {
        return new ParkedDomainManager(
            $this->store,
            $this->apps,
            $this->reloader,
            $this->writer,
            $this->content,
            $issuer
        );
    }

    /**
     * Tulis state SSL langsung ke store (mensimulasikan worker SSL yang sudah
     * menerbitkan sertifikat).
     *
     * @param array<string,mixed> $fields
     */
    private function seedSsl(string $id, array $fields): void
    {
        $this->store->update($id, static function (array &$row) use ($fields): void {
            foreach ($fields as $key => $value) {
                $row[$key] = $value;
            }
        });
    }

    private function assertConfigContains(string $id, string $needle): void
    {
        $path = $this->conf . '/_parked_' . $id . '.conf';
        $this->assertFileExists($path);
        $this->assertStringContainsString($needle, (string) file_get_contents($path));
    }

    private function assertEnabled(string $id): void
    {
        $file = $this->conf . '/_parked_' . $id . '.conf';
        $link = $this->enabled . '/_parked_' . $id . '.conf';
        $this->assertTrue(is_link($link), 'config harus di-symlink ke sites-enabled');
        $this->assertSame($file, readlink($link));
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
