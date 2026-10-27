<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render view multi-server (F3) — pemilihan server: admin **atau** member
 * bergrant `server_select`; member tanpa grant terkunci ke server lokal.
 *
 * View repo ini TIDAK bisa dirender di test biasa: template memanggil helper
 * global yang butuh session/request Webman (`csrf_token()`, `current_user()`,
 * `is_admin()`, `flash_pull()`, `app_can()`, ...). Karena itu tiap test berjalan
 * di **proses terpisah** dengan helper global di-stub lewat berkas temp
 * (pola `tests/ServerViewTest.php`), sehingga stub tidak bocor ke tes lain.
 *
 * Kontrak payload yang diuji:
 *  - `/monitor`      → `MonitorController::index()` (+ `data.servers[]` dari `overview()`);
 *  - `/networks`     → `NetworkController::index()` (`$rows`/`$servers`/`$serverId`/`$serverName`);
 *  - `/database`     → `DatabaseController::index()` (baris lokal tanpa `server_id`, baris agent memilikinya);
 *  - detail app      → `AppController::show()` (`$app['server_id']`, opsional `$serverName`).
 *
 * Yang dibuktikan:
 *  (i)   `/monitor`: kartu "Semua Server" + template baris ber-badge
 *        online/offline/unknown (server offline = offline, bukan error);
 *        `data-is-admin` benar; `monitor.js` menaikkan cache-bust & memuat
 *        pemetaan offline serta filter non-admin;
 *  (ii)  `/networks`: pemilih server dirender bila controller mengirim daftar
 *        server (admin **atau** member bergrant `server_select`), form POST
 *        membawa field `server`, tautan detail mempertahankan `?server=`;
 *  (iii) `/networks` member tanpa grant: controller mengirim `$servers` kosong
 *        (`ServerAccess::visibleServers()` tanpa app/grant) sehingga view tidak
 *        merender pemilih maupun nama server lain;
 *  (iv)  `/database`: kolom Server + penanda baris lokal vs agent;
 *  (v)   detail app: badge Server di header + baris Info + catatan tab Network
 *        untuk app server agent; app lokal tanpa badge mencolok;
 *  (vi)  tidak ada token/PEM/fingerprint/base_url server yang bocor ke HTML.
 *
 * Test ini hanya merender berkas view; tidak menyentuh store, Docker, atau jaringan.
 */
class MultiServerViewTest extends TestCase
{
    /** Akar view (`app/view`) tanpa bergantung pada helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    /** Nilai yang **tidak boleh** muncul di HTML/JS mana pun (rahasia/endpoint agent). */
    private const SAMPLE_TOKEN = 'agt_uji_multiserver_5c1f9a';

    private const SAMPLE_BASE_URL = 'https://10.0.0.12:8443';

    private const SAMPLE_PIN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00';

    /**
     * Stub helper global (namespace global, guard `function_exists`).
     * Hanya di-`require` dari test ber-`RunInSeparateProcess`.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
        /**
         * Stub helper dashboard untuk smoke render view (tests/MultiServerViewTest.php).
         * Hanya hidup di proses anak proses-terpisah PHPUnit.
         */
        namespace {
            if (!function_exists('e')) {
                function e(mixed $value): string
                {
                    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
            if (!function_exists('csrf_token')) {
                function csrf_token(): string
                {
                    return 'smoke-csrf-token';
                }
            }
            if (!function_exists('csrf_field')) {
                function csrf_field(): string
                {
                    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
                }
            }
            if (!function_exists('current_user')) {
                function current_user(): ?array
                {
                    return ($GLOBALS['RAMES_VIEW_ADMIN'] ?? true)
                        ? ['id' => 'u1', 'username' => 'admin', 'role' => 'admin']
                        : ['id' => 'u2', 'username' => 'budi', 'role' => 'member'];
                }
            }
            if (!function_exists('is_admin')) {
                function is_admin(?array $user = null): bool
                {
                    return (bool) ($GLOBALS['RAMES_VIEW_ADMIN'] ?? true);
                }
            }
            if (!function_exists('flash_pull')) {
                function flash_pull(): ?array
                {
                    return null;
                }
            }
            if (!function_exists('brand_name')) {
                function brand_name(): string
                {
                    return 'Rames';
                }
            }
            if (!function_exists('brand_logo_url')) {
                function brand_logo_url(): string
                {
                    return '/logo-small.png';
                }
            }
            if (!function_exists('flash_set')) {
                function flash_set(string $type, string $message): void
                {
                }
            }
            if (!function_exists('update_badge')) {
                function update_badge(): array
                {
                    return ['available' => false];
                }
            }
            if (!function_exists('current_credit_balance')) {
                function current_credit_balance(): ?float
                {
                    return null;
                }
            }
            if (!function_exists('format_credits')) {
                function format_credits(float $value, int $decimals = 2): string
                {
                    return number_format($value, $decimals, ',', '.');
                }
            }
            if (!function_exists('app_role')) {
                function app_role(array $app, ?array $user = null): ?string
                {
                    return 'owner';
                }
            }
            if (!function_exists('app_can')) {
                function app_can(string $ability, array $app, ?array $user = null): bool
                {
                    return true;
                }
            }
            if (!function_exists('app_role_label')) {
                function app_role_label(?string $role): string
                {
                    return ucfirst((string) $role);
                }
            }
            if (!function_exists('user_names')) {
                function user_names(): array
                {
                    return ['u1' => 'admin', 'u2' => 'budi'];
                }
            }
            if (!function_exists('template_logo_map')) {
                function template_logo_map(?string $dir = null): array
                {
                    return [];
                }
            }
            if (!function_exists('app_subdomain')) {
                function app_subdomain(string|array|null $value = null, ?\app\library\Deploy\ServerResolver $resolver = null): string
                {
                    $label = is_array($value) ? (string) ($value['subdomain'] ?? '') : (string) $value;

                    return $label === '' ? '' : $label . '.contoh.id';
                }
            }
            if (!function_exists('app_subdomain_of')) {
                function app_subdomain_of(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): string
                {
                    return app_subdomain($app, $resolver);
                }
            }
            if (!function_exists('app_subdomain_label')) {
                function app_subdomain_label(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): string
                {
                    return (string) ($app['subdomain'] ?? '');
                }
            }
            if (!function_exists('app_subdomain_is_custom')) {
                function app_subdomain_is_custom(array $app, ?\app\library\Deploy\ServerResolver $resolver = null): bool
                {
                    return false;
                }
            }
            if (!function_exists('app_subdomain_valid')) {
                function app_subdomain_valid(string|array|null $value, ?\app\library\Deploy\ServerResolver $resolver = null): bool
                {
                    return true;
                }
            }
        }
        PHP;

    protected function setUp(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = true;
    }

    // ------------------------------------------------------------------
    // (i) /monitor
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMonitorRendersServerCardWithOfflineBadgeForAdmin(): void
    {
        $html = $this->renderView('monitor/index.php', [
            'pageTitle' => 'Monitor',
            'active' => 'monitor',
            'isAdmin' => true,
            'appCount' => 3,
            'pollMs' => 7000,
        ]);

        // Kartu ringkasan per server (diisi JS dari `data.servers[]`).
        self::assertStringContainsString('data-m="servers-card"', $html);
        self::assertStringContainsString('<tbody data-m="servers">', $html);
        self::assertStringContainsString('data-is-admin="1"', $html);

        // Tiga varian status baku ada di template — server `offline` tampil sebagai
        // offline (bukan error), status tak dikenal tidak merakit class dari data.
        self::assertMatchesRegularExpression('/data-ms="status-offline">offline</', $html);
        self::assertStringContainsString('data-ms="status-online">online<', $html);
        self::assertStringContainsString('data-ms="status-unknown">unknown<', $html);
        self::assertStringContainsString('data-ms="badge-disabled">nonaktif<', $html);

        // Tidak ada data server yang dibakar ke HTML (nama/URL agent tidak dirender).
        self::assertStringNotContainsString(self::SAMPLE_BASE_URL, $html);
        self::assertStringNotContainsString('vps-jakarta', $html);

        // Cache-bust JS ikut naik (perubahan render server + storage di monitor.js).
        self::assertStringContainsString('src="/js/monitor.js?v=3"', $html);

        // Kartu storage server (filesystem host) + kolom storage per server.
        self::assertStringContainsString('Storage server', $html);
        self::assertStringContainsString('data-m="host-disk"', $html);
        self::assertStringContainsString('data-ms="disk-host"', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMonitorMemberGetsNoServerAdminControls(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;

        $html = $this->renderView('monitor/index.php', [
            'pageTitle' => 'Monitor',
            'active' => 'monitor',
            'isAdmin' => false,
            'appCount' => 1,
            'pollMs' => 0,
        ]);

        self::assertStringContainsString('data-is-admin="0"', $html);
        self::assertStringNotContainsString('href="/servers"', $html, 'Tautan registry server hanya untuk admin');
        self::assertStringContainsString('tidak ditampilkan di sini', $html);
        self::assertStringContainsString('data-m="servers-card"', $html, 'Rangka tabel tetap ada; JS yang menyaring barisnya');

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMonitorJsKeepsServerContract(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../public/js/monitor.js');

        // Offline dipetakan ke badge offline, dan hanya tiga varian status baku yang dipakai.
        self::assertStringContainsString("status === 'offline' ? 'offline'", $js);
        self::assertStringContainsString("ms(frag, 'status-' + key)", $js);

        // Non-admin: hanya server lokal / server tempat ia punya container terlihat.
        self::assertStringContainsString("getAttribute('data-is-admin')", $js);
        self::assertStringContainsString('Array.isArray(server.containers) && server.containers.length > 0', $js);

        // Pesan galat mentah dari server tidak pernah dirender (bisa memuat host/token).
        self::assertStringContainsString('if (server.error)', $js);
        self::assertStringNotContainsString("'note'), server.error", $js);
        self::assertStringNotContainsString("'note'), String(server.error", $js);

        // Polling hanya metrik host lokal; baris per server tidak ikut dipoll diam-diam.
        self::assertStringContainsString('function pollHost()', $js);
    }

    // ------------------------------------------------------------------
    // (ii)+(iii) /networks
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNetworksAdminSeesServerPickerAndFormsCarryServer(): void
    {
        $html = $this->renderView('network/index.php', [
            'rows' => [$this->networkRow('shared-app', true)],
            'engineError' => null,
            'canManage' => true,
            'serverId' => 'vps-jakarta',
            'serverName' => 'vps-jakarta',
            'servers' => $this->serverOptions(),
        ]);

        self::assertStringContainsString('id="network-server"', $html);
        self::assertStringContainsString('name="server"', $html);
        self::assertStringContainsString('>vps-surabaya</option>', $html);
        self::assertMatchesRegularExpression('/value="vps-jakarta"\s+selected/', $html);

        // Setiap form POST membawa server terpilih (controller membaca body `server`).
        self::assertMatchesRegularExpression(
            '/action="\/networks\/create"[\s\S]{0,300}name="server" value="vps-jakarta"/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/action="\/networks\/shared-app\/delete"[\s\S]{0,300}name="server" value="vps-jakarta"/',
            $html
        );

        // Tautan detail mempertahankan cakupan server.
        self::assertStringContainsString('href="/networks/shared-app?server=vps-jakarta"', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNetworksMemberWithoutGrantGetsNoServerPayload(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;

        // Member tanpa grant: `ServerAccess::visibleServers()` mengembalikan `[]`
        // (tidak punya app di server agent) — view tidak menambah gate `is_admin()`
        // sendiri, jadi payload kosong itulah yang menyembunyikan pemilih.
        $html = $this->renderView('network/index.php', [
            'rows' => [$this->networkRow('shared-app', false)],
            'engineError' => null,
            'canManage' => false,
            'serverId' => '',
            'serverName' => '',
            'servers' => [],
        ]);

        self::assertStringNotContainsString('name="server"', $html, 'Tanpa pemilih/hidden server saat daftar kosong');
        self::assertStringNotContainsString('<select', $html);
        self::assertStringNotContainsString('vps-jakarta', $html);
        self::assertStringNotContainsString('vps-surabaya', $html);
        self::assertStringNotContainsString('?server=', $html);
        self::assertStringContainsString('hanya untuk admin', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNetworksMemberWithGrantSeesServerPicker(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;

        // Member bergrant `server_select`: controller mengirim server yang boleh
        // dipilih (`ServerAccess::selectableServers()`); view merendernya tanpa
        // gate admin tambahan.
        $html = $this->renderView('network/index.php', [
            'rows' => [$this->networkRow('shared-app', true)],
            'engineError' => null,
            'canManage' => false,
            'serverId' => 'vps-jakarta',
            'serverName' => 'vps-jakarta',
            'servers' => $this->serverOptions(),
        ]);

        self::assertStringContainsString('id="network-server"', $html);
        self::assertStringContainsString('name="server"', $html);
        self::assertMatchesRegularExpression('/value="vps-jakarta"\s+selected/', $html);
        self::assertStringContainsString('Menampilkan network di server', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNetworkDetailCarriesServerInEveryForm(): void
    {
        $html = $this->renderView('network/detail.php', [
            'id' => 'shared-app',
            'name' => 'shared-app',
            'network' => [
                'Id' => 'abc123def4567890',
                'Name' => 'shared-app',
                'Driver' => 'bridge',
                'Scope' => 'local',
                'Internal' => false,
                'Attachable' => true,
                'Created' => '2026-10-14T07:00:00Z',
            ],
            'attached' => [['name' => 'toko-web-1', 'ipv4' => '172.20.0.5', 'ipv6' => '', 'mac' => 'aa:bb:cc:dd:ee:ff']],
            'candidates' => [['name' => 'billing-web-1', 'status' => 'running']],
            'engineError' => null,
            'builtin' => false,
            'canManage' => true,
            'serverId' => 'vps-jakarta',
            'serverName' => 'vps-jakarta',
            'servers' => $this->serverOptions(),
        ]);

        // Hapus + connect + disconnect semua di server itu.
        self::assertMatchesRegularExpression(
            '/action="\/networks\/shared-app\/delete"[\s\S]{0,300}name="server" value="vps-jakarta"/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/action="\/networks\/shared-app\/connect"[\s\S]{0,300}name="server" value="vps-jakarta"/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/action="\/networks\/shared-app\/disconnect"[\s\S]{0,300}name="server" value="vps-jakarta"/',
            $html
        );
        self::assertStringContainsString('Network ini berada di server <strong>vps-jakarta</strong>', $html);
        self::assertStringContainsString('href="/networks?server=vps-jakarta"', $html, 'Breadcrumb mempertahankan cakupan server');

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // (iv) /database
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDatabaseShowsServerColumnWithAgentMarker(): void
    {
        $html = $this->renderView('db/index.php', [
            'rows' => [$this->dbRowLocal(), $this->dbRowAgent()],
            'engineError' => null,
            'isAdmin' => true,
            'ownerNamesByApp' => ['app1' => 'admin', 'app2' => 'budi'],
        ]);

        self::assertStringContainsString('<th>Server</th>', $html);
        self::assertMatchesRegularExpression('/billing-db-1[\s\S]{0,600}>lokal</', $html);
        self::assertMatchesRegularExpression('/toko-db-1[\s\S]{0,600}badge-deploying[\s\S]{0,200}vps-jakarta/', $html);

        $thead = substr($html, (int) strpos($html, '<thead>'), (int) strpos($html, '</thead>') - (int) strpos($html, '<thead>'));
        self::assertSame(7, preg_match_all('/<th[\s>]/', $thead), 'Kolom admin: Container, Image, Status, Server, Kepemilikan, Owner, Aksi');

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDatabaseMemberHasColumnWithoutOwner(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;

        $html = $this->renderView('db/index.php', [
            'rows' => [$this->dbRowAgent()],
            'engineError' => null,
            'isAdmin' => false,
            'ownerNamesByApp' => [],
        ]);

        self::assertStringContainsString('<th>Server</th>', $html);
        self::assertStringNotContainsString('<th>Owner</th>', $html);
        self::assertStringContainsString('menandai container yang berjalan di', $html);

        $thead = substr($html, (int) strpos($html, '<thead>'), (int) strpos($html, '</thead>') - (int) strpos($html, '<thead>'));
        self::assertSame(6, preg_match_all('/<th[\s>]/', $thead));

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // (v) detail app
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppDetailShowsServerBadgeAndNetworkScopeNote(): void
    {
        $html = $this->renderView('app/detail.php', $this->detailVars($this->agentApp(), ['serverName' => 'vps-jakarta']));

        self::assertStringContainsString('Server: vps-jakarta', $html, 'Badge Server di header untuk app server agent');
        self::assertMatchesRegularExpression('/<dt class="k">Server<\/dt>[\s\S]{0,400}vps-jakarta/', $html);
        self::assertStringContainsString('server tempat app ini berjalan (vps-jakarta)', $html);
        self::assertStringContainsString('tidak</strong> dapat bergabung ke network ini', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppDetailFallsBackToServerIdWhenNameMissing(): void
    {
        // Controller detail belum mengirim `$serverName` — view wajib tetap jujur
        // (menampilkan id server, bukan "lokal" palsu) dan tidak boleh warning.
        $html = $this->renderView('app/detail.php', $this->detailVars($this->agentApp()));

        self::assertStringContainsString('Server: vps-jakarta', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppDetailLocalHasNoServerBadge(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;
        $app = $this->agentApp();
        $app['server_id'] = '';

        $html = $this->renderView('app/detail.php', $this->detailVars($app));

        self::assertStringNotContainsString('badge text-bg-info">Server:', $html);
        self::assertMatchesRegularExpression('/<dt class="k">Server<\/dt>[\s\S]{0,400}>lokal</', $html);
        self::assertStringContainsString('server tempat app ini berjalan (server lokal)', $html);

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** @return array<int,array{id:string,name:string}> */
    private function serverOptions(): array
    {
        return [
            ['id' => 'local', 'name' => 'local'],
            ['id' => 'vps-jakarta', 'name' => 'vps-jakarta'],
            ['id' => 'vps-surabaya', 'name' => 'vps-surabaya'],
        ];
    }

    /** @return array<string,mixed> */
    private function networkRow(string $name, bool $free): array
    {
        return [
            'id' => 'id-' . $name,
            'name' => $name,
            'driver' => 'bridge',
            'scope' => 'local',
            'created_at' => '2026-10-14T07:00:00Z',
            'internal' => false,
            'attachable' => true,
            'project' => '',
            'container_count' => $free ? 0 : 2,
            'builtin' => false,
            'in_use' => !$free,
            'managed' => true,
            'mine' => true,
            'can_delete' => $free,
        ];
    }

    /** @return array<string,mixed> */
    private function dbRowLocal(): array
    {
        return [
            'container_name' => 'billing-db-1',
            'image' => 'mariadb:11',
            'state' => 'running',
            'status' => 'Up 3 days',
            'owned' => true,
            'app_id' => 'app1',
            'app_name' => 'billing',
            'can_manage' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function dbRowAgent(): array
    {
        return [
            'container_name' => 'toko-db-1',
            'image' => 'mysql:8',
            'state' => 'running',
            'status' => 'Up 2 hours',
            'owned' => true,
            'app_id' => 'app2',
            'app_name' => 'toko',
            'server_id' => 'vps-jakarta',
            'server_name' => 'vps-jakarta',
            'can_manage' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function agentApp(): array
    {
        return [
            'id' => 'app2',
            'name' => 'toko',
            'status' => 'running',
            'server_id' => 'vps-jakarta',
            'subdomain' => 'toko',
            'primary_service' => 'web',
            'primary_port' => 80,
            'local_path' => '/srv/apps/toko',
            'source' => 'compose',
            'compose_files' => ['docker-compose.yml'],
            'compose_content' => "services:\n  web:\n    image: nginx:alpine\n",
            'containers' => [[
                'container_name' => 'toko-web-1',
                'service' => 'web',
                'status' => 'running',
                'host_port' => 31001,
                'internal_port' => 80,
            ]],
            'external_networks' => [],
            'deploy_history' => [],
        ];
    }

    /**
     * Variabel sesuai kontrak `AppController::show()` (partial tab lain ikut render
     * di berkas yang sama, jadi semua konteks opsionalnya diisi minimal).
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function detailVars(array $app, array $extra = []): array
    {
        return [
            'app' => $app,
            'live' => [[
                'container_name' => 'toko-web-1',
                'status' => 'running',
                'host_port' => 31001,
                'internal_port' => 80,
                'ports' => [],
            ]],
            'volumes' => [],
            'availableNetworks' => ['shared-app'],
            'sshPubkey' => '',
            'guideHtml' => '',
            'deployHistory' => [],
            'activeSha' => '',
            'dbContainers' => [],
            'access' => [
                'role' => 'owner',
                'abilities' => array_fill_keys([
                    'view', 'logs', 'operate', 'deploy', 'stop', 'env', 'compose', 'network', 'domain',
                    'ssl', 'routes', 'terminal', 'database', 'sharing', 'files', 'delete', 'limits',
                ], true),
            ],
            'compose' => [
                'main_file' => 'docker-compose.yml',
                'content' => "services:\n  web:\n    image: nginx:alpine\n",
                'files' => ['docker-compose.yml'],
            ],
            'resourceLimits' => [
                'canManage' => true,
                'services' => ['web'],
                'saved' => [],
                'repo' => [],
                'hasSaved' => false,
                'error' => null,
            ],
            'billingEstimate' => ['enabled' => false, 'applies' => false],
        ] + $extra;
    }

    /**
     * Render view dengan variabel sesuai kontrak controller (tanpa HTTP/webman).
     *
     * @param array<string,mixed> $vars
     */
    private function renderView(string $relative, array $vars): string
    {
        $this->installStubs();

        $file = self::VIEW_DIR . '/' . $relative;
        self::assertFileExists($file, 'View tidak ditemukan: ' . $relative);

        extract($vars, EXTR_SKIP);

        ob_start();
        try {
            include $file;
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }

        return (string) ob_get_clean();
    }

    /**
     * Pasang stub helper global lewat berkas temp ber-namespace global, lalu
     * `require` (fungsi tetap hidup setelah berkasnya dihapus).
     */
    private function installStubs(): void
    {
        if (function_exists('csrf_field')) {
            return; // sudah terpasang di proses anak ini
        }

        $file = sys_get_temp_dir() . '/rames-multiserver-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }

    /**
     * HTML hasil render tidak boleh memuat jejak rahasia/endpoint agent: token,
     * `Bearer`, fingerprint, PEM, atau base URL agent.
     */
    private function assertNoSecretLeak(string $html): void
    {
        foreach ([self::SAMPLE_TOKEN, self::SAMPLE_BASE_URL, self::SAMPLE_PIN, 'BEGIN CERTIFICATE', 'Bearer'] as $needle) {
            self::assertStringNotContainsString($needle, $html, 'HTML view tidak boleh memuat: ' . $needle);
        }
        self::assertStringNotContainsString('tls_fingerprint', $html);
        self::assertStringNotContainsString('tls_cert_pem', $html);
    }
}
