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
    public function page(Request $request): Response
    {
        return view('api/docs', [
            'active' => 'api',
            'spec' => OpenApiSpec::build(),
            'specPath' => OpenApiSpec::DOCS_JSON_PATH,
            'profilePath' => '/profile',
        ]);
    }

    public function spec(Request $request): Response
    {
        return json(OpenApiSpec::build())
            ->withHeader('Cache-Control', 'no-store');
    }
}
