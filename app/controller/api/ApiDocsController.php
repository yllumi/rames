<?php
declare(strict_types=1);

namespace app\controller\api;

use app\library\Api\ApiTokenAuth;
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
 * diambil anonim, dan permukaan `/api/v1/**` khusus bearer token. Detail
 * konfigurasi token (aktif/nonaktif + user aktor) hanya ditampilkan untuk admin
 * lewat flag `canManageApi` (view tidak melakukan cek hak sendiri).
 *
 * Isi halaman dirender dari {@see OpenApiSpec} — daftar endpoint/field tidak
 * pernah diduplikasi di view.
 */
final class ApiDocsController
{
    public function page(Request $request): Response
    {
        $auth = new ApiTokenAuth();

        return view('api/docs', [
            'active' => 'api',
            'spec' => OpenApiSpec::build(),
            'apiEnabled' => $auth->enabled(),
            'tokenUser' => $auth->username(),
            'specPath' => OpenApiSpec::DOCS_JSON_PATH,
            'canManageApi' => is_admin(),
        ]);
    }

    public function spec(Request $request): Response
    {
        return json(OpenApiSpec::build())
            ->withHeader('Cache-Control', 'no-store');
    }
}
