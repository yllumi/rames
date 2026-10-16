<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\DetachedWorker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `DetachedWorker::spawn()` — pewarisan **lingkungan** ke proses anak.
 *
 * Bug produksi yang dikunci di sini: `pcntl_exec()` memakai `execve()`, jadi
 * argumen ke-3 (`envp`) **menggantikan** seluruh lingkungan anak. Versi lama
 * mengirim `[]` setiap kali pemanggil tidak memberi env tambahan (jalur
 * `DeployWorkerLauncher` untuk deploy/rebuild/stop/...), sehingga worker lahir
 * **tanpa satu pun variabel** — `RAMES_DB_DIR` hilang ⇒ worker mencari app di
 * store `<repo>/database`, tidak menemukannya, lalu keluar senyap sebelum
 * menulis `runtime/logs/deploy/<appId>.log` (app tertinggal
 * `status=deploying, stage=queued`).
 *
 * Yang diuji (anak **sungguhan**: fork + exec, bukan seam):
 *  1. `$env = []` ⇒ anak tetap melihat variabel lingkungan proses induk
 *     (`PATH`/`HOME` + penanda unik yang diset induk);
 *  2. env tambahan tetap terkirim **dan menimpa** nilai induk (termasuk
 *     `RAMES_JOB_ID`, kontrak server agent);
 *  3. nilai non-string pada env tambahan di-`strval`-kan (pcntl_exec menuntut
 *     envp berisi string) alih-alih memicu TypeError di proses anak.
 *
 * Semua berkas penanda berada di direktori **temp** (`sys_get_temp_dir()`) dan
 * dibersihkan di `tearDown()`; tidak ada store/deploy nyata yang disentuh.
 * Variabel penanda memakai nama **non-produksi** ber-sufiks acak supaya tidak
 * bertabrakan dengan environment mesin uji.
 *
 * Dilewati (`markTestSkipped`) bila `pcntl_*` tidak tersedia (mis. SAPI tanpa
 * ekstensi pcntl) — tanpa pcntl, `spawn()` sendiri memang mengembalikan false.
 */
final class DetachedWorkerTest extends TestCase
{
    private string $tmp = '';

    private string $fixture = '';

    /** @var int|string|false disposisi SIGCHLD sebelum uji (dipulihkan di tearDown) */
    private int|string|false $prevSigchld = false;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            $this->markTestSkipped('pcntl_fork/pcntl_exec tidak tersedia — DetachedWorker::spawn() juga tidak aktif.');
        }

        $this->tmp = sys_get_temp_dir() . '/rames_detachedworker_' . bin2hex(random_bytes(6));
        if (!mkdir($this->tmp, 0700, true) && !is_dir($this->tmp)) {
            throw new RuntimeException("gagal membuat direktori temp: {$this->tmp}");
        }

        // `spawn()` menyetel SIGCHLD=SIG_IGN (auto-reap di worker HTTP). Disposisi
        // itu dipulihkan di tearDown supaya tidak bocor ke test lain di proses
        // PHPUnit yang sama.
        $this->prevSigchld = pcntl_signal_get_handler(SIGCHLD);

        $this->fixture = $this->tmp . '/record-env.php';
        file_put_contents($this->fixture, self::fixtureSource());
    }

    protected function tearDown(): void
    {
        if ($this->prevSigchld !== false && function_exists('pcntl_signal')) {
            @pcntl_signal(SIGCHLD, $this->prevSigchld);
        }

        GitTestFixture::removeDir($this->tmp);
    }

    /**
     * Skrip anak: tulis nilai variabel lingkungan yang diminta (argv[2..]) ke
     * berkas JSON `argv[1]`. Nilai yang tidak ada ditulis `null` supaya
     * "tidak diwariskan" bisa dibedakan dari "diwariskan tapi kosong".
     */
    private static function fixtureSource(): string
    {
        return <<<'PHP'
        <?php
        declare(strict_types=1);
        $out = (string) ($argv[1] ?? '');
        $record = [];
        foreach (array_slice($argv, 2) as $name) {
            $value = getenv((string) $name);
            $record[(string) $name] = $value === false ? null : $value;
        }
        file_put_contents($out, json_encode($record));
        exit(0);
        PHP;
    }

    /**
     * Skrip anak: muat berkas konfigurasi **sungguhan** yang dipakai gerbang mode
     * agent & resolusi store (`config/deploy.php` — berkas yang sama di-`include`
     * `config('deploy.mode')`/`config('deploy.sqlite_file')`) lalu tuliskan nilai
     * yang **dilihat** anak.
     *
     * Sengaja **tidak** memuat seluruh bootstrap (`support/bootstrap.php`): mode
     * agent menuntut TLS (`config/process.php` fail-closed) yang tak ada
     * hubungannya dengan env-inheritance yang diuji, dan bootstrap penuh sudah
     * dibuktikan jalan di probe end-to-end. Yang dikunci di sini: nilai yang
     * dibaca gerbang itu berasal dari lingkungan anak.
     */
    private static function deployConfigFixtureSource(string $repo): string
    {
        return sprintf(
            <<<'PHP'
            <?php
            declare(strict_types=1);
            if (!defined('BASE_PATH')) {
                define('BASE_PATH', %s);
            }
            require %s;
            $deploy = require %s;
            file_put_contents((string) ($argv[1] ?? ''), json_encode([
                'env_mode' => getenv('RAMES_MODE') === false ? null : (string) getenv('RAMES_MODE'),
                'config_mode' => (string) ($deploy['mode'] ?? ''),
                'sqlite_file' => (string) ($deploy['sqlite_file'] ?? ''),
            ]));
            exit(0);
            PHP,
            var_export($repo, true),
            var_export($repo . '/vendor/autoload.php', true),
            var_export($repo . '/config/deploy.php', true),
        );
    }

    /** Nama variabel penanda unik (non-produksi) per uji. */
    private function markerName(string $suffix): string
    {
        return 'RAMES_TEST_DW_' . strtoupper($suffix) . '_' . bin2hex(random_bytes(4));
    }

    /**
     * Jalankan `$fixture` lewat `DetachedWorker::spawn()` dan tunggu berkas
     * hasilnya (stdio anak dialihkan ke `/dev/null` + auto-reap ⇒ tidak bisa pakai
     * waitpid, jadi berkas penanda yang jadi kanal bukti).
     *
     * @param array<int,string>    $args argumen setelah berkas keluaran
     * @param array<string,string> $env  env tambahan (biasanya `[]` — justru itu yang diuji)
     * @return array<string,mixed> isi berkas JSON anak
     */
    private function runChild(string $fixture, array $args, array $env, string $out): array
    {
        $argv = array_merge([PHP_BINARY, $fixture, $out], $args);

        $pid = 0;
        $this->assertTrue(DetachedWorker::spawn($argv, $pid, $env), 'spawn() harus berhasil di-fork');
        $this->assertGreaterThan(0, $pid, 'pid anak harus terisi');

        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            if (is_file($out)) {
                $decoded = json_decode((string) @file_get_contents($out), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            usleep(20_000);
        }

        $this->fail("anak tidak menulis berkas penanda {$out} dalam 15 detik");
    }

    /**
     * @param array<int,string>    $names variabel lingkungan yang harus dibaca anak
     * @param array<string,string> $env
     * @return array<string,mixed>
     */
    private function spawnAndCollect(array $names, array $env, string $label): array
    {
        return $this->runChild($this->fixture, $names, $env, $this->tmp . '/child-' . $label . '.json');
    }

    public function testChildInheritsParentEnvironmentWhenNoExtraEnvGiven(): void
    {
        $dbDir = $this->markerName('db_dir');
        $sentinel = $this->markerName('sentinel');
        $dbDirValue = '/var/lib/rames-test-' . bin2hex(random_bytes(3));
        putenv($dbDir . '=' . $dbDirValue);
        putenv($sentinel . '=parent-value');

        try {
            $seen = $this->spawnAndCollect([$dbDir, $sentinel, 'PATH', 'HOME'], [], 'inherit');
        } finally {
            putenv($dbDir);
            putenv($sentinel);
        }

        // Inti bug: `$env = []` tidak boleh berarti "lingkungan kosong".
        $this->assertSame($dbDirValue, $seen[$dbDir], 'RAMES_DB_DIR sejenis (penanda) harus diwariskan ke anak');
        $this->assertSame('parent-value', $seen[$sentinel], 'variabel induk harus diwariskan ke anak');

        // PATH/HOME hilang ⇒ docker/git di worker berperilaku beda (mis. HOME
        // menentukan ~/.docker/config.json); keduanya harus ikut.
        $this->assertIsString($seen['PATH'], 'PATH harus diwariskan ke anak');
        $this->assertNotSame('', (string) $seen['PATH'], 'PATH anak tidak boleh kosong');
        $this->assertIsString($seen['HOME'], 'HOME harus diwariskan ke anak');
    }

    public function testExplicitEnvIsDeliveredAndOverridesParentValue(): void
    {
        $overlay = $this->markerName('overlay');
        $untouched = $this->markerName('untouched');
        $jobId = 'job-' . bin2hex(random_bytes(4));

        putenv($overlay . '=from-parent');
        putenv($untouched . '=kept');

        try {
            $seen = $this->spawnAndCollect(
                [$overlay, $untouched, 'RAMES_JOB_ID'],
                [$overlay => 'from-caller', 'RAMES_JOB_ID' => $jobId],
                'overlay',
            );
        } finally {
            putenv($overlay);
            putenv($untouched);
        }

        // Env tambahan menimpa nilai induk…
        $this->assertSame('from-caller', $seen[$overlay], 'env tambahan harus menimpa nilai induk');
        // …tanpa mengorbankan sisa lingkungan induk…
        $this->assertSame('kept', $seen[$untouched], 'variabel induk lain harus tetap ikut terkirim');
        // …dan kontrak server agent (RAMES_JOB_ID ⇒ AgentJobStore) tetap utuh.
        $this->assertSame($jobId, $seen['RAMES_JOB_ID'], 'RAMES_JOB_ID harus sampai ke worker');
    }

    public function testNonStringEnvValuesAreCastToString(): void
    {
        // Seam `DeployWorkerLauncher`/`TemplatePullService` mengetik env sebagai
        // string, tapi `spawn()` menerima `array` apa adanya — nilai non-string
        // di-`strval`-kan supaya pcntl_exec tidak menerima tipe salah.
        $numeric = $this->markerName('numeric');

        $seen = $this->spawnAndCollect([$numeric], [$numeric => 8080], 'numeric');

        $this->assertSame('8080', $seen[$numeric], 'nilai non-string env harus menjadi string di anak');
    }

    /**
     * Mode runtime **agent** (`RAMES_MODE`) — efek luas perbaikan env.
     *
     * `cli/deploy.php` menolak `self_update` saat `config('deploy.mode') !== 'agent'`,
     * dan `config/deploy.php` menurunkannya dari `RAMES_MODE`. Sebelum perbaikan,
     * worker agent lahir tanpa env ⇒ mode jatuh ke `dashboard` ⇒ `self_update`
     * (dan op agent lain yang bergantung identitas mode) **selalu** ditolak —
     * sedangkan `CliDeployServerOpsTest` menyuntik env lewat `proc_open` sehingga
     * tidak pernah menangkap jalur spawn yang sebenarnya. Uji ini menutup celah itu
     * lewat anak sungguhan yang membaca `config/deploy.php` asli (tanpa menjalankan
     * self-update, tanpa Docker, tanpa menulis store), dan dipasangkan dengan
     * {@see CliDeployServerOpsTest::testSelfUpdateIsRefusedOutsideAgentMode} untuk
     * sisi gerbangnya.
     */
    public function testAgentRuntimeModeAndStoreDirAreVisibleToAWorkerChild(): void
    {
        $repo = dirname(__DIR__);
        $fixture = $this->tmp . '/read-deploy-config.php';
        file_put_contents($fixture, self::deployConfigFixtureSource($repo));

        $storeDir = $this->tmp . '/store';
        $prevMode = getenv('RAMES_MODE');
        $prevDbDir = getenv('RAMES_DB_DIR');

        putenv('RAMES_MODE=agent');
        putenv('RAMES_DB_DIR=' . $storeDir);

        try {
            $seen = $this->runChild($fixture, [], [], $this->tmp . '/child-agent-mode.json');
        } finally {
            // Dipulihkan **persis** (termasuk saat asalnya ada di lingkungan
            // proses PHPUnit) supaya config() yang dimuat tes lain tidak bergeser.
            $prevMode === false ? putenv('RAMES_MODE') : putenv('RAMES_MODE=' . $prevMode);
            $prevDbDir === false ? putenv('RAMES_DB_DIR') : putenv('RAMES_DB_DIR=' . $prevDbDir);
        }

        $this->assertSame('agent', $seen['env_mode'], 'anak harus melihat RAMES_MODE dari proses induk');
        $this->assertSame(
            'agent',
            $seen['config_mode'],
            'gerbang `self_update` membaca config("deploy.mode") — tanpa env ini mode jatuh ke dashboard dan op agent ditolak'
        );
        $this->assertSame(
            $storeDir . '/rames.sqlite',
            $seen['sqlite_file'],
            'worker agent harus membaca store dari RAMES_DB_DIR induk, bukan default <repo>/database'
        );
    }
}
