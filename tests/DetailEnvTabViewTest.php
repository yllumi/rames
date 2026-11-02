<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Webman\Config;

// Helper webman (`config()`, `app_path()`) membaca konstanta BASE_PATH —
// definisikan seperti test lain agar bisa dipakai di luar runtime webman.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Smoke render partial tab Environment pada detail app
 * (`app/view/app/partials/detail-tab-env.php`).
 *
 * Kontrak yang dikunci (gerbang Import `.env.example` + catatan server agent):
 *  1. app **lokal** dengan `apps_path/<name>/.env.example` ⇒ tombol
 *     `⇩ Import dari .env.example` ADA, tombol Upload `.env` & form simpan ADA,
 *     dan catatan "berjalan di server agent" TIDAK dirender;
 *  2. app **server agent** (walau `.env.example` ada di path host) ⇒ tombol
 *     Import TIDAK ADA (import membaca berkas di host ini, bukan server agent)
 *     dan catatan "dikirim sebagai job" dirender; Upload `.env` tetap ADA
 *     (upload agent = review-only, metadata saja);
 *  3. app lokal **tanpa** `.env.example` ⇒ tombol Import TIDAK ADA;
 *  4. `$canEnv = false` (Viewer) ⇒ tidak ada form simpan/upload/import sama
 *     sekali, hanya pesan hak Operator;
 *  5. nilai env dari store di-escape (`<b>"&'` ⇒ `&lt;b&gt;&quot;&amp;&#039;`);
 *  6. `$isBusy = true` ⇒ input file/select/tombol submit dinonaktifkan;
 *  7. tombol global `#env-toggle-all` dirender **tepat sekali** di dalam
 *     `#env-form`, `type="button"` + `aria-pressed="false"` + `title`
 *     "Tampilkan semua nilai variabel", berada **sebelum** `#env-add-row` dan
 *     **tidak** ikut dinonaktifkan saat `$isBusy` (kontrol murni klien);
 *  8. teks bantuan menyebut `per baris` dan tombol `Tampilkan semua`;
 *  9. nol variabel env ⇒ tombol global tetap dirender sekali tanpa warning PHP.
 *
 * Pola sama dengan `CreateEnvFormViewTest`: partial dirender **penuh** di proses
 * terpisah dengan helper global di-stub, dan warning/notice PHP diubah menjadi
 * exception supaya undefined variable langsung menggagalkan tes. `config()`
 * milik webman sudah ter-autoload (tidak bisa di-shadow), jadi
 * `deploy.apps_path` diarahkan lewat `Config::load()` ke direktori temp unik
 * per tes — tidak menyentuh `apps/`, `database/`, atau data runtime nyata.
 */
final class DetailEnvTabViewTest extends TestCase
{
    /**
     * Helper global yang belum ada saat `vendor/autoload.php` selesai dimuat
     * (`e()`, `csrf_*()`). `config()`/`app_path()` sengaja tidak di-stub karena
     * sudah didefinisikan webman; lihat docblock kelas.
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
        }
        PHP;

    private string $tmp = '';

    private string $appsRoot = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames-envtab-view-' . bin2hex(random_bytes(6));
        $this->appsRoot = $this->tmp . '/apps';
        self::assertTrue(mkdir($this->tmp . '/config', 0777, true));
        self::assertTrue(mkdir($this->appsRoot, 0777, true));

        // `Config::load()` hanya memuat direktori yang juga punya `app.php`.
        file_put_contents(
            $this->tmp . '/config/app.php',
            "<?php\n\nreturn " . var_export(['runtime_path' => $this->tmp . '/runtime'], true) . ";\n"
        );
        file_put_contents(
            $this->tmp . '/config/deploy.php',
            "<?php\n\nreturn " . var_export(['apps_path' => $this->appsRoot], true) . ";\n"
        );

        $this->configState = $this->snapshotConfigState();
        Config::clear();
        Config::load($this->tmp . '/config');
    }

    protected function tearDown(): void
    {
        $this->restoreConfigState($this->configState);
        self::removeTree($this->tmp);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLokalDenganEnvExampleMenampilkanTombolImport(): void
    {
        $this->writeEnvExample('myapp');

        $html = $this->render();

        // Tombol import + upload + form simpan tersedia.
        self::assertStringContainsString('⇩ Import dari .env.example', $html);
        self::assertStringContainsString('action="/apps/app-1/env/import"', $html);
        self::assertStringContainsString('⇧ Upload .env', $html);
        self::assertStringContainsString('action="/apps/app-1/env/upload"', $html);
        self::assertStringContainsString('id="env-form"', $html);
        self::assertStringContainsString('name="_token"', $html);

        // Catatan server agent tidak dirender untuk app lokal.
        self::assertStringNotContainsString('berjalan di server agent', $html);
        self::assertStringNotContainsString('dikirim sebagai job', $html);

        // Tidak ada kontrol yang dinonaktifkan saat app tidak sibuk.
        self::assertStringNotContainsString('disabled', $html);
        self::assertStringNotContainsString('Dinonaktifkan sementara', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRemoteDenganEnvExampleDiPathHostTidakMenampilkanImport(): void
    {
        // `.env.example` ada di path host, tetapi app berjalan di server agent:
        // import lokal tidak berlaku, catatan "dikirim sebagai job" tampil.
        $this->writeEnvExample('myapp');

        $html = $this->render(['isRemoteServer' => true]);

        self::assertStringNotContainsString('⇩ Import dari .env.example', $html);
        self::assertStringNotContainsString('/env/import', $html);
        self::assertStringContainsString('App ini berjalan di server agent', $html);
        self::assertStringContainsString('dikirim sebagai job', $html);

        // Upload tetap tersedia (review-only) begitu pula form simpan.
        self::assertStringContainsString('⇧ Upload .env', $html);
        self::assertStringContainsString('action="/apps/app-1/env/upload"', $html);
        self::assertStringContainsString('id="env-form"', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLokalTanpaEnvExampleTidakMenampilkanImport(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString('⇩ Import dari .env.example', $html);
        self::assertStringNotContainsString('/env/import', $html);
        self::assertStringContainsString('⇧ Upload .env', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testViewerTanpaHakEnvTidakMendapatFormAksi(): void
    {
        // Bahkan bila `.env.example` ada, viewer tidak boleh melihat aksi apa pun.
        $this->writeEnvExample('myapp');

        $html = $this->render(['canEnv' => false]);

        self::assertStringNotContainsString('id="env-form"', $html);
        self::assertStringNotContainsString('name="env_file"', $html);
        self::assertStringNotContainsString('/env/upload', $html);
        self::assertStringNotContainsString('/env/import', $html);
        self::assertStringNotContainsString('⇧ Upload .env', $html);
        self::assertStringNotContainsString('⇩ Import dari .env.example', $html);
        self::assertStringNotContainsString('type="submit"', $html);
        // Tombol global hanya hidup di dalam form env, jadi tidak boleh bocor
        // ke tampilan viewer (blok `<script>` bawah tetap menyebut id-nya,
        // jadi cukup kunci atribut `id="…"` milik elemen tombol).
        self::assertStringNotContainsString('id="env-toggle-all"', $html);

        self::assertStringContainsString('hak <strong>Operator</strong>', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNilaiEnvTerEscape(): void
    {
        $html = $this->render();

        self::assertStringContainsString('value="&lt;b&gt;&quot;&amp;&#039;"', $html);
        self::assertStringNotContainsString('<b>"&\'', $html);
        // Nilai normal tidak ikut rusak.
        self::assertStringContainsString('value="base64:secret"', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBusyMenonaktifkanTombolSubmitDanUpload(): void
    {
        $this->writeEnvExample('myapp');

        $html = $this->render(['isBusy' => true]);

        self::assertMatchesRegularExpression('/name="env_file"[^>]*\bdisabled\b/', $html);
        self::assertMatchesRegularExpression('/name="mode"[^>]*\bdisabled\b/', $html);
        self::assertMatchesRegularExpression('/type="submit"[^>]*\bdisabled\b/', $html);
        self::assertMatchesRegularExpression('/disabled>⇩ Import dari \.env\.example/', $html);
        self::assertStringContainsString('Dinonaktifkan sementara app sedang diproses.', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTombolGlobalTampilkanSemuaSekaliDanSebelumTambahVariabel(): void
    {
        $form = $this->envFormSection($this->render());

        // Tepat satu tombol global, tepat satu tombol tambah baris, dan
        // urutannya: tombol global dulu (baris aksi: tampilkan → tambah → simpan).
        self::assertSame(1, substr_count($form, 'id="env-toggle-all"'), 'tombol global harus tepat satu di dalam form env');
        self::assertSame(1, substr_count($form, 'id="env-add-row"'), 'tombol tambah baris harus tepat satu');

        $togglePos = strpos($form, 'id="env-toggle-all"');
        $addPos = strpos($form, 'id="env-add-row"');
        self::assertNotFalse($togglePos);
        self::assertNotFalse($addPos);
        self::assertLessThan($addPos, $togglePos, 'tombol global harus dirender sebelum #env-add-row');

        $tag = $this->envToggleAllTag($form);
        self::assertStringContainsString('type="button"', $tag);
        self::assertStringNotContainsString('type="submit"', $tag);
        self::assertStringContainsString('aria-pressed="false"', $tag);
        self::assertStringContainsString('title="Tampilkan semua nilai variabel"', $tag);
        self::assertStringContainsString('Tampilkan semua', $this->envToggleAllLabel($form));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTeksBantuanMenyebutTampilkanSemuaDanPerBaris(): void
    {
        $form = $this->envFormSection($this->render());

        // Frasa ini hanya ada di paragraf bantuan (bukan di blok `<script>`).
        self::assertStringContainsString('per baris', $form);
        self::assertStringContainsString('klik 👁 per baris atau tombol <strong>Tampilkan semua</strong>', $form);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBusyTidakMenonaktifkanTombolGlobalTampilkanSemua(): void
    {
        $this->writeEnvExample('myapp');

        $html = $this->render(['isBusy' => true]);
        $form = $this->envFormSection($html);

        // Toggle adalah kontrol klien (tidak men-submit) ⇒ tetap aktif.
        $tag = $this->envToggleAllTag($form);
        self::assertStringNotContainsString('disabled', $tag, 'tombol global tidak boleh dinonaktifkan saat app sibuk');
        self::assertStringNotContainsString('aria-disabled', $tag);
        self::assertStringContainsString('Tampilkan semua', $this->envToggleAllLabel($form));

        // Submit tetap dinonaktifkan: lapisan server tidak dilonggarkan.
        self::assertMatchesRegularExpression('/type="submit"[^>]*\bdisabled\b/', $html);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTanpaVariabelEnvTetapMerenderTombolGlobalSekali(): void
    {
        // Warning/notice PHP sudah diubah menjadi exception oleh harness, jadi
        // daftar env kosong (`$envVars = []`, `count($envVars)` untuk `rowIndex`,
        // tabel tanpa `<tr data-env-row>`) ikut terjaga bebas notice/undefined.
        $html = $this->render(['app' => [
            'id' => 'app-1',
            'name' => 'myapp',
            'env' => [],
        ]]);

        self::assertStringContainsString('Belum ada environment variable', $html);

        $form = $this->envFormSection($html);
        self::assertSame(1, substr_count($form, 'id="env-toggle-all"'));
        self::assertStringContainsString('aria-pressed="false"', $this->envToggleAllTag($form));
        self::assertStringContainsString('Tampilkan semua', $this->envToggleAllLabel($form));
    }

    // ==================================================================
    // Harness render
    // ==================================================================

    /**
     * Render partial penuh dengan scope yang dipakai view.
     *
     * @param array<string,mixed> $overrides
     */
    private function render(array $overrides = []): string
    {
        $this->installStubs();

        $file = dirname(__DIR__) . '/app/view/app/partials/detail-tab-env.php';
        self::assertFileExists($file);

        $vars = array_replace([
            'app' => [
                'id' => 'app-1',
                'name' => 'myapp',
                'env' => [
                    'APP_KEY' => 'base64:secret',
                    'DB_PASSWORD' => '<b>"&\'',
                ],
            ],
            'canEnv' => true,
            'isBusy' => false,
            'isRemoteServer' => false,
        ], $overrides);

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

    /**
     * Potongan HTML `<form … id="env-form">` … `</form>` pertama (isi form env).
     *
     * Blok `<script>` di bawah form memakai id (`getElementById('env-add-row')`,
     * `getElementById('env-toggle-all')`) dan literal label yang sama
     * (`'Tampilkan semua nilai variabel'`), jadi klaim "di dalam form" serta
     * urutan tombol diuji pada potongan ini agar tidak tertipu skrip tersebut.
     */
    private function envFormSection(string $html): string
    {
        $start = strpos($html, 'id="env-form"');
        self::assertNotFalse($start, 'form env (`#env-form`) tidak ditemukan');

        $end = strpos($html, '</form>', $start);
        self::assertNotFalse($end, 'penutup form env tidak ditemukan');

        return substr($html, $start, $end - $start);
    }

    /**
     * Tag pembuka `<button … id="env-toggle-all" …>` apa adanya, untuk memeriksa
     * atribut (`type`, `aria-pressed`, `title`, `disabled`) tanpa terganggu teks.
     */
    private function envToggleAllTag(string $html): string
    {
        self::assertMatchesRegularExpression(
            '/<button\b[^>]*\bid="env-toggle-all"[^>]*>/',
            $html,
            'tombol global `#env-toggle-all` tidak ditemukan'
        );
        preg_match('/<button\b[^>]*\bid="env-toggle-all"[^>]*>/', $html, $matches);

        return $matches[0];
    }

    /** Label (isi) tombol global `#env-toggle-all`, mis. `👁 Tampilkan semua`. */
    private function envToggleAllLabel(string $html): string
    {
        self::assertMatchesRegularExpression(
            '/<button\b[^>]*\bid="env-toggle-all"[^>]*>.*?<\/button>/s',
            $html,
            'label tombol global `#env-toggle-all` tidak ditemukan'
        );
        preg_match('/<button\b[^>]*\bid="env-toggle-all"[^>]*>(.*?)<\/button>/s', $html, $matches);

        return $matches[1];
    }

    private function installStubs(): void
    {
        if (function_exists('csrf_field')) {
            return;
        }

        $file = sys_get_temp_dir() . '/rames-envtab-view-stubs-' . getmypid() . '.php';
        file_put_contents($file, self::STUB_SOURCE);
        require $file;
        unlink($file);
    }

    private function writeEnvExample(string $name): void
    {
        $dir = $this->appsRoot . '/' . $name;
        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0777, true));
        }
        file_put_contents($dir . '/.env.example', "APP_KEY=\nDB_PASSWORD=\n");
    }

    /** @return array<string,mixed> */
    private function snapshotConfigState(): array
    {
        $state = [];
        foreach (['config', 'configPath', 'loaded', 'flatCache'] as $name) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $state[$name] = $prop->getValue();
        }

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function restoreConfigState(array $state): void
    {
        foreach ($state as $name => $value) {
            $prop = new ReflectionProperty(Config::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
