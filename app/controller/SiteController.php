<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Deploy\DeployerFactory;
use app\library\Docker\ComposeParser;
use app\library\Docker\PortManager;
use app\library\Git\GitService;
use app\library\Git\SshKeyManager;
use app\library\Nginx\NginxStatusReader;
use app\library\Storage\SiteStore;
use RuntimeException;
use support\Request;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Site Management (SPECS.md §7).
 *
 * Controller hanya mediator — seluruh logika bisnis di app/library.
 * Tidak ada state di properti controller (Webman persistent, lihat
 * copilot-instructions).
 */
class SiteController
{
    // ==================================================================
    // Daftar & detail
    // ==================================================================

    public function index(Request $request)
    {
        return view('site/index', ['sites' => (new SiteStore())->all()]);
    }

    public function detail(Request $request, string $id)
    {
        $store = new SiteStore();
        $site = $store->find($id);
        if ($site === null) {
            flash_set('error', 'Site tidak ditemukan.');
            return redirect('/sites');
        }

        // status container live (best effort — engine mungkin tidak tersedia)
        $live = [];
        try {
            $live = DeployerFactory::create()->getContainers($site['name']);
        } catch (\Throwable $e) {
            // pakai data tersimpan
        }

        $nginxStatus = (new NginxStatusReader((string) config('deploy.nginx_reload_status_file')))->lastReload();

        // Public key deploy key site (untuk repo private via SSH)
        $sshPubkey = null;
        if (($site['auth_method'] ?? 'none') === 'ssh') {
            $sshPubkey = (new SshKeyManager())->publicKey($site['name']);
        }

        return view('site/detail', [
            'site' => $site,
            'live' => $live,
            'nginxStatus' => $nginxStatus,
            'sshPubkey' => $sshPubkey,
        ]);
    }

    // ==================================================================
    // Wizard create — langkah 1 (form + clone + parse + saran port)
    // ==================================================================

    public function createForm(Request $request)
    {
        return view('site/create');
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

            $store = new SiteStore();
            if ($store->nameExists($name)) {
                throw new RuntimeException("Nama site \"{$name}\" sudah dipakai.");
            }

            $sitesPath = (string) config('deploy.sites_path');
            $dest = $sitesPath . '/' . $name;
            if (is_dir($dest)) {
                // area sites_path dikelola sistem — bersihkan lalu clone ulang
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
            $services = $parsed['services'];

            // deteksi konflik & isi/saran host port
            $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
            $portManager = new PortManager((int) $range['start'], (int) $range['end']);
            $usedPorts = $portManager->usedHostPorts($store->all());
            $services = $portManager->resolve($services, $usedPorts);

            // simpan data pending (belum commit) di session
            $primary = $this->defaultPrimary($services);
            $request->session()->set('pending_site', [
                'name' => $name,
                'repo_url' => $repoUrl,
                'branch' => $branch,
                'local_path' => 'sites/' . $name,
                'compose_file' => $composeFile,
                'services' => $services,
                'primary_service' => $primary,
                'auth_method' => $authMethod,
                'ssh_key' => $authMethod === 'ssh' ? 'keys/' . $name : null,
            ]);

            return redirect('/sites/create/confirm');
        } catch (\Throwable $e) {
            // Repo private via SSH: kalau clone gagal (deploy key belum ditambahkan),
            // tampilkan public key di form agar user bisa menambahkannya ke repo
            // lalu mencoba Analisis Repo lagi (kunci dipakai ulang).
            if ($authMethod === 'ssh' && $keyManager->exists($name)) {
                return view('site/create', [
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
            return redirect('/sites/create');
        }
    }

    // ==================================================================
    // Wizard create — langkah 2 (konfirmasi port & primary service)
    // ==================================================================

    public function confirmForm(Request $request)
    {
        $pending = $request->session()->get('pending_site');
        if (!$pending) {
            return redirect('/sites/create');
        }
        return view('site/confirm', ['pending' => $pending]);
    }

    public function confirmCreate(Request $request)
    {
        $pending = $request->session()->get('pending_site');
        if (!$pending) {
            return redirect('/sites/create');
        }

        $serviceInput = (array) $request->post('services', []);
        $primaryService = (string) $request->post('primary_service', '');

        try {
            // Fail-fast: pastikan direktori Nginx dapat ditulis sebelum deploy.
            // Kalau tidak, tampilkan pesan jelas (bukan gagal di tengah build).
            DeployerFactory::create()->ensureWritable();

            $services = $this->validateAndApplyPorts($pending['services'], $serviceInput);
            $primaryService = $this->resolvePrimaryService($services, $primaryService);

            $composeFiles = [$pending['compose_file']];
            $this->writeOverride($pending, $services, $composeFiles);

            $site = (new SiteStore())->create([
                'name' => $pending['name'],
                'subdomain' => site_subdomain($pending['name']),
                'repo_url' => $pending['repo_url'],
                'branch' => $pending['branch'],
                'local_path' => $pending['local_path'],
                'primary_service' => $primaryService,
                'status' => 'deploying',
                'stage' => 'queued',
                'message' => 'Menunggu worker deploy ...',
                'compose_files' => $composeFiles,
                'needs_ssl' => false,
                'ssl_status' => null,
                'auth_method' => $pending['auth_method'] ?? 'none',
                'ssh_key' => $pending['ssh_key'] ?? null,
                'containers' => [],
            ]);

            $request->session()->delete('pending_site');

            if (!$this->spawnWorker($site['id'], 'deploy')) {
                (new SiteStore())->update($site['id'], function (array &$s): void {
                    $s['status'] = 'error';
                    $s['stage'] = null;
                    $s['message'] = 'Gagal menjalankan worker deploy. Cek log & coba Rebuild.';
                    $s['error'] = 'Gagal spawn worker deploy.';
                });
            }

            flash_set('success', 'Site "' . $site['name'] . '" sedang di-deploy.');
            return redirect('/sites/' . $site['id']);
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
            return redirect('/sites/create/confirm');
        }
    }

    // ==================================================================
    // Polling status (API)
    // ==================================================================

    public function status(Request $request, string $id)
    {
        $site = (new SiteStore())->find($id);
        if ($site === null) {
            return json(['code' => 404, 'msg' => 'Site tidak ditemukan.']);
        }
        return json([
            'code' => 0,
            'site' => [
                'id' => $site['id'],
                'name' => $site['name'],
                'status' => $site['status'] ?? 'unknown',
                'stage' => $site['stage'] ?? null,
                'message' => $site['message'] ?? '',
                'error' => $site['error'] ?? null,
            ],
        ]);
    }

    // ==================================================================
    // Aksi: rebuild / stop / start / delete
    // ==================================================================

    public function rebuild(Request $request, string $id)
    {
        $store = new SiteStore();
        $site = $store->find($id);
        if ($site === null) {
            flash_set('error', 'Site tidak ditemukan.');
            return redirect('/sites');
        }

        $dir = (string) config('deploy.sites_path') . '/' . $site['name'];
        if (!is_dir($dir)) {
            flash_set('error', 'Direktori site tidak ada. Site mungkin sudah dihapus.');
            return redirect('/sites/' . $id);
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
            flash_set('error', 'Gagal menjalankan rebuild.');
        } else {
            flash_set('success', 'Rebuild dijalankan.');
        }
        return redirect('/sites/' . $id);
    }

    public function stop(Request $request, string $id)
    {
        $store = new SiteStore();
        $site = $store->find($id);
        if ($site === null) {
            flash_set('error', 'Site tidak ditemukan.');
            return redirect('/sites');
        }
        try {
            DeployerFactory::create()->stop($site);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'stopped';
                $s['message'] = 'Stopped';
            });
            flash_set('success', 'Site dihentikan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal stop: ' . $e->getMessage());
        }
        return redirect('/sites/' . $id);
    }

    public function start(Request $request, string $id)
    {
        $store = new SiteStore();
        $site = $store->find($id);
        if ($site === null) {
            flash_set('error', 'Site tidak ditemukan.');
            return redirect('/sites');
        }
        try {
            DeployerFactory::create()->start($site);
            $store->update($id, function (array &$s): void {
                $s['status'] = 'running';
                $s['message'] = 'Running';
            });
            flash_set('success', 'Site dijalankan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal start: ' . $e->getMessage());
        }
        return redirect('/sites/' . $id);
    }

    public function delete(Request $request, string $id)
    {
        $store = new SiteStore();
        $site = $store->find($id);
        if ($site === null) {
            flash_set('error', 'Site tidak ditemukan.');
            return redirect('/sites');
        }
        try {
            DeployerFactory::create()->teardown($site);

            $dir = (string) config('deploy.sites_path') . '/' . $site['name'];
            if (is_dir($dir)) {
                $this->cleanupDir($dir);
            }

            // Bersihkan pasangan kunci SSH deploy key site
            if (($site['auth_method'] ?? 'none') === 'ssh') {
                (new SshKeyManager())->remove($site['name']);
            }

            $store->delete($id);
            flash_set('success', 'Site "' . $site['name'] . '" dihapus.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal menghapus: ' . $e->getMessage());
        }
        return redirect('/sites');
    }

    // ==================================================================
    // Helper privat
    // ==================================================================

    private function validateCreateInput(string $name, string $repoUrl, string $branch, string $authMethod = 'none'): void
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name)) {
            throw new RuntimeException('Nama site hanya boleh huruf kecil a-z, angka, dan strip (-).');
        }
        if (strlen($name) > 63) {
            throw new RuntimeException('Nama site maksimal 63 karakter.');
        }
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
     * Validasi & terapkan host port hasil edit user (langkah konfirmasi).
     *
     * @param array<string,array> $services
     * @param array               $input  bentuk services[svcName][host_port]
     * @return array<string,array>
     */
    private function validateAndApplyPorts(array $services, array $input): array
    {
        $range = config('deploy.port_range', ['start' => 30000, 'end' => 30999]);
        $portManager = new PortManager((int) $range['start'], (int) $range['end']);
        $usedPorts = $portManager->usedHostPorts((new SiteStore())->all());

        $localUsed = [];
        foreach ($services as $svcName => $svc) {
            // Service tanpa port exposed (mis. php-fpm yang hanya diakses via
            // jaringan internal compose) tidak punya host port — lewati.
            if (empty($svc['ports'])) {
                continue;
            }

            $posted = $input[$svcName]['host_port'] ?? null;
            $hostPort = $posted !== null && $posted !== '' ? (int) $posted : (int) ($svc['host_port'] ?? 0);

            if ($hostPort <= 0 || !$portManager->validatePort($hostPort)) {
                throw new RuntimeException("Port tidak valid untuk service \"{$svcName}\".");
            }
            if (in_array($hostPort, $usedPorts, true) || in_array($hostPort, $localUsed, true)) {
                throw new RuntimeException("Port {$hostPort} (service \"{$svcName}\") sudah terpakai. Pilih port lain.");
            }

            $localUsed[] = $hostPort;
            $usedPorts[] = $hostPort;

            $services[$svcName]['host_port'] = $hostPort;
            if (isset($services[$svcName]['ports'][0])) {
                $services[$svcName]['ports'][0]['host'] = $hostPort;
            }
        }

        return $services;
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
     * @param array                  $pending
     * @param array<string,array>    $services
     * @param array<int,string>      $composeFiles (by reference — ditambah nama override)
     */
    private function writeOverride(array $pending, array $services, array &$composeFiles): void
    {
        $dir = (string) config('deploy.sites_path') . '/' . $pending['name'];
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
        if (file_put_contents($dir . '/docker-compose.override.yml', $resetYaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis docker-compose.override.yml.');
        }
        $composeFiles[] = 'docker-compose.override.yml';

        $portsYaml = Yaml::dump($ports, 4, 2);
        if (file_put_contents($dir . '/docker-compose.override.ports.yml', $portsYaml, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis docker-compose.override.ports.yml.');
        }
        $composeFiles[] = 'docker-compose.override.ports.yml';
    }

    /**
     * Spawn background worker deploy (detached).
     */
    private function spawnWorker(string $siteId, string $mode): bool
    {
        $logDir = runtime_path('logs/deploy');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/' . $siteId . '.log';

        $command = [PHP_BINARY, base_path('cli/deploy.php'), $siteId, $mode];
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $proc = @proc_open($command, $descriptors, $pipes, base_path(), null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return false;
        }
        // proc_close langsung (tanpa pipe interaktif) => proses berjalan detached
        @proc_close($proc);
        return true;
    }

    /**
     * Hapus direktori beserta isinya (hanya dipakai untuk area sites_path).
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
