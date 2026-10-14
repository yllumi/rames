<?php
declare(strict_types=1);

namespace Tests;

use app\controller\ServerController;
use app\library\Agent\AgentClient;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Storage\SqliteDatabase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use support\Request;
use Webman\Config;
use Webman\Http\Response;

/**
 * Test mediator {@see ServerController} (registry server multi-server fase F0).
 *
 * Tanpa HTTP nyata, tanpa Docker, tanpa jaringan, dan **tanpa menyentuh**
 * `database/rames.sqlite` / `apps/` nyata: store, direktori pin TLS, dan config
 * diarahkan ke direktori temp unik. Panggilan ke agent memakai handler Guzzle
 * tiruan ({@see ServerAgentHandler}), user login & flash disuntik lewat seam
 * (session Webman butuh konteks HTTP).
 *
 * Yang dibuktikan:
 *  (i)  non-admin ⇒ **404** di SETIAP route (tanpa efek samping), admin ⇒ selesai normal;
 *  (ii) create valid ⇒ tersimpan + `tls_fingerprint` konsisten dengan PEM;
 *  (iii) payload cacat (http, token kosong, PEM sampah, nama invalid, port tumpang
 *        tindih, nama duplikat) ⇒ 422 (JSON) / form dengan `errors` + input lama,
 *        dan **tidak** tersimpan;
 *  (iv) update `local` tidak bisa mengubah `kind`/`base_url`/`token`/PEM/port range;
 *  (v)  `delete local` ditolak; delete server agent membersihkan pin TLS-nya;
 *  (vi) test connection sukses ⇒ `status=online` + `agent_version`/`capabilities` tersimpan;
 *  (vii) test connection gagal (`unreachable`/`tls_failed`/`unauthorized`) ⇒ `code=1`,
 *        `status=offline`, pesan tanpa token;
 *  (viii) `GET /api/servers` tidak memuat `token`/`token_ref`/`tls_cert_pem`.
 */
class ServerControllerTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];
    private const TOKEN = 'agt_uji_4f8a2c9d1e';

    private static ?string $pem = null;

    private string $tmp;
    private string $db;
    private string $tlsDir;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/rames-serverctrl-' . getmypid() . '-' . bin2hex(random_bytes(5));
        $this->db = $this->tmp . '/rames.sqlite';
        $this->tlsDir = $this->tmp . '/agent-tls';
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // (i) admin-only ⇒ 404 non-admin, admin lolos
    // ------------------------------------------------------------------

    public function testMemberGets404OnEveryRouteWithoutSideEffects(): void
    {
        $store = new ServerStore($this->db);
        $agent = $store->create(['name' => 'vps-1', 'base_url' => 'https://10.0.0.12:8443']);

        $member = $this->controller(self::MEMBER);
        $before = $store->all();
        $agentId = (string) $agent['id'];

        $requests = [
            '/servers' => fn () => $member->index($this->get('/servers')),
            '/servers/create' => fn () => $member->createForm($this->get('/servers/create')),
            '/servers (POST)' => fn () => $member->store($this->post('/servers', $this->payload())),
            '/servers/{id}' => fn () => $member->editForm($this->get('/servers/' . $agentId), $agentId),
            '/servers/{id} (POST)' => fn () => $member->update($this->post('/servers/' . $agentId, $this->payload(['name' => 'dibajak'])), $agentId),
            '/servers/{id}/delete' => fn () => $member->delete($this->post('/servers/' . $agentId . '/delete', []), $agentId),
            '/servers/{id}/test' => fn () => $member->testConnection($this->postJson('/servers/' . $agentId . '/test', []), $agentId),
            '/api/servers' => fn () => $member->apiIndex($this->get('/api/servers', true)),
        ];

        $allowed = [];
        foreach ($requests as $label => $call) {
            $status = $call()->getStatusCode();
            if ($status !== 404) {
                $allowed[] = sprintf('%s ⇒ %d', $label, $status);
            }
        }
        self::assertSame([], $allowed, 'Route berikut tidak menolak member dengan 404: ' . implode(', ', $allowed));

        // Tidak ada efek samping: registry utuh, tanpa flash, tanpa panggilan agent.
        self::assertSame($before, $store->all(), 'Member tidak boleh mengubah registry server');
        self::assertSame([], $member->flashes, 'Member tidak boleh memicu flash apa pun');
    }

    public function testJsonApiDenialIs404Envelope(): void
    {
        $member = $this->controller(self::MEMBER);

        $response = $member->apiIndex($this->get('/api/servers', true));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Not Found', $response->rawBody());
    }

    public function testAdminReachesEveryRoute(): void
    {
        $controller = $this->admin();
        $store = $this->store();

        // Halaman
        $response = $controller->index($this->get('/servers'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('server/index', $controller->rendered['template'] ?? null);
        self::assertSame(['servers', 'localId', 'active'], array_keys($controller->rendered['vars'] ?? []));

        $response = $controller->createForm($this->get('/servers/create'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('server/form', $controller->rendered['template'] ?? null);
        self::assertNull($controller->rendered['vars']['server'] ?? null);
        self::assertSame([], $controller->rendered['vars']['errors'] ?? null);
        self::assertFalse($controller->rendered['vars']['isEdit'] ?? null);

        // Create
        $response = $controller->store($this->post('/servers', $this->payload()));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/servers', $response->getHeader('Location'));
        $created = $store->findByName('vps-jakarta-2');
        self::assertNotNull($created);

        // Edit + update
        $id = (string) $created['id'];
        $response = $controller->editForm($this->get('/servers/' . $id), $id);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($controller->rendered['vars']['isEdit'] ?? null);
        self::assertSame($id, $controller->rendered['vars']['server']['id'] ?? null);

        $response = $controller->update($this->post('/servers/' . $id, $this->payload(['notes' => 'catatan baru'])), $id);
        self::assertSame(302, $response->getStatusCode());

        // Test connection (handler tiruan: /healthz + /version)
        $controller->agentHandler()->reply(200, '{"ok":true,"service":"rames-agent","version":"0.1.0"}');
        $controller->agentHandler()->reply(200, '{"agent_version":"0.1.0","capabilities":["deploy"],"min_control_plane_version":"0.1.0"}');
        $response = $controller->testConnection($this->postJson('/servers/' . $id . '/test', []), $id);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->json($response)['code'] ?? null);

        // JSON list & delete
        self::assertSame(200, $controller->apiIndex($this->get('/api/servers', true))->getStatusCode());
        self::assertSame(302, $controller->delete($this->post('/servers/' . $id . '/delete', []), $id)->getStatusCode());
    }

    // ------------------------------------------------------------------
    // (ii) create tersimpan + fingerprint konsisten
    // ------------------------------------------------------------------

    public function testCreateStoresServerWithFingerprintFromPem(): void
    {
        $controller = $this->admin();

        $response = $controller->store($this->post('/servers', $this->payload()));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/servers', $response->getHeader('Location'));
        self::assertNotEmpty($controller->flashes);
        self::assertSame('success', $controller->flashes[0]['type']);

        $server = $this->store()->findByName('vps-jakarta-2');
        self::assertNotNull($server);
        self::assertSame('agent', $server['kind']);
        self::assertTrue($server['enabled']);
        self::assertSame('unknown', $server['status']);
        self::assertSame('https://10.0.0.12:8443', $server['base_url']);
        self::assertSame('domain2.com', $server['domain']);
        self::assertSame('catatan uji', $server['notes']);
        self::assertSame(['start' => 31000, 'end' => 31999], $server['port_range']);
        self::assertSame(self::TOKEN, $server['token']);
        self::assertSame(trim(self::pem()), $server['tls_cert_pem']);
        self::assertNotEmpty($server['id']);
        self::assertNotEmpty($server['created_at']);
        self::assertNotEmpty($server['updated_at']);

        // Fingerprint dihitung dari PEM (bukan diketik user) & konsisten.
        self::assertSame(ServerResolver::fingerprintFromPem(self::pem()), $server['tls_fingerprint']);
        $cert = openssl_x509_read(self::pem());
        self::assertNotFalse($cert);
        $expected = strtolower(str_replace(':', '', (string) openssl_x509_fingerprint($cert, 'sha256')));
        self::assertSame($expected, $server['tls_fingerprint']);
    }

    public function testCreateRejectsLocalKind(): void
    {
        $controller = $this->admin();

        $response = $controller->store($this->postJson('/servers', $this->payload(['kind' => 'local'])));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, $this->json($response)['code'] ?? null);
        self::assertCount(1, $this->store()->all(), 'Hanya baris seed lokal yang boleh ada');
    }

    // ------------------------------------------------------------------
    // (iii) payload cacat ⇒ 422 / errors + input lama, tidak tersimpan
    // ------------------------------------------------------------------

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'base_url http' => [['base_url' => 'http://10.0.0.12:8443']],
            'base_url kosong' => [['base_url' => '']],
            'token kosong' => [['token' => '']],
            'PEM sampah' => [['tls_cert_pem' => 'bukan sertifikat']],
            'PEM kosong' => [['tls_cert_pem' => '']],
            'nama invalid' => [['name' => 'VPS Jakarta!']],
            'nama kosong' => [['name' => '']],
            'domain dengan skema' => [['domain' => 'https://domain2.com']],
            'port tumpang tindih lokal' => [['port_range' => '30000-30999']],
            'port terbalik' => [['port_range' => '31999-31000']],
            'enabled tak dikenal' => [['enabled' => 'mungkin']],
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @dataProvider invalidPayloadProvider
     */
    public function testCreateInvalidPayloadIsRejectedAndNotStored(array $overrides): void
    {
        $controller = $this->admin();
        $fields = $this->payload($overrides);

        // (a) jalur AJAX ⇒ 422 + envelope {code:1,error}
        $response = $controller->store($this->postJson('/servers', $fields));
        self::assertSame(422, $response->getStatusCode());
        $json = $this->json($response);
        self::assertSame(1, $json['code'] ?? null);
        self::assertNotSame('', trim((string) ($json['error'] ?? '')));

        // (b) jalur HTML ⇒ form dirender ulang dengan errors + input lama
        $response = $controller->store($this->post('/servers', $fields));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('server/form', $controller->rendered['template'] ?? null);
        self::assertFalse($controller->rendered['vars']['isEdit'] ?? null);
        self::assertNotEmpty($controller->rendered['vars']['errors'] ?? []);
        self::assertSame($fields['base_url'], $controller->rendered['vars']['server']['base_url'] ?? null);
        self::assertSame($fields['name'], $controller->rendered['vars']['server']['name'] ?? null);

        // Tidak tersimpan & tidak ada pesan yang membocorkan token.
        self::assertCount(1, $this->store()->all());
        self::assertStringNotContainsString(self::TOKEN, (string) ($json['error'] ?? ''));
        self::assertStringNotContainsString(self::TOKEN, (string) implode(' ', $controller->rendered['vars']['errors'] ?? []));
    }

    public function testDuplicateNameIsRejected(): void
    {
        $controller = $this->admin();

        self::assertSame(302, $controller->store($this->post('/servers', $this->payload()))->getStatusCode());

        $response = $controller->store($this->postJson('/servers', $this->payload([
            'base_url' => 'https://10.0.0.13:8443',
        ])));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('sudah dipakai', (string) ($this->json($response)['error'] ?? ''));
        self::assertCount(2, $this->store()->all(), 'Server duplikat tidak boleh masuk');
    }

    // ------------------------------------------------------------------
    // (iv) update server lokal
    // ------------------------------------------------------------------

    public function testUpdateLocalCannotChangeIdentityOrAgentFields(): void
    {
        $controller = $this->admin();

        $response = $controller->update($this->post('/servers/local', $this->payload([
            'name' => 'local-baru',
            'kind' => 'agent',
            'base_url' => 'https://10.0.0.99:8443',
            'token' => 'agt_palsu',
            'port_range' => '31000-31999',
            'enabled' => '0',
        ])), 'local');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/servers/local', $response->getHeader('Location'));
        $error = (string) ($controller->flashes[0]['message'] ?? '');
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertStringContainsString('base_url', $error);
        self::assertStringContainsString('token', $error);
        self::assertStringNotContainsString('agt_palsu', $error);

        $local = $this->store()->local();
        self::assertSame('local', $local['id']);
        self::assertSame('local', $local['kind']);
        self::assertSame('local', $local['name']);
        self::assertArrayNotHasKey('base_url', $local);
        self::assertArrayNotHasKey('token', $local);
        self::assertArrayNotHasKey('port_range', $local);
        self::assertTrue($local['enabled']);
    }

    public function testUpdateLocalAcceptsDomainAndNotesOnly(): void
    {
        $controller = $this->admin();

        // Field terkunci yang dikirim **dengan nilai yang sama** = bukan perubahan.
        $response = $controller->update($this->post('/servers/local', [
            'name' => 'local',
            'kind' => 'local',
            'domain' => 'Local.Example.com.',
            'notes' => '  server utama  ',
            'enabled' => 'on',
        ]), 'local');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('success', $controller->flashes[0]['type'] ?? null);

        $local = $this->store()->local();
        self::assertSame('local.example.com', $local['domain']);
        self::assertSame('server utama', $local['notes']);
        self::assertSame('local', $local['kind']);
    }

    public function testUpdateLocalRejectsInvalidDomain(): void
    {
        $controller = $this->admin();

        $response = $controller->update($this->post('/servers/local', ['domain' => 'https://x.example.com']), 'local');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertSame('', $this->store()->local()['domain'] ?? null);
    }

    // ------------------------------------------------------------------
    // (iv-b) refactor `ServerResolver::validateLocal()` — kesetaraan perilaku
    // ------------------------------------------------------------------

    public function testUpdateLocalStoresDomainAndNotesAlone(): void
    {
        $controller = $this->admin();

        $response = $controller->update($this->post('/servers/local', [
            'domain' => 'utama.example.com',
            'notes' => 'dashboard produksi',
        ]), 'local');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/servers/local', $response->getHeader('Location'));
        self::assertSame('success', $controller->flashes[0]['type'] ?? null);

        $local = $this->store()->local();
        self::assertSame('utama.example.com', $local['domain']);
        self::assertSame('dashboard produksi', $local['notes']);
        self::assertSame('local', $local['kind']);
        self::assertSame('local', $local['name']);
        self::assertTrue($local['enabled']);
        self::assertArrayNotHasKey('base_url', $local);
        self::assertArrayNotHasKey('token', $local);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function lockedLocalFieldProvider(): array
    {
        return [
            'name' => ['name', 'local-baru'],
            'kind' => ['kind', 'agent'],
            'base_url' => ['base_url', 'https://10.0.0.99:8443'],
            'token' => ['token', 'agt_palsu_rahasia_2f'],
            'tls_cert_pem' => ['tls_cert_pem', '-----BEGIN CERTIFICATE-----SANGAT-RAHASIA-----END CERTIFICATE-----'],
            'enabled' => ['enabled', '0'],
            'port_range' => ['port_range', '31000-31999'],
        ];
    }

    /** @dataProvider lockedLocalFieldProvider */
    public function testUpdateLocalRejectsChangedLockedField(string $field, string $value): void
    {
        $controller = $this->admin();

        $response = $controller->update($this->post('/servers/local', [$field => $value]), 'local');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/servers/local', $response->getHeader('Location'));
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        $error = (string) ($controller->flashes[0]['message'] ?? '');
        self::assertStringContainsString('field ' . $field, $error);
        self::assertStringNotContainsString('SANGAT-RAHASIA', $error);

        $local = $this->store()->local();
        self::assertSame('local', $local['kind']);
        self::assertSame('local', $local['name']);
        self::assertTrue($local['enabled']);
        self::assertArrayNotHasKey('base_url', $local);
        self::assertArrayNotHasKey('token', $local);
        self::assertArrayNotHasKey('port_range', $local);
    }

    public function testUpdateLocalEquivalentValuesAreNoOps(): void
    {
        $controller = $this->admin();

        // Bentuk yang benar-benar dikirim form untuk baris lokal: field terkunci
        // berisi nilai tersimpan (atau kosong karena memang kosong).
        $response = $controller->update($this->post('/servers/local', [
            'name' => 'local',
            'kind' => 'local',
            'base_url' => '',
            'token' => '',
            'tls_cert_pem' => '',
            'enabled' => 'on',
            'port_range' => '',
            'port_range_start' => '',
            'port_range_end' => '',
            'domain' => 'no-op.example.com',
            'notes' => 'tetap',
        ]), 'local');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('success', $controller->flashes[0]['type'] ?? null);
        self::assertSame('no-op.example.com', $this->store()->local()['domain'] ?? null);

        // Rentang port tersimpan yang dikirim ulang dengan bentuk setara (teks).
        $this->store()->update('local', static function (array &$row): void {
            $row['port_range'] = ['start' => 30000, 'end' => 30999];
        });
        $controller->update($this->post('/servers/local', [
            'port_range' => '30000-30999',
            'domain' => 'no-op-2.example.com',
        ]), 'local');

        self::assertSame('success', $controller->flashes[1]['type'] ?? null);
        self::assertSame('no-op-2.example.com', $this->store()->local()['domain'] ?? null);
    }

    public function testUpdateLocalNeverEchoesSubmittedToken(): void
    {
        $controller = $this->admin();
        $token = 'agt_rahasia_lokal_q7x1';

        $response = $controller->update($this->post('/servers/local', ['token' => $token, 'domain' => 'x.test']), 'local');
        self::assertSame(302, $response->getStatusCode());
        $error = (string) ($controller->flashes[0]['message'] ?? '');
        self::assertStringContainsString('field token', $error);
        self::assertStringNotContainsString($token, $error);
        self::assertStringNotContainsString($token, $response->rawBody());

        $json = $controller->update($this->postJson('/servers/local', ['token' => $token]), 'local');
        self::assertSame(422, $json->getStatusCode());
        self::assertStringNotContainsString($token, $json->rawBody());
        self::assertSame(1, $this->json($json)['code'] ?? null);

        // Token yang dicoba "dipasang" ke baris lokal tidak pernah tersimpan.
        self::assertArrayNotHasKey('token', $this->store()->local());
    }

    public function testUpdateAgentKeepsSecretWhenBlankAndResetsStaleState(): void
    {
        $controller = $this->admin();
        $controller->store($this->post('/servers', $this->payload()));
        $server = $this->store()->findByName('vps-jakarta-2');
        self::assertNotNull($server);
        $id = (string) $server['id'];

        // Sebelumnya online (test connection sukses).
        $controller->agentHandler()->reply(200, '{"ok":true,"service":"rames-agent","version":"0.1.0"}');
        $controller->agentHandler()->reply(200, '{"agent_version":"0.1.0","capabilities":["deploy"]}');
        $controller->testConnection($this->postJson('/servers/' . $id . '/test', []), $id);
        self::assertSame('online', $this->store()->find($id)['status'] ?? null);

        // Update tanpa mengisi ulang token/PEM (form edit tidak menampilkan rahasia).
        $response = $controller->update($this->post('/servers/' . $id, [
            'name' => 'vps-jakarta-2',
            'base_url' => 'https://10.0.0.14:8443',
            'token' => '',
            'tls_cert_pem' => '',
            'domain' => 'domain2.com',
        ]), $id);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('success', $controller->flashes[0]['type'] ?? null);

        $updated = $this->store()->find($id);
        self::assertNotNull($updated);
        self::assertSame('https://10.0.0.14:8443', $updated['base_url']);
        self::assertSame(self::TOKEN, $updated['token'], 'Token kosong = tidak diubah');
        self::assertSame(trim(self::pem()), $updated['tls_cert_pem']);
        self::assertSame('agent', $updated['kind']);
        // Endpoint berubah ⇒ state koneksi lama tidak berlaku lagi.
        self::assertSame('unknown', $updated['status']);
        self::assertArrayNotHasKey('agent_version', $updated);
        self::assertArrayNotHasKey('last_seen_at', $updated);
    }

    public function testUpdateAgentRejectsInvalidPayloadWithRedirect(): void
    {
        $controller = $this->admin();
        $controller->store($this->post('/servers', $this->payload()));
        $server = $this->store()->findByName('vps-jakarta-2');
        self::assertNotNull($server);
        $id = (string) $server['id'];

        $response = $controller->update($this->post('/servers/' . $id, [
            'name' => 'vps-jakarta-2',
            'base_url' => 'http://10.0.0.12:8443',
        ]), $id);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('server/form', $controller->rendered['template'] ?? null);
        self::assertTrue($controller->rendered['vars']['isEdit'] ?? null);
        self::assertNotEmpty($controller->rendered['vars']['errors'] ?? []);
        self::assertSame('https://10.0.0.12:8443', $this->store()->find($id)['base_url'] ?? null);
        // Form edit yang dirender ulang tetap tahu token sudah terpasang.
        self::assertTrue($controller->rendered['vars']['server']['has_token'] ?? null);
        self::assertSame('', $controller->rendered['vars']['server']['token'] ?? null);

        $json = $controller->update($this->postJson('/servers/' . $id, ['name' => 'vps-jakarta-2']), $id);
        self::assertSame(422, $json->getStatusCode());
        self::assertSame(1, $this->json($json)['code'] ?? null);
    }

    // ------------------------------------------------------------------
    // (v) delete
    // ------------------------------------------------------------------

    public function testDeleteLocalIsRejected(): void
    {
        $controller = $this->admin();

        $response = $controller->delete($this->post('/servers/local/delete', []), 'local');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/servers', $response->getHeader('Location'));
        self::assertSame('error', $controller->flashes[0]['type'] ?? null);
        self::assertStringContainsString('lokal', (string) ($controller->flashes[0]['message'] ?? ''));
        self::assertNotNull($this->store()->local());
        self::assertCount(1, $this->store()->all());
    }

    public function testDeleteAgentPrunesOnlyItsOwnPinFile(): void
    {
        $controller = $this->admin();
        $controller->store($this->post('/servers', $this->payload()));
        $controller->store($this->post('/servers', $this->payload([
            'name' => 'vps-2',
            'base_url' => 'https://10.0.0.13:8443',
        ])));

        $store = $this->store();
        $pins = new TlsPinMaterializer($this->tlsDir);
        $first = $store->findByName('vps-jakarta-2');
        $second = $store->findByName('vps-2');
        self::assertNotNull($first);
        self::assertNotNull($second);

        $firstPin = $pins->ensure($first);
        $secondPin = $pins->ensure($second);
        self::assertFileExists($firstPin);
        self::assertFileExists($secondPin);

        $response = $controller->delete($this->post('/servers/' . $first['id'] . '/delete', []), (string) $first['id']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('success', $controller->flashes[0]['type'] ?? null);
        self::assertNull($store->find((string) $first['id']));
        self::assertFileDoesNotExist($firstPin, 'Pin TLS server yang dihapus harus dibersihkan');
        self::assertFileExists($secondPin, 'Pin TLS server lain tidak boleh ikut terhapus');
    }

    public function testDeleteUnknownServerIs404(): void
    {
        $controller = $this->admin();

        self::assertSame(404, $controller->delete($this->post('/servers/tidak-ada/delete', []), 'tidak-ada')->getStatusCode());
        self::assertSame(404, $controller->editForm($this->get('/servers/tidak-ada'), 'tidak-ada')->getStatusCode());
    }

    // ------------------------------------------------------------------
    // (vi) test connection sukses
    // ------------------------------------------------------------------

    public function testConnectionSuccessStoresOnlineState(): void
    {
        $controller = $this->admin();
        $controller->store($this->post('/servers', $this->payload()));
        $server = $this->store()->findByName('vps-jakarta-2');
        self::assertNotNull($server);
        $id = (string) $server['id'];

        $handler = $controller->agentHandler();
        $handler->reply(200, '{"ok":true,"service":"rames-agent","mode":"agent","version":"0.1.0"}');
        $handler->reply(200, '{"agent_version":"0.1.0","capabilities":["deploy","nginx"],"min_control_plane_version":"0.1.0"}');

        $response = $controller->testConnection($this->postJson('/servers/' . $id . '/test', []), $id);

        self::assertSame(200, $response->getStatusCode());
        $json = $this->json($response);
        self::assertSame(0, $json['code'] ?? null);
        self::assertSame($id, $json['server']['id'] ?? null);
        self::assertSame('online', $json['server']['status'] ?? null);
        self::assertSame('0.1.0', $json['server']['agent_version'] ?? null);
        self::assertSame(['deploy', 'nginx'], $json['server']['capabilities'] ?? null);
        self::assertNotSame('', (string) ($json['server']['last_seen_at'] ?? ''));

        $stored = $this->store()->find($id);
        self::assertNotNull($stored);
        self::assertSame('online', $stored['status']);
        self::assertSame('0.1.0', $stored['agent_version']);
        self::assertSame(['deploy', 'nginx'], $stored['capabilities']);
        self::assertNotSame('', (string) ($stored['last_seen_at'] ?? ''));

        // Dua panggilan sesuai kontrak agent: /healthz lalu /version.
        self::assertSame(['/healthz', '/version'], $handler->paths());
        // Tidak ada rahasia di respons.
        self::assertStringNotContainsString(self::TOKEN, $response->rawBody());
    }

    public function testConnectionToLocalServerSkipsNetwork(): void
    {
        $controller = $this->admin();
        $handler = $controller->agentHandler();

        $response = $controller->testConnection($this->postJson('/servers/local/test', []), 'local');

        self::assertSame(200, $response->getStatusCode());
        $json = $this->json($response);
        self::assertSame(0, $json['code'] ?? null);
        self::assertSame('online', $json['server']['status'] ?? null);
        self::assertSame(0, $handler->count(), 'Server lokal tidak boleh diuji lewat jaringan');
        self::assertSame('online', $this->store()->local()['status'] ?? null);
    }

    // ------------------------------------------------------------------
    // (vii) test connection gagal
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:callable,1:string,2:string}>
     */
    public static function failureProvider(): array
    {
        return [
            'unreachable' => [
                static fn (ServerAgentHandler $h): ServerAgentHandler => $h->fail(new ConnectException(
                    'cURL error 7: Failed to connect to agent.test port 8443',
                    new GuzzleRequest('GET', 'https://agent.test:8443/healthz')
                )),
                'unreachable',
                'tidak bisa dihubungi',
            ],
            'tls_failed' => [
                static fn (ServerAgentHandler $h): ServerAgentHandler => $h->fail(new ConnectException(
                    'cURL error 60: SSL certificate problem: self-signed certificate',
                    new GuzzleRequest('GET', 'https://agent.test:8443/healthz')
                )),
                'tls_failed',
                'sertifikat',
            ],
            'unauthorized' => [
                static fn (ServerAgentHandler $h): ServerAgentHandler => $h->reply(401, '{"error":"token ditolak"}'),
                'unauthorized',
                'Token agent ditolak',
            ],
        ];
    }

    /**
     * @param callable(ServerAgentHandler):ServerAgentHandler $prepare
     * @dataProvider failureProvider
     */
    public function testConnectionFailureMarksOfflineAndHidesSecret(callable $prepare, string $reason, string $hint): void
    {
        $controller = $this->admin();
        $controller->store($this->post('/servers', $this->payload()));
        $server = $this->store()->findByName('vps-jakarta-2');
        self::assertNotNull($server);
        $id = (string) $server['id'];

        $prepare($controller->agentHandler());

        $response = $controller->testConnection($this->postJson('/servers/' . $id . '/test', []), $id);

        self::assertSame(200, $response->getStatusCode());
        $json = $this->json($response);
        self::assertSame(1, $json['code'] ?? null);
        self::assertSame($reason, $json['reason'] ?? null);
        self::assertStringContainsString($hint, (string) ($json['error'] ?? ''));
        self::assertStringNotContainsString(self::TOKEN, (string) ($json['error'] ?? ''));
        self::assertStringNotContainsString(self::TOKEN, $response->rawBody());
        self::assertStringNotContainsString('Bearer', (string) ($json['error'] ?? ''));

        $stored = $this->store()->find($id);
        self::assertNotNull($stored);
        self::assertSame('offline', $stored['status']);
        self::assertArrayNotHasKey('last_seen_at', $stored);
    }

    public function testConnectionUnauthorizedIsMarkedOfflineWhenPreviouslyOnline(): void
    {
        $controller = $this->admin();
        $controller->store($this->post('/servers', $this->payload()));
        $server = $this->store()->findByName('vps-jakarta-2');
        self::assertNotNull($server);
        $id = (string) $server['id'];

        $handler = $controller->agentHandler();
        $handler->reply(200, '{"ok":true,"service":"rames-agent"}');
        $handler->reply(200, '{"agent_version":"0.1.0","capabilities":[]}');
        $controller->testConnection($this->postJson('/servers/' . $id . '/test', []), $id);
        self::assertSame('online', $this->store()->find($id)['status'] ?? null);

        $handler->reply(403, '{"error":"token tidak dikenal"}');
        $controller->testConnection($this->postJson('/servers/' . $id . '/test', []), $id);

        $stored = $this->store()->find($id);
        self::assertNotNull($stored);
        self::assertSame('offline', $stored['status']);
        self::assertSame('0.1.0', $stored['agent_version'], 'Versi terakhir yang diketahui dibiarkan');
    }

    // ------------------------------------------------------------------
    // (viii) GET /api/servers tanpa rahasia
    // ------------------------------------------------------------------

    public function testApiIndexOmitsSecrets(): void
    {
        $controller = $this->admin();
        $controller->store($this->post('/servers', $this->payload()));

        $response = $controller->apiIndex($this->get('/api/servers', true));
        $json = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $json['code'] ?? null);
        self::assertCount(2, $json['data'] ?? [], 'Server lokal + server agent');

        foreach ($json['data'] as $row) {
            self::assertArrayNotHasKey('token', $row);
            self::assertArrayNotHasKey('token_ref', $row);
            self::assertArrayNotHasKey('tls_cert_pem', $row);
            self::assertArrayHasKey('has_token', $row);
        }

        $agentRow = $json['data'][1];
        self::assertTrue($agentRow['has_token']);
        self::assertSame('vps-jakarta-2', $agentRow['name']);
        self::assertSame(ServerResolver::fingerprintFromPem(self::pem()), $agentRow['tls_fingerprint']);
        self::assertStringNotContainsString(self::TOKEN, $response->rawBody());
        self::assertStringNotContainsString('BEGIN CERTIFICATE', $response->rawBody());
    }

    public function testIndexAndFormPayloadsHideStoredToken(): void
    {
        $controller = $this->admin();
        $controller->store($this->post('/servers', $this->payload()));
        $server = $this->store()->findByName('vps-jakarta-2');
        self::assertNotNull($server);

        $controller->index($this->get('/servers'));
        $vars = $controller->rendered['vars'] ?? [];
        self::assertSame(ServerStore::LOCAL_ID, $vars['localId'] ?? null);
        self::assertSame('servers', $vars['active'] ?? null);
        foreach ($vars['servers'] as $row) {
            self::assertArrayNotHasKey('token', $row);
            self::assertArrayNotHasKey('tls_cert_pem', $row);
        }

        $controller->editForm($this->get('/servers/' . $server['id']), (string) $server['id']);
        $form = $controller->rendered['vars']['server'] ?? [];
        self::assertSame('', $form['token'] ?? null, 'Token tersimpan tidak boleh dirender ke form');
        self::assertTrue($form['has_token'] ?? null);
        self::assertSame(trim(self::pem()), $form['tls_cert_pem'] ?? null);
        self::assertArrayNotHasKey('token_ref', $form);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function admin(): FakeServerController
    {
        return $this->controller(self::ADMIN);
    }

    private function controller(?array $user): FakeServerController
    {
        $controller = new FakeServerController($this->store(), $this->tlsDir);
        $controller->user = $user;

        return $controller;
    }

    private function store(): ServerStore
    {
        return new ServerStore($this->db);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,string>
     */
    private function payload(array $overrides = []): array
    {
        return array_map(
            static fn ($value): string => (string) $value,
            array_merge([
                'name' => 'vps-jakarta-2',
                'base_url' => 'https://10.0.0.12:8443',
                'token' => self::TOKEN,
                'tls_cert_pem' => self::pem(),
                'domain' => 'domain2.com',
                'notes' => 'catatan uji',
                'enabled' => '1',
                'port_range' => '31000-31999',
            ], $overrides)
        );
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->rawBody(), true);
        self::assertIsArray($decoded, 'Respons harus JSON: ' . $response->rawBody());

        return $decoded;
    }

    /** @param array<string,string> $fields */
    private function post(string $path, array $fields): Request
    {
        return $this->request('POST', $path, http_build_query($fields), false);
    }

    /** @param array<string,string> $fields */
    private function postJson(string $path, array $fields): Request
    {
        return $this->request('POST', $path, http_build_query($fields), true);
    }

    private function get(string $path, bool $json = false): Request
    {
        return $this->request('GET', $path, '', $json);
    }

    private function request(string $method, string $path, string $body = '', bool $json = false): Request
    {
        $headers = "Host: localhost\r\n" . ($json ? "Accept: application/json\r\n" : '');
        if ($body !== '') {
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n";
        }

        return new Request("{$method} {$path} HTTP/1.1\r\n{$headers}\r\n{$body}");
    }

    private static function pem(): string
    {
        if (self::$pem !== null) {
            return self::$pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        self::assertTrue(openssl_x509_export($cert, $pem));

        return self::$pem = (string) $pem;
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
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

    /** Config temp: rentang port lokal 30000–30999 (agar overlap bisa diuji). */
    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->db,
                'database_path' => $this->tmp,
                'port_range' => ['start' => 30000, 'end' => 30999],
                'app_domain' => 'localhost',
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
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
 * Subclass seam: user login, flash, view, dan klien agent disuntik tanpa session
 * atau jaringan HTTP nyata.
 */
class FakeServerController extends ServerController
{
    public ?array $user = null;

    /** @var array<int,array{type:string,message:string}> */
    public array $flashes = [];

    /** @var array{template:string,vars:array}|null */
    public ?array $rendered = null;

    private ServerAgentHandler $handler;

    public function __construct(ServerStore $servers, private readonly string $tlsDir)
    {
        parent::__construct($servers);
        $this->handler = new ServerAgentHandler();
    }

    public function agentHandler(): ServerAgentHandler
    {
        return $this->handler;
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
     * Tangkap payload view (bukan dirender) karena `view()` butuh konteks HTTP.
     *
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): Response
    {
        $this->rendered = ['template' => $template, 'vars' => $vars];

        return new Response(200, [], 'rendered');
    }

    /** Klien agent dengan handler tiruan + cert di direktori temp. */
    protected function agentClient(array $server): AgentClient
    {
        return new AgentClient($server, $this->handler, 5, $this->pinMaterializer());
    }

    protected function pinMaterializer(): TlsPinMaterializer
    {
        return new TlsPinMaterializer($this->tlsDir);
    }
}

/**
 * Handler Guzzle tiruan: merekam path yang dipanggil dan mengantrikan
 * respons/exception — cukup untuk `/healthz` + `/version`.
 */
class ServerAgentHandler
{
    /** @var array<int,string> */
    public array $calls = [];

    /** @var list<GuzzleResponse|\Throwable> */
    private array $queue = [];

    public function reply(int $status, string $body = ''): self
    {
        $this->queue[] = new GuzzleResponse($status, ['Content-Type' => 'application/json'], $body);

        return $this;
    }

    public function fail(\Throwable $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /** @return array<int,string> */
    public function paths(): array
    {
        return $this->calls;
    }

    public function __invoke(object $request, array $options): mixed
    {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = $request->getUri()->getPath();

        if ($this->queue === []) {
            throw new \RuntimeException('ServerAgentHandler: tidak ada respons tersisa');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}
