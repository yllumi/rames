<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render view `/servers/provision` (provisioning agent lewat Ansible).
 *
 * Pola sama dengan `tests/ServerViewTest.php`: template butuh helper global
 * ber-session (`csrf_field()`, `current_user()`, …), jadi tiap test berjalan di
 * **proses terpisah** dan helper di-stub lewat berkas temp. View hanya dirender;
 * tidak ada store, SQLite, Docker, atau jaringan yang disentuh.
 *
 * Yang dibuktikan:
 *  (i)   form lengkap: CSRF (`_token`), action POST `/servers/provision`, semua
 *        field wajib (name/host/ssh_user/ssh_port/domain/email,
 *        rentang port, agent_port/bind/ip/control_plane_ips/manage_firewall/
 *        playbook/ssl_*), nilai default dari payload;
 *  (ii)  area status (`[data-provision-status]`, `[data-provision-log]`,
 *        `[data-provision-cancel]`, `[data-provision-pubkey]`) + tabel job
 *        terakhir (status bertahan setelah refresh) + skrip `/js/provision.js?v=4`;
 *  (iii) peringatan keamanan (kunci 0600, host key TOFU, egress image Ansible);
 *  (iv)  input lama ter-escape & pesan galat tampil; `private_key` tidak pernah
 *        dirender ulang;
 *  (v)   render defensif tanpa variabel opsional;
 *  (vi)  tidak ada jejak rahasia (`PRIVATE KEY`, `Bearer`, token contoh).
 */
class ProvisionViewTest extends TestCase
{
    /** Akar view (`app/view`) tanpa bergantung pada helper framework. */
    private const VIEW_DIR = __DIR__ . '/../app/view';

    /** Token contoh: nilai ini **tidak boleh** muncul di HTML mana pun. */
    private const SAMPLE_TOKEN = 'agt_uji_smoke_9f3c1d7e2b';

    /**
     * Stub helper global (namespace global, guard `function_exists`).
     * Hanya di-`require` dari dalam test yang berjalan di proses terpisah.
     */
    private const STUB_SOURCE = <<<'PHP'
        <?php
        /**
         * Stub helper dashboard untuk smoke render view (dipakai tests/ProvisionViewTest.php).
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
                    return ['id' => 'u1', 'username' => 'admin', 'role' => 'admin'];
                }
            }
            if (!function_exists('is_admin')) {
                function is_admin(?array $user = null): bool
                {
                    return true;
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

    // ------------------------------------------------------------------
    // (i)-(iii) form lengkap
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormRendersAllFieldsDefaultsAndStatusArea(): void
    {
        $html = $this->renderView('server/provision.php', $this->payload());

        // Form + CSRF.
        $this->assertStringContainsString('id="provision-form"', $html);
        $this->assertStringContainsString('method="post" action="/servers/provision"', $html);
        $this->assertStringContainsString('name="_token" value="smoke-csrf-token"', $html);

        // Field wajib.
        foreach ([
            'name="name"',
            'name="host"',
            'name="ssh_user"',
            'name="ssh_port"',
            'name="domain"',
            'name="email"',
            'name="port_range_start"',
            'name="port_range_end"',
            'name="agent_port"',
            'name="bind"',
            'name="ip"',
            'name="control_plane_ips"',
            'name="playbook"',
            'name="ssl_challenge"',
            'name="ssl_ca_server"',
        ] as $field) {
            $this->assertStringContainsString($field, $html, 'Field form hilang: ' . $field);
        }

        // Default dari controller (bukan dihitung ulang di view).
        $this->assertStringContainsString('value="root"', $html);
        $this->assertStringContainsString('value="22"', $html);
        $this->assertStringContainsString('value="8443"', $html);
        $this->assertStringContainsString('value="0.0.0.0:8443"', $html);
        $this->assertStringContainsString('value="30000"', $html);
        $this->assertStringContainsString('value="30999"', $html);

        // Playbook dari payload (kunci `ProvisionInput::PLAYBOOKS`).
        $this->assertStringContainsString('value="provision-agent"', $html);
        $this->assertStringContainsString('value="verify-agent"', $html);
        $this->assertStringContainsString('provision-agent.yml', $html);

        // Checkbox firewall: hidden 0 SEBELUM checkbox 1 (key selalu terkirim).
        $hidden = strpos($html, 'type="hidden" name="manage_firewall" value="0"');
        $checkbox = strpos($html, 'name="manage_firewall" value="1"');
        $this->assertNotFalse($hidden, 'Hidden manage_firewall=0 hilang');
        $this->assertNotFalse($checkbox, 'Checkbox manage_firewall hilang');
        $this->assertLessThan($checkbox, $hidden, 'Hidden 0 harus dirender sebelum checkbox');
        $this->assertStringContainsString('checked', $html);

        // Area status + skrip polling.
        $this->assertStringContainsString('data-provision-status', $html);
        $this->assertStringContainsString('data-provision-log', $html);
        $this->assertStringContainsString('data-provision-cancel', $html);
        $this->assertStringContainsString('src="/js/provision.js?v=4"', $html);

        // Peringatan keamanan yang wajib terlihat operator.
        $this->assertStringContainsString('0600', $html);
        $this->assertStringContainsString('TOFU', $html);
        $this->assertStringContainsString('egress', $html);
        $this->assertStringContainsString('SHA256', $html);

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // (iv) input lama & galat
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testErrorsAndOldValuesAreEscapedAndKeyIsNotReRendered(): void
    {
        $payload = $this->payload();
        $payload['values'] = [
            'name' => 'vps"><script>alert(1)</script>',
            'host' => '10.0.0.5',
            'manage_firewall' => '0',
            // Kunci privat TIDAK pernah dikirim controller ke view; bila ada pun
            // tidak dirender (view hanya membaca field whitelist).
            'private_key' => 'dummy',
        ];
        $payload['errors'] = ['Field "name" hanya boleh huruf kecil a-z, angka, dan strip.'];

        $html = $this->renderView('server/provision.php', $payload);

        $this->assertStringContainsString('Provisioning belum bisa dimulai', $html);
        $this->assertStringContainsString('Field &quot;name&quot; hanya boleh huruf kecil', $html);
        $this->assertStringContainsString('vps&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'Input user tidak boleh menjadi HTML');
        $this->assertStringNotContainsString('dummy', $html, 'Nilai private_key tidak boleh dirender');
        $this->assertStringNotContainsString('name="private_key"', $html, 'form tidak lagi meminta kunci privat');
        $this->assertStringNotContainsString('checked', $html, 'manage_firewall=0 ⇒ checkbox tidak dicentang');

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // (v) render defensif
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testViewRendersWithoutOptionalVariables(): void
    {
        $html = $this->renderView('server/provision.php', []);

        $this->assertStringContainsString('id="provision-form"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('data-provision-pubkey', $html, 'panel kunci bersama tetap dirender');
        $this->assertStringContainsString('data-provision-keygen', $html);
        $this->assertStringContainsString('value="provision-agent"', $html);
        $this->assertStringNotContainsString('Provisioning belum bisa dimulai', $html);

        $this->assertNoSecretLeak($html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testJobHistoryAndResumeAttributeAreRendered(): void
    {
        $payload = $this->payload();
        $payload['active_job'] = ['id' => 'job-aktif-1', 'status' => 'running', 'stage' => 'run'];
        $payload['jobs'] = [
            [
                'id' => 'job-aktif-1',
                'name' => 'agent-133',
                'host' => '103.82.92.133',
                'stage' => 'run',
                'status' => 'running',
                'message' => 'Menjalankan playbook provision-agent …',
                'error' => null,
                'created_at' => '2026-11-05T04:00:00Z',
                'can_cancel' => true,
            ],
            [
                'id' => 'job-lama',
                'name' => 'agent-01',
                'host' => '10.0.0.5',
                'stage' => 'done',
                'status' => 'done',
                'message' => 'Provisioning selesai.',
                'error' => null,
                'created_at' => '2026-11-04T10:00:00Z',
                'can_cancel' => false,
            ],
        ];

        $html = $this->renderView('server/provision.php', $payload);

        // Status bertahan setelah refresh: daftar job + tautan pantau.
        $this->assertStringContainsString('Job provisioning terakhir', $html);
        $this->assertStringContainsString('agent-133', $html);
        $this->assertStringContainsString('/servers/provision?job=job-aktif-1', $html);
        $this->assertStringContainsString('berjalan', $html);
        $this->assertStringContainsString('selesai', $html);

        // Hapus log: job aktif tidak punya tombol hapus; job terminal punya.
        $this->assertStringContainsString('data-provision-delete="job-lama"', $html);
        $this->assertStringNotContainsString('data-provision-delete="job-aktif-1"', $html,
            'job berjalan tidak boleh bisa dihapus');
        // Tombol massal sesuai status yang ada di daftar (ada done ⇒ "Hapus yang selesai").
        $this->assertStringContainsString('data-provision-purge="done"', $html);
        $this->assertStringNotContainsString('data-provision-purge="failed"', $html,
            'tidak ada job gagal di payload ⇒ tombol tidak dirender');

        // Pemantauan dilanjutkan otomatis untuk job aktif terbaru.
        $this->assertStringContainsString('data-provision-active-job="job-aktif-1"', $html);

        $this->assertNoSecretLeak($html);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return [
            'jobs' => [],
            'active_job' => null,
            'defaults' => [
                'name' => '',
                'host' => '',
                'ssh_user' => 'root',
                'ssh_port' => 22,
                'domain' => '',
                'email' => '',
                'port_range_start' => 30000,
                'port_range_end' => 30999,
                'agent_port' => 8443,
                'bind' => '0.0.0.0:8443',
                'ip' => '',
                'control_plane_ips' => [],
                'manage_firewall' => true,
                'playbook' => 'provision-agent',
                'ssl_challenge' => '',
                'ssl_ca_server' => '',
                'playbooks' => ['provision-agent', 'verify-agent'],
            ],
            'values' => [],
            'errors' => [],
            'active' => 'servers',
        ];
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
        if (!function_exists('csrf_field')) {
            $file = sys_get_temp_dir() . '/rames-provision-view-stubs-' . getmypid() . '.php';
            file_put_contents($file, self::STUB_SOURCE);
            require $file;
            unlink($file);
        }

        // Footer merender sprite ikon ⇒ butuh `icon_paths()`/`svg_icon()` produksi.
        if (!function_exists('svg_icon')) {
            require_once dirname(__DIR__) . '/app/functions.php';
        }
    }

    /**
     * HTML tidak boleh memuat jejak rahasia: header kunci privat, header
     * `Authorization`, atau nilai token contoh.
     */
    private function assertNoSecretLeak(string $html): void
    {
        self::assertStringNotContainsString('PRIVATE KEY', $html, 'HTML memuat header kunci privat');
        self::assertStringNotContainsString(self::SAMPLE_TOKEN, $html, 'HTML memuat nilai token contoh');
        self::assertStringNotContainsString('Bearer', $html, 'HTML memuat jejak header Authorization');
    }
}
