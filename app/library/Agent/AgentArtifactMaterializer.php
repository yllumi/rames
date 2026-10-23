<?php
declare(strict_types=1);

namespace app\library\Agent;

use app\library\Deploy\ComposeSource;
use app\library\Deploy\EnvManager;
use app\library\Git\GitService;
use app\library\Git\SshKeyManager;
use app\library\Support\ProcessRunner;
use app\library\Template\TemplateCatalog;
use InvalidArgumentException;
use RuntimeException;

/**
 * Materialisasi **artefak app** di sisi **agent** (misi `ms-f1-artifacts`,
 * plan §4.3/§5 F1) — dipanggil `AgentJobController` **sebelum** worker
 * (`cli/deploy.php`) di-spawn.
 *
 * Sebab: worker agent langsung membaca direktori app (`apps/<name>/`) dan
 * deploy key (`database/keys/`). Bila artefak itu belum ada, deploy gagal *di
 * tengah* dengan pesan yang tidak menjelaskan apa pun (`docker compose` tidak
 * menemukan file, `git clone` tidak menemukan kunci). Maka:
 *
 *  - file compose utama app `source` compose/template ditulis **sebelum** spawn;
 *  - **berkas override port** pilihan user (`docker-compose.override.yml` +
 *    `docker-compose.override.ports.yml`, misi F1 celah B) ditulis dari
 *    `artifacts.files` — control plane tidak menulis berkas itu di host-nya untuk
 *    app agent, jadi inilah satu-satunya jalur yang membuat pilihan host port
 *    user berlaku (agent tidak meregenerasi port dari metadata);
 *  - deploy key SSH ditulis `0600` di dalam direktori `0700`;
 *  - app ber-template di-materialisasi dari katalog template milik agent
 *    (`templates/<slug>/`) lewat jalur yang **sama** dengan control plane
 *    (`TemplateCatalog::materialize()`);
 *  - app **repo** (`source=repo`/`git`) di-**clone di agent** (`GitService`)
 *    memakai `repo_url`, `branch`, dan deploy key yang baru saja ditulis. Ini
 *    pekerjaan yang **tidak boleh** dilakukan control plane: checkout repo
 *    adalah aset host tempat container berjalan, dan `LocalDeployer::deploy()`
 *    di agent hanya `revParse` direktori yang sudah ada. Bila direktori app
 *    sudah berupa repo dengan remote yang **sama** ⇒ tidak clone ulang (deploy
 *    berikutnya memakai `git fetch`/`checkout` `LocalDeployer`); bila remote
 *    berbeda (atau direktori non-kosong tanpa `.git`) ⇒ **ditolak**, tidak
 *    ditimpa;
 *  - bentuk & tipe payload `artifacts` diverifikasi di sini
 *    ({@see self::validate()}) — kunci asing, nama berkas berbahaya, atau isi
 *    kosong ditolak, bukan ditulis.
 *
 * ## Path yang dipakai (bukan tebakan)
 * | Artefak | Path | Rujukan |
 * |---|---|---|
 * | direktori app | `{deploy.apps_path}/{name}` | `LocalDeployer::appDir()` (`DeployerFactory` menyuntik `deploy.apps_path`) |
 * | file compose | `…/{name}/<compose_files[0]>` | `ComposeSource::resolveMainFile()` |
 * | deploy key | `{deploy.database_path}/{ssh_key}` (fallback `{deploy.ssh_keys_path}/{name}`) | `LocalDeployer::appSshKeyPath()` — itulah pembaca di agent; mode dir `0700`/file `0600` sama seperti `SshKeyManager` |
 * | checkout repo (`source=git`) | `{deploy.apps_path}/{name}` (clone sementara `apps/.rames-clone-*` ⇒ `rename`) | pembaca: `LocalDeployer::deploy()`/`rebuild()` (`git -C <dir> rev-parse`/`pull`) |
 *
 * ## Siklus hidup artefak (jangan hanya menulis)
 *  - **saat app dihapus di control plane**, worker agent memanggil
 *    {@see self::forget()} (penanda `forget` di payload job `teardown`):
 *    direktori app + deploy key + managed env file dibuang, sama seperti
 *    `AppController::delete()` di host lokal (cleanupDir + `SshKeyManager::remove()`
 *    + `EnvManager::remove()`). Tanpa ini nama app tetap "terpakai" oleh
 *    direktorinya dan app berikutnya dengan nama sama tidak bisa dibuat;
 *  - **direktori sisa app yang sudah dihapus** di server agent (instalasi lama /
 *    penghapusan yang gagal separuh) dibuang lebih dulu oleh
 *    {@see self::dropStaleDir()} — dipanggil `AgentJobController` **setelah**
 *    proyeksi menjamin tidak ada app lain yang memegang nama itu. Direktori
 *    milik app ini dan direktori **tanpa** penanda tidak pernah disentuh jalur
 *    itu.
 *
 * ## Fail-fast (dilarang menimpa diam-diam)
 *  - direktori app sudah ada dan **bukan** milik app ini (penanda
 *    {@see self::MARKER_FILE} berisi id app lain, atau direktori non-kosong
 *    tanpa penanda) ⇒ melempar, **tidak** menimpa — kecuali direktori itu
 *    terbukti sisa app yang sudah dihapus dan pemanggil sudah membuangnya lebih
 *    dulu lewat {@see self::dropStaleDir()} (keputusan ada di pemanggil, bukan
 *    di sini);
 *  - direktori app repo sudah ada, non-kosong, dan bukan clone git dari
 *    `repo_url` ⇒ melempar (tidak meng-clone di atas data orang lain);
 *  - clone repo gagal ⇒ melempar dengan pesan jelas, direktori sementara
 *    dibersihkan (direktori app tidak pernah terlihat setengah jadi);
 *  - app mode compose tanpa file compose (tidak dikirim job ini **dan** tidak
 *    ada di disk agent) ⇒ melempar sebelum menulis apa pun;
 *  - template tidak ada di checkout agent ⇒ melempar (jangan deploy setengah jadi).
 *
 * ## Idempoten
 * Job ulang hanya **menimpa** artefak dengan isi terbaru (tulis-lalu-rename
 * untuk kunci, `LOCK_EX` untuk berkas lain) dan **tidak pernah menghapus**
 * sesuatu. App tanpa artefak & **bukan** app repo tidak menyentuh disk sama
 * sekali; app repo hanya di-clone saat checkout-nya belum ada.
 *
 * ## Rahasia
 * Isi `ssh_key` **tidak pernah** masuk pesan galat ({@see self::sanitizeError()}),
 * rekaman job ({@see \app\library\Agent\AgentJobStore}), atau log. Yang ditulis
 * hanya ke berkas `0600`.
 *
 * Stateless & instansi (bukan statik murni) karena path di-override saat tes
 * (pola `EnvManager`/`TemplateCatalog`); dipanggil di dalam request `POST /jobs`,
 * bukan dari konstruktor kelas lain.
 */
final class AgentArtifactMaterializer
{
    /** Nama file compose default bila payload tidak menyebutkannya. */
    public const DEFAULT_COMPOSE_NAME = ComposeSource::MAIN_FILES[0];

    /**
     * Penanda kepemilikan direktori app (ditulis materializer, bukan user).
     * Isinya `{app_id, name, updated_at}` — dipakai untuk menolak menimpa
     * direktori milik app lain dengan nama yang sama.
     */
    public const MARKER_FILE = '.rames-artifact.json';

    /** Mode berkas kunci privat & direktorinya (sama seperti `SshKeyManager`). */
    public const PRIVATE_KEY_MODE = 0600;
    public const PRIVATE_KEY_DIR_MODE = 0700;

    /** Plafon ukuran artefak (nilai sama dengan konstanta `ComposeSource`). */
    public const MAX_COMPOSE_BYTES = ComposeSource::MAX_FILE_BYTES;
    public const MAX_TOTAL_BYTES = ComposeSource::MAX_TOTAL_BYTES;
    public const MAX_FILES = 64;

    /** Bentuk slug template yang sah (sama dengan `TemplateCatalog::isValidSlug()`). */
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /**
     * Awalan direktori **sementara** hasil clone di `apps_path`
     * (`apps/.rames-clone-<name>-<acak>`) — di-`rename()` ke direktori app
     * setelah clone sukses, dibersihkan bila clone gagal.
     */
    public const CLONE_TMP_PREFIX = '.rames-clone-';

    /** Timeout probe read-only `git remote get-url` (detik). */
    public const REMOTE_TIMEOUT = 30;

    private ?string $appsPath;
    private ?string $databasePath;
    private ?string $keysDir;
    private ?TemplateCatalog $catalog;
    private ?GitService $git;
    private ?ProcessRunner $runner;

    /**
     * @param string|null $appsPath     direktori app agent (default `deploy.apps_path`)
     * @param string|null $databasePath direktori data agent (default `deploy.database_path`)
     * @param string|null $keysDir      direktori deploy key (default `deploy.ssh_keys_path`)
     * @param TemplateCatalog|null $catalog katalog template agent (default `deploy.templates_path`)
     * @param GitService|null $git      seam uji: operasi git (default `new GitService()`)
     * @param ProcessRunner|null $runner seam uji: probe `git remote get-url` (default `new ProcessRunner()`)
     */
    public function __construct(
        ?string $appsPath = null,
        ?string $databasePath = null,
        ?string $keysDir = null,
        ?TemplateCatalog $catalog = null,
        ?GitService $git = null,
        ?ProcessRunner $runner = null,
    ) {
        $this->appsPath = $appsPath;
        $this->databasePath = $databasePath;
        $this->keysDir = $keysDir;
        $this->catalog = $catalog;
        $this->git = $git;
        $this->runner = $runner;
    }

    // ==================================================================
    // Validasi payload
    // ==================================================================

    /**
     * Verifikasi **bentuk & tipe** payload `artifacts` lalu kembalikan bentuk
     * ternormalisasi (kunci selalu lengkap; `null` = tidak ada).
     *
     * Kontrak bentuk: {@see AgentArtifactPackager::KEYS}. Kunci asing ditolak
     * (bukan diabaikan) supaya kontrak lintas-sisi tidak melenceng diam-diam.
     *
     * @param array<string,mixed> $artifacts
     * @return array{compose:?string,compose_name:?string,template:?string,ssh_key:?string,files:array<string,string>}
     * @throws InvalidArgumentException bentuk/tipe tidak sesuai
     */
    public static function validate(array $artifacts): array
    {
        foreach (array_keys($artifacts) as $key) {
            if (!is_string($key) || !in_array($key, AgentArtifactPackager::KEYS, true)) {
                throw new InvalidArgumentException(
                    'Artefak job memuat kunci tak dikenal (`' . (is_scalar($key) ? (string) $key : gettype($key)) . '`).'
                );
            }
        }

        $normalized = [
            AgentArtifactPackager::KEY_COMPOSE => null,
            AgentArtifactPackager::KEY_COMPOSE_NAME => null,
            AgentArtifactPackager::KEY_TEMPLATE => null,
            AgentArtifactPackager::KEY_SSH_KEY => null,
            AgentArtifactPackager::KEY_FILES => [],
        ];

        foreach ([AgentArtifactPackager::KEY_COMPOSE, AgentArtifactPackager::KEY_COMPOSE_NAME, AgentArtifactPackager::KEY_TEMPLATE] as $key) {
            $value = $artifacts[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('Artefak `' . $key . '` harus string.');
            }
            $normalized[$key] = $value;
        }

        $sshKey = $artifacts[AgentArtifactPackager::KEY_SSH_KEY] ?? null;
        if ($sshKey !== null && !is_string($sshKey)) {
            throw new InvalidArgumentException('Artefak `ssh_key` harus string.');
        }
        $normalized[AgentArtifactPackager::KEY_SSH_KEY] = $sshKey === '' ? null : $sshKey;

        if (($normalized[AgentArtifactPackager::KEY_COMPOSE] ?? null) !== null
            && trim((string) $normalized[AgentArtifactPackager::KEY_COMPOSE]) === '') {
            throw new InvalidArgumentException('Artefak `compose` ada tapi isinya kosong.');
        }
        if ($normalized[AgentArtifactPackager::KEY_COMPOSE] !== null
            && strlen($normalized[AgentArtifactPackager::KEY_COMPOSE]) > self::MAX_COMPOSE_BYTES) {
            throw new InvalidArgumentException('Artefak `compose` terlalu besar.');
        }
        if ($normalized[AgentArtifactPackager::KEY_TEMPLATE] !== null) {
            $slug = trim($normalized[AgentArtifactPackager::KEY_TEMPLATE]);
            if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > 63) {
                throw new InvalidArgumentException('Artefak `template` bukan slug template yang sah.');
            }
            $normalized[AgentArtifactPackager::KEY_TEMPLATE] = $slug;
        }
        if ($normalized[AgentArtifactPackager::KEY_COMPOSE_NAME] !== null) {
            $normalized[AgentArtifactPackager::KEY_COMPOSE_NAME] = self::composeName((string) $normalized[AgentArtifactPackager::KEY_COMPOSE_NAME]);
            if ($normalized[AgentArtifactPackager::KEY_COMPOSE] === null) {
                throw new InvalidArgumentException('Artefak `compose_name` tanpa `compose` tidak ada gunanya.');
            }
        }

        $rawFiles = $artifacts[AgentArtifactPackager::KEY_FILES] ?? [];
        if (!is_array($rawFiles)) {
            throw new InvalidArgumentException('Artefak `files` harus map nama berkas ⇒ isi.');
        }
        $total = 0;
        $files = [];
        foreach ($rawFiles as $file => $content) {
            if (!is_string($file) || !is_string($content)) {
                throw new InvalidArgumentException('Artefak `files` harus map nama berkas ⇒ isi (string).');
            }
            if (count($files) >= self::MAX_FILES) {
                throw new InvalidArgumentException('Artefak `files` memuat terlalu banyak berkas.');
            }
            $safe = self::safeName($file);
            if (str_starts_with(basename($safe), ComposeSource::GENERATED_PREFIX)
                && !in_array($safe, AgentArtifactPackager::PORT_OVERRIDE_FILES, true)) {
                throw new InvalidArgumentException('Berkas `' . $safe . '` dikelola sistem dan tidak boleh dikirim.');
            }
            if (ComposeSource::isMainFileName($safe)) {
                throw new InvalidArgumentException('Berkas `' . $safe . '` adalah file compose utama — kirim lewat `compose`.');
            }
            $total += strlen($content);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new InvalidArgumentException('Total artefak `files` terlalu besar.');
            }
            $files[$safe] = $content;
        }

        // Override port wajib **berpasangan** (dua lapis): reset tanpa ports akan
        // menghapus seluruh port bawaan repo tanpa menulis port final — jangan
        // pernah menulis keadaan setengah seperti itu.
        if ($files !== []) {
            $reset = array_key_exists(ComposeSource::RESET_OVERRIDE_FILE, $files);
            $ports = array_key_exists(ComposeSource::PORTS_OVERRIDE_FILE, $files);
            if ($reset !== $ports) {
                throw new InvalidArgumentException(
                    'Override port harus dikirim berpasangan (' . ComposeSource::RESET_OVERRIDE_FILE
                    . ' + ' . ComposeSource::PORTS_OVERRIDE_FILE . ').'
                );
            }
        }
        $normalized[AgentArtifactPackager::KEY_FILES] = $files;

        return $normalized;
    }

    /**
     * Sanitasi pesan galat materialisasi sebelum dikirim ke control plane /
     * ditulis ke rekaman job: isi deploy key & blok PEM dibuang, panjang dibatasi.
     *
     * @param array<string,mixed> $artifacts payload asli (belum tentu ternormalisasi)
     */
    public static function sanitizeError(string $message, array $artifacts): string
    {
        $key = $artifacts[AgentArtifactPackager::KEY_SSH_KEY] ?? null;
        if (is_string($key)) {
            foreach (array_unique([$key, trim($key)]) as $needle) {
                if ($needle !== '') {
                    $message = str_replace($needle, '[kunci deploy dihilangkan]', $message);
                }
            }
        }

        // Blok PEM apa pun (kunci lain yang kebetulan ikut terbaca) juga dibuang,
        // lalu spasi/newline dirapikan supaya tidak ada sisa baris rahasia.
        $message = (string) preg_replace('/-----BEGIN[^-]*-----.*?-----END[^-]*-----/s', '[isi rahasia dihilangkan]', $message);
        $message = trim((string) preg_replace('/\s+/', ' ', $message));
        if ($message === '') {
            $message = 'Materialisasi artefak app gagal (penyebab tidak dilaporkan).';
        }
        if (strlen($message) > 300) {
            $message = substr($message, 0, 297) . '...';
        }

        return $message;
    }

    // ==================================================================
    // Materialisasi
    // ==================================================================

    /**
     * Tulis artefak job ke disk agent. Idempoten; dilarang menimpa direktori
     * app milik app lain (fail-fast).
     *
     * @param array<string,mixed> $app       dokumen app dari payload job
     * @param array<string,mixed> $artifacts payload `artifacts` (divalidasi di sini)
     * @throws InvalidArgumentException bentuk artefak tidak sah
     * @throws RuntimeException artefak tak bisa ditulis / bertentangan dengan keadaan disk
     */
    public function materialize(array $app, array $artifacts): void
    {
        $art = self::validate($artifacts);
        $name = self::appDirName($app);
        // Direktori app dihitung **hanya bila diperlukan**: app non-repo tanpa
        // artefak tidak boleh menyentuh `apps_path`.
        $dir = null;

        $compose = $art[AgentArtifactPackager::KEY_COMPOSE];
        $slug = (string) $art[AgentArtifactPackager::KEY_TEMPLATE];
        $files = $art[AgentArtifactPackager::KEY_FILES];
        $sshKey = $art[AgentArtifactPackager::KEY_SSH_KEY];

        // Fail-fast SEBELUM efek samping apa pun: app mode compose wajib punya
        // file compose (dikirim job ini, atau sudah ada dari job sebelumnya),
        // dan template wajib ada di katalog template agent.
        if (ComposeSource::isCompose($app) && $compose === null && $slug === '') {
            $dir = $this->appDir($name);
            $main = $this->mainNameOf($app, $dir);
            if (!is_file($dir . '/' . $main)) {
                throw new RuntimeException(
                    'App mode compose "' . $name . '" tidak punya file compose "' . $main . '" di server agent ini '
                    . 'dan job tidak mengirimkannya. Jalankan ulang deploy dari dashboard (control plane mengirim '
                    . 'file compose) — materialisasi dihentikan supaya deploy tidak berjalan setengah jadi.'
                );
            }
        }
        $template = $slug !== '' ? $this->resolveTemplate($slug) : null;

        // Isi compose divalidasi **sebelum** efek samping apa pun (kunci ditulis,
        // clone jalan): payload compose yang tidak sah tidak boleh meninggalkan
        // artefak setengah jadi di server agent.
        if ($compose !== null) {
            ComposeSource::assertDeployable($compose);
        }

        // App **repo**: checkout repo milik server INI (agent). Tanpa langkah ini
        // worker agent menjalankan `LocalDeployer` di direktori yang tidak ada.
        $repoUrl = self::repoUrlOf($app);
        $repoDir = $repoUrl !== '' ? $this->appDir($name) : null;

        // App non-repo tanpa artefak: tidak ada yang ditulis — jangan menyentuh disk.
        if ($repoUrl === '' && $compose === null && $template === null && $files === [] && $sshKey === null) {
            return;
        }

        // Deploy key ditulis LEBIH DULU: clone repo memakai kunci itu lewat
        // `GIT_SSH_COMMAND` (`LocalDeployer::appSshKeyPath()` = pembaca yang sama).
        if ($sshKey !== null) {
            $this->writeKey($app, $sshKey);
        }

        if ($repoUrl !== '') {
            $this->ensureRepoClone($app, $repoUrl, $sshKey);
        }

        if ($compose !== null || $template !== null || $files !== []) {
            $dir = $repoDir ?? $this->appDir($name);
            if ($dir !== $repoDir) {
                // Direktori non-repo: kepemilikan ditegakkan penanda (perilaku lama).
                $this->ensureOwnedDir($dir, $app);
            }
        }

        if ($compose !== null) {
            $this->writeCompose($dir, $art[AgentArtifactPackager::KEY_COMPOSE_NAME], $compose);
        }
        if ($files !== []) {
            $this->writeFiles($dir, $files);
        }
        if ($template !== null) {
            $this->writeTemplate($dir, $template);
        }
    }

    // ==================================================================
    // Clone repo (app `source=repo`)
    // ==================================================================

    /**
     * Pastikan direktori app berisi **checkout repo yang benar** di server ini.
     *
     * Aturan (plan §4.3/§5 F1 — clone adalah pekerjaan host, bukan control plane):
     *  - direktori belum ada / kosong ⇒ clone (`git clone --depth=1 --single-branch`
     *    lewat `GitService` agent) memakai `repo_url`, `branch`, dan deploy key
     *    yang baru ditulis; hasilnya di-`rename()` dari direktori sementara;
     *  - direktori sudah repo dengan remote **sama** ⇒ tidak clone ulang
     *    (deploy berikutnya memakai `git pull`/`checkout` `LocalDeployer`);
     *  - direktori sudah repo dengan remote **berbeda**, atau non-kosong tanpa
     *    `.git` ⇒ melempar (tidak meng-clone di atas data yang bukan milik app ini).
     *
     * Idempoten: clone kedua tidak menghapus/menimpa apa pun (tidak ada clone).
     *
     * **Higienitas kredensial**: semua pesan di sini mencetak URL repo lewat
     * {@see self::redactRepoUrl()} — userinfo (`user:pass@`, termasuk password yang
     * memuat `@`) tidak pernah tampil di pesan yang dibaca user/log; host & path
     * tetap ada supaya pesannya bisa didiagnosis.
     *
     * @param array<string,mixed> $app
     * @param string|null $sshKeyContent isi deploy key job ini (hanya untuk sanitasi pesan galat)
     * @throws RuntimeException clone gagal / direktori bentrok / direktori tak bisa dipasang
     */
    private function ensureRepoClone(array $app, string $repoUrl, ?string $sshKeyContent): void
    {
        $name = self::appDirName($app);
        $dir = $this->appDir($name);
        $remote = is_dir($dir) ? $this->repoRemote($dir) : '';

        if ($remote !== '') {
            if (!self::sameRepoUrl($remote, $repoUrl)) {
                // Kedua URL masuk pesan yang bisa dibaca user ⇒ **wajib** lewat
                // `redactRepoUrl()`: userinfo (`user:pass@`) tidak pernah tampil,
                // host/path tetap ada supaya pesannya bisa didiagnosis.
                throw new RuntimeException(
                    'Direktori artefak app (' . $dir . ') sudah berisi repo lain ('
                    . self::redactRepoUrl($remote) . '), bukan "' . self::redactRepoUrl($repoUrl)
                    . '". Dibiarkan utuh — pindahkan/hapus direktori itu lebih dulu (penimpaan '
                    . 'diam-diam dilarang).'
                );
            }

            return;
        }

        if (is_dir($dir)) {
            if (!$this->isEmptyDir($dir)) {
                throw new RuntimeException(
                    'Direktori artefak app (' . $dir . ') sudah ada dan bukan clone git (tanpa remote `.git`). '
                    . 'Dibiarkan utuh supaya berkas di dalamnya tidak hilang — pindahkan/hapus manual lalu '
                    . 'deploy lagi.'
                );
            }
            if (!@rmdir($dir)) {
                throw new RuntimeException('Gagal menyiapkan direktori artefak app (' . $dir . ') untuk clone.');
            }
        }

        $parent = $this->appsPath();
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Gagal membuat direktori artefak app (' . $parent . ').');
        }

        // Clone ke direktori sementara (satu filesystem ⇒ `rename()` atomik), baru
        // dipasang ke nama app: direktori app tidak pernah terlihat setengah jadi.
        $tmp = $parent . '/' . self::CLONE_TMP_PREFIX . $name . '-' . bin2hex(random_bytes(4));

        // Kunci dipakai hanya bila berkasnya benar-benar ada (path dibaca dari
        // metadata `ssh_key` — jalur yang sama dengan `LocalDeployer`). Metadata
        // itu sendiri tidak boleh menggagalkan clone: nilai tak sah ⇒ clone tanpa
        // kunci (git yang akan melaporkan masalah akses repo).
        try {
            $keyPath = $this->keyPath($app);
        } catch (RuntimeException) {
            $keyPath = '';
        }

        try {
            $this->git()->clone($repoUrl, self::branchOf($app), $tmp, $keyPath !== '' && is_file($keyPath) ? $keyPath : null);
        } catch (\Throwable $e) {
            $this->removeTree($tmp);
            throw new RuntimeException(
                'Clone repo "' . self::redactRepoUrl($repoUrl) . '" gagal di server agent ini: '
                . self::sanitizeError($e->getMessage(), [AgentArtifactPackager::KEY_SSH_KEY => $sshKeyContent])
                . ' Direktori sementara dibersihkan; perbaiki akses repo (deploy key di repo / cabang) lalu '
                . 'deploy ulang.',
                0,
                $e
            );
        }

        if (!@rename($tmp, $dir)) {
            $this->removeTree($tmp);
            throw new RuntimeException('Gagal memasang hasil clone repo ke direktori artefak app (' . $dir . ').');
        }
    }

    /**
     * URL remote `origin` direktori app — `''` bila direktori bukan repo git
     * (read-only, tanpa jaringan).
     */
    private function repoRemote(string $dir): string
    {
        $result = $this->runner()->run(
            ['git', '-C', $dir, 'remote', 'get-url', 'origin'],
            null,
            self::REMOTE_TIMEOUT
        );

        return $result['code'] === 0 ? trim((string) $result['stdout']) : '';
    }

    /**
     * Apakah dua URL repo menunjuk repo yang sama. Perbandingan **longgar tapi
     * aman**: tanpa pengaruh huruf besar/kecil, garis miring di ujung, dan akhiran
     * `.git` — bentuk yang biasa ditulis user (`…/repo` vs `…/repo.git`).
     */
    private static function sameRepoUrl(string $a, string $b): bool
    {
        $normalize = static function (string $url): string {
            $url = strtolower(trim($url));
            $url = rtrim($url, '/');
            if (str_ends_with($url, '.git')) {
                $url = substr($url, 0, -4);
            }

            return rtrim($url, '/');
        };

        return $normalize($a) === $normalize($b);
    }

    /**
     * URL repo app **bila app memang app repo** (`source=repo`/`git`, punya
     * `repo_url`, dan bukan app ber-template) — `''` untuk app compose/template.
     *
     * @param array<string,mixed> $app
     */
    private static function repoUrlOf(array $app): string
    {
        if (!ComposeSource::isGit($app) || AgentArtifactPackager::templateSlug($app) !== '') {
            return '';
        }

        return is_scalar($app['repo_url'] ?? null) ? trim((string) $app['repo_url']) : '';
    }

    /**
     * Branch yang dipakai clone: `branch` app, fallback `main` (sama dengan
     * default `LocalDeployer::rebuild()`).
     *
     * @param array<string,mixed> $app
     */
    private static function branchOf(array $app): string
    {
        $branch = is_scalar($app['branch'] ?? null) ? trim((string) $app['branch']) : '';

        return $branch !== '' ? $branch : 'main';
    }

    /**
     * Hapus direktori hasil clone sementara beserta isinya (hanya dipakai untuk
     * direktori sementara `apps_path` + hasil clone ke path-nya sendiri).
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    // ==================================================================
    // Direktori app & kepemilikannya
    // ==================================================================

    /**
     * Nama direktori app di `apps_path` — **satu sumber** batas jalurnya (dipakai
     * juga {@see AgentArtifactPackager::appDirName()}).
     *
     * Nama app wajib satu segmen aman: tolak kosong, absolut, `..`, maupun
     * subdirektori (pola `ComposeSource::sanitizeName()` + larangan `/`).
     *
     * @param array<string,mixed> $app
     * @throws RuntimeException nama app tidak sah
     */
    public static function appDirName(array $app): string
    {
        $name = is_scalar($app['name'] ?? null) ? trim((string) $app['name']) : '';
        if ($name === '' || str_contains(str_replace('\\', '/', $name), '/')) {
            throw new RuntimeException('App tanpa `name` yang sah tidak bisa di-materialisasi di server agent.');
        }

        return ComposeSource::sanitizeName($name);
    }

    /**
     * Pastikan direktori app ada **dan** milik app ini, lalu segarkan penanda.
     *
     * @param array<string,mixed> $app
     * @throws RuntimeException direktori milik app lain / tak bisa dibuat
     */
    private function ensureOwnedDir(string $dir, array $app): void
    {
        if (is_dir($dir)) {
            $marker = $this->readMarker($dir);
            if ($marker !== null) {
                $owner = is_scalar($marker['app_id'] ?? null) ? trim((string) $marker['app_id']) : '';
                if ($owner !== (string) ($app['id'] ?? '')) {
                    throw new RuntimeException(
                        'Direktori artefak app (' . $dir . ') sudah dipakai app lain di server ini '
                        . '(penanda kepemilikan berbeda). Hapus/pindahkan direktori itu lebih dulu — penimpaan '
                        . 'diam-diam dilarang.'
                    );
                }
            } elseif ($this->isEmptyDir($dir)) {
                // Direktori kosong (mis. sisa job yang gagal) → boleh dipakai.
            } else {
                throw new RuntimeException(
                    'Direktori artefak app (' . $dir . ') sudah ada dan bukan milik app ini (tanpa penanda '
                    . 'kepemilikan). Dipakai proses lain? Pindahkan/hapus direktori itu lebih dulu.'
                );
            }
        } elseif (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Gagal membuat direktori artefak app (' . $dir . ').');
        }

        $this->writeMarker($dir, $app);
    }

    /**
     * Buang direktori artefak app bila penanda kepemilikannya milik **id app
     * lain** — `true` bila ada direktori yang dibuang.
     *
     * Dipanggil {@see \app\controller\AgentJobController::create()} **sesudah**
     * {@see \app\library\Agent\AgentAppProjection::upsert()}, yang menjamin tidak
     * ada app lain yang memegang nama itu di store agent (baris basi bernama sama
     * dibuang). Nama direktori app ditentukan oleh nama app, sehingga direktori
     * `apps/<name>` yang penandanya milik id lain **bukan** milik app lain yang
     * sah di server ini: ia sisa app lama. Tanpa dibuang, materialisasi app ini
     * ditolak penanda kepemilikan (`ensureOwnedDir()`) dan nama app itu tidak
     * bisa dipakai lagi di server ini (GOTCHA §8 no. 35).
     *
     * Direktori milik app ini dan direktori **tanpa** penanda tidak disentuh:
     * yang pertama sudah benar, yang kedua adalah data tak dikenal — tetap
     * ditolak `ensureOwnedDir()`, bukan dihapus.
     *
     * @param array<string,mixed> $app
     * @return bool true bila direktori sisa dibuang
     * @throws RuntimeException direktori sisa tidak bisa dibuang (jangan lanjutkan dengan error yang menyesatkan)
     */
    public function dropStaleDir(array $app): bool
    {
        $dir = $this->appDir(self::appDirName($app));
        if (!is_dir($dir)) {
            return false;
        }

        $marker = $this->readMarker($dir);
        $owner = is_array($marker) && is_scalar($marker['app_id'] ?? null) ? trim((string) $marker['app_id']) : '';
        if ($owner === '' || $owner === (string) ($app['id'] ?? '')) {
            return false;
        }

        $this->removeTree($dir);
        if (is_dir($dir)) {
            throw new RuntimeException(
                'Direktori artefak app (' . $dir . ') berisi artefak app lain dan tidak bisa dibuang otomatis. '
                . 'Bereskan direktori itu di server agent (izin/isi), lalu deploy ulang.'
            );
        }

        return true;
    }

    /**
     * Keadaan direktori artefak `apps/<name>` **di server ini** — preflight
     * **read-only** untuk create app di control plane (`POST /apps/check`).
     *
     * Kenapa perlu: jalur materialisasi memang menolak direktori milik orang lain
     * (`ensureOwnedDir()` / `ensureRepoClone()`), tetapi penolakan itu baru terjadi
     * **sesudah** record app dibuat di control plane — user melihatnya sebagai
     * "deploy gagal", bukan sebagai "nama app tidak bisa dipakai". Preflight ini
     * memindahkan penolakan itu ke depan, sebelum ada efek samping apa pun.
     *
     * Keadaan **aman** bagi app ini (cermin keputusan jalur job):
     *  - `free`  — path belum ada / direktori kosong (sisa job yang gagal);
     *  - `owned` — penanda kepemilikannya milik **app ini** (deploy ulang app yang
     *              sama), **atau** direktori tanpa penanda yang berisi checkout git
     *              dengan remote origin **sama** dengan `repo_url` app
     *              (⟷ `ensureRepoClone()` menerimanya: remote sama ⇒ tidak clone
     *              ulang, deploy lanjut). Yang kedua hanya diperiksa bila payload
     *              membawa `repo_url`;
     *  - `stale` — penanda kepemilikannya milik **id app lain**: sisa app yang
     *              sudah dihapus di control plane ⇒ nanti dibuang
     *              {@see self::dropStaleDir()} saat job benar-benar datang
     *              (keputusan pembuangan tetap milik pemanggil jalur job).
     *
     * `blocked` = jalur job pasti menolaknya, jadi create harus berhenti lebih
     * dulu. Tiga sebab, semuanya **tidak pernah** dihapus otomatis Rames:
     *  1. path ada tetapi **bukan direktori** (berkas/symlink dangling) — `mkdir`
     *     di jalur job akan gagal;
     *  2. direktori non-kosong tanpa penanda kepemilikan yang **sah**, dan (bila
     *     `repo_url` dikirim) bukan checkout git ber-remote sama — cermin pesan
     *     `ensureRepoClone()` yang menyebut repo lain, atau data tak dikenal;
     *  3. penanda ada tetapi `app_id`-nya tidak terbaca (data tak dikenal).
     *
     * ## Probe remote (read-only, tanpa jaringan)
     * Perbandingan remote memakai `repoRemote()` yang sama dengan jalur job
     * (`git -C <dir> remote get-url origin`, `REMOTE_TIMEOUT`), dan **hanya**
     * dijalankan bila: direktori ada & non-kosong, **tanpa** penanda kepemilikan,
     * `{dir}/.git` ada (cek murah sebelum menyentuh proses), **dan** payload
     * membawa `repo_url` non-kosong. Tanpa salah satu syarat itu tidak ada proses
     * yang dijalankan sama sekali. `.git` yang berupa **berkas** (git worktree /
     * submodule) sengaja tidak diprobe: hasilnya `blocked` — fail-safe, karena
     * kepemilikannya tidak bisa dipastikan dengan murah.
     *
     * Tanpa mutasi apa pun: tidak ada `mkdir`/`unlink`/`rename`/penulisan penanda,
     * dan isi direktori (termasuk `mtime`) dibiarkan apa adanya (dibuktikan uji).
     *
     * @param array<string,mixed> $app minimal `{id,name}`; `repo_url` opsional
     *                                 (metadata non-rahasia — JANGAN kirim `env`,
     *                                 deploy key, atau token ke sini)
     * @return array{state:string,available:bool,message:string}
     * @throws RuntimeException nama app tidak sah (pemanggil menerjemahkannya jadi 400)
     */
    public function appDirState(array $app): array
    {
        $name = self::appDirName($app);
        $dir = $this->appDir($name);

        // (1) Path ada tetapi bukan direktori: berkas biasa atau symlink dangling.
        // `mkdir()` di jalur job akan gagal, jadi ini `blocked` — bukan `free`.
        if (!is_dir($dir) && (file_exists($dir) || is_link($dir))) {
            return [
                'state' => 'blocked',
                'available' => false,
                'message' => 'Di server ini sudah ada berkas bernama `apps/' . $name . '` (bukan direktori), '
                    . 'sehingga direktori artefak app tidak bisa dibuat. Rames tidak pernah menghapus data yang '
                    . 'tidak dikenalinya: pindahkan atau hapus berkas `apps/' . $name . '` secara manual di '
                    . 'server agent itu, lalu deploy ulang — atau pakai nama app lain.',
            ];
        }

        if (!is_dir($dir) || $this->isEmptyDir($dir)) {
            return ['state' => 'free', 'available' => true, 'message' => ''];
        }

        $marker = $this->readMarker($dir);
        $owner = is_array($marker) && is_scalar($marker['app_id'] ?? null)
            ? trim((string) $marker['app_id'])
            : '';

        if ($marker !== null && $owner !== '') {
            return $owner === (string) ($app['id'] ?? '')
                ? ['state' => 'owned', 'available' => true, 'message' => '']
                : ['state' => 'stale', 'available' => true, 'message' => ''];
        }

        // (2) Direktori non-kosong tanpa penanda yang sah. Bila pemanggil mengirim
        // `repo_url`, keputusannya harus sama dengan `ensureRepoClone()`: checkout
        // git ber-remote **sama** diterima jalur job ⇒ bukan `blocked`.
        $repoUrl = is_scalar($app['repo_url'] ?? null) ? trim((string) $app['repo_url']) : '';
        if ($repoUrl !== '' && is_dir($dir . '/.git')) {
            $remote = $this->repoRemote($dir);
            if ($remote !== '' && self::sameRepoUrl($remote, $repoUrl)) {
                return ['state' => 'owned', 'available' => true, 'message' => ''];
            }
            if ($remote !== '') {
                return [
                    'state' => 'blocked',
                    'available' => false,
                    'message' => 'Direktori `apps/' . $name . '` di server ini sudah berisi repo lain ('
                        . self::redactRepoUrl($remote) . '), bukan "' . self::redactRepoUrl($repoUrl)
                        . '". Rames tidak menimpa checkout orang lain '
                        . 'diam-diam: pindahkan atau hapus direktori `apps/' . $name . '` secara manual di '
                        . 'server agent itu, lalu deploy ulang — atau pakai nama app lain.',
                ];
            }
        }

        return [
            'state' => 'blocked',
            'available' => false,
            'message' => 'Direktori `apps/' . $name . '` di server ini sudah ada dan berisi data tanpa '
                . 'penanda kepemilikan Rames. Rames tidak pernah menghapus data yang tidak dikenalinya: '
                . 'pindahkan atau hapus direktori `apps/' . $name . '` secara manual di server agent itu, '
                . 'lalu deploy ulang — atau pakai nama app lain.',
        ];
    }

    /**
     * URL repo **tanpa userinfo** (`user:password@`) untuk pesan yang bisa dibaca
     * user: URL repo private sering ditulis dengan kredensial di dalamnya, dan
     * pesan ini tampil di wizard/log. Sisanya dibiarkan apa adanya supaya pesannya
     * tetap bisa didiagnosis.
     *
     * Seluruh userinfo dibuang — **termasuk password yang memuat `@`**
     * (`https://user:p@ss@host/x.git`): pola `[^/]*@` (serakah, berhenti di `/`
     * pertama) memakan sampai `@` **terakhir** sebelum path. Pola `[^/@]*@` salah:
     * ia berhenti di `@` pertama sehingga menyisakan ekor password.
     *
     * URL scp-like (`git@host:org/repo.git`) tidak punya skema `://`, jadi tidak
     * punya userinfo berpassword — regex ini sengaja **tidak** menyentuhnya.
     */
    private static function redactRepoUrl(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/]*@#i', '$1', $url);
    }

    /**
     * Buang **seluruh** artefak app di server ini: direktori app (termasuk
     * penanda), deploy key (`+ .pub`), dan managed env file app.
     *
     * Dipakai saat app **dihapus di control plane** — worker agent memanggilnya
     * bersama {@see \app\library\Agent\AgentAppProjection::forget()} (penanda
     * `forget` di payload job `teardown`). Langkah ini **wajib**: tanpa membuang
     * direktori, nama app tetap "terpakai" di server ini dan create berikutnya
     * dengan nama sama ditolak penanda kepemilikan (GOTCHA §8 no. 35).
     *
     * Cermin dari pembersihan jalur lokal (`AppController::delete()`:
     * `cleanupDir()` + `SshKeyManager::remove()` + `EnvManager::remove()`) —
     * sengaja memakai path yang sama dengan penulisnya ({@see self::keyPath()},
     * `{database_path}/env`), bukan path yang ditebak.
     *
     * Idempoten: apa pun yang tidak ada dilewati. Gagal membuang direktori ⇒
     * melempar (pemanggil mencatatnya sebagai peringatan, tidak menggagalkan
     * penghapusan app yang sudah ter-teardown); kegagalan kunci/env bersifat
     * best-effort seperti jalur lokal.
     *
     * @param array<string,mixed> $app
     * @throws RuntimeException direktori app tidak bisa dibuang
     */
    public function forget(array $app): void
    {
        $name = self::appDirName($app);

        $dir = $this->appDir($name);
        if (is_dir($dir)) {
            $this->removeTree($dir);
            if (is_dir($dir)) {
                throw new RuntimeException('Gagal menghapus direktori artefak app (' . $dir . ').');
            }
        }

        // Deploy key: path yang sama dengan penulisnya. Metadata `ssh_key` tak
        // sah ⇒ tidak ada yang dibuang (bukan alasan menggagalkan pembersihan).
        $key = '';
        try {
            $key = $this->keyPath($app);
        } catch (RuntimeException) {
            $key = '';
        }
        foreach ($key !== '' ? [$key, $key . '.pub'] : [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Managed env file app (`{database_path}/env/<name>.env`, mode 0600).
        try {
            (new EnvManager($this->databasePath() . '/env'))->remove($name);
        } catch (\Throwable) {
            // Direktori env tidak bisa ditentukan: pembersihan ini hygiene,
            // bukan syarat sahnya penghapusan app.
        }
    }

    /**
     * Isi penanda kepemilikan direktori app (JSON) — `null` bila belum ada/tidak terbaca.
     *
     * @return array<string,mixed>|null
     */
    private function readMarker(string $dir): ?array
    {
        $path = $dir . '/' . self::MARKER_FILE;
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $app
     */
    private function writeMarker(string $dir, array $app): void
    {
        $payload = json_encode([
            'app_id' => (string) ($app['id'] ?? ''),
            'name' => (string) ($app['name'] ?? ''),
            'updated_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false || @file_put_contents($dir . '/' . self::MARKER_FILE, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis penanda artefak app di ' . $dir . '.');
        }
    }

    private function isEmptyDir(string $dir): bool
    {
        $entries = @scandir($dir);

        return is_array($entries) && array_values(array_diff($entries, ['.', '..'])) === [];
    }

    // ==================================================================
    // Penulisan artefak
    // ==================================================================

    /**
     * Tulis file compose utama lewat jalur penyimpanan mode compose
     * (`ComposeSource::store()`) supaya validasi identik dengan control plane
     * (YAML valid, ada `services`, tanpa `build:`).
     */
    private function writeCompose(string $dir, ?string $composeName, string $content): void
    {
        $name = $composeName !== null && $composeName !== ''
            ? self::composeName($composeName)
            : self::DEFAULT_COMPOSE_NAME;

        ComposeSource::store($dir, [], $content, self::MAX_COMPOSE_BYTES, self::MAX_TOTAL_BYTES, $name);
    }

    /**
     * Tulis berkas pendukung (cadangan kontrak; packager belum mengirimnya) —
     * hanya nama relatif yang aman & bukan berkas sistem yang diterima
     * ({@see self::validate()}).
     *
     * @param array<string,string> $files
     */
    private function writeFiles(string $dir, array $files): void
    {
        foreach ($files as $relative => $content) {
            $path = $dir . '/' . $relative;
            $parent = dirname($path);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException('Gagal membuat direktori artefak ' . $parent . '.');
            }
            if (@file_put_contents($path, $content, LOCK_EX) === false) {
                throw new RuntimeException('Gagal menulis artefak ' . $relative . '.');
            }
        }
    }

    /**
     * Template dari katalog template **agent** (`templates/<slug>/`) — jalur yang
     * sama dipakai control plane saat create. Dipanggil **sebelum** direktori app
     * disentuh supaya template yang tidak ada di checkout agent tidak
     * meninggalkan artefak setengah jadi.
     *
     * @return array<string,mixed> dokumen template (hasil `TemplateCatalog::require()`)
     * @throws RuntimeException template tidak ada / tidak valid
     */
    private function resolveTemplate(string $slug): array
    {
        try {
            return $this->catalog()->require($slug);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Template "' . $slug . '" tidak bisa dipakai di server agent ini ('
                . $e->getMessage() . '). Sinkronkan checkout server agent (template pull) lalu deploy lagi — '
                . 'job dihentikan supaya app tidak setengah jadi.'
            );
        }
    }

    /**
     * Materialisasi app ber-template lewat jalur kontrol plane
     * (`TemplateCatalog::materialize()` ⇒ `ComposeSource::store()`), sehingga
     * hasilnya identik: compose utama + berkas pendukung dari `files/`.
     *
     * @param array<string,mixed> $template
     */
    private function writeTemplate(string $dir, array $template): void
    {
        $this->catalog()->materialize($template, $dir, self::MAX_COMPOSE_BYTES, self::MAX_TOTAL_BYTES);
    }

    /**
     * Tulis private key deploy app.
     *
     * Lokasi = **path yang dibaca agent saat deploy**
     * (`LocalDeployer::appSshKeyPath()`): `{deploy.database_path}/{ssh_key}`;
     * bila app tidak menyimpan `ssh_key`, dipakai lokasi kanonik
     * `{deploy.ssh_keys_path}/{name}` (API `SshKeyManager`) — mode direktori
     * `0700` & berkas `0600` sama seperti `SshKeyManager`.
     *
     * Ditulis ke berkas sementara `0600` lalu `rename()` (atomik): tidak pernah
     * ada jendela waktu kunci setengah tertulis dengan mode salah.
     *
     * @param array<string,mixed> $app
     */
    private function writeKey(array $app, string $content): void
    {
        $target = $this->keyPath($app);
        $dir = dirname($target);

        if (!is_dir($dir)) {
            if (!@mkdir($dir, self::PRIVATE_KEY_DIR_MODE, true) && !is_dir($dir)) {
                throw new RuntimeException('Gagal membuat direktori deploy key (' . $dir . ').');
            }
        }
        // Kunci privat = rahasia ⇒ direktori tempatnya wajib 0700. Direktori data
        // (`deploy.database_path`) sendiri tidak pernah diubah modenya, karena ia
        // juga memuat `env/` yang dibaca proses lain.
        if (rtrim($dir, '/') !== rtrim($this->databasePath(), '/')) {
            @chmod($dir, self::PRIVATE_KEY_DIR_MODE);
        }

        $tmp = $target . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('Gagal menulis deploy key app di server agent.');
        }
        @chmod($tmp, self::PRIVATE_KEY_MODE);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Gagal memasang deploy key app di server agent (' . $target . ').');
        }
        @chmod($target, self::PRIVATE_KEY_MODE);
    }

    /**
     * Path absolut private key deploy app di agent — cermin
     * `LocalDeployer::appSshKeyPath()` (pembaca sebenarnya).
     *
     * @param array<string,mixed> $app
     */
    private function keyPath(array $app): string
    {
        $relative = is_scalar($app['ssh_key'] ?? null) ? trim((string) $app['ssh_key']) : '';
        if ($relative !== '') {
            return $this->databasePath() . '/' . ComposeSource::sanitizeName($relative);
        }

        return $this->keysDir() . '/' . self::appDirName($app);
    }

    // ==================================================================
    // Helper
    // ==================================================================

    /**
     * Nama file compose yang sah: satu segmen aman + salah satu nama utama yang
     * dikenali `ComposeSource` (`docker-compose.yml`, `compose.yaml`, …).
     */
    private static function composeName(string $name): string
    {
        $safe = self::safeName($name);
        if (!ComposeSource::isMainFileName($safe)) {
            throw new InvalidArgumentException(
                'Nama file compose tidak dikenali: ' . $safe . ' (harus salah satu dari '
                . implode(', ', ComposeSource::MAIN_FILES) . ').'
            );
        }

        return $safe;
    }

    /**
     * Nama file relatif yang aman sebagai artefak — pola
     * {@see ComposeSource::sanitizeName()} (tolak absolut, `..`, segmen aneh),
     * tapi galatnya diklasifikasikan sebagai **bentuk payload tidak sah**
     * (`InvalidArgumentException`) supaya controller bisa menjawab 400 tanpa
     * menyentuh disk.
     */
    private static function safeName(string $name): string
    {
        try {
            return ComposeSource::sanitizeName($name);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Nama berkas artefak tidak sah: ' . $name, 0, $e);
        }
    }

    /**
     * Nama file compose utama app: dari `compose_name` artefak, atau
     * `compose_files` app, atau deteksi di disk, terakhir nama default.
     *
     * @param array<string,mixed> $app
     */
    private function mainNameOf(array $app, string $dir): string
    {
        $declared = ComposeSource::mainFile($app);
        if ($declared !== '') {
            return $declared;
        }

        $detected = ComposeSource::detectMainFile($dir);

        return $detected !== '' ? $detected : self::DEFAULT_COMPOSE_NAME;
    }

    /** Direktori app agent (`apps_path/<name>`). */
    private function appDir(string $name): string
    {
        return $this->appsPath() . '/' . $name;
    }

    /** Direktori app agent (config `deploy.apps_path`). */
    private function appsPath(): string
    {
        $path = rtrim($this->appsPath ?? (string) config('deploy.apps_path'), '/');
        if ($path === '') {
            throw new RuntimeException('Konfigurasi `deploy.apps_path` kosong — artefak tidak bisa ditulis.');
        }

        return $path;
    }

    /** Direktori data agent (`keys/`, `env/`) — config `deploy.database_path`. */
    private function databasePath(): string
    {
        $path = rtrim($this->databasePath ?? (string) config('deploy.database_path'), '/');
        if ($path === '') {
            throw new RuntimeException('Konfigurasi `deploy.database_path` kosong — deploy key tidak bisa ditulis.');
        }

        return $path;
    }

    /** Direktori deploy key (config `deploy.ssh_keys_path`, lewat `SshKeyManager`). */
    private function keysDir(): string
    {
        return rtrim($this->keysDir ?? (string) (new SshKeyManager())->keysDir(), '/');
    }

    /** Katalog template agent (config `deploy.templates_path`). */
    private function catalog(): TemplateCatalog
    {
        return $this->catalog ??= new TemplateCatalog();
    }

    /** Operasi git agent (`GitService` — array + `bypass_shell`, bebas injection). */
    private function git(): GitService
    {
        return $this->git ??= new GitService();
    }

    /** Runner proses untuk probe read-only (`SigchldGuard` ditangani `ProcessRunner`). */
    private function runner(): ProcessRunner
    {
        return $this->runner ??= new ProcessRunner();
    }
}
