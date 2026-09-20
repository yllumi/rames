<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\UserStore;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\ContainerNames;
use app\library\Deploy\DeployerFactory;
use app\library\Deploy\EnvManager;
use app\library\Deploy\NetworkManager;
use app\library\Db\DbContainerDetector;
use app\library\Docker\ComposeParser;
use app\library\Docker\DockerClient;
use app\library\Docker\PortManager;
use app\library\Git\GitService;
use app\library\Git\SshKeyManager;
use app\library\Nginx\NginxReloader;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use RuntimeException;
use support\Request;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * App Management (SPECS.md §7).
 *
 * Controller hanya mediator — seluruh logika bisnis di app/library.
 * Tidak ada state di properti controller (Webman persistent, lihat
 * copilot-instructions).
 */
class AppController
{
    // ==================================================================
    // Daftar & detail
    // ==================================================================

    public function index(Request $request)
    {
        $store = new AppStore();
        $user = current_user();
        $userId = (string) ($user['id'] ?? '');

        // Hanya app milik sendiri / yang dibagikan ke user ini. Admin melihat
        // semua app (dengan label owner).
        $visible = AppAccess::visible($store->all(), $user);

        $roles = [];
        $owned = [];
        foreach ($visible as $app) {
            $id = (string) $app['id'];
            $roles[$id] = (string) (AppAccess::roleFor($app, $user) ?? '');
            $owned[$id] = ((string) ($app['owner_id'] ?? '') === $userId);
        }

        $mine = array_filter($visible, static fn (array $a): bool => $owned[(string) $a['id']] ?? false);
        $shared = array_filter($visible, static fn (array $a): bool => in_array(
            $roles[(string) $a['id']] ?? '',
            [AppAccess::ROLE_OPERATOR, AppAccess::ROLE_VIEWER],
            true
        ));

        // Filter tab: all (default) | mine | shared.
        $scope = (string) $request->get('scope', 'all');
        $apps = match ($scope) {
            'mine' => array_values($mine),
            'shared' => array_values($shared),
            default => $visible,
        };

        // App milik sendiri tampil lebih dulu, selebihnya alfabetis.
        usort($apps, static function (array $a, array $b) use ($owned): int {
            $oa = ($owned[(string) $a['id']] ?? false) ? 0 : 1;
            $ob = ($owned[(string) $b['id']] ?? false) ? 0 : 1;
            return $oa <=> $ob ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return view('app/index', [
            'apps' => $apps,
            'roles' => $roles,
            'ownedIds' => $owned,
            'scope' => $scope,
            'counts' => ['mine' => count($mine), 'shared' => count($shared), 'all' => count($visible)],
            'isAdmin' => is_admin($user),
            'ownerNames' => is_admin($user) ? user_names() : [],
        ]);
    }

    public function detail(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'view');

        $deployer = DeployerFactory::create();

        // status container live (best effort — engine mungkin tidak tersedia)
        $live = [];
        try {
            $live = $deployer->getContainers($app['name']);
        } catch (\Throwable $e) {
            // pakai data tersimpan
        }

        // named volume project (untuk modal konfirmasi delete)
        $volumes = [];
        try {
            $volumes = $deployer->getProjectVolumes($app['name']);
        } catch (\Throwable $e) {
            // engine tidak tersedia — modal tampil tanpa daftar volume
        }

        // Network eksternal (shared) yang bisa di-join app ini. Kandidat:
        // bukan built-in & bukan milik app aktif (compose) — biasanya shared
        // network yang dibuat lewat halaman /networks.
        $availableNetworks = [];
        try {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
            $activeNames = [];
            foreach ($store->all() as $s) {
                $activeNames[(string) ($s['name'] ?? '')] = true;
            }
            foreach ($docker->listNetworks() as $n) {
                $nname = (string) ($n['Name'] ?? '');
                if ($nname === '' || in_array($nname, ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'], true)) {
                    continue;
                }
                $project = (string) (($n['Labels'] ?? [])['com.docker.compose.project'] ?? '');
                if ($project !== '' && isset($activeNames[$project])) {
                    continue; // network milik app aktif dikelola lewat app tsb
                }
                $availableNetworks[] = $nname;
            }
            sort($availableNetworks);
        } catch (\Throwable $e) {
            // engine tidak tersedia — form tampil tanpa daftar
        }

        // Public key deploy key app (untuk repo private via SSH)
        $sshPubkey = null;
        if (($app['auth_method'] ?? 'none') === 'ssh') {
            $sshPubkey = (new SshKeyManager())->publicKey($app['name']);
        }

        // Riwayat deploy (terbaru dulu) + versi aktif untuk tombol rollback
        $deployHistory = array_reverse($app['deploy_history'] ?? []);
        $activeSha = $this->resolveActiveSha($app);

        // Container MySQL/MariaDB milik app (untuk tab Database).
        $dbContainers = [];
        try {
            $dbContainers = (new DbContainerDetector())->detectForApp($app);
        } catch (\Throwable $e) {
            // engine tidak tersedia — tab Database tidak muncul
        }

        return view('app/detail', [
            'app' => $app,
            'live' => $live,
            'volumes' => $volumes,
            'availableNetworks' => $availableNetworks,
            'sshPubkey' => $sshPubkey,
            'deployHistory' => $deployHistory,
            'activeSha' => $activeSha,
            'dbContainers' => $dbContainers,
            'access' => $this->accessContext($app),
            'compose' => $this->composeContext($app),
        ]);
    }

    /**
     * Konteks tab "Compose" (app mode compose saja): nama file utama, isinya,
     * dan daftar file sumber (untuk editor & daftar file).
     *
     * @return array{main_file:string,content:string,files:array<int,string>}|null
     */
    private function composeContext(array $app): ?array
    {
        if (!ComposeSource::isCompose($app)) {
            return null;
        }
        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];

        return [
            'main_file' => ComposeSource::resolveMainFile($dir, $app),
            'content' => ComposeSource::readMain($dir, $app),
            'files' => ComposeSource::listSourceFiles($dir),
        ];
    }

    /**
     * Halaman khusus riwayat versi app (checkpoint rollback).
     * App mode compose tidak punya checkpoint Git — dialihkan ke detail app.
     */
    public function versions(Request $request, string $id)
    {
        $app = $this->findApp($id, 'view');

        if (ComposeSource::isCompose($app)) {
            flash_set('info', 'App ini dibuat dari file compose (tanpa repo Git) — riwayat versi & rollback tidak tersedia.');
            return redirect('/apps/' . $id);
        }

        return view('app/versions', [
            'app' => $app,
            'deployHistory' => array_reverse($app['deploy_history'] ?? []),
            'activeSha' => $this->resolveActiveSha($app),
        ]);
    }

    // ==================================================================
    // Wizard create — langkah 1 (form + clone + parse + saran port)
    // ==================================================================

    public function createForm(Request $request)
    {
        return view('app/create', [
            'mode' => ((string) $request->get('mode', 'git')) === 'compose' ? 'compose' : 'git',
        ]);
    }

    public function createPreview(Request $request)
    {
        $name = strtolower(trim((string) $request->post('name', '')));
        $repoUrl = trim((string) $request->post('repo_url', ''));
        $branch = trim((string) $request->post('branch', 'main'));
        $authMethod = (string) $request->post('auth_method', 'none');

        $keyManager = new SshKeyManager();
        $generatedKey = false;

        try {
            $this->validateCreateInput($name, $repoUrl, $branch, $authMethod);

            $store = new AppStore();
            if ($store->nameExists($name)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai.");
            }

            $appsPath = (string) config('deploy.apps_path');
            $dest = $appsPath . '/' . $name;
            if (is_dir($dest)) {
                // area apps_path dikelola sistem — bersihkan lalu clone ulang
                $this->cleanupDir($dest);
            }

            // Repo private: generate deploy key SSH sebelum clone. Kalau sudah ada
            // (percobaan ulang setelah user menambah deploy key), dipakai kembali.
            $sshKeyPath = null;
            if ($authMethod === 'ssh') {
                $keyManager->generate($name);
                $generatedKey = true;
                $sshKeyPath = $keyManager->privateKeyPath($name);
            }

            $git = new GitService();
            $git->clone($repoUrl, $branch, $dest, $sshKeyPath);

            $composeFile = $git->findComposeFile($dest);
            if ($composeFile === null) {
                $this->cleanupDir($dest);
                throw new RuntimeException('Repo tidak memiliki docker-compose.yml (atau .yaml) di root.');
            }

            $parsed = (new ComposeParser())->parse($dest . '/' . $composeFile);
            $services = $this->resolveServicePorts($parsed['services']);

            // simpan data pending (belum commit) di session
            $primary = $this->defaultPrimary($services);
            $request->session()->set('pending_app', [
                'name' => $name,
                'source' => ComposeSource::SOURCE_GIT,
                'repo_url' => $repoUrl,
                'branch' => $branch,
                'local_path' => 'apps/' . $name,
                'compose_file' => $composeFile,
                'services' => $services,
                'primary_service' => $primary,
                'primary_port' => $this->defaultPrimaryPort($services, $primary),
                'auth_method' => $authMethod,
                'ssh_key' => $authMethod === 'ssh' ? 'keys/' . $name : null,
            ]);

            return redirect('/apps/create/confirm');
        } catch (\Throwable $e) {
            // Repo private via SSH: kalau clone gagal (deploy key belum ditambahkan),
            // tampilkan public key di form agar user bisa menambahkannya ke repo
            // lalu mencoba Analisis Repo lagi (kunci dipakai ulang).
            if ($authMethod === 'ssh' && $keyManager->exists($name)) {
                return view('app/create', [
                    'mode' => 'git',
                    'sshPubkey' => $keyManager->publicKey($name),
                    'auth_method' => $authMethod,
                    'preview_error' => $e->getMessage(),
                    'form_name' => $name,
                    'form_repo_url' => $repoUrl,
                    'form_branch' => $branch,
                ]);
            }
            if ($generatedKey) {
                $keyManager->remove($name);
            }
            flash_set('error', $e->getMessage());
            return redirect('/apps/create');
        }
    }

    /**
     * Wizard create — mode "Compose": nama app + file `docker-compose.yml`
     * (paste di textarea atau upload) + file pendukung opsional, tanpa repo Git.
     *
     * Ditujukan untuk app yang memakai image prebuilt (tanpa build context).
     * File ditulis ke `apps/{name}` (seperti mode clone), compose di-parse untuk
     * deteksi port, lalu memakai halaman konfirmasi yang sama.
     */
    public function composePreview(Request $request)
    {
        $name = strtolower(trim((string) $request->post('name', '')));
        $composeContent = (string) $request->post('compose', '');
        $dest = '';

        try {
            $this->validateCreateName($name);

            $store = new AppStore();
            if ($store->nameExists($name)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai.");
            }

            // area apps_path dikelola sistem — bersihkan lalu tulis ulang file
            $dest = (string) config('deploy.apps_path') . '/' . $name;
            if (is_dir($dest)) {
                $this->cleanupDir($dest);
            }
            if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                throw new RuntimeException('Gagal membuat direktori app.');
            }

            ComposeSource::store(
                $dest,
                $this->uploadsFrom($request),
                $composeContent,
                (int) config('deploy.compose_upload_max_file_bytes', ComposeSource::MAX_FILE_BYTES),
                (int) config('deploy.compose_upload_max_total_bytes', ComposeSource::MAX_TOTAL_BYTES)
            );

            $composeFile = ComposeSource::detectMainFile($dest);
            if ($composeFile === '') {
                throw new RuntimeException('File docker-compose.yml tidak ditemukan setelah upload.');
            }

            $parsed = (new ComposeParser())->parse($dest . '/' . $composeFile);
            $services = $this->resolveServicePorts($parsed['services']);

            $request->session()->set('pending_app', [
                'name' => $name,
                'source' => ComposeSource::SOURCE_COMPOSE,
                'repo_url' => null,
                'branch' => null,
                'local_path' => 'apps/' . $name,
                'compose_file' => $composeFile,
                'services' => $services,
                'primary_service' => $this->defaultPrimary($services),
                'primary_port' => $this->defaultPrimaryPort($services, $this->defaultPrimary($services)),
                'auth_method' => 'none',
                'ssh_key' => null,
            ]);

            return redirect('/apps/create/confirm');
        } catch (\Throwable $e) {
            // Gagal sebelum konfirmasi → direktori app dibersihkan, form dirender
            // ulang dengan isi yang sudah ditulis user (tidak hilang).
            if ($dest !== '' && is_dir($dest)) {
                $this->cleanupDir($dest);
            }

            return view('app/create', [
                'mode' => 'compose',
                'compose_error' => $e->getMessage(),
                'form_name' => $name,
                'form_compose' => $composeContent,
            ]);
        }
    }

    /**
     * Resolusi konflik host port untuk daftar service hasil parse compose
     * (dipakai kedua mode create — clone repo & compose).
     *
     * @param array<string,array> $services
     * @return array<string,array>
     */
    private function resolveServicePorts(array $services): array
    {
        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $portManager = new PortManager((int) $range['start'], (int) $range['end']);
        $usedPorts = $portManager->usedHostPorts((new AppStore())->all());

        return $portManager->resolve($services, $usedPorts);
    }

    /**
     * Normalisasi file unggahan dari request (Webman UploadFile / struktur
     * $_FILES) menjadi daftar entri yang dipahami ComposeSource::store().
     *
     * @return array<int,array{name:string,tmp_name:string,size:int,error:int}>
     */
    private function uploadsFrom(Request $request): array
    {
        $files = $request->file('files');
        if (!is_array($files) || $files === []) {
            return [];
        }

        $result = [];
        foreach (array_values($files) as $file) {
            if ($file instanceof \Webman\Http\UploadFile) {
                $name = trim((string) $file->getUploadName());
                // Input file kosong dikirim browser dengan nama kosong → abaikan.
                if ($name === '') {
                    continue;
                }
                $path = (string) $file->getPathname();
                $size = 0;
                try {
                    $size = (int) $file->getSize();
                } catch (\Throwable $e) {
                    $size = 0; // file gagal di-upload → error code yang menentukan
                }
                $result[] = [
                    'name' => $name,
                    'tmp_name' => $file->isValid() ? $path : '',
                    'size' => $size,
                    'error' => (int) ($file->getUploadErrorCode() ?? UPLOAD_ERR_NO_FILE),
                ];
                continue;
            }
            if (is_array($file)) {
                foreach (ComposeSource::normalizeUploads($file) as $entry) {
                    if (trim((string) ($entry['name'] ?? '')) === '') {
                        continue;
                    }
                    $result[] = $entry;
                }
            }
        }

        return $result;
    }

    // ==================================================================
    // Wizard create — langkah 2 (konfirmasi port & primary service)
    // ==================================================================

    public function confirmForm(Request $request)
    {
        $pending = $request->session()->get('pending_app');
        if (!$pending) {
            return redirect('/apps/create');
        }
        return view('app/confirm', ['pending' => $pending]);
    }

    public function confirmCreate(Request $request)
    {
        $pending = $request->session()->get('pending_app');
        if (!$pending) {
            return redirect('/apps/create');
        }

        $serviceInput = (array) $request->post('services', []);
        $primarySelection = (string) $request->post('primary', '');

        // Prefix nama container divalidasi lebih dulu supaya pesan errornya kembali
        // ke halaman konfirmasi (pending_app masih tersimpan), bukan ke form create.
        try {
            $containerPrefix = $this->resolveContainerPrefix(
                (string) $pending['name'],
                (string) $pending['compose_file'],
                (string) $request->post('container_prefix', '')
            );
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
            return redirect('/apps/create/confirm');
        }

        try {
            // Fail-fast: pastikan direktori Nginx dapat ditulis sebelum deploy.
            // Kalau tidak, tampilkan pesan jelas (bukan gagal di tengah build).
            DeployerFactory::create()->ensureWritable();

            $services = $this->validateAndApplyPorts($pending['services'], $serviceInput);
            $primary = $this->resolvePrimarySelection(
                $services,
                $primarySelection,
                (string) ($pending['primary_service'] ?? ''),
                (int) ($pending['primary_port'] ?? 0)
            );
            $primaryService = $primary['service'];
            $primaryPort = $primary['port'];

            $composeFiles = [$pending['compose_file']];
            $this->writeOverride($pending, $services, $composeFiles, $containerPrefix);

            $app = (new AppStore())->create([
                'name' => $pending['name'],
                'owner_id' => (string) (current_user()['id'] ?? ''),
                'members' => [],
                'subdomain' => app_subdomain($pending['name']),
                'source' => $pending['source'] ?? ComposeSource::SOURCE_GIT,
                'repo_url' => $pending['repo_url'] ?? null,
                'branch' => $pending['branch'] ?? null,
                'local_path' => $pending['local_path'],
                'primary_service' => $primaryService,
                'primary_port' => $primaryPort,
                'status' => 'deploying',
                'stage' => 'queued',
                'message' => 'Menunggu worker deploy ...',
                'compose_files' => $composeFiles,
                'container_prefix' => $containerPrefix !== '' ? $containerPrefix : null,
                'needs_ssl' => false,
                'ssl_status' => null,
                'auth_method' => $pending['auth_method'] ?? 'none',
                'ssh_key' => $pending['ssh_key'] ?? null,
                'containers' => [],
            ]);

            $request->session()->delete('pending_app');

            $spawned = $this->spawnWorker($app['id'], 'deploy');
            if (!$spawned) {
                (new AppStore())->update($app['id'], function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker deploy. Cek log & coba Rebuild.';
                    $s['error'] = 'Gagal spawn worker deploy.';
                });
            }

            // Panggilan AJAX (fetch) mengembalikan JSON; form biasa tetap redirect.
            if ($request->expectsJson()) {
                return json([
                    'code' => $spawned ? 0 : 1,
                    'id' => $app['id'],
                    'name' => $app['name'],
                    'error' => $spawned ? null : 'Gagal menjalankan worker deploy.',
                ]);
            }

            flash_set('success', 'App "' . $app['name'] . '" sedang di-deploy.');
            return redirect('/apps/' . $app['id']);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $e->getMessage()]);
            }
            flash_set('error', $e->getMessage());
            return redirect('/apps/create/confirm');
        }
    }

    // ==================================================================
    // Polling status (API)
    // ==================================================================

    public function status(Request $request, string $id)
    {
        $app = $this->findApp($id, 'view');
        return json([
            'code' => 0,
            'app' => [
                'id' => $app['id'],
                'name' => $app['name'],
                'status' => $app['status'] ?? 'unknown',
                'stage' => $app['stage'] ?? null,
                'message' => $app['message'] ?? '',
                'error' => $app['error'] ?? null,
            ],
        ]);
    }

    // ==================================================================
    // Aksi: rebuild / stop / start / delete
    // ==================================================================

    public function rebuild(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'deploy');

        // Tolak rebuild ganda saat app sedang diproses worker lain.
        if (($app['status'] ?? '') === 'deploying') {
            $msg = 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg, 'busy' => true]);
            }
            flash_set('error', $msg);
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            $msg = 'Direktori app tidak ada. App mungkin sudah dihapus.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg]);
            }
            flash_set('error', $msg);
            return redirect('/apps/' . $id);
        }

        $store->update($id, function (array &$s): void {
            $s['status'] = 'deploying';
            $s['stage'] = 'queued';
            $s['message'] = 'Menunggu worker rebuild ...';
            $s['error'] = null;
        });

        if (!$this->spawnWorker($id, 'rebuild')) {
            $store->update($id, function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker rebuild.';
                $s['error'] = 'Gagal spawn worker rebuild.';
            });
            $msg = 'Gagal menjalankan rebuild.';
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $msg]);
            }
            flash_set('error', $msg);
        } else {
            if ($request->expectsJson()) {
                return json([
                    'code' => 0,
                    'status' => 'deploying',
                    'stage' => 'queued',
                    'message' => 'Rebuild dimulai.',
                ]);
            }
            flash_set('success', 'Rebuild dijalankan.');
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Rollback app ke versi (commit) yang pernah sukses.
     *
     * Ref divalidasi: harus ada di deploy_history dengan status sukses/restored
     * dan bukan versi yang sedang aktif. Ditandai busy (status deploying) agar
     * tidak bentrok dengan deploy/rebuild/rollback lain, lalu worker di-spawn.
     */
    public function rollback(Request $request, string $id)
    {
        $app = $this->findApp($id, 'deploy');
        if (ComposeSource::isCompose($app)) {
            flash_set('error', 'App ini dibuat dari file compose (tanpa repo Git) — rollback versi tidak tersedia. Gunakan tab Compose untuk mengubah compose lalu Deploy Ulang.');
            return redirect('/apps/' . $id);
        }
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $ref = trim((string) $request->post('ref', ''));
        if ($ref === '' || !preg_match('/^[0-9a-fA-F]{7,40}$/', $ref)) {
            flash_set('error', 'Ref rollback tidak valid.');
            return redirect('/apps/' . $id);
        }

        // Cari di history — resolve ke full SHA bila user kirim short SHA.
        $fullRef = '';
        foreach (($app['deploy_history'] ?? []) as $h) {
            if (in_array(($h['status'] ?? ''), ['success', 'restored'], true) && str_starts_with((string) ($h['sha'] ?? ''), $ref)) {
                $fullRef = (string) $h['sha'];
                break;
            }
        }
        if ($fullRef === '') {
            flash_set('error', 'Ref tidak ditemukan di riwayat deploy yang sukses.');
            return redirect('/apps/' . $id);
        }

        // Tolak rollback ke versi yang sedang aktif (no-op).
        if ($fullRef === $this->resolveActiveSha($app)) {
            flash_set('error', 'Ref yang dipilih adalah versi yang sedang aktif.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        $store->update($id, function (array &$s): void {
            $s['status'] = 'deploying';
            $s['stage'] = 'queued';
            $s['message'] = 'Menunggu worker rollback ...';
            $s['error'] = null;
        });

        if (!$this->spawnWorker($id, 'rollback', $fullRef)) {
            $store->update($id, function (array &$s): void {
                $s['status'] = 'error';
                $s['stage'] = null;
                $s['message'] = 'Gagal menjalankan worker rollback.';
                $s['error'] = 'Gagal spawn worker rollback.';
            });
            flash_set('error', 'Gagal menjalankan rollback.');
        } else {
            flash_set('success', 'Rollback ke ' . substr($fullRef, 0, 7) . ' dijalankan.');
        }
        return redirect('/apps/' . $id);
    }

    public function stop(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'stop');
        try {
            DeployerFactory::create()->stop($app);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'stopped';
                $s['message'] = 'Stopped';
            });
            flash_set('success', 'App dihentikan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal stop: ' . $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    public function start(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'stop');
        try {
            DeployerFactory::create()->start($app);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'running';
                $s['message'] = 'Running';
            });
            flash_set('success', 'App dijalankan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal start: ' . $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    public function delete(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'delete');

        // mode: preserve (default, aman — volume tercentang dipertahankan) atau
        // purge (hapus total termasuk semua volume).
        $mode = (string) $request->post('mode', 'preserve');
        $preserve = array_values(array_filter(
            array_map('strval', (array) $request->post('preserve_volumes', [])),
            static fn (string $v): bool => $v !== ''
        ));

        try {
            if ($mode === 'purge') {
                // Hapus total: down -v (semua volume termasuk anonymous terhapus).
                DeployerFactory::create()->teardown($app, null);
            } else {
                // Default aman: pertahankan volume tercentang (data DB dll. tetap
                // ada, dipakai ulang bila app dibuat ulang dengan nama sama).
                DeployerFactory::create()->teardown($app, $preserve);
            }

            $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
            if (is_dir($dir)) {
                $this->cleanupDir($dir);
            }

            // Bersihkan pasangan kunci SSH deploy key app
            if (($app['auth_method'] ?? 'none') === 'ssh') {
                (new SshKeyManager())->remove($app['name']);
            }

            // Bersihkan managed env file app (override env di dalam dir app
            // ikut terhapus bersama cleanupDir).
            (new EnvManager())->remove($app['name']);

            $store->delete($id);
            flash_set('success', 'App "' . $app['name'] . '" dihapus.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal menghapus: ' . $e->getMessage());
        }
        return redirect('/apps');
    }

    // ==================================================================
    // Kepemilikan & sharing (owner_id + members)
    // ==================================================================

    /**
     * Tambah / ubah akses seorang user ke app (POST /apps/{id}/members).
     * Hanya owner app (atau admin) yang boleh — ability 'sharing'.
     */
    public function addMember(Request $request, string $id)
    {
        $store = new AppStore();
        $this->findApp($id, 'sharing');

        $userId = trim((string) $request->post('user_id', ''));
        $role = strtolower(trim((string) $request->post('role', AppAccess::ROLE_VIEWER)));

        try {
            if (!in_array($role, AppAccess::ASSIGNABLE_ROLES, true)) {
                throw new RuntimeException('Role tidak valid.');
            }
            $target = (new UserStore())->findPublicById($userId);
            if ($target === null) {
                throw new RuntimeException('User tidak ditemukan.');
            }
            $store->addMember($id, $userId, $role, (string) (current_user()['id'] ?? ''));
            flash_set('success', 'Akses "' . $target['username'] . '" diset sebagai ' . AppAccess::label($role) . '.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }

    /**
     * Cabut akses seorang member (POST /apps/{id}/members/{userId}/remove).
     */
    public function removeMember(Request $request, string $id, string $userId)
    {
        $store = new AppStore();
        $this->findApp($id, 'sharing');

        try {
            $current = $store->find($id);
            if ($current !== null && (string) ($current['owner_id'] ?? '') === $userId) {
                throw new RuntimeException('Owner app tidak bisa dicabut. Pindahkan kepemilikan (transfer owner) lebih dulu.');
            }
            if ((string) (current_user()['id'] ?? '') === $userId) {
                throw new RuntimeException('Tidak bisa mencabut akses Anda sendiri.');
            }
            $store->removeMember($id, $userId);
            flash_set('success', 'Akses user dicabut.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }

    /**
     * Pindahkan kepemilikan app ke user lain (POST /apps/{id}/owner).
     * Owner lama tetap terdaftar sebagai co-owner (role owner) agar tidak
     * kehilangan akses mendadak.
     */
    public function transferOwner(Request $request, string $id)
    {
        $store = new AppStore();
        $this->findApp($id, 'sharing');

        $userId = trim((string) $request->post('user_id', ''));

        try {
            $target = (new UserStore())->findPublicById($userId);
            if ($target === null) {
                throw new RuntimeException('User tidak ditemukan.');
            }
            $store->transferOwner($id, $userId, (string) (current_user()['id'] ?? ''));
            flash_set('success', 'Kepemilikan app dipindahkan ke "' . $target['username'] . '". Anda tetap terdaftar sebagai co-owner.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id . '#access');
    }

    // ==================================================================
    // Custom domain (set / hapus)
    // ==================================================================

    /**
     * Set / ganti custom domain app.
     *
     * Validasi: FQDN publik valid, bukan subdomain bawaan app sendiri, unik di
     * semua app (subdomain & custom domain app lain). Bila ada custom domain
     * lama yang punya sertifikat, di-revoke dulu. Setelah tersimpan, config
     * Nginx ditulis ulang (subdomain → redirect ke custom domain).
     */
    public function setDomain(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $domain = strtolower(trim((string) $request->post('domain', '')));
        $subdomain = app_subdomain($app['name']);
        $oldCustom = (string) ($app['custom_domain'] ?? '');

        try {
            if (!SslIssuer::isPublicDomain($domain)) {
                throw new RuntimeException('Custom domain harus FQDN publik yang valid (mis. example.org).');
            }
            if ($domain === $subdomain) {
                throw new RuntimeException('Custom domain tidak boleh sama dengan subdomain bawaan app.');
            }
            if ($domain === $oldCustom) {
                flash_set('info', 'Custom domain sudah di-set ke ' . $domain . '.');
                return redirect('/apps/' . $id);
            }
            $this->assertDomainUnique($store, $id, $domain);

            // Custom domain lama (bila ada sertifikat) di-revoke sebelum diganti.
            if ($oldCustom !== '') {
                try {
                    (new SslIssuer())->revoke($oldCustom);
                } catch (\Throwable $e) {
                    flash_set('info', 'Peringatan: gagal revoke sertifikat lama ' . $oldCustom . ' — ' . $e->getMessage());
                }
            }

            $store->update($id, function (array &$s) use ($domain): void {
                $s['custom_domain'] = $domain;
                $s['custom_ssl_status'] = 'disabled';
                $s['custom_ssl_stage'] = null;
                $s['custom_ssl_message'] = null;
                $s['custom_ssl_error'] = null;
                $s['custom_ssl_expires_at'] = null;
                $s['custom_needs_ssl'] = false;
            });

            $app = $store->find($id);
            if ($app !== null) {
                try {
                    $this->applyNginxConfig($app);
                } catch (\Throwable $e) {
                    flash_set('error', 'Custom domain ' . $domain . ' tersimpan, tetapi gagal menulis config Nginx: ' . $e->getMessage() . ' (jalankan Rebuild untuk menerapkan).');
                    return redirect('/apps/' . $id);
                }
            }

            // Reload nginx host agar reverse proxy custom domain langsung aktif.
            $reloadNote = $this->reloadNginxNote();
            flash_set('success', 'Custom domain ' . $domain . ' diset. Subdomain bawaan kini redirect ke domain tersebut.' . ($reloadNote !== '' ? ' ' . $reloadNote : '') . ' Jangan lupa arahkan DNS domain ke server, lalu aktifkan SSL-nya.');
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Hapus custom domain app. Sertifikat SSL domain (bila ada) di-revoke;
     * bila revoke gagal, domain tetap dihapus dari config (recovery via Rebuild).
     */
    public function removeDomain(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'domain');

        $customDomain = (string) ($app['custom_domain'] ?? '');
        if ($customDomain === '') {
            flash_set('info', 'App tidak memiliki custom domain.');
            return redirect('/apps/' . $id);
        }

        $revokeError = null;
        try {
            (new SslIssuer())->revoke($customDomain);
        } catch (\Throwable $e) {
            $revokeError = $e->getMessage();
        }

        $store->update($id, function (array &$s): void {
            $s['custom_domain'] = null;
            $s['custom_ssl_status'] = 'disabled';
            $s['custom_ssl_stage'] = null;
            $s['custom_ssl_message'] = null;
            $s['custom_ssl_error'] = null;
            $s['custom_ssl_expires_at'] = null;
            $s['custom_needs_ssl'] = false;
        });

        $app = $store->find($id);
        if ($app !== null) {
            try {
                $this->applyNginxConfig($app);
                // Reload nginx host agar subdomain kembali melayani app (best-effort).
                $reloadNote = $this->reloadNginxNote();
                if ($reloadNote !== '') {
                    $revokeError = ($revokeError ?? '') . ' ' . $reloadNote;
                }
            } catch (\Throwable $e) {
                $revokeError = ($revokeError ?? '') . ' Gagal menulis config Nginx: ' . $e->getMessage();
            }
        }

        if ($revokeError !== null) {
            flash_set('error', 'Custom domain ' . $customDomain . ' dihapus, tetapi: ' . $revokeError . ' (jalankan Rebuild untuk menerapkan config).');
        } else {
            flash_set('success', 'Custom domain ' . $customDomain . ' dihapus. Sertifikat SSL-nya di-revoke.');
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Tulis ulang config Nginx app (dipakai saat custom domain di-set/dihapus).
     */
    private function applyNginxConfig(array $app): void
    {
        DeployerFactory::create()->writeNginxConfig($app);
    }

    /**
     * Reload nginx host via NginxReloader (best-effort). Mengembalikan string
     * pesan error bila gagal, atau string kosong bila sukses.
     */
    private function reloadNginxNote(): string
    {
        try {
            $r = (new NginxReloader())->reload();
            return $r['ok'] ? '' : 'Reload Nginx GAGAL: ' . ($r['error'] ?? 'unknown error') . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
        } catch (\Throwable $e) {
            return 'Reload Nginx gagal: ' . $e->getMessage() . ' — klik tombol "Reload Nginx" untuk mencoba lagi.';
        }
    }

    /**
     * Pastikan sebuah domain belum dipakai app lain (sebagai subdomain bawaan
     * maupun custom domain). Subdomain app itu sendiri sudah dicek pemanggil.
     */
    private function assertDomainUnique(AppStore $store, string $excludeId, string $domain): void
    {
        foreach ($store->all() as $other) {
            if (($other['id'] ?? '') === $excludeId) {
                continue;
            }
            if (($other['subdomain'] ?? '') === $domain) {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai subdomain app "' . ($other['name'] ?? '?') . '".');
            }
            if (($other['custom_domain'] ?? '') === $domain) {
                throw new RuntimeException('Domain ' . $domain . ' sudah dipakai sebagai custom domain app "' . ($other['name'] ?? '?') . '".');
            }
        }
    }

    // ==================================================================
    // Environment variables (fitur "Environment Variables")
    // ==================================================================

    /**
     * Simpan environment variable app (POST /apps/{id}/env).
     *
     * Validasi ketat format kunci, lalu: tulis managed env file + override env
     * (EnvManager), persist ke apps.json, dan auto-recreate container via
     * `docker compose up -d` (tanpa build) agar perubahan langsung diterapkan.
     */
    public function saveEnv(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        try {
            $posted = (array) $request->post('env', []);
            $deleteKeys = array_values(array_filter(
                array_map('strval', (array) $request->post('env_delete', [])),
                static fn (string $k): bool => $k !== ''
            ));
            $env = $this->normalizeEnv($posted, $deleteKeys, is_array($app['env'] ?? null) ? $app['env'] : []);

            // 1) Tulis file dulu (managed + override). Gagal => tidak ada state
            //    yang berubah (apps.json belum disentuh, tetap konsisten).
            $envManager = new EnvManager();
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            $envManager->sync(['name' => $app['name'], 'env' => $env], $dir, $composeFiles);

            // 2) Persist ke apps.json (env + compose_files).
            $store->update($id, function (array &$s) use ($env): void {
                $s['env'] = $env;
                $files = $s['compose_files'] ?? ['docker-compose.yml'];
                $files = array_values(array_filter($files, static fn (string $f): bool => $f !== EnvManager::OVERRIDE_FILE));
                if ($env !== []) {
                    $files[] = EnvManager::OVERRIDE_FILE; // selalu terakhir → menang atas repo
                }
                $s['compose_files'] = $files;
            });

            // 3) Auto-recreate container (up -d tanpa build). Sinkron & cepat;
            //    kegagalan penerapan tidak menggagalkan penyimpanan.
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::create()->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', 'Environment variable disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'Environment variable tersimpan, tetapi gagal menerapkan ke container: ' . $e->getMessage() . ' — coba Rebuild untuk menerapkan.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Import variabel yang belum ada dari .env.example di direktori app
     * (POST /apps/{id}/env/import). Hanya mengisi nilai default; TIDAK
     * auto-recreate — user meninjau nilainya dulu, lalu klik "Simpan & Terapkan".
     */
    public function importEnv(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'env');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        try {
            $envManager = new EnvManager();
            $example = $envManager->parseEnvExample($dir);
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
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            $envManager->sync(['name' => $app['name'], 'env' => $env], $dir, $composeFiles);
            $store->update($id, function (array &$s) use ($env): void {
                $s['env'] = $env;
                $files = $s['compose_files'] ?? ['docker-compose.yml'];
                $files = array_values(array_filter($files, static fn (string $f): bool => $f !== EnvManager::OVERRIDE_FILE));
                $files[] = EnvManager::OVERRIDE_FILE;
                $s['compose_files'] = $files;
            });

            flash_set('success', "Diimport {$added} variabel dari .env.example. Tinjau nilainya, lalu klik \"Simpan & Terapkan\" untuk menerapkan ke container.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Simpan external network app (POST /apps/{id}/network).
     *
     * Menghubungkan seluruh service app ke shared network (via compose override
     * `external: true` + `networks: [default, <ext>]`) agar persisten lintas
     * Rebuild/Rollback. Validasi ketat: nama network & keberadaannya di Engine;
     * lalu tulis override, persist ke apps.json, dan recreate container
     * (`up -d` tanpa build) untuk menerapkan.
     */
    public function saveNetworks(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'network');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        // Normalisasi pilihan dari form + validasi format nama.
        $selected = [];
        foreach (array_map('strval', (array) $request->post('external_networks', [])) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
                flash_set('error', "Nama network tidak valid: {$name}");
                return redirect('/apps/' . $id);
            }
            if (!in_array($name, $selected, true)) {
                $selected[] = $name;
            }
        }

        // Validasi network benar-benar ada di Engine (bukan built-in).
        if ($selected !== []) {
            try {
                $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
                $existing = [];
                foreach ($docker->listNetworks() as $n) {
                    $existing[(string) ($n['Name'] ?? '')] = true;
                }
                foreach ($selected as $name) {
                    if (!isset($existing[$name]) || in_array($name, ['bridge', 'host', 'none', 'ingress', 'docker_gwbridge'], true)) {
                        flash_set('error', "Network \"{$name}\" tidak ditemukan (atau built-in). Buat shared network dulu di halaman Networks.");
                        return redirect('/apps/' . $id);
                    }
                }
            } catch (\Throwable $e) {
                flash_set('error', 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage());
                return redirect('/apps/' . $id);
            }
        }

        try {
            // 1) Tulis override dulu — gagal => state apps.json tidak berubah.
            $composeFiles = $app['compose_files'] ?? ['docker-compose.yml'];
            (new NetworkManager())->sync(['name' => $app['name'], 'external_networks' => $selected], $dir, $composeFiles);

            // 2) Persist ke apps.json (external_networks + compose_files).
            $store->update($id, function (array &$s) use ($selected): void {
                $s['external_networks'] = $selected;
                $files = $s['compose_files'] ?? ['docker-compose.yml'];
                $files = array_values(array_filter($files, static fn (string $f): bool => $f !== NetworkManager::OVERRIDE_FILE));
                if ($selected !== []) {
                    $files[] = NetworkManager::OVERRIDE_FILE; // selalu terakhir
                }
                $s['compose_files'] = $files;
            });

            // 3) Recreate container (up -d tanpa build) agar network diterapkan.
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::create()->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', 'External networks disimpan & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'External networks tersimpan, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — pastikan network eksternal ada, lalu coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        return redirect('/apps/' . $id);
    }

    /**
     * Simpan prefix nama container app (POST /apps/{id}/container-names) —
     * form "Nama container" di tab Container (ability `compose`, operator+).
     *
     * Alur (validasi dulu, baru tulis — gagal = tidak ada state yang berubah):
     *  1) Normalisasi & validasi prefix; tolak service ber-replica; fail-fast bila
     *     nama final sudah dipakai container lain (nama container unik se-host,
     *     tanpa prefix project).
     *  2) Tulis/hapus `docker-compose.override.names.yml` + rapikan compose_files.
     *  3) Recreate container (`up -d` tanpa build) agar nama baru dipakai.
     */
    public function saveContainerNames(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'compose');
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        $composeFiles = (array) ($app['compose_files'] ?? ['docker-compose.yml']);

        try {
            $prefix = $this->resolveContainerPrefix(
                (string) $app['name'],
                ComposeSource::resolveMainFile($dir, $app),
                (string) $request->post('container_prefix', '')
            );

            // 1) Tulis file dulu — gagal => state apps.json tidak berubah.
            if ($prefix === '') {
                ContainerNames::removeOverride($dir);
            } else {
                ContainerNames::writeOverride($dir, array_keys(ContainerNames::services($dir, $composeFiles)), $prefix);
            }

            // 2) Persist prefix + compose_files (urutan tetap: reset → ports → names → lain).
            $store->update($id, function (array &$s) use ($prefix, $composeFiles): void {
                $s['container_prefix'] = $prefix !== '' ? $prefix : null;
                $files = array_values(array_filter(
                    $composeFiles,
                    static fn (string $f): bool => $f !== ContainerNames::OVERRIDE_FILE
                ));
                if ($prefix !== '') {
                    $files[] = ContainerNames::OVERRIDE_FILE;
                }
                $s['compose_files'] = $this->orderComposeFiles($files);
            });

            // 3) Recreate container agar nama baru dipakai (tanpa build).
            $app = $store->find($id) ?? $app;
            try {
                $applied = DeployerFactory::create()->applyEnv($app, static function (string $stage, string $message): void {
                });
                $store->update($id, function (array &$s) use ($applied): void {
                    $s['containers'] = $applied['containers'] ?? [];
                    $s['status'] = 'running';
                    $s['message'] = 'Running';
                    $s['error'] = null;
                });
                flash_set('success', $prefix === ''
                    ? 'Nama container dikembalikan ke default compose & container diciptakan ulang.'
                    : 'Nama container disimpan (prefix "' . $prefix . '") & container diciptakan ulang.');
            } catch (\Throwable $e) {
                flash_set('error', 'Nama container tersimpan, tetapi gagal diterapkan ke container: ' . $e->getMessage() . ' — coba Rebuild.');
            }
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Validasi prefix nama container dari form:
     *  - normalisasi + validasi format;
     *  - service ber-replica > 1 ditolak (compose tidak mengizinkan container_name);
     *  - nama final harus belum dipakai container lain di host — fail-fast, tanpa
     *    ini `docker compose up` gagal di tengah deploy dengan "Conflict".
     *
     * @return string prefix final ('' = pakai nama default compose)
     */
    private function resolveContainerPrefix(string $appName, string $composeFile, string $rawPrefix): string
    {
        $prefix = ContainerNames::normalizePrefix($rawPrefix);
        ContainerNames::assertValidPrefix($prefix);
        if ($prefix === '') {
            return '';
        }

        $dir = (string) config('deploy.apps_path') . '/' . $appName;
        $services = ContainerNames::services($dir, [$composeFile]);
        ContainerNames::assertNotReplicated($services);
        ContainerNames::assertAvailable(
            ContainerNames::mapFor(array_keys($services), $prefix),
            $this->usedContainerNames($appName)
        );

        return $prefix;
    }

    /**
     * Nama container yang sudah terpakai di host, di luar project app ini.
     * Engine API jadi acuan utama (mencakup container app lain, container
     * eksternal, dan container dashboard sendiri); apps.json dipakai sebagai
     * cadangan bila Engine tidak dapat diakses.
     *
     * @return array<string,string> nama => deskripsi pemilik
     */
    private function usedContainerNames(string $ownProject): array
    {
        try {
            $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
            return ContainerNames::usedFromEngine($docker->listContainers(), $ownProject);
        } catch (\Throwable $e) {
            return ContainerNames::usedFromApps((new AppStore())->all(), $ownProject);
        }
    }

    /**
     * Urutkan compose_files: base compose → override reset/ports/names →
     * override lain (network, env). Override env wajib paling akhir agar tetap
     * menang atas file repo.
     *
     * @param array<int,string> $files
     * @return array<int,string>
     */
    private function orderComposeFiles(array $files): array
    {
        $priority = [
            ComposeSource::RESET_OVERRIDE_FILE,
            ComposeSource::PORTS_OVERRIDE_FILE,
            ContainerNames::OVERRIDE_FILE,
        ];

        $base = [];
        $rest = [];
        foreach ($files as $file) {
            $file = (string) $file;
            if ($file === '') {
                continue;
            }
            if (!str_starts_with($file, ComposeSource::GENERATED_PREFIX)) {
                $base[] = $file;
                continue;
            }
            if (!in_array($file, $priority, true)) {
                $rest[] = $file;
            }
        }
        foreach ($priority as $file) {
            if (in_array($file, $files, true)) {
                $base[] = $file;
            }
        }

        return array_values(array_unique(array_merge($base, $rest)));
    }

    /**
     * Simpan perubahan compose app mode compose (POST /apps/{id}/compose) —
     * tab "Compose" di detail app.
     *
     * Alur (validasi dulu, baru tulis — gagal = tidak ada state yang berubah):
     *  1) Validasi isi compose (YAML valid, ada service, tanpa `build:`).
     *  2) Rencanakan host port: port lama tiap service dipertahankan, konflik
     *     dengan app lain digeser ke port bebas (PortManager).
     *  3) Tulis file compose utama + file pendukung baru, hapus file tercentang.
     *  4) Tulis ulang override port (2 lapis: reset + ports).
     *  5) Persist compose_files/primary_service + spawn worker `apply`
     *     (up -d tanpa build + tulis config Nginx; progres via polling).
     */
    public function saveCompose(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $this->findApp($id, 'compose');

        if (ComposeSource::isGit($app)) {
            flash_set('error', 'App ini dibuat dari repo Git — perbarui source lewat Rebuild (git pull).');
            return redirect('/apps/' . $id);
        }
        if (($app['status'] ?? '') === 'deploying') {
            flash_set('error', 'App sedang diproses (deploy/rebuild/rollback). Tunggu sampai selesai dulu.');
            return redirect('/apps/' . $id);
        }

        $dir = (string) config('deploy.apps_path') . '/' . $app['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori app tidak ada. App mungkin sudah dihapus.');
            return redirect('/apps/' . $id);
        }

        try {
            $content = (string) $request->post('compose', '');
            $mainFile = ComposeSource::resolveMainFile($dir, $app);

            // 1) Validasi & rencana port DULU (belum ada file/state yang diubah).
            ComposeSource::assertDeployable($content);
            $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
            $services = ComposeSource::planHostPorts(
                ComposeSource::parseContent($content),
                ComposeSource::existingHostPorts($app),
                $this->usedHostPortsExcept($id),
                (int) $range['start'],
                (int) $range['end']
            );
            $primaryPortSelection = $this->resolvePrimarySelection(
                $services,
                '',
                (string) ($app['primary_service'] ?? ''),
                (int) ($app['primary_port'] ?? 0)
            );
            $primary = $primaryPortSelection['service'];
            $primaryPort = $primaryPortSelection['port'];

            // 2) Tulis file: compose utama (divalidasi ulang oleh store) +
            //    file pendukung baru (upload), lalu hapus file tercentang.
            $uploads = $this->uploadsFrom($request);
            ComposeSource::store(
                $dir,
                $uploads,
                $content,
                (int) config('deploy.compose_upload_max_file_bytes', ComposeSource::MAX_FILE_BYTES),
                (int) config('deploy.compose_upload_max_total_bytes', ComposeSource::MAX_TOTAL_BYTES),
                $mainFile
            );
            $delete = array_values(array_filter(
                array_map('strval', (array) $request->post('file_delete', [])),
                static fn (string $f): bool => $f !== ''
            ));
            $removed = ComposeSource::removeFiles($dir, $delete, $mainFile);

            // 3) Tulis ulang override port; override lain (network/env) tetap
            //    di urutan belakang agar prioritas compose tidak berubah.
            $composeFiles = [$mainFile];
            $this->writeOverride(
                ['name' => $app['name']],
                $services,
                $composeFiles,
                ContainerNames::normalizePrefix($app['container_prefix'] ?? '')
            );
            $composeFiles = array_values(array_unique(array_merge($composeFiles, $this->generatedExtras($app))));

            // 4) Persist + spawn worker apply (up -d tanpa build + Nginx).
            $store->update($id, function (array &$s) use ($composeFiles, $primary, $primaryPort): void {
                $s['compose_files'] = $composeFiles;
                $s['primary_service'] = $primary;
                $s['primary_port'] = $primaryPort;
                $s['status'] = 'deploying';
                $s['stage'] = 'queued';
                $s['message'] = 'Menerapkan perubahan compose ...';
                $s['error'] = null;
            });

            if (!$this->spawnWorker($id, 'apply')) {
                $store->update($id, function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker deploy.';
                    $s['error'] = 'Gagal spawn worker deploy.';
                });
                flash_set('error', 'Compose tersimpan, tetapi gagal menjalankan deploy ulang.');
                return redirect('/apps/' . $id);
            }

            $extra = $removed !== [] ? ' ' . count($removed) . ' file dihapus.' : '';
            flash_set('success', 'Compose disimpan — container diciptakan ulang di latar belakang.' . $extra);
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        return redirect('/apps/' . $id);
    }

    /**
     * Host port yang terpakai app LAIN (untuk mengedit compose app ini: port
     * milik app sendiri bukan konflik).
     *
     * @return array<int,int>
     */
    private function usedHostPortsExcept(string $id): array
    {
        $others = array_values(array_filter(
            (new AppStore())->all(),
            static fn (array $a): bool => (string) ($a['id'] ?? '') !== $id
        ));

        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $portManager = new PortManager((int) $range['start'], (int) $range['end']);

        return $portManager->usedHostPorts($others);
    }

    /**
     * File override generated milik app (selain override port yang ditulis ulang
     * di sini) — mis. override network & env. Urutannya dipertahankan agar
     * override env tetap paling akhir (menang atas repo).
     *
     * @return array<int,string>
     */
    private function generatedExtras(array $app): array
    {
        $keep = [];
        foreach ((array) ($app['compose_files'] ?? []) as $file) {
            $file = (string) $file;
            if (!str_starts_with($file, ComposeSource::GENERATED_PREFIX)) {
                continue;
            }
            if (in_array($file, [ComposeSource::RESET_OVERRIDE_FILE, ComposeSource::PORTS_OVERRIDE_FILE, ContainerNames::OVERRIDE_FILE], true)) {
                continue; // ditulis ulang oleh writeOverride()
            }
            $keep[] = $file;
        }
        return $keep;
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

    // ==================================================================
    // Helper privat
    // ==================================================================

    /**
     * Ambil app + pastikan user berhak melakukan `ability`.
     *
     * Melempar AppAccessDenied (dirender sebagai 404 oleh webman) bila app tidak
     * ada ATAU tidak boleh diakses — supaya keberadaan app milik user lain tidak
     * bocor lewat perbedaan pesan 403 vs 404.
     *
     * @throws AppAccessDenied
     */
    private function findApp(string $id, string $ability): array
    {
        $app = (new AppStore())->find($id);
        if ($app === null) {
            throw new AppAccessDenied($ability, $id);
        }
        AppAccess::require($ability, $app, current_user());

        return $app;
    }

    /**
     * Data untuk tab "Akses" di halaman detail app.
     *
     * @return array{role:?string, owner:?array, owner_id:string, members:array<int,array>, candidates:array<int,array>, users:array<int,array>, abilities:array<string,bool>}
     */
    private function accessContext(array $app): array
    {
        $users = (new UserStore())->listWithRoles();
        $byId = [];
        foreach ($users as $user) {
            $byId[(string) ($user['id'] ?? '')] = $user;
        }

        $ownerId = (string) ($app['owner_id'] ?? '');
        $memberIds = [];
        $members = [];

        $rawMembers = is_array($app['members'] ?? null) ? $app['members'] : [];
        foreach ($rawMembers as $userId => $entry) {
            $userId = (string) $userId;
            $role = is_array($entry) ? (string) ($entry['role'] ?? '') : (string) $entry;
            if (!in_array($role, AppAccess::ASSIGNABLE_ROLES, true)) {
                continue;
            }
            $memberIds[] = $userId;
            $members[] = [
                'id' => $userId,
                'username' => (string) ($byId[$userId]['username'] ?? $userId . ' (user dihapus)'),
                'role' => $role,
                'added_at' => is_array($entry) ? (string) ($entry['added_at'] ?? '') : '',
                'exists' => isset($byId[$userId]),
            ];
        }

        // Kandidat user yang belum punya akses.
        $candidates = [];
        foreach ($users as $user) {
            $userId = (string) ($user['id'] ?? '');
            if ($userId === $ownerId || in_array($userId, $memberIds, true)) {
                continue;
            }
            $candidates[] = $user;
        }

        $role = AppAccess::roleFor($app, current_user());

        return [
            'role' => $role,
            'owner' => $ownerId !== '' ? ($byId[$ownerId] ?? null) : null,
            'owner_id' => $ownerId,
            'members' => $members,
            'candidates' => $candidates,
            'users' => array_values($byId),
            'abilities' => AppAccess::abilitiesFor($role),
        ];
    }

    private function validateCreateInput(string $name, string $repoUrl, string $branch, string $authMethod = 'none'): void
    {
        $this->validateCreateName($name);
        if (!in_array($authMethod, ['none', 'ssh'], true)) {
            throw new RuntimeException('Metode akses repo tidak valid.');
        }
        if (!$this->isRepoUrlValid($repoUrl, $authMethod)) {
            throw new RuntimeException(
                $authMethod === 'ssh'
                    ? 'URL repo tidak valid untuk SSH. Gunakan git@host:user/repo.git atau ssh://git@host/user/repo.git'
                    : 'URL repo tidak valid (harus http/https).'
            );
        }
        if ($branch === '' || !preg_match('/^[a-zA-Z0-9._\/-]+$/', $branch)) {
            throw new RuntimeException('Branch tidak valid.');
        }
    }

    /**
     * Validasi nama app (slug) — dipakai kedua mode create.
     */
    private function validateCreateName(string $name): void
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            throw new RuntimeException('Nama app hanya boleh huruf kecil a-z, angka, dan strip (-).');
        }
        if (strlen($name) > 63) {
            throw new RuntimeException('Nama app maksimal 63 karakter.');
        }
    }

    /**
     * Validasi format URL repo sesuai metode akses:
     *   - publik (none): http/https
     *   - ssh          : scp-like (git@host:user/repo.git) atau ssh:// / git://
     */
    private function isRepoUrlValid(string $repoUrl, string $authMethod): bool
    {
        if ($repoUrl === '' || preg_match('/[\s\x00-\x1F]/', $repoUrl)) {
            return false;
        }
        // scp-like: git@github.com:user/repo.git
        if (preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:[^\s]+$/', $repoUrl)) {
            return true;
        }
        try {
            $scheme = strtolower((string) parse_url($repoUrl, PHP_URL_SCHEME));
        } catch (\Throwable $e) {
            return false;
        }
        if ($authMethod === 'ssh') {
            return in_array($scheme, ['ssh', 'git'], true) && filter_var($repoUrl, FILTER_VALIDATE_URL) !== false;
        }
        return in_array($scheme, ['http', 'https'], true) && filter_var($repoUrl, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param array<string,array> $services
     */
    private function defaultPrimary(array $services): string
    {
        foreach ($services as $name => $svc) {
            if (($svc['host_port'] ?? null) !== null) {
                return (string) $name;
            }
        }
        // tidak ada service dengan port exposed -> dibiarkan kosong; konfirmasi
        // akan menolak dengan pesan jelas (resolvePrimaryService)
        return '';
    }

    /**
     * Port container default yang di-proxy untuk sebuah service: port pertama
     * yang ditulis di `ports:` compose (0 bila service tidak punya port).
     *
     * @param array<string,array> $services
     */
    private function defaultPrimaryPort(array $services, string $service): int
    {
        if ($service === '' || !isset($services[$service]['ports'])) {
            return 0;
        }
        return (int) ($services[$service]['ports'][0]['container'] ?? 0);
    }

    /**
     * Validasi & terapkan host port hasil edit user (langkah konfirmasi).
     *
     * Form mengirim satu input per port service:
     * `services[<svc>][ports][<containerPort>][host_port]` — jadi service dengan
     * lebih dari satu port (mis. web + gateway API) bisa punya host port sendiri
     * masing-masing. Bentuk lama `services[<svc>][host_port]` (hanya port pertama)
     * tetap diterima sebagai fallback.
     *
     * @param array<string,array> $services
     * @param array               $input
     * @return array<string,array>
     */
    private function validateAndApplyPorts(array $services, array $input): array
    {
        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $portManager = new PortManager((int) $range['start'], (int) $range['end']);
        $usedPorts = $portManager->usedHostPorts((new AppStore())->all());

        $localUsed = [];
        foreach ($services as $svcName => $svc) {
            foreach ((array) ($svc['ports'] ?? []) as $i => $entry) {
                $containerPort = (int) ($entry['container'] ?? 0);
                $posted = $input[$svcName]['ports'][$containerPort]['host_port']
                    ?? $input[$svcName]['ports'][$i]['host_port']
                    ?? ($i === 0 ? ($input[$svcName]['host_port'] ?? null) : null);

                $hostPort = $posted !== null && $posted !== '' ? (int) $posted : (int) ($entry['host'] ?? 0);
                $label = 'service "' . $svcName . '"' . ($containerPort > 0 ? ', container port ' . $containerPort : '');

                if ($hostPort <= 0 || !$portManager->validatePort($hostPort)) {
                    throw new RuntimeException("Port tidak valid untuk {$label}.");
                }
                if (in_array($hostPort, $usedPorts, true) || in_array($hostPort, $localUsed, true)) {
                    throw new RuntimeException("Port {$hostPort} ({$label}) sudah terpakai. Pilih port lain.");
                }

                $localUsed[] = $hostPort;
                $usedPorts[] = $hostPort;

                $services[$svcName]['ports'][$i]['host'] = $hostPort;
                if ($i === 0) {
                    $services[$svcName]['host_port'] = $hostPort;
                }
            }
        }

        return $services;
    }

    /**
     * Resolusi service + port yang di-proxy Nginx dari input halaman konfirmasi.
     *
     * `primary` berbentuk `<service>:<containerPort>` (boleh hanya `<service>`
     * untuk kompatibilitas). Bila tidak ada/tidak valid dipakai `$fallbackService`
     * + `$preferredPort` (dari langkah analisis), else port pertama service tsb.
     *
     * @param array<string,array> $services
     * @return array{service:string,port:int}
     */
    private function resolvePrimarySelection(array $services, string $selection, string $fallbackService = '', int $preferredPort = 0): array
    {
        $service = '';
        $port = 0;
        if ($selection !== '') {
            if (str_contains($selection, ':')) {
                [$svc, $p] = explode(':', $selection, 2);
                $service = trim($svc);
                $port = (int) $p;
            } else {
                $service = trim($selection);
            }
        }

        if ($service === '') {
            $service = $this->resolvePrimaryService($services, $fallbackService);
        } elseif (!isset($services[$service])) {
            throw new RuntimeException('Service "' . $service . '" tidak ditemukan pada compose app.');
        }

        // Port yang valid = port yang benar-benar punya host port di service itu.
        $available = [];
        foreach ((array) ($services[$service]['ports'] ?? []) as $entry) {
            if (!empty($entry['host'])) {
                $available[] = (int) $entry['container'];
            }
        }

        if ($port <= 0 && $preferredPort > 0 && in_array($preferredPort, $available, true)) {
            $port = $preferredPort;
        }
        if ($port > 0 && !in_array($port, $available, true)) {
            throw new RuntimeException(
                'Port ' . $port . ' tidak tersedia pada service "' . $service . '" — pilih salah satu port yang terdaftar.'
            );
        }
        if ($port <= 0) {
            $port = (int) ($available[0] ?? 0);
        }
        if ($port <= 0) {
            throw new RuntimeException('Tidak ada service dengan port exposed. Primary port tidak bisa ditentukan.');
        }

        return ['service' => $service, 'port' => $port];
    }

    /**
     * @param array<string,array> $services
     */
    private function resolvePrimaryService(array $services, string $primary): string
    {
        $candidates = array_filter(array_keys($services), static fn (string $n): bool => isset($services[$n]['host_port']));
        if ($primary !== '' && in_array($primary, array_keys($services), true) && isset($services[$primary]['host_port'])) {
            return $primary;
        }
        if ($candidates !== []) {
            return (string) array_values($candidates)[0];
        }
        throw new RuntimeException('Tidak ada service dengan port exposed. Primary service tidak bisa ditentukan.');
    }

    /**
     * Tulis docker-compose override berisi ports hasil edit user —
     * file compose asli dari repo tetap bersih (SPECS.md §7.2 langkah 8).
     *
     * Penting: docker compose MENGGABUNGKAN daftar "ports" (base + override,
     * bukan mengganti). Supaya port bawaan repo benar-benar diganti, dipakai
     * dua lapis override sesuai compose spec:
     *   1) docker-compose.override.yml       -> ports: !reset [] (hapus port bawaan)
     *   2) docker-compose.override.ports.yml -> ports: [host:container] (port final)
     *
     * Lapis ketiga (opsional) = override nama container
     * (docker-compose.override.names.yml) bila prefix nama diisi user.
     *
     * @param array                  $pending
     * @param array<string,array>    $services
     * @param array<int,string>      $composeFiles (by reference — ditambah nama override)
     * @param string|null            $containerPrefix '' = pakai nama default compose
     */
    private function writeOverride(array $pending, array $services, array &$composeFiles, ?string $containerPrefix = null): void
    {
        $dir = (string) config('deploy.apps_path') . '/' . $pending['name'];
        $reset = ['services' => []];
        $ports = ['services' => []];

        foreach ($services as $svcName => $svc) {
            // Service tanpa port exposed (mis. php-fpm) tidak perlu override.
            if (empty($svc['ports'])) {
                continue;
            }

            $reset['services'][$svcName]['ports'] = new TaggedValue('reset', []);

            $portEntries = [];
            foreach ($svc['ports'] as $entry) {
                $container = (int) $entry['container'];
                $host = $entry['host'] ?? null;
                $portEntries[] = $host !== null && $host > 0
                    ? $host . ':' . $container
                    : (string) $container;
            }
            $ports['services'][$svcName]['ports'] = $portEntries;
        }

        $resetYaml = Yaml::dump($reset, 4, 2);
        if (file_put_contents($dir . '/' . ComposeSource::RESET_OVERRIDE_FILE, $resetYaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . ComposeSource::RESET_OVERRIDE_FILE . '.');
        }
        $composeFiles[] = ComposeSource::RESET_OVERRIDE_FILE;

        $portsYaml = Yaml::dump($ports, 4, 2);
        if (file_put_contents($dir . '/' . ComposeSource::PORTS_OVERRIDE_FILE, $portsYaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis ' . ComposeSource::PORTS_OVERRIDE_FILE . '.');
        }
        $composeFiles[] = ComposeSource::PORTS_OVERRIDE_FILE;

        // Lapis 3 (opsional): nama container (container_name) hasil override user.
        // Daftar service diambil dari base compose yang ada di disk (bukan dari
        // $services pemanggil) supaya jalur tab Compose pun ikut tervalidasi
        // replicanya — container_name tidak kompatibel dengan deploy.replicas > 1.
        if ($containerPrefix !== null && $containerPrefix !== '') {
            $declared = ContainerNames::services($dir, $composeFiles);
            ContainerNames::assertNotReplicated($declared);
            ContainerNames::writeOverride($dir, array_keys($declared), $containerPrefix);
            $composeFiles[] = ContainerNames::OVERRIDE_FILE;
        } else {
            ContainerNames::removeOverride($dir);
        }
    }

    /**
     * Spawn background worker deploy (detached, non-blocking).
     *
     * Dipakai pcntl_fork + pcntl_exec (tanpa shell, sesuai konvensi anti
     * command injection). Alasan: proc_open + proc_close BLOCKING sampai worker
     * selesai — request HTTP yang memanggilnya menggantung selama build (bisa
     * menit), sehingga timeout/refresh browser tampak "menggagalkan" deploy.
     * Dengan fork + exec + SIGCHLD:
     *   - request langsung kembali (hanya fork, tidak menunggu build);
     *   - worker berjalan detached (posix_setsid) dan tetap lanjut meski HTTP
     *     worker di-restart atau browser ditutup;
     *   - di HTTP worker SIGCHLD di-ignore (kernel otomatis reap anak => tanpa
     *     zombie); di worker anak SIGCHLD di-reset ke SIG_DFL sebelum exec agar
     *     proc_get_status/proc_close di dalam worker tetap membaca exit code
     *     proses anaknya (git/docker) dengan benar.
     */
    private function spawnWorker(string $appId, string $mode, ?string $arg = null): bool
    {
        $logDir = runtime_path('logs/deploy');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/' . $appId . '.log';

        $command = [PHP_BINARY, base_path('cli/deploy.php'), $appId, $mode];
        if ($arg !== null && $arg !== '') {
            $command[] = $arg;
        }

        // Otomatis-reap anak saat selesai supaya tidak menumpuk zombie.
        @pcntl_signal(SIGCHLD, SIG_IGN);

        $pid = @pcntl_fork();
        if ($pid === -1) {
            return false;
        }

        if ($pid === 0) {
            // Proses anak: lepas dari sesi/terminal, ganti stdio ke /dev/null,
            // lalu ganti image proses menjadi worker (cli/deploy.php). Logging
            // ditangani worker sendiri via file_put_contents ke logFile.
            @posix_setsid();
            @fclose(STDIN);
            @fclose(STDOUT);
            @fclose(STDERR);
            // Buka ulang fd 0/1/2 ke /dev/null (fd reuse — tidak ada posix_dup2).
            // Variabel sengaja dipertahankan sampai pcntl_exec agar fd tetap terbuka.
            $nullIn = @fopen('/dev/null', 'r');
            $nullOut = @fopen('/dev/null', 'w');
            $nullErr = @fopen('/dev/null', 'w');
            // RESET SIGCHLD ke default sebelum exec. Tanpa ini worker mewarisi
            // SIGCHLD=SIG_IGN dari HTTP worker → kernel auto-reap proses anak
            // (git/docker) saat keluar, sehingga proc_get_status/proc_close di
            // ProcessRunner kehilangan exit code (selalu -1) dan perintah yang
            // sukses (mis. `git checkout main` → "Already on 'main'") dianggap
            // gagal. Disposisi SIG_DFL bertahan melewati exec.
            @pcntl_signal(SIGCHLD, SIG_DFL);
            pcntl_exec(PHP_BINARY, array_slice($command, 1));
            // Hanya tercapai bila exec gagal.
            exit(127);
        }

        return true;
    }

    /**
     * SHA versi yang sedang aktif = entri sukses/restored terakhir di history.
     * Dipakai untuk menampilkan "versi aktif" dan mencegah rollback ke versi aktif.
     */
    private function resolveActiveSha(array $app): string
    {
        foreach (array_reverse($app['deploy_history'] ?? []) as $h) {
            if (in_array(($h['status'] ?? ''), ['success', 'restored'], true)) {
                return (string) ($h['sha'] ?? '');
            }
        }
        return '';
    }

    /**
     * Hapus direktori beserta isinya (hanya dipakai untuk area apps_path).
     */
    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
