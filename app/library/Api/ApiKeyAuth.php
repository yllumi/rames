<?php
declare(strict_types=1);

namespace app\library\Api;

use app\library\Auth\UserStore;
use Webman\Http\Request;

/**
 * Autentikasi API eksternal dengan **API key per user** (docs/SPECS.md §7.19).
 *
 * Model:
 *  - setiap user login membuat/mencabut key-nya sendiri dari halaman Profil;
 *    satu key aktif per user (generate ulang = key lama langsung mati);
 *  - plaintext key (`rmk_…`) hanya ditampilkan **sekali** saat dibuat; store
 *    hanya menyimpan SHA-256 + prefix tampilan (`UserStore`);
 *  - key **tidak punya hak sendiri** — ia bertindak sebagai user pemiliknya,
 *    sehingga `AppAccess` dan gerbang kredit berjalan sama seperti sesi user
 *    itu. Tidak ada token admin global di env.
 *
 * Header wajib `Authorization: Bearer <api-key>` (skema case-insensitive).
 * Kegagalan apa pun ⇒ `null` (pemanggil membalas 401 seragam, tanpa oracle).
 * Stateless: store dibaca per pemanggilan (aman untuk worker persistent).
 */
final class ApiKeyAuth
{
    public const HEADER = 'Authorization';
    public const SCHEME = 'Bearer';

    private readonly ?UserStore $users;

    /** @param UserStore|null $users seam uji: store user temp */
    public function __construct(?UserStore $users = null)
    {
        $this->users = $users;
    }

    /**
     * Verifikasi request **dan** catat pemakaian key (throttle 1×/menit).
     * Dipakai middleware — efek samping pencatatan tidak mengubah keputusan.
     *
     * @return array<string,mixed>|null bentuk publik user (tanpa hash/secret)
     */
    public function authenticate(Request $request): ?array
    {
        $actor = $this->actorFromRequest($request);
        if ($actor === null) {
            return null;
        }

        $id = (string) ($actor['id'] ?? '');
        if ($id !== '') {
            try {
                $this->users()->touchApiKey($id);
            } catch (\Throwable) {
                // Best-effort: kegagalan mencatat pemakaian tidak boleh
                // menggagalkan request yang sudah terautentikasi.
            }
        }

        return $actor;
    }

    /**
     * Verifikasi tanpa efek samping (dipakai controller sebagai
     * defense-in-depth setelah middleware).
     *
     * @return array<string,mixed>|null
     */
    public function actorFromRequest(Request $request): ?array
    {
        $header = $request->header(self::HEADER);
        if (!is_string($header)) {
            return null;
        }

        $key = self::bearerToken($header);
        if ($key === null) {
            return null;
        }

        try {
            return $this->users()->actorByApiKey($key);
        } catch (\Throwable) {
            // Fail-closed: store tak terbaca = tidak ada aktor.
            return null;
        }
    }

    /**
     * Ambil key dari nilai header `Authorization`; hanya skema bearer.
     */
    public static function bearerToken(?string $header): ?string
    {
        $value = trim((string) $header);
        if ($value === '' || preg_match('/^Bearer\s+(\S+)$/i', $value, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function users(): UserStore
    {
        return $this->users ?? new UserStore();
    }
}
