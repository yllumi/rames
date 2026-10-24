<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Branding\BrandingLogo;
use support\Request;
use Webman\Http\Response;

/**
 * Penyaji berkas **logo brand kustom** (`GET /branding/logo`).
 *
 * Route ini publik (`AuthMiddleware::PUBLIC_PATHS`, path eksak) karena logo
 * dipakai halaman login — sebelum sesi ada. Karena itu keamanannya tidak
 * bertumpu pada autentikasi, melainkan pada dua hal:
 *
 * 1. **Tidak ada input path/nama dari request.** Satu-satunya sumber berkas
 *    adalah {@see BrandingLogo::path()} (direktori store, nama berkas tetap
 *    `logo.png|jpg|jpeg|webp`), sehingga tidak ada permukaan path traversal.
 * 2. Respons hanya berisi byte gambar brand — tanpa data sensitif lain, dan
 *    path absolut tidak pernah dikirim ke klien/log.
 *
 * Cache: logo sering diganti, jadi `Cache-Control: no-cache` **wajib**; URL
 * pembawa cache-buster (`/branding/logo?v=<etag>`, lihat
 * `BrandingManager::logoUrl()`) disajikan sebagai ETag sehingga revalidasi
 * murah (`If-None-Match` → 304 tanpa body).
 */
class BrandingController
{
    /**
     * GET /branding/logo — sajikan logo kustom; 404 bila belum ada.
     */
    public function logo(Request $request): Response
    {
        $store = $this->logoStore();
        $path = $store->path();
        if ($path === null) {
            return response('', 404);
        }

        $etag = $store->etag();
        $headers = [
            // SVG/GIF tidak pernah masuk store (`BrandingLogo::save()` menolak),
            // jadi tipe di bawah aman untuk ditampilkan inline.
            'Content-Type' => $store->mime() ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache',
        ];

        if ($etag !== null) {
            $headers['ETag'] = '"' . $etag . '"';
            if ($this->matchesIfNoneMatch((string) $request->header('if-none-match', ''), $etag)) {
                // 304 tanpa body: klien memakai salinan cache-nya.
                return response('', 304, $headers);
            }
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            // Berkas hilang/tak terbaca antar-cek: perlakukan sebagai tidak ada.
            return response('', 404);
        }

        // `Content-Length` TIDAK diset manual: Webman menambahkannya dari body,
        // header ganda (nilai sama) melanggar RFC 7230 §3.3.2.
        return response($content, 200, $headers);
    }

    /**
     * Store logo (seam uji: direktori temp).
     */
    protected function logoStore(): BrandingLogo
    {
        return new BrandingLogo();
    }

    /**
     * Apakah header `If-None-Match` cocok dengan ETag aktif.
     *
     * Menerima `*`, daftar dipisah koma, dan bentuk berkutip/weak (`W/"…"`) —
     * perbandingan tetap pada nilai ETag yang **persis** sama.
     */
    private function matchesIfNoneMatch(string $header, string $etag): bool
    {
        $header = trim($header);
        if ($header === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (str_starts_with($candidate, 'W/')) {
                $candidate = trim(substr($candidate, 2));
            }
            if (trim($candidate, '"') === $etag) {
                return true;
            }
        }

        return false;
    }
}
