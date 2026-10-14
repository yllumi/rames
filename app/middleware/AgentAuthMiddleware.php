<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use app\library\Agent\NonceStore;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Autentikasi mesin-ke-mesin untuk server agent (mode `RAMES_MODE=agent`,
 * plan §4.1). Middleware **global** di cabang mode agent
 * (`config/middleware.php`) — jadi setiap route agent, tanpa pengecualian,
 * wajib lolos di sini; `/healthz` agent pun butuh token (tidak ada route
 * publik di agent, berbeda dengan dashboard).
 *
 * Yang diperiksa (urutan: murah & stateless dulu, cache nonce terakhir):
 *  1. `Authorization: Bearer <AGENT_TOKEN>` — dibandingkan `hash_equals()`
 *     (constant-time) dengan `config('deploy.agent_token')`. Token kosong /
 *     tidak dikonfigurasi ⇒ **selalu tolak** (fail-closed).
 *  2. `X-Rames-Timestamp` — unix seconds, toleransi ±60 s (tolak masa lalu
 *     maupun masa depan di luar jendela; membatasi usia replay & request basi).
 *  3. `X-Rames-Nonce` — string acak ≥16 karakter, sekali pakai. Cache lintas
 *     worker di `NonceStore` (`runtime/agent-nonces.json`, TTL 120 s).
 *  4. `X-Rames-Signature` (opsional) — bila header **ada**, wajib valid:
 *     HMAC-SHA256 atas `"{timestamp}\n{METHOD}\n{path}\n{sha256(body)}"`
 *     dengan key = token. Bila header tidak ada, baseline keamanan bersandar
 *     pada token + timestamp + nonce di kanal TLS ter-pin (keputusan §5).
 *
 * Kegagalan apa pun ⇒ **401 tanpa detail**: body-nya sama persis untuk semua
 * penyebab (tanpa token/nonce/path/alasan spesifik, tanpa log) supaya tidak ada
 * oracle yang membocorkan header mana yang salah. Route yang tidak ada tetap
 * 404 — middleware tidak pernah mengubahnya menjadi 401 karena ia hanya
 * berjalan pada route yang terdaftar.
 *
 * Mode `dashboard` ⇒ **no-op mutlak** (`$handler($request)` langsung):
 * dashboard punya skema auth sendiri (sesi + CSRF) dan perilakunya tidak boleh
 * tersentuh oleh middleware ini.
 *
 * Tanpa state lintas-request: token dibaca dari config per request, nonce
 * dilempar ke berkas, `$request`/`session()` tidak disentuh, dan tidak ada
 * properti yang menyimpan hasil request sebelumnya. Jam dapat di-inject lewat
 * konstruktor (`$now`) agar uji tidak bergantung `sleep()`.
 */
final class AgentAuthMiddleware implements MiddlewareInterface
{
    /** Toleransi jam (detik, dua arah) untuk `X-Rames-Timestamp`. */
    public const TIMESTAMP_WINDOW = 60;

    /** Panjang minimum nonce (entropi yang layak untuk sekali pakai). */
    public const NONCE_MIN_LENGTH = 16;

    /** Batas atas panjang nonce — menahan header absurd tanpa membatasi praktik wajar. */
    public const NONCE_MAX_LENGTH = 256;

    private readonly ?string $noncePath;

    /** @var Closure():int|null */
    private readonly ?Closure $clock;

    private readonly int $window;

    /**
     * @param string|null   $noncePath berkas cache nonce; default `runtime/agent-nonces.json`
     * @param callable|null $now       pengganti `time()` untuk uji: `fn (): int`
     * @param int|null      $window    jendela timestamp (uji/penyesuaian ops)
     */
    public function __construct(?string $noncePath = null, ?callable $now = null, ?int $window = null)
    {
        $this->noncePath = $noncePath;
        $this->clock = $now === null ? null : Closure::fromCallable($now);
        $this->window = $window === null ? self::TIMESTAMP_WINDOW : max(1, $window);
    }

    public function process(Request $request, callable $handler): Response
    {
        // Mode dashboard: middleware ini tidak punya urusan apa pun di sana.
        // Didaftarkan hanya di cabang agent, tetapi pemeriksaan ini menjaga
        // perilaku tetap benar bila suatu saat dipasang lebih luas (mis. uji).
        if (!$this->isAgentMode()) {
            return $handler($request);
        }

        // Fail-closed: tanpa token terkonfigurasi tidak ada request yang lolos.
        // (config/route.php sudah mendaftarkan 0 route dalam kondisi ini; ini
        // lapisan kedua supaya agent tetap tertutup walau ada route menyusul.)
        $token = (string) config('deploy.agent_token', '');
        if (trim($token) === '') {
            return $this->denied();
        }

        $presented = $this->bearerToken($request);
        if ($presented === null || !hash_equals($token, $presented)) {
            return $this->denied();
        }

        $timestamp = $this->timestamp($request);
        $now = $this->now();
        if ($timestamp === null || abs($now - $timestamp) > $this->window) {
            return $this->denied();
        }

        $nonce = $this->nonce($request);
        if ($nonce === null) {
            return $this->denied();
        }

        // Bila klien mengirim signature, ia WAJIB valid. Tidak ada mode
        // "signature opsional yang diabaikan" — header yang salah adalah
        // kegagalan, bukan isyarat untuk jatuh ke baseline.
        $signature = $request->header('x-rames-signature');
        if ($signature !== null) {
            if (!is_string($signature) || !$this->signatureValid($signature, $token, $timestamp, $request)) {
                return $this->denied();
            }
        }

        // Terakhir: pakai nonce (satu tulis atomik). Request yang gagal di atas
        // tidak membakar nonce, sehingga kesalahan klien tidak mengunci
        // request sah berikutnya dengan nonce yang sama.
        if (!$this->nonceStore()->consume($nonce, $now)) {
            return $this->denied();
        }

        return $handler($request);
    }

    /**
     * 401 generik: bentuk respons identik untuk semua penyebab kegagalan.
     * Tidak ada token, nonce, path, atau alasan spesifik di body.
     */
    private function denied(): Response
    {
        return json(['code' => 401, 'msg' => 'Unauthorized'])->withStatus(401);
    }

    private function isAgentMode(): bool
    {
        return (string) config('deploy.mode', 'dashboard') === 'agent';
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    /**
     * Token dari `Authorization: Bearer <token>` (skema case-insensitive).
     */
    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('authorization');
        if (!is_string($header)) {
            return null;
        }
        if (preg_match('/^Bearer[ \t]+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * `X-Rames-Timestamp` sebagai unix seconds (digit murni — bentuk kanonik
     * tunggal, sehingga string yang ditandatangani klien tidak ambigu).
     */
    private function timestamp(Request $request): ?int
    {
        $header = $request->header('x-rames-timestamp');
        if (!is_string($header)) {
            return null;
        }
        $header = trim($header);
        if ($header === '' || strlen($header) > 12 || !ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    /**
     * `X-Rames-Nonce`: string acak ≥16 karakter. Bentuknya tidak dibatasi lebih
     * jauh (bebas karakter printable) — yang penting panjang & sekali pakai.
     */
    private function nonce(Request $request): ?string
    {
        $header = $request->header('x-rames-nonce');
        if (!is_string($header)) {
            return null;
        }
        $nonce = trim($header);
        if (strlen($nonce) < self::NONCE_MIN_LENGTH || strlen($nonce) > self::NONCE_MAX_LENGTH) {
            return null;
        }

        return $nonce;
    }

    /**
     * HMAC-SHA256 atas string kanonik
     * `"{timestamp}\n{METHOD}\n{path}\n{sha256(rawBody)}"` dengan key = token.
     */
    private function signatureValid(string $provided, string $token, int $timestamp, Request $request): bool
    {
        $canonical = implode("\n", [
            (string) $timestamp,
            strtoupper($request->method()),
            $request->path(),
            hash('sha256', $request->rawBody()),
        ]);
        $expected = hash_hmac('sha256', $canonical, $token);

        return hash_equals($expected, strtolower(trim($provided)));
    }

    private function nonceStore(): NonceStore
    {
        return new NonceStore($this->noncePath ?? runtime_path('agent-nonces.json'));
    }
}
