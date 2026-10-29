<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentClient;
use app\library\Agent\AgentArtifactPackager;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Deploy\AgentDeployer;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Handler Guzzle tiruan untuk `AgentDeployer` (tanpa jaringan): merekam setiap
 * permintaan (method, path, query, body) dan melayani respons dari antrean.
 *
 * Sengaja **tidak** memakai handler di `tests/AgentClientTest.php` (kelas itu
 * dideklarasikan di berkas dengan nama kelas lain sehingga tidak bisa
 * di-autoload PSR-4).
 */
final class AgentDeployerFakeHandler
{
    /** @var array<int,array{method:string,path:string,query:string,body:string}> */
    public array $calls = [];

    /** @var list<Response|\Throwable> */
    private array $queue = [];

    /** @param array<string,string> $headers */
    public function reply(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers + ['Content-Type' => 'application/json'], $body);

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

    /** Body JSON panggilan ke-`$index` sebagai array. */
    public function jsonBody(int $index): array
    {
        $decoded = json_decode($this->call($index)['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    public function __invoke(object $request, array $options): mixed
    {
        if ($this->queue === []) {
            throw new RuntimeException('AgentDeployerFakeHandler: antrean respons habis');
        }

        /** @var \Psr\Http\Message\RequestInterface $request */
        $this->calls[] = [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery(),
            'body' => (string) $request->getBody(),
        ];

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return Create::promiseFor($next);
    }
}

/**
 * Test `AgentDeployer` — implementasi `DeployerInterface` untuk server
 * `kind=agent` (misi `ms-f1-deploy`).
 *
 * Yang dikunci (plan §3 butir 4 + §4.1 + §11 catatan 6):
 *  1. setiap operasi panjang dipetakan ke **op job agent** yang benar
 *     (`ref` untuk rollback, `preserve_volumes` + `forget` untuk teardown);
 *  2. polling berhenti saat `done`; baris log baru diteruskan **tepat sekali**
 *     (offset byte-accurate, termasuk saat `X-Rames-Offset` agent bergeser atau
 *     potongan baris belum selesai);
 *  3. `error` dari agent ⇒ exception berisi pesan agent (disanitasi, tanpa token);
 *  4. batas waktu total ⇒ exception, bukan menggantung;
 *  5. state eksekusi (status/containers/deploy_history/ssl_status) sesudah job
 *     **ditarik dari agent** dan digabung ke app yang dikembalikan — metadata
 *     control plane tidak ikut tertimpa;
 *  6. volume project/network/DB-agent **dibaca dari** server agent (F3d: `/volumes`,
 *     `/networks*`, `/database/containers`) — tidak ada fallback `LocalDeployer`.
 *
 * Seluruh uji tanpa jaringan: `AgentClient` ber-handler tiruan; sertifikat TLS
 * ditulis ke `/tmp`. Tidak ada Docker, `apps/`, atau `database/rames.sqlite`
 * nyata yang tersentuh.
 */
class AgentDeployerTest extends TestCase
{
    private const APP_ID = 'app-uji-1';
    private const TOKEN = 'tok-agent-uji-f1-4d7a9c';

    /** Blok PEM + penanda isinya: tidak pernah boleh muncul di pesan/galat UI. */
    private const PEM_BLOCK = "-----BEGIN CERTIFICATE-----\nQUJDREVGUEVNVU5ERU5HSElKSw==\n-----END CERTIFICATE-----";
    private const PEM_BODY_MARKER = 'QUJDREVGUEVNVU5ERU5HSElKSw==';

    private string $tmp;

    /** @var list<array{0:string,1:string}> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-agentdeployer-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tmp);
    }

    // ==================================================================
    // (i) op → op job agent
    // ==================================================================

    /**
     * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}>
     */
    public static function operationCases(): array
    {
        return [
            'deploy' => ['deploy', [], ['op' => 'deploy']],
            'rebuild' => ['rebuild', [], ['op' => 'rebuild']],
            'apply' => ['apply', [], ['op' => 'apply']],
            'apply_env' => ['applyEnv', [], ['op' => 'apply_env']],
            'apply_limits' => ['applyLimits', [], ['op' => 'apply_limits']],
            'rollback' => ['rollback', ['ref' => 'abc1234'], ['op' => 'rollback', 'ref' => 'abc1234']],
            'stop' => ['stop', [], ['op' => 'stop']],
            'start' => ['start', [], ['op' => 'start']],
            // `teardown` = app dihapus di control plane ⇒ agent wajib membuang
            // proyeksinya sesudah teardown sukses (nama app dibebaskan).
            'teardown (purge)' => ['teardown', ['preserve' => null], ['op' => 'teardown', 'preserve_volumes' => null, 'forget' => true]],
            'teardown (preserve)' => ['teardown', ['preserve' => ['vol_a', 'vol_b']], ['op' => 'teardown', 'preserve_volumes' => ['vol_a', 'vol_b'], 'forget' => true]],
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('operationCases')]
    public function testEveryOperationMapsToAnAgentJobOp(string $method, array $args, array $expected): void
    {
        $handler = new AgentDeployerFakeHandler();
        $this->queueSuccessfulJob($handler);
        $handler->reply(200, json_encode(['code' => 0, 'app' => []]));
        $deployer = $this->deployer($handler);

        match ($method) {
            'deploy' => $deployer->deploy($this->app(), $this->logger()),
            'rebuild' => $deployer->rebuild($this->app(), $this->logger()),
            'apply' => $deployer->apply($this->app(), $this->logger()),
            'applyEnv' => $deployer->applyEnv($this->app(), $this->logger()),
            'applyLimits' => $deployer->applyLimits($this->app(), $this->logger()),
            'rollback' => $deployer->rollback($this->app(), (string) $args['ref'], $this->logger()),
            'stop' => $deployer->stop($this->app()),
            'start' => $deployer->start($this->app()),
            'teardown' => $deployer->teardown($this->app(), $args['preserve']),
            default => throw new \LogicException("method tak dikenal: {$method}"),
        };

        $first = $handler->call(0);
        $this->assertSame('POST', $first['method']);
        $this->assertSame('/jobs', $first['path']);

        $body = $handler->jsonBody(0);
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $body, "payload job wajib memuat `{$key}`");
            $this->assertSame($value, $body[$key], "payload `{$key}` tidak sesuai");
        }

        // Dokumen app ikut dikirim (proyeksi di agent, plan §4.3).
        $this->assertSame(self::APP_ID, $body['app']['id'] ?? null);
        $this->assertSame('demo', $body['app']['name'] ?? null);
    }

    public function testRollbackWithoutRefIsRejectedBeforeAnyCall(): void
    {
        $handler = new AgentDeployerFakeHandler();

        try {
            $this->deployer($handler)->rollback($this->app(), '   ', $this->logger());
            $this->fail('rollback tanpa ref harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ref', $e->getMessage());
        }

        $this->assertSame(0, $handler->count(), 'tidak boleh ada panggilan HTTP');
    }

    // ==================================================================
    // (ii) polling + offset log
    // ==================================================================

    public function testPollingStopsWhenDoneAndForwardsEachNewLineExactlyOnce(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $lineA = '[2026-10-14T10:00:00+07:00] [build] Menjalankan docker compose up -d --build ...' . "\n";
        $lineB = '[2026-10-14T10:00:05+07:00] done' . "\n";

        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job1', 'op' => 'deploy', 'log_offset' => 42]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'running']]))
            ->reply(200, $lineA)
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done']]))
            ->reply(200, $lineB)
            ->reply(200, '')
            ->reply(200, json_encode(['code' => 0, 'app' => $this->agentAppState()]));

        $result = $this->deployer($handler)->deploy($this->app(), $this->logger());

        // Baris log diteruskan tepat sekali, stage+pesan terpisah.
        $this->assertSame([
            ['build', 'Menjalankan docker compose up -d --build ...'],
            ['done', 'done'],
        ], $this->logLines());

        // Offset pertama = `log_offset` dari POST /jobs, lanjutannya byte-accurate.
        $this->assertSame('offset=42', $handler->call(2)['query']);
        $this->assertSame('/jobs/job1/log', $handler->call(2)['path']);
        $this->assertSame('offset=' . (42 + strlen($lineA)), $handler->call(4)['query']);

        // Status `done` menghentikan polling: tidak ada GET /jobs/{id} sesudahnya.
        $statusCalls = 0;
        foreach ($handler->calls as $call) {
            if ($call['path'] === '/jobs/job1') {
                $statusCalls++;
            }
        }
        $this->assertSame(2, $statusCalls, 'polling harus berhenti begitu status done');

        $this->assertSame('running', $result['status']);
    }

    public function testPartialTrailingLineIsNeitherForwardedNorSkipped(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $complete = '[ts] [build] baris lengkap' . "\n";
        $partial = '[ts] [build] baris belum selesai';
        $rest = " lanjutannya\n";

        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job2', 'log_offset' => 10]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'running']]))
            // Potongan pertama: satu baris lengkap + ekor baris yang belum tamat.
            ->reply(200, $complete . $partial)
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done']]))
            // Bacaan berikutnya mulai dari AWAL baris yang belum tamat.
            ->reply(200, $partial . $rest)
            ->reply(200, '')
            ->reply(200, json_encode(['code' => 0, 'app' => []]));

        $this->deployer($handler)->deploy($this->app(), $this->logger());

        $this->assertSame('offset=' . (10 + strlen($complete)), $handler->call(4)['query'], 'offset tidak boleh melewati baris yang belum tamat');
        $this->assertSame([
            ['build', 'baris lengkap'],
            ['build', 'baris belum selesai lanjutannya'],
        ], $this->logLines());
    }

    public function testShiftedXramesOffsetHeaderDoesNotCauseDuplicates(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $line = '[ts] [build] satu baris' . "\n";

        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job3', 'log_offset' => 7]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'running']]))
            // Header agent sengaja "bergeser" — offset kita dihitung dari body.
            ->reply(200, $line, ['X-Rames-Offset' => '999999'])
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done']]))
            ->reply(200, '', ['X-Rames-Offset' => '999999'])
            ->reply(200, json_encode(['code' => 0, 'app' => []]));

        $this->deployer($handler)->deploy($this->app(), $this->logger());

        $this->assertSame('offset=' . (7 + strlen($line)), $handler->call(4)['query']);
        $this->assertSame([['build', 'satu baris']], $this->logLines());
    }

    public function testMissingLogOffsetFallsBackToZeroAndSaysSoInTheLog(): void
    {
        $handler = new AgentDeployerFakeHandler();

        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job4', 'op' => 'deploy']))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done']]))
            ->reply(200, '')
            ->reply(200, json_encode(['code' => 0, 'app' => []]));

        $this->deployer($handler)->deploy($this->app(), $this->logger());

        $this->assertSame('offset=0', $handler->call(2)['query'], 'tanpa log_offset, baca dari 0');
        $this->assertStringContainsString('log_offset', implode(' ', array_column($this->lines, 1)));
    }

    // ==================================================================
    // (iii) error job
    // ==================================================================

    public function testAgentJobErrorBecomesExceptionWithSanitizedMessage(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job5', 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => [
                'status' => 'error',
                'error' => 'docker compose gagal: unauthorized token=' . self::TOKEN,
            ]]))
            ->reply(200, '');

        try {
            $this->deployer($handler)->rebuild($this->app(), $this->logger());
            $this->fail('job error harus melempar exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('docker compose gagal', $e->getMessage());
            $this->assertStringContainsString('rebuild', $e->getMessage());
            $this->assertStringContainsString('vps-jakarta-2', $e->getMessage(), 'identitas server wajib ada');
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage(), 'token tidak boleh bocor ke pesan galat');
        }
    }

    /**
     * N-4: `sanitize()` juga meredaksi **identitas server** — `base_url` lengkap,
     * `host:port`, dan host saja diganti `server agent`, sedangkan label
     * `Server "nama" (id)` tetap utuh (admin butuh tahu server mana).
     *
     * Uji pemisah ini penting: bila kelak sanitizer diganti versi yang tetap
     * meredaksi PEM namun tidak meredaksi host, **uji ini** yang memerah (kalau
     * tidak ada, regresi itu lolos diam-diam).
     */
    public function testAgentMessagesRedactTheAgentBaseUrlAndHostButKeepTheLabel(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'available' => false,
            'state' => 'blocked',
            'message' => 'gagal menghubungi https://agent.test:8443/apps/check — cek agent.test:8443 (host agent.test).',
        ]));

        try {
            $this->deployer($handler)->assertAppTargetAvailable($this->app());
            $this->fail('available:false harus membatalkan create app');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            $this->assertStringNotContainsString('agent.test', $message, 'host internal agent tidak boleh tampil');
            $this->assertStringNotContainsString('8443', $message, 'port internal agent tidak boleh tampil');
            $this->assertStringContainsString('server agent', $message, 'alamat diganti penanda netral');
            $this->assertStringContainsString(
                'Server "vps-jakarta-2" (srv-2)',
                $message,
                'label server (identitas yang dibutuhkan admin) tetap utuh'
            );
        }
    }

    /**
     * Fix R-1: blok PEM (sertifikat/kunci) dan token yang tercecer di pesan agent
     * **tidak pernah** sampai ke pesan exception control plane — `sanitize()`
     * memakai satu pintu {@see AgentError::sanitizeForServer()}.
     *
     * Uji ini **GAGAL** bila `AgentDeployer::sanitize()` dikembalikan ke
     * `AgentError::sanitize($msg, $token)`: blok PEM lolos apa adanya (token tetap
     * teredaksi) — dibuktikan dengan mutation check: mengganti isi `sanitize()`
     * ke versi lama ⇒ 2 uji ini merah ("BEGIN CERTIFICATE" muncul di pesan).
     */
    public function testAgentJobErrorNeverLeaksPemBlocksOrTheServerToken(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-pem', 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => [
                'status' => 'error',
                'error' => "verifikasi sertifikat gagal:\n" . self::PEM_BLOCK . "\ntoken=" . self::TOKEN,
            ]]))
            ->reply(200, '');

        try {
            $this->deployer($handler)->rebuild($this->app(), $this->logger());
            $this->fail('job error harus melempar exception');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('BEGIN CERTIFICATE', $e->getMessage());
            $this->assertStringNotContainsString(self::PEM_BODY_MARKER, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringContainsString('verifikasi sertifikat gagal', $e->getMessage(), 'pesan asli (tanpa rahasia) tetap tersampaikan');
            $this->assertStringContainsString('vps-jakarta-2', $e->getMessage(), 'identitas server tetap ada');
        }
    }

    // ==================================================================
    // (iv) batas waktu total
    // ==================================================================

    public function testPollingToleratesTransientServerErrorsThenSucceeds(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-t', 'log_offset' => 0]))
            ->reply(503, '{"error":"sibuk"}')
            ->reply(503, '{"error":"sibuk"}')
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done']]))
            ->reply(200, '')
            ->reply(200, '')
            ->reply(200, json_encode(['code' => 0, 'app' => $this->agentAppState()]));

        $result = $this->deployer($handler)->deploy($this->app(), $this->logger());

        $this->assertIsArray($result);
    }

    public function testPollingGivesUpAfterTooManyConsecutiveFailures(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job-f', 'log_offset' => 0]));
        for ($i = 0; $i < AgentDeployer::MAX_POLL_FAILURES + 1; $i++) {
            $handler->reply(503, '{"error":"mati"}');
        }

        $this->expectException(\app\library\Agent\AgentError::class);
        $this->deployer($handler)->deploy($this->app(), $this->logger());
    }

    public function testPollingFailsFastOnUnauthorized(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => 'job-u', 'log_offset' => 0]))
            ->reply(401, '{"code":401}')
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done']]));

        try {
            $this->deployer($handler)->deploy($this->app(), $this->logger());
            $this->fail('401 tidak boleh diulang');
        } catch (\app\library\Agent\AgentError $e) {
            $this->assertSame(\app\library\Agent\AgentError::UNAUTHORIZED, $e->reason);
            $this->assertSame(2, $handler->count());
        }
    }

    public function testTotalTimeoutThrowsInsteadOfHanging(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, json_encode(['code' => 0, 'job_id' => 'job6', 'log_offset' => 0]));
        for ($i = 0; $i < 10; $i++) {
            $handler
                ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'running', 'stage' => 'build']]))
                ->reply(200, '');
        }

        $now = 0.0;
        $deployer = $this->deployer($handler, timeout: 5, clock: static function () use (&$now): float {
            $now += 3.0;

            return $now;
        });

        try {
            $deployer->deploy($this->app(), $this->logger());
            $this->fail('timeout total harus melempar exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('detik', $e->getMessage());
            $this->assertStringContainsString('job6', $e->getMessage());
        }

        $this->assertLessThan(6, $handler->count(), 'tidak boleh menggantung: polling berhenti saat batas waktu');
    }

    // ==================================================================
    // (v) state eksekusi ditarik dari agent
    // ==================================================================

    public function testExecutionStateFromAgentIsMergedIntoReturnedApp(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $this->queueSuccessfulJob($handler);
        $handler->reply(200, json_encode(['code' => 0, 'app' => $this->agentAppState()]));

        $result = $this->deployer($handler)->deploy($this->app(), $this->logger());

        $this->assertSame('running', $result['status']);
        $this->assertSame([['service_name' => 'web', 'container_name' => 'demo-web-1', 'image' => 'nginx', 'internal_port' => 80, 'host_port' => 31001, 'status' => 'running']], $result['containers']);
        $this->assertSame([['sha' => 'deadbee', 'action' => 'deploy', 'status' => 'success']], $result['deploy_history']);
        $this->assertSame('active', $result['ssl_status']);

        // Metadata tetap milik control plane.
        $this->assertSame('demo', $result['name']);
        $this->assertSame('u-owner', $result['owner_id']);
        $this->assertSame([['KEY' => 'VALUE']], $result['env']);
        $this->assertSame('srv-2', $result['server_id']);

        // Permintaan state memakai id app control plane.
        $last = $handler->call($handler->count() - 1);
        $this->assertSame('/apps/' . self::APP_ID, $last['path']);
    }

    public function testStatePullFailureDoesNotFailASucceededJob(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $this->queueSuccessfulJob($handler);
        // Agent versi lama/gagal: `GET /apps/{id}` menjawab 500.
        $handler->reply(500, json_encode(['code' => 1, 'error' => 'boom']));

        $result = $this->deployer($handler)->deploy($this->app(), $this->logger());

        $this->assertSame('deploying', $result['status'], 'dokumen app dikembalikan apa adanya');
        $this->assertStringContainsString('Gagal menarik state', implode(' ', array_column($this->lines, 1)));
    }

    public function testUnknownAppOnStatePullIsTreatedAsNoState(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $this->queueSuccessfulJob($handler);
        // App belum/tidak lagi diproyeksikan di agent ⇒ 404 = "tidak ada state",
        // bukan kegagalan job (deploy-nya sendiri sudah sukses).
        $handler->reply(404, json_encode(['code' => 1, 'error' => 'App tidak ditemukan.']));

        $result = $this->deployer($handler)->deploy($this->app(), $this->logger());

        $this->assertSame('deploying', $result['status']);
        $this->assertStringNotContainsString('Gagal menarik state', implode(' ', array_column($this->lines, 1)));
    }

    // ==================================================================
    // (vi) volume project per server (F3d) — dibaca DARI AGENT, tidak dari lokal
    // ==================================================================

    /**
     * `getProjectVolumes()` sudah server-aware (F3d): nama volume dibaca dari
     * Engine **server agent** (`GET /volumes?project=…`), bukan daemon host
     * control plane. Jalur lokal diuji `LocalDeployerTeardownTest`.
     */
    public function testProjectVolumesAreReadFromTheAgent(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, json_encode([
            'code' => 0,
            'data' => ['volumes' => ['demo_db', 'demo_uploads', '']],
        ]));

        $volumes = $this->deployer($handler)->getProjectVolumes('demo');

        $this->assertSame(['demo_db', 'demo_uploads'], $volumes);
        $call = $handler->call(0);
        $this->assertSame('GET', $call['method']);
        $this->assertSame('/volumes', $call['path']);
        $this->assertSame('project=demo', $call['query']);
    }

    /**
     * Project kosong/tidak sah ⇒ **tanpa** panggilan HTTP apa pun (tidak ada
     * permintaan aneh ke agent, dan tidak ada fallback ke daemon lokal).
     */
    public function testProjectVolumesRejectsInvalidProjectWithoutHttpCall(): void
    {
        $handler = new AgentDeployerFakeHandler();

        $this->assertSame([], $this->deployer($handler)->getProjectVolumes(''));
        $this->assertSame([], $this->deployer($handler)->getProjectVolumes('../etc'));
        $this->assertSame(0, $handler->count(), 'project tidak sah tidak boleh memanggil agent/lokal sama sekali');
    }

    public function testGetContainersReadsFromAgentByAppId(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, json_encode(['code' => 0, 'app' => $this->agentAppState()]));

        $containers = $this->deployer($handler)->getContainersForApp($this->app());

        $this->assertSame('demo-web-1', $containers[0]['container_name']);
        $this->assertSame('/apps/' . self::APP_ID, $handler->call(0)['path']);
        $this->assertSame('GET', $handler->call(0)['method']);
    }

    /**
     * `GET /apps/{id}/services` — daftar service + limit base compose **dari
     * agent**, satu panggilan, bentuk respons divalidasi & dinormalkan (tidak ada
     * field asing yang ikut keluar).
     */
    public function testAppServicesReadsServicesAndDetectedFromTheAgent(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'data' => [
                'services' => ['web', 'db', 'web', ''],
                'detected' => [
                    'web' => ['cpus' => 1, 'memory_mb' => 512, 'cpu_families' => ['legacy']],
                    'db' => ['cpus' => null, 'memory_mb' => null],
                ],
            ],
        ]));

        $data = $this->deployer($handler)->appServices($this->app());

        $this->assertSame(['web', 'db'], $data['services'], 'duplikat & entri kosong dibuang');
        $this->assertSame(['cpus' => 1.0, 'memory_mb' => 512], $data['detected']['web'], 'hanya field kontrak');
        $this->assertSame(['cpus' => null, 'memory_mb' => null], $data['detected']['db']);
        $this->assertNull($data['error']);

        $this->assertSame(1, $handler->count());
        $this->assertSame('GET', $handler->call(0)['method']);
        $this->assertSame('/apps/' . self::APP_ID . '/services', $handler->call(0)['path']);
    }

    /**
     * Agent lama (endpoint belum ada) ⇒ 404 yang diterjemahkan menjadi
     * `AgentError::NOT_FOUND` — pemanggil menampilkan "perbarui agent".
     */
    public function testAppServicesOnOldAgentIsNotFound(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(404, (string) json_encode(['code' => 1, 'error' => 'Endpoint tidak ada.']));

        try {
            $this->deployer($handler)->appServices($this->app());
            $this->fail('404 harus menjadi AgentError::NOT_FOUND');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::NOT_FOUND, $e->reason);
        }
    }

    /** Bentuk respons di luar kontrak ⇒ `AgentError`, bukan array kosong diam-diam. */
    public function testAppServicesRejectsMalformedPayload(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, (string) json_encode(['code' => 0, 'services' => ['web']]));

        $this->expectException(AgentError::class);

        $this->deployer($handler)->appServices($this->app());
    }

    public function testAppWithoutValidIdIsRejected(): void
    {
        $handler = new AgentDeployerFakeHandler();

        try {
            $this->deployer($handler)->deploy(['name' => 'demo', 'id' => '../etc/passwd'], $this->logger());
            $this->fail('id app tak sah harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('id', $e->getMessage());
        }

        $this->assertSame(0, $handler->count());
    }

    // ==================================================================
    // (vii) artefak app (misi ms-f1-artifacts)
    // ==================================================================

    /**
     * Artefak dikirim untuk op yang membutuhkannya (deploy/rebuild/apply/rollback)
     * dan **tidak** dipaketkan untuk op yang tidak menyentuh artefak.
     *
     * @return array<string,array{0:string,1:bool}>
     */
    public static function artifactOpCases(): array
    {
        return [
            'deploy' => ['deploy', true],
            'rebuild' => ['rebuild', true],
            'apply' => ['apply', true],
            'rollback' => ['rollback', true],
            'apply_env' => ['applyEnv', false],
            'apply_limits' => ['applyLimits', false],
            'stop' => ['stop', false],
            'start' => ['start', false],
            'teardown' => ['teardown', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('artifactOpCases')]
    public function testArtifactsRideAlongOnlyForOpsThatNeedThem(string $method, bool $expected): void
    {
        $compose = "services:\n  web:\n    image: nginx:alpine\n";
        mkdir($this->tmp . '/apps/demo', 0777, true);
        file_put_contents($this->tmp . '/apps/demo/docker-compose.yml', $compose);

        $app = ['source' => 'compose'] + $this->app();

        $handler = new AgentDeployerFakeHandler();
        $this->queueSuccessfulJob($handler);
        $handler->reply(200, json_encode(['code' => 0, 'app' => []]));
        $deployer = $this->deployer($handler);

        match ($method) {
            'deploy' => $deployer->deploy($app, $this->logger()),
            'rebuild' => $deployer->rebuild($app, $this->logger()),
            'apply' => $deployer->apply($app, $this->logger()),
            'applyEnv' => $deployer->applyEnv($app, $this->logger()),
            'applyLimits' => $deployer->applyLimits($app, $this->logger()),
            'rollback' => $deployer->rollback($app, 'abc1234', $this->logger()),
            'stop' => $deployer->stop($app),
            'start' => $deployer->start($app),
            'teardown' => $deployer->teardown($app, null),
            default => throw new \LogicException("method tak dikenal: {$method}"),
        };

        $body = $handler->jsonBody(0);
        if (!$expected) {
            $this->assertArrayNotHasKey('artifacts', $body, "op `{$method}` tidak boleh memaketkan artefak");

            return;
        }

        $this->assertSame($compose, $body['artifacts']['compose'] ?? null);
        $this->assertSame('docker-compose.yml', $body['artifacts']['compose_name'] ?? null);
        $this->assertNull($body['artifacts']['template'] ?? null);
    }

    /**
     * Gagal memaketkan artefak (mis. file compose hilang di control plane) ⇒
     * **tidak ada** job yang dikirim, galat disampaikan ke pemanggil (dan pesan
     * tidak memuat token server).
     */
    public function testPackagerFailureAbortsTheJobBeforeAnyHttpCall(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $app = ['source' => 'compose'] + $this->app(); // file compose tidak ada di disk

        try {
            $this->deployer($handler)->deploy($app, $this->logger());
            $this->fail('pemaketan gagal harus membatalkan job');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('artefak app tidak bisa dikirim', $e->getMessage());
            $this->assertStringContainsString('docker-compose.yml', $e->getMessage());
            $this->assertStringContainsString('vps-jakarta-2', $e->getMessage(), 'pesan galat menyebut server target');
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage(), 'token server tidak boleh bocor');
        }

        $this->assertSame(0, $handler->count(), 'job tidak boleh dikirim bila artefak tidak lengkap');

        // Op yang tidak menyentuh artefak tetap jalan walau file compose hilang.
        $this->queueSuccessfulJob($handler);
        $handler->reply(200, json_encode(['code' => 0, 'app' => []]));
        $this->deployer($handler)->applyEnv($app, $this->logger());

        $this->assertSame('POST', $handler->call(0)['method']);
        $this->assertSame('/jobs', $handler->call(0)['path']);
        $this->assertArrayNotHasKey('artifacts', $handler->jsonBody(0));
    }

    // ==================================================================
    // (viii) preflight ketersediaan nama app di server agent (POST /apps/check)
    // ==================================================================

    /**
     * `available:true` ⇒ lewat tanpa efek samping, dan payload preflight hanya
     * membawa `{id,name}` (dokumen app penuh bisa memuat `env`/deploy key — tidak
     * satu pun boleh ikut).
     */
    public function testAppTargetAvailableSendsOnlyIdAndNameAndReturnsSilently(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'op' => 'app_check',
            'available' => true,
            'state' => 'free',
            'message' => '',
        ]));

        $this->deployer($handler)->assertAppTargetAvailable($this->app());

        $this->assertSame(1, $handler->count());
        $this->assertSame('POST', $handler->call(0)['method']);
        $this->assertSame('/apps/check', $handler->call(0)['path']);

        $body = $handler->jsonBody(0);
        $this->assertSame(self::APP_ID, $body['app']['id'] ?? null);
        $this->assertSame('demo', $body['app']['name'] ?? null);
        $this->assertArrayNotHasKey('env', (array) ($body['app'] ?? []), 'payload preflight sempit');
        $this->assertArrayNotHasKey('ssh_key', (array) ($body['app'] ?? []));
    }

    /**
     * Ketiga state yang aman (`free`, `owned`, `stale`) **tidak** boleh menolak
     * create: mutasi "hanya `free` yang lolos" harus tertangkap uji ini.
     *
     * @return array<string,array{0:string}>
     */
    public static function safePreflightStates(): array
    {
        return ['free' => ['free'], 'owned' => ['owned'], 'stale' => ['stale']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('safePreflightStates')]
    public function testSafePreflightStatesNeverRefuseCreate(string $state): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'op' => 'app_check',
            'available' => true,
            'state' => $state,
            'message' => '',
        ]));

        $this->deployer($handler)->assertAppTargetAvailable($this->app());

        $this->assertSame(1, $handler->count());
    }

    /**
     * Fix R-3 (fail-closed): `available` **wajib** boolean. Bentuk yang tak terbaca
     * tidak boleh dilewati diam-diam (itu mengembalikan "deploy gagal" sesudah
     * record app lahir), tetapi juga **bukan** `RuntimeException` ramah — ini
     * masalah agent, jadi `AgentError` ber-label + pesan aksi.
     *
     * @return array<string,array{0:string}>
     */
    public static function unreadableAvailablePayloads(): array
    {
        return [
            'absen' => [json_encode(['code' => 0, 'op' => 'app_check', 'state' => 'free'])],
            'angka nol' => [json_encode(['code' => 0, 'available' => 0])],
            'string "false"' => [json_encode(['code' => 0, 'available' => 'false'])],
            'null' => [json_encode(['code' => 0, 'available' => null])],
            'array' => [json_encode(['code' => 0, 'available' => []])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableAvailablePayloads')]
    public function testUnreadableAvailabilityAnswerIsFailClosed(string $body): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, $body);

        try {
            $this->deployer($handler)->assertAppTargetAvailable($this->app());
            $this->fail('respons agent tanpa `available` boolean harus membatalkan create');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::BAD_RESPONSE, $e->reason);
            $this->assertStringContainsString('vps-jakarta-2', $e->getMessage(), 'identitas server wajib ada');
            $this->assertStringContainsString('status ketersediaan nama app', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        $this->assertSame(1, $handler->count(), 'preflight tidak diulang');
    }

    /**
     * `available:false` ⇒ create dibatalkan (RuntimeException) dengan pesan ramah:
     * menyebut server target, nama app, tindakannya, plus catatan dari agent —
     * dan tanpa membocorkan token server.
     */
    public function testBlockedAppTargetIsRefusedWithAFriendlyMessage(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'op' => 'app_check',
            'available' => false,
            'state' => 'blocked',
            'message' => 'Direktori `apps/demo` di server ini sudah ada dan berisi data tanpa penanda kepemilikan Rames.',
        ]));

        try {
            $this->deployer($handler)->assertAppTargetAvailable($this->app());
            $this->fail('available:false harus membatalkan create app');
        } catch (RuntimeException $e) {
            // Kontrak: penolakan data = pesan ramah (RuntimeException), BUKAN
            // AgentError. `AgentError extends RuntimeException`, jadi tanpa
            // assertion ini mutasi "lempar AgentError" akan lolos.
            $this->assertNotInstanceOf(AgentError::class, $e, 'penolakan `available:false` bukan galat transport/agent');
            $this->assertInstanceOf(RuntimeException::class, $e);
            $this->assertStringContainsString('demo', $e->getMessage(), 'nama app wajib disebut');
            $this->assertStringContainsString('vps-jakarta-2', $e->getMessage(), 'server target wajib disebut');
            $this->assertStringContainsString('pakai nama app lain', $e->getMessage());
            $this->assertStringContainsString('deploy ulang', $e->getMessage());
            $this->assertStringContainsString('apps/demo', $e->getMessage(), 'catatan dari agent ikut disampaikan');
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage(), 'token server tidak boleh bocor');
        }

        $this->assertSame(1, $handler->count(), 'preflight hanya sekali panggilan, tanpa job');
    }

    /**
     * Fix R-1 di jalur preflight: catatan `message` dari agent disanitasi lewat
     * pintu tunggal — blok PEM tidak boleh ikut ke pesan yang tampil di wizard.
     */
    public function testBlockedAppTargetMessageNeverCarriesPemBlocks(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, (string) json_encode([
            'code' => 0,
            'available' => false,
            'state' => 'blocked',
            'message' => "sisa sertifikat:\n" . self::PEM_BLOCK,
        ]));

        try {
            $this->deployer($handler)->assertAppTargetAvailable($this->app());
            $this->fail('available:false harus membatalkan create app');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('BEGIN CERTIFICATE', $e->getMessage());
            $this->assertStringNotContainsString(self::PEM_BODY_MARKER, $e->getMessage());
            $this->assertStringContainsString('[redacted]', $e->getMessage(), 'rahasia diganti penanda, bukan sekadar dihapus');
        }
    }

    /**
     * `repo_url` ikut payload preflight **hanya** untuk app repo (predikat sama
     * dengan `AgentArtifactMaterializer::repoUrlOf()`): agent membandingkan remote
     * checkout yang sudah ada di sana. App template/compose tidak mengirimnya —
     * dan `env`/`ssh_key` tidak pernah ikut.
     */
    public function testRepoUrlRidesAlongOnlyForRepoApps(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(200, (string) json_encode(['code' => 0, 'available' => true, 'state' => 'free', 'message' => '']));
        $handler->reply(200, (string) json_encode(['code' => 0, 'available' => true, 'state' => 'free', 'message' => '']));
        $deployer = $this->deployer($handler);

        $deployer->assertAppTargetAvailable($this->app());
        $body = $handler->jsonBody(0);
        $this->assertSame('https://example.test/demo.git', $body['app']['repo_url'] ?? null);
        $this->assertArrayNotHasKey('env', (array) $body['app']);

        // App template dengan `repo_url` tertinggal (data lama) ⇒ tidak dikirim.
        $deployer->assertAppTargetAvailable(['template' => ['slug' => 'uji']] + $this->app());
        $this->assertArrayNotHasKey('repo_url', (array) $handler->jsonBody(1)['app']);
    }

    /**
     * Agent lama (belum punya `POST /apps/check`) menjawab 404 ⇒ preflight
     * **dilewati**: create app yang sebelumnya jalan tidak boleh mati hanya karena
     * control plane lebih dulu di-update.
     */
    public function testOldAgentWithoutCheckEndpointIsTolerated(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(404, (string) json_encode(['code' => 1, 'error' => 'Endpoint tidak ada.']));

        $this->deployer($handler)->assertAppTargetAvailable($this->app());

        $this->assertSame(1, $handler->count());
    }

    /**
     * Galat agent selain 404 (5xx/transport) ⇒ **tidak** boleh dilewati: create
     * dibatalkan karena ketersediaan namanya tidak bisa dipastikan.
     */
    public function testAgentFailureOtherThan404AbortsTheCreate(): void
    {
        $handler = new AgentDeployerFakeHandler();
        $handler->reply(500, (string) json_encode(['code' => 1, 'error' => 'gagal membaca direktori app']));

        try {
            $this->deployer($handler)->assertAppTargetAvailable($this->app());
            $this->fail('galat agent selain 404 harus membatalkan create');
        } catch (AgentError $e) {
            $this->assertSame(AgentError::SERVER_ERROR, $e->reason);
            $this->assertStringContainsString('tidak bisa memastikan', $e->getMessage());
            $this->assertStringContainsString('vps-jakarta-2', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    /** App tanpa id sah ditolak **sebelum** ada panggilan HTTP apa pun. */
    public function testAppTargetAvailableRejectsInvalidAppIdWithoutHttpCall(): void
    {
        $handler = new AgentDeployerFakeHandler();

        try {
            $this->deployer($handler)->assertAppTargetAvailable(['id' => '../etc/passwd', 'name' => 'demo']);
            $this->fail('id app tak sah harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('id', $e->getMessage());
        }

        $this->assertSame(0, $handler->count());
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @return array<string,mixed>
     */
    private function app(): array
    {
        return [
            'id' => self::APP_ID,
            'name' => 'demo',
            'server_id' => 'srv-2',
            'owner_id' => 'u-owner',
            'repo_url' => 'https://example.test/demo.git',
            'branch' => 'main',
            'env' => [['KEY' => 'VALUE']],
            'compose_files' => ['docker-compose.yml'],
            'status' => 'deploying',
            'containers' => [],
            'deploy_history' => [],
        ];
    }

    /**
     * State eksekusi versi agent — juga membawa field metadata yang mencoba
     * "menimpa" control plane (harus diabaikan).
     *
     * @return array<string,mixed>
     */
    private function agentAppState(): array
    {
        return [
            'id' => self::APP_ID,
            'name' => 'nama-dari-agent',
            'server_id' => 'local',
            'status' => 'running',
            'stage' => null,
            'message' => 'Running',
            'error' => null,
            'containers' => [[
                'service_name' => 'web',
                'container_name' => 'demo-web-1',
                'image' => 'nginx',
                'internal_port' => 80,
                'host_port' => 31001,
                'status' => 'running',
            ]],
            'deploy_history' => [['sha' => 'deadbee', 'action' => 'deploy', 'status' => 'success']],
            'ssl_status' => 'active',
        ];
    }

    /**
     * Antrean respons satu job sukses (POST → status `done` → log → log ekor).
     * Respons `GET /apps/{id}` **tidak** ikut diantrekan — pemanggil menambahnya
     * sendiri supaya baris `panggilan ke-N` pada assertion tetap jelas.
     */
    private function queueSuccessfulJob(AgentDeployerFakeHandler $handler, string $jobId = 'job-ok'): void
    {
        $handler
            ->reply(200, json_encode(['code' => 0, 'job_id' => $jobId, 'op' => 'deploy', 'log_offset' => 0]))
            ->reply(200, json_encode(['code' => 0, 'job' => ['status' => 'done', 'message' => 'Running']]))
            ->reply(200, '')
            ->reply(200, '');
    }

    private function deployer(
        AgentDeployerFakeHandler $handler,
        ?int $timeout = 30,
        ?callable $clock = null,
        ?AgentArtifactPackager $packager = null,
    ): AgentDeployer {
        $server = [
            'id' => 'srv-2',
            'name' => 'vps-jakarta-2',
            'kind' => 'agent',
            'base_url' => 'https://agent.test:8443',
            'token' => self::TOKEN,
            // Domain server (APP_DOMAIN server itu) — vhost/SSL di agent butuh ini
            // (kontrak F2 §5); tanpa domain, operasi vhost/SSL ditolak lebih awal.
            'domain' => 'agent.example.test',
            'tls_cert_pem' => self::selfSignedPem(),
        ];

        $client = new AgentClient($server, $handler, 25, new TlsPinMaterializer($this->tmp . '/tls'));

        return new AgentDeployer(
            $server,
            $client,
            $timeout,
            AgentDeployer::MIN_POLL_INTERVAL_MS,
            static function (int $microseconds): void {
                // tanpa tidur nyata di uji
            },
            $clock,
            // Path artefak diarahkan ke /tmp: uji tidak menyentuh `apps/` atau
            // `database/keys/` nyata.
            $packager ?? new AgentArtifactPackager(
                $this->tmp . '/apps',
                $this->tmp . '/database/keys',
                $this->tmp . '/database'
            )
        );
    }

    /** @return callable(string,string):void */
    private function logger(): callable
    {
        return function (string $stage, string $message): void {
            $this->lines[] = [$stage, $message];
        };
    }

    /**
     * Baris log yang berasal dari agent (mengabaikan baris envelope stage
     * `agent`).
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function logLines(): array
    {
        return array_values(array_filter($this->lines, static fn (array $line): bool => $line[0] !== 'agent'));
    }

    /** Sertifikat self-signed sekali pakai (pola `AgentClientTest`). */
    private static function selfSignedPem(): string
    {
        static $pem = null;
        if ($pem !== null) {
            return $pem;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'agent.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $exported);

        return $pem = (string) $exported;
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
