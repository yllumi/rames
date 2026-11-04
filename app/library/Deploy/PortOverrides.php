<?php
declare(strict_types=1);

namespace app\library\Deploy;

use app\library\Docker\AppPorts;
use app\library\Storage\AppStore;
use RuntimeException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Override **port container** per service per app — satu-satunya pemilik render
 * & tulis dua lapis berkas override port.
 *
 * ## Mengapa yang diedit adalah port *container*, bukan port host
 *
 * Port **host** adalah kontrak dengan dunia luar app ini: vhost Nginx dashboard
 * me-reverse-proxy domain app ke `127.0.0.1:<host port>` yang dipilih saat app
 * dibuat ({@see AppPorts::primaryHostPort()}). Mengubahnya dari dashboard akan
 * memindahkan target `proxy_pass`, memutus tautan domain/subdomain yang sudah
 * dipakai user, dan gampang bentrok dengan container lain di server yang sama.
 *
 * Port **container** justru milik image/aplikasi itu sendiri: image dari repo
 * compose bisa listen di port yang berbeda dari yang dipikirkan dashboard (mis.
 * `lscr.io/linuxserver/picoclaw:launcher` listen di `3000`, bukan `8080`).
 * Karena itu form "Port container" mengunci host port dan hanya membuka port
 * container untuk diedit; hasilnya dipetakan ke host port yang **sama**
 * (`host:container`), sehingga `proxy_pass` tetap sahih dan tidak ada host port
 * baru yang perlu diperebutkan.
 *
 * Compose **menggabungkan** daftar `ports:` (base + override, bukan mengganti),
 * jadi perubahan peta port perlu dua lapis (docs/SPECS.md §7.2 langkah 8):
 *
 *   1. `docker-compose.override.yml`       → `ports: !reset []` per service
 *      (membuang port bawaan repo; butuh compose v2.6+);
 *   2. `docker-compose.override.ports.yml` → daftar port final
 *      (`[host:]container[/proto]`).
 *
 * Bentuk berkas **identik** dengan yang dulu dirender `AppArtifactControllerBase::
 * writeOverride()` (`Yaml::dump(…, 4, 2)` + `TaggedValue('reset', [])`), dan
 * `AgentArtifactPackager::renderPortOverrides()` kini **mendelegasi** ke kelas
 * ini, supaya app lokal dan app di server agent menerima isi yang sama persis.
 *
 * ## Bentuk baris (row)
 *
 * ```
 * {service:string, container:int, host:?int, protocol:string, raw:?string, editable:bool}
 * ```
 *
 *  - `host` null = port hanya diekspos (tanpa publish ke host) — **tanpa
 *    identitas host**, jadi tidak ada yang bisa diedit dari dashboard;
 *  - `raw` = entri asli dari berkas compose/override (null untuk baris yang
 *    berasal dari metadata `host_ports`);
 *  - `editable` false = entri non-sederhana (port range, IP bind, bentuk tak
 *    terbaca). Entri itu **selalu** ditulis ulang apa adanya (`raw`) dan port
 *    containernya tidak boleh diubah dari dashboard — menormalkannya akan
 *    mengubah port yang dipublikasikan tanpa persetujuan user.
 *
 * ## Sumber baris (lihat {@see self::rows()})
 *
 *  1. metadata `host_ports` app (rencana host port app di server **agent** —
 *     satu-satunya sumber di sana karena direktori app tidak ada di host ini);
 *  2. berkas override port di disk (port hasil edit user yang sedang berlaku),
 *     **digabung** dengan service baru dari base compose;
 *  3. base compose app (app yang belum pernah diedit, mis. app lama).
 *
 * Stateless (statik murni) untuk render/perencanaan; I/O hanya di `rows()`
 * (baca), `write()`/`persist()`/`persistMetadata()` (tulis) — aman untuk worker
 * Webman persistent.
 */
final class PortOverrides
{
    /** Dua lapis berkas override port, urut prioritas compose. */
    public const OVERRIDE_FILES = [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE];

    /** Protokol default compose bila entri tidak menyebut `/proto`. */
    public const DEFAULT_PROTOCOL = 'tcp';

    /** Protokol yang dikenali compose (selain ini diperlakukan sebagai tak terbaca). */
    public const PROTOCOLS = ['tcp', 'udp', 'sctp'];

    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;

    // ==================================================================
    // Baca baris
    // ==================================================================

    /**
     * Daftar baris port app — sumber berurutan:
     *
     *  1. `$app['host_ports']` (metadata app server agent; dipakai walau
     *     `$dir === ''`), bentuk `[{service,container,host,protocol?}]`, `host`
     *     boleh null; **satu-satunya** sumber (tanpa penggabungan — host ini
     *     tidak punya base compose app agent);
     *  2. `$dir/docker-compose.override.ports.yml` bila ada (port yang berlaku)
     *     **digabung** dengan base compose `ComposeSource::resolveMainFile()`:
     *     service yang belum punya baris di override (mis. service baru dari
     *     `git pull`) tetap tampil dari base. Urutan: baris override dulu, lalu
     *     service baru dari base (urutan base). Service yang sudah ada di
     *     override **tidak** diambil dari base sama sekali;
     *  3. base compose `ComposeSource::resolveMainFile()` di `$dir` bila ada
     *     (app yang belum pernah diedit, mis. app lama).
     *
     * Urutan baris = urutan service lalu urutan entri; duplikat entri identik
     * (service + container + protokol yang sama) dibuang — entri pertama yang
     * menang. Base yang gagal di-parse diabaikan (bukan kegagalan fatal).
     *
     * @param array<string,mixed> $app
     * @param string              $dir          direktori app ('' = tanpa filesystem)
     * @param array<int,string>   $composeFiles daftar compose_files app (opsional;
     *                                          menentukan nama base yang dibaca)
     * @return array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>
     */
    public static function rows(array $app, string $dir = '', array $composeFiles = []): array
    {
        $metadata = self::rowsFromMetadata($app);
        if ($metadata !== []) {
            return $metadata;
        }

        if ($dir === '') {
            return [];
        }

        $override = rtrim($dir, '/') . '/' . ComposeSource::PORTS_OVERRIDE_FILE;
        if (is_file($override)) {
            $parsed = self::parseFile($override);
            if ($parsed !== []) {
                return array_merge($parsed, self::rowsForMissingServices($parsed, $dir, $composeFiles));
            }
        }

        $baseName = self::baseComposeName($dir, $composeFiles);
        $base = rtrim($dir, '/') . '/' . $baseName;
        if ($baseName !== '' && is_file($base)) {
            return self::parseFile($base);
        }

        return [];
    }

    /**
     * Baris port dari base compose untuk service yang **belum** muncul di baris
     * sumber lain (mis. override port) — supaya service baru yang ditambahkan
     * repo lewat `git pull` tetap terlihat & bisa diubah dari dashboard.
     *
     * Urutan mengikuti service di base. Base yang tidak ada / gagal di-parse
     * menghasilkan `[]` (bukan exception).
     *
     * @param array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}> $existing
     * @param array<int,string> $composeFiles
     * @return array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>
     */
    private static function rowsForMissingServices(array $existing, string $dir, array $composeFiles): array
    {
        $known = [];
        foreach ($existing as $row) {
            $known[(string) $row['service']] = true;
        }

        $baseName = self::baseComposeName($dir, $composeFiles);
        $base = rtrim($dir, '/') . '/' . $baseName;
        if ($baseName === '' || !is_file($base)) {
            return [];
        }

        $extra = [];
        foreach (self::parseFile($base) as $row) {
            if (isset($known[(string) $row['service']])) {
                continue;   // service sudah diwakili override (jangan duplikasi)
            }
            $extra[] = $row;
        }

        return $extra;
    }

    /**
     * Nama file compose utama app (`compose_files` → deteksi di direktori).
     *
     * @param array<int,string> $composeFiles
     */
    private static function baseComposeName(string $dir, array $composeFiles): string
    {
        $baseName = ComposeSource::mainFileFrom($composeFiles);
        if ($baseName !== '') {
            return $baseName;
        }

        return ComposeSource::resolveMainFile($dir, ['compose_files' => $composeFiles]);
    }

    /**
     * Baris dari hasil `ComposeParser::parse()['services']` (jalur create app &
     * editor compose). Semua baris `editable=true`, `raw=null`; protokol diambil
     * dari entri (`tcp` default). Urutan mengikuti service lalu entri.
     *
     * Tidak melakukan dedupe: jalur ini harus menghasilkan override yang sama
     * persis dengan perilaku lama `AppArtifactControllerBase::writeOverride()`.
     *
     * @param array<string,array<string,mixed>> $services
     * @return array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>
     */
    public static function rowsFromServices(array $services): array
    {
        $rows = [];
        foreach ($services as $service => $config) {
            $name = trim((string) $service);
            if ($name === '' || !is_array($config)) {
                continue;
            }
            foreach ((array) ($config['ports'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $container = (int) ($entry['container'] ?? 0);
                if ($container < self::MIN_PORT || $container > self::MAX_PORT) {
                    continue;
                }
                $host = isset($entry['host']) ? (int) $entry['host'] : 0;
                $protocol = strtolower(trim((string) ($entry['protocol'] ?? self::DEFAULT_PROTOCOL)));

                $rows[] = [
                    'service' => $name,
                    'container' => $container,
                    'host' => $host >= self::MIN_PORT && $host <= self::MAX_PORT ? $host : null,
                    'protocol' => in_array($protocol, self::PROTOCOLS, true) ? $protocol : self::DEFAULT_PROTOCOL,
                    'raw' => null,
                    'editable' => true,
                ];
            }
        }

        return $rows;
    }

    // ==================================================================
    // Render & tulis
    // ==================================================================

    /**
     * Render dua lapis override dari baris port — **murni**, tanpa I/O.
     *
     * `reset` hanya memuat service yang punya ≥1 baris (service tanpa baris
     * tidak perlu di-reset). `ports` menulis entri final: `raw` apa adanya untuk
     * baris non-editable, `host:container` / `container` untuk baris editable,
     * plus sufiks `/proto` hanya bila protokolnya bukan `tcp`.
     *
     * Baris kosong ⇒ `services: {  }` pada kedua dokumen (bentuk yang sama
     * dengan perilaku lama `AppArtifactControllerBase::writeOverride()` saat tanpa port).
     *
     * @param array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}> $rows
     * @return array{reset:string,ports:string}
     */
    public static function render(array $rows): array
    {
        $reset = ['services' => []];
        $ports = ['services' => []];

        foreach ($rows as $row) {
            $service = trim((string) ($row['service'] ?? ''));
            if ($service === '') {
                continue;
            }

            $reset['services'][$service]['ports'] = new TaggedValue('reset', []);
            $ports['services'][$service]['ports'][] = self::renderEntry($row);
        }

        return [
            'reset' => Yaml::dump($reset, 4, 2),
            'ports' => Yaml::dump($ports, 4, 2),
        ];
    }

    /**
     * Tulis kedua berkas override ke direktori app.
     *
     * Baris kosong ⇒ **hapus** kedua berkas (app tanpa port terpublish) dan
     * kembalikan false supaya pemanggil tidak menambahkan entri `compose_files`
     * yang file-nya tidak ada.
     *
     * @param array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}> $rows
     * @return bool true = berkas ditulis
     * @throws RuntimeException berkas gagal ditulis
     */
    public static function write(string $dir, array $rows): bool
    {
        $dir = rtrim($dir, '/');

        if ($rows === []) {
            foreach (self::OVERRIDE_FILES as $name) {
                $path = $dir . '/' . $name;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            return false;
        }

        $yaml = self::render($rows);
        $rendered = [
            ComposeSource::RESET_OVERRIDE_FILE => $yaml['reset'],
            ComposeSource::PORTS_OVERRIDE_FILE => $yaml['ports'],
        ];

        foreach ($rendered as $name => $yaml) {
            if (file_put_contents($dir . '/' . $name, $yaml, LOCK_EX) === false) {
                throw new RuntimeException('Gagal menulis ' . $name . '.');
            }
        }

        return true;
    }

    // ==================================================================
    // Rencana & persist
    // ==================================================================

    /**
     * Hitung rencana port dari input form.
     *
     * Identitas baris = **host port** (unik per app, tidak pernah berubah):
     * `$posted` = `ports[<service>][<host>] = "<port container>"` (nilai string).
     * Nilai kosong/null = port container lama dipertahankan. Host port itu
     * sendiri **tidak pernah** diubah oleh method ini — nilainya diambil apa
     * adanya dari `$rows`.
     *
     * Fail-fast (`RuntimeException`, pesan menyebut service/host) bila:
     *  - service+host tidak ada di `$rows` (input asing / halaman basi);
     *  - baris hanya-diekspos (`host === null`, tanpa identitas host) dikirimi
     *    nilai — tidak ada yang bisa dipetakan;
     *  - baris non-editable (port range / IP bind / bentuk tak terbaca)
     *    dikirimi nilai berbeda;
     *  - nilai bukan angka murni atau di luar 1..65535;
     *  - pasangan (host, port container) menjadi duplikat di dalam rencana.
     *
     * Port container **boleh** sama pada host port berbeda (`8080:80` dan
     * `8081:80`) — Docker mengizinkannya.
     *
     * `$usedPorts` (host port milik app **lain** di server yang sama) sengaja
     * **tidak** dipakai lagi untuk menolak: host port tidak pernah berubah,
     * jadi operasi ini tidak mungkin menciptakan konflik host baru. Parameter
     * dipertahankan di signature supaya pemanggil tidak perlu berubah.
     *
     * @param array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}> $rows
     * @param array<int|string,mixed> $posted
     * @param array<int,int|string>   $usedPorts tidak dipakai (lihat catatan di atas)
     * @return array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>
     */
    public static function plan(array $rows, array $posted, array $usedPorts): array
    {
        // Identitas form = host port; baris tanpa host tidak punya identitas dan
        // tidak bisa dialamatkan input (dikumpulkan terpisah untuk pesan jelas).
        $byHost = [];
        $exposed = [];
        foreach ($rows as $i => $row) {
            $service = (string) $row['service'];
            if ($row['host'] === null) {
                $key = $service . '|' . (int) $row['container'];
                if (!isset($exposed[$key])) {
                    $exposed[$key] = $i;
                }
                continue;
            }
            $key = $service . '|' . (int) $row['host'];
            if (!isset($byHost[$key])) {
                $byHost[$key] = $i;
            }
        }

        $containers = [];
        foreach ($rows as $i => $row) {
            $containers[$i] = (int) $row['container'];
        }

        foreach ($posted as $rawService => $entries) {
            $service = trim((string) $rawService);
            if (!is_array($entries)) {
                throw new RuntimeException(
                    'Format port tidak valid pada service "' . $service . '": setiap service wajib berupa '
                    . 'map {host port: port container}, bukan nilai skalar.'
                );
            }

            foreach ($entries as $rawHost => $value) {
                $hostKey = trim((string) $rawHost);
                $host = ctype_digit($hostKey) ? (int) $hostKey : 0;
                $validHost = $host >= self::MIN_PORT && $host <= self::MAX_PORT;

                $index = $validHost ? ($byHost[$service . '|' . $host] ?? null) : null;
                if ($index === null) {
                    if ($validHost && isset($exposed[$service . '|' . $host])) {
                        throw new RuntimeException(
                            'Port ' . $host . ' pada service "' . $service . '" hanya diekspos di jaringan '
                            . 'container (tidak dipublikasikan ke host), jadi tidak ada host port yang bisa '
                            . 'dipakai sebagai identitas — port ini tidak bisa diubah dari dashboard.'
                        );
                    }
                    throw new RuntimeException(
                        'Host port ' . ($hostKey === '' ? '(kosong)' : $hostKey)
                        . ' pada service "' . $service . '" tidak dikenal untuk app ini. '
                        . 'Muat ulang halaman lalu coba lagi.'
                    );
                }

                if ($value !== null && !is_scalar($value)) {
                    throw new RuntimeException(
                        'Port container untuk host port ' . $host . ' pada service "' . $service
                        . '" tidak sah (harus angka 1-' . self::MAX_PORT . ').'
                    );
                }

                $text = trim((string) $value);
                if ($text === '') {
                    continue;   // kosong = pertahankan port container lama
                }

                $row = $rows[$index];

                if (!$row['editable']) {
                    $unchanged = (int) $row['container'] >= self::MIN_PORT
                        && $text === (string) (int) $row['container'];
                    if (!$unchanged) {
                        throw new RuntimeException(self::lockedMessage($row));
                    }
                    continue;
                }

                if (!ctype_digit($text)) {
                    throw new RuntimeException(
                        'Port container "' . $text . '" untuk host port ' . $host . ' pada service "'
                        . $service . '" tidak sah (harus angka 1-' . self::MAX_PORT . ').'
                    );
                }
                $container = (int) $text;
                if ($container < self::MIN_PORT || $container > self::MAX_PORT) {
                    throw new RuntimeException(
                        'Port container "' . $text . '" untuk host port ' . $host . ' pada service "'
                        . $service . '" di luar rentang 1-' . self::MAX_PORT . '.'
                    );
                }

                $containers[$index] = $container;
            }
        }

        $plan = [];
        foreach ($rows as $i => $row) {
            $plan[] = [
                'service' => (string) $row['service'],
                'container' => $containers[$i],
                'host' => $row['host'] === null ? null : (int) $row['host'],
                'protocol' => (string) $row['protocol'],
                'raw' => $row['raw'],
                'editable' => (bool) $row['editable'],
            ];
        }

        self::assertNoDuplicateMappings($plan);

        return $plan;
    }

    /**
     * Simpan port app **lokal**: `rows()` → `plan()` → `write()` → metadata.
     *
     * Berkas override ditulis **sebelum** `apps.json` diperbarui: gagal menulis
     * ⇒ metadata tidak berubah (tidak ada entri `compose_files` yatim). Entri
     * `OVERRIDE_FILES` lama dibuang lebih dulu (urutan kanonik lewat
     * {@see ComposeSource::orderFiles()}) lalu ditambahkan lagi hanya bila
     * berkasnya benar-benar ditulis.
     *
     * `primary_port` (= port container yang di-proxy Nginx) ikut diperbarui bila
     * pemetaan yang sedang di-proxy berubah port containernya: host port target
     * `proxy_pass` dihitung **sebelum** perubahan
     * ({@see AppPorts::primaryHostPort()}), lalu baris rencana dengan host itu —
     * dan service `primary_service` bila diisi — menjadi sumber `primary_port`
     * baru. Pemetaan lain tidak menyentuh `primary_port`.
     *
     * @param array<string,mixed>     $app
     * @param array<int|string,mixed> $posted
     * @param array<int,int|string>   $usedPorts tidak dipakai (host port tidak berubah)
     * @return array{rows:array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>,app:array<string,mixed>}
     * @throws RuntimeException rencana tidak sah atau berkas gagal ditulis
     */
    public static function persist(AppStore $store, array $app, string $dir, array $posted, array $usedPorts): array
    {
        $id = self::appId($app);
        $proxiedHost = self::proxiedHostPort($app);
        $primaryService = (string) ($app['primary_service'] ?? '');

        $plan = self::plan(
            self::rows($app, $dir, (array) ($app['compose_files'] ?? [])),
            $posted,
            $usedPorts
        );
        $written = self::write($dir, $plan);

        $files = self::withoutOverrideEntries((array) ($app['compose_files'] ?? []));
        if ($written) {
            $files = array_merge($files, self::OVERRIDE_FILES);
        }
        $files = ComposeSource::orderFiles($files);

        $store->update($id, static function (array &$s) use ($files, $plan, $proxiedHost, $primaryService): void {
            $s['compose_files'] = $files;
            $primaryPort = self::primaryPortFromPlan($plan, $proxiedHost, $primaryService);
            if ($primaryPort !== null) {
                $s['primary_port'] = $primaryPort;
            }
        });

        return ['rows' => $plan, 'app' => $store->find($id) ?? $app];
    }

    /**
     * Simpan port app di server **agent** (tanpa filesystem): `plan()` lalu tulis
     * metadata saja — `host_ports` bentuk kanonik `[{service,container,host}]`
     * untuk **semua** baris (`host` null dipertahankan), ditambah `protocol`
     * **hanya** bila bukan `tcp` (entri TCP tetap ringkas; entri lama tanpa
     * `protocol` = tcp). `compose_files` dirapikan seperti
     * {@see self::persist()} (entri `OVERRIDE_FILES` hanya ada bila rencana tidak
     * kosong). `host_ports` dihapus bila rencana kosong.
     *
     * `primary_port` diperbarui dengan aturan yang sama seperti
     * {@see self::persist()} (port container hasil edit yang ikut tersimpan di
     * `host_ports`).
     *
     * @param array<string,mixed>     $app
     * @param array<int|string,mixed> $posted
     * @param array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}> $rows
     * @param array<int,int|string>   $usedPorts tidak dipakai (host port tidak berubah)
     * @return array{rows:array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>,app:array<string,mixed>}
     * @throws RuntimeException rencana tidak sah
     */
    public static function persistMetadata(AppStore $store, array $app, array $posted, array $rows, array $usedPorts): array
    {
        $id = self::appId($app);
        $proxiedHost = self::proxiedHostPort($app);
        $primaryService = (string) ($app['primary_service'] ?? '');

        $plan = self::plan($rows, $posted, $usedPorts);

        $metadata = [];
        foreach ($plan as $row) {
            $entry = [
                'service' => $row['service'],
                'container' => $row['container'],
                'host' => $row['host'],
            ];
            $protocol = self::normalizeProtocol($row['protocol'] ?? null);
            if ($protocol !== self::DEFAULT_PROTOCOL) {
                // Protokol non-tcp wajib ikut metadata: tanpa itu `apply_ports`
                // merender ulang port UDP sebagai TCP dan datagram berhenti.
                $entry['protocol'] = $protocol;
            }
            $metadata[] = $entry;
        }

        $files = self::withoutOverrideEntries((array) ($app['compose_files'] ?? []));
        if ($metadata !== []) {
            $files = array_merge($files, self::OVERRIDE_FILES);
        }
        $files = ComposeSource::orderFiles($files);

        $store->update($id, static function (array &$s) use ($files, $metadata, $plan, $proxiedHost, $primaryService): void {
            $s['compose_files'] = $files;
            if ($metadata === []) {
                unset($s['host_ports']);
            } else {
                $s['host_ports'] = $metadata;
            }
            $primaryPort = self::primaryPortFromPlan($plan, $proxiedHost, $primaryService);
            if ($primaryPort !== null) {
                $s['primary_port'] = $primaryPort;
            }
        });

        return ['rows' => $plan, 'app' => $store->find($id) ?? $app];
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /**
     * Baris dari metadata `host_ports` app (rencana host port server agent).
     *
     * Protokol dibaca per entri bila ada (entri lama tanpa `protocol` = `tcp`;
     * nilai tak dikenal juga diperlakukan `tcp` — toleran karena ini metadata
     * lokal, validasi ketat rencana deploy ada di
     * `AgentArtifactPackager::hostPortPlan()`).
     *
     * Toleran terhadap dokumen app yang tidak lengkap: entri yang bukan map /
     * service kosong / port tidak sah **dilewati** (bukan melempar).
     *
     * @param array<string,mixed> $app
     * @return array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>
     */
    private static function rowsFromMetadata(array $app): array
    {
        $raw = $app['host_ports'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $service = is_scalar($entry['service'] ?? null) ? trim((string) $entry['service']) : '';
            $container = is_scalar($entry['container'] ?? null) ? (int) $entry['container'] : 0;
            if ($service === '' || $container < self::MIN_PORT || $container > self::MAX_PORT) {
                continue;
            }

            $rawHost = $entry['host'] ?? null;
            $host = $rawHost === null ? null : (int) (is_scalar($rawHost) ? $rawHost : 0);
            if ($host !== null && ($host < self::MIN_PORT || $host > self::MAX_PORT)) {
                $host = null;
            }

            $protocol = self::DEFAULT_PROTOCOL;
            if (is_scalar($entry['protocol'] ?? null)) {
                $candidate = strtolower(trim((string) $entry['protocol']));
                if (in_array($candidate, self::PROTOCOLS, true)) {
                    $protocol = $candidate;
                }
            }

            $rows[] = [
                'service' => $service,
                'container' => $container,
                'host' => $host,
                'protocol' => $protocol,
                'raw' => null,
                'editable' => true,
            ];
        }

        return self::dedupe($rows);
    }

    /**
     * Parse berkas compose / override menjadi baris port (semua service).
     *
     * Berkas yang tidak ada / tidak bisa di-parse ⇒ `[]` (pemanggil memperlakukan
     * sebagai "tidak ada port", bukan kegagalan fatal — halaman port tetap bisa
     * dibuka).
     *
     * @return array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>
     */
    private static function parseFile(string $path): array
    {
        try {
            $data = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($data) || !is_array($data['services'] ?? null)) {
            return [];
        }

        $rows = [];
        foreach ($data['services'] as $service => $config) {
            $name = trim((string) $service);
            if ($name === '' || !is_array($config)) {
                continue;
            }
            $ports = $config['ports'] ?? null;
            if (!is_array($ports)) {
                continue;   // absen / tag YAML tak dikenal (!reset dll.)
            }
            foreach ($ports as $entry) {
                $row = is_string($entry)
                    ? self::parseShortEntry($name, $entry)
                    : self::parseLongEntry($name, $entry);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return self::dedupe($rows);
    }

    /**
     * Parse sintaks pendek `[ip:][host:]container[/proto]`.
     *
     * Hanya bentuk sederhana (`container`, `host:container`, boleh ber-`/proto`)
     * yang `editable=true`. Bentuk lain (IP bind, port range, bagian tak
     * terbaca) tetap dikembalikan dengan `editable=false` + `raw` aslinya.
     *
     * @return array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}|null
     */
    private static function parseShortEntry(string $service, string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        $protocol = self::DEFAULT_PROTOCOL;
        if (preg_match('#^(?<rest>.*)/(?<proto>[A-Za-z]+)$#', $text, $match) === 1) {
            $candidate = strtolower($match['proto']);
            if (in_array($candidate, self::PROTOCOLS, true)) {
                $protocol = $candidate;
                $text = $match['rest'];
            }
        }

        $parts = explode(':', $text);
        $containerRaw = trim((string) end($parts));
        $hostRaw = count($parts) >= 2 ? trim((string) $parts[count($parts) - 2]) : null;

        $container = ctype_digit($containerRaw) ? (int) $containerRaw : 0;
        $host = $hostRaw !== null && ctype_digit($hostRaw) ? (int) $hostRaw : null;

        $simple = count($parts) <= 2
            && $container >= self::MIN_PORT && $container <= self::MAX_PORT
            && ($host === null
                ? count($parts) === 1
                : ($host >= self::MIN_PORT && $host <= self::MAX_PORT));

        return [
            'service' => $service,
            'container' => $container,
            'host' => $host !== null && $host >= self::MIN_PORT && $host <= self::MAX_PORT ? $host : null,
            'protocol' => $protocol,
            'raw' => $raw,
            'editable' => $simple,
        ];
    }

    /**
     * Parse sintaks panjang (`{target, published, protocol}`). Dukungan dibatasi:
     * entri berbentuk map **tidak** bisa disunting dari dashboard (bentuk asli
     * tidak bisa direproduksi sebagai string pendek), jadi hasilnya selalu
     * `editable=false` dan `raw` = bentuk pendek setara (`[host:]container[/proto]`)
     * yang dirender apa adanya — semantik port tetap sama dengan berkas aslinya.
     *
     * @return array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}|null
     */
    private static function parseLongEntry(string $service, mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $container = isset($entry['target']) && is_scalar($entry['target']) ? (int) $entry['target'] : 0;
        if ($container < self::MIN_PORT || $container > self::MAX_PORT) {
            return null;
        }
        $published = isset($entry['published']) && is_scalar($entry['published']) ? (int) $entry['published'] : 0;
        $host = $published >= self::MIN_PORT && $published <= self::MAX_PORT ? $published : null;

        $protocol = strtolower(trim((string) ($entry['protocol'] ?? self::DEFAULT_PROTOCOL)));
        if (!in_array($protocol, self::PROTOCOLS, true)) {
            $protocol = self::DEFAULT_PROTOCOL;
        }

        $raw = ($host !== null ? $host . ':' : '') . $container
            . ($protocol !== self::DEFAULT_PROTOCOL ? '/' . $protocol : '');

        return [
            'service' => $service,
            'container' => $container,
            'host' => $host,
            'protocol' => $protocol,
            'raw' => $raw,
            'editable' => false,
        ];
    }

    /**
     * Entri YAML untuk satu baris port.
     *
     * @param array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool} $row
     */
    private static function renderEntry(array $row): string
    {
        $raw = $row['raw'] ?? null;
        if (is_string($raw) && ($row['editable'] ?? true) === false) {
            return $raw;
        }

        $container = (int) ($row['container'] ?? 0);
        $host = $row['host'] ?? null;
        $entry = $host !== null ? $host . ':' . $container : (string) $container;

        $protocol = (string) ($row['protocol'] ?? self::DEFAULT_PROTOCOL);
        if ($protocol !== '' && $protocol !== self::DEFAULT_PROTOCOL) {
            $entry .= '/' . $protocol;
        }

        return $entry;
    }

    /**
     * Host port yang sedang menjadi target `proxy_pass` Nginx — dihitung
     * **sebelum** rencana diterapkan (dari `apps.json` versi lama).
     *
     * Null bila app tidak punya host port sama sekali (mis. app yang hanya
     * mengekspos port di jaringan container) — tanpa host tidak ada mapping yang
     * di-proxy, jadi `primary_port` tidak perlu disentuh.
     *
     * @param array<string,mixed> $app
     */
    private static function proxiedHostPort(array $app): ?int
    {
        try {
            return AppPorts::primaryHostPort($app);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Port container baru untuk `primary_port` bila mapping yang di-proxy ikut
     * berubah port containernya.
     *
     * `$proxiedHost` null (app tanpa host port) ⇒ tidak ada yang di-proxy.
     * `$primaryService` kosong ⇒ filter service diabaikan (data app lama tanpa
     * `primary_service`), sama seperti {@see AppPorts::primaryHostPort()}.
     *
     * @param array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}> $plan
     */
    private static function primaryPortFromPlan(array $plan, ?int $proxiedHost, string $primaryService): ?int
    {
        if ($proxiedHost === null) {
            return null;
        }

        foreach ($plan as $row) {
            if ($row['host'] !== $proxiedHost) {
                continue;
            }
            if ($primaryService !== '' && (string) $row['service'] !== $primaryService) {
                continue;
            }

            return (int) $row['container'];
        }

        return null;
    }

    /**
     * Normalisasi protokol port ke bentuk kanonik (`tcp`/`udp`/`sctp`).
     *
     * Nilai kosong / tak dikenal / bukan skalar ⇒ `tcp` (protokol default
     * compose). Murni.
     */
    private static function normalizeProtocol(mixed $value): string
    {
        if (!is_scalar($value)) {
            return self::DEFAULT_PROTOCOL;
        }
        $candidate = strtolower(trim((string) $value));

        return in_array($candidate, self::PROTOCOLS, true) ? $candidate : self::DEFAULT_PROTOCOL;
    }

    /**
     * Buang duplikat entri identik (service + port container + protokol). Untuk
     * baris yang port containernya tak terbaca (`0`), `raw` ikut jadi kunci
     * supaya entri berbeda tidak saling menghapus.
     *
     * Protokol ikut jadi kunci: `3000/tcp` dan `3000/udp` pada service yang sama
     * adalah dua port berbeda dan tidak boleh saling menghapus.
     *
     * @param array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}> $rows
     * @return array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}>
     */
    private static function dedupe(array $rows): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = $row['service'] . '|' . $row['container'] . '|' . (string) $row['protocol'];
            if ($row['container'] === 0) {
                $key .= '|' . (string) $row['raw'];
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Tolak pasangan (host port, port container) yang sama dua kali — host yang
     * dipertahankan, jadi duplikat hanya mungkin muncul dari dua baris berbeda
     * yang mengarah ke pemetaan identik. Port container yang sama pada host
     * **berbeda** tetap sah (`8080:80` + `8081:80`).
     *
     * @param array<int,array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool}> $plan
     * @throws RuntimeException pasangan (host, container) duplikat
     */
    private static function assertNoDuplicateMappings(array $plan): void
    {
        $seen = [];
        foreach ($plan as $row) {
            $host = $row['host'];
            if ($host === null) {
                continue;
            }
            $key = $host . '|' . $row['container'];
            $label = $row['service'] . ' (host port ' . $host . ', port container ' . $row['container'] . ')';
            if (isset($seen[$key])) {
                throw new RuntimeException(
                    'Pemetaan host port ' . $host . ' → port container ' . $row['container']
                    . ' dipakai lebih dari sekali pada app ini: ' . $seen[$key] . ' dan ' . $label . '.'
                );
            }
            $seen[$key] = $label;
        }
    }

    /**
     * Pesan penolakan untuk baris non-editable (port range / IP bind / bentuk
     * tak terbaca).
     *
     * @param array{service:string,container:int,host:?int,protocol:string,raw:?string,editable:bool} $row
     */
    private static function lockedMessage(array $row): string
    {
        $label = is_string($row['raw'] ?? null) && $row['raw'] !== ''
            ? $row['raw']
            : (string) $row['container'];

        return 'Port ' . $label . ' pada service ' . $row['service']
            . ($row['host'] !== null ? ' (host port ' . (int) $row['host'] . ')' : '')
            . ' tidak bisa diubah dari dashboard (bentuk entri di compose tidak sederhana).';
    }

    /**
     * Buang entri override port dari daftar compose_files (urutan entri lain
     * dipertahankan) — dasar penyusunan ulang urutan kanonik.
     *
     * @param array<int,mixed> $files
     * @return array<int,string>
     */
    private static function withoutOverrideEntries(array $files): array
    {
        $kept = [];
        foreach ($files as $file) {
            $name = (string) $file;
            if ($name === '' || in_array($name, self::OVERRIDE_FILES, true)) {
                continue;
            }
            $kept[] = $name;
        }

        return $kept;
    }

    /**
     * @param array<string,mixed> $app
     * @throws RuntimeException app tanpa id (tidak bisa disimpan ke store)
     */
    private static function appId(array $app): string
    {
        $id = trim((string) ($app['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('App tanpa id — port tidak bisa disimpan.');
        }

        return $id;
    }
}
