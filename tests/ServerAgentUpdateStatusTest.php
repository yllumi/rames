<?php
declare(strict_types=1);

namespace Tests;

use app\controller\ServerController;
use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployWorkerLauncher;
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
 * Endpoint polling status job **update agen** (`GET /servers/{id}/agent-update/status`)
 * di sisi control plane.
 *
 * Latar: `POST /servers/{id}/agent-update` hanya mengembalikan `job_id`; job
 * `self_update` berjalan di agen, sehingga kegagalan preflight (mis. repo dengan
 * perubahan belum di-commit) sebelumnya hanya terlihat di `agent.log` server itu.
 *
 * Kontrak yang dikunci:
 *  (i)   non-admin ⇒ **404** tanpa panggilan agen; server tak dikenal ⇒ 404;
 *  (ii)  `job` kosong/tidak sah ⇒ 400 **sebelum** jaringan (validator id satu
 *        sumber dengan {@see AgentDeployer::serverJobProgress()});
 *  (iii) server lokal ⇒ 400 dengan pesan jelas, tanpa HTTP;
 *  (iv)  status `running` ⇒ `error:""`, `log`/`offset` diteruskan apa adanya
 *        (hanya baris lengkap; offset maju tepat sepanjang teks itu);
 *  (v)   status `error` ⇒ `error` terisi pesan job yang **tersanitasi**
 *        (tanpa token/host agen), `log` tetap diteruskan; `stage`/`message`
 *        (teks bebas agen) juga tersanitasi (token/host/PEM tidak bocor);
 *  (vi)  galat agent **transien** (`unreachable`, `server_error`, `not_found`)
 *        ⇒ **200** `status="unreachable"` + `data.reason` (self-update me-recreate
 *        agen ⇒ putus sesaat itu normal; UI tetap polling), pesan/`offset` aman;
 *        galat **deterministik** (`invalid_config`, `unauthorized`, `tls_failed`,
 *        `bad_response`) ⇒ **400** `{code,msg,reason}` tanpa menunggu polling;
 *  (vii) rekaman tanpa `status` ⇒ fallback `pending`.
 *
 * Tanpa jaringan, Docker, atau data runtime: handler Guzzle tiruan, store &
 * direktori pin TLS di `/tmp`.
 */
class ServerAgentUpdateStatusTest extends TestCase
{
    private const ADMIN = ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
    private const MEMBER = ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];
    private const TOKEN = 'agt_uji_status_7d1e';
    private const HOST = 'vps-1.internal.test';

    private static ?string $pem = null;

    private string $tmp;
    private string $db;

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        SqliteDatabase::reset();

        $this->tmp = sys_get_temp_dir() . '/rames-agentstatus-' . bin2hex(random_bytes(5));
        $this->db = $this->tmp . '/rames.sqlite';
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        $this->useConfig();
    }

    protected function tearDown(): void
    {
        SqliteDatabase::reset();
        $this->restoreConfigState($this->configState);
        ServerResolver::forgetMemo();
        self::removeTree($this->tmp);
    }

    // ------------------------------------------------------------------
    // (i) otorisasi & keberadaan server
    // ------------------------------------------------------------------

    public function testMemberGets404WithoutAgentCall(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $controller = $this->controller($handler, self::MEMBER);

        [$status, , $body, $raw] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(404, $status);
        self::assertSame(0, $handler->count(), 'non-admin tidak boleh memicu panggilan ke agen');
        self::assertStringNotContainsString(self::TOKEN, $raw);
        self::assertStringNotContainsString(self::HOST, $raw);
        unset($body);
    }

    public function testUnknownServerIs404(): void
    {
        $handler = new AgentStatusHandler();
        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/ghost/agent-update/status?job=job-upd'),
            'ghost'
        ));

        self::assertSame(404, $status);
        self::assertSame(1, $body['code']);
        self::assertSame(0, $handler->count());
    }

    // ------------------------------------------------------------------
    // (ii) validasi id job — tanpa jaringan
    // ------------------------------------------------------------------

    public function testMissingJobIs400WithoutAgentCall(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status'),
            'vps-1'
        ));

        self::assertSame(400, $status);
        self::assertSame(400, $body['code']);
        self::assertStringContainsString('job', (string) $body['msg']);
        self::assertSame(0, $handler->count(), 'id job tidak sah tidak boleh menyentuh jaringan');
    }

    public function testInvalidJobIdsAre400WithoutAgentCall(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $controller = $this->controller($handler, self::ADMIN);

        // `job[]=…` bukan string skalar ⇒ diperlakukan seperti kosong.
        $queries = [
            'traversal' => ['?job=' . rawurlencode('../../etc/passwd'), 'tidak sah'],
            'spasi' => ['?job=' . rawurlencode('job upd'), 'tidak sah'],
            'terlalu panjang' => ['?job=' . str_repeat('a', 129), 'tidak sah'],
            'array' => ['?job[]=job-upd', 'wajib diisi'],
        ];

        foreach ($queries as $label => [$query, $expected]) {
            [, , $body] = $this->decode($controller->agentUpdateStatus(
                $this->get('/servers/vps-1/agent-update/status' . $query),
                'vps-1'
            ));

            self::assertSame(400, $body['code'], "job {$label} harus ditolak 400");
            self::assertStringContainsString($expected, (string) $body['msg'], "job {$label}");
        }

        self::assertSame(0, $handler->count(), 'validasi id harus fail-fast sebelum efek samping');
    }

    // ------------------------------------------------------------------
    // (iii) server lokal
    // ------------------------------------------------------------------

    public function testLocalServerIs400WithoutAgentCall(): void
    {
        $handler = new AgentStatusHandler();
        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/local/agent-update/status?job=job-upd'),
            ServerStore::LOCAL_ID
        ));

        self::assertSame(400, $status);
        self::assertSame(400, $body['code']);
        self::assertStringContainsString('lokal', (string) $body['msg']);
        self::assertSame(0, $handler->count(), 'server lokal tidak punya job agen');
    }

    // ------------------------------------------------------------------
    // (iv) status running — log/offset diteruskan
    // ------------------------------------------------------------------

    public function testRunningStatusForwardsLogAndOffset(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(200, json_encode([
            'code' => 0,
            'job' => [
                'id' => 'job-upd',
                'op' => DeployWorkerLauncher::AGENT_UPDATE_OP,
                'status' => 'running',
                'stage' => 'preflight',
                'message' => 'memeriksa repo',
                'error' => '',
                'finished_at' => '',
            ],
        ]));
        // Potongan log berakhir tanpa newline: baris terakhir yang belum lengkap
        // TIDAK dikembalikan (dibaca ulang pada polling berikutnya).
        $handler->reply(200, "preflight ok\nstarting\nsisa-baris");

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body, $raw] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd&offset=5'),
            'vps-1'
        ));

        self::assertSame(200, $status, $raw);
        self::assertSame(0, $body['code']);
        self::assertSame('job-upd', $body['data']['job_id']);
        self::assertSame('running', $body['data']['status']);
        self::assertSame('preflight', $body['data']['stage']);
        self::assertSame('memeriksa repo', $body['data']['message']);
        self::assertSame('', $body['data']['error'], 'status running tidak boleh mengisi error');
        self::assertSame('', $body['data']['finished_at']);
        self::assertArrayNotHasKey('reason', $body['data'], 'reason hanya untuk status unreachable');

        $log = (string) $body['data']['log'];
        self::assertSame("preflight ok\nstarting\n", $log);
        self::assertSame(5 + strlen($log), $body['data']['offset']);

        // Satu pembacaan progres per request: status job + potongan log, offset
        // diteruskan ke agen.
        self::assertSame(2, $handler->count());
        self::assertSame('/jobs/job-upd', $handler->call(0)['path']);
        self::assertSame('/jobs/job-upd/log', $handler->call(1)['path']);
        self::assertSame('offset=5', $handler->call(1)['query']);
        self::assertStringNotContainsString(self::TOKEN, $raw);
    }

    public function testNonNumericOffsetIsIgnored(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job' => ['id' => 'job-upd', 'status' => 'done']]));
        $handler->reply(200, "selesai\n");

        $controller = $this->controller($handler, self::ADMIN);

        [, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd&offset=abc'),
            'vps-1'
        ));

        self::assertSame('offset=0', $handler->call(1)['query']);
        self::assertSame(strlen("selesai\n"), $body['data']['offset']);
        self::assertSame('done', $body['data']['status']);
        self::assertSame('', $body['data']['error']);
    }

    public function testNegativeOffsetIsClampedToZero(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job' => ['id' => 'job-upd', 'status' => 'running']]));
        $handler->reply(200, "satu\n");

        $controller = $this->controller($handler, self::ADMIN);

        [, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd&offset=-7'),
            'vps-1'
        ));

        self::assertSame('offset=0', $handler->call(1)['query'], 'offset negatif tidak boleh diteruskan');
        self::assertSame(strlen("satu\n"), $body['data']['offset']);
    }

    // ------------------------------------------------------------------
    // (v) status error — pesan tersanitasi
    // ------------------------------------------------------------------

    public function testErrorStatusFillsSanitizedError(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(200, json_encode([
            'code' => 0,
            'job' => [
                'id' => 'job-upd',
                'status' => 'error',
                'stage' => 'preflight',
                'message' => 'preflight gagal',
                'error' => 'Repo punya perubahan yang belum di-commit (token ' . self::TOKEN
                    . ', host https://' . self::HOST . ':8443).',
                'finished_at' => '2026-10-29T10:00:00+07:00',
            ],
        ]));
        $handler->reply(200, "preflight: repo kotor\nexit 1\n");

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body, $raw] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(200, $status, $raw);
        self::assertSame('error', $body['data']['status']);
        self::assertSame('2026-10-29T10:00:00+07:00', $body['data']['finished_at']);
        self::assertSame("preflight: repo kotor\nexit 1\n", $body['data']['log']);

        $error = (string) $body['data']['error'];
        self::assertStringContainsString('Repo punya perubahan yang belum di-commit', $error);
        self::assertStringContainsString(DeployWorkerLauncher::AGENT_UPDATE_OP, $error);
        self::assertStringNotContainsString(self::TOKEN, $error, 'token agen tidak boleh bocor');
        self::assertStringNotContainsString(self::HOST, $error, 'host agen tidak boleh bocor');

        // Tidak ada pesan bocor di seluruh payload.
        self::assertStringNotContainsString(self::TOKEN, $raw);
        self::assertStringNotContainsString(self::HOST, $raw);
    }

    /**
     * `stage`/`message` adalah teks bebas dari agen — token, host, & blok PEM
     * di dalamnya wajib tersanitasi (bukan hanya field `error`).
     */
    public function testStageAndMessageAreSanitized(): void
    {
        $this->agent('vps-1');

        $pemBlock = "-----BEGIN CERTIFICATE-----\nMIIBdGVzdA==\n-----END CERTIFICATE-----";

        $handler = new AgentStatusHandler();
        $handler->reply(200, json_encode([
            'code' => 0,
            'job' => [
                'id' => 'job-upd',
                'status' => 'running',
                'stage' => 'preflight ' . self::TOKEN,
                'message' => 'kontak https://' . self::HOST . ':8443 ' . $pemBlock,
            ],
        ]));
        $handler->reply(200, '');

        $controller = $this->controller($handler, self::ADMIN);

        [, , $body, $raw] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertStringContainsString('preflight', (string) $body['data']['stage']);
        self::assertStringContainsString('kontak', (string) $body['data']['message']);
        self::assertStringContainsString('[redacted]', $raw, 'rahasia diganti penanda');

        self::assertStringNotContainsString(self::TOKEN, $raw);
        self::assertStringNotContainsString(self::HOST, $raw);
        self::assertStringNotContainsString('BEGIN CERTIFICATE', $raw);
        self::assertStringNotContainsString('MIIBdGVzdA', $raw);
    }

    // ------------------------------------------------------------------
    // (vi) galat transien ⇒ 200 `unreachable` (bukan 4xx)
    // ------------------------------------------------------------------

    public function testTransportErrorAnswers200Unreachable(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->fail(new ConnectException(
            'Connection refused',
            new GuzzleRequest('GET', 'https://' . self::HOST . ':8443/jobs/job-upd')
        ));

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body, $raw] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd&offset=42'),
            'vps-1'
        ));

        // Agent restart saat self-update ⇒ transport putus sesaat bukan "gagal job".
        self::assertSame(200, $status);
        self::assertSame(0, $body['code']);
        self::assertSame('job-upd', $body['data']['job_id']);
        self::assertSame('unreachable', $body['data']['status']);
        self::assertSame('', $body['data']['stage']);
        self::assertSame('', $body['data']['error']);
        self::assertSame('', $body['data']['finished_at']);
        self::assertSame('', $body['data']['log']);
        self::assertSame(42, $body['data']['offset'], 'offset masukan diteruskan apa adanya');
        self::assertSame(AgentError::UNREACHABLE, $body['data']['reason']);
        self::assertStringContainsString('tidak terjangkau', (string) $body['data']['message']);
        self::assertStringContainsString('restart', (string) $body['data']['message']);

        self::assertStringNotContainsString(self::TOKEN, $raw, 'token agen tidak boleh bocor');
        self::assertStringNotContainsString(self::HOST, $raw, 'host agen tidak boleh bocor');
    }

    public function testAgentServerErrorAnswers200Unreachable(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(500, json_encode(['code' => 1, 'error' => 'agen sedang sibuk']));

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(200, $status);
        self::assertSame('unreachable', $body['data']['status']);
        self::assertSame(AgentError::SERVER_ERROR, $body['data']['reason']);
    }

    public function testJobNotFoundOnAgentAnswers200UnreachableWithReason(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(404, json_encode(['code' => 1, 'error' => 'Job tidak ditemukan.']));

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-hilang'),
            'vps-1'
        ));

        // Rekaman job hilang setelah container agen di-recreate ⇒ transien (200),
        // dan `reason` membedakannya dari agen yang benar-benar tak terjangkau.
        self::assertSame(200, $status);
        self::assertSame(0, $body['code']);
        self::assertSame('job-hilang', $body['data']['job_id']);
        self::assertSame('unreachable', $body['data']['status']);
        self::assertSame(AgentError::NOT_FOUND, $body['data']['reason']);
        self::assertArrayNotHasKey('msg', $body);
    }

    // ------------------------------------------------------------------
    // (vi-b) galat deterministik ⇒ 400 + `reason` (tanpa menunggu polling)
    // ------------------------------------------------------------------

    public function testInvalidConfigWithEmptyTokenIs400WithReasonWithoutNetwork(): void
    {
        $this->agent('vps-1', ['token' => '']);

        $handler = new AgentStatusHandler();
        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body, $raw] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(400, $status, 'konfigurasi cacat bukan "agen sedang restart"');
        self::assertSame(400, $body['code']);
        self::assertSame(AgentError::INVALID_CONFIG, $body['reason']);
        self::assertStringContainsString('Token server agent kosong', (string) $body['msg']);
        self::assertSame(0, $handler->count(), 'validasi konfigurasi berjalan sebelum jaringan');
        self::assertStringNotContainsString(self::HOST, $raw);
    }

    public function testInvalidConfigWithMissingPemIs400WithReason(): void
    {
        $this->agent('vps-1', ['tls_cert_pem' => '']);

        $handler = new AgentStatusHandler();
        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(400, $status);
        self::assertSame(AgentError::INVALID_CONFIG, $body['reason']);
        self::assertStringContainsString('tls_cert_pem', (string) $body['msg']);
        self::assertSame(0, $handler->count());
    }

    public function testUnauthorizedIs400WithReason(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(401, json_encode(['code' => 1, 'error' => 'token ditolak']));

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body, $raw] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(400, $status);
        self::assertSame(AgentError::UNAUTHORIZED, $body['reason']);
        self::assertSame(1, $handler->count());
        self::assertStringNotContainsString(self::TOKEN, $raw);
        self::assertStringNotContainsString(self::HOST, $raw);
    }

    public function testTlsFailureIs400WithReason(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->fail(new ConnectException(
            'SSL certificate problem: unable to get local issuer certificate',
            new GuzzleRequest('GET', 'https://' . self::HOST . ':8443/jobs/job-upd')
        ));

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body, $raw] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(400, $status);
        self::assertSame(AgentError::TLS_FAILED, $body['reason']);
        self::assertStringNotContainsString(self::HOST, $raw, 'host agen tidak boleh bocor');
        self::assertStringNotContainsString(self::TOKEN, $raw);
    }

    public function testBadResponseIs400WithReason(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(200, '<html>bukan json</html>');

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(400, $status);
        self::assertSame(AgentError::BAD_RESPONSE, $body['reason']);
        self::assertStringContainsString('bukan JSON', (string) $body['msg']);
    }

    public function testUnsupportedIs400WithReason(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(501, json_encode(['code' => 1, 'error' => 'op tidak didukung']));

        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(400, $status);
        self::assertSame(AgentError::UNSUPPORTED, $body['reason']);
    }

    public function testUnknownServerKindIs400WithoutNetwork(): void
    {
        $this->agent('vps-1');
        // Baris tersimpan dengan `kind` di luar local/agent (data rusak).
        (new ServerStore($this->db))->update('vps-1', static function (array &$row): void {
            $row['kind'] = 'aneh';
        });
        ServerResolver::forgetMemo();

        $handler = new AgentStatusHandler();
        $controller = $this->controller($handler, self::ADMIN);

        [$status, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame(400, $status);
        self::assertSame(400, $body['code']);
        self::assertStringContainsString('kind', (string) $body['msg']);
        self::assertSame(0, $handler->count());
    }

    // ------------------------------------------------------------------
    // (vii) fallback status
    // ------------------------------------------------------------------

    public function testMissingStatusFallsBackToPending(): void
    {
        $this->agent('vps-1');

        $handler = new AgentStatusHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job' => ['id' => 'job-upd']]));
        $handler->reply(200, "menunggu\n");

        $controller = $this->controller($handler, self::ADMIN);

        [, , $body] = $this->decode($controller->agentUpdateStatus(
            $this->get('/servers/vps-1/agent-update/status?job=job-upd'),
            'vps-1'
        ));

        self::assertSame('pending', $body['data']['status']);
        self::assertSame('', $body['data']['error']);
        self::assertSame('', $body['data']['stage']);
        self::assertSame('', $body['data']['message']);
        self::assertSame("menunggu\n", $body['data']['log']);
        self::assertArrayNotHasKey('reason', $body['data'], 'reason hanya untuk status unreachable');
    }

    // ------------------------------------------------------------------
    // Seam & fixture
    // ------------------------------------------------------------------

    private function controller(AgentStatusHandler $handler, array $user): AgentStatusServerController
    {
        $controller = new AgentStatusServerController(new ServerStore($this->db), $handler, $this->tmp);
        $controller->user = $user;

        return $controller;
    }

    /**
     * @param array<string,mixed> $overrides field yang sengaja dibuat cacat
     *                                       (mis. token/PEM kosong)
     * @return array<string,mixed>
     */
    private function agent(string $id, array $overrides = []): array
    {
        $server = (new ServerStore($this->db))->create($overrides + [
            'id' => $id,
            'name' => $id,
            'kind' => 'agent',
            'enabled' => true,
            'domain' => $id . '.example.test',
            'base_url' => 'https://' . self::HOST . ':8443',
            'token' => self::TOKEN,
            'tls_cert_pem' => self::pem(),
            'status' => 'online',
        ]);

        ServerResolver::forgetMemo();

        return $server;
    }

    private function get(string $path): Request
    {
        return new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n");
    }

    /**
     * @return array{0:int,1:array<string,string>,2:array<string,mixed>,3:string}
     */
    private function decode(Response $response): array
    {
        $raw = $response->rawBody();
        $body = $raw === '' ? [] : json_decode($raw, true);

        return [$response->getStatusCode(), $response->getHeaders(), is_array($body) ? $body : [], $raw];
    }

    private function useConfig(): void
    {
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->db,
                'database_path' => $this->tmp,
                'app_domain' => 'localhost',
                'templates_path' => dirname(__DIR__) . '/templates',
                'docker_binary' => 'docker-test',
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');
    }

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @param array<string,mixed> $state
     */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function pem(): string
    {
        if (self::$pem !== null) {
            return self::$pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $exported);

        return self::$pem = (string) $exported;
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
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * Handler Guzzle tiruan (tanpa jaringan): merekam method/path/query/body dan
 * melayani respons (atau exception) dari antrean.
 */
final class AgentStatusHandler
{
    /** @var array<int,array{method:string,path:string,query:string,body:string}> */
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

    /**
     * @return array{method:string,path:string,query:string,body:string}
     */
    public function call(int $index): array
    {
        if (!isset($this->calls[$index])) {
            throw new \LogicException("panggilan HTTP #{$index} tidak ada (total {$this->count()})");
        }

        return $this->calls[$index];
    }

    public function __invoke(object $request, array $options): mixed
    {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
        ];

        if ($this->queue === []) {
            throw new \RuntimeException('AgentStatusHandler: antrean respons habis');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/**
 * `ServerController` dengan seam: store temp, user tersuntik, dan klien agent
 * ber-handler tiruan (tanpa jaringan).
 */
final class AgentStatusServerController extends ServerController
{
    /** @var array<string,mixed>|null */
    public ?array $user = null;

    public function __construct(
        private readonly ServerStore $store,
        private readonly AgentStatusHandler $handler,
        private readonly string $tmp,
    ) {
        parent::__construct($store, null, new TlsPinMaterializer($this->tmp . '/agent-tls'));
    }

    protected function currentUser(): ?array
    {
        return $this->user;
    }

    protected function agentClient(array $server): AgentClient
    {
        return new AgentClient($server, $this->handler, 25, new TlsPinMaterializer($this->tmp . '/agent-tls'));
    }

    protected function flash(string $type, string $message): void
    {
        // tanpa session di uji
    }

    protected function renderView(string $template, array $vars): Response
    {
        return new Response(200, [], '');
    }
}
