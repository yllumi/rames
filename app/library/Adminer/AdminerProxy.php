<?php
declare(strict_types=1);

namespace app\library\Adminer;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use InvalidArgumentException;
use Workerman\Timer;

/**
 * Reverse proxy HTTP dari dashboard ke helper Adminer (fitur D1).
 *
 * Alur: browser → Webman (Auth + AppAccess + proxy ini) → helper Adminer
 * (`http://<container>:8080`, tanpa port publik) → container DB (TCP).
 *
 * Keputusan desain penting (semua terverifikasi nyata pada spike Fase 0):
 *
 *  1. **Kredensial tidak pernah ke browser.** Jar (cookie server-side) hidup di
 *     sesi Webman; `Set-Cookie` dari Adminer selalu dibuang dan `Cookie` yang
 *     dikirim ke helper hanya berasal dari jar. Karena Adminer menulis cookie
 *     dengan `path=<prefix>/` (bukan path nyata request ke helper `/`),
 *     cookie TIDAK bisa diandalkan lewat browser — memang harus server-side.
 *     `adminer_key` wajib ikut disimpan: Adminer memakainya untuk mendekripsi
 *     password yang tersimpan di sesi (`get_password()`).
 *
 *  2. **Auto-login = POST dari sisi server** (bukan injeksi JS — CSP Adminer
 *     ber-`nonce` per respons memblokir script suntikan): `GET /` untuk token →
 *     `POST /` dengan `token`, `auth[driver|server|username|password|db]` → 302
 *     dengan `Location` relatif. `Location` itu disimpan (kunci jar `__landing`)
 *     dan dipakai untuk **melengkapi** URL yang tidak membawa konteks server
 *     (`?server=`), sehingga membuka prefix polos langsung menampilkan halaman DB.
 *
 *  3. `X-Forwarded-Prefix: <prefix>` dikirim ke helper sebagai satu-satunya cara
 *     memberi tahu Adminer prefix proxy (URL yang dihasilkan relatif). HTML
 *     Adminer **tidak pernah** disunting.
 *
 *  4. Klien Guzzle dibangun dengan `HandlerStack::create(new CurlHandler())`
 *     eksplisit (opsi `curl`/`CURLOPT_*` per-request dilarang), `expect => false`
 *     (php -S tidak menjawab `100-continue` → hemat ~1 detik), `timeout` ≤ 30 s,
 *     `http_errors => false`, `allow_redirects => false`, `stream => true`, dan
 *     `sink` ke berkas sementara nyata ({@see LimitedTempSink}) sehingga byte
 *     tidak pernah ditahan di memori PHP dan bisa di-stream Workerman.
 *
 * Instance = satu permintaan (spec di-inject); tidak ada state lintas-request di
 * kelas ini — jar selalu berasal dari sesi (parameter by-reference).
 */
class AdminerProxy
{
    /** Pesan tunggal untuk semua pelanggaran batas ukuran (respons maupun request). */
    public const LIMIT_MESSAGE = 'Berkas terlalu besar untuk proxy Adminer — gunakan fitur Volume/backup atau Terminal untuk dump/import besar.';

    /**
     * Kunci jar non-cookie: `Location` hasil login (query relatif) untuk
     * melengkapi URL yang belum membawa konteks server Adminer.
     */
    public const JAR_LANDING = '__landing';

    /** Semua kunci jar berawalan ini tidak pernah dikirim sebagai cookie. */
    public const JAR_RESERVED_PREFIX = '__';

    /** Batas byte yang dibaca dari halaman login saat mengekstrak token. */
    private const LOGIN_SCAN_BYTES = 524288;

    /** Penanda form login Adminer (hanya halaman login yang memuat ini). */
    public const LOGIN_FORM_MARKER = 'auth[password]';

    /** Batas byte yang dipindai untuk mendeteksi halaman login (respons HTML). */
    private const STALE_SCAN_BYTES = 65536;

    /** Rencana sesi: sesi jar sudah ada. */
    public const MODE_READY = 'ready';

    /** Rencana sesi: kredensial ada → login otomatis sisi server. */
    public const MODE_AUTO = 'auto';

    /** Rencana sesi: kredensial tidak terdeteksi → user mengetik di form login. */
    public const MODE_MANUAL = 'manual';

    /**
     * Header `X-Forwarded-*` yang **tidak pernah** diteruskan dari klien.
     * Nilainya selalu dibentuk dari konteks dashboard (spec `forwarded_proto` /
     * `forwarded_for`) atau konfigurasi (`X-Forwarded-Prefix`).
     */
    private const CLIENT_FORWARDED_HEADERS = [
        'x-forwarded-proto',
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-port',
        'x-forwarded-prefix',
        'forwarded',
        'x-real-ip',
    ];

    /**
     * Header hop-by-hop (RFC 9110 §7.6.1) + `Set-Cookie`/`Content-Length`/
     * `Content-Encoding` yang tidak boleh diteruskan.
     */
    private const RESPONSE_DROP = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'set-cookie',
        'content-length',
        'content-encoding',
    ];

    /** Header request browser yang boleh diteruskan ke helper. */
    private const FORWARD_REQUEST_HEADERS = ['content-type', 'accept', 'accept-language'];

    private Client $client;

    /** @var array<string,mixed> */
    private array $spec;

    /**
     * @param array<string,mixed> $spec `base_url`, `prefix`, `timeout`,
     *        `max_bytes`, `temp_dir`, `credentials` (driver/server/username/
     *        password/db)
     */
    public function __construct(array $spec = [], ?Client $client = null)
    {
        $this->spec = self::normalizeSpec($spec);

        $this->client = $client ?? new Client([
            'handler' => HandlerStack::create(new CurlHandler()),
            'timeout' => (int) $this->spec['timeout'],
            'connect_timeout' => min(10, (int) $this->spec['timeout']),
            'http_errors' => false,
            'allow_redirects' => false,
            'expect' => false,
            'stream' => true,
            'decode_content' => false,
            // Selalu minta byte identitas: byte yang diterima == byte yang
            // diteruskan, sehingga `content-encoding` boleh dibuang.
            'headers' => ['Accept-Encoding' => 'identity'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function spec(): array
    {
        return $this->spec;
    }

    /**
     * Base URL helper Adminer.
     */
    public function baseUrl(): string
    {
        return rtrim((string) $this->spec['base_url'], '/');
    }

    /**
     * Prefix URL publik halaman Adminer (mis. `/database/myapp-db-1/adminer`).
     */
    public function prefix(): string
    {
        return (string) $this->spec['prefix'];
    }

    // ==================================================================
    // Proxy satu permintaan
    // ==================================================================

    /**
     * Teruskan satu permintaan browser ke helper Adminer.
     *
     * Dua mode sesi:
     *  - **auto** — kredensial DB terdeteksi (`credentials.server` +
     *    `credentials.username`) → login otomatis sisi server.
     *  - **manual** — kredensial tidak terdeteksi (mis. image DB custom tanpa env
     *    baku) → auto-login **dilewati**; helper diberi `?server=<host:port>` saja
     *    sehingga halaman login Adminer tampil dengan kolom Server ter-prefill dan
     *    user mengetik kredensialnya sendiri. Ini mempertahankan kemampuan form
     *    koneksi manual yang dulu ada di halaman `manage`.
     *
     * @param string                $method  metode HTTP
     * @param string                $path    path SETELAH prefix ('' = root helper)
     * @param string                $query   query string mentah tanpa '?'
     * @param string|null           $body    body mentah (multipart JANGAN diparse)
     * @param array<string,string>  $headers header request terpilih (`content-type`,
     *                                       `accept`, `accept-language`, `referer`,
     *                                       `origin`). Nilai `X-Forwarded-*` milik
     *                                       klien **diabaikan** (dibentuk dari spec).
     * @param array<string,string>  $jar     jar cookie server-side (by reference —
     *                                       dirotasi Set-Cookie & diisi `__landing`)
     * @return array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int,session_expired:bool}
     *         `session_expired = true` hanya untuk request yang mengubah data yang
     *         sesi Adminer-nya kedaluwarsa ⇒ pemanggil wajib merender halaman pesan
     *         (bukan body Adminer).
     * @throws AdminerProxyError
     * @throws AdminerProxyLimitExceeded
     */
    public function forward(string $method, string $path, string $query, ?string $body, array $headers, array &$jar): array
    {
        $jar = self::sanitizeJar($jar);
        $method = strtoupper($method);
        if ($method === '') {
            $method = 'GET';
        }

        $this->pruneTempDir();

        if ($body !== null && $body !== '') {
            self::assertWithinLimit(strlen($body), (int) $this->spec['max_bytes']);
        }

        $cleanPath = self::normalizePath($path);

        // 1) Sesi: login otomatis bila kredensial tersedia, selain itu mode manual.
        $hadSession = self::sessionPlan($this->spec['credentials'], $jar) === self::MODE_READY;
        $autoLoggedIn = false;
        if (!$hadSession) {
            $autoLoggedIn = $this->establishSession($jar);
        }

        // 2) Kirim permintaan yang diminta.
        $result = $this->sendAndSanitize($method, $cleanPath, $query, $body, $headers, $jar);
        $loginPage = self::isLoginPageResponse($result);

        // 3) Halaman login = sesi helper tidak dikenal (basi) ATAU kredensial
        //    otomatis ditolak. Pulihkan SEKALI (lihat recoverLoginPage()).
        $retried = false;
        if ($loginPage && self::canRecoverLoginPage($hadSession, $autoLoggedIn)) {
            $retried = true;
            $retry = $this->recoverLoginPage($method, $cleanPath, $query, $body, $headers, $jar, $hadSession && !$autoLoggedIn);
            if ($retry !== null) {
                self::discard((string) $result['body_file']);
                $result = $retry;
                $loginPage = self::isLoginPageResponse($result);
            }
        }

        // 4) Hasil akhir.
        $result['session_expired'] = false;
        if (self::needsSessionExpiredNotice($method, $result, $loginPage, $retried)) {
            // Permintaan yang mengubah data + halaman login/gagal otorisasi ⇒
            // input pengguna hilang. Percobaan pertama TIDAK berefek samping
            // (halaman login berarti Adminer tidak memproses form), percobaan
            // kedua pun tidak berhasil ⇒ pemanggil menampilkan halaman pesan Rames.
            self::discard((string) $result['body_file']);
            $result['status'] = 409;
            $result['headers'] = ['Content-Type' => ['text/html; charset=utf-8']];
            $result['body_file'] = '';
            $result['size'] = 0;
            $result['session_expired'] = true;

            return $result;
        }

        if ($loginPage) {
            // Halaman login adalah respons normal untuk navigasi GET. Adminer
            // memberi 403 saat URL membawa `?username=` (`auth_error()`) →
            // status dinormalkan ke 200 agar browser memperlakukannya sebagai
            // halaman biasa (HTML Adminer tidak disunting).
            $result['status'] = 200;
        }

        if ((string) $result['body_file'] !== '') {
            // Workerman membaca berkas SETELAH handler kembali → jadwalkan
            // pembersihan (juga menutup jalur stream besar).
            $this->scheduleCleanup((string) $result['body_file']);
        }

        return $result;
    }

    /**
     * Kirim satu permintaan + bersihkan header responsnya.
     *
     * @param array<string,string> $headers
     * @param array<string,string> $jar
     * @return array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int}
     */
    private function sendAndSanitize(string $method, string $path, string $query, ?string $body, array $headers, array &$jar): array
    {
        $effectiveQuery = self::effectiveQuery($query, $jar);
        $result = $this->send(
            $method,
            self::targetUrl($this->baseUrl(), $path, $effectiveQuery),
            $body,
            $this->forwardHeaders($headers, $path, $effectiveQuery),
            $jar
        );
        $result['headers'] = self::sanitizeResponseHeaders($result['headers'], $this->prefix());

        return $result;
    }

    /**
     * Pulihkan sesi Adminer setelah helper menjawab halaman login.
     *
     * Dipanggil **paling banyak sekali** per permintaan (tidak ada loop) dan
     * **hanya** ketika responsnya benar-benar halaman login (bukan 413, 500, dll).
     *
     * Mengapa mengulang permintaan aman — termasuk untuk POST: halaman login
     * (termasuk 403 `auth_error()` yang Adminer kirim saat URL memuat
     * `?username=`) berarti Adminer **tidak memproses** permintaan itu sama sekali,
     * sehingga percobaan pertama tidak menghasilkan efek samping apa pun.
     *
     * Yang **tidak** dilakukan: menyegarkan/memalsukan token CSRF Adminer. Token
     * form Adminer terikat ke sesi (`get_token()`/`verify_token()`), jadi POST
     * form dari sesi lama tetap ditolak Adminer setelah login ulang. Memasok token
     * baru ke body pengguna akan melemahkan pertahanan CSRF Adminer (dan melebarkan
     * pengecualian CSRF di `CsrfMiddleware`) — karena itu tidak dilakukan; pemanggil
     * menampilkan halaman pesan Rames (`session_expired`) agar user mengulang aksi.
     *
     * @param bool $reLogin true bila sesi lama memang pernah ada & kredensial
     *                      tersedia → login ulang otomatis masuk akal
     * @param array<string,string> $headers
     * @param array<string,string> $jar
     * @return array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int}|null
     */
    private function recoverLoginPage(string $method, string $path, string $query, ?string $body, array $headers, array &$jar, bool $reLogin): ?array
    {
        self::forgetSession($jar);
        try {
            if ($reLogin && $this->hasCredentials()) {
                $this->autoLogin($jar);
            } else {
                // Kredensial otomatis tidak ada/ditolak → mode manual: halaman
                // login berikutnya tampil dengan Server ter-prefill.
                $this->setManualLanding($jar);
            }
        } catch (AdminerProxyLimitExceeded $e) {
            throw $e;
        } catch (AdminerProxyError $e) {
            // Helper/Engine bermasalah saat login ulang → biarkan respons asli
            // (halaman Adminer) dikembalikan, bukan 502.
            $this->setManualLanding($jar);

            return null;
        }

        try {
            return $this->sendAndSanitize($method, $path, $query, $body, $headers, $jar);
        } catch (AdminerProxyLimitExceeded $e) {
            // Batas ukuran harus tetap diteruskan apa adanya (bukan dipulihkan).
            throw $e;
        } catch (AdminerProxyError $e) {
            return null;
        }
    }

    // ==================================================================
    // Auto-login (sisi server)
    // ==================================================================

    /**
     * Bangun sesi untuk permintaan ini.
     *
     * @param array<string,string> $jar
     * @return bool true bila login otomatis dijalankan (kredensial tersedia)
     */
    private function establishSession(array &$jar): bool
    {
        if ($this->hasCredentials()) {
            $this->autoLogin($jar);

            return true;
        }

        $this->setManualLanding($jar);

        return false;
    }

    /**
     * Mode manual: tanpa kredensial otomatis, user mengetik sendiri.
     *
     * Landing hanya berisi `server=<host:port>` supaya Adminer mengisi kolom
     * Server pada form login (Adminer memakai `$_GET['server']` sebagai nilai
     * field — terverifikasi dari respons nyata) dan URL turunannya tetap membawa
     * konteks server. **HTML Adminer tidak disunting.**
     *
     * `ADMINER_DEFAULT_SERVER` pada helper tidak diandalkan: satu helper dipakai
     * bersama banyak container DB, jadi nilai env itu (bawaan `db`) akan
     * menyesatkan.
     *
     * @param array<string,string> $jar
     */
    private function setManualLanding(array &$jar): void
    {
        $landing = self::manualLanding($this->spec['credentials']);
        if ($landing === '') {
            unset($jar[self::JAR_LANDING]);

            return;
        }
        $jar[self::JAR_LANDING] = $landing;
    }

    /**
     * Login otomatis: `GET /` (ambil token) → `POST /` (auth[…]) → simpan jar +
     * `Location` (landing). Kredensial **tidak pernah** menyentuh browser/argv.
     */
    private function autoLogin(array &$jar): void
    {
        $creds = $this->credentials();
        if ($creds['server'] === '' || $creds['username'] === '') {
            throw new AdminerProxyError(
                'Kredensial DB tidak tersedia untuk login otomatis ke Adminer '
                . '(host/username kosong — deteksi kredensial container gagal).'
            );
        }

        $get = $this->send('GET', $this->baseUrl() . '/', null, ['Accept' => 'text/html'], $jar);
        try {
            $html = self::readBody($get['body_file'], self::LOGIN_SCAN_BYTES);
        } finally {
            self::discard($get['body_file']);
        }

        if ($get['status'] >= 400) {
            throw new AdminerProxyError('Helper Adminer menolak permintaan halaman login (HTTP ' . $get['status'] . ').');
        }

        $token = self::extractToken($html);
        if ($token === null) {
            throw new AdminerProxyError('Tidak bisa membaca token login Adminer dari helper (helper belum siap?).');
        }

        $fields = http_build_query([
            'auth' => [
                'driver' => $creds['driver'],
                'server' => $creds['server'],
                'username' => $creds['username'],
                'password' => $creds['password'],
                'db' => $creds['db'],
            ],
            'token' => $token,
        ], '', '&', PHP_QUERY_RFC3986);

        $post = $this->send(
            'POST',
            $this->baseUrl() . '/',
            $fields,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'text/html'],
            $jar
        );
        $location = self::headerFirst($post['headers'], 'location');
        self::discard($post['body_file']);

        if ($post['status'] !== 302 && $post['status'] !== 303) {
            throw new AdminerProxyError(
                'Login otomatis ke Adminer gagal (HTTP ' . $post['status'] . ') — periksa kredensial DB app.'
            );
        }

        $landing = self::queryOfLocation((string) $location);
        if ($landing !== '') {
            $jar[self::JAR_LANDING] = $landing;
        }
    }

    /**
     * Token form login Adminer (`name='token' value='…'`).
     */
    public static function extractToken(string $html): ?string
    {
        if (preg_match('/name=[\'"]token[\'"]\s+value=[\'"]([^\'"]+)[\'"]/', $html, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * Query (tanpa '?') dari sebuah `Location`.
     */
    public static function queryOfLocation(string $location): string
    {
        $pos = strpos($location, '?');
        if ($pos === false) {
            return '';
        }
        $query = substr($location, $pos + 1);
        $hash = strpos($query, '#');
        if ($hash !== false) {
            $query = substr($query, 0, $hash);
        }

        return $query;
    }

    /**
     * Apakah potongan body ini halaman login Adminer (bukan halaman data).
     */
    public static function looksLikeLoginForm(string $body): bool
    {
        return str_contains($body, self::LOGIN_FORM_MARKER);
    }

    /**
     * Apakah respons ini halaman login Adminer ⇒ sesi helper tidak dikenal (basi)
     * atau kredensial otomatis ditolak.
     *
     * Status yang mungkin: 200 (tanpa konteks server) atau 401/403 (`auth_error()`
     * saat URL membawa `?username=`). Status lain (413 batas ukuran, 500, …)
     * **bukan** halaman login → tidak pernah dipulihkan/diulang.
     *
     * @param array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int} $result
     */
    public static function isLoginPageResponse(array $result): bool
    {
        if (!in_array((int) $result['status'], [200, 401, 403], true)) {
            return false;
        }
        if (!str_contains(strtolower(self::headerFirst((array) $result['headers'], 'content-type')), 'text/html')) {
            return false;
        }
        $size = (int) $result['size'];
        if ($size <= 0 || $size > self::STALE_SCAN_BYTES) {
            return false;
        }

        return self::looksLikeLoginForm(self::readBody((string) $result['body_file'], self::STALE_SCAN_BYTES));
    }

    /**
     * Boleh tidaknya halaman login dipulihkan (login ulang + ulangi SEKALI).
     *
     * Hanya bila sesi lama memang pernah ada (basi) atau login otomatis baru saja
     * dijalankan. Mode manual tanpa sesi (kunjungan pertama, user mengetik sendiri)
     * → halaman login adalah hasil yang diharapkan, tidak perlu diulang.
     */
    public static function canRecoverLoginPage(bool $hadSession, bool $autoLoggedIn): bool
    {
        return $hadSession || $autoLoggedIn;
    }

    /**
     * Apakah metode ini mengubah data (efek samping bila diulang).
     */
    public static function isMutating(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Apakah hasil akhir harus diganti halaman pesan "sesi Adminer kedaluwarsa"
     * (bukan body Adminer) karena aksi pengguna **tidak dijalankan**.
     *
     * Hanya untuk permintaan yang mengubah data, dan hanya bila:
     *  - Adminer menjawab halaman login (form tidak diproses sama sekali ⇒ input
     *    pengguna hilang), atau
     *  - percobaan ulang setelah login ulang tetap gagal secara otorisasi
     *    (401/403) — kasus nyata: token CSRF form berasal dari sesi lama sehingga
     *    Adminer menolaknya walaupun sesinya sudah diperbarui. Token TIDAK
     *    dipalsukan (lihat {@see self::recoverLoginPage()}).
     *
     * Status lain **tidak pernah** diganti: 413 (batas ukuran) diteruskan apa
     * adanya, begitu juga 5xx/4xx lain dan respons sukses (200/302).
     *
     * @param array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int} $result
     * @param bool $loginPage hasil {@see self::isLoginPageResponse()}
     * @param bool $retried   apakah permintaan sudah diulang sekali
     */
    public static function needsSessionExpiredNotice(string $method, array $result, bool $loginPage, bool $retried): bool
    {
        if (!self::isMutating($method)) {
            return false;
        }
        if ($loginPage) {
            return true;
        }

        return $retried && in_array((int) $result['status'], [401, 403], true);
    }

    /**
     * Buang sesi Adminer dari jar (cookie sesi + landing) agar login/landing
     * dibangun ulang — dipakai saat sesi helper terbukti basi.
     *
     * @param array<string,string> $jar
     */
    private static function forgetSession(array &$jar): void
    {
        unset($jar['adminer_sid'], $jar['adminer_key'], $jar[self::JAR_LANDING]);
    }

    // ==================================================================
    // Rencana sesi & mode manual (statik murni)
    // ==================================================================

    /**
     * Rencana sesi untuk satu permintaan: `ready` (sesi jar sudah ada), `auto`
     * (kredensial tersedia → login otomatis), atau `manual` (kredensial tidak
     * terdeteksi → user mengetik sendiri di halaman login Adminer).
     *
     * Statik murni supaya pemilihan jalur kredensial-kosong bisa diuji tanpa HTTP
     * maupun Docker.
     *
     * @param array<string,mixed>  $credentials
     * @param array<string,string> $jar
     * @return string self::MODE_*
     */
    public static function sessionPlan(array $credentials, array $jar): string
    {
        if ((string) ($jar['adminer_sid'] ?? '') !== '' && (string) ($jar['adminer_key'] ?? '') !== '') {
            return self::MODE_READY;
        }

        return self::credentialsUsable($credentials) ? self::MODE_AUTO : self::MODE_MANUAL;
    }

    /**
     * Landing mode manual: `server=<host:port>` saja (tanpa username/db) sehingga
     * form login Adminer ter-prefill pada kolom Server dan user mengetik sisanya.
     *
     * @param array<string,mixed> $credentials
     * @return string query relatif ('' bila host tidak diketahui)
     */
    public static function manualLanding(array $credentials): string
    {
        $server = (string) ($credentials['server'] ?? '');
        if ($server === '') {
            return '';
        }

        return 'server=' . rawurlencode($server);
    }

    /**
     * Kredensial cukup untuk login otomatis: host + username harus ada.
     *
     * @param array<string,mixed> $credentials
     */
    private static function credentialsUsable(array $credentials): bool
    {
        return (string) ($credentials['server'] ?? '') !== '' && (string) ($credentials['username'] ?? '') !== '';
    }

    // ==================================================================
    // Normalisasi URL & guard SSRF (statik murni)
    // ==================================================================

    /**
     * Normalisasi path dari route `{path:.*}`.
     *
     * Guard SSRF: menolak `..`, path absolut, `//`, backslash, dan karakter
     * kontrol — proxy hanya boleh menuju helper (base URL dari config), tidak
     * pernah host lain.
     *
     * Bentuk **ter-encode** ditolak juga (temuan Fase 5a RENDAH): `%2e` (`.`),
     * `%2f` (`/`), dan `%5c` (`\`) tidak boleh lolos walau framework URL sudah
     * menolak sebagian besar variasi — guard ini tidak boleh bergantung pada
     * lapisan lain.
     */
    public static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (preg_match('#%2e|%2f|%5c#i', $path) === 1) {
            throw new InvalidArgumentException('Path proxy Adminer tidak valid (bentuk ter-encode).');
        }
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '//')) {
            throw new InvalidArgumentException('Path proxy Adminer tidak valid.');
        }
        if (str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Path proxy Adminer tidak boleh absolut.');
        }
        if (preg_match('#[\x00-\x1f\x7f]#', $path) === 1) {
            throw new InvalidArgumentException('Path proxy Adminer memuat karakter kontrol.');
        }

        return $path;
    }

    /**
     * URL target di helper. Selalu `base_url` + path ternormalisasi (+ query) —
     * host tidak pernah berasal dari input pengguna.
     */
    public static function targetUrl(string $baseUrl, string $path, string $query): string
    {
        $url = rtrim($baseUrl, '/') . '/' . $path;
        if ($query !== '') {
            $url .= '?' . ltrim($query, '?');
        }

        return $url;
    }

    /**
     * Lengkapi query dengan konteks login (`server`/`username`/`db`) bila
     * permintaan belum membawanya — sehingga prefix polos (atau tautan UI tanpa
     * query) tetap menampilkan halaman DB, bukan form login.
     */
    public static function effectiveQuery(string $query, array $jar): string
    {
        $query = ltrim($query, '?');
        if (self::hasQueryParam($query, 'server')) {
            return $query;
        }
        $landing = (string) ($jar[self::JAR_LANDING] ?? '');
        if ($landing === '') {
            return $query;
        }

        return $query === '' ? $landing : $landing . '&' . $query;
    }

    private static function hasQueryParam(string $query, string $name): bool
    {
        if ($query === '') {
            return false;
        }
        foreach (explode('&', $query) as $pair) {
            $key = explode('=', $pair, 2)[0];
            if (rawurldecode($key) === $name) {
                return true;
            }
        }

        return false;
    }

    // ==================================================================
    // Header (statik murni)
    // ==================================================================

    /**
     * Bersihkan header respons helper: buang hop-by-hop, `Set-Cookie`,
     * `Content-Length`, `Content-Encoding`, dan rewrite `Location` ke prefix.
     *
     * @param array<string,array<int,string>> $headers
     * @return array<string,array<int,string>>
     */
    public static function sanitizeResponseHeaders(array $headers, string $prefix): array
    {
        $out = [];
        foreach ($headers as $name => $headerValues) {
            $lower = strtolower((string) $name);
            if ($lower === '' || in_array($lower, self::RESPONSE_DROP, true)) {
                continue;
            }

            $values = [];
            foreach ((array) $headerValues as $value) {
                $value = (string) $value;
                if ($value !== '') {
                    $values[] = $value;
                }
            }
            if ($values === []) {
                continue;
            }

            if ($lower === 'location') {
                $values = [self::rewriteLocation($values[0], $prefix)];
            }

            $out[self::canonicalHeaderName($lower)] = $values;
        }

        return $out;
    }

    /**
     * Rewrite `Location` agar selalu berada di bawah prefix proxy.
     *
     * - absolut (`http(s)://…`) → diambil path+query-nya saja (cegah kebocoran
     *   host helper/redirect keluar),
     * - absolut-path yang sudah ber-prefix → dibiarkan,
     * - absolut-path lain → ditempeli prefix,
     * - relatif → dibiarkan (browser menyelesaikannya terhadap URL ber-prefix).
     */
    public static function rewriteLocation(string $location, string $prefix): string
    {
        $location = trim($location);
        if ($location === '') {
            return '';
        }
        $prefix = '/' . trim($prefix, '/');

        if (preg_match('#^https?://#i', $location) === 1) {
            $parts = parse_url($location);
            if (is_array($parts)) {
                $path = (string) ($parts['path'] ?? '/');
                $location = $path === '' ? '/' : $path;
                if (($parts['query'] ?? '') !== '') {
                    $location .= '?' . $parts['query'];
                }
            }
        }

        if (str_starts_with($location, '//')) {
            $location = '/' . ltrim($location, '/');
        }

        if (str_starts_with($location, '/')) {
            if (
                $location === $prefix
                || str_starts_with($location, $prefix . '/')
                || str_starts_with($location, $prefix . '?')
            ) {
                return $location;
            }

            return $prefix . $location;
        }

        return $location;
    }

    /**
     * `content-type` → `Content-Type` (Workerman memeriksa nama ini
     * case-sensitif saat mengirim berkas).
     */
    public static function canonicalHeaderName(string $name): string
    {
        return implode('-', array_map(static fn (string $part): string => ucfirst($part), explode('-', strtolower($name))));
    }

    /**
     * Nilai pertama sebuah header — pencarian **case-insensitive**.
     *
     * Kunci `getHeaders()` PSR-7 memakai casing asli dari server (`Location`,
     * `Set-Cookie`), jadi tidak boleh diindeks dengan literal lowercase.
     *
     * @param array<string,array<int,string>> $headers
     */
    public static function headerFirst(array $headers, string $name): string
    {
        $needle = strtolower($name);
        foreach ($headers as $key => $values) {
            if (strtolower((string) $key) !== $needle) {
                continue;
            }
            foreach ((array) $values as $value) {
                $value = (string) $value;
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Semua nilai sebuah header (case-insensitive).
     *
     * @param array<string,array<int,string>> $headers
     * @return array<int,string>
     */
    public static function headerAll(array $headers, string $name): array
    {
        $needle = strtolower($name);
        $out = [];
        foreach ($headers as $key => $values) {
            if (strtolower((string) $key) !== $needle) {
                continue;
            }
            foreach ((array) $values as $value) {
                $value = (string) $value;
                if ($value !== '') {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Guard batas ukuran; pesannya tunggal & jelas (lihat {@see self::LIMIT_MESSAGE}).
     *
     * @throws AdminerProxyLimitExceeded
     */
    public static function assertWithinLimit(int $bytes, int $maxBytes): void
    {
        if ($maxBytes > 0 && $bytes > $maxBytes) {
            throw new AdminerProxyLimitExceeded(self::LIMIT_MESSAGE);
        }
    }

    // ==================================================================
    // Jar cookie (statik murni + pembungkus sesi)
    // ==================================================================

    /**
     * Header `Cookie` dari jar — kunci reserved (`__…`) tidak ikut dikirim.
     *
     * @param array<string,string> $jar
     */
    public static function cookieHeader(array $jar): string
    {
        $pairs = [];
        foreach ($jar as $name => $value) {
            $name = (string) $name;
            if ($name === '' || str_starts_with($name, self::JAR_RESERVED_PREFIX)) {
                continue;
            }
            $pairs[] = $name . '=' . (string) $value;
        }

        return implode('; ', $pairs);
    }

    /**
     * Ambil `name=value` pertama dari satu header `Set-Cookie`.
     *
     * @return array{0:string,1:string}|null
     */
    public static function parseSetCookie(string $raw): ?array
    {
        $pair = explode(';', $raw, 2)[0];
        $eq = strpos($pair, '=');
        if ($eq === false) {
            return null;
        }
        $name = trim(substr($pair, 0, $eq));
        if ($name === '') {
            return null;
        }

        return [$name, trim(substr($pair, $eq + 1))];
    }

    // ==================================================================
    // Eksekusi HTTP (internal)
    // ==================================================================

    /**
     * Kirim satu permintaan ke helper; isi jar dari `Set-Cookie`; simpan body ke
     * berkas sementara (byte tidak ditahan di memori PHP).
     *
     * @param array<string,string> $headers
     * @param array<string,string> $jar
     * @return array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int}
     * @throws AdminerProxyError|AdminerProxyLimitExceeded
     */
    private function send(string $method, string $url, ?string $body, array $headers, array &$jar): array
    {
        $cookie = self::cookieHeader($jar);
        if ($cookie !== '') {
            $headers['Cookie'] = $cookie;
        }

        $created = AdminerTempDir::create($this->tempDir());
        if ($created === null) {
            throw new AdminerProxyError('Tidak bisa membuat berkas sementara proxy Adminer di "' . $this->tempDir() . '".');
        }
        [$path, $handle] = $created;
        $sink = new LimitedTempSink($handle, (int) $this->spec['max_bytes']);
        $maxBytes = (int) $this->spec['max_bytes'];
        $options = [
            'headers' => $headers,
            'stream' => true,
            'sink' => $sink,
            'http_errors' => false,
            'allow_redirects' => false,
            'decode_content' => false,
            'timeout' => (int) $this->spec['timeout'],
            'expect' => false,
            // Tolak lebih awal bila `Content-Length` sudah melewati batas.
            'on_headers' => static function ($response) use ($maxBytes): void {
                $length = (int) $response->getHeaderLine('Content-Length');
                if ($length > 0 && $length > $maxBytes) {
                    throw new AdminerProxyLimitExceeded(AdminerProxy::LIMIT_MESSAGE);
                }
            },
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (\Throwable $e) {
            $sink->close();
            @unlink($path);
            if (self::isLimitExceeded($e)) {
                throw new AdminerProxyLimitExceeded(self::LIMIT_MESSAGE, 0, $e);
            }
            throw new AdminerProxyError('Proxy Adminer gagal menghubungi helper: ' . $e->getMessage(), 0, $e);
        }

        $sink->close();

        $size = @filesize($path);
        $size = $size === false ? 0 : (int) $size;
        if ($maxBytes > 0 && $size > $maxBytes) {
            @unlink($path);
            throw new AdminerProxyLimitExceeded(self::LIMIT_MESSAGE);
        }

        // Rotasi cookie Adminer (`adminer_sid`) + `adminer_key` — wajib disimpan
        // karena Adminer memakainya untuk mendekripsi password di sesinya.
        foreach (self::headerAll($response->getHeaders(), 'set-cookie') as $rawCookie) {
            $parsed = self::parseSetCookie($rawCookie);
            if ($parsed === null) {
                continue;
            }
            [$name, $value] = $parsed;
            if ($value === '') {
                unset($jar[$name]);
                continue;
            }
            $jar[$name] = $value;
        }

        return [
            'status' => (int) $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body_file' => $path,
            'size' => $size,
        ];
    }

    /**
     * Apakah kegagalan berasal dari guard batas ukuran (langsung atau dibungkus Guzzle).
     */
    private static function isLimitExceeded(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AdminerProxyLimitExceeded) {
                return true;
            }
        }

        return str_contains($e->getMessage(), self::LIMIT_MESSAGE);
    }

    /**
     * Header request ke helper: hanya subset aman + konteks proxy.
     *
     * `Cookie` milik browser TIDAK pernah diteruskan (hanya jar); `Referer`/
     * `Origin` ditulis ulang ke host helper agar host dashboard tidak bocor.
     *
     * `X-Forwarded-*` milik klien **tidak pernah** diteruskan apa adanya
     * (temuan Fase 5a INFO):`X-Forwarded-Proto`/`X-Forwarded-For` dibentuk dari
     * konteks dashboard (spec `forwarded_proto`/`forwarded_for`, yaitu skema
     * permintaan dashboard & `REMOTE_ADDR` yang divalidasi), dan
     * `X-Forwarded-Prefix` selalu dari konfigurasi prefix — bukan referensi
     * klien. `X-Forwarded-Host` tidak pernah dikirim.
     *
     * @param array<string,string> $incoming
     * @return array<string,string>
     */
    private function forwardHeaders(array $incoming, string $path, string $query): array
    {
        $incoming = array_change_key_case($incoming, CASE_LOWER);

        // Buang eksplisit setiap `X-Forwarded-*`/`Forwarded`/`X-Real-IP` dari
        // klien — jangan hanya bergantung pada whitelist di bawah, supaya
        // kebocoran tidak kembali bila suatu saat whitelist diperluas.
        foreach (array_keys($incoming) as $name) {
            if (self::isClientForwardedHeader((string) $name)) {
                unset($incoming[$name]);
            }
        }

        $out = [];
        foreach (self::FORWARD_REQUEST_HEADERS as $name) {
            $value = (string) ($incoming[$name] ?? '');
            if ($value !== '') {
                $out[$name] = $value;
            }
        }

        $out['X-Forwarded-Prefix'] = $this->prefix();
        $out['X-Forwarded-Proto'] = $this->forwardedProto();

        $for = $this->forwardedFor();
        if ($for !== '') {
            $out['X-Forwarded-For'] = $for;
        }

        if ((string) ($incoming['referer'] ?? '') !== '') {
            $out['Referer'] = self::targetUrl($this->baseUrl(), $path, $query);
        }
        if ((string) ($incoming['origin'] ?? '') !== '') {
            $out['Origin'] = $this->baseUrl();
        }

        return $out;
    }

    /**
     * Apakah header berasal dari klien (dan karena itu tidak boleh diteruskan).
     * Statik murni — dipakai test & dokumentasi kontrak.
     */
    public static function isClientForwardedHeader(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::CLIENT_FORWARDED_HEADERS, true);
    }

    /**
     * Skema eksternal dashboard (`http`/`https`) yang dikirim ke helper.
     *
     * Nilainya berasal dari konteks permintaan dashboard (spec `forwarded_proto`
     * — diisi pemanggil dari skema request, mis. `X-Forwarded-Proto` yang di-set
     * Nginx), **bukan** dari header yang dibawa klien ke endpoint proxy. Nilai
     * tak dikenal jatuh ke `http`.
     */
    public function forwardedProto(): string
    {
        $proto = strtolower(trim((string) $this->spec['forwarded_proto']));

        return $proto === 'https' ? 'https' : 'http';
    }

    /**
     * Alamat klien dashboard untuk `X-Forwarded-For` (spec `forwarded_for` —
     * diisi pemanggil dari `REMOTE_ADDR`). Hanya IP valid yang diteruskan;
     * rantai/hostname/port ditolak agar tidak bisa diselundupkan klien.
     */
    public function forwardedFor(): string
    {
        $for = trim((string) $this->spec['forwarded_for']);

        return filter_var($for, FILTER_VALIDATE_IP) !== false ? $for : '';
    }

    /**
     * @return array{driver:string,server:string,username:string,password:string,db:string}
     */
    private function credentials(): array
    {
        $creds = (array) $this->spec['credentials'];

        return [
            'driver' => (string) (($creds['driver'] ?? '') ?: 'server'),
            'server' => (string) ($creds['server'] ?? ''),
            'username' => (string) ($creds['username'] ?? ''),
            'password' => (string) ($creds['password'] ?? ''),
            'db' => (string) ($creds['db'] ?? ''),
        ];
    }

    /**
     * Kredensial spes ini cukup untuk login otomatis (host + username).
     */
    private function hasCredentials(): bool
    {
        return self::credentialsUsable((array) $this->spec['credentials']);
    }

    // ==================================================================
    // Berkas sementara
    // ==================================================================

    private function tempDir(): string
    {
        return AdminerTempDir::path((string) $this->spec['temp_dir']);
    }

    /**
     * Jadwalkan penghapusan berkas sementara (Workerman membaca berkas setelah
     * handler kembali, jadi pembersihan tidak boleh sinkron).
     */
    public function scheduleCleanup(string $path, int $delaySeconds = 600): void
    {
        if ($path === '') {
            return;
        }
        try {
            Timer::add($delaySeconds, static function () use ($path): void {
                @unlink($path);
            }, [], false);
        } catch (\Throwable $e) {
            // Tanpa event loop (CLI/test): andalkan prune oportunistik.
        }
    }

    /**
     * Prune oportunistik berkas respons basi (backstop bila `Timer` tidak
     * tersedia — mis. proses CLI/test). Delegasi ke {@see AdminerTempDir::prune()}
     * yang idempoten & tidak pernah melempar.
     */
    public function pruneTempDir(): void
    {
        AdminerTempDir::prune($this->tempDir());
    }

    /**
     * Baca sebagian berkas body (dibatasi agar halaman login tidak pernah besar).
     */
    private static function readBody(string $path, int $maxBytes): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $data = @file_get_contents($path, false, null, 0, $maxBytes);

        return $data === false ? '' : $data;
    }

    private static function discard(string $path): void
    {
        if ($path !== '') {
            @unlink($path);
        }
    }

    /**
     * @param array<mixed> $jar
     * @return array<string,string>
     */
    private static function sanitizeJar(array $jar): array
    {
        $out = [];
        foreach ($jar as $name => $value) {
            $name = (string) $name;
            if ($name === '' || !is_scalar($value)) {
                continue;
            }
            $out[$name] = (string) $value;
        }

        return $out;
    }

    /**
     * Default spec dari config (tanpa I/O).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public static function normalizeSpec(array $overrides): array
    {
        $defaults = [
            'base_url' => 'http://' . (string) config('deploy.adminer_container', 'rames-adminer')
                . ':' . AdminerHelper::PORT,
            'prefix' => '/',
            'timeout' => (int) config('deploy.adminer_proxy_timeout', 30),
            'max_bytes' => (int) config('deploy.adminer_proxy_max_bytes', 67108864),
            'temp_dir' => runtime_path() . '/adminer-proxy',
            // Konteks proxy (`X-Forwarded-*`) — BUKAN nilai dari klien:
            // `forwarded_proto` = skema permintaan dashboard (http/https),
            // `forwarded_for` = alamat klien dashboard (REMOTE_ADDR, IP saja).
            // Keduanya opsional; kosong → `http` & tanpa `X-Forwarded-For`.
            'forwarded_proto' => '',
            'forwarded_for' => '',
            'credentials' => [],
        ];

        $spec = array_replace($defaults, array_intersect_key($overrides, $defaults));

        // Aturan repo: klien HTTP tidak boleh punya timeout > 30 detik.
        $spec['timeout'] = max(1, min(30, (int) $spec['timeout']));
        $spec['max_bytes'] = max(1024, (int) $spec['max_bytes']);
        $spec['prefix'] = '/' . trim((string) $spec['prefix'], '/');
        $spec['base_url'] = rtrim((string) $spec['base_url'], '/');
        $spec['temp_dir'] = rtrim((string) $spec['temp_dir'], '/');

        return $spec;
    }
}
