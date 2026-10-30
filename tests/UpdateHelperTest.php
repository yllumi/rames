<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerClient;
use app\library\Support\ProcessRunner;
use app\library\Update\RepoInfo;
use app\library\Update\UpdateService;
use app\library\Update\UpdateState;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

/**
 * Test mesin self-update (docs/SPECS.md §7.8):
 *
 *  1. `UpdateService::buildHelperCommand()` — perintah `docker run` helper
 *     disusun sebagai ARRAY (tanpa shell) dan seluruh nilai tervalidasi masuk
 *     sebagai satu elemen argv, sehingga tidak ada command injection. Skrip
 *     helper disalin ke /tmp dulu supaya `git pull` tidak menimpa skrip yang
 *     sedang dieksekusi.
 *
 *  2. `cli/update-report.php` — penulis status mandiri yang dipakai helper
 *     (subproses, seperti cli/deploy.php diuji): hanya stage/result dari daftar
 *     tertutup yang diterima, SHA divalidasi, dan run terdahulu digabung (merge).
 */
class UpdateHelperTest extends TestCase
{
    private string $tmp;
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->tmp = sys_get_temp_dir() . '/rames-helper-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        // Fixture repo minimal: `selfContext()` memvalidasi berkas compose yang
        // akan dipakai helper (fallback dashboard = docker-compose.yml).
        file_put_contents($this->tmp . '/docker-compose.yml', "services: {}\n");
    }

    protected function tearDown(): void
    {
        GitTestFixture::removeDir($this->tmp);
    }

    // ==================================================================
    // Perintah helper
    // ==================================================================

    public function testBuildHelperCommandIsArrayAndCarriesAllContext(): void
    {
        $command = UpdateService::buildHelperCommand($this->spec());

        $this->assertSame('docker', $command[0]);
        $this->assertContains('run', $command);
        $this->assertContains('-d', $command);
        $this->assertContains('--rm', $command);

        // User = pemilik repo (agar berkas hasil pull tidak jadi milik root).
        $this->assertContains('--user', $command);
        $this->assertContains('1000:1000', $command);
        // Grup socket Docker (agar `docker compose` bisa dipakai user non-root).
        $this->assertContains('--group-add', $command);
        $this->assertContains('999', $command);
        // Network yang sama dengan dashboard → /healthz lewat nama container.
        $this->assertContains('--network', $command);
        $this->assertContains('rames_default', $command);
        $this->assertContains('--dns', $command);
        $this->assertContains('8.8.8.8', $command);
        $this->assertContains('1.1.1.1', $command);

        $this->assertContains('/var/run/docker.sock:/var/run/docker.sock', $command);
        $this->assertContains('/srv/rames:/srv/rames', $command);
        $this->assertContains('-w', $command);
        $this->assertContains('PWD=/srv/rames', $command);
        $this->assertContains('rames-webman', $command);

        // Ekor perintah: [image, sh, -c, <shell konstan>, sh, script, report, plan]
        $tail = array_slice($command, -8);
        $this->assertSame('rames-webman', $tail[0]);
        $this->assertSame('sh', $tail[1]);
        $this->assertSame('-c', $tail[2]);
        $this->assertSame(
            'cp "$1" /tmp/rames-self-update.sh && cp "$2" /tmp/rames-update-report.php && exec sh /tmp/rames-self-update.sh "$3"',
            $tail[3]
        );
        $this->assertSame('sh', $tail[4]);
        // Skrip dijalankan dari SALINAN di /tmp (stabil walau repo berubah oleh pull).
        $this->assertStringContainsString('/tmp/rames-self-update.sh', (string) $tail[3]);
        $this->assertStringContainsString('/tmp/rames-update-report.php', (string) $tail[3]);
        $this->assertSame(
            ['/srv/rames/cli/self-update.sh', '/srv/rames/cli/update-report.php', '/tmp/runs/x.plan.json'],
            array_slice($tail, 5)
        );
    }

    public function testOptionalFlagsAreOmittedWhenAbsent(): void
    {
        $spec = $this->spec();
        $spec['group_add'] = 0;
        $spec['dns'] = [];

        $command = UpdateService::buildHelperCommand($spec);

        $this->assertNotContains('--group-add', $command);
        $this->assertNotContains('--dns', $command);
    }

    /**
     * UPDATE_ALLOW_ROOT (opt-in instalasi root): helper berjalan `--user 0:0`.
     * Tidak ada jalur yang memaksa uid > 0 saat uid sah = 0 (`--group-add` hanya
     * untuk gid socket yang diketahui).
     */
    public function testBuildHelperCommandSupportsRootHelperUserWhenOptedIn(): void
    {
        $spec = $this->spec();
        $spec['uid'] = 0;
        $spec['gid'] = 0;

        $command = UpdateService::buildHelperCommand($spec);

        $userIndex = array_search('--user', $command, true);
        $this->assertNotFalse($userIndex);
        $this->assertSame('0:0', $command[$userIndex + 1], 'uid/gid helper dipakai apa adanya (0 sah)');
    }

    /**
     * Env interpolasi compose yang efektif diteruskan sebagai `-e NAME=value`
     * setelah env bawaan (HOME/DOCKER_CONFIG/COMPOSER_HOME/PWD). Tanpa ini
     * `docker compose up` di helper memakai `${VAR:-default}` → container hasil
     * recreate bisa pindah port/path (mis. AGENT_PORT_PUBLISH kembali 8443).
     */
    public function testBuildHelperCommandForwardsComposeEnvAfterDefaults(): void
    {
        $spec = $this->spec();
        $spec['env'] = [
            'AGENT_PORT_PUBLISH' => '9443',
            'NGINX_CONF_PATH' => '/srv/nginx/sites-available',
            'DNS_1' => '9.9.9.9',
        ];

        $command = UpdateService::buildHelperCommand($spec);

        foreach (['AGENT_PORT_PUBLISH=9443', 'NGINX_CONF_PATH=/srv/nginx/sites-available', 'DNS_1=9.9.9.9'] as $env) {
            $index = array_search($env, $command, true);
            $this->assertNotFalse($index, 'env ' . $env . ' harus diteruskan');
            $this->assertSame('-e', $command[$index - 1], 'nama & nilai tetap SATU elemen argv setelah -e');
        }

        // Setelah env bawaan PWD (indeks env bawaan terakhir), sebelum volume.
        $pwdIndex = array_search('PWD=/srv/rames', $command, true);
        $portIndex = array_search('AGENT_PORT_PUBLISH=9443', $command, true);
        $this->assertNotFalse($pwdIndex);
        $this->assertNotFalse($portIndex);
        $this->assertGreaterThan((int) $pwdIndex, (int) $portIndex, 'env compose menyusul env bawaan');
    }

    public function testBuildHelperCommandSkipsEmptyEnvNamesAndValues(): void
    {
        $spec = $this->spec();
        $spec['env'] = ['' => 'x', 'DNS_1' => '', 'DNS_2' => '1.1.1.1'];

        $command = UpdateService::buildHelperCommand($spec);

        $envArgs = [];
        foreach ($command as $index => $arg) {
            if ($arg === '-e' && isset($command[$index + 1])) {
                $envArgs[] = $command[$index + 1];
            }
        }
        $this->assertContains('DNS_2=1.1.1.1', $envArgs);
        $this->assertNotContains('DNS_1=', $envArgs);
        $this->assertNotContains('=x', $envArgs);
    }

    public function testBuildHelperCommandOmitsEnvWhenSpecHasNone(): void
    {
        $command = UpdateService::buildHelperCommand($this->spec());

        $this->assertSame([], array_values(array_filter(
            $command,
            static fn (string $arg): bool => str_starts_with($arg, 'AGENT_PORT_PUBLISH=')
        )));
        // Env bawaan tetap ada (regresi).
        $this->assertContains('PWD=/srv/rames', $command);
    }

    public function testValuesAreNeverInterpolatedIntoTheShellString(): void
    {
        // Nilai "jahat" pada path & nama: harus tetap jadi SATU elemen argv dan
        // string `sh -c` tidak boleh ikut berubah (konstan).
        $spec = $this->spec();
        $spec['root'] = '/srv/evil; rm -rf / #';
        $spec['script'] = '/srv/evil; rm -rf / #/cli/self-update.sh';
        $spec['plan'] = '/tmp/x$(id).plan.json';

        $command = UpdateService::buildHelperCommand($spec);

        $expectedShell = 'cp "$1" /tmp/rames-self-update.sh && cp "$2" /tmp/rames-update-report.php && exec sh /tmp/rames-self-update.sh "$3"';
        $this->assertContains($expectedShell, $command);
        $this->assertContains($spec['script'], $command);
        $this->assertContains($spec['plan'], $command);
        // Path jahat tetap utuh sebagai SATU elemen argv (bukan dipecah shell).
        $this->assertContains($spec['root'] . ':' . $spec['root'], $command);
        $this->assertSame(1, count(array_filter($command, static fn (string $arg): bool => $arg === $spec['script'])));
        $this->assertSame(1, count(array_filter($command, static fn (string $arg): bool => $arg === $spec['plan'])));
    }

    // ==================================================================
    // Network helper (egress + health-check) & konteks container dashboard
    // ==================================================================

    public function testHelperNetworkPrefersComposeDefaultOverInternalHelperNetwork(): void
    {
        // Dashboard juga ter-attach ke network helper Adminer `rames-helpers`
        // (`internal: true`) dan ke network default compose. Urutan kunci
        // `NetworkSettings.Networks` terurut abjad → "rames-helpers" lebih dulu;
        // pemilihan TIDAK boleh mengikuti urutan itu (network internal tidak punya
        // egress → `git fetch` gagal "Could not resolve host").
        $chosen = UpdateService::chooseHelperNetwork(
            ['rames-helpers', 'rames_default'],
            ['rames-helpers' => true, 'rames_default' => false],
            'rames'
        );

        $this->assertSame('rames_default', $chosen);
    }

    public function testHelperNetworkIgnoresAppNetworksAttachedToDashboard(): void
    {
        // Network DB app (di-attach dashboard saat dump/restore) tidak dipakai
        // walau non-internal: container dashboard hasil recreate berada di network
        // compose, dan /healthz diakses lewat nama container di network yang sama.
        $chosen = UpdateService::chooseHelperNetwork(
            ['aaa_default', 'rames-helpers', 'rames_default'],
            ['aaa_default' => false, 'rames-helpers' => true, 'rames_default' => false],
            'rames'
        );

        $this->assertSame('rames_default', $chosen);
    }

    public function testHelperNetworkSkipsInternalComposeNetwork(): void
    {
        $chosen = UpdateService::chooseHelperNetwork(
            ['rames_default', 'rames_shared'],
            ['rames_default' => true, 'rames_shared' => false],
            'rames'
        );

        $this->assertSame('rames_shared', $chosen);
    }

    public function testHelperNetworkIsEmptyWhenEveryNetworkIsInternal(): void
    {
        $chosen = UpdateService::chooseHelperNetwork(
            ['rames-helpers', 'rames_default'],
            ['rames-helpers' => true, 'rames_default' => true],
            'rames'
        );

        $this->assertSame('', $chosen);
    }

    public function testHelperNetworkRefusesForeignProjectNetworkWhenInternalFlagsAreKnown(): void
    {
        // Semua network project compose internal, tersisa network milik project
        // lain (app). Network itu punya egress, tetapi container dashboard hasil
        // recreate tidak ada di sana → /healthz gagal & update berakhir rollback.
        // Lebih baik ditolak di preflight.
        $chosen = UpdateService::chooseHelperNetwork(
            ['rames_default', 'rames-helpers', 'sibimo_default'],
            ['rames_default' => true, 'rames-helpers' => true, 'sibimo_default' => false],
            'rames'
        );

        $this->assertSame('', $chosen);
    }

    public function testHelperNetworkToleratesUnknownInternalFlagAndMissingProject(): void
    {
        // Engine tidak menjawab saat inspect → status internal tidak diketahui:
        // update tetap boleh dicoba, jangan memblokir karena tidak bisa memastikan.
        $this->assertSame('rames_default', UpdateService::chooseHelperNetwork(['rames_default'], [], 'rames'));
        $this->assertSame('app_net', UpdateService::chooseHelperNetwork(['app_net'], [], 'rames'));
        $this->assertSame('dash_default', UpdateService::chooseHelperNetwork(['dash_default'], [], ''));
        $this->assertSame('', UpdateService::chooseHelperNetwork([], [], 'rames'));
    }

    public function testSelfContextPicksNonInternalNetworkAndKeepsDns(): void
    {
        $context = $this->service($this->fakeDocker(
            [
                'Name' => '/rames-webman',
                'Config' => [
                    'Image' => 'rames-webman',
                    'Labels' => [
                        'com.docker.compose.project' => 'rames',
                        'com.docker.compose.service' => 'webman',
                    ],
                ],
                'HostConfig' => ['Dns' => ['8.8.8.8', '1.1.1.1']],
                'NetworkSettings' => [
                    'Networks' => [
                        'rames-helpers' => ['IPAddress' => '172.20.0.2'],
                        'rames_default' => ['IPAddress' => '172.18.0.2'],
                    ],
                ],
            ],
            ['rames-helpers' => true, 'rames_default' => false]
        ))->selfContext();

        $this->assertTrue($context['ok'], (string) $context['error']);
        $this->assertSame('rames_default', $context['network']);
        $this->assertSame(['8.8.8.8', '1.1.1.1'], $context['dns']);
        $this->assertSame('rames', $context['project']);
        $this->assertSame('webman', $context['service']);
        $this->assertSame('http://rames-webman:8787/healthz', $this->service($this->fakeDocker(
            [
                'Name' => '/rames-webman',
                'Config' => ['Image' => 'rames-webman', 'Labels' => [
                    'com.docker.compose.project' => 'rames',
                    'com.docker.compose.service' => 'webman',
                ]],
                'HostConfig' => ['Dns' => []],
                'NetworkSettings' => ['Networks' => ['rames_default' => ['IPAddress' => '172.18.0.2']]],
            ],
            ['rames_default' => false]
        ))->healthUrl($context));
    }

    public function testSelfContextFailsFastWhenOnlyInternalNetworksAreAttached(): void
    {
        // Tanpa network non-internal, helper tidak akan bisa `git fetch` — berhenti
        // SEBELUM repo disentuh, dengan pesan yang menyebut sebabnya.
        $context = $this->service($this->fakeDocker(
            [
                'Name' => '/rames-webman',
                'Config' => ['Image' => 'rames-webman', 'Labels' => [
                    'com.docker.compose.project' => 'rames',
                    'com.docker.compose.service' => 'webman',
                ]],
                'HostConfig' => ['Dns' => []],
                'NetworkSettings' => ['Networks' => ['rames-helpers' => ['IPAddress' => '172.20.0.2']]],
            ],
            ['rames-helpers' => true]
        ))->selfContext();

        $this->assertFalse($context['ok']);
        $this->assertSame('', $context['network']);
        $this->assertStringContainsString('internal', (string) $context['error']);
        $this->assertStringContainsString('rames-helpers', (string) $context['error']);
    }

    // ==================================================================
    // Env interpolasi compose (port/path hasil kustomisasi installer)
    // ==================================================================

    public function testSelfContextCollectsEffectiveComposeInterpolationValues(): void
    {
        $context = $this->service($this->fakeDocker(
            [
                'Name' => '/rames-agent',
                'Config' => [
                    'Image' => 'rames-agent',
                    'Labels' => [
                        'com.docker.compose.project' => 'rames-agent',
                        'com.docker.compose.service' => 'webman',
                    ],
                    'Env' => [
                        'RAMES_MODE=agent',
                        'NGINX_CONF_PATH=/srv/nginx/conf.d',
                        'NGINX_ENABLED_PATH=/srv/nginx/enabled',
                        'NGINX_HTTP_CONF=/srv/nginx/nginx.conf',
                        'NGINX_BIN=/usr/local/sbin/nginx',
                        'NGINX_RELOAD_IMAGE=alpine:3.20',
                        'DOCKER_SOCKET=/run/user/1000/docker.sock',
                        'DOCKER_BINARY=/usr/local/bin/docker',
                        'RAMES_DB_DIR=/srv/rames-agent-db',
                        'DNS_1=9.9.9.9',
                        'DNS_2=149.112.112.112',
                        // Rahasia/identitas: TIDAK boleh ikut ke helper.
                        'AGENT_TOKEN=rahasia-token',
                        'AGENT_TLS_CERT=/srv/secrets/agent.crt',
                        'AGENT_TLS_KEY=/srv/secrets/agent.key',
                        'ADMIN_EMAIL=owner@example.test',
                        'APP_DOMAIN=agent.example.test',
                        'PORT_RANGE_START=31000',
                        'PORT_RANGE_END=31999',
                    ],
                ],
                'HostConfig' => [
                    'Dns' => ['9.9.9.9', '149.112.112.112'],
                    // Port publish kustom hasil installer (--bind 0.0.0.0:9443):
                    // hanya terlihat di sini, tidak ada di Config.Env.
                    'PortBindings' => ['8443/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '9443']]],
                ],
                'NetworkSettings' => ['Networks' => ['rames-agent_default' => ['IPAddress' => '172.19.0.2']]],
            ],
            ['rames-agent_default' => false]
        ))->selfContext();

        $this->assertTrue($context['ok'], (string) $context['error']);
        $this->assertSame([
            'NGINX_CONF_PATH' => '/srv/nginx/conf.d',
            'NGINX_ENABLED_PATH' => '/srv/nginx/enabled',
            'NGINX_HTTP_CONF' => '/srv/nginx/nginx.conf',
            'NGINX_BIN' => '/usr/local/sbin/nginx',
            'NGINX_RELOAD_IMAGE' => 'alpine:3.20',
            'DOCKER_SOCKET' => '/run/user/1000/docker.sock',
            'DOCKER_BINARY' => '/usr/local/bin/docker',
            'RAMES_DB_DIR' => '/srv/rames-agent-db',
            'DNS_1' => '9.9.9.9',
            'DNS_2' => '149.112.112.112',
            'AGENT_PORT_PUBLISH' => '9443',
        ], $context['env']);

        // Lot yang MUSTAHIL lolos ke `docker run -e` helper.
        $command = UpdateService::buildHelperCommand(array_merge($this->spec(), ['env' => $context['env']]));
        foreach (['AGENT_TOKEN', 'AGENT_TLS_CERT', 'AGENT_TLS_KEY', 'ADMIN_EMAIL', 'APP_DOMAIN', 'PORT_RANGE_START', 'PORT_RANGE_END'] as $name) {
            $forbidden = array_values(array_filter(
                $command,
                static fn (string $arg): bool => str_starts_with($arg, $name . '=')
            ));
            $this->assertSame([], $forbidden, 'rahasia/identitas ' . $name . ' tidak boleh diteruskan ke helper');
        }
        $this->assertContains('AGENT_PORT_PUBLISH=9443', $command);
    }

    public function testSelfContextAddsNoComposeEnvWhenValuesAreNotCustom(): void
    {
        // Nilai tidak ditemukan (default compose) → JANGAN menulis `-e` sama
        // sekali; compose memakai default-nya sendiri. PortBindings kosong /
        // HostPort non-numerik juga tidak boleh menghasilkan env.
        $context = $this->service($this->fakeDocker(
            [
                'Name' => '/rames-webman',
                'Config' => [
                    'Image' => 'rames-webman',
                    'Labels' => [
                        'com.docker.compose.project' => 'rames',
                        'com.docker.compose.service' => 'webman',
                    ],
                    'Env' => ['RAMES_MODE=dashboard', 'PATH=/usr/bin', 'AGENT_TOKEN=rahasia-token'],
                ],
                'HostConfig' => [
                    'Dns' => [],
                    'PortBindings' => [
                        '8787/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8123']],
                        '8443/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '']],
                    ],
                ],
                'NetworkSettings' => ['Networks' => ['rames_default' => ['IPAddress' => '172.18.0.2']]],
            ],
            ['rames_default' => false]
        ))->selfContext();

        $this->assertSame([], $context['env']);
    }

    // ==================================================================
    // Health URL (skema mengikuti listener: agent = TLS)
    // ==================================================================

    public function testHealthUrlUsesHttpsWhenAgentListenerTerminatesTls(): void
    {
        // Mode agent: `config/process.php` menyetel listen http://0.0.0.0:<bind>
        // + transport => ssl (TLS diterminasi Workerman), sehingga /healthz HANYA
        // dilayani HTTPS. Skema diturunkan dari config, bukan dari nama container.
        $url = $this->withProcessConfig(
            ['webman' => ['listen' => 'http://0.0.0.0:9443', 'transport' => 'ssl']],
            fn (): string => $this->service($this->fakeDocker([], []))->healthUrl(['name' => 'rames-agent'])
        );

        $this->assertSame('https://rames-agent:9443/healthz', $url);
    }

    public function testHealthUrlStaysHttpForDashboardWithoutTransport(): void
    {
        $url = $this->withProcessConfig(
            ['webman' => ['listen' => 'http://0.0.0.0:8787']],
            fn (): string => $this->service($this->fakeDocker([], []))->healthUrl(['name' => 'rames-webman'])
        );

        $this->assertSame('http://rames-webman:8787/healthz', $url);
    }

    // ==================================================================
    // cli/self-update.sh — health check internal mengabaikan TLS self-signed
    // ==================================================================

    public function testHelperScriptHealthCheckIgnoresSelfSignedTls(): void
    {
        $script = (string) file_get_contents($this->root . '/cli/self-update.sh');

        $curlLines = [];
        foreach (preg_split('/\R/', $script) ?: [] as $line) {
            if (preg_match('/\bcurl\b/', $line) === 1 && str_contains($line, 'HEALTH_URL')) {
                $curlLines[] = $line;
            }
        }
        $this->assertNotSame([], $curlLines, 'skrip helper harus memanggil health URL dengan curl');

        foreach ($curlLines as $line) {
            $this->assertMatchesRegularExpression(
                '/(\s-k\s|\s--insecure\s)/',
                $line,
                'health check internal wajib -k/--insecure (sertifikat agent self-signed): ' . trim($line)
            );
        }

        // Kedua titik health check (versi baru & rollback) memakai satu probe.
        $this->assertSame(2, substr_count($script, 'body=$(health_probe)'));
        $this->assertStringContainsString('health_probe() {', $script);
    }

    // ==================================================================
    // Berkas compose helper (bug production: helper me-recreate service salah)
    // ==================================================================

    public function testSelfContextUsesComposeConfigFilesLabelForHelper(): void
    {
        $base = $this->tmp . '/docker-compose.agent.yml';
        $override = $this->tmp . '/docker-compose.agent.override.yml';
        file_put_contents($base, "services: {}\n");
        file_put_contents($override, "services: {}\n");

        $labels = [
            'com.docker.compose.project' => 'rames-agent',
            'com.docker.compose.service' => 'webman',
            'com.docker.compose.project.working_dir' => $this->tmp,
        ];

        // Satu berkas.
        $context = $this->service($this->fakeDocker(
            $this->fakeInspect('rames-agent', $labels + ['com.docker.compose.project.config_files' => $base]),
            ['rames-agent_default' => false]
        ))->selfContext();
        $this->assertTrue($context['ok'], (string) $context['error']);
        $this->assertSame([$base], $context['compose_files']);
        $env = UpdateService::helperEnv($context);
        $this->assertSame($base, $env['COMPOSE_FILE']);
        $this->assertSame('rames-agent', $env['COMPOSE_PROJECT_NAME']);

        // Banyak berkas (label dipisah koma → env dipisah ':' seperti Compose).
        $context = $this->service($this->fakeDocker(
            $this->fakeInspect('rames-agent', $labels + ['com.docker.compose.project.config_files' => $base . ',' . $override]),
            ['rames-agent_default' => false]
        ))->selfContext();
        $this->assertTrue($context['ok'], (string) $context['error']);
        $this->assertSame([$base, $override], $context['compose_files']);
        $env = UpdateService::helperEnv($context);
        $this->assertSame($base . ':' . $override, $env['COMPOSE_FILE']);

        // Perintah helper benar-benar membawa COMPOSE_FILE/COMPOSE_PROJECT_NAME.
        $command = UpdateService::buildHelperCommand(array_merge($this->spec(), ['env' => $env]));
        $fileIndex = array_search('COMPOSE_FILE=' . $base . ':' . $override, $command, true);
        $this->assertNotFalse($fileIndex);
        $this->assertSame('-e', $command[$fileIndex - 1]);
        $this->assertContains('COMPOSE_PROJECT_NAME=rames-agent', $command);
    }

    public function testAgentFallsBackToAgentComposeFileNotDashboardTemplate(): void
    {
        // Label config_files TIDAK ada (Compose lama / container dibuat manual):
        // project `rames-agent` + `<root>/docker-compose.agent.yml` ada ⇒ berkas
        // itu. docker-compose.yml (template DASHBOARD) juga ada di root — memilih
        // berkas itu berarti me-recreate `rames-webman` dan menghapus agent.
        $agent = $this->tmp . '/docker-compose.agent.yml';
        file_put_contents($agent, "services: {}\n");
        $this->assertFileExists($this->tmp . '/docker-compose.yml', 'fixture: template dashboard ada juga');

        $context = $this->service($this->fakeDocker(
            $this->fakeInspect('rames-agent', [
                'com.docker.compose.project' => 'rames-agent',
                'com.docker.compose.service' => 'webman',
            ]),
            ['rames-agent_default' => false]
        ))->selfContext();

        $this->assertTrue($context['ok'], (string) $context['error']);
        $this->assertSame([$agent], $context['compose_files']);
        $this->assertSame($agent, UpdateService::helperEnv($context)['COMPOSE_FILE']);
        $this->assertStringNotContainsString('docker-compose.yml', $agent);
    }

    public function testDashboardKeepsDashboardComposeFile(): void
    {
        // Regresi dashboard: tanpa label config_files dan bukan project agent ⇒
        // tetap `docker-compose.yml`.
        $context = $this->service($this->fakeDocker(
            $this->fakeInspect('rames-webman', [
                'com.docker.compose.project' => 'rames',
                'com.docker.compose.service' => 'webman',
            ]),
            ['rames_default' => false]
        ))->selfContext();

        $this->assertTrue($context['ok'], (string) $context['error']);
        $this->assertSame([$this->tmp . '/docker-compose.yml'], $context['compose_files']);
        $this->assertSame($this->tmp . '/docker-compose.yml', UpdateService::helperEnv($context)['COMPOSE_FILE']);
        $this->assertSame('rames', UpdateService::helperEnv($context)['COMPOSE_PROJECT_NAME']);
    }

    public function testSelfContextRefusesWhenComposeFileIsMissingOrEmpty(): void
    {
        $labels = [
            'com.docker.compose.project' => 'rames-agent',
            'com.docker.compose.service' => 'webman',
            'com.docker.compose.project.working_dir' => $this->tmp,
        ];

        // Berkas label tidak ada → preflight gagal SEBELUM helper di-spawn.
        $missing = $this->tmp . '/tidak-ada-compose.yml';
        $context = $this->service($this->fakeDocker(
            $this->fakeInspect('rames-agent', $labels + ['com.docker.compose.project.config_files' => $missing]),
            ['rames-agent_default' => false]
        ))->selfContext();
        $this->assertFalse($context['ok']);
        $this->assertStringContainsString($missing, (string) $context['error']);
        $this->assertStringContainsString('tidak ditemukan', (string) $context['error']);
        $this->assertStringContainsString('me-recreate service yang salah', (string) $context['error']);

        // Berkas kosong juga ditolak.
        $empty = $this->tmp . '/kosong-compose.yml';
        file_put_contents($empty, '');
        $context = $this->service($this->fakeDocker(
            $this->fakeInspect('rames-agent', $labels + ['com.docker.compose.project.config_files' => $empty]),
            ['rames-agent_default' => false]
        ))->selfContext();
        $this->assertFalse($context['ok']);
        $this->assertStringContainsString('kosong', (string) $context['error']);

        // Fallback dashboard tanpa docker-compose.yml di root juga ditolak.
        unlink($this->tmp . '/docker-compose.yml');
        $context = $this->service($this->fakeDocker(
            $this->fakeInspect('rames-webman', [
                'com.docker.compose.project' => 'rames',
                'com.docker.compose.service' => 'webman',
            ]),
            ['rames_default' => false]
        ))->selfContext();
        $this->assertFalse($context['ok']);
        $this->assertStringContainsString('docker-compose.yml', (string) $context['error']);

        // preflight() meneruskan galat ini (start() menolak sebelum spawn).
        $pf = $this->service($this->fakeDocker(
            $this->fakeInspect('rames-agent', $labels + ['com.docker.compose.project.config_files' => $missing]),
            ['rames-agent_default' => false]
        ))->preflight();
        $this->assertFalse($pf['ok']);
        $this->assertNotEmpty(array_filter(
            $pf['errors'],
            static fn (string $error): bool => str_contains($error, 'tidak ditemukan')
        ));
    }

    public function testHelperScriptFailsFastWhenComposeFileIsMissing(): void
    {
        $script = (string) file_get_contents($this->root . '/cli/self-update.sh');

        // Fail-fast SEBELUM compose dijalankan: COMPOSE_FILE wajib diisi dan
        // setiap berkasnya harus ada/tidak kosong.
        $this->assertStringContainsString('COMPOSE_FILES="${COMPOSE_FILE:-}"', $script);
        $this->assertStringContainsString('first_missing_compose_file "$COMPOSE_FILES"', $script);
        $this->assertStringContainsString('first_missing_compose_file() {', $script);

        $guard = strpos($script, 'MISSING_COMPOSE=$(first_missing_compose_file');
        $up = strpos($script, 'docker compose -p "$PROJECT" --project-directory "$ROOT" up');
        $this->assertNotFalse($guard, 'pemeriksaan berkas compose harus ada');
        $this->assertNotFalse($up, 'compose up harus ada');
        $this->assertLessThan((int) $up, (int) $guard, 'pemeriksaan berkas compose mendahului compose up');

        // Pesan galat menyebut nama berkas & alasan destruktifnya.
        $this->assertStringContainsString('rep preflight error "Berkas compose $MISSING_COMPOSE tidak ditemukan', $script);
        $this->assertStringContainsString('me-recreate service yang salah', $script);
    }

    // ==================================================================
    // cli/update-report.php
    // ==================================================================

    public function testReportScriptGetsPlanValues(): void
    {
        $plan = $this->writePlan(['branch' => 'main', 'health_timeout' => 180]);

        $this->assertSame('main', trim($this->report(['get', $plan, 'branch'])['stdout']));
        $this->assertSame('180', trim($this->report(['get', $plan, 'health_timeout'])['stdout']));
        $this->assertSame('fallback', trim($this->report(['get', $plan, 'tidak_ada', 'fallback'])['stdout']));
    }

    public function testReportScriptWritesRunProgress(): void
    {
        $plan = $this->writePlan();

        $this->report(['set', $plan, 'fetch', 'running', 'Mengambil pembaruan…']);
        $run = $this->readRun();
        $this->assertSame('fetch', $run['stage']);
        $this->assertNull($run['result']);
        $this->assertArrayNotHasKey('finished_at', $run);
        $this->assertSame('20260923-120000-abc123', $run['id']);
        $this->assertSame('update', $run['mode']);
        $this->assertSame('aaaa', $run['old_sha']);

        // Tahap berikutnya MENGGABUNG (bukan menimpa) status sebelumnya.
        $this->report(['set', $plan, 'build', 'running', 'Build…']);
        $run = $this->readRun();
        $this->assertSame('build', $run['stage']);
        $this->assertSame('20260923-120000-abc123', $run['id']);

        $this->report(['set', $plan, 'finished', 'success', 'Selesai.']);
        $run = $this->readRun();
        $this->assertSame('success', $run['result']);
        $this->assertNotNull($run['finished_at']);
        $this->assertNotSame('', (string) $run['finished_at']);
    }

    public function testReportScriptRecordsFailure(): void
    {
        $plan = $this->writePlan();

        $this->report(['set', $plan, 'finished', 'error', 'composer install gagal']);
        $run = $this->readRun();

        $this->assertSame('error', $run['result']);
        $this->assertSame('composer install gagal', $run['error']);
    }

    public function testReportScriptUpdatesMutableState(): void
    {
        $plan = $this->writePlan();

        $sha = 'b' . str_repeat('0', 39);
        $this->report(['state', $plan, 'target_sha', $sha]);
        $this->assertSame($sha, $this->readRun()['target_sha']);

        $this->report(['state', $plan, 'rollback_from', $sha]);
        $this->assertSame($sha, $this->readRun()['rollback_from']);
    }

    public function testReportScriptRejectsUnknownStageResultAndKey(): void
    {
        $plan = $this->writePlan();

        $stage = $this->report(['set', $plan, 'rm -rf /', 'running', 'x']);
        $this->assertSame(1, $stage['code']);
        $this->assertStringContainsString('Tahap tidak dikenali', $stage['stderr']);

        $result = $this->report(['set', $plan, 'build', 'hacked', 'x']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Hasil tidak dikenali', $result['stderr']);

        $key = $this->report(['state', $plan, 'root', '/etc']);
        $this->assertSame(1, $key['code']);
        $this->assertStringContainsString('Kunci yang boleh diubah', $key['stderr']);

        // Nilai bukan SHA ditolak (tidak boleh masuk berkas status).
        $bad = $this->report(['state', $plan, 'target_sha', 'main; rm -rf /']);
        $this->assertSame(1, $bad['code']);
        $this->assertStringContainsString('harus SHA', $bad['stderr']);
    }

    public function testReportScriptRejectsMissingOrBrokenPlan(): void
    {
        $missing = $this->report(['get', $this->tmp . '/tidak-ada.json', 'root']);
        $this->assertSame(1, $missing['code']);
        $this->assertStringContainsString('Plan update tidak ditemukan', $missing['stderr']);

        file_put_contents($this->tmp . '/broken.json', '{bukan json');
        $broken = $this->report(['get', $this->tmp . '/broken.json', 'root']);
        $this->assertSame(1, $broken['code']);
        $this->assertStringContainsString('bukan JSON yang valid', $broken['stderr']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Inspect palsu minimal untuk `selfContext()`: network = `<project>_default`
     * (non-internal) supaya pemilihan network tidak mengaburkan uji lain.
     *
     * @param array<string,string> $labels
     * @param array<int,string>    $env
     * @param array<string,mixed>  $portBindings
     * @return array<string,mixed>
     */
    private function fakeInspect(string $name, array $labels, array $env = [], array $portBindings = []): array
    {
        $project = (string) ($labels['com.docker.compose.project'] ?? '');
        $network = $project !== '' ? $project . '_default' : 'rames_default';

        return [
            'Name' => '/' . $name,
            'Config' => ['Image' => $name, 'Labels' => $labels, 'Env' => $env],
            'HostConfig' => ['Dns' => [], 'PortBindings' => $portBindings],
            'NetworkSettings' => ['Networks' => [$network => ['IPAddress' => '172.18.0.2']]],
        ];
    }

    /**
     * Jalankan `$fn` dengan config webman dimuat dari direktori temp berisi
     * `process.php` tiruan, lalu pulihkan state `Config` semula. Dipakai untuk
     * menguji `healthUrl()` yang membaca `process.webman.transport` (satu sumber
     * skema: mode agent = TLS) tanpa menyentuh `config/process.php` nyata.
     *
     * @param array<string,mixed> $process isi `config/process.php`
     */
    private function withProcessConfig(array $process, callable $fn): mixed
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue(null);
        }

        $dir = $this->tmp . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/app.php', "<?php return [];\n");
        file_put_contents($dir . '/process.php', '<?php return ' . var_export($process, true) . ';' . PHP_EOL);
        Config::clear();
        Config::load($dir);

        try {
            return $fn();
        } finally {
            foreach ($state as $name => $value) {
                $prop = new ReflectionProperty(Config::class, $name);
                $prop->setAccessible(true);
                $prop->setValue(null, $value);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function spec(): array
    {
        return [
            'image' => 'rames-webman',
            'container' => 'rames-self-update-20260923-120000-abc123',
            'network' => 'rames_default',
            'dns' => ['8.8.8.8', '1.1.1.1'],
            'uid' => 1000,
            'gid' => 1000,
            'group_add' => 999,
            'root' => '/srv/rames',
            'script' => '/srv/rames/cli/self-update.sh',
            'report' => '/srv/rames/cli/update-report.php',
            'plan' => '/tmp/runs/x.plan.json',
            'socket' => '/var/run/docker.sock',
            'docker_binary' => 'docker',
        ];
    }

    /**
     * `UpdateService` dengan path temp + Engine palsu (selfContext tidak menyentuh
     * repo/network nyata).
     */
    private function service(DockerClient $docker): UpdateService
    {
        return new UpdateService(
            new RepoInfo($this->tmp),
            new UpdateState($this->tmp . '/check.json', $this->tmp . '/runs'),
            $docker
        );
    }

    /**
     * DockerClient palsu: hanya `inspectContainer()`/`inspectNetwork()` yang
     * dipakai `selfContext()`. `inspectNetwork()` yang tidak dikenal melempar
     * (meniru network yang tidak bisa di-inspect → status internal tidak diketahui).
     *
     * @param array<string,mixed> $inspectContainer
     * @param array<string,bool> $internal
     */
    private function fakeDocker(array $inspectContainer, array $internal): DockerClient
    {
        return new class ($inspectContainer, $internal) extends DockerClient {
            /**
             * @param array<string,mixed> $inspectContainer
             * @param array<string,bool> $internal
             */
            public function __construct(
                private readonly array $inspectContainer,
                private readonly array $internal,
            ) {
            }

            public function inspectContainer(string $id): array
            {
                return $this->inspectContainer;
            }

            public function inspectNetwork(string $id): array
            {
                if (!array_key_exists($id, $this->internal)) {
                    throw new RuntimeException('network tidak ditemukan: ' . $id);
                }

                return ['Name' => $id, 'Internal' => $this->internal[$id]];
            }
        };
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function writePlan(array $overrides = []): string
    {
        $plan = array_merge([
            'id' => '20260923-120000-abc123',
            'mode' => 'update',
            'root' => '/srv/rames',
            'branch' => 'main',
            'project' => 'rames',
            'service' => 'webman',
            'old_sha' => 'aaaa',
            'target_sha' => '',
            'health_url' => 'http://rames-webman:8787/healthz',
            'health_timeout' => 180,
            'rollback_timeout' => 180,
            'run_dir' => $this->tmp . '/runs',
            'log_file' => $this->tmp . '/runs/20260923-120000-abc123.log',
            'actor' => 'admin',
        ], $overrides);

        $path = $this->tmp . '/plan.json';
        file_put_contents($path, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function readRun(): array
    {
        $raw = (string) file_get_contents($this->tmp . '/runs/run.json');
        $data = json_decode($raw, true);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * Jalankan cli/update-report.php sebagai subproses.
     *
     * @param array<int,string> $args
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    private function report(array $args): array
    {
        return (new ProcessRunner())->run(
            array_merge([PHP_BINARY, $this->root . '/cli/update-report.php'], $args),
            $this->root,
            30
        );
    }
}
