<?php
declare(strict_types=1);

namespace app\controller\backup;

use app\library\Agent\AgentError;
use app\library\Backup\BackupAccess;
use app\library\Backup\BackupDispatcher;
use app\library\Backup\BackupJobRunner;
use app\library\Backup\DumpStaging;
use app\library\Deploy\AgentDeployer;
use app\library\Storage\AppStore;
use app\library\Storage\DbBackup;
use support\Request;
use Webman\Http\Response;

/**
 * Aksi database dashboard (SQLite) & dump `.sql` per volume:
 * `POST /backups/db-dump`, `GET /backups/dump/sql`, serta
 * `POST /backups/db/{run,prune,restore}` dan `GET /backups/db/download`.
 *
 * Multi-server F4: dump volume server agent dijalankan/diunduh **di server itu**
 * (`AgentDeployer`), volume lokal memakai worker/staging host ini.
 */
class BackupDbController extends BackupControllerBase
{
    /** Teks konfirmasi restore DB SQLite (kartu "Database dashboard"). */
    private const DB_RESTORE_CONFIRM = 'RESTORE';

    /**
     * Dump logis DB satu volume (worker detached, ability `backup`).
     *
     * Multi-server F4: volume server agent di-dump **di server itu** (job
     * `db_dump`, kredensial DB dibaca di sana); volume lokal memakai jalur worker
     * host ini. Tidak ada UI khusus untuk aksi ini (dipakai operator/admin lewat
     * API) — berkas `.sql` tetap di staging server yang menjalankannya.
     */
    public function dbDump(Request $request)
    {
        if (config('deploy.volume_backup_enabled', true) === false) {
            return json(['code' => 422, 'msg' => 'Fitur backup volume dimatikan.'])->withStatus(422);
        }

        $volume = trim((string) $request->post('volume', ''));
        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }

        try {
            $targets = $this->volumeTargets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()]);
        }

        try {
            $target = $this->findTarget($targets, $volume, trim((string) $request->post('server_id', '')));
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }
        if ($target === null) {
            return $this->notFound($request);
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('backup', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), $this->currentUser());

        $serverId = self::serverIdOf($target);
        try {
            $this->assertAgentSupports($serverId, 'db_dump');
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }

        $appArg = ($target['app_id'] !== null && $target['app_id'] !== '') ? (string) $target['app_id'] : '-';
        if (!$this->spawnWorker(['db-dump', $appArg, $volume, $serverId])) {
            return json(['code' => 500, 'msg' => 'Gagal menjalankan worker dump.'])->withStatus(500);
        }

        return json(['code' => 0, 'msg' => 'Dump database dijalankan.']);
    }

    /**
     * Unduh berkas `.sql` hasil `db_dump` (staging) sebuah volume — ability
     * `backup` (operator+).
     *
     * Multi-server F4 lanjutan: volume server **agent** diunduh **dari server
     * itu** lewat `AgentDeployer::downloadDump()` (potongan bertahap, setiap
     * panggilan HTTP ≤30 s, total dibatasi plafon); volume lokal dibaca dari
     * staging host ini lewat `BackupJobRunner::dumpChunk()` — kontrol plane tidak
     * pernah membaca staging server agent secara langsung.
     *
     * Respons = `Webman\Http\Response` (unduhan), bukan helper `json()`.
     */
    public function downloadDumpSql(Request $request)
    {
        if (config('deploy.volume_backup_enabled', true) === false) {
            return json(['code' => 422, 'msg' => 'Fitur backup volume dimatikan.'])->withStatus(422);
        }

        $volume = trim((string) $request->get('volume', ''));
        if (!$this->validVolume($volume)) {
            return json(['code' => 422, 'msg' => 'Nama volume tidak valid.'])->withStatus(422);
        }

        try {
            $targets = $this->volumeTargets();
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage()])->withStatus(500);
        }

        try {
            $target = $this->findTarget($targets, $volume, trim((string) $request->get('server_id', '')));
        } catch (\RuntimeException $e) {
            return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
        }
        if ($target === null) {
            return $this->notFound($request);
        }

        $apps = (new AppStore())->all();
        BackupAccess::require('backup', $target, $this->findApp($apps, (string) ($target['app_id'] ?? '')), $this->currentUser());

        $maxBytes = max(1, (int) config('deploy.volume_backup_dump_download_max_bytes', 134217728));
        $serverId = self::serverIdOf($target);

        if (!BackupDispatcher::isLocalServer($serverId)) {
            try {
                $this->assertAgentSupports($serverId, 'db_dump');
                $dump = $this->agentDeployerForTarget($target)->downloadDump($volume);
            } catch (AgentError $e) {
                // `AgentError` juga `RuntimeException`, jadi ia wajib ditangkap
                // **lebih dulu**: pesannya memuat `base_url`/host agent pada galat
                // transport, dan identitas itu harus dibuang lewat satu pintu.
                $code = AgentDeployer::failureCode($e);

                return json([
                    'code' => $code,
                    'msg' => AgentError::sanitizeForServer($e->getMessage(), $this->agentServerOf($target)),
                ])->withStatus($code);
            } catch (\RuntimeException $e) {
                return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
            } catch (\Throwable $e) {
                $code = AgentDeployer::failureCode($e);

                // Identitas server (token/base_url/PEM) dibuang lewat satu pintu.
                return json([
                    'code' => $code,
                    'msg' => AgentError::sanitizeForServer($e->getMessage(), $this->agentServerOf($target)),
                ])->withStatus($code);
            }
        } else {
            try {
                $dump = $this->readLocalDump($volume, $maxBytes);
            } catch (\RuntimeException $e) {
                return json(['code' => 422, 'msg' => $e->getMessage()])->withStatus(422);
            } catch (\Throwable $e) {
                return json(['code' => 500, 'msg' => 'Gagal menyiapkan dump: ' . $e->getMessage()])->withStatus(500);
            }
        }

        $content = (string) ($dump['content'] ?? '');
        $name = (string) ($dump['name'] ?? '');
        if (strlen($content) > $maxBytes) {
            return json(['code' => 422, 'msg' => 'Berkas dump melebihi batas unduh.'])->withStatus(422);
        }

        return new Response(200, [
            'Content-Type' => 'application/sql; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . self::safeDownloadName($volume, $name !== '' ? $name : 'dump') . '"',
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
        ], $content);
    }

    /**
     * Baca berkas dump staging **host ini** (jalur lokal) secara bertahap —
     * memakai `BackupJobRunner::dumpChunk()` (satu sumber dengan endpoint agent),
     * dibatasi `$maxBytes` supaya satu permintaan tidak menahan data tanpa batas.
     *
     * `protected` (bukan `private`) semata agar unit test dapat menyuntikkan isi
     * dump tanpa menyentuh staging `runtime/` nyata — perilaku produksi tidak
     * berubah (pola yang sama dengan `BackupControllerBase::volumeTargets()`).
     *
     * @return array{name:string,bytes:int,content:string}
     * @throws \RuntimeException belum ada dump / melebihi batas / berkas tak terbaca
     */
    protected function readLocalDump(string $volume, int $maxBytes): array
    {
        $runner = new BackupJobRunner();
        $content = '';
        $name = '';
        $offset = 0;

        while (true) {
            $chunk = $runner->dumpChunk($volume, $offset, DumpStaging::MAX_READ_BYTES);
            if ($name === '') {
                $name = (string) $chunk['name'];
            }
            $content .= (string) $chunk['data'];
            $offset += strlen((string) $chunk['data']);

            if (strlen($content) > $maxBytes) {
                throw new \RuntimeException('Berkas dump melebihi batas unduh — unduh langsung di server tersebut.');
            }
            if ($chunk['eof'] === true) {
                break;
            }
            if ((string) $chunk['data'] === '') {
                throw new \RuntimeException('Berkas dump tidak dapat dibaca sepenuhnya.');
            }
        }

        if ($content === '') {
            throw new \RuntimeException('Berkas dump kosong — tidak ada yang bisa diunduh.');
        }

        return ['name' => $name, 'bytes' => strlen($content), 'content' => $content];
    }

    /**
     * Buat snapshot DB dashboard sekarang (manual).
     */
    public function dbRun(Request $request)
    {
        if (!$this->isAdmin()) {
            return $this->notFound($request);
        }

        try {
            $result = (new DbBackup())->run('manual');
        } catch (\Throwable $e) {
            $this->flash('error', 'Gagal membuat snapshot database: ' . $e->getMessage());

            return redirect('/backups#db-backup');
        }

        if (!empty($result['skipped'])) {
            // Busy bukan kegagalan — beri tahu, jangan tampilkan sebagai error.
            $this->flash('info', 'Snapshot dilewati: backup lain sedang berjalan (busy). Coba lagi sebentar lagi.');
        } else {
            $this->flash('success', 'Snapshot database dibuat: ' . (string) ($result['file'] ?? ''));
        }

        return redirect('/backups#db-backup');
    }

    /**
     * Jalankan retensi (prune) snapshot DB dashboard sekarang.
     */
    public function dbPrune(Request $request)
    {
        if (!$this->isAdmin()) {
            return $this->notFound($request);
        }

        try {
            $result = (new DbBackup())->prune();
        } catch (\Throwable $e) {
            $this->flash('error', 'Gagal menjalankan prune: ' . $e->getMessage());

            return redirect('/backups#db-backup');
        }

        if (!empty($result['skipped'])) {
            $this->flash('info', 'Prune dilewati: backup lain sedang berjalan (busy).');
        } else {
            $this->flash('success', sprintf(
                'Prune selesai — %d snapshot dihapus, %d dipertahankan.',
                (int) ($result['removed'] ?? 0),
                (int) ($result['kept'] ?? 0)
            ));
        }

        return redirect('/backups#db-backup');
    }

    /**
     * Pulihkan DB dashboard dari snapshot terpilih.
     *
     * **Wajib** konfirmasi teks `RESTORE` + nama snapshot valid. Setelah sukses,
     * worker **tidak** di-reload di sini (proses ini sendiri memakai DB) — pesan
     * flash meminta operator menjalankan `php start.php reload` manual.
     */
    public function dbRestore(Request $request)
    {
        if (!$this->isAdmin()) {
            return $this->notFound($request);
        }

        $confirm = trim((string) $request->post('confirm', ''));
        if ($confirm !== self::DB_RESTORE_CONFIRM) {
            $this->flash('error', 'Konfirmasi restore tidak cocok — ketik RESTORE untuk melanjutkan.');

            return redirect('/backups#db-backup');
        }

        $file = trim((string) $request->post('file', ''));

        try {
            $result = (new DbBackup())->restore($file);
        } catch (\Throwable $e) {
            $this->flash('error', 'Restore gagal: ' . $e->getMessage());

            return redirect('/backups#db-backup');
        }

        $this->flash('success', 'Database dipulihkan dari ' . (string) ($result['file'] ?? '') . '. '
            . 'Jalankan `php start.php reload` agar worker memakai DB baru.');

        return redirect('/backups#db-backup');
    }

    /**
     * Unduh satu snapshot DB dashboard (`Content-Disposition: attachment`).
     *
     * Nama berkas divalidasi `DbBackup::download()` (basename `*.sqlite`, di
     * dalam direktori backup) sehingga path traversal ditolak **sebelum**
     * berkas disentuh. Streaming via `Response::download()` (sendfile) — isi
     * tidak dimuat ke memori. Nama tidak sah ⇒ 404 (bukan 403/422) agar
     * keberadaan berkas tidak bocor.
     */
    public function dbDownload(Request $request)
    {
        if (!$this->isAdmin()) {
            return $this->notFound($request);
        }

        $file = trim((string) $request->get('file', ''));

        try {
            $path = (new DbBackup())->download($file);
        } catch (\Throwable $e) {
            return $this->notFound($request);
        }

        return (new Response(200, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]))->download($path, basename($path));
    }

    /**
     * Set pesan flash (seam uji; default memakai session).
     */
    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
    }
}
