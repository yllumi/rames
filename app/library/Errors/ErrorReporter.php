<?php
declare(strict_types=1);

namespace app\library\Errors;

use app\library\Auth\AppAccess;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use InvalidArgumentException;
use Throwable;
use Webman\Exception\BusinessException;
use Webman\Http\Request;

/**
 * Layanan pelaporan error ("Rekam Error") — satu pintu bagi laporan klien
 * (`POST /api/errors`) dan exception server 5xx (`app\exception\Handler`).
 *
 * Dua jalur dengan kebijakan berbeda yang sengaja:
 *
 *  - {@see self::reportClient()} — **authenticated only** + rate limit per user;
 *    payload divalidasi {@see ErrorInput}, lalu disimpan lewat
 *    {@see ErrorStore}. Hasil dikembalikan sebagai alasan terstruktur agar
 *    controller bisa memetakan status HTTP (401/422/429/200).
 *  - {@see self::reportServer()} — **best-effort, tidak pernah melempar**.
 *    Dilewati untuk mode agent (control plane saja) dan `BusinessException`
 *    (mis. `AppAccessDenied` — 404 yang disengaja bukan error server).
 *
 * Tidak ada data mentah yang dicatat: IP tidak pernah dibaca, pesan/stack
 * di-redaksi {@see ErrorRedactor}, dan payload klien tidak pernah digemakan ke
 * pesan galat.
 */
final class ErrorReporter
{
    /** Jendela clamp waktu kejadian dari klien (detik). */
    private const PAST_WINDOW_SECONDS = 600;

    /** Toleransi jam klien di masa depan (detik) — simetris dengan masa lalu. */
    private const FUTURE_WINDOW_SECONDS = 600;

    /** Batas panjang user-agent yang disimpan (byte). */
    private const MAX_USER_AGENT_BYTES = 512;

    /** Batas panjang pesan/stack error server (byte). */
    private const MAX_SERVER_MESSAGE_BYTES = 2000;

    private const MAX_SERVER_STACK_BYTES = 8192;

    private readonly ?ErrorStore $store;
    private readonly ?ErrorRateLimiter $limiter;

    /**
     * @param ErrorStore|null       $store   seam uji; null = DB dari config
     * @param ErrorRateLimiter|null $limiter seam uji; null = bucket `kv`
     */
    public function __construct(?ErrorStore $store = null, ?ErrorRateLimiter $limiter = null)
    {
        $this->store = $store;
        $this->limiter = $limiter;
    }

    /**
     * Terima laporan error dari browser user yang sudah login.
     *
     * @param array<string,mixed> $post      `request->post()` (JSON/form)
     * @param string              $userAgent header User-Agent (bukan payload)
     * @param array<string,mixed>|null $user user login
     * @param string              $route     path request (sudah bebas query)
     * @return array{accepted:bool,reason:string,group_id:?string}
     */
    public function reportClient(array $post, string $userAgent, ?array $user, string $route): array
    {
        if ($user === null || !is_array($user)) {
            return ['accepted' => false, 'reason' => 'unauthenticated', 'group_id' => null];
        }

        $userId = (string) ($user['id'] ?? '');
        $limit = (int) config('errors.rate_per_minute', 20);
        if (!$this->rateLimiter()->allow($userId, $limit)) {
            return ['accepted' => false, 'reason' => 'rate_limited', 'group_id' => null];
        }

        try {
            $payload = ErrorInput::clientPayload(
                $post,
                $userAgent,
                (int) config('errors.max_payload_bytes', 16384),
                (int) config('errors.max_message_chars', 2000),
                (int) config('errors.max_stack_bytes', 8192)
            );
        } catch (InvalidArgumentException) {
            return ['accepted' => false, 'reason' => 'invalid', 'group_id' => null];
        }

        $source = (string) ($payload['source'] ?? '');
        $line = $payload['line'] ?? null;
        $column = $payload['column'] ?? null;

        // Halaman asal error ada di payload (`route`), bukan path endpoint
        // pelaporan itu sendiri (`/api/errors`). Nilainya sudah divalidasi
        // ErrorInput (wajib `/`, query dibuang) — dipakai lebih dulu agar
        // triage menunjuk halaman tempat error terjadi.
        $payloadRoute = (string) ($payload['route'] ?? '');
        $route = $payloadRoute !== '' ? $payloadRoute : ErrorRedactor::routePath($route);

        $location = $source;
        if ($source !== '' && is_int($line)) {
            $location .= ':' . $line;
            if (is_int($column)) {
                $location .= ':' . $column;
            }
        }
        if ($location === '') {
            $location = $route;
        }

        $event = [
            'kind' => 'client',
            'message' => (string) ($payload['message'] ?? ''),
            'stack' => (string) ($payload['stack'] ?? ''),
            'location' => $location,
            'route' => $route,
            'http_status' => null,
            'app_id' => $this->visibleAppId($payload['app_id'] ?? null, $user),
            'server_id' => ServerStore::LOCAL_ID,
            'user_id' => $userId !== '' ? $userId : null,
            'username' => self::usernameOf($user),
            'occurred_at' => self::clampOccurredAt($payload['occurred_at'] ?? null),
            'context' => is_array($payload['context'] ?? null) ? $payload['context'] : [],
            'user_agent' => (string) ($payload['user_agent'] ?? ''),
        ];

        $group = $this->store()->record(
            $event,
            ErrorFingerprint::client($event),
            (int) config('errors.max_samples_per_group', 20),
            (int) config('errors.retention_days', 30),
            (int) config('errors.max_events', 5000)
        );

        $groupId = isset($group['id']) && is_scalar($group['id']) ? (string) $group['id'] : '';

        return [
            'accepted' => true,
            'reason' => '',
            'group_id' => $groupId !== '' ? $groupId : null,
        ];
    }

    /**
     * Catat exception server 5xx. **Best-effort**: tidak pernah melempar dan
     * tidak menahan request (dipanggil sesudah `parent::report()`).
     */
    public function reportServer(Throwable $e, ?Request $request = null): void
    {
        try {
            // Control plane saja: agent tidak punya UI `/errors` dan database
            // error log bisa berbeda (plan multi-server).
            if ((string) config('deploy.mode', '') === 'agent') {
                return;
            }

            // BusinessException (termasuk AppAccessDenied → 404) bukan error server.
            if ($e instanceof BusinessException) {
                return;
            }

            $route = self::routeFrom($request);
            $fingerprint = ErrorFingerprint::server($e, $route);

            $limit = (int) config('errors.rate_per_minute', 20);
            if (!$this->rateLimiter()->allow('srv:' . substr($fingerprint, 0, 64), $limit)) {
                return;
            }

            $user = self::currentUserSafely();

            $event = [
                'kind' => 'server',
                'message' => ErrorRedactor::redact(
                    get_class($e) . ': ' . $e->getMessage(),
                    self::MAX_SERVER_MESSAGE_BYTES
                ),
                'stack' => ErrorRedactor::redact(self::serverStack($e), self::MAX_SERVER_STACK_BYTES),
                'location' => ErrorFingerprint::serverLocation($e),
                'route' => $route,
                'http_status' => 500,
                'app_id' => self::appIdFromRoute($route, $user),
                'server_id' => ServerStore::LOCAL_ID,
                'user_id' => is_array($user) && (string) ($user['id'] ?? '') !== ''
                    ? (string) $user['id']
                    : null,
                'username' => is_array($user) ? self::usernameOf($user) : null,
                'occurred_at' => date('c'),
                'context' => [],
                'user_agent' => self::userAgentFrom($request),
            ];

            $this->store()->record(
                $event,
                $fingerprint,
                (int) config('errors.max_samples_per_group', 20),
                (int) config('errors.retention_days', 30),
                (int) config('errors.max_events', 5000)
            );
        } catch (Throwable) {
            // Jalur diagnostik: kegagalan mencatat tidak boleh mengubah
            // respons error yang sedang dirender ke user.
        }
    }

    // ==================================================================
    // Internal
    // ==================================================================

    private function store(): ErrorStore
    {
        return $this->store ?? new ErrorStore();
    }

    private function rateLimiter(): ErrorRateLimiter
    {
        return $this->limiter ?? new ErrorRateLimiter();
    }

    /**
     * `app_id` hanya dipertahankan bila app ada **dan** user boleh melihatnya.
     * Admin selalu boleh (lewat `AppAccess`). App tak terlihat ⇒ `null` supaya
     * laporan tidak membocorkan keberadaan app milik user lain.
     */
    private function visibleAppId(mixed $appId, array $user): ?string
    {
        if (!is_string($appId) || $appId === '') {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $appId) !== 1) {
            return null;
        }

        try {
            $app = (new AppStore())->find($appId);
        } catch (Throwable) {
            return null;
        }

        return $app !== null && AppAccess::can('view', $app, $user) ? $appId : null;
    }

    /** Sama seperti {@see self::visibleAppId()} untuk exception server. */
    private static function appIdFromRoute(string $route, ?array $user): ?string
    {
        if ($user === null || preg_match('#\A/apps/([A-Za-z0-9_-]{1,128})(?:/|\z)#', $route, $m) !== 1) {
            return null;
        }

        try {
            $app = (new AppStore())->find($m[1]);
        } catch (Throwable) {
            return null;
        }

        return $app !== null && AppAccess::can('view', $app, $user) ? $m[1] : null;
    }

    /** Clamp waktu klien ke `now−10m .. now+10m`; di luar itu / tak sah ⇒ now. */
    private static function clampOccurredAt(mixed $value): string
    {
        $now = time();
        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false
                && $timestamp >= $now - self::PAST_WINDOW_SECONDS
                && $timestamp <= $now + self::FUTURE_WINDOW_SECONDS) {
                return date('c', $timestamp);
            }
        }

        return date('c', $now);
    }

    private static function usernameOf(array $user): ?string
    {
        $username = trim((string) ($user['username'] ?? ''));

        return $username === '' ? null : $username;
    }

    /** Stack tanpa base path absolut (path host tidak perlu tersimpan). */
    private static function serverStack(Throwable $e): string
    {
        try {
            $stack = $e->getTraceAsString();
            if (function_exists('base_path')) {
                $base = rtrim((string) base_path(), '/');
                if ($base !== '') {
                    $stack = str_replace($base . '/', '', $stack);
                    $stack = str_replace($base, '', $stack);
                }
            }

            return $stack;
        } catch (Throwable) {
            return '';
        }
    }

    /** Route dari request eksplisit atau request aktif; `''` bila tak tersedia. */
    private static function routeFrom(?Request $request): string
    {
        $path = '';
        if ($request !== null) {
            try {
                $path = (string) $request->path();
            } catch (Throwable) {
                $path = '';
            }
        }

        if ($path === '') {
            try {
                if (function_exists('request')) {
                    $current = request();
                    if (is_object($current) && method_exists($current, 'path')) {
                        $path = (string) $current->path();
                    }
                }
            } catch (Throwable) {
                $path = '';
            }
        }

        return ErrorRedactor::routePath($path);
    }

    /** User-agent dari header request (bukan payload), dipotong 512 byte. */
    private static function userAgentFrom(?Request $request): string
    {
        $userAgent = '';
        try {
            $target = $request;
            if ($target === null && function_exists('request')) {
                $current = request();
                $target = is_object($current) ? $current : null;
            }
            if ($target !== null && method_exists($target, 'header')) {
                $userAgent = (string) $target->header('user-agent', '');
            }
        } catch (Throwable) {
            $userAgent = '';
        }

        if (strlen($userAgent) <= self::MAX_USER_AGENT_BYTES) {
            return $userAgent;
        }

        return function_exists('mb_strcut')
            ? (string) mb_strcut($userAgent, 0, self::MAX_USER_AGENT_BYTES, 'UTF-8')
            : substr($userAgent, 0, self::MAX_USER_AGENT_BYTES);
    }

    private static function currentUserSafely(): ?array
    {
        try {
            if (!function_exists('current_user')) {
                return null;
            }
            $user = current_user();

            return is_array($user) ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }
}
