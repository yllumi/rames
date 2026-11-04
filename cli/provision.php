#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Worker provisioning server agent lewat Ansible (opsi B).
 *
 *   php cli/provision.php <jobId>
 *
 * Dipanggil detached oleh controller provisioning (batch 3) — tidak pernah
 * dijalankan dari request HTTP. Alur:
 *
 *   queued → running (stage `preflight`)
 *     tulis `<workRoot>/<jobId>/inventory.yml` + `extra_vars.yml` (0600)
 *   stage `run`
 *     helper container `ansible-playbook` (lihat {@see AnsibleRunner}) — output
 *     streaming masuk `runtime/logs/provision/<jobId>.log`
 *   stage `register` (opsional)
 *     bila playbook `provision-agent`, `deploy.provision_auto_register` aktif,
 *     dan Ansible menyalin `onboarding.txt` ⇒ daftarkan server ke control plane
 *     ({@see ProvisionOnboarding::register()}) lalu tautkan job ke baris server
 *     yang dibuat (`server_id`) dan tampilkan `agent_version` di pesan selesai
 *   stage `done` / status `error`
 *
 * Direktori kerja job **selalu** dibersihkan (`finally`) karena berisi
 * `onboarding.txt` dengan token agent + PEM. Worker tidak pernah mencetak isi
 * berkas rahasia ke stdout/stderr.
 */

use app\library\Provision\AnsibleRunner;
use app\library\Provision\HostKeyPinner;
use app\library\Provision\ProvisionInput;
use app\library\Provision\ProvisionJobStore;
use app\library\Provision\ProvisionLog;
use app\library\Provision\ProvisionOnboarding;
use app\library\Provision\ServerSshKeyStore;
use Symfony\Component\Yaml\Yaml;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$jobId = trim((string) ($argv[1] ?? ''));
if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $jobId) !== 1) {
    fwrite(STDERR, "Usage: php cli/provision.php <jobId>\n");
    exit(1);
}

$store = new ProvisionJobStore();

// Job yang tertinggal `running` karena worker sebelumnya mati (host reboot,
// container recreate) tidak boleh memblokir antrean selamanya.
//
// Ambang WAJIB lebih besar dari timeout satu run Ansible: worker lain bisa
// menjalankan housekeeping ini saat job kita masih hidup, dan menandainya
// `error` di tengah provision adalah kegagalan palsu. `+300` = margin untuk
// fase build/pull image + registrasi.
try {
    $staleSeconds = max(
        (int) config('deploy.provision_stale_seconds', 3600),
        (int) config('deploy.ansible_timeout', 1800) + 300
    );
    $store->failStaleRunning($staleSeconds);
} catch (\Throwable) {
    // Housekeeping bersifat best-effort — jangan gagalkan job yang sah.
}

$job = $store->find($jobId);
if ($job === null) {
    fwrite(STDERR, "Job provision tidak ditemukan: {$jobId}\n");
    exit(1);
}

$status = (string) ($job['status'] ?? '');
if (!in_array($status, ['queued', 'running'], true)) {
    // Sudah selesai/dibatalkan proses lain — bukan kegagalan worker, jadi kode
    // keluar 0 supaya supervisor/pemanggil tidak mengulang tanpa henti.
    fwrite(STDERR, "Job {$jobId} berstatus {$status}; worker tidak dijalankan.\n");
    exit(0);
}

$logDir = (string) config('deploy.provision_log_dir', '');
$log = new ProvisionLog($logDir !== '' ? $logDir : null);

/**
 * Catat tahap ke job + log. Kegagalan menulis log (mis. direktori milik uid
 * lain) tidak boleh menggagalkan provisioning.
 */
$stage = static function (string $stage, string $message) use ($store, $log, $jobId): void {
    try {
        $store->update($jobId, ['stage' => $stage, 'message' => $message]);
    } catch (\Throwable) {
        // store bisa saja sudah kehilangan baris (dihapus operator) — lanjut.
    }
    try {
        $log->append($jobId, "[{$stage}] {$message}");
    } catch (\Throwable) {
        // id sudah divalidasi; kegagalan I/O log bukan alasan menghentikan job.
    }
};

$exitCode = 0;
$jobDir = '';
$runner = null;

try {
    $store->markRunning($jobId);
    $stage('preflight', "Menyiapkan berkas kerja untuk job {$jobId}.");

    // Satu sumber path known_hosts untuk pinning (HostKeyPinner) dan mount
    // helper (AnsibleRunner) — kalau berbeda, pin tidak akan terlihat Ansible.
    $knownHostsPath = (string) (config('deploy.provision_known_hosts')
        ?: config('deploy.git_known_hosts')
        ?: base_path() . '/database/keys/known_hosts');

    $runner = new AnsibleRunner(
        null,
        null,
        (string) (config('deploy.provision_work_dir') ?: runtime_path('provision')),
        null,
        $knownHostsPath
    );

    $jobDir = $runner->workRoot() . '/' . $jobId;
    if (!is_dir($jobDir) && !@mkdir($jobDir, 0700, true) && !is_dir($jobDir)) {
        throw new RuntimeException('Direktori kerja job tidak bisa dibuat (periksa izin `deploy.provision_work_dir`).');
    }
    @chmod($jobDir, 0700);

    $name = trim((string) ($job['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Job provisioning tidak memuat nama server (`name`).');
    }
    $playbook = trim((string) ($job['playbook'] ?? ''));
    if (trim((string) ($job['host'] ?? '')) === '' || trim((string) ($job['ssh_user'] ?? '')) === '') {
        throw new RuntimeException('Job provisioning tidak memuat host/ssh_user target.');
    }
    $autoRegister = (bool) config('deploy.provision_auto_register', true);

    // ---------------------------------------------------------------- input
    // Rekonstruksi payload form dari kolom job (satu-satunya sumber kebenaran
    // setelah request selesai — worker tidak menyimpan request di memori).
    $controlPlaneIps = $job['control_plane_ips'] ?? [];
    if (is_string($controlPlaneIps)) {
        $raw = trim($controlPlaneIps);
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $controlPlaneIps = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $raw);
    }
    $controlPlaneIps = is_array($controlPlaneIps) ? array_values(array_filter(
        array_map(static fn (mixed $v): string => trim((string) $v), $controlPlaneIps),
        static fn (string $v): bool => $v !== ''
    )) : [];

    $input = [
        'name' => $name,
        'host' => trim((string) ($job['host'] ?? '')),
        'ssh_user' => trim((string) ($job['ssh_user'] ?? '')),
        'ssh_port' => (int) ($job['ssh_port'] ?? 22),
        'domain' => trim((string) ($job['domain'] ?? '')),
        'port_range_start' => (int) ($job['port_range_start'] ?? 0),
        'port_range_end' => (int) ($job['port_range_end'] ?? 0),
        'agent_port' => (int) ($job['agent_port'] ?? 0),
        'control_plane_ips' => $controlPlaneIps,
        'manage_firewall' => filter_var($job['manage_firewall'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'playbook' => trim((string) ($job['playbook'] ?? '')),
        // Field opsional yang tidak punya kolom di `provision_jobs`: kosong ⇒
        // `ProvisionInput::extraVars()` tidak mengirimnya dan default role yang
        // berlaku (mis. ADMIN_EMAIL/bind dipertahankan dari secrets server).
        'email' => trim((string) ($job['email'] ?? '')),
        'bind' => trim((string) ($job['bind'] ?? '')),
        'ssl_challenge' => trim((string) ($job['ssl_challenge'] ?? '')),
        'ssl_ca_server' => trim((string) ($job['ssl_ca_server'] ?? '')),
    ];

    // ------------------------------------------------------------ inventory
    // `rames_agent_ip` = IPv4 untuk SAN sertifikat & URL onboarding. Job hanya
    // menyimpan `host`; bila host berupa IPv4, nilai itu dipakai apa adanya.
    $agentIp = trim((string) ($job['ip'] ?? ''));
    if ($agentIp === '' && filter_var($input['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $agentIp = $input['host'];
    }
    $input['ip'] = $agentIp;

    $hostVars = [
        'ansible_host' => $input['host'],
        'ansible_user' => $input['ssh_user'],
        'ansible_port' => $input['ssh_port'],
    ];
    if ($agentIp !== '') {
        $hostVars['rames_agent_ip'] = $agentIp;
    }

    $inventory = ['agents' => ['hosts' => [$name => $hostVars]]];
    $inventoryYaml = Yaml::dump($inventory, 4, 2);
    if (@file_put_contents($jobDir . '/inventory.yml', $inventoryYaml, LOCK_EX) === false) {
        throw new RuntimeException('Berkas inventory.yml tidak bisa ditulis.');
    }
    @chmod($jobDir . '/inventory.yml', 0600);

    // `extra_vars.yml` = kontrak variabel role `rames_agent` (satu sumber:
    // ProvisionInput). Tidak berisi token/PEM — berkas onboarding terpisah.
    $extraVars = ProvisionInput::extraVars($input);
    if ($autoRegister && $playbook === 'provision-agent' && !array_key_exists('rames_agent_onboarding_out', $extraVars)) {
        // Lokasi di dalam container helper = mount `<jobDir>:/job:rw`; worker
        // membaca berkas yang sama dari host setelah playbook selesai. Tidak
        // menimpa nilai yang sudah ditetapkan ProvisionInput.
        $extraVars['rames_agent_onboarding_out'] = '/job/onboarding.txt';
    }
    $extraVarsYaml = Yaml::dump($extraVars, 4, 2);
    if (@file_put_contents($jobDir . '/extra_vars.yml', $extraVarsYaml, LOCK_EX) === false) {
        throw new RuntimeException('Berkas extra_vars.yml tidak bisa ditulis.');
    }
    @chmod($jobDir . '/extra_vars.yml', 0600);

    // ------------------------------------------------------------ host key
    // TOFU + pin SEBELUM playbook: AnsibleRunner memakai
    // `StrictHostKeyChecking=yes`, jadi tanpa entri di known_hosts koneksi
    // pertama gagal membingungkan. Kunci privat TIDAK pernah dibaca di sini.
    $stage('hostkey', "Memeriksa host key SSH {$input['host']}:{$input['ssh_port']} ...");
    $pinner = new HostKeyPinner(null, new ServerSshKeyStore(dirname($knownHostsPath)));
    $pin = $pinner->pin($name, $input['host'], (int) $input['ssh_port']);
    if ($pin['host_key_changed']) {
        // Jangan menimpa pin lama: kunci berganti bisa berarti server dipasang
        // ulang (sah, verifikasi manual) atau MITM. Operator memutuskan.
        throw new RuntimeException(
            'host key server berubah — verifikasi manual; kunci tidak diperbarui'
        );
    }
    $stage(
        'hostkey',
        ($pin['pinned'] ? 'Host key dipin (TOFU)' : 'Host key sudah dipin (cocok)')
        . '; fingerprint ' . ($pin['fingerprint'] !== '' ? $pin['fingerprint'] : 'tidak terbaca')
        . ' — bandingkan manual dengan server tujuan.'
    );

    // ---------------------------------------------------------------- run
    $stage('run', "Menjalankan playbook {$playbook} untuk {$name} ...");

    $result = $runner->run($job, $jobDir, static function (string $chunk) use ($log, $jobId): void {
        try {
            $log->append($jobId, $chunk);
        } catch (\Throwable) {
            // output mentah Ansible bukan alasan menggagalkan playbook.
        }
    });

    if ($result['code'] !== 0) {
        $current = $store->find($jobId);
        if ((string) ($current['status'] ?? '') === 'cancelled') {
            // Pembatalan (batch 3) membunuh container ⇒ exit code ≠ 0. Status
            // `cancelled` tidak boleh ditimpa menjadi `error`.
            $log->append($jobId, 'Job dibatalkan operator; worker berhenti.');
        } else {
            throw new RuntimeException(
                'ansible-playbook berhenti dengan kode ' . $result['code'] . '. Lihat log provisioning untuk detail.'
            );
        }
    } else {
        // ------------------------------------------------------------ register
        $agentVersion = '';
        $onboardingFile = $jobDir . '/onboarding.txt';
        if ($playbook === 'provision-agent' && $autoRegister && is_file($onboardingFile)) {
            $stage('register', 'Mendaftarkan server agent ke control plane ...');
            $document = ProvisionOnboarding::register($job, $onboardingFile);
            $agentVersion = trim((string) ($document['agent_version'] ?? ''));
            // Tautkan job ke baris server yang baru dibuat: kolom `server_id`
            // dipakai `latestForServer()`/`activeForServer()` (status UI) dan
            // indeks `idx_provision_jobs_server`.
            $registeredId = trim((string) ($document['id'] ?? ''));
            if ($registeredId !== '') {
                try {
                    $store->update($jobId, ['server_id' => $registeredId]);
                } catch (\Throwable) {
                    // Kegagalan menautkan tidak boleh menggagalkan registrasi
                    // yang sudah sukses (server tetap terdaftar & terverifikasi).
                }
            }
        }

        $doneMessage = $agentVersion !== ''
            ? "Provisioning selesai; agent terdaftar (versi {$agentVersion})."
            : 'Provisioning selesai.';
        $store->markDone($jobId, $doneMessage);
        try {
            $log->append($jobId, "[done] {$doneMessage}");
        } catch (\Throwable) {
            // log bersifat diagnostik
        }
    }
} catch (\Throwable $e) {
    $message = trim(str_replace(["\r\n", "\r", "\n"], ' ', $e->getMessage()));
    if (strlen($message) > 2000) {
        $message = substr($message, 0, 2000) . '...';
    }
    if ($message === '') {
        $message = 'Worker provisioning gagal tanpa pesan.';
    }

    try {
        $log->append($jobId, 'ERROR: ' . $message);
    } catch (\Throwable) {
        // abaikan kegagalan log
    }
    try {
        $store->update($jobId, ['error' => $message, 'message' => $message]);
    } catch (\Throwable) {
        // baris job bisa saja sudah dihapus operator
    }
    try {
        $store->markError($jobId, $message);
    } catch (\Throwable) {
        // status akhir tidak bisa disimpan — worker tetap keluar dengan kode 1
    }

    $exitCode = 1;
} finally {
    // Direktori kerja memuat onboarding.txt (token agent + PEM) — WAJIB hilang
    // baik job sukses maupun gagal. `finally` tetap jalan walau ada exception.
    if ($runner !== null && $jobDir !== '') {
        try {
            $runner->cleanup($jobDir);
        } catch (\Throwable) {
            // Pembersihan best-effort; jangan menutupi hasil job.
        }
    }
}

exit($exitCode);
