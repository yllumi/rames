<?php
declare(strict_types=1);

namespace app\library\Billing;

use app\library\Agent\AgentError;
use app\library\Auth\UserStore;
use app\library\Deploy\ResourceLimits;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use DateTimeImmutable;
use Throwable;

/**
 * Meteran pemakaian per app — akrual inkremental tiap tick (bukan selisih
 * timestamp) supaya tahan restart worker dan tidak membengkakkan tagihan bila
 * dashboard sempat mati.
 *
 * Aturan:
 * - hanya app `status === 'running'` yang diakru;
 * - app milik owner ber-role global **admin** dilewati (admin gratis);
 * - **bukti container dari server app** (multi-server F4, misi
 *   `ms-f4b-metering-server`): `status` di control plane saja tidak cukup —
 *   container app wajib **teramati hidup (`running`/`paused`/`restarting`) di
 *   server tempat app itu hidup**
 *   (`UsageProbe`: Engine host ini untuk `kind=local`, `AgentDeployer::containerStats()`
 *   untuk `kind=agent`). Tanpa bukti itu (container mati / server tidak
 *   terjangkau), app **dilewati** dan alasannya dicatat ke log/report — lihat
 *   `sampleReport()`;
 * - `credits_pending += (seconds / 3600) × harga_per_jam(limits app)`;
 * - `backup_credits_pending += gb_ditagih × tarif_backup × (seconds / periode)`
 *   — akumulasi disimpan **4 desimal** (bukan 2) agar bias pembulatan per tick
 *   tidak terakumulasi jadi drift bulanan; pembulatan uang 2 desimal dilakukan
 *   saat penagihan (`Invoicer`). (GB ditagih dari `BackupUsage`, lihat catatan
 *   di bawah);
 * - `period` diinisialisasi ke bulan berjalan saat baris pertama dibuat
 *   (**tanpa** tagihan retroaktif);
 * - `name`/`owner_id` disegarkan tiap tick (snapshot untuk app yang kemudian
 *   dihapus agar tetap bisa ditagih).
 *
 * Backup volume ditagih **per GB per bulan**, diakru per tick bersama CPU/RAM
 * dengan aturan kelayakan yang sama (app running & owner non-admin). Basis GB
 * dibaca sekali per tick dari `BackupUsage` (`BackupRegistry`, tanpa Docker),
 * lengkap dengan floor minimum per app. Aturan `status`/admin sengaja **tidak**
 * diubah: app berhenti/ber-owner admin juga tidak diakru backup.
 *
 * Harga memakai `Pricing` dengan **tarif per server** app (`ServerRates`):
 * server tempat app berjalan boleh punya tarif `rates` sendiri; key yang tidak
 * diisi memakai tarif global. Nilai tarif dihitung **saat tick** (tanpa
 * pembekuan historis): perubahan tarif server langsung berlaku pada akrual
 * berikutnya, sedangkan akrual yang sudah tersimpan tidak dihitung ulang.
 * Parsing `limits` memakai `ResourceLimits` (satu sumber kebenaran validasi &
 * penulisan limit CPU/RAM).
 *
 * **Kebijakan server tidak terjangkau (dipilih sadar).** Bila metrik satu server
 * gagal dibaca (offline/timeout/config cacat), seluruh app di server itu
 * **dilewati pada tick ini** — tidak ada akrual, dan alasannya ditulis ke log
 * billing + dilaporkan `sampleReport()`. Alasan memilih "lewati" ketimbang
 * "pakai nilai terakhir / tetap menagih penuh":
 *  1. akrual menuntut bukti container berjalan; menagih saat tak teramati bisa
 *     menagih app yang sudah mati (over-count) — jebakan yang justru dilarang;
 *  2. akrual bersifat **inkremental per tick** (tidak menyimpan utang detik),
 *     jadi interval yang dilewati **tidak pernah** ditagihkan kemudian —
 *     tick berikutnya hanya menambah detiknya sendiri. Tidak ada akrual ganda
 *     maupun tunggakan tersembunyi;
 *  3. melewati tanpa menulis log akan jadi "menagih nol secara diam-diam" —
 *     karena itu setiap server yang dilewati **selalu** dicatat (log/report).
 * Interval yang hilang karena server mati adalah kerugian terukur yang diterima
 * operator; alternatifnya (menebak "tetap berjalan") menagih tanpa bukti.
 */
class UsageMeter
{
    private AppStore $apps;
    private BillingStore $store;
    private UserStore $users;
    private BackupUsage $backup;
    private UsageProbe $probe;
    private ?ServerRates $serverRates;

    /** @var callable|null fn(string $message): void */
    private $logger;

    /**
     * `$serverRates` opsional aditif (parameter terakhir, BC-safe): tarif per
     * server app. `null` ⇒ dibuat lazy memakai store server dari path
     * `AppStore` yang disuntik (satu basis data SQLite dengan registry server),
     * sehingga tes ber-store temp tidak pernah membaca registry runtime nyata.
     */
    public function __construct(
        ?AppStore $apps = null,
        ?BillingStore $store = null,
        ?UserStore $users = null,
        ?BackupUsage $backup = null,
        ?UsageProbe $probe = null,
        ?callable $logger = null,
        ?ServerRates $serverRates = null,
    ) {
        $this->apps = $apps ?? new AppStore();
        $this->store = $store ?? new BillingStore();
        $this->users = $users ?? new UserStore();
        $this->backup = $backup ?? new BackupUsage();
        $this->probe = $probe ?? new UsageProbe();
        $this->logger = $logger;
        $this->serverRates = $serverRates;
    }

    /**
     * Akrual `$seconds` detik pemakaian untuk seluruh app yang memenuhi syarat.
     *
     * Pintasan dari {@see self::sampleReport()} (bentuk lama `array<int,string>`
     * dipertahankan: `cli/billing.php usage`/pemanggil lama tidak berubah).
     *
     * @return array<int,string> daftar appId yang diakru
     */
    public function sample(int $seconds, ?string $now = null): array
    {
        return $this->sampleReport($seconds, $now)['accrued'];
    }

    /**
     * Varian `sample()` yang mengembalikan laporan lengkap satu tick: app yang
     * diakru, app yang **dilewati beserta alasannya**, dan ringkasan per server.
     *
     * Dipakai `BillingRunner` (log alasan) & tes. Inilah satu-satunya tempat
     * keputusan "boleh diakru" diambil: app harus (a) `status === running`,
     * (b) owner non-admin, dan (c) container-nya **teramati hidup di server
     * pemilik app** (`UsageProbe`). Syarat (c) berlaku untuk CPU/RAM **dan**
     * backup (aturan kelayakan yang sama), sehingga app yang hidup di server
     * agent tidak lagi luput dari akrual hanya karena container-nya tidak ada di
     * host control plane.
     *
     * @return array{
     *   accrued: array<int,string>,
     *   skipped: array<string,array{server_id:string,cause:string,detail:string}>,
     *   servers: array<string,array<string,mixed>>
     * }
     */
    public function sampleReport(int $seconds, ?string $now = null): array
    {
        $report = ['accrued' => [], 'skipped' => [], 'servers' => []];
        if ($seconds <= 0) {
            return $report;
        }
        if (!(bool) config('deploy.billing_enabled', true)) {
            return $report;
        }

        $current = BillingPeriod::current($now);
        $rates = $this->serverRates();
        // Dihitung sekali per tick: jumlah hari periode & GB ditagih per app.
        $daysInPeriod = BillingPeriod::daysIn($current);
        $chargedGb = $this->backup->chargedGigabytesByApp();

        // 1. Calon: app yang layak menurut status + owner (syarat lama).
        $candidates = [];
        foreach ($this->apps->all() as $app) {
            if (!is_array($app) || (string) ($app['status'] ?? '') !== 'running') {
                continue;
            }
            $appId = (string) ($app['id'] ?? '');
            $ownerId = (string) ($app['owner_id'] ?? '');
            if ($appId === '' || $ownerId === '') {
                continue;
            }
            $owner = $this->users->findById($ownerId);
            if ($owner === null || $this->users->isAdmin($owner)) {
                continue; // owner tidak diketahui / admin gratis
            }

            $gb = (float) ($chargedGb[$appId] ?? 0.0);
            $serverId = AppStore::serverIdOf($app);
            $candidates[$appId] = [
                'name' => (string) ($app['name'] ?? ''),
                'owner_id' => $ownerId,
                'server_id' => $serverId,
                'credits' => ($seconds / 3600) * Pricing::hourlyCredits(
                    ResourceLimits::of($app),
                    $rates->ratesFor($serverId)
                ),
                'backup_gb' => $gb,
                'backup_credits' => Pricing::backupCredits(
                    $gb,
                    (float) $seconds,
                    $daysInPeriod,
                    $rates->backupMonthlyFor($serverId)
                ),
            ];
        }

        if ($candidates === []) {
            return $report;
        }

        // 2. Bukti dari server app (satu pembacaan per server, bukan per app).
        [$verdicts, $servers] = $this->observe($candidates);

        $pending = [];
        foreach ($candidates as $appId => $row) {
            $verdict = is_array($verdicts[$appId] ?? null) ? $verdicts[$appId] : null;
            if ($verdict === null || ($verdict['container_running'] ?? false) !== true) {
                // Tidak ada bukti container berjalan → lewati (dengan alasan).
                $report['skipped'][$appId] = [
                    'server_id' => (string) ($verdict['server_id'] ?? $row['server_id']),
                    'cause' => (string) ($verdict['cause'] ?? UsageProbe::CAUSE_UNREACHABLE),
                    'detail' => (string) ($verdict['detail'] ?? 'tidak ada bukti container berjalan dari server app'),
                ];
                continue;
            }
            $pending[$appId] = $row;
        }
        $report['servers'] = $servers;

        // 3. Tulis akrual (satu transaksi atomik, seperti sebelumnya).
        if ($pending !== []) {
            $sampledAt = $this->timestamp($now);
            $this->store->update(function (array &$data) use ($pending, $current, $sampledAt, $seconds): void {
                $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
                foreach ($pending as $appId => $row) {
                    $existing = is_array($usage[$appId] ?? null) ? $usage[$appId] : [];
                    $period = (string) ($existing['period'] ?? '');
                    if ($period === '') {
                        // Baris pertama bulan ini: mulai dari nol, tanpa tagihan retroaktif.
                        $period = $current;
                    }
                    $usage[$appId] = [
                        'name' => $row['name'],
                        'owner_id' => $row['owner_id'],
                        // Atribusi per server (pelaporan/agregasi); arti kolom
                        // lain tidak berubah. `server_id` dipertahankan saat
                        // penagihan mereset baris (`Invoicer`).
                        'server_id' => $row['server_id'],
                        'period' => $period,
                        'seconds_pending' => (int) ($existing['seconds_pending'] ?? 0) + $seconds,
                        'credits_pending' => round((float) ($existing['credits_pending'] ?? 0.0) + $row['credits'], 2),
                        'backup_gb' => round((float) $row['backup_gb'], 4),
                        // Backup diakru per tick: pembulatan 4 desimal (bukan 2) agar
                        // bias naik per tick tidak terakumulasi jadi drift nyata
                        // (uang tetap 2 desimal saat ditagih — `Invoicer`).
                        'backup_credits_pending' => round((float) ($existing['backup_credits_pending'] ?? 0.0) + $row['backup_credits'], 4),
                        'sampled_at' => $sampledAt,
                    ];
                }
                $data['usage'] = $usage;
            });
        }

        $report['accrued'] = array_keys($pending);
        if ($report['skipped'] !== []) {
            $this->logSkipped($report['skipped'], $servers);
        }

        return $report;
    }

    /**
     * Ringkasan pemakaian berjalan per app (untuk UI): `owner_id`, `name`,
     * `server_id`, `seconds_pending`, `credits_pending`, `hourly`,
     * `estimate_month`, plus rincian backup `backup_gb`, `backup_credits`,
     * `backup_monthly`.
     *
     * Dikunci berdasarkan appId (kunci sama dengan `usage` di store billing).
     * `estimate_month` = estimasi CPU/RAM bulan berjalan **+** estimasi backup
     * bulan berjalan. Baris lama tanpa field backup diperlakukan `0` (tidak
     * error); baris lama tanpa `server_id` memakai server app saat ini.
     *
     * @return array<string,array{owner_id:string,name:string,server_id:string,seconds_pending:int,credits_pending:float,hourly:float,estimate_month:float,backup_gb:float,backup_credits:float,backup_monthly:float}>
     */
    public function summary(): array
    {
        $rates = $this->serverRates();
        $summary = [];

        foreach ($this->store->usage() as $appId => $row) {
            $appId = (string) $appId;
            if (!is_array($row)) {
                continue;
            }
            $app = $this->apps->find($appId);
            $limits = $app !== null ? ResourceLimits::of($app) : [];
            $serverId = $this->serverIdOf($row, $app);
            $hourly = Pricing::hourlyCredits($limits, $rates->ratesFor($serverId));

            $period = (string) ($row['period'] ?? '');
            if ($period === '') {
                $period = BillingPeriod::current();
            }

            $backupGb = (float) ($row['backup_gb'] ?? 0.0);
            $backupMonthly = Pricing::backupMonthlyEstimate(
                $backupGb,
                $rates->backupMonthlyFor($serverId)
            );

            $summary[$appId] = [
                'owner_id' => (string) ($row['owner_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'server_id' => $serverId,
                'seconds_pending' => (int) ($row['seconds_pending'] ?? 0),
                'credits_pending' => round((float) ($row['credits_pending'] ?? 0.0), 2),
                'hourly' => $hourly,
                'estimate_month' => round($hourly * 24 * BillingPeriod::daysIn($period) + $backupMonthly, 2),
                'backup_gb' => $backupGb,
                'backup_credits' => round((float) ($row['backup_credits_pending'] ?? 0.0), 2),
                'backup_monthly' => $backupMonthly,
            ];
        }

        return $summary;
    }

    /**
     * Resolver tarif per server (lazy, satu instance per meteran). Store server
     * memakai path `AppStore` yang sama supaya uji temp tidak membaca registry
     * runtime nyata.
     */
    private function serverRates(): ServerRates
    {
        return $this->serverRates ??= new ServerRates(new ServerStore($this->apps->path()));
    }

    /**
     * `server_id` satu baris usage: dari baris (ditulis meteran), atau — untuk
     * baris lama (data sebelum F4) — diturunkan dari app saat ini (satu app
     * terikat satu server; tidak ada pemindahan app antar server).
     *
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $app
     */
    private function serverIdOf(array $row, ?array $app): string
    {
        $serverId = trim((string) ($row['server_id'] ?? ''));
        if ($serverId !== '') {
            return $serverId;
        }

        return $app !== null ? AppStore::serverIdOf($app) : '';
    }

    /**
     * Ambil bukti container dari `UsageProbe` dengan pengaman berlapis: kegagalan
     * pengamat (bug/IO tak terduga) **tidak** mematikan tick dan **tidak**
     * menagih tanpa bukti — semua app dianggap "tak teramati" (dilewati, dengan
     * alasan yang tetap dicatat).
     *
     * @param array<string,array<string,mixed>> $candidates appId => baris calon
     * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>}
     */
    private function observe(array $candidates): array
    {
        try {
            $observation = $this->probe->observe($candidates);
            $servers = is_array($observation['servers'] ?? null) ? $observation['servers'] : [];
            $apps = is_array($observation['apps'] ?? null) ? $observation['apps'] : [];
        } catch (Throwable $e) {
            $detail = 'pengamat container gagal: '
                . AgentError::sanitize($e->getMessage());
            $verdicts = [];
            foreach ($candidates as $appId => $row) {
                $verdicts[$appId] = [
                    'server_id' => $row['server_id'],
                    'container_running' => false,
                    'cause' => UsageProbe::CAUSE_UNREACHABLE,
                    'detail' => $detail,
                ];
            }

            return [$verdicts, []];
        }

        $verdicts = [];
        foreach ($apps as $appId => $verdict) {
            if (is_array($verdict)) {
                $verdicts[(string) $appId] = $verdict;
            }
        }
        // Jaring pengaman: app yang tidak dijawab probe tidak pernah diakru.
        foreach ($candidates as $appId => $row) {
            if (!isset($verdicts[$appId])) {
                $verdicts[$appId] = [
                    'server_id' => $row['server_id'],
                    'container_running' => false,
                    'cause' => UsageProbe::CAUSE_UNREACHABLE,
                    'detail' => 'server app tidak melaporkan status app ini',
                ];
            }
        }

        return [$verdicts, $servers];
    }

    /**
     * Catat alasan pelewatan akrual — satu baris per server (bukan per app) agar
     * log tidak membanjir saat satu server mati.
     *
     * @param array<string,array{server_id:string,cause:string,detail:string}> $skipped
     * @param array<string,array<string,mixed>>                                $servers
     */
    private function logSkipped(array $skipped, array $servers): void
    {
        $byServer = [];
        foreach ($skipped as $appId => $row) {
            $byServer[$row['server_id']][$row['cause']][] = (string) $appId;
        }

        foreach ($byServer as $serverId => $byCause) {
            $server = is_array($servers[$serverId] ?? null) ? $servers[$serverId] : [];
            $reasons = [];
            $apps = [];
            foreach ($byCause as $cause => $appIds) {
                $reasons[] = $cause;
                $apps = array_merge($apps, $appIds);
            }
            $this->log(sprintf(
                'metering: %d app di server "%s" (%s) dilewati tanpa akrual — alasan: %s%s [%s]',
                count($apps),
                $serverId,
                (string) ($server['kind'] ?? 'unknown'),
                implode(', ', array_unique($reasons)),
                ($server['error'] ?? null) !== null ? ' — ' . (string) $server['error'] : '',
                implode(', ', $apps)
            ));
        }
    }

    /**
     * Tulis satu baris ke log billing. Default `$logger` menulis ke
     * `runtime/logs/billing/{Y-m-d}.log` (konvensi `AppStopper`; `billing_log_path`
     * dapat mengarahkannya saat tes). Kegagalan menulis log diabaikan — log
     * bukan jalur uang.
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);

            return;
        }

        $configured = trim((string) config('deploy.billing_log_path', ''));
        $dir = $configured !== '' ? rtrim($configured, '/') : runtime_path('logs/billing');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $dir . '/' . date('Y-m-d') . '.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Timestamp ISO 8601 untuk `sampled_at`: pakai `$now` bila diberikan,
     * selain itu waktu sekarang.
     */
    private function timestamp(?string $now): string
    {
        if ($now === null || trim($now) === '') {
            return date('c');
        }
        try {
            return (new DateTimeImmutable($now))->format('c');
        } catch (Throwable) {
            return date('c');
        }
    }
}
