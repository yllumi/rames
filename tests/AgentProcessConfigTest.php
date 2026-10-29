<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Webman\Config;

// Helper webman (`base_path()`, `config()`, `cpu_count()`) membaca konstanta
// BASE_PATH — definisikan seperti test lain agar `config/process.php` (dan
// `config/deploy.php` yang di-`require` di dalamnya) bisa dimuat di luar runtime.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Listener proses agent pada `config/process.php` (multi-server F0, keputusan 5 & 6).
 *
 * Kontrak yang dijaga (berkas diuji apa adanya, bukan salinan logikanya):
 *  1. mode `dashboard` (default) → proses `webman` **persis** seperti sebelum
 *     gerbang mode: `listen => 'http://0.0.0.0:8787'`, `context => []`, tanpa
 *     kunci `transport` — dan env `AGENT_TLS_*` yang menunjuk berkas tidak ada
 *     TIDAK boleh menggagalkan startup dashboard (mode dashboard tidak peduli);
 *  2. mode `agent` → listen mengikuti `AGENT_BIND` (default `0.0.0.0:8443`),
 *     `transport => 'ssl'`, `context.ssl.local_cert/local_pk` = berkas yang
 *     diberikan, `verify_peer => false` (TLS diterminasi proses agent sendiri);
 *  3. mode `agent` TANPA cert/key (kosong, tidak ada, atau penanda "mati") →
 *     gagal **tegas** (`RuntimeException` saat berkas dimuat = saat start),
 *     bukan jalan tanpa TLS; `AGENT_BIND` tak berbentuk `IP:PORT` juga ditolak;
 *  4. timer khusus dashboard (update-check/billing/db-backup/ssl-queue) tidak
 *     dipasang di mode agent dan tetap dipasang di mode dashboard.
 *
 * Tidak menyentuh data runtime: hanya direktori temp + env proses yang
 * dipulihkan di `tearDown` (registry webman `Config` juga dipulihkan).
 */
class AgentProcessConfigTest extends TestCase
{
    /** Timer lokal (kedua mode) + timer khusus dashboard (plan §10.1). */
    private const LOCAL_PROCESSES = ['webman', 'monitor'];
    private const DASHBOARD_ONLY_PROCESSES = ['update-check', 'billing', 'db-backup', 'ssl-queue'];

    private string $tmp = '';

    /** @var array<string,mixed> */
    private array $configState = [];

    /** @var array<string,string|false> */
    private array $envState = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/rames_agent_process_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmp, 0777, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }
        // `global $argv` di config/process.php harus punya nilai array.
        if (!isset($GLOBALS['argv']) || !is_array($GLOBALS['argv'])) {
            $GLOBALS['argv'] = [];
        }
        $this->configState = $this->snapshotConfigState();
        foreach ($this->envNames() as $name) {
            $this->envState[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envState as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->restoreConfigState($this->configState);
        foreach (glob($this->tmp . '/*.pem') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->tmp . '/config/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp . '/config');
        @rmdir($this->tmp);
    }

    // ------------------------------------------------------------------
    // (i) mode dashboard — perilaku TIDAK berubah
    // ------------------------------------------------------------------

    public function testDashboardModeKeepsHttpListenerAndEmptyContext(): void
    {
        $this->applyEnv(['RAMES_MODE' => 'dashboard', 'AGENT_BIND' => '0.0.0.0:9443']);
        $webman = $this->loadProcesses()['webman'];

        $this->assertSame('http://0.0.0.0:8787', $webman['listen'], 'mode dashboard tidak boleh mengubah alamat listen');
        $this->assertSame([], $webman['context'], 'mode dashboard harus tetap tanpa context TLS');
        $this->assertArrayNotHasKey('transport', $webman, 'mode dashboard tidak boleh memaksa transport ssl');
        // Proses HTTP dashboard tetap seperti sebelumnya.
        $this->assertSame(\app\process\Http::class, $webman['handler']);
        $this->assertFalse($webman['reusePort']);
        $this->assertSame('', $webman['eventLoop']);
    }

    public function testDashboardModeIgnoresBrokenAgentTlsEnv(): void
    {
        $this->applyEnv([
            'RAMES_MODE' => 'dashboard',
            'AGENT_TLS_CERT' => $this->tmp . '/tidak-ada.crt',
            'AGENT_TLS_KEY' => 'none',
        ]);
        $webman = $this->loadProcesses()['webman'];

        $this->assertSame('http://0.0.0.0:8787', $webman['listen']);
        $this->assertSame([], $webman['context'], 'env AGENT_TLS_* tidak boleh memengaruhi mode dashboard');
    }

    // ------------------------------------------------------------------
    // (ii) mode agent — bind + TLS
    // ------------------------------------------------------------------

    public function testAgentModeListensOnBindWithTlsContext(): void
    {
        [$cert, $key] = $this->makeCertPair();
        $this->applyEnv(['RAMES_MODE' => 'agent', 'AGENT_BIND' => '0.0.0.0:9443', 'AGENT_TLS_CERT' => $cert, 'AGENT_TLS_KEY' => $key]);
        $webman = $this->loadProcesses()['webman'];

        // Skema pada `listen` adalah protokol APLIKASI (Workerman tidak mengenal
        // skema `https://`); TLS dinyalakan oleh `transport => 'ssl'`.
        $this->assertSame('http://0.0.0.0:9443', $webman['listen'], 'listen agent harus mengikuti AGENT_BIND');
        $this->assertSame('ssl', $webman['transport'], 'mode agent wajib TLS');
        $this->assertSame($cert, $webman['context']['ssl']['local_cert'] ?? null);
        $this->assertSame($key, $webman['context']['ssl']['local_pk'] ?? null);
        $this->assertFalse($webman['context']['ssl']['verify_peer'] ?? true, 'self-signed + pinning: agent tidak meminta cert klien');
    }

    public function testAgentModeWithoutBindUsesDefaultBind(): void
    {
        [$cert, $key] = $this->makeCertPair();
        $this->applyEnv(['RAMES_MODE' => 'agent', 'AGENT_TLS_CERT' => $cert, 'AGENT_TLS_KEY' => $key]);
        putenv('AGENT_BIND'); // default dari config/deploy.php

        $this->assertSame('http://0.0.0.0:8443', $this->loadProcesses()['webman']['listen']);
    }

    public function testAgentModeNormalizesBindWithSchemePrefix(): void
    {
        [$cert, $key] = $this->makeCertPair();
        $this->applyEnv([
            'RAMES_MODE' => 'agent',
            'AGENT_BIND' => 'https://0.0.0.0:8443/',
            'AGENT_TLS_CERT' => $cert,
            'AGENT_TLS_KEY' => $key,
        ]);

        $this->assertSame('http://0.0.0.0:8443', $this->loadProcesses()['webman']['listen']);
    }

    // ------------------------------------------------------------------
    // (iii) mode agent tanpa TLS — gagal tegas
    // ------------------------------------------------------------------

    public function testAgentModeWithoutCertFailsFast(): void
    {
        $this->applyEnv(['RAMES_MODE' => 'agent', 'AGENT_TLS_CERT' => '', 'AGENT_TLS_KEY' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AGENT_TLS_CERT/');
        $this->loadProcesses();
    }

    public function testAgentModeWithFlagValueMeaningNoTlsFailsFast(): void
    {
        $this->applyEnv(['RAMES_MODE' => 'agent', 'AGENT_TLS_CERT' => 'none', 'AGENT_TLS_KEY' => 'off']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AGENT_TLS_CERT/');
        $this->loadProcesses();
    }

    public function testAgentModeWithUnreadableCertFailsFast(): void
    {
        [$cert, $key] = $this->makeCertPair();
        $this->applyEnv([
            'RAMES_MODE' => 'agent',
            'AGENT_TLS_CERT' => $this->tmp . '/hilang.crt',
            'AGENT_TLS_KEY' => $key,
        ]);
        $this->assertFileExists($cert); // cert valid tapi tidak dipakai di kasus ini

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak ada atau tidak terbaca/');
        $this->loadProcesses();
    }

    public function testAgentModeWithMissingKeyFailsFast(): void
    {
        [$cert] = $this->makeCertPair();
        $this->applyEnv(['RAMES_MODE' => 'agent', 'AGENT_TLS_CERT' => $cert, 'AGENT_TLS_KEY' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AGENT_TLS_KEY/');
        $this->loadProcesses();
    }

    public function testAgentModeWithBindWithoutPortFailsFast(): void
    {
        [$cert, $key] = $this->makeCertPair();
        $this->applyEnv([
            'RAMES_MODE' => 'agent',
            'AGENT_BIND' => '0.0.0.0',
            'AGENT_TLS_CERT' => $cert,
            'AGENT_TLS_KEY' => $key,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AGENT_BIND/');
        $this->loadProcesses();
    }

    // ------------------------------------------------------------------
    // (iv) timer: dashboard vs agent
    // ------------------------------------------------------------------

    public function testDashboardModeRunsDashboardTimers(): void
    {
        $this->applyEnv(['RAMES_MODE' => 'dashboard']);

        $processes = $this->loadProcesses();
        $this->assertSame(
            array_merge(self::LOCAL_PROCESSES, self::DASHBOARD_ONLY_PROCESSES),
            array_keys($processes)
        );
        // Drainer antrean SSL: satu worker (tidak boleh paralel — certbot lock global).
        $this->assertSame(\app\process\SslQueueProcess::class, $processes['ssl-queue']['handler']);
        $this->assertSame(1, $processes['ssl-queue']['count']);
        $this->assertTrue($processes['ssl-queue']['reloadable']);
    }

    public function testAgentModeRunsLocalProcessesOnly(): void
    {
        [$cert, $key] = $this->makeCertPair();
        $this->applyEnv(['RAMES_MODE' => 'agent', 'AGENT_TLS_CERT' => $cert, 'AGENT_TLS_KEY' => $key]);
        $processes = $this->loadProcesses();

        $this->assertSame(self::LOCAL_PROCESSES, array_keys($processes), 'agent hanya menjalankan worker HTTP + monitor');
        foreach (self::DASHBOARD_ONLY_PROCESSES as $name) {
            $this->assertArrayNotHasKey($name, $processes, "timer dashboard `{$name}` tidak boleh jalan di agent");
        }
        $this->assertSame(\app\process\Monitor::class, $processes['monitor']['handler']);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** @return array<int,string> */
    private function envNames(): array
    {
        return ['RAMES_MODE', 'AGENT_BIND', 'AGENT_TLS_CERT', 'AGENT_TLS_KEY'];
    }

    /**
     * Set env proses; nilai `null` = hapus variabel.
     *
     * @param array<string,string|null> $vars
     */
    private function applyEnv(array $vars): void
    {
        foreach ($this->envNames() as $name) {
            if (!array_key_exists($name, $vars) || $vars[$name] === null) {
                putenv($name);
                continue;
            }
            putenv($name . '=' . $vars[$name]);
        }
    }

    /**
     * Muat `config/process.php` apa adanya dengan env yang sedang berlaku.
     *
     * @return array<string,mixed>
     */
    private function loadProcesses(): array
    {
        $dir = $this->tmp . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // Config::loadFromDir() hanya memuat berkas di direktori yang punya app.php,
        // dan config/process.php memanggil Log::channel('default') saat dievaluasi.
        file_put_contents($dir . '/app.php', "<?php return [];\n");
        file_put_contents($dir . '/log.php', "<?php return " . var_export(['default' => ['handlers' => [[]]]], true) . ";\n");
        Config::clear();
        Config::load($dir);

        return require BASE_PATH . '/config/process.php';
    }

    /**
     * Pasangan sertifikat + kunci privat di direktori temp (dipakai apa adanya
     * oleh `config/process.php`; TLS handshake nyata diverifikasi `agent/install.sh`).
     *
     * @return array{0:string,1:string} [certPath, keyPath]
     */
    private function makeCertPair(): array
    {
        $certPath = $this->tmp . '/agent.crt.pem';
        $keyPath = $this->tmp . '/agent.key.pem';

        if (extension_loaded('openssl')) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = $key !== false
                ? openssl_csr_new(['commonName' => 'rames-agent'], $key, ['digest_alg' => 'sha256'])
                : false;
            $cert = $csr !== false ? openssl_csr_sign($csr, null, $key, 825, ['digest_alg' => 'sha256']) : false;
            if ($cert !== false && openssl_x509_export($cert, $certPem) && openssl_pkey_export($key, $keyPem)) {
                file_put_contents($certPath, $certPem);
                file_put_contents($keyPath, $keyPem);
                chmod($keyPath, 0600);
                return [$certPath, $keyPath];
            }
        }

        // Cadangan: berkas yang tetap "ada & terbaca" (yang diperiksa config loader).
        file_put_contents($certPath, "-----BEGIN CERTIFICATE-----\ntemp\n-----END CERTIFICATE-----\n");
        file_put_contents($keyPath, "-----BEGIN PRIVATE KEY-----\ntemp\n-----END PRIVATE KEY-----\n");
        chmod($keyPath, 0600);

        return [$certPath, $keyPath];
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
}
