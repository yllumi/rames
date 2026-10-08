<?php
declare(strict_types=1);

namespace app\library\Adminer;

use app\library\Docker\DockerClient;
use app\library\Storage\JsonStore;
use app\library\Support\ProcessRunner;
use app\library\Support\SigchldGuard;
use InvalidArgumentException;
use RuntimeException;

/**
 * Helper container Adminer standalone (fitur D1 — "Adminer sebagai mesin /database").
 *
 * Kontrak arsitektur: browser → Webman (Auth + AppAccess + reverse proxy HTTP) →
 * helper Adminer (`adminer:6` standalone, `php -S`) → container DB (TCP).
 * Dashboard **tidak pernah** `require`/mengeksekusi `adminer.php` dan helper
 * **tidak punya port publik**.
 *
 * Aturan keras yang ditegakkan di sini (hasil spike Fase 0):
 *  1. Helper harus berbagi network dengan dashboard dan dijangkau lewat **nama
 *     container** (DNS) — tanpa network bersama, DNS gagal & IP antar-bridge
 *     tidak routable.
 *  2. Network khusus helper dibuat `internal: true` (Adminer tanpa auth tidak
 *     boleh terlihat container lain).
 *  3. `connectContainerToNetwork()` **tidak idempoten** (403 `endpoint … already
 *     exists`) → selalu cek `NetworkSettings.Networks` lebih dulu, lalu tetap
 *     tangkap error "already exists" sebagai sukses (race).
 *  4. `--rm` konflik dengan `--restart unless-stopped` (exit 125) → **tanpa**
 *     `--rm`; lifecycle eksplisit (rm → run).
 *  5. Tidak ada rahasia (kredensial DB) di argv helper — kredensial hanya
 *     dipakai AdminerProxy saat auto-login HTTP.
 *  6. Attach ke network app **terbatas umur**: waktu pakai terakhir dicatat di
 *     `runtime/adminer-helper/networks.json` (tanpa kredensial) dan network yang
 *     idle melebihi `adminer_network_ttl` dilepas oportunistik dari
 *     {@see ensureRunning()} — helper tanpa auth tidak boleh terus terlihat
 *     container lain di network app yang non-internal.
 *
 * Spawn CLI selalu lewat `ProcessRunner` (argv array + `bypass_shell`) di dalam
 * `SigchldGuard::withDefault()` dengan pemeriksaan exit code — pola ResticRunner.
 * Instance: spec di-inject agar bisa diuji tanpa Docker.
 */
class AdminerHelper
{
    /** Label penanda helper Adminer (untuk prune/teardown). */
    public const LABEL = 'rames.role=adminer-helper';

    /** Port yang dilayani image adminer (`php -S [::]:8080`). */
    public const PORT = 8080;

    /** Pola nama container/network yang boleh masuk argv helper. */
    public const NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/';

    /** Network Docker bawaan yang tidak pernah dipakai helper. */
    private const BUILTIN_NETWORKS = ['bridge', 'host', 'none'];

    private DockerClient $docker;

    private ProcessRunner $runner;

    /** @var array<string,mixed> */
    private array $spec;

    /**
     * @param array<string,mixed> $spec override spec (`image`, `container`,
     *        `network`, `workers`, `dashboard`, `docker`, `command_timeout`)
     */
    public function __construct(?DockerClient $docker = null, ?ProcessRunner $runner = null, array $spec = [])
    {
        $this->docker = $docker ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
        $this->runner = $runner ?? new ProcessRunner();
        $this->spec = self::normalizeSpec($spec);
    }

    /**
     * Spec efektif (setelah digabung default config).
     *
     * @return array<string,mixed>
     */
    public function spec(): array
    {
        return $this->spec;
    }

    // ==================================================================
    // Eksekusi (instance)
    // ==================================================================

    /**
     * Pastikan helper Adminer hidup, berada di network helper, dan dashboard
     * sendiri terhubung ke network itu (agar bisa menjangkau helper via DNS).
     *
     * Idempoten: container yang sudah ada dengan image yang sama hanya di-ensure
     * running/attached; image berbeda → recreate (rm -f → run).
     *
     * Sebelum apa pun, dua **prune oportunistik** dijalankan (tanpa scheduler
     * baru, tanpa pernah menggagalkan request — temuan Fase 5a SEDANG/RENDAH):
     *  - {@see pruneIdleNetworks()} melepas helper dari network app yang idle
     *    melebihi `adminer_network_ttl` (helper tanpa auth tidak boleh terus
     *    terlihat container lain di network non-internal);
     *  - {@see AdminerTempDir::prune()} membersihkan berkas respons basi walau
     *    `Workerman\Timer` tidak tersedia (CLI/test).
     *
     * @throws RuntimeException bila Docker Engine/docker CLI tidak tersedia
     */
    public function ensureRunning(): void
    {
        $this->assertEngineAvailable();

        $this->pruneIdleNetworks();
        AdminerTempDir::prune((string) $this->spec['proxy_temp_dir']);

        $network = (string) $this->spec['network'];
        if ($network === '' || preg_match(self::NAME_PATTERN, $network) !== 1) {
            throw new InvalidArgumentException(
                'Nama network helper Adminer tidak valid: "' . $network . '" (ADMINER_NETWORK).'
            );
        }
        $this->ensureNetworkExists($network);

        $existing = $this->findContainer();
        if ($existing !== null && $this->imageMatches($existing)) {
            $id = (string) ($existing['Id'] ?? '');
            if ($id !== '') {
                $this->ensureStarted($id);
                $this->attach($id, $network);
            }
            $this->attachDashboard($network);

            return;
        }

        if ($existing !== null) {
            // Image berubah (mis. ADMINER_IMAGE di-set ulang) → recreate.
            $this->remove();
        }

        $this->runHelper();

        $id = $this->containerId();
        if ($id === null) {
            throw new RuntimeException(
                'Helper Adminer gagal dijalankan: container "' . (string) $this->spec['container'] . '" tidak ditemukan setelah `docker run`.'
            );
        }
        $this->attach($id, $network);
        $this->attachDashboard($network);
    }

    /**
     * Attach **helper** ke network tambahan (network app target tempat container
     * DB berada) supaya helper bisa menjangkau DB lewat DNS.
     *
     * Idempoten (guard `NetworkSettings.Networks` + toleran 403 "already exists").
     * Setelah attach berhasil, waktu pakai network dicatat (untuk prune TTL).
     *
     * @throws RuntimeException bila helper/network tidak ada
     */
    public function ensureOnNetwork(string $networkName): void
    {
        if ($networkName === '') {
            return;
        }
        if (preg_match(self::NAME_PATTERN, $networkName) !== 1) {
            throw new InvalidArgumentException('Nama network app tidak valid: "' . $networkName . '".');
        }
        if ($networkName === (string) $this->spec['network']) {
            return; // sudah dijamin ensureRunning()
        }

        $id = $this->containerId();
        if ($id === null) {
            throw new RuntimeException('Helper Adminer belum berjalan — panggil ensureRunning() lebih dulu.');
        }

        $this->attach($id, $networkName);
        $this->rememberNetwork($networkName, $id);
    }

    /**
     * Lepas **helper** dari network tambahan (idempoten, best-effort).
     *
     * Dipakai prune TTL dan pembersihan manual. Aman dipanggil berkali-kali:
     *  - bila `NetworkSettings.Networks` sudah tidak memuat network itu, tidak
     *    ada panggilan ke Docker Engine sama sekali (0 panggilan);
     *  - 404/403 dari Engine (network/endpoint sudah tidak ada, "not connected")
     *    ditoleransi sebagai sukses;
     *  - network helper sendiri (`adminer_network`) TIDAK PERNAH dilepas;
     *  - catatan TTL network itu ikut dihapus.
     *
     * @throws InvalidArgumentException nama network tidak valid
     * @throws RuntimeException         kegagalan Engine selain 404/403
     */
    public function ensureOffNetwork(string $networkName): void
    {
        if ($networkName === '') {
            return;
        }
        if (preg_match(self::NAME_PATTERN, $networkName) !== 1) {
            throw new InvalidArgumentException('Nama network app tidak valid: "' . $networkName . '".');
        }
        if ($networkName === (string) $this->spec['network']) {
            return; // network helper tidak pernah dilepas
        }

        $id = $this->containerId();
        if ($id === null) {
            // Helper tidak ada → tidak ada attachment yang tersisa.
            $this->forgetNetwork($networkName);

            return;
        }

        if (!$this->isAttached($id, $networkName)) {
            $this->forgetNetwork($networkName);

            return;
        }

        $networkId = $this->networkIdByName($networkName);
        if ($networkId === null) {
            // Network app sudah dihapus (app dibongkar) → tidak ada yang dilepas.
            $this->forgetNetwork($networkName);

            return;
        }

        try {
            $this->docker->disconnectContainerFromNetwork($networkId, $id);
        } catch (RuntimeException $e) {
            if (!$this->isAbsentNetworkError($e)) {
                throw $e;
            }
        }

        $this->forgetNetwork($networkName);
    }

    /**
     * Prune oportunistik: lepas helper dari network app yang terakhir dipakai
     * lebih lama dari `adminer_network_ttl` detik, dan bersihkan catatan network
     * yang sudah tidak ada (app dihapus) — TIDAK PERNAH melempar.
     *
     * Dipanggil dari {@see ensureRunning()} (tidak ada scheduler baru). `ttl = 0`
     * mematikan prune berbasis waktu, tetapi catatan untuk network yang hilang
     * tetap dibersihkan. Bila helper sudah tidak ada (id berubah/hilang), seluruh
     * catatan lama dibuang karena attachment-nya mati bersama container lama.
     *
     * Log kegagalan lewat spec `logger` (default `support\Log`) — tidak pernah
     * menjadi exception ke user.
     */
    public function pruneIdleNetworks(): void
    {
        try {
            $records = $this->readNetworkRecords();
            $helper = (string) $this->spec['network'];
            $id = $this->containerId();
            $ttl = (int) $this->spec['network_ttl'];

            if ($records['helper'] !== '' && $id !== null && $records['helper'] !== $id) {
                // Container helper di-recreate → semua attachment lama hilang.
                $records = ['helper' => '', 'networks' => []];
                $this->writeNetworkRecords($records);

                return;
            }

            $kept = [];
            $changed = false;
            $now = time();

            foreach ($records['networks'] as $name => $touchedAt) {
                if ($name === $helper || preg_match(self::NAME_PATTERN, $name) !== 1) {
                    $changed = true;
                    continue; // network helper tidak pernah dilepas
                }
                if ($id === null || $this->networkIdByName($name) === null) {
                    $changed = true;
                    continue; // helper mati / network app sudah dihapus
                }
                if ($ttl > 0 && ($now - $touchedAt) >= $ttl) {
                    try {
                        $this->ensureOffNetwork($name);
                    } catch (\Throwable $e) {
                        // Gagal lepas satu network tidak boleh menggagalkan
                        // network lain maupun request; percobaan berikutnya
                        // masih akan mencoba lagi.
                        $kept[$name] = $touchedAt;
                        $this->log('Gagal melepas helper Adminer dari network "' . $name . '": ' . $e->getMessage());
                        continue;
                    }
                    $changed = true;
                    continue;
                }
                $kept[$name] = $touchedAt;
            }

            if ($changed) {
                $this->writeNetworkRecords(['helper' => $records['helper'], 'networks' => $kept]);
            }
        } catch (\Throwable $e) {
            $this->log('Prune network helper Adminer gagal: ' . $e->getMessage());
        }
    }

    /**
     * Catatan waktu pakai network helper (read-only, untuk observabilitas/test).
     *
     * @return array{helper:string,networks:array<string,int>}
     */
    public function networkRecords(): array
    {
        return $this->readNetworkRecords();
    }

    /**
     * Id container helper yang sedang ada ('' → null).
     */
    public function containerId(): ?string
    {
        $container = $this->findContainer();
        if ($container === null) {
            return null;
        }
        $id = (string) ($container['Id'] ?? '');

        return $id !== '' ? $id : null;
    }

    /**
     * Base URL helper yang dijangkau dashboard lewat nama container + port 8080.
     */
    public function baseUrl(): string
    {
        return 'http://' . (string) $this->spec['container'] . ':' . self::PORT;
    }

    /**
     * Hapus helper (lifecycle eksplisit; dipakai teardown/prune). Idempoten —
     * container yang sudah hilang dianggap sukses (404 diabaikan Engine).
     * Catatan waktu attach network dibuang (attachment mati bersama container).
     */
    public function remove(): void
    {
        $id = $this->containerId();
        if ($id === null) {
            return;
        }
        $this->docker->removeContainer($id, true, false);
        $this->writeNetworkRecords(['helper' => '', 'networks' => []]);
    }

    // ==================================================================
    // Pembentuk argv (statik murni — tanpa I/O)
    // ==================================================================

    /**
     * argv `docker run -d …` untuk helper Adminer.
     *
     * Berakhir tepat di `<image>`: image adminer resmi sudah memakai
     * `php -S [::]:8080 -t /var/www/html` sebagai perintahnya, dan
     * `PHP_CLI_SERVER_WORKERS` menambah worker (env, bukan argumen).
     *
     * Tidak ada rahasia di argv (kredensial DB hanya dipakai AdminerProxy).
     *
     * @param array<string,mixed> $spec
     * @return array<int,string>
     */
    public static function buildRunArgv(array $spec): array
    {
        $spec = self::normalizeSpec($spec);

        $image = (string) $spec['image'];
        if ($image === '') {
            throw new InvalidArgumentException(
                'Image Adminer belum dikonfigurasi (ADMINER_IMAGE, default "adminer:6").'
            );
        }

        $container = (string) $spec['container'];
        if ($container === '' || preg_match(self::NAME_PATTERN, $container) !== 1) {
            throw new InvalidArgumentException(
                'Nama container helper Adminer tidak valid: "' . $container . '" (ADMINER_CONTAINER).'
            );
        }

        $network = (string) $spec['network'];
        if ($network === '' || preg_match(self::NAME_PATTERN, $network) !== 1) {
            throw new InvalidArgumentException(
                'Nama network helper Adminer tidak valid: "' . $network . '" (ADMINER_NETWORK).'
            );
        }

        $workers = max(1, (int) $spec['workers']);

        return [
            (string) $spec['docker'],
            'run',
            '-d',
            '--name',
            $container,
            '--label',
            self::LABEL,
            // Tanpa `--rm`: `--rm` + `--restart` = `docker create` exit 125.
            '--restart',
            'unless-stopped',
            '--network',
            $network,
            '-e',
            'PHP_CLI_SERVER_WORKERS=' . $workers,
            '--log-driver',
            'json-file',
            '--log-opt',
            'max-size=10m',
            '--log-opt',
            'max-file=3',
            $image,
        ];
    }

    /**
     * Default spec dari config (tanpa I/O ke Engine).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public static function normalizeSpec(array $overrides): array
    {
        $defaults = [
            'docker' => (string) config('deploy.docker_binary', 'docker'),
            'image' => (string) config('deploy.adminer_image', 'adminer:6'),
            'container' => (string) config('deploy.adminer_container', 'rames-adminer'),
            'network' => (string) config('deploy.adminer_network', 'rames-helpers'),
            'workers' => (int) config('deploy.adminer_workers', 8),
            // Container dashboard (dipakai untuk attach network helper).
            'dashboard' => (string) config('deploy.dashboard_container', 'rames-webman'),
            'command_timeout' => 120,
            // Batas umur attach helper ke network app (detik, 0 = nonaktif).
            'network_ttl' => (int) config('deploy.adminer_network_ttl', 1800),
            // Catatan waktu pakai network (tanpa kredensial apa pun).
            'network_state_file' => runtime_path() . '/adminer-helper/networks.json',
            // Direktori berkas respons proxy (dipangkas oportunistik di sini juga).
            'proxy_temp_dir' => runtime_path() . '/adminer-proxy',
            // Callable(string): void untuk log best-effort (null → support\Log).
            'logger' => null,
        ];

        $spec = array_replace($defaults, array_intersect_key($overrides, $defaults));
        $spec['workers'] = max(1, (int) $spec['workers']);
        $spec['command_timeout'] = max(1, (int) $spec['command_timeout']);
        $spec['network_ttl'] = max(0, (int) $spec['network_ttl']);
        $spec['network_state_file'] = (string) $spec['network_state_file'];
        $spec['proxy_temp_dir'] = (string) $spec['proxy_temp_dir'];

        return $spec;
    }

    /**
     * Network app target (tempat container DB berada) yang harus di-join helper.
     *
     * Statik murni: memilih network user-defined dari hasil `inspectContainer()`,
     * mengabaikan network bawaan (bridge/host/none), dan mengutamakan network
     * milik compose project app.
     *
     * @param array<string,mixed> $inspect hasil `DockerClient::inspectContainer()`
     * @param string              $project nama compose project app (boleh '')
     */
    public static function targetNetwork(array $inspect, string $project = ''): string
    {
        $networks = $inspect['NetworkSettings']['Networks'] ?? [];
        if (!is_array($networks)) {
            return '';
        }

        $candidates = [];
        foreach (array_keys($networks) as $name) {
            $name = (string) $name;
            if ($name === '' || in_array($name, self::BUILTIN_NETWORKS, true)) {
                continue;
            }
            $candidates[] = $name;
        }

        if ($candidates === []) {
            return '';
        }

        if ($project !== '') {
            foreach ($candidates as $name) {
                if ($name === $project . '_default' || str_contains($name, $project)) {
                    return $name;
                }
            }
        }

        return $candidates[0];
    }

    /**
     * Host (nama container, fallback IP) yang bisa dijangkau helper di `$network`.
     *
     * Statik murni. Dipakai sebagai `host` koneksi Adminer: helper dan container
     * DB berada di network yang sama sehingga DNS nama container bekerja.
     *
     * @param array<string,mixed> $inspect
     */
    public static function databaseHost(array $inspect, string $network): string
    {
        $name = ltrim((string) ($inspect['Name'] ?? ''), '/');
        if ($name !== '') {
            return $name;
        }

        $networks = $inspect['NetworkSettings']['Networks'] ?? [];
        $ip = is_array($networks) ? (string) ($networks[$network]['IPAddress'] ?? '') : '';
        if ($ip !== '') {
            return $ip;
        }

        $id = (string) ($inspect['Id'] ?? '');

        return $id !== '' ? substr($id, 0, 12) : '';
    }

    // ==================================================================
    // Helper internal (I/O)
    // ==================================================================

    /**
     * Fail-fast: Engine harus terjangkau sebelum menyentuh apa pun.
     */
    private function assertEngineAvailable(): void
    {
        if (!$this->docker->ping()) {
            throw new RuntimeException(
                'Docker Engine tidak dapat dihubungi (socket ' . (string) config('deploy.docker_socket', '/var/run/docker.sock')
                . ') — helper Adminer tidak bisa dijalankan.'
            );
        }
    }

    /**
     * Pastikan network helper ada (idempoten by name; `internal: true`).
     */
    private function ensureNetworkExists(string $name): void
    {
        if ($this->networkIdByName($name) !== null) {
            return;
        }

        try {
            $this->docker->createNetwork([
                'Name' => $name,
                'Driver' => 'bridge',
                // Tanpa gateway keluar: Adminer tanpa auth tidak boleh terlihat
                // container lain; kebutuhan helper hanya dashboard + network app.
                'Internal' => true,
                'CheckDuplicate' => true,
                'Labels' => ['rames.role' => 'adminer-helper-network'],
            ]);
        } catch (RuntimeException $e) {
            // Race: network dibuat proses lain di antara list & create.
            if ($this->networkIdByName($name) !== null) {
                return;
            }
            throw new RuntimeException('Gagal membuat network helper Adminer "' . $name . '": ' . $e->getMessage(), 0, $e);
        }

        if ($this->networkIdByName($name) === null) {
            throw new RuntimeException('Network helper Adminer "' . $name . '" tidak ditemukan setelah dibuat.');
        }
    }

    /**
     * Id network berdasarkan nama ('' → null).
     */
    private function networkIdByName(string $name): ?string
    {
        foreach ($this->docker->listNetworks() as $network) {
            if ((string) ($network['Name'] ?? '') === $name) {
                $id = (string) ($network['Id'] ?? '');
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Container helper (dengan nama persis) → ringkasan list, atau null.
     *
     * @return array<string,mixed>|null
     */
    private function findContainer(): ?array
    {
        $name = (string) $this->spec['container'];
        if ($name === '') {
            return null;
        }

        try {
            $containers = $this->docker->listContainers(['name' => [$name]]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Gagal membaca daftar container dari Docker Engine: ' . $e->getMessage(), 0, $e);
        }

        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }
            foreach ((array) ($container['Names'] ?? []) as $listed) {
                if (ltrim((string) $listed, '/') === $name) {
                    return $container;
                }
            }
        }

        return null;
    }

    /**
     * Apakah container yang ada memakai image yang diminta.
     *
     * @param array<string,mixed> $container
     */
    private function imageMatches(array $container): bool
    {
        $want = (string) $this->spec['image'];
        $have = (string) ($container['Image'] ?? '');
        if ($want === '' || $have === '') {
            return false;
        }
        if ($want === $have) {
            return true;
        }

        // Docker CLI/Engine bisa melaporkan nama yang sudah dinormalisasi.
        return str_ends_with($have, '/' . $want) || str_ends_with($want, '/' . $have);
    }

    /**
     * Attach container ke network (idempoten, toleran race 403).
     */
    private function attach(string $containerId, string $networkName): void
    {
        if ($containerId === '' || $networkName === '') {
            return;
        }

        try {
            $inspect = $this->docker->inspectContainer($containerId);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Gagal inspect container "' . $containerId . '" saat attach network: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $networks = $inspect['NetworkSettings']['Networks'] ?? [];
        if (is_array($networks) && isset($networks[$networkName])) {
            return; // sudah terhubung (connectContainerToNetwork TIDAK idempoten)
        }

        $networkId = $this->networkIdByName($networkName);
        if ($networkId === null) {
            throw new RuntimeException(
                'Network "' . $networkName . '" tidak ditemukan di Docker Engine — helper Adminer tidak bisa menjangkau container DB.'
            );
        }

        try {
            $this->docker->connectContainerToNetwork($networkId, $containerId);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                return; // race: sudah terhubung
            }
            throw $e;
        }
    }

    /**
     * Attach container dashboard sendiri ke network helper (dashboard harus
     * berbagi network dengan helper agar `http://<container>:8080` resolve).
     */
    private function attachDashboard(string $networkName): void
    {
        $self = (string) $this->spec['dashboard'];
        if ($self === '') {
            throw new RuntimeException(
                'Identitas container dashboard tidak diketahui (HOSTNAME/DASHBOARD_CONTAINER) — tidak bisa attach ke network helper.'
            );
        }

        $this->attach($self, $networkName);
    }

    /**
     * Apakah container sudah ter-attach ke network (guard idempoten).
     */
    private function isAttached(string $containerId, string $networkName): bool
    {
        try {
            $inspect = $this->docker->inspectContainer($containerId);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Gagal inspect container "' . $containerId . '" saat melepas network "' . $networkName . '": ' . $e->getMessage(),
                0,
                $e
            );
        }

        $networks = $inspect['NetworkSettings']['Networks'] ?? [];

        return is_array($networks) && isset($networks[$networkName]);
    }

    /**
     * Error Engine yang berarti "sudah tidak ter-attach" (404 network/endpoint
     * hilang, 403 "not connected") → boleh dianggap sukses.
     */
    private function isAbsentNetworkError(RuntimeException $e): bool
    {
        $message = $e->getMessage();

        foreach (['HTTP 404', 'HTTP 403', 'not connected', 'no such network', 'not found'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    // ==================================================================
    // Catatan waktu pakai network (prune TTL)
    // ==================================================================

    private function stateStore(): JsonStore
    {
        return new JsonStore((string) $this->spec['network_state_file']);
    }

    /**
     * Ketatkan izin berkas/direktori catatan (`0700`/`0600`).
     *
     * Catatan ini tidak memuat kredensial, tetapi memuat topologi (nama network
     * app) — hanya proses dashboard yang perlu membacanya.
     */
    private function tightenState(): void
    {
        $path = (string) $this->spec['network_state_file'];
        $dir = dirname($path);
        if (is_dir($dir)) {
            @chmod($dir, 0700);
        }
        if (is_file($path)) {
            @chmod($path, 0600);
        }
        if (is_file($path . '.bak')) {
            @chmod($path . '.bak', 0600);
        }
    }

    /**
     * Catatan waktu pakai network helper (tanpa kredensial apa pun).
     *
     * Bentuk: `{"helper": "<container id>", "networks": {"<network>": <unix ts>}}`.
     * Berkas hilang/korup diperlakukan sebagai catatan kosong — prune tidak boleh
     * gagal karena state.
     *
     * @return array{helper:string,networks:array<string,int>}
     */
    private function readNetworkRecords(): array
    {
        $empty = ['helper' => '', 'networks' => []];
        try {
            $data = $this->stateStore()->read();
        } catch (\Throwable $e) {
            $this->log('Catatan network helper Adminer tidak bisa dibaca: ' . $e->getMessage());

            return $empty;
        }

        $records = ['helper' => (string) ($data['helper'] ?? ''), 'networks' => []];
        foreach ((array) ($data['networks'] ?? []) as $name => $touchedAt) {
            $name = (string) $name;
            if ($name !== '' && is_numeric($touchedAt) && (int) $touchedAt > 0) {
                $records['networks'][$name] = (int) $touchedAt;
            }
        }

        return $records;
    }

    /**
     * Tulis catatan (atomik + flock lewat {@see JsonStore}). Best-effort.
     *
     * @param array{helper:string,networks:array<string,int>} $records
     */
    private function writeNetworkRecords(array $records): void
    {
        try {
            $this->stateStore()->update(static function (array &$data) use ($records): void {
                $data = $records;
            });
            $this->tightenState();
        } catch (\Throwable $e) {
            $this->log('Catatan network helper Adminer tidak bisa ditulis: ' . $e->getMessage());
        }
    }

    /**
     * Catat waktu pakai terakhir sebuah network app (best-effort; tidak pernah
     * menggagalkan request yang sebenarnya sudah sukses attach).
     */
    private function rememberNetwork(string $networkName, string $helperId): void
    {
        if ($networkName === '' || $helperId === '' || $networkName === (string) $this->spec['network']) {
            return;
        }
        try {
            $this->stateStore()->update(static function (array &$data) use ($helperId, $networkName): void {
                $networks = is_array($data['networks'] ?? null) ? $data['networks'] : [];
                if ((string) ($data['helper'] ?? '') !== $helperId) {
                    // Container helper baru → catatan lama tidak berlaku lagi.
                    $networks = [];
                }
                $data['helper'] = $helperId;
                $networks[$networkName] = time();
                $data['networks'] = $networks;
            });
            $this->tightenState();
        } catch (\Throwable $e) {
            $this->log('Gagal mencatat network helper Adminer "' . $networkName . '": ' . $e->getMessage());
        }
    }

    /**
     * Hapus catatan sebuah network (sudah dilepas/tidak ada).
     */
    private function forgetNetwork(string $networkName): void
    {
        if ($networkName === '') {
            return;
        }
        try {
            $this->stateStore()->update(static function (array &$data) use ($networkName): void {
                if (is_array($data['networks'] ?? null) && array_key_exists($networkName, $data['networks'])) {
                    unset($data['networks'][$networkName]);
                }
            });
            $this->tightenState();
        } catch (\Throwable $e) {
            $this->log('Gagal menghapus catatan network helper Adminer "' . $networkName . '": ' . $e->getMessage());
        }
    }

    /**
     * Log best-effort: spec `logger` bila ada, selain itu `support\Log`. Tidak
     * pernah melempar (mis. tanpa konfigurasi logger di CLI/test).
     */
    private function log(string $message): void
    {
        $logger = $this->spec['logger'] ?? null;
        try {
            if (is_callable($logger)) {
                $logger($message);

                return;
            }
            \support\Log::error('AdminerHelper: ' . $message);
        } catch (\Throwable $e) {
            // Tanpa logger → peringatan hilang, tetapi request tetap jalan.
        }
    }

    /**
     * Nyalakan helper yang sudah ada tapi berhenti.
     */
    private function ensureStarted(string $containerId): void
    {
        $running = false;
        try {
            $inspect = $this->docker->inspectContainer($containerId);
            $running = (bool) ($inspect['State']['Running'] ?? false);
        } catch (\Throwable $e) {
            throw new RuntimeException('Gagal membaca status helper Adminer: ' . $e->getMessage(), 0, $e);
        }
        if ($running) {
            return;
        }

        $argv = [(string) $this->spec['docker'], 'start', (string) $this->spec['container']];
        $this->runArgv($argv, 'Gagal menyalakan helper Adminer (`docker start`)');
    }

    /**
     * Jalankan helper (`docker run -d`) dengan cek exit code.
     */
    private function runHelper(): void
    {
        $this->runArgv(self::buildRunArgv($this->spec), 'Gagal menjalankan helper Adminer (`docker run`)');
    }

    /**
     * Jalankan argv CLI dengan SigchldGuard + cek exit code (pola ResticRunner).
     *
     * @param array<int,string> $argv
     */
    private function runArgv(array $argv, string $errorPrefix): void
    {
        $timeout = (int) $this->spec['command_timeout'];
        $result = SigchldGuard::withDefault(
            fn (): array => $this->runner->run($argv, null, $timeout)
        );

        if ($result['code'] !== 0 || $result['timedOut']) {
            $message = trim((string) ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
            if ($result['timedOut']) {
                $message = 'timeout setelah ' . $timeout . ' detik';
            }

            throw new RuntimeException($errorPrefix . ': ' . ($message !== '' ? $message : 'exit code ' . $result['code']));
        }
    }
}
