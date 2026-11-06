<?php
declare(strict_types=1);

namespace app\controller\api;

use app\library\Api\OpenApiSpec;
use support\Request;
use Webman\Http\Response;

/**
 * Halaman dokumentasi OpenAPI + dokumen JSON-nya.
 *
 *  - `GET /api-docs`              → halaman dokumentasi (**wajib login**;
 *    `AuthMiddleware` global menolak permintaan tanpa sesi → redirect `/login`);
 *  - `GET /api-docs/openapi.json` → dokumen OpenAPI 3.1 (juga **wajib login**).
 *
 * Keduanya sengaja **di luar** prefix `/api/v1/**`: dokumentasi API tidak boleh
 * diambil anonim, dan permukaan `/api/v1/**` khusus API key per user. Halaman
 * mengarahkan user membuat/mencabut key-nya sendiri di `/profile`.
 *
 * Isi halaman dirender dari {@see OpenApiSpec} — daftar endpoint/field tidak
 * pernah diduplikasi di view.
 */
final class ApiDocsController
{
    /** Tab halaman dokumentasi (server-side, deep-link `?tab=`). */
    public const TABS = ['endpoint', 'mcp'];

    public function page(Request $request): Response
    {
        return view('api/docs', [
            'active' => 'api',
            'spec' => OpenApiSpec::build(),
            'specPath' => OpenApiSpec::DOCS_JSON_PATH,
            'profilePath' => '/profile',
            'origin' => $this->origin($request),
            'tab' => $this->tabFromRequest($request),
        ]);
    }

    /**
     * Tab aktif dari `?tab=` — nilai tak dikenal (atau array) jatuh ke tab
     * pertama, pola yang sama dengan `NginxController::tabFromRequest()`.
     */
    private function tabFromRequest(Request $request): string
    {
        $tab = $request->get('tab');

        return is_string($tab) && in_array($tab, self::TABS, true) ? $tab : self::TABS[0];
    }

    public function spec(Request $request): Response
    {
        return json(OpenApiSpec::build())
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Origin dashboard untuk contoh konfigurasi MCP (`http(s)://host[:port]`).
     *
     * Skema diambil dari `X-Forwarded-Proto` (di-set proxy Nginx) dan jatuh ke
     * `http` bila tidak ada. String kosong = host tidak diketahui; view memakai
     * placeholder. Tidak ada data sensitif di sini (hanya host publik).
     */
    private function origin(Request $request): string
    {
        $host = trim((string) $request->host(false));
        if ($host === '') {
            return '';
        }

        $proto = strtolower(trim((string) $request->header('x-forwarded-proto', '')));
        if (!in_array($proto, ['http', 'https'], true)) {
            $proto = strtolower(trim((string) $request->header('x-forwarded-ssl', ''))) === 'on'
                ? 'https'
                : 'http';
        }

        return $proto . '://' . $host;
    }
}
