<?php
declare(strict_types=1);

namespace app\library\Agent;

use app\library\Adminer\AdminerProxyLimitExceeded;
use app\library\Adminer\AdminerTempDir;
use app\library\Adminer\LimitedTempSink;
use app\middleware\AgentAuthMiddleware;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;

/**
 * Klien HTTP control plane → agent (plan §4, §4.1; misi F0).
 *
 * Kontrak yang dikunci (identik karakter-per-karakter dengan sisi agent —
 * `AgentAuthMiddleware`):
 *
 *   canonical = "{timestamp}\n{METHOD}\n{path}\n{sha256(body_hex)}"
 *   signature = hash_hmac('sha256', canonical, token)   // hex huruf kecil
 *
 * `path` = path request apa adanya, diawali `/`, **tanpa** query string
 * (`/jobs`, `/jobs/{id}/log`) — query (`?offset=N`) ikut di URI tapi tidak
 * ditandatangani. `body_hex` = `hash('sha256', $body)` atas body mentah yang
 * benar-benar dikirim (`''` untuk GET tanpa body).
 *
 * Fail-fast (aturan repo: tak ada jalur tidak aman):
 *  - `base_url` wajib `https://` (tanpa prefix path; route agent ada di akar) — **tidak ada**
 *    fallback plain HTTP dan tidak ada `verify=false`;
 *  - token kosong ⇒ `AgentError('invalid_config')`;
 *  - PEM sertifikat hilang/rusak ⇒ `AgentError('invalid_config')` (via
 *    {@see TlsPinMaterializer}), dan path hasil materialisasi dipakai sebagai
 *    `verify` (self-signed cert sebagai trust anchor-nya sendiri).
 *
 * Timeout **selalu** ≤ 30 s (aturan repo #7): operasi panjang di agent berbentuk
 * job async (`POST /jobs` → polling `GET /jobs/{id}` + `GET /jobs/{id}/log`).
 * Karena itu tidak ada request panjang di sini.
 *
 * Tanpa state lintas-request: seluruh properti `readonly` dan ditetapkan
 * konstruktor; tidak ada properti statik yang berubah ⇒ aman di worker
 * persistent. Handler Guzzle injectable supaya uji berjalan tanpa jaringan.
 */
final class AgentClient
{
    /** Plafon keras timeout HTTP (aturan repo: klien HTTP ≤ 30 s). */
    public const MAX_TIMEOUT = 30;
    /** Timeout koneksi tetap — handshake agent lokal/DC harus murah. */
    public const CONNECT_TIMEOUT = 5;
    public const DEFAULT_TIMEOUT = 25;

    public const HEADER_TIMESTAMP = 'X-Rames-Timestamp';
    public const HEADER_NONCE = 'X-Rames-Nonce';
    public const HEADER_SIGNATURE = 'X-Rames-Signature';

    /** Nonce: 16 byte acak = 32 karakter hex (≥16 karakter yang dituntut agent). */
    private const NONCE_BYTES = 16;
    private const USER_AGENT = 'rames-control-plane/1.0';

    /**
     * Batas byte per pembacaan stream (non-blocking) — menjaga memori per tick
     * `Timer` tetap kecil. Bukan batas panjang stream.
     */
    public const STREAM_READ_BYTES = 16384;

    /**
     * `Accept` untuk jalur streaming: `application/json` **wajib** ikut supaya
     * galat agent (401/404/500) tetap dirender sebagai JSON oleh webman
     * (`Request::expectsJson()`), bukan halaman HTML — pesannya lalu bisa
     * diteruskan sebagai `AgentError` yang jelas.
     */
    private const ACCEPT_STREAM = 'application/json, text/event-stream';
    /** Bentuk job id yang boleh masuk ke path URL (anti path traversal). */
    private const JOB_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /** Pola gagal-TLS yang dikenali dari pesan cURL/Guzzle (tanpa `false` keyword). */
    private const TLS_NEEDLES = [
        'ssl certificate',
        'ssl peer',
        'ssl connect',
        'ssl error',
        'ssl: ',
        'ssl routines',
        'certificate',
        'x509',
        'self-signed',
        'unknown ca',
    ];

    private readonly Client $client;
    private readonly string $baseUrl;
    private readonly string $token;
    private readonly string $verify;
    private readonly int $timeout;

    /**
     * Handler Guzzle yang disuntikkan uji (nullable). Disimpan agar
     * {@see self::openStream()} memakai transport yang sama — tanpa ini jalur
     * stream tidak bisa diuji tanpa jaringan.
     *
     * @var Closure|null
     */
    private readonly ?Closure $handler;

    /**
     * @param array<string,mixed>  $server  dokumen server: `base_url` (https, tanpa prefix
     *                                      path), `token` (alias: `token_ref`), `tls_cert_pem`, `id`
     * @param callable|null        $handler handler Guzzle (uji tanpa jaringan)
     * @param int                  $timeout timeout detik — di-cap ke {@see self::MAX_TIMEOUT}
     * @param TlsPinMaterializer|null $pins materializer cert (injectable untuk uji)
     * @throws AgentError invalid_config (base_url bukan https, token kosong, PEM hilang/rusak)
     */
    public function __construct(
        array $server,
        ?callable $handler = null,
        int $timeout = self::DEFAULT_TIMEOUT,
        ?TlsPinMaterializer $pins = null,
    ) {
        $baseUrl = trim((string) ($server['base_url'] ?? ''));
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        if ($baseUrl === '' || $scheme !== 'https' || $host === '') {
            throw AgentError::invalidConfig(
                'base_url server agent wajib absolut dan https:// (tidak ada fallback plain HTTP).'
            );
        }
        $this->baseUrl = rtrim($baseUrl, '/');

        $this->token = trim((string) ($server['token'] ?? $server['token_ref'] ?? ''));
        if ($this->token === '') {
            throw AgentError::invalidConfig('Token server agent kosong — panggilan ke agent ditolak (fail-fast).');
        }

        // Melempar invalid_config bila PEM tidak ada/rusak — jangan pernah lanjut
        // dengan verify=false.
        $this->verify = ($pins ?? new TlsPinMaterializer())->ensure($server);
        if (!is_file($this->verify)) {
            throw AgentError::invalidConfig("Berkas sertifikat TLS agent tidak ditemukan: {$this->verify}");
        }

        $this->timeout = max(1, min(self::MAX_TIMEOUT, $timeout));

        $this->handler = $handler === null ? null : Closure::fromCallable($handler);

        $this->client = new Client([
            'handler' => HandlerStack::create($this->handler ?? new CurlHandler()),
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'connect_timeout' => min(self::CONNECT_TIMEOUT, $this->timeout),
            'verify' => $this->verify,
            'http_errors' => false,
            'allow_redirects' => false,
            'expect' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
        ]);
    }

    /** Base URL efektif (tanpa `/` di ujung). */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Timeout efektif (setelah di-cap 30 s). */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /** Path berkas sertifikat yang dipakai sebagai trust anchor. */
    public function verifyPath(): string
    {
        return $this->verify;
    }

    /**
     * String kanonik tanda tangan — **kontrak lintas-misi** dengan
     * `AgentAuthMiddleware` (sisi agent). Jangan diubah tanpa mengubah keduanya.
     */
    public static function canonical(string $timestamp, string $method, string $path, string $body): string
    {
        return self::canonicalHash($timestamp, $method, $path, hash('sha256', $body));
    }

    /**
     * String kanonik dari digest body yang **sudah** dihitung — satu sumber
     * dengan {@see self::canonical()} (jangan diubah tanpa mengubah middleware
     * agent).
     */
    private static function canonicalHash(string $timestamp, string $method, string $path, string $bodySha256): string
    {
        return $timestamp . "\n" . strtoupper($method) . "\n" . $path . "\n" . $bodySha256;
    }

    /** `GET /healthz` — laporan kesehatan agent. */
    public function health(): array
    {
        return $this->get('/healthz');
    }

    /** `GET /version` — versi agent + capabilities (disimpan per server). */
    public function version(): array
    {
        return $this->get('/version');
    }

    /**
     * `POST /jobs` — antre operasi panjang di agent.
     *
     * Body yang dikirim = `payload` **digabung datar** dengan `op`
     * (`{op, app, ref?}` — plan §11 catatan 6); `op` selalu menang atas payload.
     *
     * @param array<string,mixed> $payload metadata operasi (mis. `app`, `ref`)
     * @return string job id untuk polling `GET /jobs/{id}`
     * @throws AgentError
     */
    public function postJob(string $op, array $payload): string
    {
        $op = trim($op);
        if ($op === '') {
            throw AgentError::invalidConfig('op job kosong.');
        }

        $body = $payload;
        $body['op'] = $op;

        $data = $this->post('/jobs', $body);
        $id = trim((string) ($data['job_id'] ?? $data['data']['job_id'] ?? ''));
        if (preg_match(self::JOB_ID_PATTERN, $id) !== 1) {
            throw AgentError::badResponse('Agent tidak mengembalikan job_id yang valid.');
        }

        return $id;
    }

    /**
     * `GET /jobs/{id}` — status job (pending|running|done|error + stage/message).
     *
     * @throws AgentError
     */
    public function jobStatus(string $id): array
    {
        return $this->get('/jobs/' . $this->jobId($id));
    }

    /**
     * `GET /jobs/{id}/log?offset=N` — potongan log mentah (bukan JSON).
     *
     * Query `offset` **tidak** ikut ditandatangani (hanya path).
     *
     * @throws AgentError
     */
    public function jobLog(string $id, int $offset = 0): string
    {
        $path = '/jobs/' . $this->jobId($id) . '/log';
        $result = $this->send('GET', $path, null, ['offset' => max(0, $offset)]);

        return $result['body'];
    }

    /**
     * `GET {path}` → array hasil JSON.
     *
     * @throws AgentError
     */
    public function get(string $path): array
    {
        return $this->decode($this->send('GET', $path)['body']);
    }

    /**
     * `GET {path}?{query}` → array hasil JSON.
     *
     * Query **tidak** ikut ditandatangani (kontrak signature = path saja, sama
     * seperti {@see self::jobLog()}), jadi pemanggil boleh menaruh parameter di
     * sini tanpa menyentuh kanonik HMAC.
     *
     * @param array<string,mixed> $query
     * @throws AgentError
     */
    public function getWithQuery(string $path, array $query): array
    {
        return $this->decode($this->send('GET', $path, null, $query)['body']);
    }

    /**
     * `GET {path}?{query}` → respons **mentah** (body + header), untuk endpoint
     * yang isinya bukan JSON — mis. unduhan potongan berkas `.sql` hasil
     * `db_dump` server agent (`GET /backups/dump`).
     *
     * Satu panggilan tetap dibatasi `timeout` klien (≤30 s, aturan repo) dan
     * pemanggil wajib membatasi ukuran lewat query (offset + limit), sehingga
     * tidak ada respons tanpa batas.
     *
     * @param array<string,mixed> $query query string (tidak ditandatangani)
     * @return array{status:int,body:string,headers:array<string,mixed>}
     * @throws AgentError transport/HTTP non-2xx
     */
    public function getRaw(string $path, array $query = []): array
    {
        return $this->send('GET', $path, null, $query);
    }

    /**
     * Buka **respons streaming** dari agent (mis. SSE terminal) — aditif, jalur
     * pendek di atas tetap memakai cURL handler & timeout ≤30 s.
     *
     * Perbedaan yang disengaja dibanding {@see self::get()}/{@see self::post()}:
     *  - hanya **fase koneksi + header** yang dibatasi `timeout` (≤30 s, aturan
     *    repo) — body-nya sendiri boleh panjang (stream terminal hidup sampai
     *    sesi berakhir) dan **tidak** diberi timeout buatan;
     *  - body **tidak** di-buffer di memori: pembaca yang dikembalikan membaca
     *    socket non-blocking, jadi pemanggil (controller) memompa lewat
     *    `Workerman\Timer` tanpa memblokir worker lain.
     *
     * @param array<string,mixed> $query query string (tidak ditandatangani)
     * @return array{status:int,headers:array<string,string>,read:callable():?string,close:callable():void,error:?array<string,mixed>}
     *         `read()` → `''` bila belum ada data (non-blocking), potongan body
     *         bila ada, `null` bila stream selesai/putus (`feof`/error baca).
     *         `close()` menutup socket upstream saja — **sesi di agent TIDAK
     *         ditutup** (aturan repo: koneksi drop ≠ sesi berakhir).
     *         `error` selalu `null` di jalur ini (lihat
     *         {@see self::openStreamEnvelope()} untuk varian yang menerima galat
     *         berstatus HTTP).
     * @throws AgentError transport/HTTP non-2xx (termasuk 404 SSE `gone` agent)
     */
    public function openStream(string $path, array $query = []): array
    {
        return $this->openStreamCore($path, $query, false);
    }

    /**
     * Varian {@see self::openStream()} yang **tidak** melempar untuk status HTTP
     * non-2xx: body galat agent (JSON pendek, mis. `{code,msg}`) dibaca habis dan
     * dikembalikan di kunci `error`, sehingga pemanggil dapat memetakannya
     * menjadi kode domain UI yang benar (jalur unduhan berkas: container berhenti
     * 409, berkas terlalu besar 413, berkas hilang 404) **tanpa** pernah
     * mengalirkan body galat sebagai isi berkas.
     *
     * Socket upstream selalu sudah ditutup saat `error !== null`.
     *
     * @param array<string,mixed> $query query string (tidak ditandatangani)
     * @return array{status:int,headers:array<string,string>,read:callable():?string,close:callable():void,error:?array<string,mixed>}
     * @throws AgentError transport (agent tidak bisa dihubungi / respons bukan streaming)
     */
    public function openStreamEnvelope(string $path, array $query = []): array
    {
        return $this->openStreamCore($path, $query, true);
    }

    /**
     * Inti kedua varian streaming di atas — satu implementasi koneksi, header
     * bertanda tangan, pembacaan non-blocking, dan penanganan status.
     *
     * @param array<string,mixed> $query
     * @return array{status:int,headers:array<string,string>,read:callable():?string,close:callable():void,error:?array<string,mixed>}
     * @throws AgentError
     */
    private function openStreamCore(string $path, array $query, bool $allowHttpErrors): array
    {
        $path = $this->normalizePath($path);
        $url = $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        // `stream => true` + `StreamHandler`: body = resource socket yang bisa
        // dibaca non-blocking. cURL handler tidak menyediakan pembacaan bertahap
        // (ia menunggu transfer selesai), jadi jalur ini memakai PHP stream.
        $client = new Client([
            'handler' => HandlerStack::create($this->handler ?? new StreamHandler()),
            'base_uri' => $this->baseUrl,
            'http_errors' => false,
            'allow_redirects' => false,
            'expect' => false,
        ]);

        try {
            $response = $client->request('GET', $url, [
                'headers' => $this->signedHeaders('GET', $path, '', ['Accept' => self::ACCEPT_STREAM]),
                'stream' => true,
                // Hanya fase koneksi/header; body tidak dibatasi (lihat docblock).
                'timeout' => $this->timeout,
                'verify' => $this->verify,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $e) {
            throw $this->transportError($e);
        }

        $status = (int) $response->getStatusCode();
        $headers = $response->getHeaders();
        if ($status < 200 || $status >= 300) {
            if (!$allowHttpErrors) {
                throw $this->httpError($status, (string) $response->getBody(), $response->getHeaderLine(AgentAuthMiddleware::HEADER_SERVER_TIME));
            }

            // Galat domain (JSON pendek) — dibaca habis di sini supaya tidak ada
            // byte galat yang lolos ke pemanggil sebagai isi berkas.
            $raw = (string) $response->getBody();

            return [
                'status' => $status,
                'headers' => $headers,
                'read' => static fn (): ?string => null,
                'close' => static function (): void {
                },
                'error' => $this->errorEnvelope($status, $raw),
            ];
        }

        $resource = $response->getBody()->detach();
        if (!is_resource($resource)) {
            throw AgentError::badResponse(
                'Respons streaming agent tidak bisa dibaca bertahap (body bukan resource).'
            );
        }
        // Non-blocking: `fread()` mengembalikan '' bila belum ada data, sehingga
        // worker Webman tetap melayani request lain di antara tick Timer.
        stream_set_blocking($resource, false);

        $finished = false;
        $read = static function () use ($resource, &$finished): ?string {
            if ($finished || !is_resource($resource)) {
                return null;
            }
            $chunk = @fread($resource, self::STREAM_READ_BYTES);
            if ($chunk === false) {
                $finished = true;

                return null;
            }
            if ($chunk === '') {
                if (@feof($resource)) {
                    $finished = true;

                    return null;
                }

                return '';
            }

            return $chunk;
        };
        $close = static function () use ($resource, &$finished): void {
            $finished = true;
            if (is_resource($resource)) {
                @fclose($resource);
            }
        };

        return [
            'status' => $status,
            'headers' => $headers,
            'read' => $read,
            'close' => $close,
            'error' => null,
        ];
    }

    /**
     * Envelope galat dari body non-2xx agent: JSON `{code,msg|error}` bila ada
     * (atau `[message]`), selain itu envelope minimal dari status + potongan
     * body. Nilainya sudah disanitasi (`AgentError::sanitize`) supaya token
     * server tidak pernah ikut ke UI.
     *
     * @return array<string,mixed>
     */
    private function errorEnvelope(int $status, string $raw): array
    {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            $message = '';
            foreach (['msg', 'error', 'message'] as $key) {
                $value = $decoded[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $message = trim($value);
                    break;
                }
            }

            return [
                'code' => (int) ($decoded['code'] ?? $status) ?: $status,
                'msg' => AgentError::sanitize($message, $this->token),
            ];
        }

        $detail = AgentError::sanitize($this->preview($raw), $this->token);

        return [
            'code' => $status,
            'msg' => $detail === '' ? "Agent menolak permintaan (HTTP {$status})." : $detail,
        ];
    }

    /**
     * `POST {path}` dengan body JSON → array hasil JSON.
     *
     * @param array<string,mixed> $payload
     * @throws AgentError
     */
    public function post(string $path, array $payload): array
    {
        return $this->decode($this->send('POST', $path, $payload)['body']);
    }

    /**
     * `POST {path}?{query}` dengan **isi berkas** sebagai body mentah → array
     * hasil JSON (aditif, misi F3b file manager per server).
     *
     * Dipakai untuk mengunggah berkas ke agent **tanpa memuat isinya ke memori
     * PHP**: body request = handle berkas (`fopen`) yang di-stream Guzzle/cURL
     * dari disk, dan tanda tangan `X-Rames-Signature` dihitung lewat
     * `hash_file()` (membaca berkas bertahap di disk) — sama nilainya dengan
     * `sha256(body)` yang diverifikasi `AgentAuthMiddleware` di sisi agent.
     * JSON dipakai untuk field kecil (`app_id`, `container`, `dir`, `name`) di
     * query string — query memang tidak ikut ditandatangani (kontrak signature =
     * path + body, sama seperti {@see self::jobLog()}).
     *
     * Berkas **wajib** ada dan terbaca; kegagalan membuka berkas adalah kesalahan
     * pemanggil (config), bukan kesalahan agent.
     *
     * @param string              $path     endpoint agent (mis. `/files/upload`)
     * @param string              $filePath berkas lokal control plane yang dikirim
     * @param array<string,mixed> $query    field kecil (URL-encoded, tidak ditandatangani)
     * @throws AgentError
     */
    public function postFile(string $path, string $filePath, array $query = []): array
    {
        $path = $this->normalizePath($path);
        if (!is_file($filePath)) {
            throw AgentError::invalidConfig('Berkas yang akan diunggah ke agent tidak ada: ' . $filePath);
        }
        $hash = @hash_file('sha256', $filePath);
        if (!is_string($hash) || $hash === '') {
            throw AgentError::invalidConfig('Berkas yang akan diunggah ke agent tidak bisa dibaca: ' . $filePath);
        }

        $stream = @fopen($filePath, 'rb');
        if ($stream === false) {
            throw AgentError::invalidConfig('Berkas yang akan diunggah ke agent tidak bisa dibuka: ' . $filePath);
        }

        $url = $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $response = $this->client->request('POST', $url, [
                'headers' => $this->signedHeadersForHash('POST', $path, $hash, [
                    'Content-Type' => 'application/octet-stream',
                ]),
                // Handle resource (bukan string) ⇒ body di-stream dari disk, bukan
                // disalin ke memori worker.
                'body' => $stream,
                'timeout' => $this->timeout,
                'connect_timeout' => min(self::CONNECT_TIMEOUT, $this->timeout),
                'verify' => $this->verify,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $e) {
            throw $this->transportError($e);
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw $this->httpError($status, $raw, $response->getHeaderLine(AgentAuthMiddleware::HEADER_SERVER_TIME));
        }

        return $this->decode($raw);
    }

    /**
     * Satu panggilan HTTP bertanda tangan dengan body **mentah** dan respons
     * ditulis bertahap ke berkas sementara (F3d: proxy Adminer per server).
     *
     * Kenapa jalur tersendiri (bukan `send()`/`postFile()`):
     *  - body request adalah **byte apa adanya** dari browser (multipart import
     *    Adminer) — tidak boleh di-decode/parse/di-encode ulang, dan
     *    `Content-Type`-nya harus tetap milik pemanggil (bukan `application/json`);
     *  - respons helper Adminer (HTML/halaman besar) ditulis bertahap ke berkas
     *    nyata lewat `LimitedTempSink` + {@see AdminerTempDir}, persis pola jalur
     *    lokal (`AdminerProxy::send()`), sehingga Workerman bisa mengirimkannya
     *    dengan `Response::withFile()` tanpa menahan byte di memori worker;
     *  - status HTTP non-2xx **tidak** melempar: pemanggil (controller) memetakan
     *    status/kode domain ke respons yang sama dengan jalur lokal. Galat
     *    transport/setup tetap `AgentError`.
     *
     * Timeout tetap ≤30 s (aturan repo) — helper dipanggil sebagai permintaan
     * pendek, bukan stream panjang.
     *
     * @param string               $query   query string **mentah** (tanpa `?`),
     *                                      diteruskan apa adanya supaya parameter
     *                                      Adminer tidak berubah bentuk; tidak
     *                                      ikut ditandatangani (kontrak = path)
     * @param array<string,string> $headers header tambahan (`Content-Type`,
     *                                      `X-Forwarded-Prefix`, …)
     * @return array{status:int,headers:array<string,array<int,string>>,body_file:string,size:int,too_large:bool}
     * @throws AgentError transport / berkas sementara tidak bisa dibuat
     */
    public function forwardToFile(
        string $method,
        string $path,
        ?string $body,
        string $query,
        array $headers,
        string $tempDir,
        int $maxBytes,
    ): array {
        $method = strtoupper(trim($method));
        $path = $this->normalizePath($path);
        self::assertQuery($query);

        $created = AdminerTempDir::create($tempDir);
        if ($created === null) {
            throw AgentError::invalidConfig(
                'Tidak bisa membuat berkas sementara untuk penerusan ke agent di "' . $tempDir . '".'
            );
        }
        [$file, $handle] = $created;
        $sink = new LimitedTempSink($handle, max(0, $maxBytes));

        $url = $path . ($query !== '' ? '?' . $query : '');
        $raw = $body ?? '';
        $options = [
            // `signedHeadersForHash()` — bukan `signedHeaders()` yang selalu
            // menulis `Content-Type: application/json` (akan merusak multipart).
            'headers' => $this->signedHeadersForHash($method, $path, hash('sha256', $raw), $headers),
            'stream' => true,
            'sink' => $sink,
            'http_errors' => false,
            'allow_redirects' => false,
            'decode_content' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => min(self::CONNECT_TIMEOUT, $this->timeout),
            'verify' => $this->verify,
            'expect' => false,
            // Tolak lebih awal bila `Content-Length` sudah melewati batas.
            'on_headers' => static function ($response) use ($maxBytes): void {
                $length = (int) $response->getHeaderLine('Content-Length');
                if ($maxBytes > 0 && $length > 0 && $length > $maxBytes) {
                    throw new AdminerProxyLimitExceeded(
                        'Respons server agent melebihi batas ' . $maxBytes . ' byte.'
                    );
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
            @unlink($file);
            if (self::isLimitExceeded($e)) {
                return ['status' => 0, 'headers' => [], 'body_file' => '', 'size' => 0, 'too_large' => true];
            }
            throw $this->transportError($e);
        }

        $sink->close();

        $size = @filesize($file);
        $size = $size === false ? 0 : (int) $size;
        if ($maxBytes > 0 && $size > $maxBytes) {
            @unlink($file);

            return ['status' => 0, 'headers' => [], 'body_file' => '', 'size' => 0, 'too_large' => true];
        }

        return [
            'status' => (int) $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body_file' => $file,
            'size' => $size,
            'too_large' => false,
        ];
    }

    /**
     * Rantai `query` yang diteruskan diterima apa adanya — hanya bentuk yang
     * jelas berbahaya (CR/LF/fragment) yang ditolak, supaya parameter Adminer
     * (`?username=&db=…`) tidak berubah makna.
     *
     * @throws AgentError
     */
    private static function assertQuery(string $query): void
    {
        if ($query === '') {
            return;
        }
        if (preg_match('/[\r\n\0#]/', $query) === 1) {
            throw AgentError::invalidConfig('Query penerusan ke agent memuat karakter yang tidak diizinkan.');
        }
    }

    /**
     * Apakah kegagalan berasal dari guard batas ukuran (langsung atau dibungkus).
     */
    private static function isLimitExceeded(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AdminerProxyLimitExceeded) {
                return true;
            }
        }

        return false;
    }

    /**
     * Satu panggilan HTTP bertanda tangan.
     *
     * @param array<string,mixed> $payload null = tanpa body (GET)
     * @param array<string,mixed> $query   query string (tidak ditandatangani)
     * @return array{status:int,body:string,headers:array<string,mixed>}
     * @throws AgentError
     */
    private function send(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);
        $body = $payload === null ? '' : $this->encode($payload);

        $url = $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $response = $this->client->request($method, $url, [
                'headers' => $this->signedHeaders($method, $path, $body),
                'body' => $body,
                'timeout' => $this->timeout,
                'connect_timeout' => min(self::CONNECT_TIMEOUT, $this->timeout),
                'verify' => $this->verify,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $e) {
            throw $this->transportError($e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw $this->httpError($status, $raw, $response->getHeaderLine(AgentAuthMiddleware::HEADER_SERVER_TIME));
        }

        return ['status' => $status, 'body' => $raw, 'headers' => $response->getHeaders()];
    }

    /**
     * Header bertanda tangan untuk satu panggilan (satu sumber: dipakai jalur
     * pendek `send()` maupun jalur streaming `openStream()`).
     *
     * @param array<string,string> $extra header tambahan (mis. `Accept`)
     * @return array<string,string>
     */
    private function signedHeaders(string $method, string $path, string $body, array $extra = []): array
    {
        // Body JSON → Content-Type json (jalur streaming/unggah menyetel
        // Content-Type sendiri lewat `$extra`).
        if ($body !== '') {
            $extra = ['Content-Type' => 'application/json'] + $extra;
        }

        return $this->signedHeadersForHash($method, $path, hash('sha256', $body), $extra);
    }

    /**
     * Header bertanda tangan bila digest body **sudah** dihitung — dipakai jalur
     * unggah berkas yang menghitungnya dengan `hash_file()` (streaming di disk,
     * tanpa menahan isi berkas di memori).
     *
     * @param string $bodySha256 sha256 heksadesimal body request
     * @param array<string,string> $extra header tambahan
     * @return array<string,string>
     */
    private function signedHeadersForHash(string $method, string $path, string $bodySha256, array $extra = []): array
    {
        $timestamp = (string) time();
        $canonical = self::canonicalHash($timestamp, $method, $path, $bodySha256);
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => bin2hex(random_bytes(self::NONCE_BYTES)),
            self::HEADER_SIGNATURE => hash_hmac('sha256', $canonical, $this->token),
        ];

        foreach ($extra as $name => $value) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw AgentError::badResponse('Payload job tidak bisa di-encode sebagai JSON.');
        }

        return $json;
    }

    /** @throws AgentError bad_response */
    private function decode(string $raw): array
    {
        if (trim($raw) === '') {
            throw AgentError::badResponse('Respons agent kosong (bukan JSON).');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw AgentError::badResponse('Respons agent bukan JSON yang valid: ' . $this->preview($raw));
        }

        return $decoded;
    }

    /** Path relatif yang ditandatangani: diawali `/`, tanpa query/fragment. */
    private function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        $path = substr($trimmed, 0, strcspn($trimmed, '?#'));
        if ($path === '') {
            throw AgentError::invalidConfig('Path endpoint agent kosong.');
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    private function jobId(string $id): string
    {
        $id = trim($id);
        if (preg_match(self::JOB_ID_PATTERN, $id) !== 1) {
            throw AgentError::invalidConfig('job id tidak valid.');
        }

        return $id;
    }

    /** @throws AgentError */
    private function transportError(\Throwable $e): AgentError
    {
        $message = AgentError::sanitize($e->getMessage(), $this->token);

        if ($this->looksLikeTlsFailure($message)) {
            return AgentError::tlsFailed("Verifikasi TLS agent ({$this->baseUrl}) gagal: {$message}");
        }

        return AgentError::unreachable("Agent tidak bisa dihubungi ({$this->baseUrl}): {$message}");
    }

    private function looksLikeTlsFailure(string $message): bool
    {
        $lower = strtolower($message);
        foreach (self::TLS_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return (bool) preg_match('/curl error (35|51|60|83|90|91|98)\b/', $lower);
    }

    /** @throws AgentError */
    private function httpError(int $status, string $raw, string $serverTime = ''): AgentError
    {
        // 401 + jam agent ⇒ penyebabnya selisih jam, bukan token.
        if ($status === 401 && preg_match('/^\d{1,12}$/', $serverTime) === 1) {
            $skew = time() - (int) $serverTime;

            return new AgentError(
                'Agent menolak request karena selisih jam ' . abs($skew) . ' detik dengan control plane '
                . '(toleransi ' . AgentAuthMiddleware::TIMESTAMP_WINDOW . ' detik; jam control plane '
                . ($skew > 0 ? 'lebih maju' : 'tertinggal') . '). Sinkronkan jam (NTP) kedua server.',
                AgentError::UNAUTHORIZED
            );
        }

        $reason = match (true) {
            $status === 401, $status === 403 => AgentError::UNAUTHORIZED,
            $status === 404 => AgentError::NOT_FOUND,
            $status === 501 => AgentError::UNSUPPORTED,
            $status >= 500 => AgentError::SERVER_ERROR,
            default => AgentError::BAD_RESPONSE,
        };

        $label = match ($reason) {
            AgentError::UNAUTHORIZED => "Agent menolak token (HTTP {$status})",
            AgentError::NOT_FOUND => "Endpoint/job tidak ada di agent (HTTP {$status})",
            AgentError::UNSUPPORTED => "Operasi tidak didukung agent (HTTP {$status})",
            AgentError::SERVER_ERROR => "Agent gagal memproses permintaan (HTTP {$status})",
            default => "Respons agent tidak sesuai kontrak (HTTP {$status})",
        };

        $detail = $this->errorDetail($raw);

        return new AgentError($detail === '' ? $label : $label . ': ' . $detail, $reason);
    }

    /**
     * Detail galat dari body agent (bila ada), **setelah** disanitasi token.
     * Body HTML/plain yang bukan JSON diambil sepotong saja.
     */
    private function errorDetail(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $detail = '';
            foreach (['error', 'message', 'detail'] as $key) {
                $value = $decoded[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $detail = trim($value);
                    break;
                }
                if (is_array($value) && is_string($value['message'] ?? null) && trim($value['message']) !== '') {
                    $detail = trim($value['message']);
                    break;
                }
            }

            return AgentError::sanitize($detail, $this->token);
        }

        return AgentError::sanitize($this->preview($raw), $this->token);
    }

    /** Potongan awal body untuk pesan galat (dibatasi sebelum sanitasi). */
    private function preview(string $raw): string
    {
        return strlen($raw) > 1000 ? substr($raw, 0, 1000) : $raw;
    }
}
