<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render UI multi-server untuk halaman **`/volumes`** & **`/apps`**:
 *  - `/volumes`: kolom **Server** menampilkan nama server (badge `agent`) +
 *    volume lokal ber-badge `lokal`; baris server agent tidak menawarkan purge
 *    (tanpa checkbox/pemilih ukuran); volume yatim lokal tetap punya checkbox;
 *    peringatan server tak terjangkau tampil sebagai alert, bukan galat halaman;
 *  - `/apps`: kolom **Server** (nama, bukan id) + **filter server** hanya untuk
 *    admin (non-admin tidak menerima daftar server lain); tautan tab
 *    mempertahankan filter `?server=`; tidak ada token/base_url yang bocor.
 *
 * View repo ini tidak bisa dirender di tes biasa: template memanggil helper
 * global yang butuh konteks Webman (`csrf_token()`, `current_user()`,
 * `flash_pull()`, ...). Karena itu tes berjalan di proses terpisah dengan stub
 * helper global lewat berkas temp (pola `MultiServerViewTest`) sehingga stub
 * tidak bocor ke tes lain. PHPUnit `failOnWarning` membuat variabel template
 * yang hilang (Undefined variable) menggagalkan tes ini.
 */
class VolumeAppServerViewTest extends TestCase
{
    /** Akar view (`app/view`) tanpa bergantung pada helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    /** Nilai yang **tidak boleh** muncul di HTML (rahasia/endpoint server). */
    private const SAMPLE_TOKEN = 'tok-uji-volume-app-2f9a1c';

    private const SAMPLE_BASE_URL = 'https://10.0.0.13:8443';

    /**
     * Stub helper global (namespace global, guard `function_exists`).
     * Hanya di-`require` dari tes ber-`RunInSeparateProcess`.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
        if (!function_exists('e')) {
            function e(mixed $value): string
            {
                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        if (!function_exists('app_path')) {
            // `app_path()`/`config()` datang dari framework Webman (BASE_PATH sudah
            // terdefinisi lewat autoload) — sengaja TIDAK di-stub.
        }
        if (!function_exists('csrf_token')) {
            function csrf_token(): string
            {
                return 'smoke-token';
            }
        }
        if (!function_exists('csrf_field')) {
            function csrf_field(): string
            {
                return '<input type="hidden" name="_token" value="smoke-token">';
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
                return ($GLOBALS['RAMES_VIEW_ADMIN'] ?? true);
            }
        }
        if (!function_exists('flash_pull')) {
            function flash_pull(): ?array
            {
                return null;
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
            function format_credits(float $credits, int $decimals = 2): string
            {
                return number_format($credits, $decimals, ',', '.');
            }
        }
        if (!function_exists('user_names')) {
            function user_names(): array
            {
                return ['u1' => 'admin', 'u2' => 'budi', 'u5' => 'siti'];
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
        if (!function_exists('app_subdomain')) {
            function app_subdomain(string|array|null $value = null, mixed $resolver = null): string
            {
                $label = is_array($value) ? (string) ($value['subdomain'] ?? '') : (string) $value;

                return $label === '' ? '' : $label . '.contoh.id';
            }
        }
        if (!function_exists('app_subdomain_of')) {
            function app_subdomain_of(array $app, mixed $resolver = null): string
            {
                return app_subdomain($app);
            }
        }
        if (!function_exists('app_subdomain_label')) {
            function app_subdomain_label(array $app, mixed $resolver = null): string
            {
                return (string) ($app['subdomain'] ?? '');
            }
        }
        if (!function_exists('template_logo_map')) {
            function template_logo_map(?string $dir = null): array
            {
                return [];
            }
        }
        PHP;

    private string $stubFile = '';

    protected function tearDown(): void
    {
        if ($this->stubFile !== '' && is_file($this->stubFile)) {
            @unlink($this->stubFile);
        }
        $this->stubFile = '';
    }

    // ==================================================================
    // /volumes
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVolumePageShowsServerColumnAndAgentRowsWithoutPurgeControls(): void
    {
        $html = $this->renderView('volume/index.php', [
            'rows' => [
                $this->volumeRow('a_yatim', 'app-lama', 'local', '', 'local', true, true),
                $this->volumeRow('lokal_data', 'app-lokal', 'local', '', 'local', false, false),
                $this->volumeRow('jakarta_data', 'app-jakarta', 'agent', 'srv-jakarta', 'vps-jakarta', false, false),
                $this->volumeRow('jakarta_yatim', 'app-hilang', 'agent', 'srv-jakarta', 'vps-jakarta', true, false),
            ],
            'engineError' => null,
            'serverErrors' => [],
            'canPurge' => true,
        ]);

        self::assertStringContainsString('<th>Server</th>', $html);
        // Baris lokal ber-badge "lokal"; baris agent menampilkan nama server.
        self::assertMatchesRegularExpression('/lokal_data[\s\S]{0,400}>lokal</', $html);
        self::assertMatchesRegularExpression('/jakarta_data[\s\S]{0,400}badge-deploying[\s\S]{0,200}vps-jakarta/', $html);

        // Aksi purge hanya untuk volume yatim server lokal.
        self::assertSame(
            1,
            preg_match_all('/name="volumes\[\]" value="([^"]+)"/', $html, $matches),
            'hanya satu checkbox: volume yatim server lokal'
        );
        self::assertSame(['a_yatim'], $matches[1]);
        self::assertStringContainsString('hapus di server itu', $html, 'baris yatim agent diberi catatan');
        self::assertStringContainsString('yatim di server agent, hapus di server itu', $html);
        self::assertStringContainsString('Hapus SEMUA volume yatim di server lokal (1 volume)', $html);

        // Baris agent tidak memakai pengukuran ukuran Engine lokal.
        self::assertSame(2, preg_match_all('/data-volume-size=/', $html), 'hanya 2 volume lokal yang diukur');
        self::assertSame(0, preg_match_all('/data-volume-size="jakarta_[^"]*"/', $html));
        self::assertStringContainsString('4 volume di 2 server (2 yatim)', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVolumePageShowsOfflineServerWarningWithoutBreakingPage(): void
    {
        $html = $this->renderView('volume/index.php', [
            'rows' => [$this->volumeRow('lokal_data', 'app-lokal', 'local', '', 'local', false, false)],
            'engineError' => null,
            'serverErrors' => ['Server "vps-mati": Agent tidak bisa dihubungi.'],
            'canPurge' => true,
        ]);

        self::assertStringContainsString('Server &quot;vps-mati&quot;: Agent tidak bisa dihubungi.', $html);
        self::assertStringContainsString('lokal_data', $html, 'baris server lain tetap tampil');
        self::assertStringNotContainsString('Undefined', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVolumePageForMemberHidesPurgeButtons(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;

        $html = $this->renderView('volume/index.php', [
            'rows' => [$this->volumeRow('lokal_data', 'app-lokal', 'local', '', 'local', false, false)],
            'engineError' => 'Tidak dapat mengakses Docker Engine: socket tidak ada',
            'serverErrors' => [],
            'canPurge' => false,
        ]);

        self::assertStringContainsString('Tidak dapat mengakses Docker Engine', $html);
        self::assertStringContainsString('hanya admin', $html);
        self::assertStringNotContainsString('Purge semua yatim', $html);
    }

    // ==================================================================
    // /apps
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppsPageAdminGetsServerColumnAndFilter(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = true;

        $html = $this->renderView('app/index.php', [
            'apps' => [
                $this->appRow('app-jakarta', 'srv-jakarta', 'vps-jakarta', 'agent'),
                $this->appRow('app-lokal', 'local', 'local', 'local'),
            ],
            'roles' => ['a1' => 'admin', 'a2' => 'admin'],
            'ownedIds' => ['a1' => true, 'a2' => true],
            'scope' => 'all',
            'counts' => ['mine' => 2, 'shared' => 0, 'all' => 2],
            'isAdmin' => true,
            'ownerNames' => ['u1' => 'admin'],
            'serverNames' => ['local' => 'local', 'srv-jakarta' => 'vps-jakarta'],
            'serverOptions' => [
                ['id' => 'local', 'name' => 'local'],
                ['id' => 'srv-jakarta', 'name' => 'vps-jakarta'],
            ],
            'serverFilter' => 'srv-jakarta',
        ]);

        self::assertStringContainsString('<th>Server</th>', $html);
        // Nama server (bukan id mentah) untuk baris agent; "lokal" untuk sisanya.
        self::assertMatchesRegularExpression('/app-jakarta[\s\S]{0,600}badge-deploying[\s\S]{0,200}vps-jakarta/', $html);
        self::assertMatchesRegularExpression('/app-lokal[\s\S]{0,600}>lokal</', $html);
        self::assertStringNotContainsString('badge-deploying</span> <span class="mono d-block">srv-jakarta<', $html);

        // Pemilih filter (admin) + nilai terpilih + tab mempertahankan filter.
        self::assertStringContainsString('id="server-filter"', $html);
        self::assertStringContainsString('<option value="local">local</option>', $html);
        self::assertStringContainsString('<option value="srv-jakarta" selected>vps-jakarta</option>', $html);
        self::assertStringContainsString('href="/apps?scope=mine&amp;server=srv-jakarta"', $html);
        self::assertStringContainsString('name="scope" value="all"', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppsPageMemberGetsNoServerListAndNoPicker(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = false;

        $html = $this->renderView('app/index.php', [
            'apps' => [$this->appRow('app-jakarta', 'srv-jakarta', 'vps-jakarta', 'agent')],
            'roles' => ['a1' => 'owner'],
            'ownedIds' => ['a1' => true],
            'scope' => 'all',
            'counts' => ['mine' => 1, 'shared' => 0, 'all' => 1],
            'isAdmin' => false,
            'ownerNames' => [],
            'serverNames' => ['srv-jakarta' => 'vps-jakarta'],
            'serverOptions' => [],
            'serverFilter' => '',
        ]);

        self::assertStringNotContainsString('id="server-filter"', $html, 'non-admin tidak menerima pemilih server');
        self::assertStringNotContainsString('<th>Owner</th>', $html);
        self::assertMatchesRegularExpression('/app-jakarta[\s\S]{0,600}vps-jakarta/', $html);
        self::assertStringNotContainsString('srv-rahasia', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppsPageShowsEmptyServerFilterMessage(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = true;

        $html = $this->renderView('app/index.php', [
            'apps' => [],
            'roles' => [],
            'ownedIds' => [],
            'scope' => 'all',
            'counts' => ['mine' => 0, 'shared' => 0, 'all' => 0],
            'isAdmin' => true,
            'ownerNames' => [],
            'serverNames' => ['srv-jakarta' => 'vps-jakarta'],
            'serverOptions' => [['id' => 'srv-jakarta', 'name' => 'vps-jakarta']],
            'serverFilter' => 'srv-jakarta',
        ]);

        self::assertStringContainsString('Tidak ada app di server yang dipilih', $html);
        self::assertStringContainsString('<option value="srv-jakarta" selected>vps-jakarta</option>', $html);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * @return array<string,mixed>
     */
    private function volumeRow(
        string $name,
        string $project,
        string $kind,
        string $serverId,
        string $serverName,
        bool $orphaned,
        bool $canPurge
    ): array {
        return [
            'name' => $name,
            'project' => $project,
            'driver' => $kind === 'agent' ? '' : 'local',
            'mountpoint' => $kind === 'agent' ? '' : '/var/lib/docker/volumes/' . $name,
            'created_at' => $kind === 'agent' ? '' : '2026-10-14T10:00:00Z',
            'orphaned' => $orphaned,
            'server_id' => $serverId,
            'server_name' => $serverName,
            'server_kind' => $kind,
            'can_purge' => $canPurge,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function appRow(string $name, string $serverId, string $serverName, string $kind): array
    {
        return [
            'id' => 'a-' . $name,
            'name' => $name,
            'owner_id' => 'u1',
            'status' => 'running',
            'subdomain' => $name . '.contoh.id',
            'custom_domain' => '',
            'containers' => [],
            'updated_at' => '2026-10-14T10:00:00Z',
            'deploy' => ['replicas' => 1],
            'server_id' => $serverId,
            'server_name' => $serverName,
            'server_kind' => $kind,
        ];
    }

    private function assertNoSecretLeak(string $html): void
    {
        self::assertStringNotContainsString(self::SAMPLE_TOKEN, $html);
        self::assertStringNotContainsString(self::SAMPLE_BASE_URL, $html);
        self::assertStringNotContainsString('tls_cert_pem', $html);
        self::assertStringNotContainsString('BEGIN CERTIFICATE', $html);
    }

    /**
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
     * Stub helper global lewat berkas temp ber-namespace global, lalu `require`
     * (fungsi tetap hidup setelah berkasnya dihapus). `__DIR__` pada stub
     * diarahkan ke akar repo supaya `app_path()` menunjuk `app/view/partials`.
     */
    private function installStubs(): void
    {
        if (function_exists('csrf_field')) {
            return; // sudah terpasang di proses anak ini
        }

        $root = dirname(__DIR__);
        $this->stubFile = sys_get_temp_dir() . '/rames-volume-app-view-stubs-' . getmypid() . '.php';
        file_put_contents($this->stubFile, self::STUB_SOURCE);
        require $this->stubFile;
    }
}
