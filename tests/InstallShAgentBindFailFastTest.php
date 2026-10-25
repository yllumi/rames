<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regresi Verifier untuk `agent/install.sh` — dijalankan di SANDBOX temp
 * (salinan `agent/install.sh` + `docker-compose.agent.yml` + `docker` palsu di
 * depan `PATH`), jadi:
 *  - instalasi di repo TIDAK tersentuh (`secrets/`, `apps/`, `runtime/`,
 *    `database/`, `nginx-status/` sandbox semuanya di dalam direktori temp);
 *  - TIDAK ada Docker/container nyata yang dijalankan (semua pemanggilan
 *    `docker` ditangani skrip palsu; certbot tidak pernah dipanggil installer).
 *
 * Kontrak yang dikunci test ini (empat bug/regresi yang pernah lolos):
 *  1. `--bind`/`AGENT_BIND` WAJIB `<ip|host>:<port>` (port 1..65535) dan
 *     ditolak **fail-fast** — dulu `8443`/`:8443` lolos, ditulis ke
 *     `secrets/agent.env`, lalu `config/process.php` menolaknya saat start
 *     (container agent tidak pernah hidup) — dan `0.0.0.0:99999` lolos selama
 *     `AGENT_PORT_PUBLISH` diset sama (mapping publish mustahil dibuat).
 *  2. Dalam arah sebaliknya installer TIDAK boleh lebih ketat daripada runtime:
 *     bagian **redundan** — skema `http(s)://` (case-insensitive) dan garis
 *     miring penutup berulang — DITOLERANSI persis seperti normalisasi
 *     `config/process.php:99` (`trim()` → `preg_replace('#^https?://#i', …)` →
 *     `rtrim(…, '/')`), nilainya ditulis **VERBATIM** ke `secrets/agent.env`,
 *     dan hanya memicu **TEPAT SATU** `warn`. Port untuk koherensi
 *     bind↔publish adalah **port hasil parsing** (`0.0.0.0:9000/` ⇒ `9000`,
 *     bukan `9000/`) — penguraian ulang `${EFFECTIVE_BIND##*:}` di `main()`
 *     akan menolak nilai yang justru sah di runtime.
 *  3. Berkas env ber-CRLF (editor Windows) TIDAK boleh menggagalkan rerun
 *     (dulu: `ERROR: ADMIN_EMAIL … memuat spasi/tab/baris baru`, pesan
 *     menyesatkan karena `\r` tak terlihat) dan hasil tulis selalu LF.
 *  4. Wilayah operator (setelah `# rames-installer:end`) tidak boleh diisi
 *     komentar header installer versi LAMA saat upgrade dari berkas
 *     pra-sentinel, sedangkan kunci operator tetap dipertahankan.
 *  5. Blok onboarding wajib menyebut **dua** kelompok port: port agent
 *     (allowlist IP control plane) **dan** port web publik `80`/`443`
 *     (terbuka untuk semua). Instruksi lama hanya menyebut `8443`, sehingga
 *     operator yang mengikutinya persis meninggalkan `80`/`443` tertutup dan
 *     penerbitan SSL gagal `Timeout during connect` (ARCHITECTURE §8 GOTCHA 52).
 */
class InstallShAgentBindFailFastTest extends TestCase
{
    private const DOMAIN = 'lab.example.com';
    private const UP_MARKER = 'FAKE-DOCKER-UP';

    /**
     * Komentar header yang PERNAH ditulis installer versi lama ke wilayah
     * operator (format pra-sentinel) — SENGAJA didaftar ulang di sini (bukan
     * membaca `LEGACY_INSTALLER_HEADER_LINES` dari skrip yang diuji) supaya test
     * ini jadi oracle independen: menghapus satu entri di skrip ⇒ test merah.
     *
     * Provenance (diverifikasi ulang `git show <rev>:agent/install.sh` + `cmp`):
     * tiga baris dari `7a3c644:252-254`, dua baris dari `dafdabd:336-339` (varian
     * kutip tunggal + catatan backtick); kedua revisi itu belum punya sentinel
     * `rames-installer` sama sekali sehingga seluruh header mendarat di wilayah
     * operator. `102a403` sudah menaruh header ini DI DALAM blok kelolaan.
     */
    private const LEGACY_HEADER = [
        '# Rames agent — dibuat oleh agent/install.sh. JANGAN di-commit (/secrets/ gitignored).',
        '# Sumber tunggal identitas + rahasia agent: diinjeksi ke container lewat',
        '# `env_file: ./secrets/agent.env` di docker-compose.agent.yml.',
        "# 'env_file: ./secrets/agent.env' di docker-compose.agent.yml.",
        '# (tanpa backtick: here-doc ini tanpa kutip → backtick = command substitution)',
    ];

    private string $tmp;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('installer agent hanya untuk host POSIX');
        }
        foreach (['bash', 'openssl'] as $bin) {
            if (self::whichBinary($bin) === null) {
                $this->markTestSkipped("butuh {$bin}: installer menolak jalan tanpa prasyarat itu");
            }
        }

        $this->tmp = sys_get_temp_dir() . '/frames-installsh-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->tmp);
    }

    /** Sintaks skrip valid (guard pertama sebelum apa pun). */
    public function testSintaksInstallShValid(): void
    {
        $result = $this->runProcess(['bash', '-n', dirname(__DIR__) . '/agent/install.sh'], dirname(__DIR__));

        $this->assertSame(0, $result['code'], 'bash -n: ' . $result['stderr']);
        $this->assertSame('', $result['stderr']);
    }

    /** `--help` menjelaskan bentuk wajib `<ip|host>:<port>`. */
    public function testHelpMenjelaskanBentukIpPort(): void
    {
        $result = $this->runProcess([dirname(__DIR__) . '/agent/install.sh', '--help'], dirname(__DIR__));

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('--bind <ip:port>', $result['stdout']);
        $this->assertStringContainsString('<ip|host>:<port>', $result['stdout'], 'bentuk wajib dijelaskan di --help');
        $this->assertStringContainsString('tanpa \':\' ditolak', $result['stdout'], 'penolakan nilai tanpa titik dua didokumentasikan');
        $this->assertStringContainsString('AGENT_PORT_PUBLISH', $result['stdout']);
    }

    /**
     * Onboarding wajib memuat DUA kelompok port firewall. Mengikuti instruksi
     * yang hanya menyebut port agent (`8443`) membuat `80`/`443` tetap tertutup
     * — certbot lalu gagal `Timeout during connect` walau Rames & DNS benar.
     */
    public function testOnboardingMewajibkanPortWebPublikTerbuka(): void
    {
        $dir = $this->sandbox();
        $result = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12']);
        $out = $result['stdout'];

        $this->assertSame(0, $result['code'], $out);
        $this->assertStringContainsString('sudo ufw allow 80,443/tcp', $out, 'port web publik 80/443 wajib dicetak');
        $this->assertStringContainsString(
            'sudo ufw allow from <ip-control-plane> to any port 8443 proto tcp',
            $out,
            'port agent tetap allowlist IP control plane'
        );
        $this->assertStringContainsString('JANGAN allowlist IP', $out, 'aturan port web berbeda dari port agent');
        $this->assertStringContainsString('Timeout during connect', $out, 'operator diberi tahu gejala bila 80 tertutup');
        $this->assertStringContainsString(
            'http://<app>.' . self::DOMAIN . '/',
            $out,
            'perintah verifikasi memakai domain efektif server ini'
        );
        // Heredoc `print_onboarding` TANPA kutip → backtick akan dieksekusi shell.
        $this->assertStringNotContainsString('`', $out, 'onboarding tidak boleh memuat backtick (command substitution)');
    }

    /** @return array<string,array{0:string}> */
    public static function invalidBindProvider(): array
    {
        return [
            'tanpa titik dua (8443)' => ['8443'],
            'host kosong (:8443)' => [':8443'],
            'port kosong (0.0.0.0:)' => ['0.0.0.0:'],
            'IPv6 tanpa kurung' => ['::1:8443'],
            'IPv4 kurang oktet' => ['1.2.3:8443'],
            'IPv4 lebih oktet' => ['1.2.3.4.5:8443'],
            'oktet > 255' => ['300.1.1.1:8443'],
            'oktet nol di depan' => ['01.2.3.4:8443'],
            'hostname tak sah (_)' => ['bad_host:8443'],
            'port 0' => ['0.0.0.0:0'],
            'port > 65535' => ['0.0.0.0:65536'],
            'port bukan angka' => ['0.0.0.0:abc'],
        ];
    }

    /**
     * Nilai `--bind` yang memuat bagian **redundan** (skema `http(s)://` dan/atau
     * garis miring penutup, satu atau lebih) — WAJIB **ditoleransi**, bukan
     * ditolak: runtime `config/process.php:99` membuang keduanya sendiri, jadi
     * `https://0.0.0.0:8443/` ≡ `0.0.0.0:8443` (bisa di-bind). Tanpa toleransi
     * ini setiap rerun installer pada env yang sudah memuat nilai berskema
     * abort, padahal agent-nya sehat.
     *
     * @return array<string,array{0:string,1:array<int,string>}> bind + potongan redaksi
     *                                                             warn yang WAJIB disebut
     */
    public static function toleratedBindProvider(): array
    {
        return [
            'skema https' => ['https://0.0.0.0:8443', ['skema "https://"']],
            'skema http' => ['http://0.0.0.0:8443', ['skema "http://"']],
            'garis miring penutup' => ['0.0.0.0:8443/', ['garis miring penutup ("/")']],
            'garis miring penutup berulang' => ['0.0.0.0:8443//', ['garis miring penutup ("/")']],
            'skema + garis miring penutup' => ['https://0.0.0.0:8443/', ['skema "https://"', 'garis miring penutup ("/")']],
            'skema KAPITAL (case-insensitive)' => ['HTTPS://0.0.0.0:8443', ['skema "HTTPS://"']],
        ];
    }

    /**
     * Setelah SEMUA toleransi di atas, nilai tetap tanpa `:` ⇒ `AGENT_BIND`
     * ditolak runtime (`config/process.php:100`, `RuntimeException` saat start)
     * ⇒ installer WAJIB menolaknya fail-fast. Dua nilai pertama = kasus yang
     * paling mudah "kebablasan" bila toleransi garis miring penutup dibuat
     * terlalu longgar (`0.0.0.0/` ⇒ `0.0.0.0`; `https://0.0.0.0` ⇒ `0.0.0.0`).
     *
     * @return array<string,array{0:string}>
     */
    public static function bindTanpaTitikDuaSetelahToleransiProvider(): array
    {
        return [
            'garis miring saja (0.0.0.0/)' => ['0.0.0.0/'],
            'skema tanpa port (https://0.0.0.0)' => ['https://0.0.0.0'],
            'skema + garis miring tanpa port (http://0.0.0.0/)' => ['http://0.0.0.0/'],
        ];
    }

    /**
     * @dataProvider toleratedBindProvider
     * @param array<int,string> $redundant
     */
    public function testBindRedundanDitoleransiDitulisVerbatimDanSatuWarn(string $bind, array $redundant): void
    {
        $dir = $this->sandbox();

        $result = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12', '--bind', $bind]);

        $this->assertSame(0, $result['code'], $bind . ' harus DITERIMA (runtime membuang bagian redundan) | ' . $result['stdout']);
        $this->assertStringContainsString(self::UP_MARKER, $result['stdout'], 'agent dijalankan via compose');

        // TEPAT SATU baris warn yang membahas AGENT_BIND — bukan nol (operator
        // tidak diberi tahu) dan bukan dua (bertele-tele). Warn lain (mis.
        // ADMIN_EMAIL) tidak ikut dihitung.
        $warns = $this->bindWarnLines($result['stdout']);
        $this->assertCount(1, $warns, $bind . ': warn AGENT_BIND wajib tepat satu baris | ' . $result['stdout']);
        foreach ($redundant as $part) {
            $this->assertStringContainsString($part, $warns[0], 'warn menyebut bagian yang redundan');
        }
        $this->assertStringContainsString('0.0.0.0:8443', $warns[0], 'warn menyebut bentuk kanonik pengganti');
        $this->assertSame(1, substr_count($result['stdout'], 'redundan & ditoleransi'), 'warn gabungan, bukan dua peringatan');

        // Nilai operator TIDAK dinormalisasi ulang oleh installer (hindari diff
        // tak terduga di berkas yang diaudit operator) — apa adanya, 0600.
        $env = (string) file_get_contents($dir . '/secrets/agent.env');
        $this->assertStringContainsString("AGENT_BIND={$bind}\n", $env, 'nilai ditulis VERBATIM (tanpa normalisasi)');
        $this->assertSame(1, substr_count($env, 'AGENT_BIND='), 'kunci AGENT_BIND tidak boleh ganda');
        $this->assertSame(0600, fileperms($dir . '/secrets/agent.env') & 0777, 'env berisi rahasia wajib 0600');
    }

    /**
     * @dataProvider bindTanpaTitikDuaSetelahToleransiProvider
     */
    public function testBindTanpaTitikDuaSetelahToleransiDitolakFailFast(string $bind): void
    {
        $dir = $this->sandbox();

        $result = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12', '--bind', $bind]);

        $this->assertSame(1, $result['code'], 'wajib ditolak: ' . $bind . ' | ' . $result['stdout']);
        $combined = $result['stdout'] . $result['stderr'];
        $this->assertMatchesRegularExpression('/(--bind|AGENT_BIND)/', $combined, 'galat menyebut penyebabnya');
        $this->assertStringContainsString('IP:PORT', $combined, 'galat menyebut bentuk wajib');
        $this->assertDirectoryDoesNotExist($dir . '/secrets', 'fail-fast: TIDAK boleh ada efek samping sebelum validasi');
        $this->assertDirectoryDoesNotExist($dir . '/apps');
        $this->assertDirectoryDoesNotExist($dir . '/runtime');
        $this->assertDirectoryDoesNotExist($dir . '/nginx-status');
        $this->assertStringNotContainsString(self::UP_MARKER, $result['stdout'], 'agent tidak boleh dijalankan');
        $this->assertStringNotContainsString(
            'redundan',
            $result['stdout'],
            'warn toleransi tidak boleh mendahului fail-fast (peringatan = nilai sudah sah)'
        );
    }

    /**
     * Koherensi `AGENT_BIND` ↔ `AGENT_PORT_PUBLISH` WAJIB memakai **port hasil
     * parsing** (out-param `VALIDATED_BIND_PORT`), bukan
     * `${EFFECTIVE_BIND##*:}` pada nilai MENTAH: untuk `0.0.0.0:9000/`
     * penguraian ulang menghasilkan `9000/` sehingga nilai yang SAH di runtime
     * justru ditolak sebagai "port bukan bilangan bulat". Dibuktikan dua arah.
     */
    public function testKoherensiBindPublishMemakaiPortHasilParsing(): void
    {
        $bind = '0.0.0.0:9000/';
        $args = ['--domain', self::DOMAIN, '--ip', '10.0.0.12', '--bind', $bind];

        $dir = $this->sandbox();
        $ok = $this->runInstaller($dir, $args, ['AGENT_PORT_PUBLISH' => '9000']);
        $this->assertSame(0, $ok['code'], 'port hasil parsing (9000) cocok dengan AGENT_PORT_PUBLISH | ' . $ok['stdout']);
        $this->assertStringContainsString('publish=9000', $ok['stdout'], 'port publish efektif = 9000');
        $this->assertStringContainsString(
            "AGENT_BIND={$bind}\n",
            (string) file_get_contents($dir . '/secrets/agent.env'),
            'nilai mentah tetap ditulis verbatim'
        );

        $dir = $this->sandbox();
        $bad = $this->runInstaller($dir, $args);
        $this->assertSame(1, $bad['code'], 'tanpa AGENT_PORT_PUBLISH wajib ditolak | ' . $bad['stdout']);
        $this->assertStringContainsString('AGENT_PORT_PUBLISH=9000', $bad['stderr'], 'pesan memakai port HASIL PARSING');
        $this->assertStringNotContainsString('9000/', $bad['stderr'], 'port mentah (`9000/`) tidak boleh bocor ke pesan galat');
        $this->assertDirectoryDoesNotExist($dir . '/secrets', 'fail-fast: TIDAK boleh ada efek samping');
    }

    /**
     * @dataProvider invalidBindProvider
     */
    public function testBindTidakValidDitolakFailFast(string $bind): void
    {
        $dir = $this->sandbox();

        $result = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12', '--bind', $bind]);

        $this->assertSame(1, $result['code'], 'wajib ditolak: ' . $bind . ' | ' . $result['stdout']);
        $combined = $result['stdout'] . $result['stderr'];
        // Pesan dibedakan sumbernya: dari opsi CLI (`--bind`) vs baris berkas
        // (`AGENT_BIND di <berkas>`) — keduanya wajib menyebut kunci/opsi penyebab.
        $this->assertMatchesRegularExpression('/(--bind|AGENT_BIND)/', $combined, 'galat menyebut penyebabnya');
        $this->assertDirectoryDoesNotExist($dir . '/secrets', 'fail-fast: TIDAK boleh ada efek samping sebelum validasi');
        $this->assertDirectoryDoesNotExist($dir . '/apps');
        $this->assertDirectoryDoesNotExist($dir . '/runtime');
        $this->assertDirectoryDoesNotExist($dir . '/nginx-status');
        $this->assertStringNotContainsString(self::UP_MARKER, $result['stdout'], 'agent tidak boleh dijalankan');
    }

    /** @return array<string,array{0:string}> */
    public static function validBindProvider(): array
    {
        return [
            'semua interface' => ['0.0.0.0:8443'],
            'loopback' => ['127.0.0.1:8443'],
            'hostname' => ['localhost:8443'],
            'IPv6 wildcard' => ['[::]:8443'],
            'IPv6 link-local' => ['[fe80::1]:8443'],
            'IPv4 lain' => ['10.11.12.13:8443'],
        ];
    }

    /**
     * @dataProvider validBindProvider
     */
    public function testBindValidDiterimaDanDitulisApaAdanya(string $bind): void
    {
        $dir = $this->sandbox();

        $result = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12', '--bind', $bind]);

        $this->assertSame(0, $result['code'], $bind . ' | ' . $result['stdout']);
        $this->assertStringContainsString(self::UP_MARKER, $result['stdout'], 'agent dijalankan via compose');
        $this->assertStringContainsString(
            "AGENT_BIND={$bind}" . "\n",
            (string) file_get_contents($dir . '/secrets/agent.env')
        );
        $this->assertSame(0600, fileperms($dir . '/secrets/agent.env') & 0777, 'env berisi rahasia wajib 0600');
    }

    /**
     * Port di luar 1..65535 yang KONSISTEN dengan `AGENT_PORT_PUBLISH`: cek
     * koherensi bind↔publish tidak menangkapnya (nilainya sama), jadi
     * satu-satunya penjaga adalah rentang di `validate_agent_bind()`. Tanpa itu
     * `AGENT_BIND=0.0.0.0:99999` + mapping `99999:8443` ditulis apa adanya dan
     * container agent tidak pernah bisa start.
     *
     * @return array<string,array{0:int}>
     */
    public static function outOfRangePortProvider(): array
    {
        return ['port 0' => [0], 'port 65536' => [65536], 'port 99999' => [99999]];
    }

    /**
     * @dataProvider outOfRangePortProvider
     */
    public function testPortDiLuarRentangDitolakWalauPublishKonsisten(int $port): void
    {
        $dir = $this->sandbox();
        $bind = '0.0.0.0:' . $port;

        $result = $this->runInstaller(
            $dir,
            ['--domain', self::DOMAIN, '--ip', '10.0.0.12', '--bind', $bind],
            ['AGENT_PORT_PUBLISH' => (string) $port]
        );

        $this->assertSame(1, $result['code'], $bind . ' harus ditolak | ' . $result['stdout']);
        $this->assertStringContainsString('--bind', $result['stdout'] . $result['stderr']);
        $this->assertDirectoryDoesNotExist($dir . '/secrets', 'fail-fast sebelum menulis rahasia');
        $this->assertStringNotContainsString(self::UP_MARKER, $result['stdout']);
    }

    /**
     * Rerun idempoten: `--bind` dari berkas ikut divalidasi (pesannya beda:
     * menyebut berkas, bukan opsi CLI) dan nilai sah tidak mengubah berkas.
     */
    public function testRerunIdempotenDanBindDariBerkasIkutDivalidasi(): void
    {
        $dir = $this->sandbox();
        $args = ['--domain', self::DOMAIN, '--ip', '10.0.0.12', '--bind', '127.0.0.1:8443'];
        $this->assertSame(0, $this->runInstaller($dir, $args)['code']);
        $first = (string) file_get_contents($dir . '/secrets/agent.env');

        $second = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12']);
        $this->assertSame(0, $second['code'], 'rerun tanpa opsi wajib tetap sukses');
        $this->assertSame($first, (string) file_get_contents($dir . '/secrets/agent.env'), 'rerun byte-identik');

        // AGENT_BIND cacat DI BERKAS: ditolak, dan pesannya menunjuk berkas itu.
        file_put_contents(
            $dir . '/secrets/agent.env',
            preg_replace('/^AGENT_BIND=.*$/m', 'AGENT_BIND=8443', $first)
        );
        $third = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12']);
        $this->assertSame(1, $third['code']);
        $this->assertStringContainsString('secrets/agent.env', $third['stdout'] . $third['stderr']);
    }

    /**
     * Berkas env ber-CRLF (editor Windows): rerun tidak boleh gagal, nilai lama
     * terbaca utuh (tanpa `\r`), dan hasil tulis selalu LF.
     */
    public function testBerkasEnvCrlfTidakMenggagalkanRerunDanDinormalkan(): void
    {
        $dir = $this->sandbox();
        $this->seedEnv($dir, [
            'RAMES_MODE=agent',
            'AGENT_TOKEN=agt_legacy',
            'APP_DOMAIN=old.example.com',
            'ADMIN_EMAIL=old@example.com',
            'SSL_WEBROOT=/srv/rames/webroot',
        ], "\r\n");

        $result = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12']);

        $this->assertSame(0, $result['code'], 'CRLF tidak boleh menggagalkan installer: ' . $result['stdout']);
        $this->assertStringContainsString('ber-CRLF', $result['stdout'], 'operator diberi tahu penyebabnya');
        $env = (string) file_get_contents($dir . '/secrets/agent.env');
        $this->assertStringNotContainsString("\r", $env, 'hasil tulis installer selalu LF');
        $this->assertStringContainsString("ADMIN_EMAIL=old@example.com\n", $env, 'nilai dari berkas CRLF terbaca bersih');
        $this->assertStringContainsString("SSL_WEBROOT=/srv/rames/webroot\n", $env, 'kunci operator ikut bersih dari \\r');
    }

    /**
     * Upgrade dari berkas format LAMA (tanpa sentinel):
     *  - kelima komentar header installer lama TIDAK menumpuk di wilayah operator;
     *  - penyetelan operator utuh — termasuk komentar yang MIRIP header, baik
     *    ekstensi-prefix **di dalam blok komentar awal** (memastikan pencocokan
     *    PERSIS, bukan pola longgar/prefix) maupun ekstensi-prefix **di tengah**
     *    wilayah operator;
     *  - kunci terkelola di-refresh tanpa duplikat; rerun berikutnya byte-identik.
     */
    public function testWilayahOperatorDipertahankanDanHeaderLamaDibuang(): void
    {
        $dir = $this->sandbox();
        // Berawalan PERSIS entri #1 (satu-satunya posisi yang paling rawan:
        // ia duduk di blok komentar awal, tempat header lama memang berada).
        $operatorPrefixInHeader = self::LEGACY_HEADER[0] . ' CATATAN OPERATOR (di blok komentar awal)';
        $operatorComment = self::LEGACY_HEADER[1] . ' — catatan operator';

        $this->seedEnv($dir, array_merge(
            self::LEGACY_HEADER,
            [$operatorPrefixInHeader],
            [
                'RAMES_MODE=agent',
                'AGENT_TOKEN=agt_legacy',
                'AGENT_BIND=0.0.0.0:8443',
                'APP_DOMAIN=old.example.com',
                'ADMIN_EMAIL=old@example.com',
                'SSL_WEBROOT=/srv/rames/webroot',
                'MY_CUSTOM_KEY=nilai-kustom',
                '# komentar operator biasa',
                $operatorComment,
            ]
        ), "\n");

        $result = $this->runInstaller(
            $dir,
            ['--domain', 'new.example.com', '--ip', '10.0.0.12', '--port-range', '31000', '31999']
        );
        $this->assertSame(0, $result['code'], $result['stdout']);

        $env = (string) file_get_contents($dir . '/secrets/agent.env');
        $operator = $this->operatorRegion($env);

        foreach (self::LEGACY_HEADER as $legacy) {
            $this->assertSame(
                0,
                substr_count($operator, $legacy . "\n"),
                'komentar header installer lama tidak boleh masuk wilayah operator: ' . $legacy
            );
        }
        $this->assertSame(1, substr_count($operator, "SSL_WEBROOT=/srv/rames/webroot\n"));
        $this->assertSame(1, substr_count($operator, "MY_CUSTOM_KEY=nilai-kustom\n"));
        $this->assertSame(1, substr_count($operator, "# komentar operator biasa\n"));
        $this->assertSame(
            1,
            substr_count($operator, $operatorPrefixInHeader . "\n"),
            'komentar operator berawalan header di blok komentar awal tetap utuh (pencocokan persis, bukan prefix)'
        );
        $this->assertSame(1, substr_count($operator, $operatorComment . "\n"), 'komentar operator mirip header tetap utuh');

        // Kunci terkelola di-refresh dan TIDAK duplikat (salinan lama dibuang).
        $this->assertSame(1, substr_count($env, "AGENT_BIND=0.0.0.0:8443\n"));
        $this->assertSame(1, substr_count($env, "AGENT_TOKEN=agt_legacy\n"), 'token lama dipertahankan (idempoten tanpa --force)');
        $this->assertSame(1, substr_count($env, "ADMIN_EMAIL=old@example.com\n"));
        $this->assertStringContainsString("APP_DOMAIN=new.example.com\n", $env);
        $this->assertStringContainsString("PORT_RANGE_START=31000\n", $env);

        $before = (string) file_get_contents($dir . '/secrets/agent.env');
        $this->assertSame(0, $this->runInstaller($dir, ['--domain', 'new.example.com', '--ip', '10.0.0.12'])['code']);
        $this->assertSame($before, (string) file_get_contents($dir . '/secrets/agent.env'), 'rerun byte-identik setelah upgrade');
    }

    /**
     * Batas "wilayah header awal": komentar operator yang **byte-identik** dengan
     * salah satu entri header TETAPI berada di tengah wilayah operator (setelah
     * baris kunci pertama) WAJIB selamat. Tanpa batas ini (pencocokan persis
     * diterapkan di seluruh wilayah operator) komentar milik operator dihapus —
     * itu sebabnya pencocokan dibatasi ke rentetan komentar/kosong di AWAL berkas.
     *
     * Bukti kebocoran yang diperiksa: tidak satu pun entri header boleh muncul
     * SEBELUM penanda operator `# pembatas operator` di wilayah operator.
     */
    public function testKomentarOperatorByteIdentikHeaderDiTengahTetapUtuh(): void
    {
        $dir = $this->sandbox();
        $operatorIdenticalMid = self::LEGACY_HEADER[4];
        $marker = '# pembatas operator — di bawah ini murni penyetelan operator';

        $this->seedEnv($dir, array_merge(
            self::LEGACY_HEADER,
            [
                'RAMES_MODE=agent',
                'AGENT_TOKEN=agt_legacy',
                'AGENT_BIND=0.0.0.0:8443',
                'APP_DOMAIN=old.example.com',
                $marker,
                $operatorIdenticalMid,
                'SSL_WEBROOT=/srv/rames/webroot',
            ]
        ), "\n");

        $result = $this->runInstaller($dir, ['--domain', self::DOMAIN, '--ip', '10.0.0.12']);
        $this->assertSame(0, $result['code'], $result['stdout']);

        $operator = $this->operatorRegion((string) file_get_contents($dir . '/secrets/agent.env'));

        // Kebocoran header lama = entri header muncul sebelum penanda operator.
        $cut = strpos($operator, $marker . "\n");
        $this->assertNotFalse($cut, 'penanda operator wajib ikut tersalin: ' . $operator);
        $leaked = substr($operator, 0, $cut);
        foreach (self::LEGACY_HEADER as $legacy) {
            $this->assertSame(0, substr_count($leaked, $legacy . "\n"), 'header lama bocor ke wilayah operator: ' . $legacy);
        }

        // Komentar operator yang byte-identik dengan entri header tetap ada —
        // dan urutannya tetap SESUDAH penanda (jadi jelas milik operator).
        $this->assertSame(
            1,
            substr_count($operator, $operatorIdenticalMid . "\n"),
            'komentar operator byte-identik header di tengah wilayah operator tidak boleh dihapus'
        );
        $after = substr($operator, $cut);
        $this->assertSame(
            1,
            substr_count($after, $operatorIdenticalMid . "\n"),
            'komentar itu harus berada SESUDAH penanda operator (bukan sisa header lama)'
        );
    }

    // ---------------------------------------------------------------- sandbox
    /** Direktori "project" tiruan: salinan skrip + compose + `docker` palsu. */
    private function sandbox(): string
    {
        $dir = $this->tmp . '/' . bin2hex(random_bytes(3));
        mkdir($dir . '/agent', 0755, true);
        mkdir($dir . '/bin', 0755, true);
        mkdir($dir . '/nginx/sites-available', 0755, true);
        mkdir($dir . '/nginx/sites-enabled', 0755, true);
        copy(dirname(__DIR__) . '/agent/install.sh', $dir . '/agent/install.sh');
        copy(dirname(__DIR__) . '/docker-compose.agent.yml', $dir . '/docker-compose.agent.yml');
        chmod($dir . '/agent/install.sh', 0755);

        // `docker` palsu: `up` = penanda efek samping, `inspect` = container hidup,
        // sisanya sukses (check_prereqs). TIDAK ada Docker nyata yang dipanggil.
        file_put_contents($dir . '/bin/docker', implode("\n", [
            '#!/bin/sh',
            'for a in "$@"; do if [ "$a" = "up" ]; then echo "' . self::UP_MARKER . '"; exit 0; fi; done',
            'case "$1" in',
            '  --version) echo "Docker version 0.0.0-fake"; exit 0 ;;',
            '  inspect) echo "true"; exit 0 ;;',
            '  *) exit 0 ;;',
            'esac',
            '',
        ]));
        chmod($dir . '/bin/docker', 0755);

        return $dir;
    }

    /** @param array<int,string> $lines */
    private function seedEnv(string $dir, array $lines, string $eol): void
    {
        mkdir($dir . '/secrets', 0700, true);
        file_put_contents($dir . '/secrets/agent.env', implode($eol, $lines) . $eol);
        chmod($dir . '/secrets/agent.env', 0600);
    }

    /** @param array<int,string> $args */
    private function runInstaller(string $dir, array $args, array $extraEnv = []): array
    {
        return $this->runProcess(
            array_merge([$dir . '/agent/install.sh'], $args),
            $dir,
            [
                'PATH' => $dir . '/bin:' . (string) (getenv('PATH') ?: '/usr/bin:/bin'),
                'HOME' => $this->tmp,
                'NGINX_CONF_PATH' => $dir . '/nginx/sites-available',
                'NGINX_ENABLED_PATH' => $dir . '/nginx/sites-enabled',
            ] + $extraEnv
        );
    }

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command, string $cwd, array $env = []): array
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, $cwd, $env === [] ? null : $env);
        if (!is_resource($proc)) {
            $this->fail('proc_open gagal: ' . implode(' ', $command));
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => (int) proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Baris `warn` (prefix `!`, warna ANSI diabaikan) yang membahas AGENT_BIND —
     * warn lain pada stdout (mis. ADMIN_EMAIL / certbot) sengaja TIDAK dihitung
     * supaya "tepat satu" benar-benar mengukur janji toleransi.
     *
     * @return array<int,string>
     */
    private function bindWarnLines(string $stdout): array
    {
        $lines = [];
        foreach (explode("\n", $stdout) as $raw) {
            $line = (string) preg_replace('/\033\[[0-9;]*m/', '', $raw);
            if (str_starts_with(ltrim($line), '!')
                && str_contains($line, 'AGENT_BIND')
                && str_contains($line, 'redundan')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Baris setelah `# rames-installer:end` = wilayah milik operator. */
    private function operatorRegion(string $env): string
    {
        $parts = explode("# rames-installer:end\n", $env, 2);

        return $parts[1] ?? '';
    }

    private static function whichBinary(string $bin): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: '')) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $bin;
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
