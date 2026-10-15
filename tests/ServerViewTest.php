<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render view halaman `/servers` (fase F0 multi-server) — admin-only.
 *
 * View repo ini TIDAK bisa dirender di test biasa: template memanggil helper
 * global yang butuh session/request Webman (`csrf_token()`, `current_user()`,
 * `is_admin()`, `flash_pull()`, ...). Karena itu:
 *
 *  - tiap test dijalankan di **proses terpisah** (`#[RunInSeparateProcess]`),
 *    jadi helper global tidak pernah bocor ke tes lain dalam satu proses
 *    (kalau di-`define` di proses induk, `app/functions.php` yang memakai guard
 *    `function_exists()` akan melewati definisi aslinya dan merusak tes lain);
 *  - helper global di-stub lewat berkas temp ber-namespace global lalu
 *    di-`require` **dari dalam test** (hanya hidup di anak proses);
 *  - `app_path()`/`config()` datang dari framework (sudah ada di anak proses),
 *    sehingga tidak perlu di-stub — `config('deploy.app_domain')` di sini
 *    mengembalikan default kosong (view sudah menangani nilai kosong).
 *
 * PHPUnit mengubah warning/notice jadi kegagalan (`failOnWarning`), jadi
 * variabel template yang hilang di view mana pun akan menggagalkan tes ini.
 *
 * Yang dibuktikan:
 *  (i)   daftar: empty state onboarding (hanya `local`), baris agent (badge
 *        status, fingerprint, rentang port, tombol Test connection ber-`data-id`),
 *        tombol hapus tidak dirender untuk `local`;
 *  (ii)  form create: CSRF, hidden `enabled=0` sebelum checkbox, input rentang
 *        port, fingerprint read-only tanpa `name`;
 *  (iii) form create gagal validasi: pesan galat + input lama ter-escape, checkbox
 *        `enabled` sesuai nilai terkirim;
 *  (iv)  form edit agent: token selalu `value=""` (+ petunjuk token tersimpan),
 *        PEM dirender (publik), fingerprint read-only terisi;
 *  (v)   form edit `local`: field identitas terkunci & **tidak terkirim**, tanpa
 *        hidden `enabled=0` (mengirimnya akan ditolak controller);
 *  (vi)  nav "Servers" hanya untuk admin;
 *  (vii) render defensif: view tetap jalan tanpa variabel opsional
 *        (`active`/`errors`/`isEdit`/`server`);
 *  (viii) blok bantu onboarding agent ("Ambil token & sertifikat dari server
 *        tujuan") — dirender untuk server **non-local** (create + edit agent),
 *        tidak dirender untuk server `local`, selalu **di luar** `<form>`
 *        (bukan field form), dan perintahnya menyebut container tetap
 *        `rames-agent`.
 *
 * Kontrak payload yang diuji = `ServerController` (lihat `tests/ServerControllerTest.php`).
 * Test ini hanya merender; tidak menyentuh store, SQLite, Docker, atau jaringan.
 */
class ServerViewTest extends TestCase
{
    /** Akar view (`app/view`) tanpa bergantung pada helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    private const LOCAL_ID = 'local';

    /** Token contoh: nilai ini **tidak boleh** muncul di HTML mana pun. */
    private const SAMPLE_TOKEN = 'agt_uji_smoke_9f3c1d7e2b';

    /** Sertifikat self-signed **publik** (bukan rahasia) — boleh dirender di form edit. */
    private const SAMPLE_PEM = "-----BEGIN CERTIFICATE-----\nMIIBujurUjiSertifikatPublik\n-----END CERTIFICATE-----";

    /** Fingerprint SHA-256 contoh (64 hex, bentuk keluaran `ServerResolver::fingerprintFromPem()`). */
    private const SAMPLE_PIN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00';

    /**
     * Stub helper global (namespace global, guard `function_exists`).
     * Hanya di-`require` dari dalam test yang berjalan di proses terpisah.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
        /**
         * Stub helper dashboard untuk smoke render view (dipakai tests/ServerViewTest.php).
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
        }
        PHP;

    protected function setUp(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = true;
    }

    // ------------------------------------------------------------------
    // (i) daftar server
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIndexWithoutAgentShowsOnboardingAndNoDeleteForLocal(): void
    {
        $html = $this->renderView('server/index.php', [
            'servers' => [$this->localRow()],
            'localId' => self::LOCAL_ID,
            'active' => 'servers',
        ]);

        self::assertStringContainsString('Belum ada server agent', $html);
        self::assertStringContainsString('agent/install.sh', $html, 'Alur onboarding harus menyebut skrip installer');
        self::assertStringContainsString('Test connection', $html);
        self::assertStringContainsString('+ Tambah Server', $html);

        // Baris lokal: badge + slot status, tanpa tombol hapus (server tetap penolak sebenarnya).
        self::assertStringContainsString('data-server-status="' . self::LOCAL_ID . '"', $html);
        self::assertStringContainsString('data-server-result="' . self::LOCAL_ID . '"', $html);
        self::assertStringContainsString('data-id="' . self::LOCAL_ID . '"', $html);
        self::assertStringNotContainsString('/delete', $html, 'Server lokal tidak boleh punya form hapus');
        self::assertStringNotContainsString('name="token"', $html, 'Daftar server tidak merender input token');
        self::assertStringNotContainsString('tls_cert_pem', $html, 'Daftar server tidak merender PEM');

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIndexListsAgentsWithStatusBadgesAndTestButtons(): void
    {
        $html = $this->renderView('server/index.php', [
            'servers' => [$this->localRow(), $this->agentOnline(), $this->agentOffline()],
            'localId' => self::LOCAL_ID,
            'active' => 'servers',
        ]);

        self::assertStringNotContainsString('Belum ada server agent', $html, 'Empty state harus hilang saat ada agent');
        self::assertStringContainsString('3 server (2 agent)', $html);

        self::assertStringContainsString('data-id="vps-jakarta"', $html);
        self::assertStringContainsString('data-id="vps-surabaya"', $html);
        self::assertSame(3, substr_count($html, 'js-server-test'));

        self::assertStringContainsString('https://10.0.0.12:8443', $html);
        self::assertStringContainsString('31000–31999', $html);
        self::assertStringContainsString('rames-agent 0.1.0', $html);
        self::assertStringContainsString('2 capabilities', $html);
        self::assertStringContainsString('>nonaktif</span>', $html);
        self::assertStringContainsString('>tanpa token</span>', $html);

        // Fingerprint hanya ditampilkan terpotong; nilai penuh ada di `title` (read-only, informatif).
        self::assertStringContainsString('pin a1b2c3d4e5f60718…', $html);
        self::assertStringContainsString('title="Fingerprint SHA-256 sertifikat agent: ' . self::SAMPLE_PIN . '"', $html);

        // Hapus server agent = form HTML biasa + CSRF (semua POST controller selalu redirect).
        self::assertStringContainsString('action="/servers/vps-jakarta/delete"', $html);
        self::assertStringContainsString('name="_token" value="smoke-csrf-token"', $html);
        self::assertStringContainsString('src="/js/server.js?v=2"', $html);

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // (ii)-(iv) form
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormCreateRendersCsrfHiddenEnabledAndReadonlyFingerprint(): void
    {
        $html = $this->renderView('server/form.php', [
            'server' => null,
            'errors' => [],
            'isEdit' => false,
            'active' => 'servers',
        ]);

        self::assertStringContainsString('action="/servers"', $html);
        self::assertStringContainsString('name="_token" value="smoke-csrf-token"', $html);
        self::assertStringContainsString('name="name"', $html);
        self::assertStringContainsString('name="base_url"', $html);
        self::assertStringContainsString('name="port_range_start"', $html);
        self::assertStringContainsString('name="port_range_end"', $html);
        self::assertStringContainsString('name="domain"', $html);
        self::assertStringContainsString('name="notes"', $html);

        // `enabled` tidak dicentang = key absen ⇒ hidden 0 WAJIB mendahului checkbox.
        self::assertMatchesRegularExpression(
            '/<input type="hidden" name="enabled" value="0">\s*<input class="form-check-input" type="checkbox" name="enabled" value="1"/s',
            $html
        );

        // Token tidak pernah dirender (kosong saat submit = tidak diubah).
        self::assertMatchesRegularExpression('/id="server-token" name="token" value=""/', $html);

        // Fingerprint = hasil hitung dashboard: read-only, tanpa `name` (tidak ikut terkirim).
        self::assertStringContainsString('id="server-fingerprint"', $html);
        self::assertMatchesRegularExpression('/id="server-fingerprint"[^>]*readonly/', $html);
        self::assertStringNotContainsString('name="tls_fingerprint"', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormCreateWithErrorsRendersMessagesAndKeepsOldInputEscaped(): void
    {
        $html = $this->renderView('server/form.php', [
            'server' => [
                'name' => 'vps<x>',
                'kind' => 'agent',
                'base_url' => 'http://10.0.0.9:8443',
                'token' => '',
                'tls_cert_pem' => '',
                'enabled' => '0',
                'port_range' => ['start' => '32000', 'end' => ''],
            ],
            'errors' => ['URL agent wajib https://.', 'Rentang port server harus 1–65535 dengan start < end.'],
            'isEdit' => false,
            'active' => 'servers',
        ]);

        self::assertStringContainsString('Perubahan tidak disimpan:', $html);
        self::assertStringContainsString('URL agent wajib https://.', $html);
        self::assertStringContainsString('Rentang port server harus 1–65535', $html);

        // Input lama dipertahankan, ter-escape (tidak ada HTML mentah dari input user).
        self::assertStringContainsString('value="vps&lt;x&gt;"', $html);
        self::assertStringNotContainsString('value="vps<x>"', $html);
        self::assertStringContainsString('value="http://10.0.0.9:8443"', $html);
        self::assertStringContainsString('value="32000"', $html);

        // `enabled=0` terkirim ⇒ checkbox tidak dicentang.
        self::assertMatchesRegularExpression('/name="enabled" value="1" id="server-enabled"(?!\s+checked)/', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormEditAgentKeepsTokenEmptyAndShowsFingerprint(): void
    {
        $html = $this->renderView('server/form.php', [
            'server' => [
                'id' => 'vps-jakarta',
                'name' => 'vps-jakarta',
                'kind' => 'agent',
                'enabled' => true,
                'base_url' => 'https://10.0.0.12:8443',
                'token' => '',
                'has_token' => true,
                'tls_cert_pem' => self::SAMPLE_PEM,
                'tls_fingerprint' => self::SAMPLE_PIN,
                'domain' => 'contoh2.id',
                'notes' => 'server utama',
                'port_range' => ['start' => 31000, 'end' => 31999],
            ],
            'errors' => [],
            'isEdit' => true,
            'active' => 'servers',
        ]);

        self::assertStringContainsString('action="/servers/vps-jakarta"', $html);
        self::assertMatchesRegularExpression('/id="server-token" name="token" value=""/', $html);
        self::assertDoesNotMatchRegularExpression('/id="server-token"[^>]*required/', $html);
        self::assertStringContainsString('Token sudah tersimpan dan tidak pernah ditampilkan ulang.', $html);
        self::assertStringContainsString('BEGIN CERTIFICATE', $html, 'PEM publik dirender di form edit');
        self::assertStringContainsString('id="server-fingerprint" value="' . self::SAMPLE_PIN . '"', $html);
        self::assertStringContainsString('name="port_range_start"', $html);
        self::assertStringContainsString('value="31000"', $html);
        self::assertStringContainsString('value="31999"', $html);
        self::assertStringContainsString('>server utama</textarea>', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormEditLocalLocksIdentityFieldsAndOmitsThemFromSubmit(): void
    {
        $html = $this->renderView('server/form.php', [
            'server' => [
                'id' => self::LOCAL_ID,
                'name' => 'local',
                'kind' => 'local',
                'enabled' => true,
                'domain' => '',
                'notes' => 'host utama',
            ],
            'errors' => [],
            'isEdit' => true,
            'active' => 'servers',
        ]);

        self::assertStringContainsString('data-locked="1"', $html);
        self::assertStringContainsString('>terkunci</span>', $html);

        // Field terkunci tidak dikirim: tanpa `name` (mengirim nilai berbeda = ditolak controller).
        self::assertStringNotContainsString('name="name"', $html);
        self::assertStringNotContainsString('name="kind"', $html);
        self::assertStringNotContainsString('name="base_url"', $html);
        self::assertStringNotContainsString('name="token"', $html);
        self::assertStringNotContainsString('name="tls_cert_pem"', $html);
        self::assertStringNotContainsString('name="port_range', $html);
        self::assertStringNotContainsString('name="enabled"', $html, 'Jangan kirim enabled=0 untuk server lokal');

        // Yang boleh diubah hanya domain & catatan.
        self::assertStringContainsString('name="domain"', $html);
        self::assertStringContainsString('name="notes"', $html);
        self::assertStringContainsString('>host utama</textarea>', $html);
        self::assertMatchesRegularExpression('/type="checkbox" id="server-enabled" checked disabled/', $html);

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // (viii) blok bantu onboarding agent (view `form.php`)
    // ------------------------------------------------------------------

    /**
     * Blok bantu "Ambil token & sertifikat dari server tujuan" harus muncul untuk
     * server non-`local` (create = selalu agent, lihat `<select name="kind">`, dan
     * edit agent) — nilai yang dibaca (`AGENT_TOKEN`/`AGENT_TLS_CERT`) tidak pernah
     * dirender ulang dashboard, jadi blok ini satu-satunya jalan bagi admin.
     *
     * Diuji juga dua jebakan kontrak:
     *  1. blok berada **di luar** `<form method="post">` dan `textarea`-nya tanpa
     *     `name` ⇒ tidak ikut ter-POST (payload form server tidak berubah);
     *  2. id & nama fungsi JS dikunci agar halaman tidak memuat substring yang
     *     dilarang `assertNoSecretLeak()` (dulu `agent-secret-cmd`).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormAgentOnboardingHelperBlockRenderedForCreateAndEditAgent(): void
    {
        $cases = [
            'create' => [
                'server' => null,
                'errors' => [],
                'isEdit' => false,
                'active' => 'servers',
            ],
            'edit agent' => [
                'server' => [
                    'id' => 'vps-jakarta',
                    'name' => 'vps-jakarta',
                    'kind' => 'agent',
                    'enabled' => true,
                    'base_url' => 'https://10.0.0.12:8443',
                    'has_token' => true,
                    'tls_cert_pem' => self::SAMPLE_PEM,
                    'tls_fingerprint' => self::SAMPLE_PIN,
                    'domain' => 'contoh2.id',
                    'notes' => 'server utama',
                    'port_range' => ['start' => 31000, 'end' => 31999],
                ],
                'errors' => [],
                'isEdit' => true,
                'active' => 'servers',
            ],
        ];

        foreach ($cases as $label => $vars) {
            $html = $this->renderView('server/form.php', $vars);

            self::assertStringContainsString('Ambil token &amp; sertifikat dari server tujuan', $html, $label);
            self::assertStringContainsString('id="agent-onboarding-cmd"', $html, $label);
            self::assertStringContainsString('docker exec rames-agent sh -lc', $html, $label);
            self::assertStringContainsString(
                'docker exec rames-agent sh -lc \'printf "TOKEN=%s\n\n" "$AGENT_TOKEN"; cat "$AGENT_TLS_CERT"\'',
                $html,
                $label
            );
            self::assertStringContainsString('onclick="copyAgentOnboardingCmd()"', $html, $label);
            self::assertStringContainsString('function copyAgentOnboardingCmd()', $html, $label);

            // Tombol harus `type="button"` — jangan sampai ikut men-submit form.
            self::assertMatchesRegularExpression(
                '/<button type="button"[^>]*onclick="copyAgentOnboardingCmd\(\)"/',
                $html,
                $label
            );

            // `textarea` pemuat perintah BUKAN field form (tanpa `name`), dan blok
            // benar-benar berada DI LUAR elemen form server (dicek dari isi form-nya
            // sendiri, bukan sekadar urutan posisi).
            self::assertMatchesRegularExpression('/<textarea[^>]*id="agent-onboarding-cmd"[^>]*>/', $html, $label);
            preg_match('/<textarea[^>]*id="agent-onboarding-cmd"[^>]*>/', $html, $textarea);
            self::assertStringNotContainsString('name=', $textarea[0] ?? '', $label . ': textarea tidak boleh ber-`name`');

            $formStart = strpos($html, '<form method="post" action="/servers');
            self::assertIsInt($formStart, $label . ': form server tidak ditemukan di HTML');
            $formEnd = (int) strpos($html, '</form>', $formStart);
            $formHtml = substr($html, $formStart, $formEnd - $formStart);
            self::assertStringNotContainsString('agent-onboarding-cmd', $formHtml, $label . ': blok bantu harus DI LUAR <form> server');

            $this->assertNoSecretLeak($html);
        }
    }

    /**
     * Server `local` (dashboard ini sendiri) tidak punya agent ⇒ blok bantu beserta
     * `<script>`-nya harus absen total, agar tidak ada instruksi menyesatkan dan
     * tidak ada string mati di halaman.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormLocalOmitsAgentOnboardingHelperBlock(): void
    {
        $html = $this->renderView('server/form.php', [
            'server' => [
                'id' => self::LOCAL_ID,
                'name' => 'local',
                'kind' => 'local',
                'enabled' => true,
                'domain' => '',
                'notes' => 'host utama',
            ],
            'errors' => [],
            'isEdit' => true,
            'active' => 'servers',
        ]);

        // Sanity: memang halaman local (bukan halaman agent).
        self::assertStringContainsString('Identitas server lokal', $html);
        self::assertStringContainsString('action="/servers/' . self::LOCAL_ID . '"', $html);

        self::assertStringNotContainsString('Ambil token &amp; sertifikat dari server tujuan', $html);
        self::assertStringNotContainsString('id="agent-onboarding-cmd"', $html);
        self::assertStringNotContainsString('agent-onboarding-cmd', $html, 'id ikut muncul di JS jika <script> tak di-guard');
        self::assertStringNotContainsString('docker exec rames-agent sh -lc', $html);
        self::assertStringNotContainsString('copyAgentOnboardingCmd', $html);
        self::assertStringNotContainsString('AGENT_TOKEN', $html);
        self::assertStringNotContainsString('AGENT_TLS_CERT', $html);

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // (vi) nav & (vii) render defensif
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNavServersIsAdminOnly(): void
    {
        $GLOBALS['RAMES_VIEW_ADMIN'] = true;
        $adminHtml = $this->renderView('partials/header.php', ['pageTitle' => 'Servers', 'active' => 'servers']);
        self::assertStringContainsString('href="/servers"', $adminHtml);
        self::assertStringContainsString('aria-current="page"', $adminHtml);

        $GLOBALS['RAMES_VIEW_ADMIN'] = false;
        $memberHtml = $this->renderView('partials/header.php', ['pageTitle' => 'Apps', 'active' => 'apps']);
        self::assertStringNotContainsString('href="/servers"', $memberHtml);
        self::assertStringNotContainsString('href="/nginx"', $memberHtml, 'Item nav admin lain juga tetap tersembunyi');
        self::assertStringNotContainsString('href="/users"', $memberHtml);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testViewsRenderWithoutOptionalVariables(): void
    {
        // `active` tidak dikirim; `servers` kosong.
        $indexHtml = $this->renderView('server/index.php', ['servers' => [], 'localId' => self::LOCAL_ID]);
        self::assertStringContainsString('Belum ada server terdaftar.', $indexHtml);

        // Tidak ada `$server`/`$errors`/`$isEdit` sama sekali (kontrak form tetap aman).
        $formHtml = $this->renderView('server/form.php', []);
        self::assertStringContainsString('Tambah Server', $formHtml);
        self::assertStringContainsString('name="_token"', $formHtml);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

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
     * `require` (fungsi tetap hidup setelah berkasnya dihapus). Hanya dipanggil
     * dari test ber-`RunInSeparateProcess`, jadi proses induk tidak tercemar.
     */
    private function installStubs(): void
    {
        if (function_exists('csrf_field')) {
            return; // sudah terpasang di proses anak ini
        }

        $file = sys_get_temp_dir() . '/rames-server-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }

    /**
     * HTML hasil render tidak boleh memuat jejak rahasia apa pun: token agent
     * (nilai contoh), header `Bearer`, atau kata `secret`.
     */
    private function assertNoSecretLeak(string $html): void
    {
        self::assertStringNotContainsString(self::SAMPLE_TOKEN, $html, 'HTML memuat nilai token contoh');
        self::assertStringNotContainsString('Bearer', $html, 'HTML memuat jejak header Authorization');
        self::assertStringNotContainsString('secret', $html, 'HTML memuat kata "secret"');
    }

    /**
     * Dokumen server lokal sesuai kontrak `ServerController::publicServer()`.
     *
     * @return array<string,mixed>
     */
    private function localRow(): array
    {
        return [
            'id' => self::LOCAL_ID,
            'name' => 'local',
            'kind' => 'local',
            'enabled' => true,
            'domain' => '',
            'has_token' => false,
            'status' => 'unknown',
        ];
    }

    /**
     * Dokumen server agent online (tanpa token/PEM — controller membuang keduanya).
     *
     * @return array<string,mixed>
     */
    private function agentOnline(): array
    {
        return [
            'id' => 'vps-jakarta',
            'name' => 'vps-jakarta',
            'kind' => 'agent',
            'enabled' => true,
            'base_url' => 'https://10.0.0.12:8443',
            'domain' => 'contoh2.id',
            'has_token' => true,
            'tls_fingerprint' => self::SAMPLE_PIN,
            'port_range' => ['start' => 31000, 'end' => 31999],
            'status' => 'online',
            'agent_version' => 'rames-agent 0.1.0',
            'capabilities' => ['jobs', 'docker'],
            'last_seen_at' => '2026-10-14T09:00:00+07:00',
        ];
    }

    /**
     * Dokumen server agent offline & nonaktif, tanpa token.
     *
     * @return array<string,mixed>
     */
    private function agentOffline(): array
    {
        return [
            'id' => 'vps-surabaya',
            'name' => 'vps-surabaya',
            'kind' => 'agent',
            'enabled' => false,
            'base_url' => 'https://10.0.0.77:8443',
            'domain' => '',
            'has_token' => false,
            'status' => 'offline',
        ];
    }
}
