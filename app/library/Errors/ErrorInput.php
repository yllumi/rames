<?php
declare(strict_types=1);

namespace app\library\Errors;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Validasi + normalisasi payload laporan error dari klien ("Rekam Error").
 *
 * Kelas ini **statik murni, tanpa I/O**: menerima `request->post()` (JSON
 * maupun form; Webman mem-parse body `application/json` ke `post()`), lalu
 * mengembalikan array dengan kunci persis seperti kontrak (pemanggil memakai
 * `extract()`), atau melempar {@see InvalidArgumentException} dengan pesan
 * pendek yang **tidak pernah** menggemakan nilai input (bisa jadi rahasia).
 *
 * Semua kunci asing — termasuk `_token` CSRF — dibuang: hasil dibangun dari
 * daftar putih, bukan dengan menyalin input.
 *
 * Batas yang ditegakkan (urutan pemeriksaan):
 *  1. ukuran total payload (`json_encode($post)`) ≤ `$maxBytes`,
 *  2. `kind` wajib `client`,
 *  3. `message` string tidak kosong, ≤ `$maxMessageChars` karakter,
 *  4. `stack` ≤ `$maxStackBytes` byte, `source`/`route` ≤ 512 byte
 *     (`route` wajib diawali `/`),
 *  5. `app_id` (bila diisi) cocok `^[A-Za-z0-9_-]{1,128}$`, selain itu ambil
 *     dari route `#^/apps/([A-Za-z0-9_-]{1,128})(?:/|$)#`,
 *  6. `line`/`column` int 1..1.000.000 (null bila tidak ada),
 *  7. `occurred_at` ISO8601 valid ⇒ `date('c')`, selain itu **null** (bukan
 *     error). Zona waktu dipertahankan apa adanya — clamp waktu urusan
 *     Backend.
 *
 * Redaksi memakai {@see ErrorRedactor} untuk `message`, `stack`, `source`,
 * `route`, `context`, dan `user_agent`. `user_agent` diambil dari argumen
 * (bukan payload) karena klien tidak boleh memalsukan jejak browser.
 */
final class ErrorInput
{
    /** Satu-satunya jenis laporan yang diterima saat ini. */
    public const KIND_CLIENT = 'client';

    /** Batas panjang `source`/`route` (byte). */
    public const MAX_SOURCE_BYTES = 512;

    /** Batas panjang `user_agent` (byte). */
    public const MAX_USER_AGENT_BYTES = 512;

    /** Batas nilai `line`/`column`. */
    public const MAX_POSITION = 1_000_000;

    private const APP_ID_PATTERN = '/\A[A-Za-z0-9_-]{1,128}\z/';

    private const ROUTE_APP_PATTERN = '#\A/apps/([A-Za-z0-9_-]{1,128})(?:/|\z)#';

    /** ISO8601 dengan komponen tanggal/jam lengkap dan zona waktu opsional. */
    private const ISO8601_PATTERN = '/\A(?<y>\d{4})-(?<mo>\d{2})-(?<d>\d{2})[T ]'
        . '(?<h>\d{2}):(?<i>\d{2})(?::(?<s>\d{2})(?:\.\d{1,9})?)?'
        . '(?<tz>Z|[+-]\d{2}:?\d{2})?\z/i';

    /**
     * @param array<string,mixed> $post isi `request->post()` (JSON/form)
     * @return array{
     *     kind:string,
     *     message:string,
     *     stack:string,
     *     source:string,
     *     line:int|null,
     *     column:int|null,
     *     route:string,
     *     app_id:string|null,
     *     occurred_at:string|null,
     *     context:array<string,mixed>,
     *     user_agent:string
     * }
     *
     * @throws InvalidArgumentException pesan galat AMAN (tanpa nilai input)
     */
    public static function clientPayload(
        array $post,
        string $userAgent,
        int $maxBytes = 16384,
        int $maxMessageChars = 2000,
        int $maxStackBytes = 8192
    ): array {
        // 1. Plafon total dulu — laporan raksasa tidak perlu diparse lebih jauh.
        if (self::encodedSize($post) > max(0, $maxBytes)) {
            throw new InvalidArgumentException('Laporan error terlalu besar.');
        }

        // 2. Jenis laporan.
        $kind = $post['kind'] ?? null;
        if (!is_string($kind) || strtolower(trim($kind)) !== self::KIND_CLIENT) {
            throw new InvalidArgumentException('Jenis laporan error tidak didukung.');
        }

        // 3. Pesan.
        $message = $post['message'] ?? null;
        if (!is_string($message)) {
            throw new InvalidArgumentException('Pesan error tidak valid.');
        }
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Pesan error kosong.');
        }
        if (self::charLength($message) > max(1, $maxMessageChars)) {
            throw new InvalidArgumentException('Pesan error terlalu panjang.');
        }

        // 4. Stack, sumber, route.
        $stack = self::optionalString($post, 'stack', max(0, $maxStackBytes), 'Stack trace terlalu panjang.');
        $source = self::optionalString($post, 'source', self::MAX_SOURCE_BYTES, 'Sumber error tidak valid.');
        $rawRoute = self::optionalString($post, 'route', self::MAX_SOURCE_BYTES, 'Route error tidak valid.');
        if ($rawRoute !== '' && $rawRoute[0] !== '/') {
            throw new InvalidArgumentException('Route error tidak valid.');
        }
        $route = ErrorRedactor::routePath($rawRoute);

        // 5. app_id: dari input hanya bila polanya sah; selain itu dari route.
        $appId = self::optionalString($post, 'app_id', 0, 'ID aplikasi tidak valid.');
        if ($appId === '') {
            $appId = self::appIdFromRoute($route);
        } elseif (preg_match(self::APP_ID_PATTERN, $appId) !== 1) {
            throw new InvalidArgumentException('ID aplikasi tidak valid.');
        }

        // 6. Posisi sumber (opsional).
        $line = self::optionalPosition($post, 'line');
        $column = self::optionalPosition($post, 'column');

        // 7. Waktu kejadian (opsional; format tak sah ⇒ null).
        $occurredAt = self::occurredAt($post['occurred_at'] ?? null);

        // 8. Konteks terstruktur (tipe salah ⇒ diabaikan, bukan error).
        $rawContext = $post['context'] ?? [];
        $context = is_array($rawContext) ? ErrorRedactor::redactContext($rawContext) : [];

        // Batas redaksi mengikuti batas validasi supaya pesan/stack yang sah
        // tidak dipotong lebih pendek oleh pemanggilan redaksi.
        $messageBudget = max(ErrorRedactor::DEFAULT_MAX_BYTES, max(1, $maxMessageChars) * 4);
        $stackBudget = $maxStackBytes > 0 ? $maxStackBytes : ErrorRedactor::DEFAULT_MAX_BYTES;

        // Kunci hasil PERSIS kontrak; `_token` & kunci asing tidak pernah masuk.
        return [
            'kind' => self::KIND_CLIENT,
            'message' => ErrorRedactor::redact($message, $messageBudget),
            'stack' => ErrorRedactor::redact($stack, $stackBudget),
            'source' => ErrorRedactor::redact($source, self::MAX_SOURCE_BYTES),
            'line' => $line,
            'column' => $column,
            'route' => $route,
            'app_id' => $appId,
            'occurred_at' => $occurredAt,
            'context' => $context,
            'user_agent' => self::userAgent($userAgent),
        ];
    }

    /**
     * Ambil string opsional; tipe salah / terlalu panjang ⇒ exception aman.
     *
     * @param array<string,mixed> $post
     */
    private static function optionalString(array $post, string $key, int $maxBytes, string $error): string
    {
        $value = $post[$key] ?? null;
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($error);
        }
        if ($maxBytes > 0 && strlen($value) > $maxBytes) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    /**
     * Posisi int 1..1.000.000. Angka dari form datang sebagai string digit,
     * karenanya digit-only diterima lalu di-cast; selain itu ditolak.
     *
     * @param array<string,mixed> $post
     */
    private static function optionalPosition(array $post, string $key): ?int
    {
        $value = $post[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $position = $value;
        } elseif (is_string($value) && preg_match('/\A\d{1,7}\z/', trim($value)) === 1) {
            $position = (int) trim($value);
        } else {
            throw new InvalidArgumentException('Posisi error tidak valid.');
        }

        if ($position < 1 || $position > self::MAX_POSITION) {
            throw new InvalidArgumentException('Posisi error tidak valid.');
        }

        return $position;
    }

    /**
     * `occurred_at` valid ⇒ `date('c')`; selain itu null.
     *
     * Validasi tanggal dilakukan manual (`checkdate`) karena
     * `new DateTimeImmutable('2026-02-30')` **menormalkan** tanggal, bukan
     * melempar — waktu tidak boleh bergeser diam-diam di sini.
     */
    private static function occurredAt(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || preg_match(self::ISO8601_PATTERN, $value, $m) !== 1) {
            return null;
        }

        if (!checkdate((int) $m['mo'], (int) $m['d'], (int) $m['y'])) {
            return null;
        }
        if ((int) $m['h'] > 23 || (int) $m['i'] > 59 || (int) ($m['s'] ?? 0) > 59) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('c');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `app_id` dari path `/apps/{id}` (route sudah dibersihkan dari query).
     */
    private static function appIdFromRoute(string $route): ?string
    {
        if ($route === '') {
            return null;
        }
        if (preg_match(self::ROUTE_APP_PATTERN, $route, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * User agent dari header, di-redaksi (bisa memuat token di query aplikasi
     * pembungkus) lalu dipotong ke 512 byte tanpa merusak UTF-8.
     */
    private static function userAgent(string $userAgent): string
    {
        $clean = ErrorRedactor::redact($userAgent, self::MAX_USER_AGENT_BYTES);
        if (strlen($clean) <= self::MAX_USER_AGENT_BYTES) {
            return $clean;
        }

        return function_exists('mb_strcut')
            ? (string) mb_strcut($clean, 0, self::MAX_USER_AGENT_BYTES, 'UTF-8')
            : substr($clean, 0, self::MAX_USER_AGENT_BYTES);
    }

    /**
     * Ukuran payload mentah (byte JSON). `PHP_INT_MAX` bila tak bisa diukur —
     * memperlakukan input aneh sebagai terlalu besar (fail-closed).
     *
     * @param array<string,mixed> $post
     */
    private static function encodedSize(array $post): int
    {
        $json = json_encode(
            $post,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return is_string($json) ? strlen($json) : PHP_INT_MAX;
    }

    /**
     * Panjang karakter (bukan byte) untuk batas `message`.
     */
    private static function charLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
