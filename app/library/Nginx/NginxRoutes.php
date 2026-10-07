<?php
declare(strict_types=1);

namespace app\library\Nginx;

use RuntimeException;

/**
 * Rute proxy tambahan per app (LAPIS A) — data tersimpan di `apps.json`:
 *
 *   nginx_routes: [ { "path": "/api/", "target": "http://127.0.0.1:3001" } ]
 *
 * Setiap rute dirender `NginxConfigGenerator::render()` sebagai blok
 * `location ^~ {path} { proxy_pass {target}; ... }` di dalam serve block
 * (HTTP 80 & HTTPS 443) — bukan di redirect block / blok 80→https. Rute ini
 * disimpan sebagai struktur terstruktur (path + target), **bukan** snippet
 * mentah, supaya dashboard bisa memvalidasi & membatasi sebelum menulis config
 * Nginx (config rusak = nginx gagal reload untuk SELURUH host).
 *
 * Stateless (murni statik, tanpa properti) — aman untuk worker Webman persistent.
 */
final class NginxRoutes
{
    /** Batas jumlah rute proxy per app. */
    public const MAX = 20;

    /** Batas panjang `path` (karakter). */
    private const MAX_PATH_LENGTH = 200;

    /** Path rute yang diizinkan: absolut, tanpa spasi/query, tanpa `?`/`#`. */
    private const PATH_PATTERN = '#^/[A-Za-z0-9._~/-]*$#';

    /** Target rute: hanya http/https, host + opsional port + opsional path. */
    private const TARGET_PATTERN = '#^https?://[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?(?::\d{1,5})?(?:/[A-Za-z0-9._~/-]*)?$#';

    /**
     * Rute proxy milik app (dibaca dari `$app['nginx_routes']`).
     *
     * Toleran data rusak — absen atau bukan array → daftar kosong; **entri**
     * yang rusak (bukan array, `path`/`target` bukan teks, atau isi tidak lolos
     * aturan validasi) **hanya dilewati**, sedangkan rute valid lain tetap
     * dipakai. Duplikat `path` → kemunculan valid pertama dipertahankan.
     * Selalu tanpa throw, dan hasilnya tidak pernah melebihi {@see self::MAX}
     * (kelebihannya dilewati) supaya `render()` tidak pernah gagal karena data
     * `apps.json` yang sudah rusak/kelebihan.
     *
     * Satu entri rusak tidak boleh membuat seluruh rute hilang dari config
     * Nginx maupun UI (kehilangan data senyap).
     *
     * @param array $app
     * @return array<int,array{path:string,target:string}>
     */
    public static function all(array $app): array
    {
        $raw = $app['nginx_routes'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $routes = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (count($routes) >= self::MAX) {
                break;
            }
            if (!is_array($entry)) {
                continue;
            }
            $path = $entry['path'] ?? null;
            $target = $entry['target'] ?? null;
            if (!is_string($path) || !is_string($target)) {
                continue;
            }
            if (isset($seen[$path])) {
                continue; // duplikat: kemunculan valid pertama sudah dipakai
            }
            try {
                $normalized = self::normalize([['path' => $path, 'target' => $target]]);
            } catch (RuntimeException) {
                continue; // entri ini saja yang dilewati
            }
            $routes[] = $normalized[0];
            $seen[$path] = true;
        }

        return $routes;
    }

    /**
     * Parse isi <textarea> menjadi daftar rute.
     *
     * Format: satu rute per baris, `"<path> <target>"` dipisah spasi/tab.
     * Baris kosong & komentar (baris diawali `#`) diabaikan. Setiap kesalahan
     * (format baris maupun isi `path`/`target`) → RuntimeException yang menyebut
     * **nomor baris textarea** sebenarnya; aturan lintas-entri (duplikat `path`
     * & batas {@see self::MAX}) diperiksa sekali di akhir lewat normalize().
     *
     * @return array<int,array{path:string,target:string}>
     */
    public static function parse(string $text): array
    {
        $routes = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $index => $line) {
            $number = $index + 1;
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $parts = preg_split('/[ \t]+/', $trimmed) ?: [];
            if (count($parts) !== 2) {
                throw new RuntimeException(
                    "Baris {$number}: format rute tidak valid. " .
                    'Tulis satu rute per baris dengan format "<path> <target>", ' .
                    'mis. "/api/ http://127.0.0.1:3001".'
                );
            }
            try {
                $routes[] = self::normalize([['path' => $parts[0], 'target' => $parts[1]]])[0];
            } catch (RuntimeException $e) {
                throw new RuntimeException("Baris {$number}: " . $e->getMessage());
            }
        }

        // Aturan lintas-entri (duplikat path & batas jumlah) dicek sekali di akhir.
        return self::normalize($routes);
    }

    /**
     * Validasi penuh daftar rute proxy.
     *
     * Setiap kegagalan → RuntimeException berbahasa Indonesia yang menjelaskan
     * rute mana yang salah & cara memperbaikinya.
     *
     * @param mixed $raw
     * @return array<int,array{path:string,target:string}>
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new RuntimeException(
                'Data rute proxy tidak valid: harus berupa daftar rute. ' .
                'Gunakan format [{"path": "/api/", "target": "http://127.0.0.1:3001"}].'
            );
        }
        if (count($raw) > self::MAX) {
            throw new RuntimeException('Maksimal ' . self::MAX . ' rute proxy per app.');
        }

        $routes = [];
        $seen = [];
        $index = 0;
        foreach ($raw as $entry) {
            $index++;
            if (!is_array($entry)) {
                throw new RuntimeException(
                    "Rute proxy #{$index} tidak valid: setiap rute harus berupa objek " .
                    'dengan field "path" dan "target".'
                );
            }
            $path = $entry['path'] ?? null;
            $target = $entry['target'] ?? null;
            if (!is_string($path) || !is_string($target)) {
                throw new RuntimeException(
                    "Rute proxy #{$index} tidak valid: field \"path\" dan \"target\" wajib berupa teks."
                );
            }

            $path = self::validatePath($path, $index, $seen);
            $target = self::validateTarget($target, $index);

            $seen[$path] = true;
            $routes[] = ['path' => $path, 'target' => $target];
        }

        return $routes;
    }

    /**
     * Isi <textarea> untuk form edit: `"path target"` per baris dipisah "\n".
     *
     * Toleran: entri rusak (bukan array / path-target bukan teks) dilewati tanpa
     * throw agar form tetap bisa dirender dan user memperbaiki datanya. Isi yang
     * benar-benar ditulis ke config tetap lewat all()/normalize().
     *
     * @param array<int,mixed> $routes
     */
    public static function toText(array $routes): string
    {
        $lines = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $path = $route['path'] ?? null;
            $target = $route['target'] ?? null;
            if (!is_string($path) || !is_string($target)) {
                continue;
            }
            $lines[] = $path . ' ' . $target;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,bool> $seen
     */
    private static function validatePath(string $path, int $index, array $seen): string
    {
        if ($path === '') {
            throw new RuntimeException("Path rute #{$index} tidak boleh kosong. Contoh: /api/.");
        }
        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new RuntimeException(
                "Path rute #{$index} terlalu panjang (maksimal " . self::MAX_PATH_LENGTH . ' karakter).'
            );
        }
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new RuntimeException(
                'Path rute #' . $index . ' tidak valid: "' . self::snippet($path) . '". ' .
                'Gunakan path absolut seperti /api/ dengan karakter A-Z a-z 0-9 . _ ~ - / saja ' .
                '(tanpa spasi, "?", atau "#").'
            );
        }
        if (str_contains($path, '..')) {
            throw new RuntimeException(
                'Path rute #' . $index . ' tidak boleh mengandung ".." (segmen relatif). ' .
                'Gunakan path absolut tanpa "..", mis. /api/.'
            );
        }
        if ($path === '/') {
            throw new RuntimeException(
                'Path rute "/" tidak diizinkan karena sudah dipakai app (location /). ' .
                'Gunakan path yang lebih spesifik, mis. /api/.'
            );
        }
        if (str_starts_with($path, '/.well-known')) {
            throw new RuntimeException(
                'Path rute yang diawali /.well-known tidak diizinkan ' .
                '(dipakai verifikasi sertifikat SSL/ACME). Gunakan path lain.'
            );
        }
        if (isset($seen[$path])) {
            throw new RuntimeException(
                'Path "' . self::snippet($path) . '" dipakai lebih dari satu kali. ' .
                'Gabungkan menjadi satu rute.'
            );
        }

        return $path;
    }

    private static function validateTarget(string $target, int $index): string
    {
        if ($target === '') {
            throw new RuntimeException(
                "Target rute #{$index} tidak boleh kosong. Contoh: http://127.0.0.1:3001."
            );
        }
        if (preg_match(self::TARGET_PATTERN, $target) !== 1) {
            throw new RuntimeException(
                'Target rute #' . $index . ' tidak valid: "' . self::snippet($target) . '". ' .
                'Gunakan URL http:// atau https:// seperti http://127.0.0.1:3001 ' .
                '(tanpa user@, "?", "#", atau spasi).'
            );
        }
        if (str_contains($target, '..')) {
            throw new RuntimeException(
                'Target rute #' . $index . ' tidak boleh mengandung ".." (berpotensi keluar dari path yang dituju).'
            );
        }

        $authority = (string) preg_replace('#^https?://#', '', $target);
        $authority = explode('/', $authority, 2)[0];
        if (preg_match('/:(\d+)$/', $authority, $match) === 1) {
            $port = (int) $match[1];
            if ($port < 1 || $port > 65535) {
                throw new RuntimeException(
                    "Port pada target rute #{$index} tidak valid ({$match[1]}). Gunakan rentang 1-65535."
                );
            }
        }

        return $target;
    }

    /**
     * Potongan satu baris untuk pesan error (hindari newline/teks kepanjangan).
     */
    private static function snippet(string $value): string
    {
        $oneLine = trim((string) preg_replace('/\s+/', ' ', $value));
        if (strlen($oneLine) > 60) {
            return substr($oneLine, 0, 57) . '...';
        }

        return $oneLine;
    }
}
