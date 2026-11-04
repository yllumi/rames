<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Billing\InsufficientCredits;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\EnvManager;
use app\library\Deploy\ImportedApp;
use app\library\Deploy\ServerResolver;
use app\library\Storage\AppStore;
use RuntimeException;
use support\Request;

/**
 * Environment variable per app (§7): simpan, impor teks, unggah berkas.
 *
 * Route: `POST /apps/{id}/env`, `/env/import`, `/env/upload`.
 * Parser/penggabungan env ada di `EnvManager` (app/library).
 */
class EnvironmentController extends AppControllerBase
{
    /**
     * Simpan environment variable app (POST /apps/{id}/env).
     *
     * Validasi ketat format kunci, lalu — bergantung **server pemilik app**:
     *  - server **lokal** (perilaku lama, tidak berubah): tulis managed env file
     *    + override env (EnvManager), persist ke apps.json, dan auto-recreate
     *    container via `docker compose up -d` (tanpa build) secara sinkron;
     *  - server **agent**: host ini **tidak** menulis artefak app apa pun
     *    (tidak ada `apps/<name>` / `database/env/*` di control plane). `env` +
     *    `compose_files` disimpan sebagai metadata app, lalu worker
     *    `apply_env` mengirim job ke agent — agent menulis managed env +
     *    override env dari metadata itu lalu `up -d` tanpa build.
     *
     * @param DeployWorkerLauncher|null $launcher seam uji: peluncur worker palsu
     *                                            (null = bawaan); jalur produksi
     *                                            tidak pernah mengisinya
     */
    public function saveEnv(Request $request, string $id, ?DeployWorkerLauncher $launcher = null)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Environment variable');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Server pemilik app (fail-fast bila `server_id` menunjuk server hilang):
        // jangan pernah menulis artefak app agent di host control plane.
        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            flash_set('error', 'Environment variable tidak bisa dijalankan: ' . $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (!$local) {
            return $this->saveEnvOnAgent($request, $app, $store, $launcher);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Environment variable');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        // Gerbang kredit — sebelum menulis env & recreate container (applyEnv).
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            $env = $this->normalizedEnvFromRequest($request, $app);

            // 1) Tulis file dulu (managed + override). Gagal => tidak ada state
            //    yang berubah (apps.json belum disentuh, tetap konsisten).
            $envManager = new EnvManager();
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            $envManager->sync(['name' => $app['name'], 'env' => $env], $dir, $composeFiles);

            // 2) Persist ke apps.json (env + compose_files).
            $store->update($id, function (array &$s) use ($env): void {
                $s['env'] = $env;
                $s['compose_files'] = self::composeFilesWithEnv(
                    array_map('strval', (array) ($s['compose_files'] ?? ['docker-compose.yml'])),
                    $env
                );
            });

            // 3) Auto-recreate container (up -d tanpa build). Sinkron & cepat;
            //    kegagalan penerapan tidak menggagalkan penyimpanan.
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::createForApp($app)->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', 'Environment variable disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'Environment variable tersimpan, tetapi gagal menerapkan ke container: ' . $this->appErrorText($app, $e) . ' — coba Rebuild untuk menerapkan.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Simpan environment variable app yang berjalan di server **agent**
     * (POST /apps/{id}/env — cabang non-lokal `saveEnv()`).
     *
     * Host control plane **tidak** memiliki artefak app agent, jadi tidak ada
     * direktori/berkas yang dibuat di sini: `env` + `compose_files` (override env
     * selalu paling akhir) disimpan sebagai **metadata** app, lalu worker
     * `apply_env` mengirim job ke agent. `AgentAppProjection::METADATA` sudah
     * memuat `env`/`compose_files` — jadi payload job membawa nilai barunya, dan
     * `LocalDeployer::applyEnv()` di agent yang menulis managed env + override
     * env lalu `up -d` (tanpa build).
     *
     * Urutan: gerbang kredit **sebelum** normalisasi/persist; normalisasi
     * `normalizeEnv()` (validasi kunci) **sebelum** apps.json disentuh sehingga
     * input tidak sah tidak meninggalkan state setengah jadi.
     *
     * @param array<string,mixed>       $app      dokumen app (server agent)
     * @param DeployWorkerLauncher|null $launcher seam uji (null = bawaan)
     */
    private function saveEnvOnAgent(Request $request, array $app, AppStore $store, ?DeployWorkerLauncher $launcher = null): \Webman\Http\Response
    {
        $id = (string) $app['id'];

        // Gerbang kredit — sebelum metadata ditulis & worker `apply_env` di-spawn
        // (op itu menciptakan ulang container di agent).
        try {
            $this->billingAssertCanStart($app, current_user());
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        }

        try {
            $env = $this->normalizedEnvFromRequest($request, $app);

            // Persist metadata SAJA (tanpa artefak lokal): worker agent yang
            // menulis managed env + override env dari dokumen app ini.
            $store->update($id, function (array &$s) use ($env): void {
                $s['env'] = $env;
                $s['compose_files'] = self::composeFilesWithEnv(
                    array_map('strval', (array) ($s['compose_files'] ?? ['docker-compose.yml'])),
                    $env
                );
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan environment variable di server agent ...';
                $s['error'] = null;
            });

            if (!$this->spawnWorker($id, 'apply_env', null, $this->currentActorId(), $launcher)) {
                $store->update($id, static function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker penerapan environment variable di server agent.';
                    $s['error'] = 'Gagal spawn worker apply_env.';
                });
                flash_set('error', 'Environment variable tersimpan, tetapi gagal menjalankan worker penerapan di server agent. Coba Rebuild untuk menerapkan.');

                return redirect('/apps/' . $id);
            }

            flash_set('success', 'Environment variable disimpan — container diciptakan ulang di server agent (tanpa build).');
        } catch (\Throwable $e) {
            flash_set('error', $this->appErrorText($app, $e));
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Import variabel yang belum ada dari .env.example di direktori app
     * (POST /apps/{id}/env/import). Hanya mengisi nilai default; TIDAK
     * auto-recreate — user meninjau nilainya dulu, lalu klik "Simpan & Terapkan".
     *
     * `.env.example` hanya bisa dibaca bila checkout app ada di host ini, jadi
     * app di server **agent** ditolak dengan pesan akurat (redirect + flash,
     * tanpa efek samping) — bukan pesan menyesatkan "Direktori app tidak ada".
     */
    public function importEnv(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Environment variable');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            flash_set('error', 'Environment variable tidak bisa dijalankan: ' . $e->getMessage());

            return redirect('/apps/' . $id);
        }

        if (!$local) {
            flash_set(
                'error',
                'Import .env.example belum tersedia untuk app di server agent — unggah file .env atau isi variabel manual, lalu klik "Simpan & Terapkan".'
            );

            return redirect('/apps/' . $id);
        }

        $dir = $this->appArtifactDirOrFail($app, 'Environment variable');
        if ($dir === null) {
            return redirect('/apps/' . $id);
        }

        try {
            $example = EnvManager::parseEnvExample($dir);
            if ($example === []) {
                flash_set('error', 'Tidak ada .env.example di direktori app (atau tidak dapat di-parse).');
                return redirect('/apps/' . $id);
            }

            $env = is_array($app['env'] ?? null) ? $app['env'] : [];
            $added = 0;
            foreach ($example as $key => $value) {
                if (!array_key_exists($key, $env)) {
                    $env[$key] = $value;
                    $added++;
                }
            }

            if ($added === 0) {
                flash_set('info', 'Semua variabel di .env.example sudah ada di app.');
                return redirect('/apps/' . $id);
            }

            // Simpan + tulis file (tanpa recreate — biarkan user meninjau dulu).
            $this->persistEnvForReview($store, $id, $app, $dir, $env);

            flash_set('success', "Diimport {$added} variabel dari .env.example. Tinjau nilainya, lalu klik \"Simpan & Terapkan\" untuk menerapkan ke container.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Upload file .env (POST /apps/{id}/env/upload).
     *
     * Jalur mediator murni: parser strict ada di {@see EnvManager::parseEnvContent()}
     * (batas ukuran, NUL/biner, kunci & format baris, quote/escape) dan
     * penggabungan di {@see EnvManager::mergeUploaded()}. Mode `merge` (default)
     * menimpa kunci yang sama & menambah kunci baru; `mode=replace` mengganti
     * seluruh env app.
     *
     * Seperti `importEnv()`, hasil upload **tidak** auto-apply ke container —
     * file (managed env + override) & apps.json diperbarui agar bisa ditinjau,
     * lalu user menekan "Simpan & Terapkan". Karena tidak menyalakan/menciptakan
     * container, tidak ada gerbang kredit di sini.
     *
     * Multi-server: untuk app di server **agent** tidak ada artefak lokal yang
     * ditulis (parameter `$dir` = null) — hanya metadata `env` + `compose_files`
     * yang diperbarui; file managed env + override ditulis worker agent saat
     * "Simpan & Terapkan".
     */
    public function uploadEnv(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        try {
            ImportedApp::assertManageable($app, 'Environment variable');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());

            return redirect('/apps/' . $id);
        }

        try {
            $local = ServerResolver::isLocal($this->serverForApp($app));
        } catch (\Throwable $e) {
            flash_set('error', 'Environment variable tidak bisa dijalankan: ' . $e->getMessage());

            return redirect('/apps/' . $id);
        }

        // Server agent: review-only, tanpa menyentuh filesystem host ini.
        $dir = null;
        if ($local) {
            $dir = $this->appArtifactDirOrFail($app, 'Environment variable');
            if ($dir === null) {
                return redirect('/apps/' . $id);
            }
        }

        try {
            $limit = (int) config('deploy.env_upload_max_bytes', EnvManager::MAX_UPLOAD_BYTES);
            if ($limit <= 0) {
                $limit = EnvManager::MAX_UPLOAD_BYTES;
            }

            $envManager = new EnvManager();
            $uploaded = $envManager->parseEnvContent($this->envUploadContent($request, $limit), $limit);
            if ($uploaded === []) {
                throw new RuntimeException('File .env tidak berisi variabel yang valid.');
            }

            $existing = is_array($app['env'] ?? null) ? $app['env'] : [];
            $existingValid = EnvManager::mergeUploaded($existing, [], false);
            $replace = $request->post('mode') === 'replace';
            $merged = EnvManager::mergeUploaded($existing, $uploaded, $replace);

            // Persist tanpa recreate — user meninjau dulu (alur "Simpan & Terapkan").
            $this->persistEnvForReview($store, $id, $app, $dir, $merged);

            $total = count($uploaded);
            if ($replace) {
                $removed = count(array_diff_key($existingValid, $uploaded));
                flash_set('success', "Di-upload {$total} variabel dari .env (mode ganti semua: {$removed} variabel lama dihapus). Tinjau nilainya, lalu klik \"Simpan & Terapkan\" untuk menerapkan ke container.");
            } else {
                $added = 0;
                foreach (array_keys($uploaded) as $key) {
                    if (!array_key_exists((string) $key, $existingValid)) {
                        $added++;
                    }
                }
                $updated = $total - $added;
                flash_set('success', "Di-upload {$total} variabel dari .env ({$added} baru, {$updated} diperbarui). Tinjau nilainya, lalu klik \"Simpan & Terapkan\" untuk menerapkan ke container.");
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Ambil isi file `.env` dari request (upload tunggal atau array, ambil yang
     * pertama) + validasi error upload & batas ukuran.
     *
     * Pesan galat tidak pernah memuat isi/nilai file (bisa berisi secret).
     *
     * @throws RuntimeException
     */
    private function envUploadContent(Request $request, int $limit): string
    {
        $file = $request->file('env_file');
        if (is_array($file)) {
            // Bentuk `env_file[]` (defensif): ambil berkas pertama.
            $file = array_values($file)[0] ?? null;
        }
        if (!$file instanceof \Webman\Http\UploadFile) {
            throw new RuntimeException('Pilih file .env yang akan di-upload.');
        }

        $error = (int) ($file->getUploadErrorCode() ?? UPLOAD_ERR_NO_FILE);
        if (trim((string) $file->getUploadName()) === '' || $error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Pilih file .env yang akan di-upload.');
        }
        if ($error !== UPLOAD_ERR_OK || !$file->isValid()) {
            throw new RuntimeException('Upload file .env gagal (kode error ' . $error . ').');
        }

        try {
            $size = (int) $file->getSize();
        } catch (\Throwable $e) {
            $size = 0; // biarkan panjang isi yang menentukan (cek di bawah/parser)
        }
        if ($size > $limit) {
            throw new RuntimeException(sprintf('File .env terlalu besar (maks %d KiB).', max(1, (int) ceil($limit / 1024))));
        }

        $content = @file_get_contents((string) $file->getPathname());
        if ($content === false) {
            throw new RuntimeException('Gagal membaca file .env yang di-upload.');
        }
        if (strlen($content) > $limit) {
            throw new RuntimeException(sprintf('File .env terlalu besar (maks %d KiB).', max(1, (int) ceil($limit / 1024))));
        }

        return $content;
    }

    /**
     * Persist env app untuk ditinjau (TANPA recreate container).
     *
     * `$dir !== null` (server lokal): tulis managed env file + override YAML
     * lewat {@see EnvManager::sync()}, lalu simpan `env` & `compose_files` ke
     * apps.json. `$dir === null` (server agent): **tidak menyentuh filesystem**
     * sama sekali — hanya metadata yang diperbarui; berkasnya ditulis worker
     * agent saat "Simpan & Terapkan". Override env selalu ditaruh paling akhir
     * (menang atas compose repo); bila env kosong, entri override dihapus.
     *
     * Dipakai bersama oleh `importEnv()` (import .env.example) dan
     * `uploadEnv()` (upload .env) agar tidak ada dua versi logika persist.
     *
     * @param array<string,mixed>  $app
     * @param array<string,string> $env
     */
    private function persistEnvForReview(AppStore $store, string $id, array $app, ?string $dir, array $env): void
    {
        if ($dir !== null) {
            $envManager = new EnvManager();
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            $envManager->sync(['name' => $app['name'] ?? '', 'env' => $env], $dir, $composeFiles);
        }

        $store->update($id, function (array &$s) use ($env): void {
            $s['env'] = $env;
            $s['compose_files'] = self::composeFilesWithEnv(
                array_map('strval', (array) ($s['compose_files'] ?? ['docker-compose.yml'])),
                $env
            );
        });
    }

    /**
     * Daftar `compose_files` dengan override env sebagai **satu-satunya** entri
     * env — selalu di akhir (menang atas compose repo), dibuang bila env kosong.
     *
     * Satu sumber kebenaran untuk `saveEnv()` (lokal & agent), `saveEnvOnAgent()`,
     * dan `persistEnvForReview()`: urutan relatif file lain tidak diubah, entri
     * duplikat/stale `docker-compose.override.env.yml` tidak bisa tertinggal.
     *
     * @param array<int,string>    $files
     * @param array<string,string> $env
     * @return array<int,string>
     */
    private static function composeFilesWithEnv(array $files, array $env): array
    {
        $files = array_values(array_filter(
            $files,
            static fn (string $f): bool => $f !== EnvManager::OVERRIDE_FILE
        ));
        if ($env !== []) {
            $files[] = EnvManager::OVERRIDE_FILE;
        }

        return $files;
    }

    /**
     * Normalisasi env var dari form (validasi ketat) — satu salinan untuk jalur
     * lokal & agent `saveEnv()`, supaya aturan kunci/nilai tidak menyimpang.
     *
     * @param array<string,mixed> $app
     * @return array<string,string>
     */
    private function normalizedEnvFromRequest(Request $request, array $app): array
    {
        $posted = (array) $request->post('env', []);
        $deleteKeys = array_values(array_filter(
            array_map('strval', (array) $request->post('env_delete', [])),
            static fn (string $k): bool => $k !== ''
        ));

        return $this->normalizeEnv($posted, $deleteKeys, is_array($app['env'] ?? null) ? $app['env'] : []);
    }

    /**
     * Normalisasi & validasi input env var dari form.
     *
     * - Kunci wajib ^[A-Za-z_][A-Za-z0-9_]*$ (validasi ketat).
     * - Nilai tidak boleh mengandung baris baru.
     * - Kunci duplikat di form ditolak.
     * - Kunci yang ditandai hapus (env_delete[]) dibuang.
     * - Existing yang tidak di-overwrite form tetap dipertahankan (tidak hilang).
     *
     * @param array $posted     baris env[i][key] / env[i][value]
     * @param array $deleteKeys kunci yang ditandai hapus
     * @param array $existing   env lama dari apps.json
     * @return array<string,string>
     */
    private function normalizeEnv(array $posted, array $deleteKeys, array $existing): array
    {
        $env = [];
        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' || in_array($key, $deleteKeys, true)) {
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new RuntimeException("Nama variabel tidak valid: \"{$key}\". Gunakan huruf/angka/underscore, diawali huruf atau underscore.");
            }
            $value = (string) ($row['value'] ?? '');
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException("Nilai untuk \"{$key}\" tidak boleh mengandung baris baru.");
            }
            if (array_key_exists($key, $env)) {
                throw new RuntimeException("Variabel \"{$key}\" duplikat. Hapus salah satunya.");
            }
            $env[$key] = $value;
        }

        // Sisa existing yang tidak dihapus & tidak di-overwrite form.
        foreach ($existing as $key => $value) {
            $key = (string) $key;
            if (in_array($key, $deleteKeys, true) || array_key_exists($key, $env)) {
                continue;
            }
            $env[$key] = (string) $value;
        }
        return $env;
    }
}
