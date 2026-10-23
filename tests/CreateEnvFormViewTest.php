<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Smoke render kartu "Environment Variables" pada halaman konfirmasi create app.
 *
 * Kontrak yang dijaga (form create → assign env variable):
 *  (i)   template hasil deteksi (`.env.example` / `${VAR}` compose) dirender
 *        sebagai input `env[KEY]` dengan nilai prefill (default compose),
 *  (ii)  variabel bertanda `required` mendapat badge "wajib" + atribut `required`,
 *  (iii) draft isian user (setelah create gagal) menimpa nilai prefill,
 *  (iv)  data template rusak (key tidak valid / bentuk aneh) diabaikan tanpa
 *        warning PHP — harness mengubah warning menjadi exception,
 *  (v)   tombol "Tambah variabel" (`env_extra[...]`) selalu tersedia.
 *
 * Sama seperti `CreateWizardServerPickerTest`: view dirender di proses terpisah
 * dengan helper global di-stub, tanpa menyentuh store/Docker/database.
 */
final class CreateEnvFormViewTest extends TestCase
{
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
            if (!function_exists('update_badge')) {
                function update_badge(): array
                {
                    return ['available' => false, 'behind' => false, 'latest' => null, 'current' => null];
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
            if (!function_exists('config')) {
                function config(string $key, mixed $default = null): mixed
                {
                    $map = [
                        'deploy.app_domain' => 'contoh.id',
                        'deploy.port_range.start' => 10000,
                        'deploy.port_range.end' => 10999,
                        'deploy.apps_path' => '/tmp/rames-envform-test-apps',
                    ];

                    return $map[$key] ?? $default;
                }
            }
            if (!function_exists('app_path')) {
                function app_path(string $path = ''): string
                {
                    $base = dirname(__DIR__) . '/app';

                    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
                }
            }
        }
        PHP;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplateEnvDirenderDenganPrefillDanBadgeWajib(): void
    {
        $html = $this->renderConfirm([
            'source' => 'compose',
            'vars' => [
                ['key' => 'DB_HOST', 'value' => 'localhost', 'default' => 'localhost', 'required' => false],
                ['key' => 'DB_PASSWORD', 'value' => '', 'default' => null, 'required' => true],
            ],
        ]);

        self::assertStringContainsString('name="env[DB_HOST]"', $html);
        self::assertStringContainsString('value="localhost"', $html);
        self::assertStringContainsString('default compose:', $html);
        self::assertStringContainsString('name="env[DB_PASSWORD]"', $html);
        self::assertStringContainsString('wajib', $html);
        // Variabel wajib memakai atribut `required` pada input-nya.
        self::assertMatchesRegularExpression('/name="env\[DB_PASSWORD\]"[^>]*\brequired\b/', $html);
        self::assertMatchesRegularExpression('/name="env\[DB_HOST\]"[^>]*placeholder="opsional"/', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDraftUserMenimpaNilaiPrefill(): void
    {
        $html = $this->renderConfirm(
            [
                'source' => 'compose',
                'vars' => [
                    ['key' => 'DB_HOST', 'value' => 'localhost', 'default' => 'localhost', 'required' => false],
                ],
            ],
            ['DB_HOST' => 'db.internal']
        );

        self::assertStringContainsString('name="env[DB_HOST]"', $html);
        self::assertStringContainsString('value="db.internal"', $html);
        self::assertStringNotContainsString('value="localhost"', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnvExampleMenjadiSumberDanTanpaBadgeWajib(): void
    {
        $html = $this->renderConfirm([
            'source' => 'env-example',
            'vars' => [
                ['key' => 'APP_NAME', 'value' => 'Demo', 'default' => null, 'required' => false],
            ],
        ]);

        self::assertStringContainsString('.env.example', $html);
        self::assertStringContainsString('name="env[APP_NAME]"', $html);
        self::assertStringContainsString('value="Demo"', $html);
        self::assertStringNotContainsString('badge text-bg-danger', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTanpaTemplateTetapAdaTombolTambahVariabel(): void
    {
        $html = $this->renderConfirm(['source' => 'none', 'vars' => []]);

        self::assertStringContainsString('id="create-env-add-row"', $html);
        self::assertStringContainsString('env_extra[', $html);
        self::assertStringContainsString('docker-compose.yml', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplateRusakDiabaikanTanpaWarning(): void
    {
        $html = $this->renderConfirm([
            'source' => 'compose',
            'vars' => [
                'bukan-array',
                ['key' => 'BAD-KEY', 'value' => 'x', 'default' => null, 'required' => false],
                ['key' => ['nested'], 'value' => 'x', 'default' => null, 'required' => false],
                ['key' => 'OK_KEY', 'value' => ['array'], 'default' => null, 'required' => false],
            ],
        ]);

        self::assertStringContainsString('name="env[OK_KEY]"', $html);
        self::assertStringNotContainsString('BAD-KEY', $html);
        self::assertStringNotContainsString('name="env[]"', $html);
    }

    // ==================================================================
    // Harness render
    // ==================================================================

    /**
     * @param array<string,mixed> $envTemplate
     * @param array<string,string> $envDraft
     */
    private function renderConfirm(array $envTemplate, array $envDraft = []): string
    {
        $this->installStubs();

        $file = dirname(__DIR__) . '/app/view/app/confirm.php';
        self::assertFileExists($file);

        $vars = [
            'pending' => [
                'name' => 'myapp',
                'source' => 'git',
                'repo_url' => 'https://github.com/example/app.git',
                'branch' => 'main',
                'local_path' => '/tmp/rames-envform-test-apps/myapp',
                'compose_file' => 'docker-compose.yml',
                'subdomain' => 'myapp',
                'services' => ['web' => ['image' => 'nginx:alpine']],
                'server_id' => 'local',
            ],
            'subdomain' => 'myapp.contoh.id',
            'subdomain_label' => 'myapp',
            'subdomain_custom' => true,
            'resourceLimits' => [
                'canManage' => true,
                'services' => ['web'],
                'saved' => [],
                'repo' => ['web' => ['cpus' => null, 'memory_mb' => null]],
                'hasSaved' => false,
                'error' => null,
            ],
            'billing' => ['enabled' => false, 'member' => false],
            'limitsScaleMax' => 4,
            'servers' => [],
            'selectedServerId' => 'local',
            'canSelectServer' => false,
            'envTemplate' => $envTemplate,
            'envDraft' => $envDraft,
        ];

        extract($vars, EXTR_SKIP);

        set_error_handler(static function (int $no, string $message, string $source, int $line): bool {
            throw new \ErrorException($message, 0, $no, $source, $line);
        });

        ob_start();
        try {
            include $file;
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        } finally {
            restore_error_handler();
        }

        return (string) ob_get_clean();
    }

    private function installStubs(): void
    {
        if (function_exists('csrf_field')) {
            return;
        }

        $file = sys_get_temp_dir() . '/rames-envform-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }
}
