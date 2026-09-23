#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Pembaca rencana + penulis status update untuk helper self-update.
 *
 *   php cli/update-report.php get   <plan.json> <key> [default]
 *   php cli/update-report.php set   <plan.json> <stage> <result> <message>
 *   php cli/update-report.php state <plan.json> <key> <value>
 *
 * PENTING: berkas ini SENGAJA berdiri sendiri — tanpa `require` autoload/aplikasi.
 * Helper menyalinnya ke `/tmp` SEBELUM `git pull`, sehingga mesin update tidak
 * ikut berubah di tengah proses (kalau ia memakai kelas aplikasi, `git pull`
 * bisa mengganti kelas itu di bawah kakinya sendiri). Semua nilainya divalidasi
 * (stage/result dari daftar tertutup, SHA heksadesimal) sehingga tidak ada nilai
 * sembarang yang masuk ke berkas status.
 *
 * Status ditulis ke `<run_dir>/run.json` (penulis: helper; pembaca: dashboard).
 */

const EXIT_OK = 0;
const EXIT_ERROR = 1;

/** Tahap yang dikenali UI. */
const STAGES = [
    'starting', 'preflight', 'fetch', 'merge', 'composer', 'build', 'health', 'rolling_back', 'finished',
];

/** Hasil akhir; `running` = masih berjalan (result tetap null). */
const RESULTS = ['running', 'success', 'rolled_back', 'error'];

/** Kunci plan yang boleh diubah helper (`state`). */
const MUTABLE_KEYS = ['target_sha', 'rollback_from', 'message', 'stage'];

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(EXIT_ERROR);
}

/**
 * @return array<string,mixed>
 */
function loadPlan(string $path): array
{
    if ($path === '' || !is_file($path)) {
        fail('Plan update tidak ditemukan: ' . $path);
    }
    $raw = (string) file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('Plan update bukan JSON yang valid: ' . $path);
    }
    foreach (['id', 'run_dir'] as $key) {
        if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
            fail('Plan update tidak memuat "' . $key . '".');
        }
    }

    return $data;
}

/**
 * @return array<string,mixed>
 */
function readRun(string $runFile): array
{
    if (!is_file($runFile)) {
        return [];
    }
    $raw = (string) file_get_contents($runFile);
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/**
 * @param array<string,mixed> $plan
 * @param array<string,mixed> $patch
 */
function writeRun(array $plan, array $patch): void
{
    $dir = rtrim((string) $plan['run_dir'], '/');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail('Tidak bisa membuat direktori status: ' . $dir);
    }
    $runFile = $dir . '/run.json';

    $run = readRun($runFile);
    // Status milik run lain (mis. dirapikan dashboard) → mulai dari kerangka baru.
    if (($run['id'] ?? null) !== $plan['id']) {
        $run = [
            'id' => $plan['id'],
            'mode' => (string) ($plan['mode'] ?? 'update'),
            'stage' => 'starting',
            'message' => '',
            'result' => null,
            'error' => null,
            'started_at' => date('c'),
            'finished_at' => null,
            'old_sha' => ($plan['old_sha'] ?? '') !== '' ? (string) $plan['old_sha'] : null,
            'target_sha' => ($plan['target_sha'] ?? '') !== '' ? (string) $plan['target_sha'] : null,
            'actor' => (string) ($plan['actor'] ?? ''),
            'log' => basename((string) ($plan['log_file'] ?? '')),
            'health_url' => (string) ($plan['health_url'] ?? ''),
            'rollback_from' => null,
        ];
    }

    $run = array_merge($run, $patch);
    if (array_key_exists('error', $patch) && $patch['error'] === null) {
        unset($run['error']);
    }
    if (array_key_exists('finished_at', $patch) && $patch['finished_at'] === null) {
        unset($run['finished_at']);
    }

    $json = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fail('Gagal meng-encode status update.');
    }
    $tmp = $runFile . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        fail('Gagal menulis status update: ' . $tmp);
    }
    if (!@rename($tmp, $runFile)) {
        @unlink($tmp);
        fail('Gagal mengganti status update: ' . $runFile);
    }
}

/**
 * @param array<string,mixed> $plan
 */
function report(array $plan, string $stage, string $result, string $message): void
{
    if (!in_array($stage, STAGES, true)) {
        fail('Tahap tidak dikenali: ' . $stage);
    }
    if (!in_array($result, RESULTS, true)) {
        fail('Hasil tidak dikenali: ' . $result);
    }

    $patch = [
        'stage' => $stage,
        'message' => $message,
    ];
    if ($result === 'running') {
        $patch['result'] = null;
        $patch['finished_at'] = null;
        $patch['error'] = null;
    } else {
        $patch['result'] = $result;
        $patch['finished_at'] = date('c');
        $patch['error'] = $result === 'error' ? $message : null;
        if ($result === 'success') {
            $patch['message'] = $message !== '' ? $message : 'Dashboard berhasil diperbarui.';
        }
    }

    writeRun($plan, $patch);
}

// ======================================================================
// CLI
// ======================================================================

$command = $argv[1] ?? '';
$planPath = $argv[2] ?? '';

if (!in_array($command, ['get', 'set', 'state'], true)) {
    fail('Usage: update-report.php get|set|state <plan.json> ...');
}

$plan = loadPlan($planPath);

if ($command === 'get') {
    $key = (string) ($argv[3] ?? '');
    if ($key === '') {
        fail('Kunci plan wajib diisi.');
    }
    $value = $plan[$key] ?? ($argv[4] ?? '');
    if (is_bool($value)) {
        out($value ? 'true' : 'false');
    } elseif (is_scalar($value)) {
        out((string) $value);
    } elseif ($value === null) {
        out('');
    } else {
        out((string) json_encode($value, JSON_UNESCAPED_SLASHES));
    }
    exit(EXIT_OK);
}

if ($command === 'set') {
    $stage = (string) ($argv[3] ?? '');
    $result = (string) ($argv[4] ?? 'running');
    $message = (string) ($argv[5] ?? '');
    report($plan, $stage, $result, $message);
    exit(EXIT_OK);
}

// state
$key = (string) ($argv[3] ?? '');
$value = (string) ($argv[4] ?? '');
if (!in_array($key, MUTABLE_KEYS, true)) {
    fail('Kunci yang boleh diubah: ' . implode(', ', MUTABLE_KEYS));
}
if ($key === 'stage' && !in_array($value, STAGES, true)) {
    fail('Tahap tidak dikenali: ' . $value);
}
if (in_array($key, ['target_sha', 'rollback_from'], true) && $value !== '' && preg_match('/^[0-9a-f]{7,64}$/i', $value) !== 1) {
    fail('Nilai ' . $key . ' harus SHA commit.');
}

writeRun($plan, [$key => $value === '' ? null : $value]);
exit(EXIT_OK);
