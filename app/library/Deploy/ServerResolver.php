<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Storage\ServerStore;
use RuntimeException;
use Throwable;

/**
 * Satu titik resolusi server (multi-server F0 — plan §4, §4.2, §4.3, §11).
 *
 * Tujuan kelas ini: **"app X di server Y" tidak pernah lagi diam-diam dijalankan
 * di server lokal**. Karena itu setiap jalur resolusi bersifat *fail-fast*:
 * id server yang tidak dikenal MELEMPAR, bukan jatuh ke server lokal, dan
 * accessor yang nilainya wajib ada pada server `kind=agent` (`base_url`, `token`,
 * `tls_cert_pem`) juga melempar bila kosong.
 *
 * Bentuk kelas (pilihan sadar, konsisten dengan gaya repo):
 *  - **logika murni = statik** (`isLocal()`, `domainFor()`, `configDomain()`,
 *    `portRange()`, `baseUrl()`, `token()`, `tlsCertPem()`, `agentPort()`,
 *    `fingerprintFromPem()`) — tanpa I/O, mudah diuji tanpa store;
 *  - **logika yang menyentuh store = instance** (`local()`, `resolve()`,
 *    `resolveForApp()`/`serverForApp()`, `domainForApp()`, dan `validate()`
 *    karena aturan "tidak boleh tumpang tindih rentang port server lokal" perlu
 *    membaca rentang efektif server lokal). `findForApp()` adalah varian `null`
 *    khusus jalur pembersihan record app (§7.14) — **bukan** untuk operasi.
 *    Store disuntikkan lewat konstruktor,
 *    sehingga tes cukup memakai `new ServerResolver(new ServerStore($pathTemp))`
 *    — pola yang sama dengan `AppStore`/`UserStore` (path `.sqlite` di
 *    konstruktor), jadi kelas ini tidak perlu tahu-menahu soal path;
 *  - **varian toleran untuk perakitan FQDN/display = statik** dengan resolver
 *    opsional ({@see self::domainForAppOrLocal()}) supaya helper view tidak
 *    pernah meledak hanya karena registry server tidak terbaca.
 *
 * Tidak ada state lintas-request: satu-satunya properti instance hanya handle
 * store dan seluruh pembacaan dilakukan per panggilan — aman untuk worker
 * Webman persistent. Pengecualian yang **disengaja** (misi `ms-f1-domain`):
 * `self::$memo` — snapshot registry server yang divalidasi ulang terhadap cap
 * berkas basis data (**path + mtime + size**, termasuk `-wal`/`-shm`) pada tiap
 * pembacaan. Kunci memo memuat identitas berkas sehingga ia tidak pernah
 * menyajikan data dari berkas store lain, dan perubahan store (WAL bertambah
 * tiap commit) selalu membatalkannya. Lihat {@see self::snapshot()} dan
 * {@see self::forgetMemo()}.
 *
 * Catatan peran: fase F0 **tidak** melakukan panggilan HTTP ke agent (itu F1).
 * Kelas ini hanya menyiapkan nilai yang nanti dipakai `AgentClient`; nilainya
 * dipakai apa adanya (base_url https, token, PEM, domain, port range).
 */
final class ServerResolver
{
    /** Batas panjang nama server (slug) — pola sama `ContainerNames::MAX_PREFIX_LENGTH`. */
    public const MAX_NAME_LENGTH = 32;

    /** Port default agent (plan §10.6: agent diekspos di `:8443` HTTPS self-signed). */
    public const DEFAULT_AGENT_PORT = 8443;

    /** Batas panjang domain dasar server (batas FQDN). */
    public const MAX_DOMAIN_LENGTH = 253;

    /** Batas panjang catatan server (dokumen JSON di SQLite, bukan tempat teks bebas). */
    public const MAX_NOTES_LENGTH = 1000;

    /** Batas port TCP yang sah. */
    public const MIN_PORT = 1;
    public const MAX_PORT = 65535;

    /**
     * Capability minimal yang **wajib** sudah dilaporkan server `kind=agent`
     * sebelum app baru boleh dibuat di sana (misi `ms-f1-create-server`).
     *
     * Nilainya = op job yang benar-benar dipicu jalur create/deploy
     * (`deploy` — mode pertama {@see DeployWorkerLauncher::MODES}, satu sumber
     * yang sama dengan `capabilities` yang dilaporkan agent). Daftar ini
     * sengaja kecil: ia hanya menjaga "server yang belum pernah diuji" atau
     * "agent yang belum mendukung op dasar" agar tidak menerima app.
     *
     * @var array<int,string>
     */
    public const READY_CAPABILITIES = ['deploy'];

    /** Slug nama server: huruf kecil/angka, `-` boleh di tengah (pola prefix container). */
    private const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /**
     * Field yang **dikunci** pada baris server lokal (lihat {@see self::validateLocal()}).
     *
     * `id`, `tls_fingerprint`, serta state koneksi (`status`, `agent_version`,
     * `capabilities`, `last_seen_at`) tidak ada di sini karena memang tidak pernah
     * dibaca dari payload form — jadi tidak ada yang bisa dipalsukan lewat daftar ini.
     */
    private const LOCAL_LOCKED = ['name', 'kind', 'base_url', 'token', 'tls_cert_pem', 'enabled', 'port_range'];

    /** Satu-satunya field yang boleh disesuaikan pada baris server lokal. */
    private const LOCAL_EDITABLE = ['domain', 'notes'];

    /** Domain dasar: huruf/angka/titik/strip, diawali & diakhiri alfanumerik. */
    private const DOMAIN_PATTERN = '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/';

    private readonly ServerStore $servers;

    /**
     * Memo per-proses snapshot registry server (misi `ms-f1-domain`).
     *
     * Bukan cache lintas-request yang bocor: tiap pembacaan memvalidasi cap
     * berkas basis data (**path + mtime + size** berkas `.sqlite` beserta
     * `-wal`/`-shm`) yang disimpan bersama snapshot, sehingga setiap perubahan
     * store otomatis membatalkan memo dan tidak ada data basi yang disajikan.
     * Kunci = path berkas, jadi dua store berbeda (mis. `database/rames.sqlite`
     * dan berkas temp saat tes) tidak pernah saling menyilang.
     *
     * Alasan keberadaannya: `app_subdomain_of()` dipanggil per baris app di
     * daftar app; tanpa memo itu berarti satu kueri penuh + decode JSON per
     * baris. Pola ini sama dengan `SqliteStore::$schemaReady`.
     *
     * @var array<string,array{stamp:string,servers:array<int,array<string,mixed>>}>
     */
    private static array $memo = [];

    /**
     * @param ServerStore|null $servers store server; null = `ServerStore()` bawaan
     *                                  (path `config('deploy.sqlite_file')`).
     *                                  Tes memakai `new ServerStore($fileTemp)`.
     */
    public function __construct(?ServerStore $servers = null)
    {
        $this->servers = $servers ?? new ServerStore();
    }

    /**
     * Kosongkan memo snapshot registry server.
     *
     * Dipakai tes yang mengganti isi berkas store di luar jalur normal, dan
     * sebagai jaring pengaman operasional. `null`/kosong = bersihkan semua.
     */
    public static function forgetMemo(?string $file = null): void
    {
        if ($file === null || $file === '') {
            self::$memo = [];

            return;
        }

        unset(self::$memo[$file]);
    }

    // ==================================================================
    // Resolusi (menyentuh store)
    // ==================================================================

    /**
     * Dokumen server lokal (diri sendiri) — `ServerStore::local()` (termasuk
     * self-heal bila barisnya hilang dari snapshot lama).
     *
     * @return array<string,mixed>
     */
    public function local(): array
    {
        return $this->servers->local();
    }

    /**
     * Seluruh baris registry server (server lokal selalu yang pertama).
     *
     * **Tanpa** penyaringan hak akses: pemanggil yang menyaring (mis.
     * `ServerAccess`) — kelas ini tidak menyalin logika role. Dipakai form
     * wizard create untuk menyusun daftar pilihan server (misi
     * `ms-f1-create-server`).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->servers->all();
    }

    /**
     * Server dari registry berdasarkan id.
     *
     * `$serverId` kosong ⇒ `LOCAL_ID` (kompatibilitas app lama yang belum punya
     * `server_id`). Id tidak dikenal ⇒ **RuntimeException** — TIDAK pernah
     * mengembalikan server lokal secara diam-diam (itulah bug paling berbahaya
     * yang dicegah kelas ini, plan §3 butir 4).
     *
     * Baris lokal dibaca dari snapshot (memo) dan di-self-heal lewat
     * {@see self::local()} hanya bila barisnya memang hilang.
     *
     * @return array<string,mixed>
     * @throws RuntimeException bila id tidak ada di registry server
     */
    public function resolve(string $serverId): array
    {
        $id = trim($serverId);
        if ($id === '') {
            $id = ServerStore::LOCAL_ID;
        }

        if ($id === ServerStore::LOCAL_ID) {
            // Lewat snapshot (memo) supaya daftar app tidak menembak store per
            // baris; baris yang hilang tetap di-self-heal lewat `local()`.
            return $this->findInSnapshot($id) ?? $this->local();
        }

        $server = $this->findInSnapshot($id);
        if ($server === null) {
            throw new RuntimeException(
                "Server tidak dikenal: \"{$id}\". Periksa registry server (halaman Servers) — "
                . 'operasi dibatalkan, bukan dijalankan di server lokal.'
            );
        }

        return $server;
    }

    /**
     * Server pemilik app: dari `$app['server_id']` (kosong/absen ⇒ server lokal).
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws RuntimeException bila `server_id` app menunjuk server yang tidak ada
     */
    public function resolveForApp(array $app): array
    {
        return $this->resolve(self::text($app['server_id'] ?? ''));
    }

    /**
     * Server pemilik app **bila barisnya masih ada** di registry; `null` bila
     * `server_id` app menunjuk server yang sudah dihapus dari halaman Servers
     * (app yatim).
     *
     * Bedanya dengan {@see self::resolveForApp()}: hanya baris yang benar-benar
     * tidak ada yang menghasilkan `null` — registry yang **tidak terbaca** tetap
     * melempar, supaya kegagalan I/O tidak pernah disimpulkan sebagai "server
     * sudah dihapus". Dipakai jalur yang harus tetap bisa menuntaskan pekerjaan
     * lokal tanpa server (menghapus record app, §7.14) — operasi lain **wajib**
     * tetap memakai `resolveForApp()` yang fail-fast.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>|null
     * @throws RuntimeException bila registry server tidak terbaca
     */
    public function findForApp(array $app): ?array
    {
        $id = trim(self::text($app['server_id'] ?? ''));
        if ($id === '') {
            $id = ServerStore::LOCAL_ID;
        }

        if ($id === ServerStore::LOCAL_ID) {
            return $this->findInSnapshot($id) ?? $this->local();
        }

        return $this->findInSnapshot($id);
    }

    /**
     * Nama utama (misi `ms-f1-domain`) untuk {@see self::resolveForApp()} —
     * dipakai jalur domain supaya tidak ada dua kosakata untuk hal yang sama.
     *
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     * @throws RuntimeException bila `server_id` app menunjuk server yang tidak ada
     */
    public function serverForApp(array $app): array
    {
        return $this->resolveForApp($app);
    }

    /**
     * Domain dasar efektif **server pemilik app** (keputusan 4: subdomain app =
     * `{label}.{servers.domain}`) — fail-fast: `server_id` yang menunjuk server
     * tak dikenal MELEMPAR, bukan diam-diam memakai `APP_DOMAIN` lokal.
     *
     * Pakai ini di jalur operasi (deploy/SSL/nginx), jangan menyusun sendiri
     * `domainFor(resolveForApp($app))` di pemanggil.
     *
     * @param array<string,mixed> $app
     * @throws RuntimeException bila server app tidak dikenal
     */
    public function domainForApp(array $app): string
    {
        return self::domainFor($this->serverForApp($app));
    }

    /**
     * Domain dasar efektif server pemilik app untuk **perakitan FQDN/display**
     * (helper `app_subdomain*()`, kebijakan subdomain): tidak pernah melempar —
     * bila registry server tidak bisa dibaca (belum dikonfigurasi, berkas tidak
     * terbaca) atau `server_id` app menunjuk server yang sudah hilang, dipakai
     * `config('deploy.app_domain')` seperti sebelum multi-server.
     *
     * Sifat toleran itu disengaja: helper ini merender daftar app, sehingga satu
     * baris data rusak tidak boleh membuat seluruh halaman gagal. Jalur operasi
     * tetap memakai {@see self::domainForApp()} yang fail-fast.
     *
     * Statik + resolver opsional supaya pemanggil yang sudah memiliki resolver
     * (mis. `SubdomainManager`, tes dengan store temp) memakai store yang sama
     * tanpa memaksa membuat store default di lingkungan yang belum siap.
     *
     * @param array<string,mixed> $app
     * @param self|null $resolver resolver yang dipakai; null = store default
     */
    public static function domainForAppOrLocal(array $app, ?self $resolver = null): string
    {
        try {
            return ($resolver ?? new self())->domainForApp($app);
        } catch (Throwable) {
            return self::configDomain();
        }
    }

    // ==================================================================
    // Predikat & accessor (murni, tanpa I/O)
    // ==================================================================

    /**
     * Server lokal? Berdasarkan `kind` (bukan sekadar id) — dokumen yang
     * mengaku ber-id `local` tapi ber-`kind` agent tetap BUKAN server lokal.
     *
     * @param array<string,mixed> $server
     */
    public static function isLocal(array $server): bool
    {
        return strtolower(trim(self::text($server['kind'] ?? ''))) === 'local';
    }

    /**
     * Proyeksi satu baris server untuk **pilihan di form** (kontrak view wizard
     * create — misi `ms-f1-create-server` & misi Frontend UI).
     *
     * Field yang keluar sengaja minimal dan **tidak rahasia**: `id`, `name`,
     * `kind`, `domain` (domain dasar efektif), `enabled`, `status`, dan
     * `capabilities` (jumlahnya saja tidak cukup untuk UI memutuskan — agent
     * yang belum siap harus terlihat). Token, PEM, `base_url`, dan `notes`
     * **tidak pernah** ikut: daftar ini dirender di HTML.
     *
     * @param array<string,mixed> $server
     * @return array{id:string,name:string,kind:string,domain:string,enabled:bool,status:string,capabilities:array<int,string>}
     */
    public static function option(array $server): array
    {
        $capabilities = [];
        foreach ((array) ($server['capabilities'] ?? []) as $capability) {
            $capability = trim(self::text($capability));
            if ($capability !== '') {
                $capabilities[$capability] = $capability;
            }
        }

        return [
            'id' => trim(self::text($server['id'] ?? '')),
            'name' => trim(self::text($server['name'] ?? '')),
            'kind' => self::isLocal($server) ? 'local' : 'agent',
            'domain' => self::domainFor($server),
            'enabled' => self::isEnabled($server),
            'status' => strtolower(trim(self::text($server['status'] ?? ''))),
            'capabilities' => array_values($capabilities),
        ];
    }

    /**
     * Server siap menerima app baru? **Fail-fast** — dipanggil jalur create
     * sebelum efek samping apa pun (misi `ms-f1-create-server`).
     *
     * Aturan (plan §4.2 & keputusan §9.3):
     *  - server lokal selalu siap (tidak ada prasyarat koneksi);
     *  - `enabled=false` ⇒ ditolak (admin sengaja menonaktifkannya);
     *  - `status=offline` ⇒ ditolak (Test connection terakhir gagal);
     *  - `kind=agent` **wajib** sudah melaporkan seluruh
     *    {@see self::READY_CAPABILITIES} dari hasil Test connection
     *    (`GET /version`); capability kosong/tidak lengkap ⇒ ditolak dengan
     *    pesan yang menyuruh admin menjalankan **Test connection** dulu
     *    (tanpa nilai itu tidak ada bukti agent hidup & mendukung op dasar).
     *
     * Pesan galat memuat identitas server (nama + id, **tanpa** token/base_url)
     * agar admin tahu server mana yang harus diperbaiki.
     *
     * @param array<string,mixed> $server
     * @throws RuntimeException server belum siap dipakai untuk membuat app
     */
    public static function assertReadyForCreate(array $server): void
    {
        if (self::isLocal($server)) {
            return;
        }

        $label = self::label($server);

        if (!self::isEnabled($server)) {
            throw new RuntimeException(
                $label . ' dinonaktifkan (enabled=false) — aktifkan dulu di halaman Servers sebelum '
                . 'membuat app di sana.'
            );
        }

        if (strtolower(trim(self::text($server['status'] ?? ''))) === 'offline') {
            throw new RuntimeException(
                $label . ' berstatus offline — jalankan Test connection di halaman Servers dan pastikan '
                . 'agent dapat dihubungi sebelum membuat app di sana.'
            );
        }

        $reported = [];
        foreach ((array) ($server['capabilities'] ?? []) as $capability) {
            $capability = trim(self::text($capability));
            if ($capability !== '') {
                $reported[$capability] = true;
            }
        }

        $missing = [];
        foreach (self::READY_CAPABILITIES as $capability) {
            if (!isset($reported[$capability])) {
                $missing[] = $capability;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                $label . ' belum siap menerima app baru: capability "'
                . implode('", "', $missing)
                . '" belum dilaporkan. Jalankan Test connection di halaman Servers dulu '
                . '(hasilnya menyimpan versi & capability agent).'
            );
        }
    }

    /**
     * Server aktif? Aturan **sama persis** dengan view form server
     * (`app/view/server/form.php`): nilai negatif eksplisit
     * (`0`/`false`/`no`/`off`/`tidak`/kosong) = non-aktif, selain itu aktif
     * (field absen ⇒ aktif — baris seed/registry lama tidak pernah terkunci).
     *
     * Sengaja tidak memakai `(bool)` langsung: `(bool) '0'` di PHP adalah
     * `false` **hanya** untuk string `"0"`, sedangkan `"false"`/`"off"` tetap
     * `true` — jebakan yang sudah dihindari `normalizeEnabled()`.
     *
     * @param array<string,mixed> $server
     */
    public static function isEnabled(array $server): bool
    {
        $raw = $server['enabled'] ?? '1';
        if (!is_scalar($raw)) {
            return true; // dokumen rusak → jangan mengunci server tanpa alasan
        }

        return !in_array(strtolower(trim((string) $raw)), ['', '0', 'false', 'no', 'off', 'tidak'], true);
    }

    /**
     * Domain dasar efektif server (keputusan 4: satu `APP_DOMAIN` per server;
     * subdomain app = `{subdomain}.{domain}`). Kosong ⇒ `config('deploy.app_domain')`
     * — jadi server lokal tetap memakai konfigurasi dashboard seperti sekarang.
     *
     * @param array<string,mixed> $server
     */
    public static function domainFor(array $server): string
    {
        $domain = strtolower(rtrim(trim(self::text($server['domain'] ?? '')), '.'));
        if ($domain !== '') {
            return $domain;
        }

        return self::configDomain();
    }

    /**
     * Domain dasar dari konfigurasi dashboard (`APP_DOMAIN`) — dipakai sebagai
     * fallback server ber-`domain` kosong dan oleh varian toleran
     * {@see self::domainForAppOrLocal()} saat registry tidak terbaca.
     */
    public static function configDomain(): string
    {
        $fallback = strtolower(rtrim(trim((string) config('deploy.app_domain', 'example.com')), '.'));

        return $fallback !== '' ? $fallback : 'example.com';
    }

    /**
     * Rentang port host efektif server. Dokumen server menang; bila tidak ada
     * ⇒ `config('deploy.port_range')`.
     *
     * Dokumen yang ber-`port_range` **cacat** (bukan array/pasangan angka,
     * di luar 1–65535, `start >= end`) MELEMPAR — bukan diam-diam jatuh ke
     * rentang config, karena alokasi port dari rentang yang salah adalah bug
     * senyap. Pesannya memuat identitas server agar mudah didiagnosis.
     *
     * @param array<string,mixed> $server
     * @return array{start:int,end:int}
     * @throws RuntimeException bila `port_range` server cacat
     */
    public static function portRange(array $server): array
    {
        $raw = $server['port_range'] ?? null;
        if (self::isEmptyRange($raw)) {
            return self::configPortRange();
        }

        try {
            return self::normalizePortRange($raw) ?? self::configPortRange();
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                self::label($server) . ' punya `port_range` yang tidak valid: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Alamat dasar agent. Wajib ada, absolut, dan `https://` untuk server
     * `kind=agent` (keputusan 5: tidak ada fallback plain HTTP) — selain itu
     * MELEMPAR. Trailing slash dinormalkan (tanpa `/`).
     *
     * @param array<string,mixed> $server
     * @throws RuntimeException base_url kosong / bukan https absolut
     */
    public static function baseUrl(array $server): string
    {
        $baseUrl = trim(self::text($server['base_url'] ?? ''));
        if (self::isLocal($server)) {
            return $baseUrl;
        }

        return self::assertAgentBaseUrl($baseUrl, $server);
    }

    /**
     * Token bearer agent (alias lama: `token_ref`). Wajib non-kosong untuk
     * `kind=agent`.
     *
     * Jangan pernah menuliskan nilai kembalian ke log/pesan galat (rahasia).
     *
     * @param array<string,mixed> $server
     * @throws RuntimeException token kosong pada server agent
     */
    public static function token(array $server): string
    {
        $token = trim(self::text($server['token'] ?? $server['token_ref'] ?? ''));
        if ($token === '' && !self::isLocal($server)) {
            throw new RuntimeException(
                self::label($server) . ': token agent kosong — panggilan ke agent ditolak (fail-fast). '
                . 'Isi ulang di halaman Servers dari keluaran agent/install.sh.'
            );
        }

        return $token;
    }

    /**
     * PEM sertifikat TLS agent. Wajib ada & benar-benar sertifikat X.509 untuk
     * `kind=agent` (tanpa PEM, pinning tidak mungkin ⇒ tidak ada `verify=false`).
     *
     * @param array<string,mixed> $server
     * @throws RuntimeException PEM kosong atau tidak bisa dibaca sebagai X.509
     */
    public static function tlsCertPem(array $server): string
    {
        $pem = trim(self::text($server['tls_cert_pem'] ?? ''));
        if (self::isLocal($server)) {
            return $pem;
        }

        self::assertValidPem($pem, ' untuk ' . self::label($server));

        return $pem;
    }

    /**
     * Port TCP agent: port eksplisit pada `base_url`, jika tidak ada ⇒
     * {@see self::DEFAULT_AGENT_PORT} (`8443`, plan §10.6). Untuk server lokal
     * nilai ini tidak bermakna (dipakai hanya sebagai default aman).
     *
     * @param array<string,mixed> $server
     * @throws RuntimeException bila server agent tidak punya base_url sah
     */
    public static function agentPort(array $server): int
    {
        $baseUrl = self::baseUrl($server);
        if ($baseUrl === '') {
            return self::DEFAULT_AGENT_PORT;
        }

        $port = parse_url($baseUrl, PHP_URL_PORT);
        if (is_int($port) && $port >= self::MIN_PORT && $port <= self::MAX_PORT) {
            return $port;
        }

        return self::DEFAULT_AGENT_PORT;
    }

    /**
     * Fingerprint SHA-256 sertifikat (hex huruf kecil, tanpa `:`).
     *
     * Dipakai form Add Server (keputusan 6 / plan §11.5): dashboard menghitung
     * fingerprint dari PEM yang ditempel admin, sehingga **tidak ada peluang
     * salah ketik**; nilainya tetap ditampilkan untuk verifikasi manual.
     *
     * @throws RuntimeException PEM bukan sertifikat X.509 / gagal dihitung
     */
    public static function fingerprintFromPem(string $pem): string
    {
        $pem = trim($pem);
        self::assertValidPem($pem, '');

        $cert = @openssl_x509_read($pem);
        if ($cert === false) {
            throw new RuntimeException('Fingerprint sertifikat tidak bisa dihitung: PEM tidak bisa dibaca sebagai X.509.');
        }

        $fingerprint = @openssl_x509_fingerprint($cert, 'sha256');
        if (!is_string($fingerprint) || trim($fingerprint) === '') {
            throw new RuntimeException('Fingerprint SHA-256 sertifikat tidak bisa dihitung.');
        }

        return strtolower(str_replace(':', '', trim($fingerprint)));
    }

    // ==================================================================
    // Validasi form/CRUD server (dipakai F0 UI & F1)
    // ==================================================================

    /**
     * Normalisasi + validasi payload form server. **Hanya** boleh membuat
     * server `kind=agent` dari input user: server lokal adalah milik sistem
     * (hanya satu, yaitu "server ini sendiri").
     *
     * Aturan:
     *  - `name` wajib, slug huruf kecil/angka/`-`, ≤ 32 karakter (dinormalkan
     *    trim + lowercase); keunikan nama tetap ditegakkan `ServerStore::create()`;
     *  - `kind` default `agent`; nilai `local`/tak dikenal DITOLAK;
     *  - `base_url` wajib absolut `https://` (tanpa query/fragment/userinfo);
     *  - `token` wajib non-kosong;
     *  - `tls_cert_pem` wajib PEM X.509 valid; `tls_fingerprint` dihitung di sini;
     *  - `domain` opsional (kosong ⇒ ikut `APP_DOMAIN`);
     *  - `port_range` opsional: array `{start,end}` atau teks `"31000-31999"`;
     *    wajib 1–65535, `start < end`, dan **tidak** tumpang tindih rentang
     *    efektif server lokal (bila diberikan);
     *  - `notes`/`enabled` opsional.
     *
     * Mengembalikan payload **ternormalisasi** yang hanya berisi field yang
     * dikenal (field asing — termasuk `id`/`created_at` — dibuang, jadi payload
     * tidak bisa dipakai untuk memalsukan identitas/jejak waktu). Rahasia
     * (token/PEM) tidak pernah ditulis ke log/pesan galat.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws RuntimeException pesan bahasa Indonesia yang siap ditampilkan ke user
     */
    public function validate(array $payload): array
    {
        $name = strtolower(trim(self::text($payload['name'] ?? '')));
        if ($name === '') {
            throw new RuntimeException('Nama server wajib diisi.');
        }
        if (strlen($name) > self::MAX_NAME_LENGTH || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RuntimeException(
                'Nama server hanya boleh huruf kecil a-z, angka, dan strip (-), maksimal '
                . self::MAX_NAME_LENGTH . ' karakter, serta diawali & diakhiri huruf/angka.'
            );
        }

        $kind = strtolower(trim(self::text($payload['kind'] ?? '')));
        if ($kind === '') {
            $kind = 'agent';
        }
        if ($kind === 'local') {
            throw new RuntimeException(
                'Server lokal tidak bisa dibuat dari form: `kind=local` hanya milik sistem (satu server lokal = server ini sendiri).'
            );
        }
        if ($kind !== 'agent') {
            throw new RuntimeException("Jenis server \"{$kind}\" tidak dikenal; nilai yang sah: agent.");
        }

        $baseUrl = self::assertAgentBaseUrl(trim(self::text($payload['base_url'] ?? '')), []);

        $token = trim(self::text($payload['token'] ?? $payload['token_ref'] ?? ''));
        if ($token === '') {
            throw new RuntimeException(
                'Token agent wajib diisi (salin dari keluaran agent/install.sh — hanya ditampilkan sekali di server itu).'
            );
        }

        $pem = trim(self::text($payload['tls_cert_pem'] ?? ''));
        if ($pem === '') {
            throw new RuntimeException(
                'PEM sertifikat TLS agent wajib diisi (salin dari keluaran agent/install.sh).'
            );
        }

        $server = [
            'name' => $name,
            'kind' => $kind,
            'enabled' => self::normalizeEnabled($payload['enabled'] ?? true),
            'domain' => self::normalizeDomain(self::text($payload['domain'] ?? '')),
            'notes' => self::normalizeNotes($payload['notes'] ?? ''),
            'base_url' => $baseUrl,
            'token' => $token,
            'tls_cert_pem' => $pem,
            // Fingerprint dihitung dashboard (bukan diketik user) — sekaligus
            // memvalidasi PEM; nilai itu dipakai UI untuk verifikasi manual.
            'tls_fingerprint' => self::fingerprintFromPem($pem),
        ];

        $range = self::normalizePortRange($payload['port_range'] ?? null);
        if ($range !== null) {
            self::assertNoLocalOverlap($range, self::portRange($this->local()));
            $server['port_range'] = $range;
        }

        return $server;
    }

    /**
     * Normalisasi + validasi payload untuk **baris server lokal** (diri sendiri).
     *
     * Server lokal bukan server agent: ia tidak punya `base_url`/token/PEM, dan
     * sebagian nilainya berasal dari `.env`/`config('deploy.*')`:
     *
     *  - **boleh diubah**: `domain` (opsional; dikosongkan ⇒ ikut `APP_DOMAIN`)
     *    dan `notes` (teks, ≤ {@see self::MAX_NOTES_LENGTH});
     *  - **dikunci**: `name`, `kind`, `base_url`, `token`, `tls_cert_pem`,
     *    `enabled`, `port_range`. Field yang dikirim dengan nilai **setara**
     *    (`"1"`/`on`/`true` untuk boolean, `"31000-31999"` ≡ `{start,end}` untuk
     *    rentang port) = **no-op**, bukan perubahan; nilai yang **berbeda**
     *    ditolak `RuntimeException` yang menyebut **nama** field-nya. Karena
     *    `kind` ada di daftar itu, `kind` tidak mungkin berubah dari `local`.
     *    Field yang tidak dikirim sama sekali tidak dianggap berubah.
     *
     * `port_range` **sengaja** dikunci: untuk baris lokal sumber kebenaran
     * rentang port adalah `.env` (`PORT_RANGE_START`/`PORT_RANGE_END`, lihat
     * `config('deploy.port_range')`), bukan registry server. `validate()` juga
     * tidak bisa dipakai di sini karena ia membandingkan rentang kandidat dengan
     * rentang server lokal itu sendiri (selalu dianggap "tumpang tindih") —
     * membuka `port_range` lewat jalur lain akan menciptakan sumber kebenaran
     * kedua untuk hal yang sama.
     *
     * Mengembalikan payload ternormalisasi berisi **hanya** field yang boleh
     * berubah **dan** memang dikirim (field yang absen tidak ditulis ulang oleh
     * pemanggil, jadi nilai tersimpan tidak hilang). Rahasia (`token`/PEM) tidak
     * pernah masuk pesan galat: yang dibandingkan tetap nilainya, tetapi yang
     * disebut dalam pesan hanya **nama** field-nya — tidak ada nilai, tak ada log.
     *
     * @param array<string,mixed> $payload payload form (hanya field yang dikirim)
     * @param array<string,mixed> $local   dokumen baris lokal yang tersimpan
     * @return array<string,mixed>
     * @throws RuntimeException pesan bahasa Indonesia siap ditampilkan ke user
     */
    public static function validateLocal(array $payload, array $local): array
    {
        $changed = [];
        foreach (self::LOCAL_LOCKED as $field) {
            if (!array_key_exists($field, $payload)) {
                continue; // field tidak dikirim = tidak diubah
            }
            if (!self::localFieldUnchanged($field, $local[$field] ?? null, $payload[$field])) {
                $changed[] = $field;
            }
        }

        if ($changed !== []) {
            throw new RuntimeException(sprintf(
                'Server lokal adalah dashboard ini sendiri: field %s tidak bisa diubah dari halaman ini '
                . '(konfigurasinya datang dari .env/config). Yang bisa disesuaikan: %s.',
                implode(', ', $changed),
                implode(' & ', self::LOCAL_EDITABLE)
            ));
        }

        $out = [];
        if (array_key_exists('domain', $payload)) {
            $out['domain'] = self::normalizeDomain(self::text($payload['domain']));
        }
        if (array_key_exists('notes', $payload)) {
            $out['notes'] = self::normalizeNotes($payload['notes']);
        }

        return $out;
    }

    // ==================================================================
    // Helper privat
    // ==================================================================

    /**
     * Snapshot registry server dengan memo per-proses (lihat {@see self::$memo}).
     *
     * Urutan penting: cap berkas dibaca **sebelum** dan **sesudah** kueri; hasil
     * hanya dimoisasi bila kedua cap sama. Bila berkas berubah di tengah
     * pembacaan, hasilnya bisa berasal dari dua keadaan berbeda — lebih baik
     * dibuang (dibaca lagi pada panggilan berikutnya) daripada disajikan basi.
     *
     * `clearstatcache()` dipanggil per cap: worker Webman persistent menyimpan
     * cache `stat` per proses, sehingga tanpa itu berkas yang berubah tidak
     * akan terlihat berubah.
     *
     * @return array<int,array<string,mixed>>
     */
    private function snapshot(): array
    {
        $file = $this->servers->path();
        $before = self::fileStamp($file);

        $cached = self::$memo[$file] ?? null;
        if ($cached !== null && $cached['stamp'] === $before) {
            return $cached['servers'];
        }

        $servers = $this->servers->all();
        if (self::fileStamp($file) === $before) {
            self::$memo[$file] = ['stamp' => $before, 'servers' => $servers];
        }

        return $servers;
    }

    /**
     * Cari server (termasuk baris lokal) di dalam snapshot.
     *
     * @return array<string,mixed>|null
     */
    private function findInSnapshot(string $id): ?array
    {
        foreach ($this->snapshot() as $server) {
            if ((string) ($server['id'] ?? '') === $id) {
                return $server;
            }
        }

        return null;
    }

    /**
     * Cap berkas basis data untuk validitas memo: `mtime:size:inode` berkas
     * utama + `-wal` + `-shm`.
     *
     * WAL wajib ikut: SQLite mode WAL menulis ke `-wal` dan menyentuh berkas
     * utama hanya saat checkpoint, sehingga `mtime`/`size` berkas utama saja
     * tidak cukup untuk mendeteksi commit (apalagi yang panjangnya kebetulan
     * sama dan terjadi dalam detik yang sama). Inode ikut supaya berkas yang
     * DIGANTI (restore/rename atomik) langsung dianggap berbeda.
     */
    private static function fileStamp(string $file): string
    {
        $parts = [];
        foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
            clearstatcache(true, $path);
            $stat = @stat($path);
            $parts[] = $stat === false ? '-' : $stat['mtime'] . ':' . $stat['size'] . ':' . $stat['ino'];
        }

        return implode('|', $parts);
    }

    /**
     * Ambil nilai sebagai string tanpa konversi liar: array/objek/null ⇒ ''
     * (pemanggil akan menolaknya sebagai "kosong" dengan pesan yang jelas),
     * sehingga tidak ada warning "Array to string conversion".
     */
    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return '';
    }

    /**
     * Label server untuk pesan galat (naik ke id bila nama kosong).
     *
     * @param array<string,mixed> $server
     */
    private static function label(array $server): string
    {
        $name = trim(self::text($server['name'] ?? ''));
        $id = trim(self::text($server['id'] ?? ''));
        if ($name !== '' && $id !== '') {
            return "Server \"{$name}\" ({$id})";
        }
        if ($name !== '') {
            return "Server \"{$name}\"";
        }

        return $id !== '' ? "Server ({$id})" : 'Server tanpa id';
    }

    /** @throws RuntimeException bila bukan PEM X.509 yang bisa dibaca */
    private static function assertValidPem(string $pem, string $where): void
    {
        if ($pem === '') {
            throw new RuntimeException(
                "PEM sertifikat TLS agent{$where} belum diisi — ambil dari keluaran agent/install.sh di server itu."
            );
        }
        if (!str_contains($pem, '-----BEGIN CERTIFICATE-----') || @openssl_x509_read($pem) === false) {
            throw new RuntimeException(
                "PEM sertifikat TLS agent{$where} tidak bisa dibaca (bukan sertifikat X.509 valid). "
                . 'Salin ulang seluruh blok -----BEGIN CERTIFICATE----- … -----END CERTIFICATE-----.'
            );
        }
    }

    /**
     * Validasi alamat agent: absolut `https://host[:port]`, tanpa userinfo,
     * query, fragment, atau spasi. Mengembalikan bentuk ternormalisasi (trailing
     * slash dibuang).
     *
     * @param array<string,mixed> $server untuk identitas di pesan galat
     * @throws RuntimeException
     */
    private static function assertAgentBaseUrl(string $baseUrl, array $server): string
    {
        $prefix = $server === [] ? '' : self::label($server) . ': ';
        if ($baseUrl === '') {
            throw new RuntimeException($prefix . 'alamat agent (base_url) wajib diisi, mis. https://10.0.0.12:8443.');
        }
        if (preg_match('/\s/', $baseUrl) === 1) {
            throw new RuntimeException($prefix . 'alamat agent tidak boleh mengandung spasi.');
        }

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($scheme === 'http') {
            throw new RuntimeException(
                $prefix . 'alamat agent wajib https:// — TLS ter-pin adalah keputusan yang dikunci, '
                . 'tidak ada mode plain HTTP.'
            );
        }

        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        if ($scheme !== 'https' || $host === '') {
            throw new RuntimeException(
                $prefix . 'alamat agent tidak valid: harus absolut https://host[:port] (tanpa path, query, atau fragment).'
            );
        }
        if ($port !== null && (!is_int($port) || $port < self::MIN_PORT || $port > self::MAX_PORT)) {
            throw new RuntimeException($prefix . 'port pada alamat agent tidak valid (harus 1–65535).');
        }
        if (parse_url($baseUrl, PHP_URL_USER) !== null || parse_url($baseUrl, PHP_URL_PASS) !== null) {
            throw new RuntimeException($prefix . 'alamat agent tidak boleh memuat kredensial user:pass.');
        }
        if (parse_url($baseUrl, PHP_URL_QUERY) !== null || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException($prefix . 'alamat agent tidak boleh memuat query atau fragment.');
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * Rentang port config (`deploy.port_range`) sebagai bentuk baku.
     *
     * @return array{start:int,end:int}
     */
    private static function configPortRange(): array
    {
        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $start = is_array($range) ? (int) ($range['start'] ?? 30000) : 30000;
        $end = is_array($range) ? (int) ($range['end'] ?? 30999) : 30999;

        return ['start' => $start, 'end' => $end];
    }

    /** `port_range` dianggap tidak ada? (null, '', array kosong) */
    private static function isEmptyRange(mixed $raw): bool
    {
        if ($raw === null) {
            return true;
        }
        if (is_string($raw)) {
            return trim($raw) === '';
        }
        if (is_array($raw)) {
            return $raw === [];
        }

        return false;
    }

    /**
     * Normalisasi `port_range` dari form: array `{start,end}` atau teks
     * `"31000-31999"` (`-`, `..`, atau `:` sebagai pemisah). Mengembalikan null
     * bila tidak diisi; MELEMPAR bila bentuk/nilainya tidak sah.
     *
     * @return array{start:int,end:int}|null
     * @throws RuntimeException
     */
    private static function normalizePortRange(mixed $raw): ?array
    {
        if (self::isEmptyRange($raw)) {
            return null;
        }

        $start = null;
        $end = null;
        if (is_array($raw)) {
            $start = self::intFrom($raw['start'] ?? null);
            $end = self::intFrom($raw['end'] ?? null);
        } elseif (is_string($raw)) {
            $parts = preg_split('/\s*(?:-|\.\.|:)\s*/', trim($raw)) ?: [];
            if (count($parts) === 2) {
                $start = self::intFrom($parts[0]);
                $end = self::intFrom($parts[1]);
            }
        }

        if ($start === null || $end === null
            || $start < self::MIN_PORT || $end > self::MAX_PORT || $start >= $end
        ) {
            throw new RuntimeException(
                'Rentang port server harus 1–65535 dengan start < end (mis. 31000–31999).'
            );
        }

        return ['start' => $start, 'end' => $end];
    }

    /** Angka bulat dari input form (string digit atau int); selain itu null. */
    private static function intFrom(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,5}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * Fail-fast bentrok rentang dengan server lokal.
     *
     * Catatan keputusan: port host sebenarnya **per host**, jadi tumpang tindih
     * antar-server secara teknis tidak berbahaya. Tetapi rentang server lokal
     * juga jadi acuan "satu port = satu sumber" (alokasi port app, proxy,
     * monitoring) di control plane; membiarkan rentang identik membuat dashboard
     * tidak bisa membedakan asal port. Karena itu tumpang tindih ditolak dan
     * admin diminta memilih rentang lain (boleh dilonggarkan di fase berikutnya
     * bila terbukti mengganggu).
     *
     * @param array{start:int,end:int} $range
     * @param array{start:int,end:int} $local
     * @throws RuntimeException
     */
    private static function assertNoLocalOverlap(array $range, array $local): void
    {
        if ($range['start'] <= $local['end'] && $range['end'] >= $local['start']) {
            throw new RuntimeException(sprintf(
                'Rentang port %d–%d tumpang tindih dengan rentang server lokal (%d–%d); pilih rentang lain.',
                $range['start'],
                $range['end'],
                $local['start'],
                $local['end']
            ));
        }
    }

    /**
     * Normalisasi domain dasar dari form (lowercase, tanpa titik akhir).
     * Kosong = ikut `APP_DOMAIN` (keputusan 4).
     *
     * @throws RuntimeException
     */
    private static function normalizeDomain(string $raw): string
    {
        $domain = strtolower(rtrim(trim($raw), '.'));
        if ($domain === '') {
            return '';
        }
        if (strlen($domain) > self::MAX_DOMAIN_LENGTH
            || str_contains($domain, '://')
            || str_contains($domain, '/')
            || str_contains($domain, ':')
            || preg_match(self::DOMAIN_PATTERN, $domain) !== 1
        ) {
            throw new RuntimeException(
                'Domain server hanya boleh nama domain/IP (huruf kecil, angka, titik, strip) tanpa skema/path/port — '
                . 'atau dikosongkan agar mengikuti APP_DOMAIN.'
            );
        }

        return $domain;
    }

    /**
     * Normalisasi `enabled` (default true). String "0"/"false" tidak boleh
     * dianggap true (jebakan `(bool)` pada string).
     *
     * @throws RuntimeException
     */
    private static function normalizeEnabled(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw !== 0;
        }
        if (is_string($raw)) {
            $value = strtolower(trim($raw));
            if (in_array($value, ['1', 'true', 'yes', 'on', 'ya'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off', 'tidak'], true)) {
                return false;
            }
        }

        throw new RuntimeException('Nilai `enabled` server harus ya/tidak (true/false).');
    }

    /**
     * Normalisasi catatan server (opsional, teks pendek).
     *
     * @throws RuntimeException
     */
    private static function normalizeNotes(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            throw new RuntimeException('Catatan server harus berupa teks.');
        }

        $notes = trim((string) $raw);
        if (strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new RuntimeException('Catatan server maksimal ' . self::MAX_NOTES_LENGTH . ' karakter.');
        }

        return $notes;
    }

    /**
     * Field terkunci dianggap **tidak berubah**? Perbandingan longgar untuk bentuk
     * yang setara (boolean `"1"`/`on`/`true`, rentang port `"31000-31999"` ≡
     * `{start,end}`) supaya form yang mengirim ulang nilai yang sama tidak
     * ditolak; selebihnya perbandingan teks (trim, lowercase).
     *
     * Nilai tidak pernah masuk pesan galat — helper ini hanya mengembalikan bool.
     */
    private static function localFieldUnchanged(string $field, mixed $stored, mixed $submitted): bool
    {
        if ($field === 'enabled') {
            return self::boolText($stored) === self::boolText($submitted);
        }
        if ($field === 'port_range') {
            return self::rangeText($stored) === self::rangeText($submitted);
        }
        if ($field === 'base_url' || $field === 'tls_cert_pem') {
            return trim(self::text($stored)) === trim(self::text($submitted));
        }

        return strtolower(trim(self::text($stored))) === strtolower(trim(self::text($submitted)));
    }

    /** Bentuk baku boolean form (`"1"`/`"0"`, menerima `on`/`true`/`ya`, …). */
    private static function boolText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = strtolower(trim(self::text($value)));
        if (in_array($text, ['1', 'true', 'yes', 'on', 'ya'], true)) {
            return '1';
        }
        if (in_array($text, ['0', 'false', 'no', 'off', 'tidak', ''], true)) {
            return '0';
        }

        return $text;
    }

    /**
     * Bentuk teks rentang port (`"31000-31999"`) dari array `{start,end}` maupun
     * teks bebas (`-`, `..`, atau `:` sebagai pemisah) — dipakai **hanya** untuk
     * membandingkan nilai terkunci, bukan untuk validasi/penyimpanan.
     */
    private static function rangeText(mixed $value): string
    {
        if (is_array($value)) {
            return trim(self::text($value['start'] ?? '')) . '-' . trim(self::text($value['end'] ?? ''));
        }

        $parts = preg_split('/\s*(?:-|\.\.|:)\s*/', trim(self::text($value))) ?: [];

        return count($parts) === 2 ? trim($parts[0]) . '-' . trim($parts[1]) : trim(self::text($value));
    }
}
