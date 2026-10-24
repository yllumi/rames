<?php
declare(strict_types=1);

namespace app\library\Branding;

use app\library\Storage\BrandingStore;
use InvalidArgumentException;

/**
 * Orkestrasi identitas brand dashboard: gabungan metadata di
 * {@see BrandingStore} (SQLite) dan berkas logo di {@see BrandingLogo}.
 *
 * Bentuk state publik yang dipakai controller/view:
 * ```
 * [
 *   'name' => 'Rames',
 *   'logo_url' => '/logo-small.png',            // atau '/branding/logo?v=<etag>'
 *   'logo_custom' => false,
 *   'updated_at' => null,                       // ISO-8601 setelah disimpan
 * ]
 * ```
 *
 * Validasi nama dilakukan **sebelum** menyentuh store/disk sehingga kegagalan
 * tidak meninggalkan perubahan parsial. `InvalidArgumentException` diteruskan
 * apa adanya (tidak ditelan) — controller yang memetakan ke respons HTTP.
 */
final class BrandingManager
{
    /** Panjang maksimum nama brand (karakter). */
    public const MAX_NAME = 32;

    /** Logo bawaan bila belum ada logo kustom. */
    public const DEFAULT_LOGO_URL = '/logo-small.png';

    /** Path publik logo kustom (butuh query `v=` sebagai cache-buster). */
    public const LOGO_PATH = '/branding/logo';

    private readonly BrandingStore $store;
    private readonly BrandingLogo $logo;

    public function __construct(?BrandingStore $store = null, ?BrandingLogo $logo = null)
    {
        $this->store = $store ?? new BrandingStore();
        $this->logo = $logo ?? new BrandingLogo();
    }

    /**
     * Identitas brand aktif (selalu bentuk lengkap).
     *
     * @return array{name:string,logo_url:string,logo_custom:bool,updated_at:?string}
     */
    public function current(): array
    {
        $data = $this->store->get();
        $etag = $data['logo'] !== null ? $this->logo->etag() : null;
        $custom = $data['logo'] !== null && $etag !== null;

        return [
            'name' => $data['name'],
            'logo_url' => $custom ? self::LOGO_PATH . '?v=' . $etag : self::DEFAULT_LOGO_URL,
            'logo_custom' => $custom,
            'updated_at' => $data['updated_at'],
        ];
    }

    /**
     * Simpan nama brand (+ logo baru bila `$upload` diberikan).
     *
     * `$upload === null` mempertahankan logo lama; `$rawName` wajib berisi
     * 1..32 karakter tanpa karakter kontrol.
     *
     * @param array{tmp:string,mime:string,size:int,error:int,name:string}|null $upload
     * @return array{name:string,logo_url:string,logo_custom:bool,updated_at:?string}
     *
     * @throws InvalidArgumentException bila nama tidak valid atau unggahan ditolak
     */
    public function update(?string $rawName, ?array $upload): array
    {
        $name = $this->normalizeName($rawName);

        $logo = $this->store->get()['logo'];
        $newLogo = null;
        if ($upload !== null) {
            // Validasi dulu tanpa efek samping: unggahan ditolak ⇒ store dan
            // berkas lama tidak tersentuh sama sekali.
            $newLogo = $this->logo->validate($upload);
        }

        // Metadata dulu, berkas kemudian (self-healing): bila `store()` gagal,
        // berkas lama masih utuh dan dokumen menunjuk nama yang belum ada —
        // `current()` sudah fallback ke logo bawaan saat `etag()` null.
        $this->store->save($name, $newLogo ?? $logo);

        if ($newLogo !== null && $upload !== null) {
            $this->logo->store($upload, $newLogo);
        }

        return $this->current();
    }

    /**
     * Kembalikan ke brand bawaan: hapus berkas logo lalu dokumen store.
     *
     * @return array{name:string,logo_url:string,logo_custom:bool,updated_at:?string}
     */
    public function reset(): array
    {
        $this->logo->delete();
        $this->store->reset();

        return $this->current();
    }

    /**
     * Nama brand aktif (jalur baca ringkas untuk helper view).
     */
    public function name(): string
    {
        return $this->store->get()['name'];
    }

    /**
     * URL logo aktif (jalur baca ringkas untuk helper view).
     */
    public function logoUrl(): string
    {
        return $this->current()['logo_url'];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function normalizeName(?string $rawName): string
    {
        $name = trim((string) $rawName);
        if ($name === '') {
            throw new InvalidArgumentException('Nama brand wajib diisi.');
        }

        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length > self::MAX_NAME) {
            throw new InvalidArgumentException('Nama brand maksimal ' . self::MAX_NAME . ' karakter.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new InvalidArgumentException('Nama brand tidak boleh memuat karakter kontrol.');
        }

        return $name;
    }
}
