<?php
declare(strict_types=1);

namespace app\library\Agent;

use app\library\Deploy\ComposeSource;
use app\library\Git\SshKeyManager;
use RuntimeException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Kumpulkan **artefak app** di sisi **control plane** untuk dikirim bersama
 * payload job ke server agent (misi `ms-f1-artifacts`, plan §4.3/§5 F1).
 *
 * Sebab: agent hanya memegang proyeksi metadata app (§4.3) + direktori
 * artefaknya sendiri. Tiga hal yang **tidak** bisa diregenerasi agent dari
 * metadata saja — dan karenanya harus dikirim — adalah:
 *
 *  1. **file compose utama** app mode `compose` (di-paste/di-upload user; hanya
 *     ada di control plane, `apps/<name>/`),
 *  2. **deploy key SSH** app repo private (`database/keys/`; `GitService` agent
 *     butuh kunci itu untuk `git clone`/`git fetch`),
 *  3. **slug template** app yang dibuat dari katalog template (`templates/<slug>/`
 *     dibaca agent dari checkout-nya sendiri — lihat catatan pemilihan di bawah).
 *  4. **berkas override port** (`files`) hasil **pilihan host port user** —
 *     dibangkitkan di sini dari rencana port app (`host_ports`, misi F1 celah B)
 *     supaya agent tidak memakai port bawaan repo. Untuk app **lokal** berkas
 *     yang sama sudah ditulis di disk oleh jalur create/save; untuk app **agent**
 *     host ini tidak menyentuh `apps_path` app itu, jadi isinya dikirim lewat
 *     `artifacts.files` dan ditulis `AgentArtifactMaterializer` di server agent.
 *
 * Kontrak keluaran {@see self::pack()} = **satu-satunya** bentuk yang dipahami
 * {@see AgentArtifactMaterializer} di agent (kunci-kuncinya
 * {@see self::KEYS}); jangan menambah kunci tanpa mengubah keduanya:
 *
 * ```php
 * [
 *   'compose' => ?string,      // isi mentah file compose utama
 *   'compose_name' => ?string, // nama file (relatif apps/<name>/)
 *   'template' => ?string,     // slug template (agent membaca templates/<slug>/)
 *   'ssh_key' => ?string,      // isi PRIVATE key deploy (rahasia!)
 *   'files' => array<string,string>, // override port (reset + ports) — lihat bawah
 * ]
 * ```
 *
 * **Pemilihan `compose`: master control plane lebih dulu.** App mode compose bisa
 * disunting dari tab Compose di dashboard (termasuk untuk app di server agent,
 * `AppController::saveCompose()`); master-nya disimpan di metadata app
 * (`compose_content`) sehingga tidak bergantung pada keberadaan direktori
 * artefak di host ini. Bila metadata itu kosong (app lama / dibuat wizard yang
 * memang menulis master ke `apps/<name>/`), berkas di disk yang dipakai.
 * Selebihnya perilaku lama tidak berubah — file yang hilang **tetap** melempar
 * (fail-fast) supaya job tidak dikirim setengah lengkap.
 *
 * **Pemilihan `template`: slug, bukan isi berkas.** Untuk app ber-template hanya
 * `template` (slug) yang dikirim; agent membaca `templates/<slug>/` miliknya
 * sendiri lewat jalur materialisasi yang **sama persis** dengan yang dipakai
 * control plane saat create (`TemplateCatalog::materialize()`). Alasannya:
 * template adalah bagian codebase yang sama (`templates/` ada di checkout
 * agent), jadi tidak ada jalur kode kedua yang bisa menyimpang, payload tetap
 * kecil, dan berkas pendukung template (`files/`) ikut serta tanpa perlu
 * disalin ulang. Konsekuensinya agent **wajib** punya slug yang sama di
 * checkout-nya; bila tidak, materialisasi **fail-fast** dengan pesan jelas
 * (bukan deploy setengah jadi) — drift itu ditutup fase template pull (F4).
 * Karena itu `files` sengaja dibiarkan kosong di sini: berkas pendukung
 * (non-template) belum dilacak per app (lihat batas di bawah).
 *
 * `source=repo`/`git` + `auth_method=ssh` ⇒ `ssh_key` = isi private key app.
 * Absen (repo publik, atau berkas kunci hilang) ⇒ `null` (kontrak; agent tidak
 * boleh mengarang kunci).
 *
 * **Berkas pendukung template** (mis. `nginx.conf`) tidak disalin dari control
 * plane: template adalah bagian codebase yang sama (`templates/` ada di checkout
 * agent) dan materialisasinya lewat jalur yang sama persis dengan create
 * (`TemplateCatalog::materialize()`), sehingga agent **wajib** punya slug itu di
 * checkout-nya; bila tidak, materialisasi **fail-fast** dengan pesan jelas
 * (drift itu ditutup fase template pull, F4). Karena itu `files` tidak dipakai
 * untuk berkas pendukung: isinya **hanya** override port (lihat atas).
 *
 * `source=repo`/`git` + `auth_method=ssh` ⇒ `ssh_key` = isi private key app.
 * Absen (repo publik, atau berkas kunci hilang) ⇒ `null` (kontrak; agent tidak
 * boleh mengarang kunci).
 *
 * **Batas yang diketahui fase ini** (bukan bug yang disembunyikan):
 *  - berkas pendukung app mode compose (mis. `nginx.conf` yang di-bind mount)
 *    belum dikirim — hanya file compose utama; jalur upload/hapus berkas
 *    pendukung untuk app di server agent ditolak eksplisit di `AppController`
 *    (bukan dibuang diam-diam);
 *  - berkas non-UTF-8 tidak didukung (payload JSON); materi seperti itu ditolak
 *    di sini, bukan dikirim korup oleh `JSON_INVALID_UTF8_SUBSTITUTE`.
 *
 * **Higienitas rahasia**: `ssh_key` hanya muncul di nilai kembalian `pack()`
 * (payload job) — **tidak pernah** di pesan exception, log, atau rekaman job.
 * Override port yang dikirim tidak pernah memuat nilai env/rahasia: berkas
 * `docker-compose.override.env.yml` **tidak** ikut (agent menulisnya sendiri dari
 * metadata `env` miliknya).
 *
 * Stateless & instansi (bukan statik murni) karena path di-override saat tes
 * (pola `EnvManager`/`TemplateCatalog`); path default dibaca dari config yang
 * ada (`deploy.apps_path`, `deploy.database_path`, `SshKeyManager::keysDir()`),
 * tidak ada path yang di-hardcode.
 */
final class AgentArtifactPackager
{
    /** Kunci artifact — kontrak lintas-sisi (control plane ↔ agent). */
    public const KEY_COMPOSE = 'compose';
    public const KEY_COMPOSE_NAME = 'compose_name';
    public const KEY_TEMPLATE = 'template';
    public const KEY_SSH_KEY = 'ssh_key';
    public const KEY_FILES = 'files';

    /**
     * Kunci yang boleh ada di payload `artifacts` (dipakai
     * {@see AgentArtifactMaterializer::validate()} untuk menolak kunci asing).
     */
    public const KEYS = [
        self::KEY_COMPOSE,
        self::KEY_COMPOSE_NAME,
        self::KEY_TEMPLATE,
        self::KEY_SSH_KEY,
        self::KEY_FILES,
    ];

    /**
     * Berkas override port yang boleh dikirim di `files` — dua lapis yang sama
     * dengan jalur lokal ({@see ComposeSource::RESET_OVERRIDE_FILE} +
     * {@see ComposeSource::PORTS_OVERRIDE_FILE}). Override generated lain
     * (nama/limits/network/env) **tidak** boleh dikirim: agent menulisnya sendiri
     * dari metadata app.
     */
    public const PORT_OVERRIDE_FILES = [
        ComposeSource::RESET_OVERRIDE_FILE,
        ComposeSource::PORTS_OVERRIDE_FILE,
    ];

    /**
     * Batas ukuran artefak (pertahanan berlapis). Nilainya konstanta
     * {@see ComposeSource} yang sama dengan plafon unggahan compose di control
     * plane, sehingga apa yang lolos di sini pasti lolos di agent.
     */
    public const MAX_COMPOSE_BYTES = ComposeSource::MAX_FILE_BYTES;
    public const MAX_TOTAL_BYTES = ComposeSource::MAX_TOTAL_BYTES;
    public const MAX_FILES = 64;

    private ?string $appsPath;
    private ?string $keysDir;
    private ?string $databasePath;

    /**
     * @param string|null $appsPath     direktori app control plane (default `deploy.apps_path`)
     * @param string|null $keysDir      direktori deploy key (default `deploy.ssh_keys_path`)
     * @param string|null $databasePath direktori data (`keys/`, `env/`; default `deploy.database_path`)
     */
    public function __construct(?string $appsPath = null, ?string $keysDir = null, ?string $databasePath = null)
    {
        $this->appsPath = $appsPath;
        $this->keysDir = $keysDir;
        $this->databasePath = $databasePath;
    }

    /**
     * Kumpulkan artefak app untuk satu job.
     *
     * @param array<string,mixed> $app dokumen app control plane
     * @return array{compose:?string,compose_name:?string,template:?string,ssh_key:?string,files:array<string,string>}
     * @throws RuntimeException app tak bisa dipaketkan (nama/dokumen tak sah,
     *                          file compose hilang, kunci tak terbaca, terlalu besar,
     *                          rencana port `host_ports` tidak sah)
     */
    public function pack(array $app): array
    {
        $artifacts = [
            self::KEY_COMPOSE => null,
            self::KEY_COMPOSE_NAME => null,
            self::KEY_TEMPLATE => null,
            self::KEY_SSH_KEY => null,
            self::KEY_FILES => [],
        ];

        // Nama app wajib sah (dipakai sebagai segmen direktori di agent) —
        // diverifikasi SEBELUM apa pun dibaca dari disk.
        $dirName = self::appDirName($app);

        // Override port (pilihan host port user) ikut SETIAP job yang membawa
        // artefak — apa pun sumber app-nya (template/git/compose): agent tidak
        // bisa meregenerasinya dari metadata, jadi tanpa ini app memakai port
        // bawaan repo (`AgentArtifactMaterializer` menulisnya ke direktori app).
        $portFiles = $this->portOverrideFiles($app, $dirName);

        $slug = self::templateSlug($app);
        // Compose yang **disunting** user (tab Compose, `compose_content`) menang
        // atas provenance template: kalau tidak, agent akan mematerialisasi ulang
        // template-nya dan menimpa compose hasil edit (materialisasi template
        // berjalan setelah compose).
        if ($slug !== '' && !self::hasEditedCompose($app)) {
            // App ber-template: agent membaca katalog template miliknya sendiri
            // (lihat catatan kelas) — tidak ada isi berkas yang dikirim.
            if (preg_match(AgentArtifactMaterializer::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > 63) {
                throw new RuntimeException(
                    'App "' . $dirName . '" menyimpan slug template yang tidak sah — '
                    . 'deploy ke server agent dibatalkan (template tidak bisa dibaca agent).'
                );
            }
            $artifacts[self::KEY_TEMPLATE] = $slug;
            $artifacts[self::KEY_FILES] = $portFiles;

            return $artifacts;
        }

        if (ComposeSource::isCompose($app)) {
            [$artifacts[self::KEY_COMPOSE], $artifacts[self::KEY_COMPOSE_NAME]] = $this->composeOf($app);
            $artifacts[self::KEY_FILES] = $portFiles;

            return $artifacts;
        }

        $artifacts[self::KEY_SSH_KEY] = $this->sshKeyOf($app);
        $artifacts[self::KEY_FILES] = $portFiles;

        return $artifacts;
    }

    // ==================================================================
    // Override port (pilihan host port user)
    // ==================================================================

    /**
     * Berkas override port app — dua lapis sesuai kontrak jalur lokal
     * (`AppController::writeOverride()`): `docker-compose.override.yml` berisi
     * `ports: !reset []` per service (menghapus port bawaan repo) lalu
     * `docker-compose.override.ports.yml` berisi port final `host:container`.
     *
     * Sumber isinya, berurutan:
     *  1. metadata `host_ports` app (rencana host port pilihan user — ditulis
     *     control plane saat create/simpan compose untuk app di server agent);
     *  2. bila rencana tidak ada: berkas yang sudah ditulis jalur lokal di
     *     direktori app (`apps/<name>/docker-compose.override*.yml`) — app
     *     compose/template lama yang artefaknya memang ada di host ini.
     *
     * Kosong ⇒ `[]` (app tanpa port yang dipublikasikan ke host, atau tanpa
     * rencana & tanpa berkas lokal). **Tidak pernah** memuat berkas override
     * env (rahasia nilai env milik agent; agent menulisnya sendiri dari
     * metadata `env`).
     *
     * @param array<string,mixed> $app
     * @param string              $dirName nama direktori app (sudah divalidasi)
     * @return array<string,string> nama berkas ⇒ isi
     * @throws RuntimeException rencana `host_ports` tidak sah (fail-fast, jangan
     *                          mengirim port yang salah)
     */
    private function portOverrideFiles(array $app, string $dirName): array
    {
        $plan = self::hostPortPlan($app);
        if ($plan !== []) {
            return self::renderPortOverrides($plan);
        }

        $dir = $this->appsPath() . '/' . $dirName;
        $files = [];
        foreach (self::PORT_OVERRIDE_FILES as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                return [];
            }
            $content = @file_get_contents($path);
            if ($content === false || trim($content) === '') {
                return [];
            }
            $files[$name] = $content;
        }

        return $files;
    }

    /**
     * Rencana host port app dari metadata `host_ports` (bentuk kanonik:
     * daftar `{service, container, host}`; `host` boleh `null` untuk port yang
     * hanya diekspos tanpa publish ke host).
     *
     * Murni + defensif: entri yang tidak berbentuk map/angka ditolak
     * (`RuntimeException`), karena rencana yang salah akan menulis port yang
     * salah ke server app — lebih baik job tidak dikirim.
     *
     * @param array<string,mixed> $app
     * @return array<int,array{service:string,container:int,host:?int}>
     * @throws RuntimeException bentuk `host_ports` tidak sah
     */
    public static function hostPortPlan(array $app): array
    {
        $raw = $app['host_ports'] ?? null;
        if ($raw === null || $raw === [] || $raw === '') {
            return [];
        }
        if (!is_array($raw)) {
            throw new RuntimeException('Rencana port app (`host_ports`) tidak berbentuk daftar; deploy dibatalkan.');
        }

        $plan = [];
        foreach (array_values($raw) as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Rencana port app (`host_ports`) memuat entri yang tidak sah; deploy dibatalkan.');
            }
            $service = is_scalar($entry['service'] ?? null) ? trim((string) $entry['service']) : '';
            $container = (int) (is_scalar($entry['container'] ?? null) ? $entry['container'] : 0);
            $rawHost = $entry['host'] ?? null;
            $host = $rawHost === null ? null : (int) (is_scalar($rawHost) ? $rawHost : 0);

            if ($service === '' || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $service) !== 1
                || $container <= 0 || $container > 65535
                || ($host !== null && ($host <= 0 || $host > 65535))) {
                throw new RuntimeException(
                    'Rencana port app (`host_ports`) memuat service/port yang tidak sah; deploy dibatalkan '
                    . '(perbaiki lewat tab Compose lalu simpan ulang).'
                );
            }

            $plan[] = ['service' => $service, 'container' => $container, 'host' => $host];
        }

        return $plan;
    }

    /**
     * Render dua lapis override port dari rencana (dump YAML **identik** dengan
     * jalur lokal `AppController::writeOverride()`: `TaggedValue('reset', [])`
     * + `Yaml::dump(…, 4, 2)`), supaya berkas di server agent sama persis dengan
     * yang akan ditulis host ini untuk app lokal.
     *
     * @param array<int,array{service:string,container:int,host:?int}> $plan
     * @return array<string,string> [reset => yaml, ports => yaml]
     */
    private static function renderPortOverrides(array $plan): array
    {
        $reset = ['services' => []];
        $ports = ['services' => []];

        foreach ($plan as $entry) {
            $service = $entry['service'];
            $reset['services'][$service]['ports'] = new TaggedValue('reset', []);
            $ports['services'][$service]['ports'][] = $entry['host'] !== null
                ? $entry['host'] . ':' . $entry['container']
                : (string) $entry['container'];
        }

        return [
            ComposeSource::RESET_OVERRIDE_FILE => Yaml::dump($reset, 4, 2),
            ComposeSource::PORTS_OVERRIDE_FILE => Yaml::dump($ports, 4, 2),
        ];
    }

    // ==================================================================
    // Mode compose (paste/upload)
    // ==================================================================

    /**
     * Isi + nama file compose utama app mode compose.
     *
     * Master compose diambil dari metadata app (`compose_content`) bila ada —
     * itulah sumber kebenaran untuk app di server **agent** yang disunting lewat
     * tab Compose (host ini tidak menyimpan direktori artefaknya); kalau tidak,
     * dibaca dari direktori app control plane (perilaku lama).
     *
     * Nama file mengikuti `compose_files` app ({@see ComposeSource::resolveMainFile()}),
     * sehingga file yang ditulis agent adalah file yang sama dengan yang dipakai
     * `docker compose -f <file>` (bukan tebakan `docker-compose.yml`).
     *
     * @param array<string,mixed> $app
     * @return array{0:string,1:string} [isi, nama file]
     * @throws RuntimeException berkas tidak ada / tidak terbaca / terlalu besar
     */
    private function composeOf(array $app): array
    {
        $dir = $this->appsPath() . '/' . self::appDirName($app);
        $name = ComposeSource::resolveMainFile($dir, $app);

        $stored = $app['compose_content'] ?? null;
        if (is_string($stored) && trim($stored) !== '') {
            if (strlen($stored) > self::MAX_COMPOSE_BYTES) {
                throw new RuntimeException(
                    'File compose "' . $name . '" terlalu besar untuk dikirim ke server agent ('
                    . strlen($stored) . ' byte, maks ' . self::MAX_COMPOSE_BYTES . ' byte).'
                );
            }

            return [$stored, $name];
        }

        $path = $dir . '/' . $name;

        if (!is_file($path)) {
            throw new RuntimeException(
                'App mode compose tidak bisa dikirim ke server agent: file compose utama "' . $name
                . '" tidak ada di server ini (' . $path . '). Perbaiki lewat tab Compose (simpan ulang file) '
                . 'lalu jalankan deploy lagi — job tidak dikirim supaya agent tidak gagal di tengah.'
            );
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('File compose "' . $name . '" tidak bisa dibaca di server ini (' . $path . ').');
        }
        if (strlen($content) > self::MAX_COMPOSE_BYTES) {
            throw new RuntimeException(
                'File compose "' . $name . '" terlalu besar untuk dikirim ke server agent ('
                . strlen($content) . ' byte, maks ' . self::MAX_COMPOSE_BYTES . ' byte).'
            );
        }

        return [$content, $name];
    }

    // ==================================================================
    // Deploy key SSH
    // ==================================================================

    /**
     * Isi private key deploy app (repo private via SSH), atau `null` bila app
     * bukan repo SSH atau berkasnya tidak ada (kontrak: absen ⇒ null).
     *
     * Lokasi berkas memakai API yang ada — `SshKeyManager::keysDir()`/
     * `privateKeyPath()` membaca `deploy.ssh_keys_path` (tidak ada path
     * hardcode); relatif terhadap `deploy.database_path` bila app menyimpan
     * `ssh_key` (mis. `keys/<name>`) karena begitulah `LocalDeployer` agent
     * membacanya (`config('deploy.database_path') . '/' . $ssh_key`).
     *
     * Rahasia **hanya** masuk ke nilai kembalian; pesan galat tidak pernah
     * memuat isi kunci.
     *
     * @param array<string,mixed> $app
     */
    private function sshKeyOf(array $app): ?string
    {
        if ((string) ($app['auth_method'] ?? 'none') !== 'ssh') {
            return null;
        }

        $path = $this->sshKeyPath($app);
        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException(
                'Deploy key app "' . self::appDirName($app) . '" ada tapi tidak bisa dibaca (kosong/tidak terbaca) — '
                . 'buat ulang kunci lalu deploy lagi. Job tidak dikirim supaya clone di agent tidak gagal separuh jalan.'
            );
        }

        return $content;
    }

    /**
     * Path absolut private key deploy app (di server control plane).
     *
     * @param array<string,mixed> $app
     */
    private function sshKeyPath(array $app): string
    {
        $relative = is_scalar($app['ssh_key'] ?? null) ? trim((string) $app['ssh_key']) : '';
        if ($relative !== '') {
            // Nama relatif diverifikasi jalur amannya (tolak `..`, absolut, segmen aneh).
            return $this->databasePath() . '/' . ComposeSource::sanitizeName($relative);
        }

        return $this->keysDir() . '/' . self::keyName($app);
    }

    // ==================================================================
    // Helper (murni)
    // ==================================================================

    /**
     * Nama direktori app di `apps_path` — satu sumber dengan yang ditegakkan
     * {@see AgentArtifactMaterializer::appDirName()} di agent (jangan disalin).
     *
     * @param array<string,mixed> $app
     * @throws RuntimeException nama app kosong / berisi jalur
     */
    public static function appDirName(array $app): string
    {
        return AgentArtifactMaterializer::appDirName($app);
    }

    /**
     * Apakah app membawa master compose hasil suntingan dashboard
     * (`compose_content`, ditulis `AppController::saveComposeOnAgent()`)?
     *
     * Metadata ini hanya terisi untuk app di server agent (host control plane
     * tidak menyimpan direktori artefaknya), jadi keberadaannya menandakan user
     * memang pernah menyimpan compose sendiri — apa pun asal app-nya (template,
     * paste, atau upload).
     *
     * @param array<string,mixed> $app
     */
    private static function hasEditedCompose(array $app): bool
    {
        $stored = $app['compose_content'] ?? null;

        return is_string($stored) && trim($stored) !== '';
    }

    /**
     * Slug template app (dokumen `template` berbentuk map `['slug' => …]`;
     * bentuk string diterima sebagai toleransi data lama), atau `''`.
     *
     * @param array<string,mixed> $app
     */
    public static function templateSlug(array $app): string
    {
        $raw = $app['template'] ?? null;
        if (is_array($raw)) {
            $raw = $raw['slug'] ?? '';
        }

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    /**
     * Nama berkas kunci milik app: basename `ssh_key` app, fallback nama app —
     * pola sama `SshKeyManager` (`{keys_path}/{name}`).
     *
     * @param array<string,mixed> $app
     */
    private static function keyName(array $app): string
    {
        $relative = is_scalar($app['ssh_key'] ?? null) ? trim((string) $app['ssh_key']) : '';
        $name = $relative !== ''
            ? basename(str_replace('\\', '/', $relative))
            : self::appDirName($app);

        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1 || $name === '.' || $name === '..') {
            throw new RuntimeException('Nama berkas deploy key app tidak sah; job tidak dikirim ke server agent.');
        }

        return $name;
    }

    /** Direktori app control plane (config `deploy.apps_path`). */
    private function appsPath(): string
    {
        $path = rtrim($this->appsPath ?? (string) config('deploy.apps_path'), '/');
        if ($path === '') {
            throw new RuntimeException('Konfigurasi `deploy.apps_path` kosong — artefak app tidak bisa dikumpulkan.');
        }

        return $path;
    }

    /** Direktori data (`keys/`, `env/`) control plane (config `deploy.database_path`). */
    private function databasePath(): string
    {
        $path = rtrim($this->databasePath ?? (string) config('deploy.database_path'), '/');
        if ($path === '') {
            throw new RuntimeException('Konfigurasi `deploy.database_path` kosong — deploy key tidak bisa dibaca.');
        }

        return $path;
    }

    /** Direktori deploy key (config `deploy.ssh_keys_path`, lewat `SshKeyManager`). */
    private function keysDir(): string
    {
        return rtrim($this->keysDir ?? (string) (new SshKeyManager())->keysDir(), '/');
    }
}
