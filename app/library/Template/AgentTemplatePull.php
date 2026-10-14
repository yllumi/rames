<?php
declare(strict_types=1);

namespace app\library\Template;

use app\library\Agent\AgentError;
use app\library\Deploy\AgentDeployer;
use RuntimeException;

/**
 * Tarik image template **di server agent** (multi-server F4 lanjutan).
 *
 * Masalah: halaman Config (`/nginx`, tab Template) menarik ulang image prebuilt
 * sebuah template (`docker pull` per image, §7.13). Untuk app/server yang
 * dilayani server `kind=agent`, pull itu **tidak boleh** dijalankan di daemon
 * control plane — image harus masuk ke daemon server yang memakai image itu.
 *
 * Alur (pola job async yang sudah mapan):
 *  1. `start()` → `AgentDeployer::startServerJob('template_pull', slug)` ⇒
 *     agen mencatat job + spawn worker detached (`cli/deploy.php template_pull`
 *     yang menjalankan mekanisme pull yang sama dengan jalur lokal di sana);
 *  2. `panel()` (dipanggil endpoint status yang dipoll UI) → `serverJobProgress()`
 *     mengambil status + potongan log **baru** job itu, mencerminkannya ke berkas
 *     log run server ini, dan menutup run saat job `done`/`error`.
 *
 * **State per server**: seluruh state/riwayat run kelas ini disimpan di
 * {@see TemplatePullState} dengan kunci = id server (`<run_dir>/servers/<id>/`),
 * jadi dua server agen tidak pernah berbagi run.json maupun log — dan jalur
 * server lokal (`TemplatePullService` + `run.json` lama) tetap apa adanya.
 *
 * Batas kelas: tidak menyentuh Docker daemon control plane, tidak menjalankan
 * `docker pull` di sini, dan tidak menutup/membuka sesi. Semuanya lewat
 * {@see AgentDeployer} (HTTP, ≤30 s per panggilan) sehingga request UI tetap
 * pendek. Stateless: properti hanya diisi konstruktor.
 */
final class AgentTemplatePull
{
    /** Op job agent yang dipakai (satu sumber: `DeployWorkerLauncher::MODES`). */
    public const OP = 'template_pull';

    private readonly string $serverId;

    private readonly string $serverName;

    public function __construct(
        private readonly AgentDeployer $agent,
        private readonly TemplatePullState $state,
        string $serverId,
        string $serverName = '',
    ) {
        $this->serverId = trim($serverId);
        $this->serverName = trim($serverName);
    }

    public function state(): TemplatePullState
    {
        return $this->state;
    }

    public function serverId(): string
    {
        return $this->serverId;
    }

    /**
     * Mulai menarik image satu template **di server agent**.
     *
     * Fail-fast (tanpa efek samping di server) sebelum job dikirim:
     *  - slug kosong;
     *  - masih ada run yang belum selesai untuk server ini;
     *  - gerbang versi/kapabilitas agen menolak op `template_pull`.
     *
     * Slug yang tidak ada di checkout template **server itu** ditolak agen
     * (preflight di agen) dengan pesan jelas — control plane tidak menebak
     * katalog server lain.
     *
     * @return array{id:string,slug:string,server_id:string,job_id:string}
     * @throws RuntimeException slug kosong / run masih berjalan / gerbang versi
     * @throws AgentError       kegagalan transport ke agent
     */
    public function start(string $slug, string $actor): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            throw new RuntimeException('Slug template tidak boleh kosong.');
        }

        $current = $this->state->run();
        if ($current['id'] !== null && $current['result'] === null) {
            // Run sebelumnya belum ditutup. Cek dulu ke agen apakah job-nya masih
            // benar-benar berjalan — jangan memutuskan dari state lokal saja
            // (control plane bisa saja tidak sempat polling, mis. UI ditutup).
            // Job yang hilang/`error`/`done` menutup run di sini sehingga pull
            // berikutnya tetap bisa dimulai.
            if ($current['job'] !== '') {
                $current = $this->poll($current);
            }

            if ($current['result'] === null) {
                throw new RuntimeException(
                    'Masih ada proses tarik image yang berjalan di ' . $this->label() . ' — tunggu sampai selesai.'
                );
            }
        }

        // Job dikirim lebih dulu; kegagalan gerbang versi/transport tidak boleh
        // meninggalkan run "berjalan" yang tidak pernah dikerjakan.
        $job = $this->agent->startServerJob(self::OP, $slug, [], null);

        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $logName = $id . '.log';

        $this->state->startRun([
            'id' => $id,
            'slug' => $slug,
            // Judul & daftar image milik katalog server agen (bisa berbeda dari
            // control plane) ⇒ tidak dikarang di sini; diisi bila agen melaporkan.
            'title' => '',
            'stage' => 'starting',
            'message' => 'Menarik image template "' . $slug . '" di ' . $this->label() . ' …',
            'result' => null,
            'error' => null,
            'started_at' => date('c'),
            'finished_at' => null,
            'actor' => trim($actor),
            'images' => [],
            'results' => [],
            'log' => $logName,
            'job' => (string) $job['job_id'],
            'log_offset' => (int) $job['log_offset'],
            // Worker-nya berjalan di server lain ⇒ pid di sini tidak bermakna
            // (0 = jangan pernah diperiksa `posix_kill`; lihat TemplatePullState).
            'pid' => 0,
        ]);

        return ['id' => $id, 'slug' => $slug, 'server_id' => $this->serverId, 'job_id' => (string) $job['job_id']];
    }

    /**
     * Status satu run (bentuk sama dengan `TemplatePullService::panel()`) —
     * dipakai endpoint status yang dipoll UI.
     *
     * @param bool $poll false = murni baca state (tanpa panggilan HTTP), pola
     *                   `UpdateService::panel(false)`; dipakai halaman yang tidak
     *                   ingin menunggu jaringan.
     * @return array{enabled:bool,running:bool,run:array<string,mixed>,log_tail:string,logs:array<int,array{name:string,size:int,modified_at:string}>}
     */
    public function panel(bool $poll = true): array
    {
        $run = $this->state->run();

        if ($poll && $run['result'] === null && $run['job'] !== '') {
            $run = $this->poll($run);
        }

        $logName = (string) ($run['log'] ?? '');

        return [
            // `enabled` selalu true di sini: ketersediaan template/worker diputuskan
            // preflight **di server agent** (dan disampaikan sebagai pesan galat job),
            // bukan dari berkas di control plane.
            'enabled' => true,
            'running' => $run['result'] === null && $run['id'] !== null,
            'run' => $run,
            'log_tail' => $logName !== '' ? $this->state->logTail($logName, 12000) : '',
            'logs' => $this->state->logs(10),
        ];
    }

    /**
     * Ambil progres job dari agen lalu perbarui state run (log dicerminkan ke
     * berkas log run server ini, offset disimpan supaya polling berikutnya tidak
     * mengulang baris).
     *
     * @param array<string,mixed> $run state run saat ini (bentuk `TemplatePullState::run()`)
     * @return array<string,mixed> state run setelah pembaruan
     */
    private function poll(array $run): array
    {
        $jobId = (string) $run['job'];
        $offset = (int) $run['log_offset'];

        try {
            $progress = $this->agent->serverJobProgress($jobId, $offset);
        } catch (AgentError $e) {
            if ($e->reason !== AgentError::NOT_FOUND) {
                // Transport bermasalah: jangan tutup run (agen bisa kembali);
                // UI menampilkan pesan terakhir + galat ini.
                return $this->state->update([
                    'stage' => 'polling',
                    'message' => 'Menunggu ' . $this->label() . ': ' . $e->getMessage(),
                ]);
            }

            return $this->state->finalize(
                'error',
                'Job tarik image tidak ditemukan di ' . $this->label()
                . ' (rekaman job hilang — mungkin agen baru saja diperbarui/restart). Periksa log di server itu.'
            );
        }

        $logName = (string) ($run['log'] ?? '');
        if ($logName !== '' && $progress['log'] !== '') {
            $this->state->appendLog($logName, $progress['log']);
        }

        $job = $progress['job'];
        $status = strtolower(trim((string) ($job['status'] ?? 'pending')));
        $stage = trim((string) ($job['stage'] ?? '')) !== '' ? (string) $job['stage'] : 'pulling';
        $message = trim((string) ($job['message'] ?? '')) !== ''
            ? (string) $job['message']
            : (string) ($run['message'] ?? '');

        $this->state->update([
            'log_offset' => (int) $progress['offset'],
            'stage' => $status === 'done' ? 'finished' : $stage,
            'message' => $message,
        ]);

        if ($status === 'error') {
            return $this->state->finalize('error', $this->agent->serverJobFailure($job, self::OP));
        }

        if ($status === 'done') {
            return $this->state->finalize('success');
        }

        if ($status !== 'pending' && $status !== 'running') {
            return $this->state->finalize(
                'error',
                $this->label() . ': status job tarik image tidak dikenal (' . $status . ').'
            );
        }

        return $this->state->run();
    }

    /** Label server untuk pesan (nama + id, tanpa `base_url`/token). */
    private function label(): string
    {
        if ($this->serverName !== '' && $this->serverId !== '') {
            return 'server "' . $this->serverName . '" (' . $this->serverId . ')';
        }

        return $this->serverId !== '' ? 'server (' . $this->serverId . ')' : 'server tanpa id';
    }
}
