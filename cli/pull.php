#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker: tarik ulang image template (halaman Config `/nginx`,
 * tab Template — SPECS.md §7.13).
 *
 *   php cli/pull.php <slug> [actor]
 *
 * Dipanggil **detached** oleh `TemplatePullService::start()` (pcntl_fork +
 * pcntl_exec) supaya request HTTP tidak terblokir `docker pull` yang bisa
 * berjalan menit-an. Alur status: `starting` → per image `pulling`
 * ("Menarik image i/M: <ref>") → `finished`.
 *
 * Ketentuan:
 *  - stdout/stderr worker sudah diarahkan ke `/dev/null` oleh `DetachedWorker`;
 *    SEMUA keluaran ditulis ke berkas log (`<run_dir>/<id>.log`) — tidak ada
 *    `echo`/`print` ke layar;
 *  - perintah dijalankan sebagai array + `bypass_shell` lewat `ProcessRunner`
 *    (bebas command injection; hanya slug dari katalog & ref image dari
 *    template yang masuk ke argv — tidak ada input user bebas);
 *  - satu image gagal TIDAK menghentikan image lain, tetapi hasil akhirnya
 *    `error`;
 *  - laporan akhir ditulis di semua jalur (`try/catch/finally`) supaya UI tidak
 *    macet di status "berjalan".
 *
 * Exit code: 0 = semua image berhasil, 1 = ada yang gagal / gagal total.
 */

use app\library\Support\ProcessRunner;
use app\library\Template\TemplateCatalog;
use app\library\Template\TemplatePullState;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../support/bootstrap.php';

$slug = trim((string) ($argv[1] ?? ''));
$actor = trim((string) ($argv[2] ?? ''));

if ($slug === '') {
    fwrite(STDERR, "Usage: php cli/pull.php <slug> [actor]\n");
    exit(1);
}

$state = new TemplatePullState((string) config('deploy.template_pull_run_dir', runtime_path('logs/template-pull')));

// Kerangka run biasanya sudah ditulis service sebelum spawn; bila worker
// dijalankan manual (tanpa service) kerangkanya dibuat sendiri di sini.
$current = $state->run();
$id = (string) ($current['id'] ?? '');
if ($id === '') {
    $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}
$logName = (string) ($current['log'] ?? '') !== '' ? (string) $current['log'] : $id . '.log';

$dockerBinary = (string) config('deploy.docker_binary', 'docker');
$timeout = (int) config('deploy.template_pull_timeout', 1800);
$runner = pullRunner();

$state->update([
    'id' => $id,
    'slug' => $slug,
    'stage' => 'starting',
    'message' => 'Menyiapkan tarik image template…',
    'result' => null,
    'error' => null,
    // Kerangka lengkap sejak awal: worker bisa dijalankan manual (tanpa service),
    // dan berkas status harus tetap berbentuk sama seperti jalur UI.
    'images' => [],
    'results' => [],
    'started_at' => (string) ($current['started_at'] ?? '') !== '' ? (string) $current['started_at'] : date('c'),
    'actor' => $actor !== '' ? $actor : (string) ($current['actor'] ?? ''),
    'log' => $logName,
    'pid' => getmypid(),
]);
$state->appendLog($logName, '[' . date('c') . "] mulai tarik image: slug={$slug} actor=" . ($actor !== '' ? $actor : '-') . "\n");

/** @var array<int,array{image:string,ok:bool,seconds:float,message:string}> $results */
$results = [];
/** @var array<int,string> $failed */
$failed = [];
$error = '';
$total = 0;

try {
    $template = (new TemplateCatalog())->find($slug);
    if ($template === null) {
        throw new RuntimeException('Template "' . $slug . '" tidak ditemukan.');
    }
    if (!$template['valid']) {
        throw new RuntimeException('Template "' . $slug . '" tidak valid: ' . (string) $template['error']);
    }

    $images = TemplateCatalog::images($template);
    if ($images === []) {
        throw new RuntimeException('Template "' . $slug . '" tidak punya image prebuilt untuk ditarik.');
    }

    $total = count($images);
    $state->update([
        'title' => (string) $template['title'],
        'images' => $images,
        'stage' => 'pulling',
    ]);

    foreach ($images as $index => $ref) {
        $position = $index + 1;
        $state->update([
            'stage' => 'pulling',
            'message' => 'Menarik image ' . $position . '/' . $total . ': ' . $ref,
        ]);
        $state->appendLog($logName, '[' . date('c') . '] docker pull ' . $ref . "\n");

        $startedAt = microtime(true);
        $result = $runner->run([$dockerBinary, 'pull', $ref], null, $timeout);
        $seconds = round(microtime(true) - $startedAt, 1);

        $output = trim($result['stdout'] . $result['stderr']);
        if ($output !== '') {
            $state->appendLog($logName, $output . "\n");
        }

        $ok = $result['code'] === 0;
        $results[] = [
            'image' => $ref,
            'ok' => $ok,
            'seconds' => $seconds,
            'message' => $ok
                ? 'OK'
                : 'Gagal (exit ' . $result['code'] . ')' . ($result['timedOut'] ? ' — timeout ' . $timeout . 's' : ''),
        ];

        if (!$ok) {
            $failed[] = $ref;
        }

        $state->update(['results' => $results]);
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($failed !== []) {
        $error = 'Gagal menarik image: ' . implode(', ', $failed)
            . '. Periksa koneksi registry/tag image di log, lalu coba lagi.';
    }
    $state->update(['results' => $results]);
    $state->finalize($error === '' ? 'success' : 'error', $error);
    $state->appendLog(
        $logName,
        '[' . date('c') . '] selesai: ' . ($error === '' ? 'sukses (' . count($results) . '/' . $total . ' image)' : 'GAGAL — ' . $error) . "\n"
    );
}

exit($error === '' ? 0 : 1);

/**
 * Runner proses `docker pull` — seam uji `TEMPLATE_PULL_RUNNER_CLASS` (pola sama
 * dengan `DEPLOYER_CLASS` di `DeployerFactory`/`tests/CliDeployRollbackTest.php`)
 * supaya test bisa menjalankan worker tanpa daemon/registry Docker.
 */
function pullRunner(): ProcessRunner
{
    $override = (string) (getenv('TEMPLATE_PULL_RUNNER_CLASS') ?: '');
    if ($override !== '' && class_exists($override)) {
        $runner = new $override();
        if ($runner instanceof ProcessRunner) {
            return $runner;
        }
    }

    return new ProcessRunner();
}
