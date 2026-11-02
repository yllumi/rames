<?php
declare(strict_types=1);

namespace app\library\Deploy;

use Throwable;

/**
 * Satu sumber **alamat publik server** pemilik app (target DNS record custom
 * domain) — **murni komputasi dari data registry, tanpa I/O jaringan**.
 *
 * Latar belakang (insiden nyata): saat user menyimpan custom domain, dashboard
 * hanya berkata "arahkan DNS ke server". User lalu mengarahkan domain ke IP
 * server **dashboard**, padahal app-nya berjalan di server **agent** — Let's
 * Encrypt HTTP-01 di agent selalu `404` karena permintaan challenge tidak
 * pernah sampai ke sana. Karena itu Rames wajib menyebut alamat konkret server
 * tempat app bernaung.
 *
 * **ATURAN KERAS — tidak ada resolusi DNS di jalur render.** Rames berjalan di
 * worker Webman **persistent**: satu host-lookup DNS yang menggantung (±4 s
 * pada instalasi default tanpa `base_url`, terukur di container) memblokir
 * worker yang melayani *semua* request — termasuk role `viewer` yang tidak
 * pernah melihat alamat ini. Resolver sistem tidak punya timeout yang bisa
 * diatur, jadi kelas ini **tidak pernah memanggil resolver DNS** (tidak ada
 * fungsi host-lookup PHP, tidak ada helper DNS, tidak ada closure resolusi
 * yang disuntikkan). Bila alamat tidak ada di registry / `base_url`,
 * kembalikan `''` — pemanggil menampilkan kalimat generik dan operator dapat
 * mengisi alamatnya sendiri di registry.
 *
 * Kelas ini **statik murni tanpa state** (tidak ada properti cache, tidak ada
 * I/O ke store sendiri): registry server dibaca lewat `ServerResolver` yang
 * disuntikkan pemanggil (pola `domainForAppOrLocal()`), sehingga tes cukup
 * memakai `new ServerResolver(new ServerStore($fileTemp))`.
 *
 * Urutan sumber alamat (kembalikan kandidat **pertama yang valid & publik**):
 *  1. field eksplisit pada baris server: `address`, lalu `public_ip`, lalu
 *     `ip` — jalur operator mengisi alamat di registry; kosong/tidak valid
 *     ⇒ dilewati;
 *  2. host dari `base_url` server ({@see ServerResolver::baseUrl()},
 *     `parse_url`; `base_url` tidak terbaca/melempar ⇒ dilewati);
 *  3. selain itu `''`.
 *
 * Validasi kandidat: tolak `''`, `localhost`, IP privat/loopback/reserved
 * (`FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`), dan hostname
 * berakhiran `.local`/`.internal`/`.lan` (pola sama
 * `SslIssuer::isPublicDomain`). Hostname **publik** dikembalikan apa adanya —
 * mis. agent yang `base_url`-nya memakai nama host. UI menyebut nilai ini
 * "alamat", bukan selalu IPv4; {@see self::recordType()} yang menentukan
 * A atau AAAA.
 *
 * **GOTCHA no. 25 (redaksi identitas/topologi server)**: kelas ini TIDAK PERNAH
 * mengembalikan token, blok PEM, `base_url` utuh, atau hostname internal — satu-
 * satunya nilai yang boleh tampil di UI adalah **alamat** (IP publik atau
 * hostname publik). Server agent yang `base_url`-nya menunjuk alamat internal
 * (mis. `https://10.0.0.5:8443` atau nama host `.internal`) sengaja
 * menghasilkan `''`, bukan tebakan dari DNS app lain: alamat yang salah justru
 * menyebabkan insiden HTTP-01.
 */
final class ServerAddress
{
    /**
     * Akhiran hostname yang secara desain tidak dapat menjadi target record
     * publik (pola sama `SslIssuer::isPublicDomain()`).
     *
     * @var array<int,string>
     */
    private const NON_PUBLIC_SUFFIXES = ['.local', '.internal', '.lan'];

    /**
     * Field baris server yang boleh memuat alamat eksplisit, berurutan
     * prioritas. Semua opsional — registry hari ini tidak mengisinya.
     *
     * @var array<int,string>
     */
    private const ADDRESS_FIELDS = ['address', 'public_ip', 'ip'];

    /**
     * FQDN publik yang sah: minimal satu titik, label alfanumerik/strip, TLD
     * alfabet. `localhost` dan nama host tanpa titik otomatis tertolak.
     */
    private const HOSTNAME_PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * Alamat publik server pemilik app untuk target DNS record; `''` bila tak
     * bisa dipastikan dari data registry. **Tanpa I/O jaringan** — lihat
     * docblock kelas: worker persistent tidak boleh menunggu resolver.
     *
     * @param array<string,mixed> $app      dokumen app (yang dipakai: `server_id`)
     * @param ServerResolver|null $resolver registry server; null = store bawaan
     */
    public static function forApp(array $app, ?ServerResolver $resolver = null): string
    {
        $resolver ??= new ServerResolver();

        try {
            $server = $resolver->findForApp($app);
        } catch (Throwable) {
            // Registry tak terbaca: jangan menebak alamat (bisa salah server).
            return '';
        }

        if ($server === null) {
            return '';
        }

        foreach (self::ADDRESS_FIELDS as $field) {
            $value = $server[$field] ?? '';
            if (!is_scalar($value)) {
                continue;
            }

            $address = self::usableAddress((string) $value);
            if ($address !== '') {
                return $address;
            }
        }

        return self::addressFromBaseUrl($server);
    }

    /**
     * Jenis record DNS untuk sebuah alamat: `'AAAA'` bila alamat IPv6 valid,
     * `'A'` bila IPv4 literal, dan `'A/AAAA'` untuk hostname (jenis record
     * belum bisa dipastikan tanpa resolusi DNS — dan resolusi DNS DILARANG di
     * jalur render) atau alamat kosong.
     */
    public static function recordType(string $address): string
    {
        $host = self::normalizeHost($address);
        if ($host === '') {
            return 'A/AAAA';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'AAAA';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'A';
        }

        return 'A/AAAA';
    }

    /**
     * Host dari `base_url` server (tanpa kredensial/path/port) yang lolos
     * validasi publik; `''` bila kosong, tidak terbaca, atau internal.
     *
     * @param array<string,mixed> $server baris registry (boleh memuat rahasia — hanya host yang dipakai)
     */
    private static function addressFromBaseUrl(array $server): string
    {
        try {
            $baseUrl = trim(ServerResolver::baseUrl($server));
        } catch (Throwable) {
            return '';
        }

        if ($baseUrl === '') {
            return '';
        }

        try {
            $parts = parse_url($baseUrl);
        } catch (Throwable) {
            return '';
        }

        if (!is_array($parts)) {
            return '';
        }

        return self::usableAddress((string) ($parts['host'] ?? ''));
    }

    /**
     * Kandidat alamat yang boleh tampil: IP publik apa adanya, atau hostname
     * publik apa adanya; selain itu `''`.
     */
    private static function usableAddress(string $candidate): string
    {
        $value = self::normalizeHost($candidate);
        if ($value === '') {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($value) ? $value : '';
        }

        return self::isPublicHostname($value) ? $value : '';
    }

    /**
     * Hostname layak tampil sebagai alamat: FQDN sah, bukan `localhost`,
     * bukan akhiran internal.
     */
    private static function isPublicHostname(string $host): bool
    {
        if (preg_match(self::HOSTNAME_PATTERN, $host) !== 1) {
            return false;
        }

        foreach (self::NON_PUBLIC_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * IP publik (IPv4 atau IPv6): bukan privat, loopback, link-local, reserved.
     */
    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Normalisasi host: huruf kecil, tanpa spasi, literal IPv6 `[...]` dibuka,
     * dan titik akhir FQDN dibuang.
     */
    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return rtrim($host, '.');
    }
}
