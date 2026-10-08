<?php
declare(strict_types=1);

namespace Tests;

use app\library\Adminer\AdminerProxy;
use app\library\Adminer\AdminerProxyLimitExceeded;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * Test `AdminerProxy::forward()` dengan handler HTTP tiruan (tanpa jaringan,
 * tanpa Docker) — menutup dua celah Fase 1b:
 *
 *  1. **Kredensial tidak terdeteksi** ⇒ mode manual: TIDAK melempar, TIDAK 409,
 *     target = halaman login Adminer dengan `?server=<host:port>` (kolom Server
 *     ter-prefill), auto-login dilewati.
 *  2. **Sesi helper basi** ⇒ login ulang satu kali + ulangi permintaan **satu
 *     kali**; hanya bila responsnya benar-benar halaman login (413/500/dokumen
 *     non-HTML tidak pernah diulang); tidak ada percobaan kedua; request yang
 *     mengubah data berakhir `session_expired` (halaman pesan Rames).
 */
class AdminerProxyForwardTest extends TestCase
{
    private const LOGIN_HTML = "<title>Login - Adminer</title><input type=\"password\" name=\"auth[password]\">";
    private const TOKEN_HTML = "<input type='hidden' name='token' value='455896:483549'>" . self::LOGIN_HTML;
    private const DATA_HTML = '<title>Select: baseline - db:3306 - Adminer</title><table><th>id<th>label';
    private const LANDING = '?server=app-db:3306&username=appuser&db=appdb';
    private const LANDING_QUERY = 'server=app-db:3306&username=appuser&db=appdb';

    /**
     * Adminer selalu mengirim `adminer_sid` (dirotasi saat login) dan
     * `adminer_key` (kunci dekripsi password di sesinya) saat sesi baru dibuat.
     */
    private const LOGIN_PAGE_HEADERS = [
        'Set-Cookie' => ['adminer_sid=sess1; path=/; HttpOnly', 'adminer_key=key1; path=/; HttpOnly'],
    ];

    private string $tempDir;

    private string $prefix = '/database/app-db-1/adminer';

    private FakeAdminerHandler $handler;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/adminer-forward-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);
        $this->handler = new FakeAdminerHandler();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tempDir);
    }

    /**
     * Proxy dengan kredensial terdeteksi (mode auto) atau dikosongkan (manual).
     *
     * @param array<string,string> $credentials
     */
    private function proxy(array $credentials = ['server' => 'app-db:3306', 'username' => 'appuser', 'password' => 'secret', 'db' => 'appdb'], int $maxBytes = 67108864): AdminerProxy
    {
        return new AdminerProxy([
            'base_url' => 'http://rames-adminer:8080',
            'prefix' => $this->prefix,
            'temp_dir' => $this->tempDir,
            'max_bytes' => $maxBytes,
            'credentials' => $credentials + ['driver' => 'server'],
        ], new Client(['handler' => $this->handler, 'stream' => true]));
    }

    /**
     * @return array<string,string>
     */
    private function readyJar(): array
    {
        return [
            'adminer_sid' => 'stale-sid',
            'adminer_key' => 'stale-key',
            AdminerProxy::JAR_LANDING => self::LANDING_QUERY,
        ];
    }

    private function body(array $result): string
    {
        $file = (string) $result['body_file'];

        return ($file !== '' && is_file($file)) ? (string) file_get_contents($file) : '';
    }

    // ==================================================================
    // 1. Kredensial tidak terdeteksi ⇒ mode manual (bukan 409)
    // ==================================================================

    public function testMissingCredentialsServePrefilledLoginPageWithoutAutoLogin(): void
    {
        $this->handler->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);

        $jar = [];
        $result = $this->proxy(['server' => 'app-db:3306', 'username' => '', 'password' => ''])->forward('GET', '', '', null, [], $jar);

        $this->assertSame(200, $result['status'], 'halaman login bukan galat (dulu 409)');
        $this->assertFalse($result['session_expired']);
        $this->assertStringContainsString('auth[password]', $this->body($result), 'halaman login Adminer yang dilayani');

        // Auto-login dilewati: hanya SATU panggilan (permintaan yang diminta),
        // dan targetnya membawa `?server=` sebagai prefill kolom Server.
        $this->assertSame(1, count($this->handler->calls()));
        $this->assertStringContainsString('?server=app-db%3A3306', $this->handler->calls()[0]['uri']);
        $this->assertSame(0, $this->handler->countBodyContains('auth['));

        // Landing manual disimpan → permintaan berikutnya tetap membawa konteks server.
        $this->assertSame('server=app-db%3A3306', $jar[AdminerProxy::JAR_LANDING] ?? '');
    }

    public function testManualModeKeepsServerContextOnFollowUpRequests(): void
    {
        $this->handler
            ->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS)
            ->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);
        $proxy = $this->proxy(['server' => 'app-db:3306', 'username' => '', 'password' => '']);
        $jar = [];

        $proxy->forward('GET', '', '', null, [], $jar);
        $proxy->forward('GET', '', 'select=baseline', null, [], $jar);

        $this->assertStringContainsString('?server=app-db%3A3306&select=baseline', $this->handler->calls()[1]['uri']);
    }

    public function testManualModeIsNotRecoveredOrRetried(): void
    {
        // Mode manual tanpa sesi: halaman login adalah hasil yang diharapkan →
        // tidak ada login ulang, tidak ada percobaan ulang.
        $this->handler->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);

        $jar = [];
        $result = $this->proxy(['server' => 'app-db:3306', 'username' => '', 'password' => ''])->forward('GET', '', '', null, [], $jar);

        $this->assertSame(1, count($this->handler->calls()));
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['session_expired']);
    }

    public function testManualModePostWithoutSessionAsksUserToRepeat(): void
    {
        // POST mode manual tanpa sesi: form langsung ditolak Adminer (halaman
        // login) ⇒ tidak ada efek samping, tetapi input user hilang → pesan Rames.
        $this->handler->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);

        $jar = [];
        $result = $this->proxy(['server' => 'app-db:3306', 'username' => '', 'password' => ''])
            ->forward('POST', '', 'select=baseline&sql=', 'sql=SELECT+1', [], $jar);

        $this->assertSame(409, $result['status']);
        $this->assertTrue($result['session_expired']);
        $this->assertSame('', $result['body_file']);
        $this->assertSame(1, count($this->handler->calls()));
    }

    // ==================================================================
    // 2. Sesi basi ⇒ login ulang + ulangi SEKALI
    // ==================================================================

    public function testStaleSessionPostIsRetriedOnceAfterRelogin(): void
    {
        $this->handler
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS)                                  // POST pertama → halaman login (tanpa efek samping)
            ->reply(200, self::TOKEN_HTML, self::LOGIN_PAGE_HEADERS)                                  // login ulang: GET token
            ->reply(302, '', ['Location' => self::LANDING, 'Set-Cookie' => 'adminer_sid=fresh; path=/'])  // login ulang: POST auth
            ->reply(200, self::DATA_HTML);                                  // POST diulang → sukses

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('POST', '', self::LANDING_QUERY . '&import=', 'sql_file[]=x', [], $jar);

        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['session_expired']);
        $this->assertStringContainsString('Select: baseline', $this->body($result));

        // Satu percobaan ulang (bukan dua) + login ulang sekali.
        $this->assertSame(4, count($this->handler->calls()));
        $this->assertSame(2, $this->handler->countBodyContains('sql_file[]'), 'permintaan + satu percobaan ulang');
        $this->assertSame(1, $this->handler->countBodyContains('auth%5B'), 'login ulang sekali saja');

        // Sesi baru (cookie hasil rotasi) tersimpan di jar; `adminer_key` ikut
        // dirotasi karena sesi lama benar-benar hilang.
        $this->assertSame('fresh', $jar['adminer_sid']);
        $this->assertSame('key1', $jar['adminer_key']);
    }

    public function testStaleSessionGetIsRetriedAndLoginPageNormalisedTo200(): void
    {
        $this->handler
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS)                      // GET pertama → sesi basi
            ->reply(200, self::TOKEN_HTML, self::LOGIN_PAGE_HEADERS)                      // login ulang
            ->reply(302, '', ['Location' => self::LANDING, 'Set-Cookie' => 'adminer_sid=fresh; path=/'])
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);                     // ulangan → Adminer tetap minta login

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('GET', '', '', null, [], $jar);

        // Status 403 `auth_error()` dinormalkan: halaman login = halaman biasa.
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['session_expired']);
        $this->assertSame(4, count($this->handler->calls()));
    }

    public function testSecondFailedAttemptIsNotRetriedAgainForMutatingRequest(): void
    {
        $this->handler
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS)
            ->reply(200, self::TOKEN_HTML, self::LOGIN_PAGE_HEADERS)
            ->reply(302, '', ['Location' => self::LANDING, 'Set-Cookie' => 'adminer_sid=fresh; path=/'])
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);                     // percobaan kedua juga gagal

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('POST', '', self::LANDING_QUERY . '&sql=', 'sql=SELECT+1', [], $jar);

        $this->assertSame(409, $result['status'], 'perlu halaman pesan Rames');
        $this->assertTrue($result['session_expired']);
        $this->assertSame(4, count($this->handler->calls()), 'tidak ada percobaan ketiga');
        // 3 POST = 1 login ulang + 2 percobaan (asli + satu ulangan), tidak lebih.
        $this->assertSame(3, $this->handler->countMethod('POST'));
        $this->assertSame(2, $this->handler->countBodyContains('SELECT+1'));
    }

    public function testRetriedPostWithStaleFormTokenEndsInRetryNotice(): void
    {
        // Kasus nyata (ditemukan saat uji E2E): form Adminer mengikat token CSRF
        // ke sesi lama. Setelah login ulang, percobaan kedua DITOLAK Adminer
        // ("Invalid CSRF token", 403 — bukan halaman login), sehingga input user
        // hilang tanpa penjelasan → harus muncul halaman pesan Rames.
        $this->handler
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS)
            ->reply(200, self::TOKEN_HTML, self::LOGIN_PAGE_HEADERS)
            ->reply(302, '', ['Location' => self::LANDING, 'Set-Cookie' => 'adminer_sid=fresh; path=/'])
            ->reply(403, '<title>Invalid CSRF token - db:3306 - Adminer</title>');

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('POST', '', self::LANDING_QUERY . '&import=', 'sql_file[]=x', [], $jar);

        $this->assertSame(409, $result['status']);
        $this->assertTrue($result['session_expired']);
        $this->assertSame('', $result['body_file']);
        $this->assertSame(4, count($this->handler->calls()));
    }

    public function testRetriedGetWith403IsNotReplacedByNotice(): void
    {
        // Navigasi GET tidak mengubah data: halaman galat Adminer diteruskan apa
        // adanya, tanpa halaman pesan.
        $this->handler
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS)
            ->reply(200, self::TOKEN_HTML, self::LOGIN_PAGE_HEADERS)
            ->reply(302, '', ['Location' => self::LANDING, 'Set-Cookie' => 'adminer_sid=fresh; path=/'])
            ->reply(403, '<title>Invalid CSRF token - db:3306 - Adminer</title>');

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('GET', '', '', null, [], $jar);

        $this->assertSame(403, $result['status'], 'bukan permintaan yang mengubah data → diteruskan');
        $this->assertFalse($result['session_expired']);
    }

    public function testAutoLoginRejectedFallsBackToManualWithoutSecondAutoLogin(): void
    {
        $this->handler
            ->reply(200, self::TOKEN_HTML, self::LOGIN_PAGE_HEADERS)                      // auto-login: GET token
            ->reply(302, '', ['Location' => self::LANDING, 'Set-Cookie' => 'adminer_sid=fresh; path=/'])
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS)                      // permintaan → kredensial otomatis ditolak
            ->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);                     // ulangan (mode manual, server ter-prefill)

        $jar = [];
        $result = $this->proxy()->forward('GET', '', '', null, [], $jar);

        $this->assertSame(200, $result['status']);
        $this->assertSame(4, count($this->handler->calls()));
        $this->assertSame(1, $this->handler->countBodyContains('auth%5B'), 'tidak ada auto-login kedua');
        $this->assertStringContainsString('?server=app-db%3A3306', $this->handler->calls()[3]['uri']);
        // Jar ditinggalkan dalam mode manual: landing hanya berisi server
        // (bukan server+username+db dari auto-login yang ditolak).
        $this->assertSame('server=app-db%3A3306', $jar[AdminerProxy::JAR_LANDING]);
    }

    // ==================================================================
    // 3. Tidak pernah diulang bila bukan halaman login
    // ==================================================================

    public function testSizeLimitResponseIsNeverRetried(): void
    {
        // Body memuat penanda form login, tetapi status 413 (batas ukuran) harus
        // diteruskan apa adanya — tanpa retry.
        $this->handler->reply(413, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('GET', '', '', null, [], $jar);

        $this->assertSame(413, $result['status']);
        $this->assertFalse($result['session_expired']);
        $this->assertSame(1, count($this->handler->calls()));
    }

    public function testServerErrorIsNeverRetried(): void
    {
        $this->handler->reply(500, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('GET', '', '', null, [], $jar);

        $this->assertSame(500, $result['status']);
        $this->assertSame(1, count($this->handler->calls()));
    }

    public function testNonHtmlResponseIsNeverRetried(): void
    {
        $this->handler->reply(200, self::LOGIN_HTML, ['Content-Type' => 'text/css']);

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('GET', '', 'file=default.css', null, [], $jar);

        $this->assertSame(1, count($this->handler->calls()));
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['session_expired']);
    }

    // ==================================================================
    // 4. Jalur normal (tanpa regresi)
    // ==================================================================

    public function testHealthySessionIsSingleRequestWithoutRetry(): void
    {
        $this->handler->reply(200, self::DATA_HTML);

        $jar = $this->readyJar();
        $result = $this->proxy()->forward('GET', '', 'select=baseline', null, [], $jar);

        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['session_expired']);
        $this->assertSame(1, count($this->handler->calls()));
        $this->assertStringContainsString('Select: baseline', $this->body($result));
        // Cookie sesi server-side dikirim ke helper, tidak pernah ke browser.
        $this->assertStringContainsString('adminer_sid=stale-sid', $this->handler->calls()[0]['cookie']);
    }

    public function testFreshAutoLoginThenRequest(): void
    {
        $this->handler
            ->reply(200, self::TOKEN_HTML, self::LOGIN_PAGE_HEADERS)
            ->reply(302, '', ['Location' => self::LANDING, 'Set-Cookie' => 'adminer_sid=fresh; path=/'])
            ->reply(200, self::DATA_HTML);

        $jar = [];
        $result = $this->proxy()->forward('GET', '', '', null, [], $jar);

        $this->assertSame(200, $result['status']);
        $this->assertSame(3, count($this->handler->calls()));
        $this->assertStringContainsString('?server=app-db:3306&username=appuser&db=appdb', $this->handler->calls()[2]['uri']);
        $this->assertSame(self::LANDING_QUERY, $jar[AdminerProxy::JAR_LANDING]);
    }

    public function testOversizedRetryStillPropagatesSizeLimit(): void
    {
        $this->handler
            ->reply(200, self::TOKEN_HTML, self::LOGIN_PAGE_HEADERS)                                   // auto-login: GET token
            ->reply(302, '', ['Location' => self::LANDING, 'Set-Cookie' => 'adminer_sid=fresh; path=/'])
            ->reply(403, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS)                                   // kredensial otomatis ditolak
            ->reply(200, str_repeat('x', 4000));                             // ulangan melebihi batas ukuran

        $jar = [];
        $proxy = $this->proxy(['server' => 'app-db:3306', 'username' => 'appuser', 'password' => 's', 'db' => ''], 1024);

        // Batas ukuran harus tetap diteruskan apa adanya — bukan dipulihkan
        // menjadi halaman login.
        $this->expectException(AdminerProxyLimitExceeded::class);
        $proxy->forward('GET', '', '', null, [], $jar);
    }

    // ==================================================================
    // 3. Sanitasi X-Forwarded-* (temuan Fase 5a INFO)
    // ==================================================================

    /**
     * Nilai `X-Forwarded-*` kiriman klien TIDAK boleh sampai ke helper.
     */
    public function testClientForwardedHeadersNeverReachHelper(): void
    {
        $this->handler->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);

        $jar = [];
        $result = $this->proxy(['server' => 'app-db:3306', 'username' => '', 'password' => ''])
            ->forward('GET', '', '', null, [
                'content-type' => 'text/html',
                'x-forwarded-proto' => 'https',
                'x-forwarded-for' => '1.2.3.4',
                'x-forwarded-host' => 'evil.example.test',
                'x-forwarded-port' => '8443',
                'x-real-ip' => '9.9.9.9',
                'forwarded' => 'for=9.9.9.9;proto=https',
            ], $jar);

        $this->assertSame(200, $result['status']);
        $this->assertSame('text/html', $this->handler->header(0, 'Content-Type'), 'header aman tetap diteruskan');

        // Proto dibentuk dari konteks dashboard (default http), bukan dari klien.
        $this->assertSame('http', $this->handler->header(0, 'X-Forwarded-Proto'));
        $this->assertFalse($this->handler->hasHeader(0, 'X-Forwarded-For'), 'XFF klien tidak diteruskan');
        $this->assertFalse($this->handler->hasHeader(0, 'X-Forwarded-Host'));
        $this->assertFalse($this->handler->hasHeader(0, 'X-Forwarded-Port'));
        $this->assertFalse($this->handler->hasHeader(0, 'X-Real-IP'));
        $this->assertFalse($this->handler->hasHeader(0, 'Forwarded'));
        // Prefix tetap dari konfigurasi (tidak bisa dipalsukan klien).
        $this->assertSame($this->prefix, $this->handler->header(0, 'X-Forwarded-Prefix'));
    }

    /**
     * Klien bisa mencoba mengirim `X-Forwarded-Prefix` sendiri — proxy tetap
     * memakai prefix dari konfigurasi.
     */
    public function testClientCannotSpoofForwardedPrefix(): void
    {
        $this->handler->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);

        $jar = [];
        $this->proxy(['server' => 'app-db:3306', 'username' => '', 'password' => ''])
            ->forward('GET', '', '', null, ['x-forwarded-prefix' => '/../../evil'], $jar);

        $this->assertSame($this->prefix, $this->handler->header(0, 'X-Forwarded-Prefix'));
    }

    /**
     * Konteks dashboard (spec) dipakai apa adanya: skema `https` + `REMOTE_ADDR`.
     */
    public function testForwardedContextComesFromDashboardSpec(): void
    {
        $this->handler->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);

        $proxy = new AdminerProxy([
            'base_url' => 'http://rames-adminer:8080',
            'prefix' => $this->prefix,
            'temp_dir' => $this->tempDir,
            'credentials' => ['driver' => 'server', 'server' => 'app-db:3306', 'username' => '', 'password' => ''],
            'forwarded_proto' => 'https',
            'forwarded_for' => '203.0.113.7',
        ], new Client(['handler' => $this->handler, 'stream' => true]));

        $jar = [];
        $proxy->forward('GET', '', '', null, [
            'x-forwarded-proto' => 'http',
            'x-forwarded-for' => '10.0.0.1',
        ], $jar);

        $this->assertSame('https', $this->handler->header(0, 'X-Forwarded-Proto'));
        $this->assertSame('203.0.113.7', $this->handler->header(0, 'X-Forwarded-For'));
    }

    /**
     * `forwarded_for` yang bukan IP tunggal (rantai/hostname) tidak diteruskan.
     */
    public function testForwardedForRejectsChainAndHostname(): void
    {
        $proxy = new AdminerProxy([
            'temp_dir' => $this->tempDir,
            'forwarded_for' => '203.0.113.7, 10.0.0.1',
            'forwarded_proto' => 'ftp',
            'credentials' => ['driver' => 'server', 'server' => 'app-db:3306', 'username' => '', 'password' => ''],
        ], new Client(['handler' => $this->handler, 'stream' => true]));

        $this->assertSame('', $proxy->forwardedFor());
        $this->assertSame('http', $proxy->forwardedProto(), 'proto tak dikenal jatuh ke http');

        $this->handler->reply(200, self::LOGIN_HTML, self::LOGIN_PAGE_HEADERS);
        $jar = [];
        $proxy->forward('GET', '', '', null, [], $jar);

        $this->assertFalse($this->handler->hasHeader(0, 'X-Forwarded-For'));
    }

    public function testClientForwardedHeaderNamesAreRecognised(): void
    {
        $this->assertTrue(AdminerProxy::isClientForwardedHeader('X-Forwarded-Proto'));
        $this->assertTrue(AdminerProxy::isClientForwardedHeader('x-forwarded-host'));
        $this->assertFalse(AdminerProxy::isClientForwardedHeader('X-Forwarded-Prefix-Proxy'));
    }
}
