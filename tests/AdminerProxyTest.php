<?php
declare(strict_types=1);

namespace Tests;

use app\library\Adminer\AdminerProxy;
use app\library\Adminer\AdminerProxyLimitExceeded;
use app\library\Adminer\LimitedTempSink;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test AdminerProxy — bagian keputusan yang **statik murni** (normalisasi path &
 * guard SSRF, URL target, rewrite `Location`, pembersihan header, guard batas
 * ukuran, jar cookie) plus sink pembatas ukuran. Tanpa Docker/HTTP nyata.
 *
 * Verifikasi end-to-end proxy (helper nyata + DB nyata) ada di laporan Fase 1,
 * bukan di unit test ini (aturan repo: tes tanpa daemon).
 */
class AdminerProxyTest extends TestCase
{
    private const PREFIX = '/database/myapp-db-1/adminer';

    /**
     * @param array<string,mixed> $spec
     */
    private function proxy(array $spec = []): AdminerProxy
    {
        // Klien tiruan: tes di sini tidak pernah melakukan request HTTP.
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);

        return new AdminerProxy(array_replace([
            'base_url' => 'http://rames-adminer:8080',
            'prefix' => self::PREFIX,
            'temp_dir' => sys_get_temp_dir() . '/adminer-proxy-test',
        ], $spec), $client);
    }

    // ==================================================================
    // Normalisasi path (guard SSRF)
    // ==================================================================

    public function testNormalizePathAcceptsPlainAndNestedPaths(): void
    {
        $this->assertSame('', AdminerProxy::normalizePath(''));
        $this->assertSame('adminer.css', AdminerProxy::normalizePath('adminer.css'));
        $this->assertSame('foo/bar.php', AdminerProxy::normalizePath('foo/bar.php'));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function dangerousPaths(): array
    {
        return [
            'traversal' => ['../secret'],
            'traversal-encoded-dotdot' => ['..%2Fsecret'],
            'traversal-encoded-dot' => ['%2e%2e/secret'],
            'traversal-encoded-dot-upper' => ['%2E%2E/secret'],
            'encoded-single-dot' => ['%2e/x'],
            'encoded-slash' => ['foo%2fbar'],
            'encoded-backslash' => ['foo%5cbar'],
            'encoded-backslash-upper' => ['foo%5Cbar'],
            'traversal-in-middle' => ['foo/../../etc/passwd'],
            'absolute' => ['/etc/passwd'],
            'double-slash' => ['//evil.example.com/'],
            'backslash' => ['..\\windows'],
            'control-char' => ["a\nb"],
            'nul-byte' => ["a\0b"],
        ];
    }

    /**
     * @dataProvider dangerousPaths
     */
    public function testNormalizePathRejectsAnythingOutsideHelperRoot(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdminerProxy::normalizePath($path);
    }

    // ==================================================================
    // URL target
    // ==================================================================

    public function testTargetUrlAlwaysPointsAtHelperBase(): void
    {
        $this->assertSame(
            'http://rames-adminer:8080/',
            AdminerProxy::targetUrl('http://rames-adminer:8080', '', '')
        );
        $this->assertSame(
            'http://rames-adminer:8080/?select=t1',
            AdminerProxy::targetUrl('http://rames-adminer:8080', '', 'select=t1')
        );
        $this->assertSame(
            'http://rames-adminer:8080/adminer.css',
            AdminerProxy::targetUrl('http://rames-adminer:8080', 'adminer.css', '')
        );
    }

    public function testTargetUrlNeverDerivesHostFromInput(): void
    {
        // Nama host di path tetap menjadi PATH di host helper, bukan host baru.
        $url = AdminerProxy::targetUrl('http://rames-adminer:8080', 'evil.example.com/adminer.css', '');

        $this->assertStringStartsWith('http://rames-adminer:8080/', $url);
        $this->assertStringNotContainsString('http://evil.example.com', $url);
    }

    // ==================================================================
    // Rewrite Location
    // ==================================================================

    public function testRewriteLocationLeavesRelativeLocationUntouched(): void
    {
        // Location relatif diselesaikan browser terhadap URL yang sudah ber-prefix.
        $this->assertSame(
            '?server=db:3306&username=app',
            AdminerProxy::rewriteLocation('?server=db:3306&username=app', self::PREFIX)
        );
        $this->assertSame('./?x=1', AdminerProxy::rewriteLocation('./?x=1', self::PREFIX));
    }

    public function testRewriteLocationKeepsAlreadyPrefixedAbsolutePath(): void
    {
        $this->assertSame(
            self::PREFIX . '/?select=t1',
            AdminerProxy::rewriteLocation(self::PREFIX . '/?select=t1', self::PREFIX)
        );
        $this->assertSame(self::PREFIX, AdminerProxy::rewriteLocation(self::PREFIX, self::PREFIX));
        $this->assertSame(
            self::PREFIX . '/adminer.css',
            AdminerProxy::rewriteLocation(self::PREFIX . '/adminer.css', self::PREFIX)
        );
    }

    public function testRewriteLocationPrefixesForeignAbsolutePath(): void
    {
        $this->assertSame(self::PREFIX . '/adminer.css', AdminerProxy::rewriteLocation('/adminer.css', self::PREFIX));
        $this->assertSame(self::PREFIX . '/', AdminerProxy::rewriteLocation('/', self::PREFIX));
    }

    public function testRewriteLocationStripsForeignHost(): void
    {
        $this->assertSame(
            self::PREFIX . '/adminer.css?v=1',
            AdminerProxy::rewriteLocation('https://evil.example.com/adminer.css?v=1', self::PREFIX)
        );
    }

    public function testRewriteLocationEmptyStaysEmpty(): void
    {
        $this->assertSame('', AdminerProxy::rewriteLocation('  ', self::PREFIX));
    }

    // ==================================================================
    // Pembersihan header respons
    // ==================================================================

    public function testSanitizeDropsSetCookieAndHopByHopHeaders(): void
    {
        $headers = [
            'set-cookie' => ['adminer_sid=abc; path=/database/x/adminer/'],
            'connection' => ['close'],
            'transfer-encoding' => ['chunked'],
            'content-length' => ['123'],
            'content-encoding' => ['gzip'],
            'keep-alive' => ['timeout=5'],
            'upgrade' => ['h2c'],
            'cache-control' => ['no-cache'],
        ];

        $clean = AdminerProxy::sanitizeResponseHeaders($headers, self::PREFIX);

        // Cookie Adminer hidup di jar server-side → tidak pernah ke browser.
        $this->assertArrayNotHasKey('Set-Cookie', $clean);
        foreach (['Connection', 'Transfer-Encoding', 'Content-Length', 'Content-Encoding', 'Keep-Alive', 'Upgrade'] as $dropped) {
            $this->assertArrayNotHasKey($dropped, $clean);
        }
        $this->assertSame(['no-cache'], $clean['Cache-Control']);
    }

    public function testSanitizeCanonicalisesNamesAndRewritesLocation(): void
    {
        $headers = [
            'content-type' => ['text/html; charset=utf-8'],
            'location' => ['/adminer.css'],
            'x-content-type-options' => ['nosniff'],
        ];

        $clean = AdminerProxy::sanitizeResponseHeaders($headers, self::PREFIX);

        // Workerman memeriksa 'Content-Type' case-sensitif saat mengirim berkas.
        $this->assertSame(['text/html; charset=utf-8'], $clean['Content-Type']);
        $this->assertSame(['nosniff'], $clean['X-Content-Type-Options']);
        $this->assertSame([self::PREFIX . '/adminer.css'], $clean['Location']);
    }

    public function testSanitizeDropsEmptyValues(): void
    {
        $clean = AdminerProxy::sanitizeResponseHeaders(['x-empty' => [''], 'vary' => ['Accept-Encoding']], self::PREFIX);

        $this->assertArrayNotHasKey('X-Empty', $clean);
        $this->assertSame(['Accept-Encoding'], $clean['Vary']);
    }

    public function testHeaderLookupsAreCaseInsensitive(): void
    {
        // Kunci getHeaders() PSR-7 memakai casing asli dari server (Location,
        // Set-Cookie) — pencarian tidak boleh mengandalkan literal lowercase.
        $headers = [
            'Location' => ['?server=db:3306'],
            'set-cookie' => ['adminer_sid=abc', 'adminer_key=def'],
        ];

        $this->assertSame('?server=db:3306', AdminerProxy::headerFirst($headers, 'location'));
        $this->assertSame('?server=db:3306', AdminerProxy::headerFirst($headers, 'Location'));
        $this->assertSame('', AdminerProxy::headerFirst($headers, 'content-type'));
        $this->assertSame(['adminer_sid=abc', 'adminer_key=def'], AdminerProxy::headerAll($headers, 'SET-COOKIE'));
        $this->assertSame([], AdminerProxy::headerAll($headers, 'x-missing'));
    }

    // ==================================================================
    // Guard batas ukuran
    // ==================================================================

    public function testAssertWithinLimitAllowsBoundaryAndRejectsOverflow(): void
    {
        AdminerProxy::assertWithinLimit(1024, 1024);
        AdminerProxy::assertWithinLimit(0, 1024);

        $this->expectException(AdminerProxyLimitExceeded::class);
        $this->expectExceptionMessageMatches('/Volume\/backup atau Terminal/');
        AdminerProxy::assertWithinLimit(1025, 1024);
    }

    public function testForwardRejectsOversizedRequestBodyBeforeTouchingHelper(): void
    {
        $jar = [];
        $proxy = $this->proxy(['max_bytes' => 1024]);

        $this->expectException(AdminerProxyLimitExceeded::class);
        $proxy->forward('POST', '', 'import=', str_repeat('x', 2048), [], $jar);
    }

    public function testLimitedSinkStopsWritingAtLimit(): void
    {
        $path = sys_get_temp_dir() . '/adminer-sink-' . bin2hex(random_bytes(4)) . '.body';
        $handle = fopen($path, 'w+b');
        $this->assertIsResource($handle);

        $sink = new LimitedTempSink($handle, 8);
        $sink->write('12345678');

        try {
            $sink->write('9');
            $this->fail('sink seharusnya menolak byte ke-9');
        } catch (AdminerProxyLimitExceeded $e) {
            $this->assertSame(AdminerProxy::LIMIT_MESSAGE, $e->getMessage());
        }

        $this->assertSame(8, $sink->bytesWritten());
        $sink->close();
        $this->assertSame('12345678', (string) file_get_contents($path));
        @unlink($path);
    }

    // ==================================================================
    // Jar cookie
    // ==================================================================

    public function testCookieHeaderSkipsReservedKeys(): void
    {
        $jar = [
            'adminer_sid' => 'abc',
            'adminer_key' => 'def',
            AdminerProxy::JAR_LANDING => 'server=db:3306&username=app&db=appdb',
        ];

        $this->assertSame('adminer_sid=abc; adminer_key=def', AdminerProxy::cookieHeader($jar));
    }

    public function testParseSetCookieReadsFirstPairOnly(): void
    {
        $this->assertSame(
            ['adminer_sid', '0b1c2d'],
            AdminerProxy::parseSetCookie('adminer_sid=0b1c2d; path=/database/x/adminer/; HttpOnly; SameSite=lax')
        );
        $this->assertSame(['adminer_sid', ''], AdminerProxy::parseSetCookie('adminer_sid=; Max-Age=0'));
        $this->assertNull(AdminerProxy::parseSetCookie('no-equals-here'));
        $this->assertNull(AdminerProxy::parseSetCookie('=value'));
    }

    // ==================================================================
    // Konteks query (landing auto-login)
    // ==================================================================

    public function testEffectiveQueryLeftAloneWhenServerPresent(): void
    {
        $jar = [AdminerProxy::JAR_LANDING => 'server=other:3306&username=u&db=d'];

        $this->assertSame('server=db:3306&select=t1', AdminerProxy::effectiveQuery('server=db:3306&select=t1', $jar));
    }

    public function testEffectiveQueryCompletesBareAndPartialRequests(): void
    {
        $landing = 'server=db:3306&username=app&db=appdb';
        $jar = [AdminerProxy::JAR_LANDING => $landing];

        $this->assertSame($landing, AdminerProxy::effectiveQuery('', $jar));
        // parameter yang diminta diletakkan paling akhir → menang saat duplikat
        $this->assertSame($landing . '&select=t1', AdminerProxy::effectiveQuery('?select=t1', $jar));
        $this->assertSame($landing . '&file=default.css', AdminerProxy::effectiveQuery('file=default.css', $jar));
    }

    public function testEffectiveQueryUnchangedWithoutLanding(): void
    {
        $this->assertSame('select=t1', AdminerProxy::effectiveQuery('select=t1', []));
        $this->assertSame('', AdminerProxy::effectiveQuery('', []));
    }

    // ==================================================================
    // Auto-login: ekstraksi token & Location
    // ==================================================================

    public function testExtractTokenReadsHiddenLoginField(): void
    {
        $html = "<form action='' method='post'><input type='hidden' name='token' value='455896:483549'>
        <input name='auth[server]' value='db'>";

        $this->assertSame('455896:483549', AdminerProxy::extractToken($html));
        $this->assertSame('1:2', AdminerProxy::extractToken('<input name="token" value="1:2">'));
        $this->assertNull(AdminerProxy::extractToken('<html>tanpa form</html>'));
    }

    public function testLooksLikeLoginFormSeparatesLoginPageFromDataPage(): void
    {
        $loginPage = "<title>Login - Adminer</title><input type=\"password\" name=\"auth[password]\" autocomplete=\"current-password\">";
        $selectPage = "<title>Select: t1 - db:3306 - Adminer</title><table class=\"nowrap\"><th>id<th>label";

        $this->assertTrue(AdminerProxy::looksLikeLoginForm($loginPage));
        $this->assertSame('auth[password]', AdminerProxy::LOGIN_FORM_MARKER);
        $this->assertFalse(AdminerProxy::looksLikeLoginForm($selectPage));
        $this->assertFalse(AdminerProxy::looksLikeLoginForm(''));
    }

    public function testQueryOfLocationExtractsQuery(): void
    {
        $this->assertSame(
            'server=db:3306&username=app&db=appdb',
            AdminerProxy::queryOfLocation('?server=db:3306&username=app&db=appdb')
        );
        $this->assertSame('a=1', AdminerProxy::queryOfLocation('/adminer/?a=1#frag'));
        $this->assertSame('', AdminerProxy::queryOfLocation('/adminer/'));
    }

    // ==================================================================
    // Spec
    // ==================================================================

    public function testSpecCapsTimeoutAndNormalisesPrefix(): void
    {
        $spec = AdminerProxy::normalizeSpec(['timeout' => 600, 'prefix' => 'database/x/adminer/']);

        // Aturan repo: timeout klien HTTP tidak boleh > 30 detik.
        $this->assertSame(30, $spec['timeout']);
        $this->assertSame('/database/x/adminer', $spec['prefix']);
    }

    public function testSpecDefaultsToHelperContainerFromConfig(): void
    {
        $spec = AdminerProxy::normalizeSpec([]);

        $this->assertSame('http://rames-adminer:8080', $spec['base_url']);
        $this->assertSame(30, $spec['timeout']);
        $this->assertSame(67108864, $spec['max_bytes']);
    }

    public function testBaseUrlAndPrefixAccessors(): void
    {
        $proxy = $this->proxy();

        $this->assertSame('http://rames-adminer:8080', $proxy->baseUrl());
        $this->assertSame(self::PREFIX, $proxy->prefix());
    }

    // ==================================================================
    // Rencana sesi & mode manual (kredensial tidak terdeteksi)
    // ==================================================================

    public function testSessionPlanReadyWhenJarHasSession(): void
    {
        $jar = ['adminer_sid' => 'sid', 'adminer_key' => 'key'];

        $this->assertSame(AdminerProxy::MODE_READY, AdminerProxy::sessionPlan(['server' => 'db:3306', 'username' => 'u'], $jar));
    }

    public function testSessionPlanAutoWhenCredentialsComplete(): void
    {
        $this->assertSame(
            AdminerProxy::MODE_AUTO,
            AdminerProxy::sessionPlan(['server' => 'db:3306', 'username' => 'appuser', 'password' => 'p'], [])
        );
    }

    public function testSessionPlanManualWhenCredentialsMissing(): void
    {
        // Kredensial tidak terdeteksi (image DB custom) → jangan melempar/409,
        // user mengetik sendiri di form login Adminer.
        $this->assertSame(AdminerProxy::MODE_MANUAL, AdminerProxy::sessionPlan([], []));
        $this->assertSame(AdminerProxy::MODE_MANUAL, AdminerProxy::sessionPlan(['server' => 'db:3306', 'username' => ''], []));
        $this->assertSame(AdminerProxy::MODE_MANUAL, AdminerProxy::sessionPlan(['server' => '', 'username' => 'u', 'password' => 'p'], []));
    }

    public function testManualLandingOnlyCarriesServerForPrefill(): void
    {
        // Landing mode manual = `?server=<host:port>` → Adminer mengisi kolom
        // Server pada form login (tanpa menyunting HTML-nya).
        $this->assertSame('server=db%3A3306', AdminerProxy::manualLanding(['server' => 'db:3306', 'username' => '', 'password' => '']));
        $this->assertSame('', AdminerProxy::manualLanding([]));

        // Dan landing itu benar-benar dipakai untuk melengkapi query kosong.
        $jar = [AdminerProxy::JAR_LANDING => AdminerProxy::manualLanding(['server' => 'db:3306'])];
        $this->assertSame('server=db%3A3306', AdminerProxy::effectiveQuery('', $jar));
        $this->assertSame('server=db%3A3306&select=t1', AdminerProxy::effectiveQuery('select=t1', $jar));
    }

    public function testCanRecoverLoginPageOnlyForKnownSessions(): void
    {
        // Sesi lama pernah ada (basi) atau auto-login baru dijalankan → boleh
        // login ulang + ulangi sekali.
        $this->assertTrue(AdminerProxy::canRecoverLoginPage(true, false));
        $this->assertTrue(AdminerProxy::canRecoverLoginPage(false, true));
        $this->assertTrue(AdminerProxy::canRecoverLoginPage(true, true));
        // Mode manual kunjungan pertama: halaman login memang hasil yang diharapkan.
        $this->assertFalse(AdminerProxy::canRecoverLoginPage(false, false));
    }

    public function testIsMutatingCoversWriteMethods(): void
    {
        $this->assertTrue(AdminerProxy::isMutating('POST'));
        $this->assertTrue(AdminerProxy::isMutating('delete'));
        $this->assertFalse(AdminerProxy::isMutating('GET'));
        $this->assertFalse(AdminerProxy::isMutating('HEAD'));
    }

    public function testNeedsSessionExpiredNoticeOnlyForMutatingFailures(): void
    {
        $result = static fn (int $status, bool $loginPage = false): array => [
            'status' => $status,
            'headers' => ['Content-Type' => ['text/html']],
            'body_file' => '',
            'size' => 0,
        ];

        // Halaman login pada request yang mengubah data → input user hilang.
        $this->assertTrue(AdminerProxy::needsSessionExpiredNotice('POST', $result(200), true, false));
        $this->assertTrue(AdminerProxy::needsSessionExpiredNotice('POST', $result(403), true, true));
        // Percobaan ulang gagal otorisasi (token form dari sesi lama) → pesan.
        $this->assertTrue(AdminerProxy::needsSessionExpiredNotice('POST', $result(403), false, true));
        $this->assertTrue(AdminerProxy::needsSessionExpiredNotice('PUT', $result(401), false, true));

        // Bukan permintaan yang mengubah data → tidak pernah diganti pesan.
        $this->assertFalse(AdminerProxy::needsSessionExpiredNotice('GET', $result(403), false, true));
        $this->assertFalse(AdminerProxy::needsSessionExpiredNotice('HEAD', $result(200), true, false));
        // 403 tanpa percobaan ulang bukan urusan kita.
        $this->assertFalse(AdminerProxy::needsSessionExpiredNotice('POST', $result(403), false, false));
        // Batas ukuran & galat lain diteruskan apa adanya.
        $this->assertFalse(AdminerProxy::needsSessionExpiredNotice('POST', $result(413), false, true));
        $this->assertFalse(AdminerProxy::needsSessionExpiredNotice('POST', $result(500), false, true));
        $this->assertFalse(AdminerProxy::needsSessionExpiredNotice('POST', $result(200), false, true));
    }

    public function testIsLoginPageResponseGatesOnStatusContentTypeAndSize(): void
    {
        $loginPage = "<title>Login - Adminer</title><input name=\"auth[password]\">";
        $dataPage = '<title>Select: t1 - db:3306 - Adminer</title><table><th>id';
        $path = sys_get_temp_dir() . '/adminer-login-page-' . bin2hex(random_bytes(4)) . '.body';
        file_put_contents($path, $loginPage);

        try {
            $result = static fn (int $status, string $type, string $file, int $size): array => [
                'status' => $status,
                'headers' => ['Content-Type' => [$type]],
                'body_file' => $file,
                'size' => $size,
            ];

            $this->assertTrue(AdminerProxy::isLoginPageResponse($result(200, 'text/html; charset=utf-8', $path, strlen($loginPage))));
            $this->assertTrue(AdminerProxy::isLoginPageResponse($result(403, 'text/html', $path, strlen($loginPage))));
            $this->assertTrue(AdminerProxy::isLoginPageResponse($result(401, 'text/html', $path, strlen($loginPage))));
            // 413 (batas ukuran) & 500 tidak pernah dianggap halaman login.
            $this->assertFalse(AdminerProxy::isLoginPageResponse($result(413, 'text/html', $path, strlen($loginPage))));
            $this->assertFalse(AdminerProxy::isLoginPageResponse($result(500, 'text/html', $path, strlen($loginPage))));
            // Dokumen non-HTML & body kosong juga bukan.
            $this->assertFalse(AdminerProxy::isLoginPageResponse($result(200, 'text/css', $path, strlen($loginPage))));
            $this->assertFalse(AdminerProxy::isLoginPageResponse($result(200, 'text/html', $path, 0)));
            $this->assertFalse(AdminerProxy::isLoginPageResponse($result(200, 'text/html', '', 0)));
            // Halaman data (tanpa penanda form login) bukan halaman login.
            $dataPath = $path . '.data';
            file_put_contents($dataPath, $dataPage);
            $this->assertFalse(AdminerProxy::isLoginPageResponse($result(200, 'text/html', $dataPath, strlen($dataPage))));
            @unlink($dataPath);
        } finally {
            @unlink($path);
        }
    }
}
