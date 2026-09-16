<?php
declare(strict_types=1);

namespace app\library\Auth;

use Throwable;
use Webman\Exception\BusinessException;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * Dilempar saat user tidak punya hak atas sebuah app (atau app tidak ada).
 *
 * Berbeda dari 403, handler merespons **404 Not Found** supaya keberadaan app
 * milik user lain tidak bocor — app yang tidak boleh diakses tampak sama dengan
 * app yang tidak ada.
 *
 * extends BusinessException: kondisi ini bukan error server, sehingga tidak
 * dicatat sebagai error (BusinessException ada di $dontReport handler webman).
 */
class AppAccessDenied extends BusinessException
{
    public function __construct(
        public readonly string $ability = 'view',
        public readonly ?string $appId = null,
    ) {
        parent::__construct('Not Found', 404);
    }

    /**
     * Render 404: JSON untuk endpoint /api/* & request AJAX, halaman 404 untuk
     * permintaan halaman biasa.
     */
    public function render(Request $request): ?Response
    {
        if ($request->expectsJson() || str_starts_with($request->path(), '/api/')) {
            return json(['code' => 404, 'msg' => 'Not Found'])->withStatus(404);
        }

        try {
            return view('error/404')->withStatus(404);
        } catch (Throwable $e) {
            // Fallback: jangan sampai kegagalan render berubah menjadi 500.
            return response(
                '<!doctype html><meta charset="utf-8"><h1>404 — Tidak ditemukan</h1>',
                404,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        }
    }
}
