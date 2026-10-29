<?php
declare(strict_types=1);

namespace Tests;

use app\controller\DomainController;
use app\library\Deploy\ServerResolver;
use app\library\Domain\ParkedDomainContent;
use app\library\Domain\ParkedDomainManager;
use app\library\Domain\ParkedDomainValidator;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslErrorSummary;
use app\library\SSL\SslQueue;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use app\library\Storage\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;
use Webman\Http\Response;
use Webman\Http\UploadFile;

// Helper webman (`config()`, `app_subdomain_of()`) hanya dimuat di runtime
// webman: definisikan konstanta + fungsi sebelum kelas dipakai.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!function_exists('app_subdomain_of')) {
    require_once dirname(__DIR__) . '/app/functions.php';
}

/**
 * Test mediator `DomainController` (halaman domain parking, docs/SPECS.md §7.11/§8b).
 *
 * Tanpa HTTP nyata, tanpa session, tanpa Nginx/Docker. Store SQLite, direktori
 * config Nginx, dan akar konten diarahkan ke direktori temp unik. `manager()`
 * memakai `ParkedDomainManager` sungguhan dengan reloader tiruan — jadi alur
 * create/update benar-benar dijalankan tanpa menyentuh host.
 *
 * Yang dibuktikan: non-admin ⇒ 404 di semua method, index mengirim `domains`,
 * create sukses memanggil manager + flash sukses, create gagal merender ulang
 * form (bukan redirect), entri tak ada ⇒ 404, dan unggahan HTML kebesaran
 * ditolak SEBELUM manager dipanggil.
 */
class DomainControllerTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];

    private string $tmp;
    private ParkedDomainStore $parked;
    private AppStore $apps;

    private SslQueue $queue;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->configState = $this->snapshotConfigState();
        $this->tmp = sys_get_temp_dir() . '/rames-domainctrl-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/nginx/conf', 0777, true);
        mkdir($this->tmp . '/nginx/enabled', 0777, true);
        mkdir($this->tmp . '/parked', 0777, true);
        mkdir($this->tmp . '/config', 0777, true);

        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->useConfig();

        $db = $this->tmp . '/rames.sqlite';
        $this->parked = new ParkedDomainStore($db);
        $this->apps = new AppStore($db);
        $this->queue = new SslQueue(new SqliteDatabase($db));
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        ServerResolver::forgetMemo();
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // (a) admin-only ⇒ 404 di semua method
    // ------------------------------------------------------------------

    public function testParkedManagementMethodsReturn404ForNonAdmin(): void
    {
        $controller = $this->controller(self::MEMBER);

        $responses = [
            'createForm' => $controller->createForm($this->get('/domains/create')),
            'editForm' => $controller->editForm($this->get('/domains/pd_000000000000/edit'), 'pd_000000000000'),
            'create' => $controller->create($this->post('/domains', ['domain' => 'parked.example.com'])),
            'update' => $controller->update(
                $this->post('/domains/pd_000000000000', ['domain' => 'parked.example.com']),
                'pd_000000000000'
            ),
            'delete' => $controller->delete($this->post('/domains/pd_000000000000/delete', []), 'pd_000000000000'),
            'enableParked' => $controller->enableParked(
                $this->post('/domains/parked/pd_000000000000/ssl', []),
                'pd_000000000000'
            ),
            'apiEnableParked' => $controller->apiEnableParked(
                $this->post('/api/domains/parked/pd_000000000000/enable', []),
                'pd_000000000000'
            ),
        ];

        foreach ($responses as $method => $response) {
            $this->assertSame(404, $response->getStatusCode(), $method . ' harus 404 untuk non-admin');
        }
        $this->assertSame(0, $this->queue->queueSize(), 'tidak boleh ada job SSL untuk non-admin');
        $this->assertSame(0, $controller->managerCalls, 'manager tidak boleh dipanggil non-admin');
        $this->assertNull($controller->rendered, 'view tidak boleh dirender untuk non-admin');
        $this->assertSame([], $controller->flashes, 'tidak ada flash untuk non-admin');
        $this->assertSame([], $this->parked->all(), 'tidak ada mutasi untuk non-admin');
    }

    /**
     * `GET /domains` terbuka untuk semua user login; baris app disaring
     * `AppAccess::visible()` dan baris domain parkir hanya untuk admin.
     */
    public function testNonAdminIndexSeesOnlyAccessibleAppRowsWithoutParkedRows(): void
    {
        $own = $this->apps->create([
            'name' => 'milik-budi',
            'status' => 'running',
            'owner_id' => self::MEMBER['id'],
            'containers' => [['host_port' => 30001, 'internal_port' => 80]],
        ]);
        $this->apps->create([
            'name' => 'milik-admin',
            'status' => 'running',
            'owner_id' => self::ADMIN['id'],
            'containers' => [['host_port' => 30002, 'internal_port' => 80]],
        ]);
        $this->parked->create(['domain' => 'parkir.example.com', 'mode' => 'html']);

        $controller = $this->controller(self::MEMBER);
        $response = $controller->index($this->get('/domains?tab=parking'));

        $this->assertSame(200, $response->getStatusCode());
        $vars = $controller->rendered['vars'];
        $this->assertFalse($vars['isAdmin']);
        $this->assertSame('all', $vars['tab'], 'tab parkir dipaksa all untuk non-admin');
        $this->assertSame([], $vars['domains'], 'CRUD domain parkir tidak boleh melihat data apa pun');
        $this->assertCount(1, $vars['rows'], 'hanya app yang boleh diakses');
        $this->assertSame($own['id'], $vars['rows'][0]['app_id']);
        $this->assertSame('milik-budi.example.org', $vars['rows'][0]['domain']);
        $this->assertArrayNotHasKey('app', $vars['rows'][0], 'objek app tidak boleh bocor ke payload');
        $this->assertTrue($vars['rows'][0]['can_ssl'], 'owner punya ability ssl');
        foreach ($vars['rows'] as $row) {
            $this->assertNotSame('parked', $row['kind'], 'baris parkir tidak boleh muncul untuk non-admin');
        }
        $this->assertTrue($vars['sslSupported']);
    }

    // ------------------------------------------------------------------
    // (b) index admin mengirim daftar domain
    // ------------------------------------------------------------------

    public function testIndexSendsDomainsPayload(): void
    {
        $this->parked->create([
            'domain' => 'parked.example.com',
            'mode' => 'redirect',
            'redirect_to' => 'https://example.org',
            'redirect_code' => 301,
            'server_id' => 'local',
        ]);

        $controller = $this->controller(self::ADMIN);
        $response = $controller->index($this->get('/domains'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($controller->rendered);
        $this->assertSame('domain/index', $controller->rendered['template']);

        $vars = $controller->rendered['vars'];
        $this->assertCount(1, $vars['domains']);
        $this->assertSame('parked.example.com', $vars['domains'][0]['domain']);
        $this->assertSame('domains', $vars['active']);
        $this->assertSame(ParkedDomainValidator::MAX_HTML_BYTES, $vars['maxHtmlBytes']);
        $this->assertSame([], $vars['errors']);
        $this->assertArrayHasKey('appDomain', $vars);
        $this->assertTrue($vars['isAdmin']);
        $this->assertSame('all', $vars['tab']);
        $this->assertCount(1, $vars['rows'], 'admin melihat baris parkir');
        $this->assertSame('parked', $vars['rows'][0]['kind']);
        $this->assertSame('parked.example.com', $vars['rows'][0]['domain']);
        $this->assertTrue($vars['rows'][0]['can_ssl']);
        $this->assertSame('/domains/parked/' . $vars['rows'][0]['id'] . '/ssl', $vars['rows'][0]['form_url']);
        $this->assertSame('/api/domains/parked/' . $vars['rows'][0]['id'] . '/enable', $vars['rows'][0]['ajax_url']);
        $this->assertSame('Domain parkir', $vars['rows'][0]['source']);
    }

    // ------------------------------------------------------------------
    // (b2) filter `?domain=` pada halaman /domains
    // ------------------------------------------------------------------

    public function testIndexMenyaringBarisDanDaftarParkirDenganFilterDomain(): void
    {
        $this->apps->create([
            'name' => 'disbuk',
            'status' => 'running',
            'owner_id' => self::ADMIN['id'],
            'containers' => [['host_port' => 30011, 'internal_port' => 80]],
        ]);
        $this->apps->create([
            'name' => 'renkeu',
            'status' => 'running',
            'owner_id' => self::ADMIN['id'],
            'containers' => [['host_port' => 30012, 'internal_port' => 80]],
        ]);
        $this->parked->create(['domain' => 'disbuk-parkir.example.com', 'mode' => 'html']);
        $this->parked->create(['domain' => 'lain.example.com', 'mode' => 'html']);

        $controller = $this->controller(self::ADMIN);
        $response = $controller->index($this->get('/domains?tab=parking&domain=DISBUK'));

        $this->assertSame(200, $response->getStatusCode());
        $vars = $controller->rendered['vars'];

        $this->assertSame('disbuk', $vars['filter'], 'nilai efektif sudah trim+lowercase');
        $this->assertTrue($vars['filterActive']);
        $this->assertSame('parking', $vars['tab'], 'filter tidak boleh mengubah tab');
        $this->assertSame(
            ['disbuk.example.org', 'disbuk-parkir.example.com'],
            array_column($vars['rows'], 'domain'),
            'baris tabel (app + parkir) disaring substring case-insensitive'
        );
        $this->assertSame(
            ['disbuk-parkir.example.com'],
            array_column($vars['domains'], 'domain'),
            'daftar CRUD domain parkir memakai filter yang sama'
        );
    }

    public function testIndexTanpaFilterMengirimSemuaBarisDanFilterNonAktif(): void
    {
        $this->parked->create(['domain' => 'a.example.com', 'mode' => 'html']);
        $this->parked->create(['domain' => 'b.example.com', 'mode' => 'html']);

        $controller = $this->controller(self::ADMIN);
        $controller->index($this->get('/domains'));

        $vars = $controller->rendered['vars'];
        $this->assertSame('', $vars['filter']);
        $this->assertFalse($vars['filterActive']);
        $this->assertCount(2, $vars['domains']);
    }

    public function testFilterTidakMenambahBarisAppMilikUserLain(): void
    {
        $this->apps->create([
            'name' => 'milik-admin',
            'status' => 'running',
            'owner_id' => self::ADMIN['id'],
            'containers' => [['host_port' => 30013, 'internal_port' => 80]],
        ]);

        $controller = $this->controller(self::MEMBER);
        $controller->index($this->get('/domains?domain=milik-admin'));

        $vars = $controller->rendered['vars'];
        $this->assertSame([], $vars['rows'], 'otorisasi tetap menang atas filter');
        $this->assertTrue($vars['filterActive']);
    }

    /**
     * `GET /api/domains/status` memakai baris terdekorasi yang sama: app +
     * parkir untuk admin, tanpa objek `app` mentah.
     */
    public function testApiStatusCarriesQueuePositionForPendingRows(): void
    {
        $app = $this->apps->create([
            'name' => 'antre-app',
            'status' => 'running',
            'owner_id' => self::ADMIN['id'],
            'containers' => [['host_port' => 30006, 'internal_port' => 80]],
            'ssl_status' => 'pending',
            'ssl_stage' => 'queued',
            'ssl_message' => 'Menunggu antrean SSL ...',
        ]);
        $this->queue->enqueueApp($app, 'antre-app.example.org', 'ssl');

        $controller = $this->controller(self::ADMIN);
        $body = json_decode((string) $controller->apiStatus($this->get('/api/domains/status'))->rawBody(), true);

        $this->assertSame(1, $body['rows'][0]['ssl_queue_position']);
        $this->assertSame(1, $body['rows'][0]['ssl_queue_size']);
    }

    public function testApiStatusIncludesAppAndParkedRowsForAdmin(): void
    {
        $app = $this->apps->create([
            'name' => 'status-app',
            'status' => 'running',
            'owner_id' => self::ADMIN['id'],
            'containers' => [['host_port' => 30005, 'internal_port' => 80]],
        ]);
        $parked = $this->parked->create([
            'domain' => 'status-parkir.example.com',
            'mode' => 'html',
            'ssl_status' => 'pending',
        ]);

        $controller = $this->controller(self::ADMIN);
        $response = $controller->apiStatus($this->get('/api/domains/status'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertSame(0, $body['code']);
        $this->assertTrue($body['ssl_supported']);
        $this->assertTrue($body['pending']);
        $this->assertSame(['app', 'parked'], array_column($body['rows'], 'kind'));
        $this->assertSame($app['id'], $body['rows'][0]['app_id']);
        $this->assertSame('status-app.example.org', $body['rows'][0]['domain']);
        $this->assertSame($parked['id'], $body['rows'][1]['id']);
        $this->assertSame('status-parkir.example.com', $body['rows'][1]['domain']);
        foreach ($body['rows'] as $row) {
            $this->assertArrayNotHasKey('app', $row, 'payload JSON tidak boleh memuat objek app');
            // Field BEKU untuk polling tambahan SSL (`public/js/ssl.js`).
            $this->assertArrayHasKey('ssl_queue_position', $row);
            $this->assertArrayHasKey('ssl_queue_size', $row);
        }
    }

    /**
     * Kolom Aksi tabel `/domains` hanya memuat **ringkasan** galat SSL
     * (`ssl_error_summary`, dihitung `SslErrorSummary`), sedangkan teks utuh
     * certbot tetap dikirim (`ssl_error`) untuk modal **Log**. Ringkasan dipakai
     * jalur render awal **dan** polling — satu sumber, tanpa pemotongan di JS.
     */
    public function testFailedSslRowCarriesShortSummaryAndFullError(): void
    {
        $full = 'Gagal menerbitkan sertifikat SSL untuk ringkas.example.org: Requesting a certificate for '
            . 'ringkas.example.org Certbot failed to authenticate some domains (authenticator: webroot). '
            . 'Identifier: ringkas.example.org Type: dns Detail: no valid A records found for '
            . 'ringkas.example.org; no valid AAAA records found for ringkas.example.org';

        $app = $this->apps->create([
            'name' => 'ringkas',
            'status' => 'running',
            'owner_id' => self::ADMIN['id'],
            'containers' => [['host_port' => 30006, 'internal_port' => 80]],
            'ssl_status' => 'failed',
            'ssl_error' => $full,
        ]);

        $controller = $this->controller(self::ADMIN);

        // Polling JSON.
        $body = json_decode((string) $controller->apiStatus($this->get('/api/domains/status'))->rawBody(), true);
        $row = $body['rows'][0];
        $this->assertSame($app['id'], $row['app_id']);
        $this->assertSame($full, $row['ssl_error'], 'teks utuh tetap dikirim untuk modal Log');
        $this->assertSame('DNS domain belum mengarah ke server ini', $row['ssl_error_summary']);
        $this->assertLessThanOrEqual(
            SslErrorSummary::MAX_LENGTH,
            mb_strlen((string) $row['ssl_error_summary']),
            'ringkasan wajib pendek'
        );

        // Render awal view memakai ringkasan yang sama.
        $this->assertSame(200, $controller->index($this->get('/domains'))->getStatusCode());
        $vars = $controller->rendered['vars'];
        $this->assertSame($full, $vars['rows'][0]['ssl_error']);
        $this->assertSame($row['ssl_error_summary'], $vars['rows'][0]['ssl_error_summary']);
    }

    // ------------------------------------------------------------------
    // (b2) aktifkan SSL domain parkir (admin-only)
    // ------------------------------------------------------------------

    public function testEnableParkedSpawnsWorkerAndFlashesSuccess(): void
    {
        $entry = $this->parked->create(['domain' => 'ssl-parkir.example.com', 'mode' => 'html']);

        $controller = $this->controller(self::ADMIN);
        $response = $controller->enableParked(
            $this->post('/domains/parked/' . $entry['id'] . '/ssl', []),
            $entry['id']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/domains?tab=parking', $response->getHeader('Location'));
        $this->assertIsArray(
            $this->queue->findActive('parked:' . $entry['id'] . ':ssl-parkir.example.com'),
            'permintaan SSL parkir masuk antrean global'
        );
        $this->assertCount(1, $controller->flashes);
        $this->assertSame('success', $controller->flashes[0]['type']);
        $this->assertSame(
            'SSL untuk ssl-parkir.example.com masuk antrean (posisi 1 dari 1).',
            $controller->flashes[0]['message']
        );

        $saved = $this->parked->find($entry['id']);
        $this->assertSame('pending', $saved['ssl_status']);
        $this->assertSame('queued', $saved['ssl_stage']);
        $this->assertSame('Menunggu antrean SSL ...', $saved['ssl_message']);
        $this->assertNull($saved['ssl_error']);
    }

    public function testEnableParkedMissingEntryFlashesErrorWithoutSpawn(): void
    {
        $controller = $this->controller(self::ADMIN);
        $response = $controller->enableParked(
            $this->post('/domains/parked/pd_000000000000/ssl', []),
            'pd_000000000000'
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->queue->queueSize());
        $this->assertSame('error', $controller->flashes[0]['type'] ?? '');
        $this->assertStringContainsString('tidak ditemukan', $controller->flashes[0]['message'] ?? '');
    }

    public function testApiEnableParkedReturnsEnvelope(): void
    {
        $entry = $this->parked->create(['domain' => 'api-parkir.example.com', 'mode' => 'html']);

        $controller = $this->controller(self::ADMIN);
        $response = $controller->apiEnableParked(
            $this->post('/api/domains/parked/' . $entry['id'] . '/enable', []),
            $entry['id']
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->rawBody(), true);
        $this->assertSame(0, $body['code']);
        $this->assertTrue($body['ok']);
        $this->assertSame('pending', $body['status']);
        $this->assertSame('api-parkir.example.com', $body['domain']);
        $this->assertSame(
            'SSL untuk api-parkir.example.com masuk antrean (posisi 1 dari 1).',
            $body['message']
        );
        $this->assertIsArray(
            $this->queue->findActive('parked:' . $entry['id'] . ':api-parkir.example.com')
        );
    }

    /**
     * Alias lama `GET /ssl` kini hanya mengalihkan ke `/domains` (tabel SSL
     * menyatu). Pembuktian "tanpa menyentuh store": baris parkir tidak berubah,
     * tidak ada spawn worker, manager tidak dipanggil, tanpa flash/render.
     */
    public function testLegacySslRedirectsToDomainsWithoutTouchingStore(): void
    {
        $entry = $this->parked->create(['domain' => 'alias-parkir.example.com', 'mode' => 'html']);
        $before = $this->parked->all();

        $controller = $this->controller(self::ADMIN);
        $response = $controller->legacySsl($this->get('/ssl'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/domains', $response->getHeader('Location'));
        $this->assertSame(0, $this->queue->queueSize(), 'alias tidak boleh melahirkan job SSL');
        $this->assertSame($before, $this->parked->all(), 'store tidak boleh berubah');
        $this->assertSame($entry['id'], $this->parked->find($entry['id'])['id'] ?? null, 'entri parkir tetap utuh');
        $this->assertSame(0, $controller->managerCalls, 'manager tidak boleh dipanggil');
        $this->assertSame([], $controller->flashes, 'alias tidak menulis flash');
        $this->assertNull($controller->rendered, 'alias tidak merender view');
    }

    // ------------------------------------------------------------------
    // (c) create sukses ⇒ manager dipanggil + flash sukses + redirect
    // ------------------------------------------------------------------

    public function testCreateSuccessCallsManagerAndFlashesSuccess(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->create($this->post('/domains', [
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<h1>Segera hadir</h1>',
            'add_www' => '1',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/domains', $response->getHeader('Location'));
        $this->assertSame(1, $controller->managerCalls);
        $this->assertCount(2, $this->parked->all(), 'add_www membuat dua entri');
        $this->assertCount(1, $controller->flashes);
        $this->assertSame('success', $controller->flashes[0]['type']);
        $this->assertStringContainsString('parked.example.com', $controller->flashes[0]['message']);
        $this->assertStringContainsString('www.', $controller->flashes[0]['message']);
    }

    // ------------------------------------------------------------------
    // (d) create gagal ⇒ render ulang form + errors (bukan redirect)
    // ------------------------------------------------------------------

    public function testCreateFailureRendersFormWithErrorsAndPreservesInput(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->create($this->post('/domains', [
            'domain' => 'bukan_domain_valid',
            'mode' => 'html',
            'html' => '<p>x</p>',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $controller->managerCalls);
        $this->assertSame([], $controller->flashes, 'kegagalan form tidak memakai flash');
        $this->assertNotNull($controller->rendered);
        $this->assertSame('domain/form', $controller->rendered['template']);

        $vars = $controller->rendered['vars'];
        $this->assertNotSame([], $vars['errors']);
        $this->assertStringContainsString('Domain tidak valid', (string) $vars['errors'][0]);
        $this->assertSame('bukan_domain_valid', $vars['input']['domain']);
        $this->assertFalse($vars['isEdit']);
        $this->assertSame([], $this->parked->all(), 'tidak ada entri tersimpan');
    }

    public function testCreateFailureReturnsJson422ForAjax(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->create($this->post('/domains', [
            'domain' => 'bukan_domain_valid',
            'mode' => 'html',
            'html' => '<p>x</p>',
        ], ['Accept' => 'application/json']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($controller->rendered);

        $payload = json_decode((string) $response->rawBody(), true);
        $this->assertSame(1, $payload['code']);
        $this->assertStringContainsString('Domain tidak valid', (string) $payload['error']);
    }

    // ------------------------------------------------------------------
    // (e) entri tidak ada ⇒ 404
    // ------------------------------------------------------------------

    public function testEditFormReturns404ForMissingEntry(): void
    {
        $controller = $this->controller(self::ADMIN);

        $response = $controller->editForm($this->get('/domains/pd_000000000000/edit'), 'pd_000000000000');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($controller->rendered);
    }

    public function testUpdateAndDeleteReturn404ForMissingEntryBeforeManager(): void
    {
        $controller = $this->controller(self::ADMIN);

        $update = $controller->update(
            $this->post('/domains/pd_000000000000', ['domain' => 'parked.example.com']),
            'pd_000000000000'
        );
        $delete = $controller->delete($this->post('/domains/pd_000000000000/delete', []), 'pd_000000000000');

        $this->assertSame(404, $update->getStatusCode());
        $this->assertSame(404, $delete->getStatusCode());
        $this->assertSame(0, $controller->managerCalls, 'manager tidak boleh dipanggil bila entri tidak ada');
    }

    // ------------------------------------------------------------------
    // (f) unggahan HTML kebesaran ditolak sebelum manager
    // ------------------------------------------------------------------

    public function testOversizedHtmlUploadIsRejectedBeforeManager(): void
    {
        $path = $this->tmp . '/big.html';
        file_put_contents($path, str_repeat('a', ParkedDomainValidator::MAX_HTML_BYTES + 1));

        $controller = $this->controller(self::ADMIN);
        $controller->upload = new UploadFile($path, 'big.html', 'text/html', UPLOAD_ERR_OK);

        $response = $controller->create($this->post('/domains', [
            'domain' => 'parked.example.com',
            'mode' => 'html',
            'html' => '<p>kecil</p>',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $controller->managerCalls, 'unggahan kebesaran harus ditolak sebelum manager');
        $this->assertNotNull($controller->rendered);
        $this->assertSame('domain/form', $controller->rendered['template']);

        $vars = $controller->rendered['vars'];
        $this->assertStringContainsString((string) ParkedDomainValidator::MAX_HTML_BYTES, (string) $vars['errors'][0]);
        $this->assertStringContainsString('1 MiB', (string) $vars['errors'][0]);
        $this->assertSame('<p>kecil</p>', $vars['input']['html']);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function controller(?array $user): FakeDomainController
    {
        $controller = new FakeDomainController(
            $this->tmp,
            $this->parked,
            $this->apps,
            new FakeParkedReloader(),
            $this->queue
        );
        $controller->user = $user;

        return $controller;
    }

    /**
     * @param array<string,mixed>  $fields
     * @param array<string,string> $headers
     */
    private function post(string $path, array $fields, array $headers = []): Request
    {
        $body = http_build_query($fields);
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        return new Request(
            'POST ' . $path . " HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . $headerLines . "\r\n" . $body
        );
    }

    private function get(string $path): Request
    {
        return new Request('GET ' . $path . " HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    /**
     * Config temp: `app_subdomain_of()` membaca `deploy.app_domain` dan registry
     * server (SQLite temp) — `database/` nyata tidak disentuh.
     */
    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents($this->tmp . '/config/deploy.php', '<?php return ' . var_export([
            'sqlite_file' => $this->tmp . '/rames.sqlite',
            'sqlite_dir' => $this->tmp,
            'apps_path' => $this->tmp . '/apps',
            'parked_root' => $this->tmp . '/parked',
            'app_domain' => 'example.org',
            'docker_socket' => $this->tmp . '/tidak-ada.sock',
        ], true) . ';' . PHP_EOL);
        Config::load($this->tmp . '/config');
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
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
 * Subclass seam: user login & flash disuntik tanpa session HTTP, view ditangkap
 * (bukan dirender), dan `manager()` memakai store/config berpath temp.
 */
class FakeDomainController extends DomainController
{
    public ?array $user = null;

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    /** @var array{template:string,vars:array<string,mixed>}|null */
    public ?array $rendered = null;

    public ?UploadFile $upload = null;

    public int $managerCalls = 0;

    public function __construct(
        private readonly string $tmp,
        private readonly ParkedDomainStore $parked,
        private readonly AppStore $apps,
        private readonly NginxReloader $reloader,
        ?SslQueue $queue = null,
    ) {
        parent::__construct($queue);
    }

    protected function appStore(): AppStore
    {
        return $this->apps;
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function flash(string $type, string $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => $message];
    }

    /**
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered');
    }

    protected function manager(): ParkedDomainManager
    {
        $this->managerCalls++;

        return new ParkedDomainManager(
            $this->parked,
            $this->apps,
            $this->reloader,
            new NginxConfigGenerator($this->tmp . '/nginx/conf', $this->tmp . '/nginx/enabled'),
            $this->content()
        );
    }

    protected function content(): ParkedDomainContent
    {
        return new ParkedDomainContent($this->tmp . '/parked');
    }

    protected function store(): ParkedDomainStore
    {
        return $this->parked;
    }

    protected function uploadedHtmlFile(Request $request): ?UploadFile
    {
        return $this->upload;
    }
}

/**
 * Reloader tiruan: selalu sukses, tanpa menjalankan helper container.
 */
class FakeParkedReloader extends NginxReloader
{
    public function __construct()
    {
    }

    public function reload(): array
    {
        return ['ok' => true, 'message' => 'Reload tiruan berhasil.', 'error' => null, 'output' => ''];
    }
}
