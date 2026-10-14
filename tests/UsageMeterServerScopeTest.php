<?php
declare(strict_types=1);

namespace Tests;

use app\library\Agent\AgentError;
use app\library\Auth\UserStore;
use app\library\Backup\BackupRegistry;
use app\library\Billing\AppStopper;
use app\library\Billing\BackupUsage;
use app\library\Billing\BillingGate;
use app\library\Billing\BillingStore;
use app\library\Billing\CreditAccount;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\Invoicer;
use app\library\Billing\UsageMeter;
use app\library\Billing\UsageProbe;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\SqliteFixture;
use Webman\Config;

/**
 * Meteran & gerbang kredit **lintas server** (multi-server F4, misi
 * `ms-f4b-metering-server`) — penutup lubang pendapatan: app yang berjalan di
 * server agent harus diakru dari metrik **server itu**, bukan dari host control
 * plane, dan server yang tak terjangkau tidak boleh menagih buta maupun
 * menggandakan akrual.
 *
 * Kontrak yang dikunci:
 *  1. app di server agent ⇒ akrual memakai stats **agent** (sumbernya terbukti:
 *     sumber lokal tidak mengenal app itu, agent mengenalnya);
 *  2. app lokal ⇒ perilaku lama (regresi eksplisit);
 *  3. server tidak terjangkau ⇒ **dilewati** pada tick itu, alasan dicatat ke
 *     log/report, dan tick berikutnya **tidak** menagih ulang interval yang
 *     dilewati (tanpa akrual ganda/tunggakan);
 *  4. baris `usage` menyimpan `server_id` app (bertahan melewati penagihan);
 *  5. gerbang kredit & auto-stop tidak berubah: admin tetap bebas, member tetap
 *     diblokir bila saldo kurang, dan auto-stop **tidak pernah** jatuh ke server
 *     yang salah (server tak dikenal ⇒ gagal jujur, tidak stop lokal).
 *
 * Seluruh uji: basis data temp (`/tmp`), probe dengan sumber stats palsu —
 * tanpa Engine/Docker nyata, tanpa jaringan, tanpa store runtime nyata.
 */
class UsageMeterServerScopeTest extends TestCase
{
    private const NOW = '2026-10-14T09:30:00+07:00';

    /** 300 detik × 60 kredit/jam (limit default) = 5,0 kredit per tick. */
    private const TICK_CREDITS = 5.0;

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    private AppStore $apps;
    private BillingStore $billing;
    private UserStore $users;
    private ServerStore $servers;
    private ServerResolver $resolver;

    /** @var array<int,string> */
    private array $logs = [];

    /** @var array<int,array<int,string>> app id yang diminta tiap pembacaan lokal */
    private array $localCalls = [];

    /** @var array<int,string> server id yang diminta tiap pembacaan agent */
    private array $agentCalls = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_meter_scope_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/config', 0777, true);

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        file_put_contents($this->tmp . '/config/app.php', "<?php return [];\n");
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            '<?php return ' . var_export([
                'sqlite_file' => $this->tmp . '/rames.sqlite',
                'database_path' => $this->tmp,
                'billing_log_path' => $this->tmp . '/logs',
            ], true) . ';' . PHP_EOL
        );
        Config::load($this->tmp . '/config');

        $db = $this->tmp . '/rames.sqlite';
        SqliteFixture::users($db, [
            ['id' => 'u1', 'username' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => ''],
            ['id' => 'u2', 'username' => 'member', 'password_hash' => 'x', 'role' => 'member', 'created_at' => ''],
        ]);

        $this->apps = new AppStore($db);
        $this->billing = new BillingStore($db);
        $this->users = new UserStore($db);
        $this->servers = new ServerStore($db);
        $this->servers->create([
            'id' => 'srv-a',
            'name' => 'agent-a',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://agent-a.test',
            'token' => 'tok-uji-f4b',
        ]);
        ServerResolver::forgetMemo();
        $this->resolver = new ServerResolver($this->servers);

        $this->logs = [];
        $this->localCalls = [];
        $this->agentCalls = [];
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : @unlink($file);
        }
        @rmdir($this->tmp);
    }

    // ==================================================================
    // (1) app di server agent ⇒ akrual dari stats AGENT
    // ==================================================================

    public function testAgentServerAppIsAccruedFromAgentStatsNotFromLocalEngine(): void
    {
        $agent = $this->app('remoteapp', 'u2', 'running', 'srv-a');
        $local = $this->app('localapp', 'u2');

        // Sumber lokal hanya mengenal app lokal (persis keadaan nyata: container
        // app agent tidak ada di host control plane). Bila meteran salah membaca
        // sumber lokal, app agent akan "tidak teramati" ⇒ tidak diakru.
        $meter = $this->meter(
            local: static fn (array $apps): array => [
                'containers' => array_values(array_filter(array_map(
                    static fn (array $a): array => [
                        'app_id' => (string) ($a['id'] ?? ''),
                        'project' => (string) ($a['name'] ?? ''),
                        'state' => 'running',
                    ],
                    $apps
                ), static fn (array $row): bool => $row['project'] === 'localapp')),
                'error' => null,
            ],
            agent: function (array $server) use ($agent): array {
                return [
                    'containers' => [[
                        'app_id' => $agent['id'],
                        'project' => 'remoteapp',
                        'state' => 'running',
                        'cpu_percent' => 7.5,
                        'mem_used' => 64 * 1048576,
                    ]],
                    'error' => null,
                ];
            }
        );

        $report = $meter->sampleReport(300, self::NOW);

        $this->assertSame([$agent['id'], $local['id']], $report['accrued'], 'kedua app (agent & lokal) diakru');
        $this->assertSame([], $report['skipped']);
        $this->assertSame(1, count($this->agentCalls), 'satu pembacaan per server agent per tick');
        $this->assertSame('srv-a', $this->agentCalls[0]);
        $this->assertSame(1, count($this->localCalls), 'satu pembacaan Engine lokal per tick');
        $this->assertSame([$local['id']], $this->localCalls[0], 'hanya app server lokal yang dikirim ke Engine lokal');

        $usage = $this->billing->usage();
        $this->assertEqualsWithDelta(self::TICK_CREDITS, $usage[$agent['id']]['credits_pending'], 0.0001);
        $this->assertEqualsWithDelta(self::TICK_CREDITS, $usage[$local['id']]['credits_pending'], 0.0001);
        $this->assertSame('srv-a', $usage[$agent['id']]['server_id']);
        $this->assertSame('local', $usage[$local['id']]['server_id']);
        $this->assertSame([], $this->logs, 'tanpa pelewatan ⇒ tanpa baris log');
    }

    /**
     * Nama project container (bukan `app_id`) juga harus memetakan ke app yang
     * benar — agen mengirim baris `ResourceCollector` yang `app_id`-nya bisa
     * kosong bila proyeksi belum lengkap.
     */
    public function testAgentRowsAreMatchedByProjectNameWhenAppIdMissing(): void
    {
        $agent = $this->app('remoteapp', 'u2', 'running', 'srv-a');

        $meter = $this->meter(
            local: self::noLocalApp(),
            agent: static fn (array $server): array => [
                'containers' => [['app_id' => '', 'project' => 'remoteapp', 'state' => 'running']],
                'error' => null,
            ]
        );

        $this->assertSame([$agent['id']], $meter->sample(300, self::NOW));
        $this->assertSame('srv-a', $this->billing->usage()[$agent['id']]['server_id']);
    }

    /**
     * Bukti langsung sumber metrik: untuk app di server agent, angka yang
     * dilaporkan probe berasal dari **agent** (bukan angka Engine lokal yang
     * sengaja dibuat berbeda), dan Engine lokal tidak disentuh sama sekali.
     */
    public function testProbeReportsAgentMetricsForAgentApps(): void
    {
        $app = $this->app('metrik', 'u2', 'running', 'srv-a');

        $probe = $this->probe(
            local: static fn (array $apps): array => [
                'containers' => array_map(static fn (array $a): array => [
                    'app_id' => (string) ($a['id'] ?? ''),
                    'state' => 'running',
                    'cpu_percent' => 99.0,
                    'mem_used' => 1024 * 1048576,
                ], $apps),
                'error' => null,
            ],
            agent: static fn (array $server): array => [
                'containers' => [[
                    'app_id' => 'metrik',
                    'project' => 'metrik',
                    'state' => 'running',
                    'cpu_percent' => 7.5,
                    'mem_used' => 64 * 1048576,
                ]],
                'error' => null,
            ]
        );

        $observed = $probe->observe([$app['id'] => $app + ['server_id' => 'srv-a']]);
        $verdict = $observed['apps'][$app['id']];

        $this->assertSame('agent', $verdict['kind']);
        $this->assertTrue($verdict['container_running']);
        $this->assertSame(7.5, $verdict['cpu_percent'], 'angka agent (7,5 %) — bukan 99 % dari Engine lokal');
        $this->assertSame(64.0, $verdict['memory_mb']);
        $this->assertSame([], $this->localCalls, 'Engine lokal tidak pernah dipakai untuk server agent');
        $this->assertSame(['srv-a'], $this->agentCalls);
        $this->assertTrue((bool) $observed['servers']['srv-a']['reachable']);
    }

    /**
     * Kegagalan satu server tidak menjatuhkan akrual server lain (isolasi per
     * server): server agent offline dilewati, app server lokal tetap diakru.
     */
    public function testOneOfflineServerDoesNotBlockAccrualOnOtherServers(): void
    {
        $remote = $this->app('remote2', 'u2', 'running', 'srv-a');
        $local = $this->app('local2', 'u2');

        $report = $this->meter(
            local: self::localObservedRunning(),
            agent: static function (array $server): array {
                throw AgentError::unreachable('agent-a timeout');
            }
        )->sampleReport(300, self::NOW);

        $this->assertSame([$local['id']], $report['accrued']);
        $this->assertSame(UsageProbe::CAUSE_UNREACHABLE, $report['skipped'][$remote['id']]['cause']);

        $usage = $this->billing->usage();
        $this->assertArrayHasKey($local['id'], $usage);
        $this->assertArrayNotHasKey($remote['id'], $usage);
        $this->assertSame('local', $usage[$local['id']]['server_id']);
    }

    /**
     * Server offline **tidak menyembunyikan uang**: akumulasi yang sudah diakru
     * tetap ditagih (dan auto-stop member tetap berjalan) — yang dilewati hanya
     * interval saat server tidak teramati.
     */
    public function testOfflineServerStillBillsAlreadyAccruedUsage(): void
    {
        $app = $this->app('invoiced', 'u2', 'running', 'srv-a');
        $online = true;

        $meter = $this->meter(
            local: self::noLocalApp(),
            agent: function (array $server) use (&$online): array {
                if (!$online) {
                    throw AgentError::unreachable('agent-a offline');
                }

                return [
                    'containers' => [['app_id' => 'invoiced', 'project' => 'invoiced', 'state' => 'running']],
                    'error' => null,
                ];
            }
        );

        $meter->sampleReport(3600, self::NOW);
        $online = false;
        $meter->sampleReport(3600, self::NOW);

        $usage = $this->billing->usage();
        $this->assertEqualsWithDelta(60.0, $usage[$app['id']]['credits_pending'], 0.0001, 'akrual lama utuh, tidak bertambah');

        $stoppedOwners = [];
        $invoicer = new Invoicer(
            $this->apps,
            $this->billing,
            $this->users,
            static function (string $userId, array $apps = []) use (&$stoppedOwners): int {
                $stoppedOwners[] = $userId;

                return count($apps);
            }
        );
        $invoiced = $invoicer->runNow('2026-10', self::NOW);

        $this->assertCount(1, $invoiced);
        $this->assertEqualsWithDelta(60.0, $invoiced[0]['amount'], 0.0001);
        $this->assertTrue($invoiced[0]['stopped'], 'kebijakan stop tidak berubah walau server app offline');
        $this->assertSame(['u2'], $stoppedOwners);
        $this->assertSame('srv-a', $this->billing->usage()[$app['id']]['server_id']);
    }

    // ==================================================================
    // (2) app lokal ⇒ perilaku lama (regresi eksplisit)
    // ==================================================================

    public function testLocalAppsKeepLegacyBehaviour(): void
    {
        $member = $this->app('localmember', 'u2');
        $this->app('localadmin', 'u1');
        $this->app('localstopped', 'u2', 'stopped');

        $report = $this->meter(
            local: self::localObservedRunning(),
            agent: static fn (array $server): array => ['containers' => [], 'error' => null]
        )->sampleReport(300, self::NOW);

        $this->assertSame([$member['id']], $report['accrued'], 'hanya app running milik member');
        $this->assertSame([], $report['skipped'], 'app admin/stopped dilewati sebelum tahap bukti (bukan "skip")');
        $usage = $this->billing->usage();
        $this->assertCount(1, $usage);
        $this->assertEqualsWithDelta(self::TICK_CREDITS, $usage[$member['id']]['credits_pending'], 0.0001);
        $this->assertSame(300, (int) $usage[$member['id']]['seconds_pending']);
        $this->assertSame('local', $usage[$member['id']]['server_id']);
        $this->assertSame('2026-10', $usage[$member['id']]['period']);
    }

    /**
     * `status` di control plane bisa basi: app berstatus `running` tetapi
     * container-nya sudah mati **tidak** boleh ditagih.
     */
    public function testStaleRunningStatusIsNotBilledWhenContainerIsNotRunning(): void
    {
        $app = $this->app('stale', 'u2');

        $meter = $this->meter(
            local: static fn (array $apps): array => [
                'containers' => array_map(static fn (array $a): array => [
                    'app_id' => (string) ($a['id'] ?? ''),
                    'project' => (string) $a['name'],
                    'state' => 'exited',
                ], $apps),
                'error' => null,
            ],
            agent: static fn (array $server): array => ['containers' => [], 'error' => null]
        );

        $report = $meter->sampleReport(300, self::NOW);

        $this->assertSame([], $report['accrued']);
        $this->assertSame(UsageProbe::CAUSE_NOT_RUNNING, $report['skipped'][$app['id']]['cause']);
        $this->assertSame('local', $report['skipped'][$app['id']]['server_id']);
        $this->assertSame([], $this->billing->usage(), 'tanpa bukti running ⇒ tidak ada baris usage');
    }

    // ==================================================================
    // (3) server tidak terjangkau ⇒ dilewati, tanpa akrual ganda
    // ==================================================================

    public function testOfflineServerSkipsAccrualAndNeverDoubleBillsOnLaterTicks(): void
    {
        $app = $this->app('offlineapp', 'u2', 'running', 'srv-a');
        $online = false;

        $meter = $this->meter(
            local: self::noLocalApp(),
            agent: function (array $server) use (&$online): array {
                if (!$online) {
                    throw AgentError::unreachable('tidak dapat terhubung ke agent-a: timeout');
                }

                return [
                    'containers' => [['app_id' => 'offlineapp', 'project' => 'offlineapp', 'state' => 'running']],
                    'error' => null,
                ];
            }
        );

        // Tick 1 & 2: server mati ⇒ dilewati (bukan "menagih penuh", bukan pula
        // menagih nol tanpa jejak).
        foreach ([1, 2] as $tick) {
            $report = $meter->sampleReport(300, self::NOW);
            $this->assertSame([], $report['accrued'], "tick {$tick}: server offline tidak mengakru");
            $this->assertSame(UsageProbe::CAUSE_UNREACHABLE, $report['skipped'][$app['id']]['cause']);
            $this->assertSame('srv-a', $report['skipped'][$app['id']]['server_id']);
            $this->assertSame([], $this->billing->usage(), "tick {$tick}: tanpa tunggakan tersembunyi");
        }
        $this->assertCount(2, $this->logs, 'tiap tick yang melewati akrual menulis alasannya ke log');
        $this->assertStringContainsString('srv-a', $this->logs[0]);
        $this->assertStringContainsString(UsageProbe::CAUSE_UNREACHABLE, $this->logs[0]);
        $this->assertStringNotContainsString('tok-uji-f4b', $this->logs[0], 'token server tidak pernah masuk log');

        // Tick 3: server hidup lagi ⇒ hanya interval tick ini yang diakru
        // (interval yang dilewati TIDAK dikejar).
        $online = true;
        $report = $meter->sampleReport(300, self::NOW);

        $this->assertSame([$app['id']], $report['accrued']);
        $this->assertSame([], $report['skipped']);
        $row = $this->billing->usage()[$app['id']];
        $this->assertSame(300, (int) $row['seconds_pending'], 'hanya satu interval, tanpa akrual ganda');
        $this->assertEqualsWithDelta(self::TICK_CREDITS, $row['credits_pending'], 0.0001);
        $this->assertSame('srv-a', $row['server_id']);
    }

    /**
     * `server_id` yang tidak ada di registry **tidak** boleh jatuh ke Engine lokal
     * (itu akan mengakru app di server yang salah / menghentikannya di server
     * yang salah) — app dilewati dengan alasan eksplisit.
     */
    public function testUnknownServerIsSkippedAndNeverFallsBackToLocal(): void
    {
        $app = $this->app('ghostapp', 'u2', 'running', 'srv-ghost');

        $report = $this->meter(
            local: static fn (array $apps): array => [
                'containers' => array_map(static fn (array $a): array => [
                    'app_id' => (string) ($a['id'] ?? ''),
                    'state' => 'running',
                ], $apps),
                'error' => null,
            ],
            agent: static fn (array $server): array => ['containers' => [], 'error' => null]
        )->sampleReport(300, self::NOW);

        $this->assertSame([], $report['accrued']);
        $this->assertSame(UsageProbe::CAUSE_UNKNOWN_SERVER, $report['skipped'][$app['id']]['cause']);
        $this->assertSame([], $this->billing->usage());
        $this->assertSame(0, count($this->localCalls), 'Engine lokal tidak boleh dipakai untuk server tak dikenal');
    }

    /**
     * Kebijakan "app hidup": `running`/`paused`/`restarting` diakru, sedangkan
     * `created`/`exited`/`dead` tidak (tidak ada celah "crash-loop = gratis",
     * tidak pula menagih container yang sudah mati).
     */
    public function testLiveContainerStatesAreAccruedAndDeadStatesAreNot(): void
    {
        $live = $this->app('hidup', 'u2');
        $dead = $this->app('mati', 'u2');

        $meter = $this->meter(
            local: static fn (array $apps): array => [
                'containers' => array_map(static function (array $a): array {
                    $state = ($a['name'] ?? '') === 'hidup' ? 'restarting' : 'exited';

                    return ['app_id' => (string) ($a['id'] ?? ''), 'project' => (string) ($a['name'] ?? ''), 'state' => $state];
                }, $apps),
                'error' => null,
            ],
            agent: static fn (array $server): array => ['containers' => [], 'error' => null]
        );

        $report = $meter->sampleReport(300, self::NOW);

        $this->assertSame([$live['id']], $report['accrued']);
        $this->assertSame(UsageProbe::CAUSE_NOT_RUNNING, $report['skipped'][$dead['id']]['cause']);
        $this->assertSame([UsageProbe::LIVE_STATES[0], UsageProbe::LIVE_STATES[1], UsageProbe::LIVE_STATES[2]], UsageProbe::LIVE_STATES);
    }

    /**
     * Sumber metrik yang gagal dengan bentuk tak terduga (mis. Engine tidak
     * terjangkau) diperlakukan sebagai server tidak terjangkau — bukan "container
     * tidak ada".
     */
    public function testLocalEngineFailureIsReportedAsUnreachable(): void
    {
        $app = $this->app('localengine', 'u2');

        $report = $this->meter(
            local: static function (array $apps): array {
                throw new RuntimeException('Gagal terhubung ke Docker Engine: Connection refused');
            },
            agent: static fn (array $server): array => ['containers' => [], 'error' => null]
        )->sampleReport(300, self::NOW);

        $this->assertSame([], $report['accrued']);
        $this->assertSame(UsageProbe::CAUSE_UNREACHABLE, $report['skipped'][$app['id']]['cause']);
        $this->assertStringContainsString('Connection refused', $report['skipped'][$app['id']]['detail']);
        $this->assertSame('local', $report['servers']['local']['kind']);
        $this->assertFalse((bool) $report['servers']['local']['reachable']);
        $this->assertSame([], $this->billing->usage());
    }

    // ==================================================================
    // (4) atribusi per server — baris usage & ringkasan
    // ==================================================================

    public function testUsageRowCarriesServerIdAndSurvivesInvoicing(): void
    {
        $agent = $this->app('agentapp', 'u2', 'running', 'srv-a');
        $local = $this->app('localapp2', 'u2');

        $meter = $this->meter(
            local: self::localObservedRunning(),
            agent: static fn (array $server): array => [
                'containers' => [['app_id' => 'agentapp', 'project' => 'agentapp', 'state' => 'running']],
                'error' => null,
            ]
        );
        $meter->sampleReport(300, self::NOW);

        $usage = $this->billing->usage();
        $this->assertSame('srv-a', $usage[$agent['id']]['server_id']);
        $this->assertSame('local', $usage[$local['id']]['server_id']);

        $summary = $meter->summary();
        $this->assertSame('srv-a', $summary[$agent['id']]['server_id']);
        $this->assertSame('local', $summary[$local['id']]['server_id']);

        // Penagihan (Invoicer) tidak menghapus atribusi server & tetap menagih
        // kedua app (perilaku uang tidak berubah).
        $invoiced = (new Invoicer($this->apps, $this->billing, $this->users))->runNow('2026-10', self::NOW);
        $this->assertCount(1, $invoiced);
        $this->assertEqualsWithDelta(2 * self::TICK_CREDITS, $invoiced[0]['amount'], 0.0001);

        $after = $this->billing->usage();
        $this->assertSame('srv-a', $after[$agent['id']]['server_id'], 'server_id bertahan setelah penagihan');
        $this->assertSame('local', $after[$local['id']]['server_id']);
        $this->assertSame(0, (int) $after[$agent['id']]['seconds_pending']);
    }

    /**
     * App milik **admin** tetap bebas tagihan walau berjalan di server agent
     * (pengecualian admin tidak boleh bocor oleh dimensi server).
     */
    public function testAdminOwnedAgentAppStaysExempt(): void
    {
        $member = $this->app('memberagent', 'u2', 'running', 'srv-a');
        $this->app('adminagent', 'u1', 'running', 'srv-a');

        $report = $this->meter(
            local: self::noLocalApp(),
            agent: static fn (array $server): array => [
                'containers' => [
                    ['app_id' => 'memberagent', 'project' => 'memberagent', 'state' => 'running'],
                    ['app_id' => 'adminagent', 'project' => 'adminagent', 'state' => 'running'],
                ],
                'error' => null,
            ]
        )->sampleReport(300, self::NOW);

        $this->assertSame([$member['id']], $report['accrued']);
        $this->assertSame([], $report['skipped'], 'app admin disaring sebelum tahap bukti');
        $this->assertCount(1, $this->billing->usage());
    }

    // ==================================================================
    // (5) gerbang kredit & auto-stop tidak berubah
    // ==================================================================

    public function testCreditGateBehaviourIsUnchangedForMemberAndAdmin(): void
    {
        $agentApp = $this->app('gateapp', 'u2', 'running', 'srv-a');
        $gate = new BillingGate(new CreditAccount($this->billing, $this->users), null, $this->users);

        // Member tanpa saldo ⇒ tetap diblokir (aplikasi di server agent pun).
        try {
            $gate->assertCanStart($agentApp, ['id' => 'u2', 'role' => 'member']);
            $this->fail('member tanpa saldo harus diblokir gerbang kredit');
        } catch (InsufficientCredits $e) {
            $this->assertGreaterThan(0.0, $e->required);
        }

        // Admin (aktor) ⇒ tetap bebas, termasuk untuk app milik member.
        $gate->assertCanStart($agentApp, ['id' => 'u1', 'role' => 'admin']);
        $this->assertTrue(BillingGate::roleIsExempt(['id' => 'u1', 'role' => 'admin']));
        $this->assertFalse(BillingGate::roleIsExempt(['id' => 'u2', 'role' => 'member']));
    }

    /**
     * Auto-stop satu-satunya jalur = `DeployerFactory::createForApp()` (deployer
     * server app). Server tak dikenal ⇒ **gagal jujur** (app dibiarkan berjalan,
     * alasannya dicatat), bukan dihentikan di host control plane.
     */
    public function testAutoStopNeverStopsOnTheWrongServer(): void
    {
        $app = $this->app('ghoststop', 'u2', 'running', 'srv-ghost');

        $stopper = new AppStopper($this->apps);
        $stopped = $stopper->stopOwnedBy('u2', [$app]);

        $this->assertSame(0, $stopped, 'server tak dikenal ⇒ tidak ada stop');
        $this->assertSame('running', (string) $this->apps->find($app['id'])['status']);

        $log = (string) @file_get_contents($this->tmp . '/logs/' . date('Y-m-d') . '.log');
        $this->assertStringContainsString('GAGAL menghentikan app ' . $app['id'], $log);

        // Kebijakan pengalihan kepemilikan (anti-escape tagihan) tetap sama.
        $this->assertTrue(AppStopper::shouldStopOnTransfer($app, true, true));
        $this->assertFalse(AppStopper::shouldStopOnTransfer($app, false, true));
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @param array<string,mixed> $limits
     */
    private function app(string $name, string $owner, string $status = 'running', ?string $serverId = null, array $limits = []): array
    {
        $payload = ['name' => $name, 'owner_id' => $owner, 'status' => $status, 'limits' => $limits];
        if ($serverId !== null) {
            $payload['server_id'] = $serverId;
        }

        return $this->apps->create($payload);
    }

    /**
     * Meteran dengan probe uji: sumber stats lokal & agent palsu (mencatat
     * panggilan supaya "satu pembacaan per server per tick" bisa dibuktikan),
     * logger ke array (tidak menyentuh berkas log runtime).
     *
     * @param callable(array<int,array<string,mixed>>): array $local
     * @param callable(array<string,mixed>): array            $agent
     */
    private function meter(callable $local, callable $agent): UsageMeter
    {
        return new UsageMeter(
            $this->apps,
            $this->billing,
            $this->users,
            new BackupUsage(new BackupRegistry($this->tmp . '/rames.sqlite')),
            $this->probe($local, $agent),
            function (string $message): void {
                $this->logs[] = $message;
            }
        );
    }

    /**
     * Probe uji dengan sumber stats palsu (mencatat tiap panggilan).
     *
     * @param callable(array<int,array<string,mixed>>): array $local
     * @param callable(array<string,mixed>): array            $agent
     */
    private function probe(callable $local, callable $agent): UsageProbe
    {
        return new UsageProbe(
            $this->resolver,
            function (array $apps) use ($local): array {
                $this->localCalls[] = array_values(array_map(
                    static fn (array $a): string => (string) ($a['id'] ?? ''),
                    $apps
                ));

                return $local($apps);
            },
            function (array $server) use ($agent): array {
                $this->agentCalls[] = (string) ($server['id'] ?? '');

                return $agent($server);
            }
        );
    }

    /**
     * Sumber lokal palsu: semua app yang diminta teramati running.
     *
     * @return callable(array<int,array<string,mixed>>): array
     */
    private static function localObservedRunning(): callable
    {
        return static fn (array $apps): array => [
            'containers' => array_map(static fn (array $a): array => [
                'app_id' => (string) ($a['id'] ?? ''),
                'project' => (string) ($a['name'] ?? ''),
                'state' => 'running',
            ], $apps),
            'error' => null,
        ];
    }

    /**
     * Sumber lokal palsu yang tidak mengenal app apa pun (host control plane
     * tanpa container app yang dinilai).
     *
     * @return callable(array<int,array<string,mixed>>): array
     */
    private static function noLocalApp(): callable
    {
        return static fn (array $apps): array => ['containers' => [], 'error' => null];
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

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDir($item) : @unlink($item);
        }
        @rmdir($dir);
    }
}
