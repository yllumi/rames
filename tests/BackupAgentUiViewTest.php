<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render UI multi-server "sisa tampilan" (F4 lanjutan):
 *  (i)  `/backups` — catatan unduh `.sql` volume server agent tampil di kerangka
 *       halaman (admin & non-admin), cache-bust `backup.js` naik, tab arsip tetap
 *       admin-only, tanpa token/base_url internal;
 *  (ii) detail app **server agent** — tombol **Run** tampil membawa `data-server`
 *       (nama server) + slot konteks `#run-server` di modal, sedangkan app
 *       **lokal** tidak berubah (tanpa `data-server`/catatan server);
 *  (iii) logika murni di `public/js/backup.js` (tautan unduh `.sql`) dan
 *        `public/js/app-terminal.js` (pesan galat Run) dijalankan lewat `node`
 *        dari blok bertanda: tautan unduh HANYA untuk volume server agent
 *        berstrategi `dump` oleh user berhak backup, dan pesan galat agent
 *        (batas waktu/nonaktif/tak didukung) jelas.
 *
 * View repo ini tidak bisa dirender di tes biasa (template memanggil helper global
 * yang butuh konteks Webman: `csrf_token()`, `current_user()`, ...), karena itu
 * tes view berjalan di proses terpisah dengan stub helper global lewat berkas
 * temp (pola `MultiServerViewTest`/`VolumeAppServerViewTest`). `failOnWarning`
 * membuat variabel template yang hilang menggagalkan tes.
 *
 * Tes TIDAK menyentuh controller/library, store, Docker, atau jaringan — hanya
 * berkas view + blok JS murni.
 */
class BackupAgentUiViewTest extends TestCase
{
    /** Akar repo (untuk membaca berkas JS). */
    private const JS_DIR = __DIR__ . '/../public/js';

    /** Akar view (`app/view`) tanpa bergantung pada helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    /** Nilai yang **tidak boleh** muncul di HTML (rahasia/endpoint server). */
    private const SAMPLE_TOKEN = 'agt_uji_backup_ui_7d3f1a';

    private const SAMPLE_BASE_URL = 'https://10.0.0.17:8443';

    /**
     * Stub helper global (namespace global, guard `function_exists`).
     * Hanya di-`require` dari tes ber-`RunInSeparateProcess`.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
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
                function app_subdomain(string|array|null $value = null, mixed $resolver = null): string
                {
                    $label = is_array($value) ? (string) ($value['subdomain'] ?? '') : (string) $value;

                    return $label === '' ? '' : $label . '.contoh.id';
                }
            }
            if (!function_exists('app_subdomain_of')) {
                function app_subdomain_of(array $app, mixed $resolver = null): string
                {
                    return app_subdomain($app, $resolver);
                }
            }
            if (!function_exists('app_subdomain_label')) {
                function app_subdomain_label(array $app, mixed $resolver = null): string
                {
                    return (string) ($app['subdomain'] ?? '');
                }
            }
            if (!function_exists('app_subdomain_is_custom')) {
                function app_subdomain_is_custom(array $app, mixed $resolver = null): bool
                {
                    return false;
                }
            }
            if (!function_exists('app_subdomain_valid')) {
                function app_subdomain_valid(string|array|null $value, mixed $resolver = null): bool
                {
                    return true;
                }
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
    // /backups — kerangka halaman
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBackupPageRendersAgentDumpNoteForAdminAndMember(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = true;
        $admin = $this->renderView('backup/index.php', $this->backupVars(true));

        self::assertStringContainsString('Unduh .sql', $admin, 'catatan aksi unduh .sql server agent');
        self::assertStringContainsString('server agent', $admin);
        self::assertStringContainsString('/js/backup.js?v=15', $admin, 'cache-bust backup.js naik');
        self::assertStringContainsString('backup-tab-archive', $admin, 'tab arsip tetap ada untuk admin');
        $this->assertNoSecretLeak($admin);

        $GLOBALS['RAMES_VIEW_ADMIN'] = false;
        $member = $this->renderView('backup/index.php', $this->backupVars(false));

        self::assertStringContainsString('Unduh .sql', $member, 'non-admin juga melihat catatan (volume miliknya)');
        self::assertStringNotContainsString('backup-tab-archive', $member, 'arsip tetap admin-only');
        self::assertStringNotContainsString('Undefined', $member);
        $this->assertNoSecretLeak($member);
    }

    // ==================================================================
    // detail app — tombol Run + konteks server
    // ==================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppDetailShowsRunButtonWithAgentServer(): void
    {
        $html = $this->renderView('app/detail.php', $this->detailVars($this->agentApp(), 'vps-jakarta'));

        self::assertMatchesRegularExpression(
            '/run-btn[^>]*data-server="vps-jakarta"/',
            $html,
            'tombol Run app agent membawa nama server'
        );
        self::assertStringContainsString('id="run-server"', $html, 'slot konteks server di modal Run');
        self::assertStringContainsString('di server agent vps-jakarta', $html, 'tooltip Run menyebut server');
        self::assertStringContainsString('perintah satu-kali di sana', $html, 'catatan tab Container menyebut server');
        self::assertStringContainsString('/js/app-terminal.js?v=7', $html, 'cache-bust app-terminal.js naik');
        self::assertStringNotContainsString('Undefined', $html);
        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppDetailLocalRunButtonHasNoServerContext(): void
    {
        $app = $this->agentApp();
        $app['server_id'] = '';
        $html = $this->renderView('app/detail.php', $this->detailVars($app, ''));

        self::assertStringContainsString('run-btn', $html, 'app lokal tetap punya tombol Run');
        self::assertMatchesRegularExpression('/run-btn[^>]*data-server=""/', $html, 'tanpa konteks server');
        self::assertStringNotContainsString('di server agent', $html, 'tanpa catatan/tooltip server agent');
        self::assertStringContainsString('id="run-server" class="alert alert-info py-2 small d-none mb-3"', $html, 'slot tersembunyi');
        $this->assertNoSecretLeak($html);
    }

    // ==================================================================
    // logika murni JS (dijalankan `node`)
    // ==================================================================

    public function testDumpDownloadLinkOnlyForAgentDumpVolumesOfAllowedUsers(): void
    {
        $js = $this->pureBlock('backup.js', 'dump-sql');
        $driver = <<<'JS'
        function check(ok, label) {
          if (!ok) { console.log('FAIL ' + label); process.exitCode = 1; return; }
          console.log('OK ' + label);
        }
        var agentDump = { name: 'toko_data', strategy: 'dump', server_id: 'srv-jakarta', can_schedule: true };
        var localDump = { name: 'billing_data', strategy: 'dump', server_id: 'local', can_schedule: true };
        var agentSnap = { name: 'toko_files', strategy: 'snapshot', server_id: 'srv-jakarta', can_schedule: true };
        var agentViewer = { name: 'toko_data', strategy: 'dump', server_id: 'srv-jakarta', can_schedule: false };
        var agentNoFlag = { name: 'toko_data', strategy: 'dump', server_id: 'srv-jakarta' };

        var item = dumpSqlItem(agentDump);
        check(item !== null, 'volume agent + dump + berhak -> ada tautan');
        check(item && item.url.indexOf('/backups/dump/sql?volume=toko_data') === 0, 'url unduh = endpoint dump/sql');
        check(item && item.url.indexOf('&server_id=srv-jakarta') !== -1, 'url memuat server_id server agent');
        check(item && item.label.indexOf('srv-jakarta') !== -1, 'label memuat nama server');
        check(dumpSqlItem(localDump) === null, 'volume lokal -> tanpa tautan (perilaku lama)');
        check(dumpSqlItem(agentSnap) === null, 'strategi snapshot -> tanpa tautan');
        check(dumpSqlItem(agentViewer) === null, 'tanpa hak backup -> tanpa tautan');
        check(dumpSqlItem(agentNoFlag) === null, 'flag hak absen -> tanpa tautan');
        check(isAgentVolume({ server_id: 'local' }) === false, 'server_id local bukan agent');
        check(isAgentVolume({}) === false, 'server_id kosong bukan agent');
        check(isAgentVolume({ server_id: ' srv-a ' }) === true, 'id server dinormalkan (trim)');
        var encoded = dumpSqlItem({ name: 'a b/c', strategy: 'dump', server_id: 'srv-1', can_schedule: true });
        check(encoded !== null && encoded.url.indexOf('volume=a%20b%2Fc') !== -1, 'nama volume di-encode');

        // HTML item menu + penanda server memakai escaper yang disuntikkan.
        function escHtml(v) { return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
        var html = dumpSqlItemHtml(agentDump, escHtml);
        check(html.indexOf('href="/backups/dump/sql?volume=toko_data&amp;server_id=srv-jakarta"') !== -1,
          'item unduh memuat server_id server agent');
        check(html.indexOf('Unduh .sql — server srv-jakarta') !== -1, 'label item memuat server');
        check(dumpSqlItemHtml(localDump, escHtml) === '', 'volume lokal -> item unduh kosong');
        check(dumpSqlItemHtml(agentSnap, escHtml) === '' && dumpSqlItemHtml(agentViewer, escHtml) === '',
          'snapshot / tanpa hak -> item unduh kosong');
        var injected = dumpSqlItemHtml(agentDump, function (v) { return '<' + v + '>'; });
        check(injected.indexOf('href="<') !== -1, 'escaper benar-benar disuntikkan');
        var badge = serverBadgeHtml(agentDump, escHtml);
        check(badge.indexOf('badge-deploying') !== -1 && badge.indexOf('srv-jakarta') !== -1,
          'badge agent + id server pada kolom volume');
        check(serverBadgeHtml(localDump, escHtml) === '', 'volume lokal tanpa badge server');

        // Tab arsip: tautan lama dipertahankan untuk arsip server lokal, arsip
        // server agent hanya keterangan (tidak ada jalur unduh dari dashboard).
        var localArchive = { name: 'billing_data', strategy: 'dump', last_snapshot: 'abcdef12', server_id: 'local' };
        check(archiveDumpSqlHtml(localArchive, escHtml) ===
          '<li><a class="dropdown-item" rel="noopener" href="/backups/archive/sql?volume=billing_data&amp;snapshot=abcdef12">Unduh SQL</a></li>',
          'arsip lokal: tautan lama apa adanya');
        var agentArchive = archiveDumpSqlHtml({ name: 'toko_data', strategy: 'dump', last_snapshot: 'abcdef12', server_id: 'srv-jakarta' }, escHtml);
        check(agentArchive.indexOf('<a ') === -1 && agentArchive.indexOf('srv-jakarta') !== -1,
          'arsip agent: keterangan server tanpa tautan');
        check(archiveDumpSqlHtml({ name: 'x', strategy: 'snapshot', last_snapshot: '', server_id: 'srv-a' }, escHtml) === '',
          'arsip snapshot: tanpa item unduh');
        JS;

        $result = $this->runNode($js . "\n" . $driver);

        self::assertSame(0, $result['code'], "driver node gagal:\n" . $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('FAIL', $result['stdout']);
        self::assertSame(22, substr_count($result['stdout'], 'OK '), $result['stdout']);
    }

    public function testRunErrorMessageIsClearForAgentFailures(): void
    {
        $js = $this->pureBlock('app-terminal.js', 'run-error');
        $driver = <<<'JS'
        function check(ok, label) {
          if (!ok) { console.log('FAIL ' + label); process.exitCode = 1; return; }
          console.log('OK ' + label);
        }
        var unreachable = runErrorMessage(502, 'agent: koneksi habis', false);
        check(unreachable.indexOf('batas waktu') !== -1, '502 -> menyebut batas waktu jalur agent');
        check(unreachable.indexOf('agent: koneksi habis') !== -1, 'pesan server diteruskan apa adanya');
        check(runErrorMessage(501, '', false).indexOf('belum mendukung') !== -1, '501 -> agen belum mendukung');
        check(runErrorMessage(400, 'Perintah kosong.', false) === 'Perintah kosong.', '400 -> pesan validasi server');
        check(runErrorMessage(0, '', false) === 'Gagal menjalankan perintah.', 'fallback pesan umum');
        check(runErrorMessage(0, '', true).indexOf('batas waktu') !== -1, 'timedOut ditandai jelas');
        check(runErrorMessage(502, '', false).indexOf('Terminal interaktif') !== -1, '502 -> saran jalur panjang');
        JS;

        $result = $this->runNode($js . "\n" . $driver);

        self::assertSame(0, $result['code'], "driver node gagal:\n" . $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('FAIL', $result['stdout']);
        self::assertSame(7, substr_count($result['stdout'], 'OK '), $result['stdout']);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Ekstrak blok logika murni bertanda dari berkas JS produksi:
     * `// BEGIN PURE <tag> block` … `// END PURE <tag> block`. Blok itu ditulis
     * tanpa DOM/jaringan supaya dapat dijalankan apa adanya oleh `node` — tes ini
     * karena itu menguji kode produksi, bukan salinannya.
     */
    private function pureBlock(string $file, string $tag): string
    {
        $path = self::JS_DIR . '/' . $file;
        $source = (string) file_get_contents($path);
        $pattern = '/\/\/ BEGIN PURE ' . preg_quote($tag, '/') . ' block[^\n]*\n(.*?)\n\s*\/\/ END PURE '
            . preg_quote($tag, '/') . ' block/s';

        self::assertSame(1, preg_match($pattern, $source, $m), 'blok murni ' . $tag . ' tidak ditemukan di ' . $file);

        return $m[1];
    }

    /**
     * Jalankan skrip JS dengan `node` (tanpa paket/ jaringan).
     *
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runNode(string $script): array
    {
        $file = sys_get_temp_dir() . '/rames-backup-agent-ui-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.js';
        file_put_contents($file, $script);

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open(['node', $file], $descriptors, $pipes, self::JS_DIR);
        if (!is_resource($proc)) {
            @unlink($file);
            self::markTestSkipped('`node` tidak tersedia untuk menjalankan driver JS.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = (int) proc_close($proc);
        @unlink($file);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string,mixed> */
    private function backupVars(bool $isAdmin): array
    {
        return [
            'enabled' => true,
            'policy' => 'stop',
            'isAdmin' => $isAdmin,
            'db' => null,
            'billingEnabled' => true,
            'backupRatePerGb' => 1000.0,
            'backupMinGb' => 1.0,
        ];
    }

    /** @return array<string,mixed> */
    private function agentApp(): array
    {
        return [
            'id' => 'app2',
            'name' => 'toko',
            'status' => 'running',
            'server_id' => 'srv-jakarta',
            'subdomain' => 'toko',
            'primary_service' => 'web',
            'primary_port' => 80,
            'source' => 'compose',
            'compose_files' => ['docker-compose.yml'],
            'compose_content' => "services:\n  web:\n    image: nginx:alpine\n",
            'containers' => [[
                'container_name' => 'toko-web-1',
                'service_name' => 'web',
                'image' => 'nginx:alpine',
                'status' => 'running',
                'host_port' => 31001,
                'internal_port' => 80,
                'ports' => [['host' => 31001, 'container' => 80]],
            ]],
            'external_networks' => [],
            'deploy_history' => [],
        ];
    }

    /**
     * Variabel sesuai kontrak `AppController::detail()` (partial tab lain ikut
     * render di berkas yang sama, jadi semua konteks opsionalnya diisi minimal).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private function detailVars(array $app, string $serverName): array
    {
        return [
            'app' => $app,
            'serverName' => $serverName,
            'live' => [[
                'container_name' => 'toko-web-1',
                'status' => 'running',
                'host_port' => 31001,
                'internal_port' => 80,
                'ports' => [],
            ]],
            'volumes' => [],
            'availableNetworks' => [],
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
        ];
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
     * (fungsi tetap hidup setelah berkasnya dihapus).
     */
    private function installStubs(): void
    {
        if (function_exists('csrf_field')) {
            return; // sudah terpasang di proses anak ini
        }

        $this->stubFile = sys_get_temp_dir() . '/rames-backup-agent-ui-stubs-' . getmypid() . '.php';
        file_put_contents($this->stubFile, self::STUB_SOURCE);
        require $this->stubFile;
    }

    private function assertNoSecretLeak(string $html): void
    {
        foreach ([self::SAMPLE_TOKEN, self::SAMPLE_BASE_URL, 'BEGIN CERTIFICATE', 'Bearer', 'tls_cert_pem'] as $needle) {
            self::assertStringNotContainsString($needle, $html, 'HTML view tidak boleh memuat: ' . $needle);
        }
    }
}
