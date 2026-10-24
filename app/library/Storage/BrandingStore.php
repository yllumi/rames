<?php
declare(strict_types=1);

namespace app\library\Storage;

/**
 * Identitas brand dashboard — store logis `branding` (tabel fisik
 * `branding_settings`) di basis data SQLite (`config('deploy.sqlite_file')`).
 *
 * Hanya ada satu dokumen, ber-id {@see self::DOC_ID}. Berkas logo **tidak**
 * disimpan di sini: yang dicatat cuma nama berkas (`logo.png`/`logo.jpg`/
 * `logo.webp`) karena path absolut akan basi saat data dipindah volume.
 * Penyimpanan berkasnya ditangani `app\library\Branding\BrandingLogo`.
 *
 * `get()` selalu mengembalikan bentuk lengkap ternormalisasi — pemakai tidak
 * perlu menangani dokumen kosong/rusak:
 * `['name' => string, 'logo' => null|string, 'updated_at' => null|string]`.
 *
 * Path dapat di-override lewat `$filePath` (berkas .sqlite) agar tes memakai
 * direktori temp (pola {@see ServerStore}/{@see ParkedDomainStore}).
 */
final class BrandingStore
{
    /** Id dokumen tunggal identitas brand. */
    public const DOC_ID = 'branding';

    /** Nama brand bawaan saat belum pernah disimpan. */
    public const DEFAULT_NAME = 'Rames';

    /** Pola nama berkas logo yang sah (relatif, tanpa path). */
    private const LOGO_PATTERN = '/^logo\.(png|jpg|jpeg|webp)$/i';

    private SqliteStore $store;

    /**
     * @param string|null $filePath berkas .sqlite; null = `config('deploy.sqlite_file')`
     */
    public function __construct(?string $filePath = null)
    {
        $this->store = new SqliteStore('branding', SchemaMigrations::storeDefinitions()['branding'], $filePath);
    }

    /**
     * Path berkas basis data .sqlite.
     */
    public function path(): string
    {
        return $this->store->path();
    }

    /**
     * Identitas brand ternormalisasi (default `Rames`, logo null).
     *
     * Field kosong/tak valid dikembalikan ke default; nilai `updated_at`
     * hanya diisi bila dokumen pernah disimpan.
     *
     * @return array{name:string,logo:?string,updated_at:?string}
     */
    public function get(): array
    {
        foreach ($this->store->read() as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === self::DOC_ID) {
                return self::normalize($row);
            }
        }

        return self::defaults();
    }

    /**
     * Upsert dokumen identitas brand (id `branding`).
     *
     * `$name` disimpan apa adanya setelah trim (validasi panjang/karakter
     * adalah tugas `BrandingManager`); `$logo` hanya menerima **nama berkas**
     * — path absolut dipangkas ke basename-nya.
     *
     * @return array{name:string,logo:?string,updated_at:?string} hasil `get()`
     */
    public function save(string $name, ?string $logo): array
    {
        $doc = [
            'id' => self::DOC_ID,
            'name' => trim($name),
            'logo' => self::normalizeLogo($logo),
            'updated_at' => date('c'),
        ];

        $this->store->update(function (array &$data) use ($doc): void {
            foreach ($data as $index => $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === self::DOC_ID) {
                    $data[$index] = $doc;
                    return;
                }
            }
            $data[] = $doc;
        });

        return $this->get();
    }

    /**
     * Hapus dokumen identitas brand (kembali ke default). Idempoten.
     *
     * @return array{name:string,logo:?string,updated_at:?string}
     */
    public function reset(): array
    {
        $this->store->update(function (array &$data): void {
            $data = array_values(array_filter(
                $data,
                static fn ($row): bool => !is_array($row) || (string) ($row['id'] ?? '') !== self::DOC_ID
            ));
        });

        return $this->get();
    }

    /**
     * @return array{name:string,logo:?string,updated_at:?string}
     */
    private static function defaults(): array
    {
        return ['name' => self::DEFAULT_NAME, 'logo' => null, 'updated_at' => null];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{name:string,logo:?string,updated_at:?string}
     */
    private static function normalize(array $row): array
    {
        $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
        $updatedAt = is_string($row['updated_at'] ?? null) ? trim($row['updated_at']) : '';

        return [
            'name' => $name !== '' ? $name : self::DEFAULT_NAME,
            'logo' => self::normalizeLogo(is_string($row['logo'] ?? null) ? $row['logo'] : null),
            'updated_at' => $updatedAt !== '' ? $updatedAt : null,
        ];
    }

    /**
     * Normalisasi nilai logo: basename saja + ekstensi yang dikenal,
     * selain itu `null`.
     */
    private static function normalizeLogo(?string $logo): ?string
    {
        if ($logo === null) {
            return null;
        }

        $name = basename(trim($logo));
        return preg_match(self::LOGO_PATTERN, $name) === 1 ? $name : null;
    }
}
