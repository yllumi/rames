<?php
declare(strict_types=1);

namespace app\library\Billing;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;

/**
 * Klien Duitku Web API v2 (inquiry, cek status, katalog metode pembayaran).
 *
 * Pola HTTP mengikuti `AdminerProxy`: klien Guzzle dibangun dengan
 * `HandlerStack::create(new CurlHandler())`, `http_errors => false`, dan
 * `timeout`/`connect_timeout` di-set per-request dan **di-cap** (timeout ≤ 30 s,
 * connect ≤ 10 s). Klien **injectable** supaya tes berjalan tanpa jaringan.
 *
 * Tanpa state lintas-request: tidak ada properti statik. Cache katalog metode
 * disimpan di berkas (`<runtime_path>/duitku-methods.json`) dan dibaca ulang tiap
 * pemanggilan.
 *
 * Kredensial (`api_key`) hanya dibaca dari spec/config dan **tidak pernah**
 * dimasukkan ke pesan exception, log, atau payload yang dikirim ke Duitku.
 */
class DuitkuClient
{
    public const SANDBOX_BASE_URL = 'https://sandbox.duitku.com/webapi/api/merchant';
    public const PRODUCTION_BASE_URL = 'https://passport.duitku.com/webapi/api/merchant';

    /**
     * Kanal yang **wajib** dikecualikan meski ada di allowlist config — kanal ini
     * butuh data kartu/paylater/account-link (`customerDetail` dll.) yang memang
     * tidak pernah kita kirim.
     */
    public const HARD_EXCLUDE_METHODS = ['VC', 'DN', 'AT', 'T1', 'T2', 'T3', 'SL', 'OL'];

    private const PATH_INQUIRY = '/v2/inquiry';
    private const PATH_STATUS = '/transactionStatus';
    private const PATH_METHODS = '/paymentmethod/getpaymentmethod';

    /** Satu-satunya route yang menerima callback Duitku (`config/route.php`). */
    private const CALLBACK_PATH = '/payments/duitku/callback';
    /** Halaman status top-up yang menjadi tujuan `return_url` default. */
    private const RETURN_PATH = '/credits/topup/return';
    /** Sufiks host tunnel pengembangan ber-autentikasi (Dev Tunnels). */
    private const DEV_TUNNEL_SUFFIX = '.devtunnels.ms';

    private const CACHE_FILE = 'duitku-methods.json';

    private Client $client;

    private string $mode;
    private string $baseUrl;
    private string $merchantCode;
    private string $apiKey;
    private string $callbackUrl;
    private string $returnUrl;
    /**
     * Apakah `return_url` berasal dari config eksplisit (bukan hasil derive).
     * Set-only-per-instance; hanya dipakai `configurationWarnings()` untuk tahu
     * apakah jalur return URL layak diperingatkan. Bukan cache lintas-request.
     */
    private bool $returnUrlFromConfig = false;
    private int $timeout;
    private int $methodTtl;
    private string $runtimePath;
    private bool $allowHttp;

    /** @var array<int,string> kode kanal huruf besar yang diizinkan */
    private array $allowlist;

    /**
     * @param array<string,mixed>|null $spec `mode`, `base_url`, `merchant_code`,
     *        `api_key`, `callback_url`, `return_url`, `timeout`, `methods`,
     *        `method_ttl`, `runtime_path` — nilai yang absen diisi dari config.
     */
    public function __construct(?array $spec = null, ?Client $client = null)
    {
        $spec ??= [];
        $defaults = self::defaultSpec();
        $merged = array_merge($defaults, $spec);

        $mode = strtolower(trim((string) ($merged['mode'] ?? 'sandbox')));
        if ($mode !== 'production') {
            $mode = 'sandbox';
        }
        $this->mode = $mode;

        $baseUrl = trim((string) ($spec['base_url'] ?? ''));
        if ($baseUrl === '') {
            // Tanpa base_url eksplisit, mode (yang sudah mempertimbangkan spec)
            // menentukan endpoint. Penting: `$spec['mode']` harus menang atas config.
            $baseUrl = $mode === 'production' ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
        }
        $this->baseUrl = rtrim($baseUrl, '/');

        $this->merchantCode = trim((string) ($merged['merchant_code'] ?? ''));
        $this->apiKey = (string) ($merged['api_key'] ?? '');
        $this->callbackUrl = trim((string) ($merged['callback_url'] ?? ''));
        $this->returnUrl = trim((string) ($merged['return_url'] ?? ''));
        $this->returnUrlFromConfig = $this->returnUrl !== '';
        if ($this->returnUrl === '') {
            // `BILLING_DUITKU_RETURN_URL` kosong = turunkan dari callback URL
            // (origin dashboard + halaman status top-up). Tanpa ini, kredensial
            // yang lengkap selain return URL akan membuat top-up 404.
            $this->returnUrl = self::deriveReturnUrl($this->callbackUrl);
        }

        // Larangan repo #7: timeout ≤ 30 s. Nilai aneh dipaksa ke rentang aman.
        $timeout = (int) ($merged['timeout'] ?? 15);
        $this->timeout = max(1, min(30, $timeout));

        $this->methodTtl = max(0, (int) ($merged['method_ttl'] ?? 3600));
        $this->allowHttp = (bool) ($merged['allow_http'] ?? false);
        $this->runtimePath = rtrim((string) ($merged['runtime_path'] ?? ''), '/');
        $this->allowlist = self::parseMethods($merged['methods'] ?? '');

        $this->client = $client ?? new Client([
            'handler' => HandlerStack::create(new CurlHandler()),
            'timeout' => $this->timeout,
            'connect_timeout' => min(10, $this->timeout),
            'http_errors' => false,
            'allow_redirects' => false,
            'expect' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /**
     * Konfigurasi lengkap? Dipakai UI/gerbang untuk fail-safe (sembunyikan tombol
     * top-up bila false). Wajib: merchant code + API key, callback URL https
     * absolut, return URL absolut.
     */
    public function isConfigured(): bool
    {
        return $this->configurationIssues() === [];
    }

    /**
     * Daftar alasan konfigurasi Duitku **belum siap** (bahasa Indonesia, untuk
     * diagnostik admin di halaman `/credits` & laporan). Kosong = siap.
     *
     * @return array<int,string>
     */
    public function configurationIssues(): array
    {
        $issues = [];
        if ($this->merchantCode === '') {
            $issues[] = 'BILLING_DUITKU_MERCHANT_CODE belum diisi.';
        }
        if ($this->apiKey === '') {
            $issues[] = 'BILLING_DUITKU_API_KEY belum diisi.';
        }
        if ($this->callbackUrl === '') {
            $issues[] = 'BILLING_DUITKU_CALLBACK_URL belum diisi.';
        } elseif ($this->allowHttp ? !self::isAbsoluteUrl($this->callbackUrl) : !self::isHttpsUrl($this->callbackUrl)) {
            $issues[] = $this->allowHttp
                ? 'BILLING_DUITKU_CALLBACK_URL harus URL absolut (http/https).'
                : 'BILLING_DUITKU_CALLBACK_URL harus URL absolut https (untuk uji lokal: BILLING_DUITKU_ALLOW_HTTP=true).';
        }
        if (!self::isAbsoluteUrl($this->returnUrl)) {
            $issues[] = 'URL kembali tidak absolut — isi BILLING_DUITKU_RETURN_URL atau perbaiki callback URL agar origin-nya bisa diturunkan.';
        }

        return $issues;
    }

    /**
     * Peringatan konfigurasi **advisori** untuk diagnostik admin di `/credits`.
     *
     * Penting: method ini **tidak** memengaruhi `isConfigured()` /
     * `configurationIssues()` — top-up tetap boleh berjalan walau ada peringatan.
     * Yang dideteksi:
     *  - path callback bukan satu-satunya route penerima callback (mis. URL yang
     *    didaftarkan di dashboard Duitku berakhiran `/callback` ⇒ callback hilang);
     *  - host callback berupa tunnel pengembangan ber-autentikasi
     *    (`*.devtunnels.ms`) ⇒ Duitku menerima `401` dari tunnel, bukan aplikasi;
     *  - `return_url` eksplisit yang salah jalur ⇒ pengguna mendarat di 404.
     *
     * Urutan keluaran deterministik: path callback → tunnel → path return.
     * Tidak pernah menyertakan API key/kredensial. Getter `callbackUrl()` /
     * `returnUrl()` dipakai pemanggil untuk menampilkan URL (tanpa I/O).
     *
     * @return array<int,string>
     */
    public function configurationWarnings(): array
    {
        $warnings = [];

        if (parse_url($this->callbackUrl, PHP_URL_PATH) !== self::CALLBACK_PATH) {
            $warnings[] = 'Path URL callback bukan `/payments/duitku/callback` — satu-satunya route yang menerima callback. Perbaiki `BILLING_DUITKU_CALLBACK_URL` **dan** URL yang didaftarkan di dashboard Duitku.';
        }

        $host = parse_url($this->callbackUrl, PHP_URL_HOST);
        if (is_string($host) && str_ends_with(strtolower($host), self::DEV_TUNNEL_SUFFIX)) {
            $warnings[] = 'Host callback adalah tunnel pengembangan (`*.devtunnels.ms`) yang biasanya menuntut autentikasi; Duitku akan menerima `401` dan callback tidak pernah sampai. Jadikan akses tunnel **Public**, atau pakai URL publik tanpa autentikasi.';
        }

        if ($this->returnUrlFromConfig && parse_url($this->returnUrl, PHP_URL_PATH) !== self::RETURN_PATH) {
            $warnings[] = 'Path URL kembali bukan `/credits/topup/return`; setelah pembayaran pengguna akan mendarat di halaman 404. Kosongkan `BILLING_DUITKU_RETURN_URL` agar diturunkan otomatis.';
        }

        return $warnings;
    }

    /**
     * Apakah callback `http://` diizinkan (uji lokal, bukan produksi)?
     */
    public function allowsHttp(): bool
    {
        return $this->allowHttp;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function callbackUrl(): string
    {
        return $this->callbackUrl;
    }

    public function returnUrl(): string
    {
        return $this->returnUrl;
    }

    public function merchantCode(): string
    {
        return $this->merchantCode;
    }

    /**
     * API key HMAC. **Rahasia** — hanya untuk library internal (penandatanganan);
     * jangan pernah dikirim ke view/log.
     */
    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Apakah kode kanal diizinkan (allowlist config & bukan hard-exclude).
     */
    public function isAllowedMethod(string $code): bool
    {
        $code = strtoupper(trim($code));

        return in_array($code, $this->allowlist, true)
            && !in_array($code, self::HARD_EXCLUDE_METHODS, true);
    }

    /**
     * Buat tagihan (inquiry). `$payload` sudah lengkap dibangun `TopUpService`.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed> respons sukses Duitku
     * @throws DuitkuError bila HTTP non-2xx / `statusCode !== '00'`
     */
    public function inquiry(array $payload): array
    {
        if (!$this->isConfigured()) {
            throw new DuitkuError('Payment gateway belum dikonfigurasi lengkap.');
        }

        $response = $this->post(self::PATH_INQUIRY, $payload);

        if (($response['statusCode'] ?? '') !== '00') {
            $message = $this->sanitize('Duitku menolak pembuatan tagihan: ' . $this->duitkuMessage($response));
            throw new DuitkuError($message, 200, $this->sanitize(trim((string) ($response['statusMessage'] ?? ''))));
        }

        return $response;
    }

    /**
     * Cek status transaksi. Duitku membatasi hit-rate — pemanggil WAJIB menghormati
     * jeda (lihat `TopUpService::reconcile`).
     *
     * @return array<string,mixed> respons (`statusCode`, `reference`, `amount`, …)
     * @throws DuitkuError
     */
    public function transactionStatus(string $merchantOrderId): array
    {
        if (!$this->isConfigured()) {
            throw new DuitkuError('Payment gateway belum dikonfigurasi lengkap.');
        }

        $body = [
            'merchantCode' => $this->merchantCode,
            'merchantOrderId' => $merchantOrderId,
            'signature' => DuitkuSignature::status($this->merchantCode, $merchantOrderId, $this->apiKey),
        ];

        return $this->post(self::PATH_STATUS, $body);
    }

    /**
     * Katalog metode pembayaran (sudah disaring allowlist + hard-exclude).
     *
     * Urutan ketahanan: cache segar → panggilan API → cache kedaluwarsa → daftar
     * statis dari allowlist config. **Tidak pernah** melempar (UI tetap jalan);
     * mengembalikan `[]` hanya bila `isConfigured() === false`.
     *
     * @return array<int,array{code:string,name:string,image:string,fee:int}>
     */
    public function paymentMethods(int $amount): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $cache = $this->readCache();
        if ($cache !== null && $this->methodTtl > 0 && (time() - $cache['at']) < $this->methodTtl) {
            return $this->filterMethods($cache['methods']);
        }

        try {
            $fresh = $this->fetchMethods($amount);
            $this->writeCache($fresh);

            return $this->filterMethods($fresh);
        } catch (\Throwable) {
            if ($cache !== null) {
                // Pakai cache walau kedaluwarsa — lebih baik daripada daftar kosong.
                return $this->filterMethods($cache['methods']);
            }

            return $this->fallbackMethods();
        }
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function post(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;

        try {
            $response = $this->client->post($url, [
                'json' => $body,
                'timeout' => $this->timeout,
                'connect_timeout' => min(10, $this->timeout),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $e) {
            throw new DuitkuError(
                $this->sanitize('Gagal menghubungi Duitku: ' . $e->getMessage()),
                0,
                ''
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];
        $duitkuMessage = $this->sanitize(trim((string) ($data['statusMessage'] ?? $data['responseMessage'] ?? '')));

        if ($status < 200 || $status >= 300) {
            $message = $this->sanitize($this->friendlyHttpMessage($status));
            if ($duitkuMessage !== '') {
                $message .= ' ' . $this->sanitize($duitkuMessage);
            }
            throw new DuitkuError($message . ' (HTTP ' . $status . ')', $status, $duitkuMessage);
        }

        return $data;
    }

    /**
     * Ambil daftar mentah dari `getpaymentmethod`.
     *
     * @return array<int,array{code:string,name:string,image:string,fee:int}>
     * @throws DuitkuError
     */
    private function fetchMethods(int $amount): array
    {
        $datetime = DuitkuSignature::datetime();
        $body = [
            'merchantcode' => $this->merchantCode,
            'amount' => $amount,
            'datetime' => $datetime,
            'signature' => DuitkuSignature::methods($this->merchantCode, $amount, $datetime, $this->apiKey),
        ];

        $response = $this->post(self::PATH_METHODS, $body);

        $code = (string) ($response['responseCode'] ?? $response['statusCode'] ?? '');
        $fees = $response['paymentFee'] ?? null;
        if (!is_array($fees)) {
            throw new DuitkuError(
                $this->sanitize('Duitku tidak mengembalikan katalog metode pembayaran.'),
                200,
                $this->sanitize(trim((string) ($response['responseMessage'] ?? $response['statusMessage'] ?? '')))
            );
        }
        if ($code !== '' && $code !== '00') {
            throw new DuitkuError(
                $this->sanitize('Duitku menolak permintaan katalog metode: ' . $this->duitkuMessage($response)),
                200,
                $this->sanitize(trim((string) ($response['responseMessage'] ?? '')))
            );
        }

        $methods = [];
        foreach ($fees as $fee) {
            if (!is_array($fee)) {
                continue;
            }
            $methodCode = strtoupper(trim((string) ($fee['paymentMethod'] ?? '')));
            if ($methodCode === '') {
                continue;
            }
            $methods[] = [
                'code' => $methodCode,
                'name' => trim((string) ($fee['paymentName'] ?? $methodCode)),
                'image' => trim((string) ($fee['paymentImage'] ?? '')),
                'fee' => is_numeric($fee['totalFee'] ?? null) ? (int) round((float) $fee['totalFee']) : 0,
            ];
        }

        return $methods;
    }

    /**
     * Saring daftar terhadap allowlist + hard-exclude, dedupe per kode.
     *
     * @param array<int,array<string,mixed>> $methods
     * @return array<int,array{code:string,name:string,image:string,fee:int}>
     */
    private function filterMethods(array $methods): array
    {
        $out = [];
        $seen = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $code = strtoupper(trim((string) ($method['code'] ?? '')));
            if ($code === '' || isset($seen[$code]) || !$this->isAllowedMethod($code)) {
                continue;
            }
            $seen[$code] = true;
            $out[] = [
                'code' => $code,
                'name' => trim((string) ($method['name'] ?? '')) !== '' ? (string) $method['name'] : $code,
                'image' => (string) ($method['image'] ?? ''),
                'fee' => (int) ($method['fee'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Daftar statis dari allowlist config (nama = kode, image kosong).
     *
     * @return array<int,array{code:string,name:string,image:string,fee:int}>
     */
    private function fallbackMethods(): array
    {
        $out = [];
        foreach ($this->allowlist as $code) {
            if (in_array($code, self::HARD_EXCLUDE_METHODS, true)) {
                continue;
            }
            $out[] = ['code' => $code, 'name' => $code, 'image' => '', 'fee' => 0];
        }

        return $out;
    }

    /**
     * @return array{at:int,methods:array<int,array<string,mixed>>}|null
     */
    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['methods'] ?? null)) {
            return null;
        }

        return ['at' => (int) ($data['at'] ?? 0), 'methods' => array_values($data['methods'])];
    }

    /**
     * @param array<int,array<string,mixed>> $methods
     */
    private function writeCache(array $methods): void
    {
        $path = $this->cachePath();
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $encoded = json_encode(
            ['at' => time(), 'methods' => array_values($methods)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if ($encoded === false) {
            return;
        }
        @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX);
    }

    private function cachePath(): string
    {
        return $this->runtimePath === '' ? '' : $this->runtimePath . '/' . self::CACHE_FILE;
    }

    /**
     * @param array<string,mixed> $response
     */
    private function duitkuMessage(array $response): string
    {
        $message = trim((string) ($response['statusMessage'] ?? $response['responseMessage'] ?? ''));

        return $message !== '' ? $message : 'status tidak dikenal';
    }

    private function friendlyHttpMessage(int $status): string
    {
        return match ($status) {
            400 => 'Permintaan top-up ditolak Duitku: nominal/kanal tidak valid atau di luar batas.',
            401 => 'Kredensial payment gateway tidak valid (periksa merchant code & API key).',
            404 => 'Merchant atau metode pembayaran tidak ditemukan di Duitku.',
            409 => 'Nominal top-up tidak cocok dengan rincian tagihan.',
            429 => 'Terlalu banyak permintaan ke Duitku. Coba lagi nanti.',
            500, 501, 502, 503, 504 => 'Duitku sedang bermasalah. Coba lagi nanti.',
            default => 'Duitku menolak permintaan.',
        };
    }

    /**
     * Buang kemunculan API key dari pesan apa pun (sabuk pengaman higienitas).
     */
    private function sanitize(string $message): string
    {
        if ($this->apiKey !== '' && str_contains($message, $this->apiKey)) {
            $message = str_replace($this->apiKey, '[redacted]', $message);
        }

        return $message;
    }

    /**
     * @param mixed $methods string CSV atau array kode
     * @return array<int,string>
     */
    private static function parseMethods(mixed $methods): array
    {
        if (is_string($methods)) {
            $methods = explode(',', $methods);
        }
        if (!is_array($methods)) {
            return [];
        }

        $out = [];
        foreach ($methods as $method) {
            $code = strtoupper(trim((string) $method));
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaultSpec(): array
    {
        $mode = strtolower(trim((string) config('deploy.billing_duitku_mode', 'sandbox')));

        return [
            'mode' => $mode,
            'base_url' => $mode === 'production' ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL,
            'merchant_code' => (string) config('deploy.billing_duitku_merchant_code', ''),
            'api_key' => (string) config('deploy.billing_duitku_api_key', ''),
            'callback_url' => (string) config('deploy.billing_duitku_callback_url', ''),
            'return_url' => (string) config('deploy.billing_duitku_return_url', ''),
            'timeout' => (int) config('deploy.billing_duitku_timeout', 15),
            'methods' => (string) config('deploy.billing_duitku_methods', ''),
            'method_ttl' => (int) config('deploy.billing_duitku_method_ttl', 3600),
            'allow_http' => (bool) config('deploy.billing_duitku_allow_http', false),
            'runtime_path' => runtime_path('billing'),
        ];
    }

    private static function isAbsoluteUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true) && $parts['host'] !== '';
    }

    private static function isHttpsUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);

        return $parts !== false
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== '';
    }

    /**
     * URL kembali default (`/credits/topup/return`) dari origin callback URL.
     * Kosong bila callback URL tidak absolut http(s) — pemanggil lalu menganggap
     * konfigurasi belum lengkap.
     */
    private static function deriveReturnUrl(string $callbackUrl): string
    {
        $parts = parse_url($callbackUrl);
        if ($parts === false) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin . '/credits/topup/return';
    }
}
