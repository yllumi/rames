<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regresi Verifier untuk one-line installer server AGENT:
 *  - `install.agent.sh` (bootstrap di root repo: mandiri/self-contained karena
 *    diunduh SEBELUM repo ada, clone ke `--dir` default `/opt/rames-agent`,
 *    prompt identitas hanya bila perlu, `host/install.sh --deps-only`, lalu
 *    `exec agent/install.sh` dengan argumen diteruskan apa adanya);
 *  - `host/install.sh --deps-only` (pasang dependensi host lalu keluar SEBELUM
 *    wizard `.env`/preflight/compose up — dipakai installer agent).
 *
 * Semua pengujian berjalan di SANDBOX temp (checkout tiruan + `id` palsu yang
 * mencetak 0 sehingga tidak butuh root nyata, apt-get/docker palsu di depan
 * `PATH`). Tidak ada `/etc`, Docker, systemd, atau data runtime (`database/`,
 * `apps/`, `runtime/`, `nginx-status/`) yang disentuh.
 *
 * Catatan determinisme prompt: `RAMES_PROMPT_FD=0` di-set eksplisit pada
 * hampir semua run — stdin adalah pipe (bukan TTY) di `proc_open`, jadi ini
 * setara kondisi `curl | sudo bash` tanpa terminal. Tanpa itu, installer bisa
 * membuka `/dev/tty` milik sesi PHPUnit dan menggantung menunggu jawaban.
 */
class InstallAgentShBootstrapTest extends TestCase
{
    private string $root;

    private string $tmp;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('installer agent hanya untuk POSIX (bash)');
        }
        if (self::which('bash') === null) {
            $this->markTestSkipped('butuh bash untuk menguji installer');
        }

        $this->root = dirname(__DIR__);
        $this->tmp = sys_get_temp_dir() . '/rames-install-agent-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            $this->removeRecursive($this->tmp);
        }
    }

    public function testSintaksSkripInstallerAgentValid(): void
    {
        foreach ([$this->root . '/install.agent.sh', $this->root . '/host/install.sh'] as $script) {
            $this->assertFileExists($script);
            $result = $this->runCommand(['bash', '-n', $script], $this->root);
            $this->assertSame(0, $result['code'], 'bash -n ' . basename($script) . ': ' . $result['stderr']);
            $this->assertSame('', trim($result['stderr']), 'bash -n harus senyap: ' . $script);
        }

        $this->assertTrue(is_executable($this->root . '/install.agent.sh'), 'install.agent.sh wajib executable (0755)');
    }

    public function testHelpBootstrapTanpaRootDanBentukPipe(): void
    {
        // Tanpa shim `id`: dijalankan sebagai user test dan TIDAK boleh menuntut root.
        $result = $this->runCommand([$this->root . '/install.agent.sh', '--help'], $this->root);
        $this->assertSame(0, $result['code'], '--help harus exit 0 tanpa root: ' . $result['stderr']);
        foreach (['--dir <path>', '--ref <ref>', '--repo <url>', '--no-deps', '--domain', '--port-range', '--bind', '--force'] as $needle) {
            $this->assertStringContainsString($needle, $result['stdout'], '--help bootstrap wajib menyebut: ' . $needle);
        }
        $this->assertStringContainsString('/opt/rames-agent', $result['stdout'], 'default dir agent wajib berbeda dari dashboard');
        $this->assertStringContainsString('instalasi PERTAMA', $result['stdout'], '--help wajib mencatat --domain wajib pada instalasi pertama');

        // Bentuk `curl ... | sudo bash -s -- --help` harus tetap jalan.
        $script = (string) file_get_contents($this->root . '/install.agent.sh');
        $piped = $this->runCommand(['bash', '-s', '--', '--help'], $this->root, $script);
        $this->assertSame(0, $piped['code'], 'bentuk pipe harus bisa --help: ' . $piped['stderr']);
        $this->assertStringContainsString('Bootstrap installer server AGENT', $piped['stdout']);

        // host/install.sh mendokumentasikan mode baru.
        $host = $this->runCommand([$this->root . '/host/install.sh', '--help'], $this->root);
        $this->assertSame(0, $host['code'], 'host/install.sh --help harus exit 0 tanpa root: ' . $host['stderr']);
        $this->assertStringContainsString('--deps-only', $host['stdout'], '--help host wajib menyebut --deps-only');
        $this->assertStringContainsString('Docker Engine + Compose v2', $host['stdout']);
    }

    public function testBootstrapMeneruskanArgumenAgentUtuh(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('pass-' . bin2hex(random_bytes(3)));

        $expected = [
            '--domain', 'agent.example.com',
            '--email', 'ops@example.com',
            '--port-range', '31000', '31999',
            '--bind', '0.0.0.0:8443',
            '--force',
        ];

        $result = $this->runBootstrap([
            '--dir', $checkout,
            '--repo', 'https://example.invalid/rames.git',
            '--ref', 'main',
            '--no-deps',
            ...$expected,
        ], $this->tmp);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('AGENT-ARGS: ' . implode(' ', $expected), $result['stdout'], 'argumen diteruskan apa adanya');
        $this->assertSame($expected, $this->lines((string) file_get_contents($checkout . '/agent/argv.out')), 'urutan & jumlah argumen agent tidak boleh berubah');

        $forwarded = (string) file_get_contents($checkout . '/agent/argv.out');
        foreach (['--dir', '--repo', '--ref', '--no-deps', $checkout, 'example.invalid'] as $bootstrapOnly) {
            $this->assertStringNotContainsString($bootstrapOnly, $forwarded, 'flag bootstrap tidak boleh ikut diteruskan: ' . $bootstrapOnly);
        }
    }

    public function testBootstrapMemakaiUlangCheckoutAgentTanpaReset(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('reuse-' . bin2hex(random_bytes(3)));
        file_put_contents($checkout . '/untracked-marker.txt', "SENTINEL\n");

        $result = $this->runBootstrap(['--dir', $checkout, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);
        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('memakai ulang TANPA reset/checkout', $result['stdout']);
        $this->assertFileExists($checkout . '/untracked-marker.txt', 'checkout lama tidak boleh direset');
        $this->assertSame("SENTINEL\n", (string) file_get_contents($checkout . '/untracked-marker.txt'));
        $this->assertSame(['--domain', 'agent.example.com'], $this->lines((string) file_get_contents($checkout . '/agent/argv.out')));

        // CWD yang sudah checkout Rames agent dipakai tanpa --dir.
        $inPlace = $this->runBootstrap(['--no-deps', '--domain', 'agent.example.com'], $checkout);
        $this->assertSame(0, $inPlace['code'], $inPlace['stdout'] . $inPlace['stderr']);
        $this->assertStringContainsString('Menggunakan checkout Rames agent yang sudah ada', $inPlace['stdout']);
    }

    public function testBootstrapMenolakDirektoriYangBukanCheckoutAgent(): void
    {
        $this->skipBootstrap();
        $dir = $this->tmp . '/bukan-rames-' . bin2hex(random_bytes(3));
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/catatan.txt', "x\n");

        $result = $this->runBootstrap(['--dir', $dir, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('bukan instalasi Rames', $result['stderr']);
        $this->assertFileExists($dir . '/catatan.txt', 'direktori yang ditolak tidak boleh diubah');
        $this->assertSame("x\n", (string) file_get_contents($dir . '/catatan.txt'));
    }

    public function testInstalasiPertamaTanpaDomainGagalNonInteraktif(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('nodomain-' . bin2hex(random_bytes(3)));
        mkdir($checkout . '/secrets', 0700, true);
        file_put_contents($checkout . '/secrets/agent.env', "AGENT_TOKEN=abc\n");

        $result = $this->runBootstrap(['--dir', $checkout, '--no-deps'], $this->tmp);

        $this->assertSame(1, $result['code'], 'instalasi pertama tanpa --domain & tanpa TTY wajib fail-fast: ' . $result['stdout']);
        $this->assertStringContainsString('--domain', $result['stderr'], 'pesan galat wajib menyebut --domain');
        $this->assertFileDoesNotExist($checkout . '/agent/argv.out', 'agent/install.sh tidak boleh dipanggil tanpa identitas');
        $this->assertSame("AGENT_TOKEN=abc\n", (string) file_get_contents($checkout . '/secrets/agent.env'), 'secrets tidak boleh disentuh');
    }

    public function testDomainTercatatDiAgentEnvMelewatiPrompt(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('envdomain-' . bin2hex(random_bytes(3)));
        mkdir($checkout . '/secrets', 0700, true);
        file_put_contents($checkout . '/secrets/agent.env', "APP_DOMAIN=ada.example.com\nAGENT_TOKEN=abc\n");

        $result = $this->runBootstrap(['--dir', $checkout, '--no-deps'], $this->tmp);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('Domain agent sudah tercatat', $result['stdout']);
        $this->assertSame([], $this->lines((string) file_get_contents($checkout . '/agent/argv.out')), 'tanpa flag agent ⇒ tidak ada argumen tambahan');
    }

    public function testArgumenTidakDikenalDitolak(): void
    {
        $result = $this->runCommand([$this->root . '/install.agent.sh', '--bogus'], $this->root);

        $this->assertSame(1, $result['code'], 'argumen tak dikenal wajib fail-fast');
        $this->assertStringContainsString('tidak dikenal', $result['stderr']);
    }

    public function testNoDepsTidakMemanggilHostInstaller(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('nodeps-' . bin2hex(random_bytes(3)));

        $result = $this->runBootstrap(['--dir', $checkout, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertFileDoesNotExist($checkout . '/host/argv.out', '--no-deps tidak boleh memanggil host/install.sh');
        $this->assertStringContainsString('dependensi host dilewati', $result['stdout']);
    }

    public function testDepsDipasangLewatHostInstallerDepsOnly(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('deps-' . bin2hex(random_bytes(3)));

        $result = $this->runBootstrap(['--dir', $checkout, '--domain', 'agent.example.com'], $this->tmp);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertSame(['--deps-only'], $this->lines((string) file_get_contents($checkout . '/host/argv.out')), 'host installer dipanggil mode --deps-only saja');
        $this->assertSame(['--domain', 'agent.example.com'], $this->lines((string) file_get_contents($checkout . '/agent/argv.out')));
    }

    public function testHostDepsOnlyBerhentiSebelumWizardEnv(): void
    {
        $sandbox = $this->tmp . '/hosts-' . bin2hex(random_bytes(4));
        mkdir($sandbox . '/host', 0755, true);
        copy($this->root . '/host/install.sh', $sandbox . '/host/install.sh');
        chmod($sandbox . '/host/install.sh', 0755);
        copy($this->root . '/.env.example', $sandbox . '/.env.example');

        $result = $this->runCommand(
            [$sandbox . '/host/install.sh', '--deps-only', '--no-deps'],
            $sandbox,
            null,
            $this->envWithShim($this->makeShim(['nginx', 'certbot']))
        );

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        // Klaim "siap" harus berasal dari pemeriksaan nyata (docker compose,
        // nginx, certbot) — bukan dicetak tanpa syarat.
        $this->assertStringContainsString('Dependensi host siap', $result['stdout']);
        $this->assertFileDoesNotExist($sandbox . '/.env', '--deps-only tidak boleh menyentuh .env');

        // Bila salah satu dependensi tidak siap (`docker compose` gagal), skrip
        // wajib memperingatkan alih-alih mengklaim siap.
        $broken = $this->tmp . '/broken-' . bin2hex(random_bytes(4));
        mkdir($broken . '/host', 0755, true);
        copy($this->root . '/host/install.sh', $broken . '/host/install.sh');
        chmod($broken . '/host/install.sh', 0755);
        copy($this->root . '/.env.example', $broken . '/.env.example');
        $brokenShim = $this->makeShim(['nginx', 'certbot']);
        file_put_contents($brokenShim . '/docker', "#!/bin/sh\nexit 1\n");
        chmod($brokenShim . '/docker', 0755);

        $notReady = $this->runCommand(
            [$broken . '/host/install.sh', '--deps-only', '--no-deps'],
            $broken,
            null,
            $this->envWithShim($brokenShim)
        );
        $this->assertSame(0, $notReady['code'], $notReady['stdout'] . $notReady['stderr']);
        $this->assertStringContainsString('Sebagian dependensi host belum siap', $notReady['stdout']);
        $this->assertStringNotContainsString('Dependensi host siap', $notReady['stdout']);

        $combined = $this->runCommand(
            [$sandbox . '/host/install.sh', '--deps-only', '--env-only'],
            $sandbox,
            null,
            $this->envWithShim($this->makeShim(['nginx', 'certbot']))
        );
        $this->assertSame(1, $combined['code'], '--deps-only + --env-only tidak masuk akal');
        $this->assertStringContainsString('--env-only', $combined['stderr']);
    }

    /**
     * Temuan 1 [SEDANG]: checkout Rames yang SUDAH TERPASANG sebagai dashboard
     * tidak boleh dipakai ulang oleh installer agent (dulu container/volume
     * `rames-agent` sempat naik di repo dashboard karena hanya struktur repo
     * yang diperiksa).
     */
    public function testBootstrapMenolakCheckoutDashboardTerpasang(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('dash-' . bin2hex(random_bytes(3)));
        file_put_contents($checkout . '/.env', "APP_URL=https://rames.example.com\n");
        file_put_contents($checkout . '/untracked-marker.txt', "SENTINEL\n");
        $envBefore = (string) file_get_contents($checkout . '/.env');
        $modeBefore = $this->mode($checkout . '/agent/install.sh');

        $result = $this->runBootstrap(
            ['--dir', $checkout, '--no-deps', '--domain', 'agent.example.com'],
            $this->tmp
        );

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('DASHBOARD', $result['stderr'], 'pesan wajib menyebut DASHBOARD: ' . $result['stderr']);
        $this->assertStringContainsString('--dir', $result['stderr']);
        $this->assertFileDoesNotExist($checkout . '/agent/argv.out', 'agent/install.sh tidak boleh dipanggil pada instalasi dashboard');
        $this->assertStringNotContainsString('AGENT-ARGS', $result['stdout']);
        $this->assertSame($envBefore, (string) file_get_contents($checkout . '/.env'), 'instalasi dashboard tidak boleh diubah');
        $this->assertSame("SENTINEL\n", (string) file_get_contents($checkout . '/untracked-marker.txt'));
        $this->assertSame($modeBefore, $this->mode($checkout . '/agent/install.sh'), 'direktori yang ditolak tidak boleh di-chmod');
    }

    /** Penanda dashboard lain: `database/apps.json` dan `apps/` berisi data. */
    public function testBootstrapMenolakPenandaDashboardLain(): void
    {
        $this->skipBootstrap();

        $appsJson = $this->makeAgentCheckout('dashjson-' . bin2hex(random_bytes(3)));
        mkdir($appsJson . '/database', 0755, true);
        file_put_contents($appsJson . '/database/apps.json', "{}\n");
        $result = $this->runBootstrap(['--dir', $appsJson, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);
        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('DASHBOARD', $result['stderr']);

        $appsData = $this->makeAgentCheckout('dashapps-' . bin2hex(random_bytes(3)));
        mkdir($appsData . '/apps/sibimo', 0755, true);
        $result = $this->runBootstrap(['--dir', $appsData, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);
        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('DASHBOARD', $result['stderr']);
    }

    /**
     * Prioritas penanda: dashboard MENANG atas agent. Repo yang sudah agent
     * sekaligus dashboard (kasus nyata: agent pernah naik di repo dashboard)
     * tetap DITOLAK, bukan dipakai ulang.
     */
    public function testPenandaDashboardMenangAtasPenandaAgent(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('both-' . bin2hex(random_bytes(3)));
        mkdir($checkout . '/secrets', 0700, true);
        file_put_contents($checkout . '/secrets/agent.env', "APP_DOMAIN=agent.example.com\n");
        file_put_contents($checkout . '/.env', "APP_URL=https://rames.example.com\n");

        $result = $this->runBootstrap(['--dir', $checkout, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);

        $this->assertSame(1, $result['code'], $result['stdout']);
        $this->assertStringContainsString('DASHBOARD', $result['stderr']);
        $this->assertFileDoesNotExist($checkout . '/agent/argv.out');
        $this->assertSame("APP_DOMAIN=agent.example.com\n", (string) file_get_contents($checkout . '/secrets/agent.env'));
    }

    /** Temuan 1: checkout Rames yang sudah terpasang sebagai agent dipakai ulang. */
    public function testBootstrapMemakaiUlangCheckoutAgentTerpasang(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('installed-' . bin2hex(random_bytes(3)));
        mkdir($checkout . '/secrets', 0700, true);
        file_put_contents($checkout . '/secrets/agent.env', "APP_DOMAIN=agent.example.com\nAGENT_TOKEN=abc\n");

        $result = $this->runBootstrap(['--dir', $checkout, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('memakai ulang TANPA reset/checkout', $result['stdout']);
        $this->assertStringNotContainsString('belum berisi identitas agent', $result['stdout']);
        $this->assertSame(['--domain', 'agent.example.com'], $this->lines((string) file_get_contents($checkout . '/agent/argv.out')));
        $this->assertFileExists($checkout . '/secrets/agent.env');
    }

    /** Temuan 1: checkout Rames yang belum diinstal (fresh clone) dipakai + warn. */
    public function testBootstrapMemakaiUlangCheckoutBelumDiinstalDenganWarning(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('fresh-' . bin2hex(random_bytes(3)));

        $result = $this->runBootstrap(['--dir', $checkout, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('memakai ulang TANPA reset/checkout', $result['stdout']);
        $this->assertStringContainsString('belum berisi identitas agent', $result['stdout'], 'fresh checkout wajib diperingatkan: ' . $result['stdout']);
        $this->assertSame(['--domain', 'agent.example.com'], $this->lines((string) file_get_contents($checkout . '/agent/argv.out')));
    }

    /** Temuan 3: `--port-range` tidak boleh menelan flag berikutnya sebagai nilai. */
    public function testPortRangeMenolakFlagSebagaiNilaiKedua(): void
    {
        foreach (
            [
                ['--port-range', '31000', '--force'],
                ['--port-range', '31000'],
                ['--port-range', '31000', 'abc'],
                ['--port-range', '0', '31999'],
                ['--port-range', '31000', '70000'],
                ['--port-range', '-1', '31999'],
                ['--port-range', '', '31999'],
            ] as $args
        ) {
            $result = $this->runCommand([$this->root . '/install.agent.sh', ...$args], $this->root);
            $this->assertSame(1, $result['code'], 'harus fail-fast: ' . implode(' ', $args) . ' → ' . $result['stdout']);
            $this->assertStringContainsString('--port-range butuh DUA bilangan bulat 1..65535', $result['stderr'], implode(' ', $args));
            $this->assertStringContainsString('ERROR:', $result['stderr']);
        }

        // Nilai sah tetap diteruskan apa adanya (dibuktikan juga oleh
        // testBootstrapMeneruskanArgumenAgentUtuh).
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('ports-' . bin2hex(random_bytes(3)));
        $ok = $this->runBootstrap(
            ['--dir', $checkout, '--no-deps', '--domain', 'agent.example.com', '--port-range', '31000', '31999'],
            $this->tmp
        );
        $this->assertSame(0, $ok['code'], $ok['stdout'] . $ok['stderr']);
        $this->assertStringContainsString('--port-range 31000 31999', $ok['stdout']);
    }

    /** Temuan 6: nilai opsi yang hilang/kosong → pesan `ERROR:` milik skrip. */
    public function testOpsiBernilaiWajibMenolakNilaiKosong(): void
    {
        foreach ([['--dir'], ['--ref'], ['--repo']] as $args) {
            $result = $this->runCommand([$this->root . '/install.agent.sh', ...$args], $this->root);
            $this->assertSame(1, $result['code'], implode(' ', $args) . ' tanpa nilai harus exit 1');
            $this->assertStringStartsWith('ERROR:', ltrim($result['stderr']));
            $this->assertStringContainsString($args[0] . ' butuh nilai.', $result['stderr']);
            $this->assertDoesNotMatchRegularExpression('/line \d+:/', $result['stderr'], 'tidak boleh bocor pesan bash: ' . $result['stderr']);
        }

        foreach ([['--dir', ''], ['--ref', ''], ['--repo', '']] as $args) {
            $result = $this->runCommand([$this->root . '/install.agent.sh', ...$args], $this->root);
            $this->assertSame(1, $result['code'], implode(' ', $args) . ' dengan nilai kosong harus exit 1');
            $this->assertStringStartsWith('ERROR:', ltrim($result['stderr']));
            $this->assertDoesNotMatchRegularExpression('/line \d+:/', $result['stderr']);
        }
    }

    /** Temuan 2: checkout BARU di-clone tetap disiapkan executable. */
    public function testFreshCloneTetapMenchmodSkripHost(): void
    {
        $this->skipBootstrap();
        $target = $this->tmp . '/clone-' . bin2hex(random_bytes(3));

        $result = $this->runCommand(
            [$this->root . '/install.agent.sh', '--dir', $target, '--no-deps', '--domain', 'agent.example.com'],
            $this->tmp,
            null,
            $this->envWithShim($this->makeCloneShim())
        );

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('Repo terunduh', $result['stdout']);
        $this->assertSame(0755, $this->mode($target . '/host/install.sh'), 'clone baru boleh chmod +x host/*.sh');
        $this->assertSame(0755, $this->mode($target . '/agent/install.sh'));
        $this->assertStringContainsString('FRESH-AGENT-ARGS: --domain agent.example.com', $result['stdout']);
    }

    /**
     * Temuan 2: checkout yang dipakai ulang TIDAK boleh di-chmod (dulu
     * host/*.sh 644→755 membuat `git diff` kotor), tetapi agent/install.sh
     * yang tidak executable tetap bisa dijalankan.
     */
    public function testReuseTidakMengubahModeSkripHost(): void
    {
        $this->skipBootstrap();
        $checkout = $this->makeAgentCheckout('modes-' . bin2hex(random_bytes(3)));
        $watcher = $checkout . '/host/nginx-reload-watcher.sh';
        file_put_contents($watcher, "#!/usr/bin/env bash\nexit 0\n");
        chmod($checkout . '/host/install.sh', 0644);
        chmod($watcher, 0644);
        chmod($checkout . '/agent/install.sh', 0644);

        $before = [
            'host/install.sh' => $this->mode($checkout . '/host/install.sh'),
            'host/nginx-reload-watcher.sh' => $this->mode($watcher),
            'agent/install.sh' => $this->mode($checkout . '/agent/install.sh'),
        ];

        $result = $this->runBootstrap(['--dir', $checkout, '--no-deps', '--domain', 'agent.example.com'], $this->tmp);

        $this->assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        foreach ($before as $rel => $mode) {
            $this->assertSame($mode, $this->mode($checkout . '/' . $rel), 'reuse tidak boleh mengubah mode: ' . $rel);
        }
        $this->assertSame(0644, $before['host/nginx-reload-watcher.sh']);
        $this->assertFileExists($checkout . '/agent/argv.out', 'agent/install.sh non-executable harus tetap dijalankan (lewat bash)');
        $this->assertSame(['--domain', 'agent.example.com'], $this->lines((string) file_get_contents($checkout . '/agent/argv.out')));
    }

    /**
     * Checkout Rames-mode-agent tiruan: cukup berkas penanda yang diperiksa
     * installer + stub `host/install.sh`/`agent/install.sh` yang mencatat argv.
     */
    private function makeAgentCheckout(string $name): string
    {
        $checkout = $this->tmp . '/' . $name;
        mkdir($checkout . '/host', 0755, true);
        mkdir($checkout . '/agent', 0755, true);
        mkdir($checkout . '/app', 0755, true);

        file_put_contents($checkout . '/docker-compose.agent.yml', "services: {}\n");
        file_put_contents($checkout . '/app/functions.php', "<?php\n");

        file_put_contents($checkout . '/host/install.sh', <<<'STUB'
#!/usr/bin/env bash
: > "$(dirname "${BASH_SOURCE[0]}")/argv.out"
for a in "$@"; do printf '%s\n' "$a" >> "$(dirname "${BASH_SOURCE[0]}")/argv.out"; done
exit 0
STUB);
        chmod($checkout . '/host/install.sh', 0755);

        file_put_contents($checkout . '/agent/install.sh', <<<'STUB'
#!/usr/bin/env bash
: > "$(dirname "${BASH_SOURCE[0]}")/argv.out"
for a in "$@"; do printf '%s\n' "$a" >> "$(dirname "${BASH_SOURCE[0]}")/argv.out"; done
printf 'AGENT-ARGS: %s\n' "$*"
exit 0
STUB);
        chmod($checkout . '/agent/install.sh', 0755);

        return $checkout;
    }

    /**
     * Shim dir berisi `id` palsu (mencetak 0 ⇒ bootstrap merasa root, tanpa
     * sudo nyata), `apt-get` palsu (tidak pernah menyentuh paket host), dan
     * (opsional) binari tambahan yang sukses — mis. `nginx`/`certbot` agar
     * pemeriksaan dependensi `host/install.sh --deps-only` deterministik.
     *
     * @param array<int,string> $extraBins
     */
    private function makeShim(array $extraBins = []): string
    {
        $shim = $this->tmp . '/bin-' . bin2hex(random_bytes(3));
        mkdir($shim, 0755, true);

        $stub = static function (string $path): void {
            file_put_contents($path, "#!/bin/sh\nexit 0\n");
            chmod($path, 0755);
        };

        file_put_contents($shim . '/id', "#!/bin/sh\necho 0\n");
        chmod($shim . '/id', 0755);
        $stub($shim . '/apt-get');
        // git palsu: sandbox TIDAK pernah clone (checkout sudah ada / direktori
        // bukan-Rames ditolak lebih dulu), jadi `git` hanya dipakai `is_git_repo`.
        file_put_contents($shim . '/git', "#!/bin/sh\nexit 1\n");
        chmod($shim . '/git', 0755);
        // `docker compose version` dipakai pemeriksaan dependensi host.
        $stub($shim . '/docker');

        foreach ($extraBins as $bin) {
            $stub($shim . '/' . $bin);
        }

        return $shim;
    }

    /**
     * Shim dengan `git clone` palsu: membuat checkout tiruan (mode 0644 untuk
     * `host/*.sh` + `agent/install.sh`) di argumen terakhir, sehingga cabang
     * "checkout baru di-clone" bisa diuji tanpa jaringan.
     */
    private function makeCloneShim(): string
    {
        $shim = $this->tmp . '/clone-bin-' . bin2hex(random_bytes(3));
        mkdir($shim, 0755, true);

        file_put_contents($shim . '/id', "#!/bin/sh\necho 0\n");
        file_put_contents($shim . '/apt-get', "#!/bin/sh\nexit 0\n");
        file_put_contents($shim . '/git', <<<'SH'
#!/usr/bin/env bash
# git palsu: "clone" = buat checkout tiruan di argumen terakhir.
for last in "$@"; do :; done
mkdir -p "$last/host" "$last/agent" "$last/app"
printf 'services: {}\n' > "$last/docker-compose.agent.yml"
printf '<?php\n' > "$last/app/functions.php"
printf '#!/usr/bin/env bash\nexit 0\n' > "$last/host/install.sh"
printf '#!/usr/bin/env bash\nprintf "FRESH-AGENT-ARGS: %%s\n" "$*"\n' > "$last/agent/install.sh"
chmod 644 "$last/host/install.sh" "$last/agent/install.sh"
exit 0
SH);
        chmod($shim . '/git', 0755);
        chmod($shim . '/id', 0755);
        chmod($shim . '/apt-get', 0755);

        return $shim;
    }

    /** Mode 0777 berkas (tanpa bit tipe) — untuk memastikan reuse tak chmod. */
    private function mode(string $path): int
    {
        $this->assertFileExists($path);

        return fileperms($path) & 0777;
    }

    /**
     * @param array<int,string> $args
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runBootstrap(array $args, string $cwd): array
    {
        return $this->runCommand(
            [$this->root . '/install.agent.sh', ...$args],
            $cwd,
            null,
            $this->envWithShim($this->makeShim())
        );
    }

    /** @return array<string,string> */
    private function envWithShim(string $shimDir): array
    {
        $env = getenv();
        $env['PATH'] = $shimDir . ':' . (string) getenv('PATH');
        // stdin bukan TTY (pipe) — samakan dengan `curl | sudo bash` tanpa terminal.
        $env['RAMES_PROMPT_FD'] = '0';

        return $env;
    }

    private function skipBootstrap(): void
    {
        $osRelease = is_readable('/etc/os-release') ? (string) file_get_contents('/etc/os-release') : '';
        if (!preg_match('/^ID(_LIKE)?=.*(ubuntu|debian)/mi', $osRelease)) {
            $this->markTestSkipped('bootstrap hanya mendukung Ubuntu/Debian (/etc/os-release tidak cocok)');
        }
        // `git` tidak disyaratkan: sandbox tidak pernah clone (lihat makeShim()).
    }

    /**
     * @param array<int,string> $command
     * @param array<string,string>|null $env
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd, ?string $stdin = null, ?array $env = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            $this->fail('proc_open gagal: ' . implode(' ', $command));
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => (int) proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<int,string> */
    private function lines(string $content): array
    {
        return $content === '' ? [] : explode("\n", rtrim($content, "\n"));
    }

    private static function which(string $bin): ?string
    {
        $path = (string) getenv('PATH');

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = $dir . DIRECTORY_SEPARATOR . $bin;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
