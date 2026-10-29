<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

// Mode runtime (multi-server F0). Nilai dinormalkan HANYA di config/deploy.php
// (`config('deploy.mode')`); file ini dibaca oleh Config::load *bersama* file
// konfigurasi lain sehingga `config()` belum terisi saat dievaluasi — jadi array
// deploy dibaca langsung dari sumbernya agar tidak ada parsing env kedua yang
// bisa menyimpang.
$ramesDeployConfig = require __DIR__ . '/deploy.php';
$ramesMode = (string) ($ramesDeployConfig['mode'] ?? 'dashboard');
$ramesIsAgent = $ramesMode === 'agent';

$ramesProcesses = [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => cpu_count() * 4,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],
];

/*
|--------------------------------------------------------------------------
| Listener TLS khusus mode `agent` (keputusan 5 & 6)
|--------------------------------------------------------------------------
| Mode `dashboard` (default) TIDAK menyentuh apa pun di atas: proses `webman`
| tetap `listen => 'http://0.0.0.0:8787'` + `context => []`, persis seperti
| sebelum gerbang mode ada.
|
| Mode `agent` mengganti HANYA tiga hal pada proses `webman`:
|   - `listen`  → `http://<AGENT_BIND>` (default `0.0.0.0:8443`);
|   - `transport` → `ssl` (Workerman: skema pada `listen` adalah protokol
|     APLIKASI — `https://` bukan skema yang dikenal Workerman, `class
|     \Workerman\Protocols\Https not exist`; TLS dinyalakan lewat `transport`);
|   - `context` → `ssl.local_cert`/`local_pk` + `verify_peer => false`.
|
| TLS diterminasi di proses agent sendiri (keputusan 6): sertifikat yang di-pin
| control plane adalah sertifikat agent, bukan sertifikat Nginx host.
|
| **Fail-fast**: cert/key wajib ada & terbaca. Tanpa itu startup DIBATALKAN
| dengan pesan jelas (`RuntimeException` saat `config()` dimuat = saat start),
| bukan diam-diam jalan tanpa TLS. Tidak ada opsi mematikan TLS: nilai kosong
| maupun penanda "mati" (`0`, `none`, `off`, `false`, `no`) sama-sama ditolak.
*/
if ($ramesIsAgent) {
    // Normalisasi AGENT_BIND: skema opsional (dibuang), tanpa garis miring
    // penutup. Bentuk akhir wajib `IP:PORT`.
    $ramesAgentBind = rtrim((string) preg_replace('#^https?://#i', '', trim((string) ($ramesDeployConfig['agent_bind'] ?? ''))), '/');
    if ($ramesAgentBind === '' || !str_contains($ramesAgentBind, ':')) {
        throw new RuntimeException(
            'Mode agent: AGENT_BIND tidak valid ("' . $ramesAgentBind . '"). Pakai bentuk IP:PORT, mis. 0.0.0.0:8443 '
            . '(atau jalankan agent/install.sh yang mengisinya otomatis).'
        );
    }

    // Nilai yang jelas-jelas berarti "tanpa TLS" — ditolak eksplisit supaya
    // tidak ada jalur "start tanpa TLS" yang bisa aktif tanpa disadari.
    $ramesTlsOffValues = ['', '0', 'none', 'off', 'false', 'no'];
    $ramesTlsFiles = [
        'AGENT_TLS_CERT' => (string) ($ramesDeployConfig['agent_tls_cert'] ?? ''),
        'AGENT_TLS_KEY' => (string) ($ramesDeployConfig['agent_tls_key'] ?? ''),
    ];
    foreach ($ramesTlsFiles as $ramesTlsEnv => $ramesTlsPath) {
        if (in_array(strtolower(trim($ramesTlsPath)), $ramesTlsOffValues, true)) {
            throw new RuntimeException(
                "Mode agent menuntut TLS: env {$ramesTlsEnv} belum diisi. TLS diterminasi di proses agent sendiri "
                . '(tidak ada mode tanpa TLS). Jalankan agent/install.sh sekali di server ini — installer membuat '
                . 'sertifikat self-signed dan mengisi AGENT_TLS_CERT/AGENT_TLS_KEY lewat secrets/agent.env.'
            );
        }
        if (!is_file($ramesTlsPath) || !is_readable($ramesTlsPath)) {
            throw new RuntimeException(
                "Mode agent menuntut TLS: berkas {$ramesTlsEnv} \"{$ramesTlsPath}\" tidak ada atau tidak terbaca. "
                . 'Berkas itu harus ada di path yang SAMA di host & di container (bind "${PWD}:${PWD}") — jalankan '
                . 'agent/install.sh (atau perbaiki AGENT_TLS_CERT/AGENT_TLS_KEY di secrets/agent.env) sebelum start.'
            );
        }
    }

    $ramesProcesses['webman']['listen'] = 'http://' . $ramesAgentBind;
    $ramesProcesses['webman']['transport'] = 'ssl';
    $ramesProcesses['webman']['context'] = [
        'ssl' => [
            'local_cert' => $ramesTlsFiles['AGENT_TLS_CERT'],
            'local_pk' => $ramesTlsFiles['AGENT_TLS_KEY'],
            // Self-signed + pinning fingerprint di control plane (keputusan 5):
            // agent tidak meminta/memverifikasi sertifikat klien.
            'verify_peer' => false,
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| Timer khusus dashboard — TIDAK dipasang di mode `agent`
|--------------------------------------------------------------------------
| Ketiganya hanya bermakna bagi control plane (yang memiliki user/billing/store
| otoritatif) dan menimbulkan efek samping yang tidak diinginkan bila jalan di
| server agent (mis. menagih meteran untuk app yang bukan miliknya, atau
| membuat snapshot basis data yang bukan kebenaran):
|   - `extra.update-check`  → cek pembaruan dashboard (badge nav sidebar);
|   - `extra.billing`       → akrual meteran + penagihan + auto-stop saldo;
|   - `extra.db-backup`     → backup berkala SQLite dashboard.
| Timer lokal yang tetap dipasang di kedua mode: `webman` (HTTP worker) dan
| `monitor` (pemantau berkas + memori Workerman — dev/reload, bukan fitur
| dashboard; sudah mati sendiri saat jalan dengan `-d`).
*/
if (!$ramesIsAgent) {
    // Cek pembaruan dashboard berkala untuk badge di nav sidebar (docs/SPECS.md §7.8).
    // Sengaja TIDAK memakai `git fetch` (itu menulis .git sebagai root di dalam
    // container → file milik root di repo milik user host) melainkan
    // `git ls-remote` yang tidak menyentuh repo lokal. Interval 0 = proses tidak
    // dijalankan (cek manual lewat tombol di halaman /nginx tetap bisa).
    $ramesProcesses['update-check'] = [
        'handler' => app\process\UpdateCheckProcess::class,
        'reloadable' => true,
        'count' => 1,
    ];
    // Penjadwal billing (docs/SPECS.md §7.12): akrual meteran + penagihan periode +
    // auto-stop saldo negatif + konsiliasi order top-up. Billing mati
    // (`BILLING_ENABLED=false`) atau meteran dimatikan (`BILLING_SAMPLE_SECONDS=0`)
    // → timer tidak dipasang sama sekali. Semua error ditangkap proses (tidak
    // boleh mematikan worker).
    $ramesProcesses['billing'] = [
        'handler' => app\process\BillingProcess::class,
        'reloadable' => true,
        'count' => 1,
    ];
    // Backup berkala basis data SQLite (snapshot VACUUM INTO + retensi).
    // Tick tiap 300 dtk; hanya jalan bila `DB_BACKUP_ENABLED=true` dan jadwal
    // harian `DB_BACKUP_HOUR` belum terlewat untuk hari ini. Semua error
    // ditangkap proses (tidak boleh mematikan worker). CATATAN: proses BARU →
    // butuh restart (bukan reload) saat pertama kali di-deploy.
    $ramesProcesses['db-backup'] = [
        'handler' => app\process\DbBackupProcess::class,
        'reloadable' => true,
        'count' => 1,
    ];
    // Antrean aktivasi SSL (docs/SPECS.md §8a): satu job certbot pada satu
    // waktu lintas seluruh domain (lock global certbot + rate limit Let's
    // Encrypt). Controller hanya menulis job; proses ini yang menjalankannya
    // tiap 5 dtk. Satu worker (`count=1`) sehingga tidak ada penerbitan paralel.
    // Semua error ditangkap proses (tidak boleh mematikan worker). CATATAN:
    // proses BARU → butuh restart (bukan reload) saat pertama kali di-deploy.
    $ramesProcesses['ssl-queue'] = [
        'handler' => app\process\SslQueueProcess::class,
        'reloadable' => true,
        'count' => 1,
    ];
}

return $ramesProcesses;
