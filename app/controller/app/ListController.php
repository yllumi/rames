<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use app\library\Deploy\ComposeImporter;
use app\library\Docker\ComposeDiscovery;
use app\library\Docker\DockerClient;
use app\library\Storage\AppFilter;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use support\Request;

/**
 * Daftar app + impor project compose yang sudah berjalan (docs/SPECS.md §7/§8e).
 *
 * Route: `GET /apps`, `GET /apps/import`, `POST /apps/import`.
 * Penyaringan sisi server tetap `AppAccess::visible()`; controller mediator.
 */
class ListController extends AppControllerBase
{
    public function index(Request $request)
    {
        $store = new AppStore();
        $user = current_user();
        $userId = (string) ($user['id'] ?? '');
        $isAdmin = is_admin($user);

        // Hanya app milik sendiri / yang dibagikan ke user ini. Admin melihat
        // semua app (dengan label owner).
        $visible = AppAccess::visible($store->all(), $user);

        // Multi-server: peta `id server → nama` untuk kolom **Server** dan
        // pilihan filter server. Peta dibaca **sekali** (bukan per baris) dari
        // registry server. Non-admin hanya mengenal server yang menaungi app yang
        // boleh ia lihat, ditambah server yang boleh ia pilih bila admin memberi
        // grant `server_select` (`ServerAccess::visibleServers()` — satu pintu).
        // Daftar server lain tidak pernah dikirim ke UI, jadi menyembunyikan
        // pemilih bukan satu-satunya pengaman.
        $serverNames = $this->serverNameMapFor($visible);
        $serverOptions = $this->serverFilterOptions($user, $store->all());

        // Filter `?server=<id>` (query string aman — id server, bukan rahasia).
        // Hanya berlaku untuk server yang boleh dilihat user; id asing diabaikan
        // (bukan oracle keberadaan server).
        $serverFilter = trim((string) $request->get('server', ''));
        $allowedServers = array_fill_keys(array_keys($serverNames), true);
        foreach ($serverOptions as $option) {
            $allowedServers[(string) ($option['id'] ?? '')] = true;
        }
        if ($serverFilter === '' || !isset($allowedServers[$serverFilter])) {
            $serverFilter = '';
        } else {
            $visible = array_values(array_filter(
                $visible,
                static fn (array $a): bool => AppStore::serverIdOf($a) === $serverFilter
            ));
        }

        // Filter `?name=` / `?domain=` / `?owner=` (query string, docs/SPECS.md §8e).
        // Nilai mentah dinormalisasi lebih dulu lewat satu pintu `AppFilter`
        // (trim + lowercase, non-skalar ⇒ kosong) supaya nilai efektif yang
        // dikirim ke view identik dengan yang dipakai penyaringan.
        $filters = AppFilter::normalize([
            'name' => $request->get('name', ''),
            'domain' => $request->get('domain', ''),
            'owner' => $request->get('owner', ''),
        ]);

        // `?owner=` hanya dihormati untuk **admin**: bagi non-admin parameter itu
        // diabaikan total (tidak menyaring, tidak membocorkan app user lain —
        // penyaringan sisi server tetap `AppAccess::visible()`).
        // Id owner di luar peta `user_names()` juga diabaikan (pola sama dengan
        // `?server=<id asing>`): bukan oracle keberadaan user dan tidak
        // menghasilkan halaman kosong.
        $ownerNames = $isAdmin ? user_names() : [];
        if (!$isAdmin || ($filters['owner'] !== '' && !isset($ownerNames[$filters['owner']]))) {
            $filters['owner'] = '';
        }

        // Filter menyempitkan baris **dan** tab count (`counts`), konsisten
        // dengan filter server yang sudah ada.
        if (AppFilter::isActive($filters)) {
            $visible = AppFilter::apply($visible, $filters);
        }

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

        // `apps.json.subdomain` menyimpan label (boleh kosong); view memakai
        // `$app['subdomain']` sebagai FQDN → kirim FQDN efektif. Asal server
        // (nama + kind, bukan id mentah) juga dikirim agar view tidak menebak.
        foreach ($apps as &$app) {
            $app['subdomain'] = app_subdomain_of($app);
            $serverId = AppStore::serverIdOf($app);
            $app['server_name'] = $serverNames[$serverId] ?? $serverId;
            $app['server_kind'] = $serverId === ServerStore::LOCAL_ID ? 'local' : 'agent';
        }
        unset($app);

        return $this->renderView('app/index', [
            'apps' => $apps,
            'roles' => $roles,
            'ownedIds' => $owned,
            'scope' => $scope,
            'counts' => ['mine' => count($mine), 'shared' => count($shared), 'all' => count($visible)],
            'isAdmin' => $isAdmin,
            'ownerNames' => $ownerNames,
            'ownerOptions' => $ownerNames,
            'serverNames' => $serverNames,
            'serverOptions' => $serverOptions,
            'serverFilter' => $serverFilter,
            'filters' => $filters,
            'filterActive' => AppFilter::isActive($filters),
            // Tombol "Impor project compose" hanya untuk admin global (daftar
            // project terdeteksi juga admin-only). Halaman daftar TIDAK memanggil
            // Engine — discovery baru dijalankan di halaman impor.
            'canImport' => $isAdmin,
        ]);
    }

    /**
     * Form impor project compose terdeteksi (GET /apps/import).
     *
     * Admin-only (kebijakan "container eksternal hanya admin"): non-admin
     * mendapat **404** lewat {@see AppAccessDenied}, bukan 403.
     *
     * Kegagalan Engine tidak menggagalkan halaman: pesannya dikirim sebagai
     * `engineError` dan halaman tetap 200.
     */
    public function importForm(Request $request)
    {
        if (!is_admin(current_user())) {
            throw new AppAccessDenied();
        }

        $projects = [];
        $primaryChoices = [];
        $engineError = null;

        try {
            $discovery = new ComposeDiscovery(
                new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'))
            );
            $projects = $discovery->projects((new AppStore())->all());
            foreach ($projects as $project) {
                $primaryChoices[(string) $project['project']] = $discovery->primaryChoices($project);
            }
        } catch (\Throwable $e) {
            $engineError = $e->getMessage();
        }

        return $this->renderView('app/import', [
            'projects' => $projects,
            'primaryChoices' => $primaryChoices,
            'isAdmin' => true,
            'engineError' => $engineError,
        ]);
    }

    /**
     * Proses impor project compose (POST /apps/import).
     *
     * Admin-only (404). Seluruh keputusan (discovery ulang, validasi primary,
     * keunikan nama, penyusunan record) ada di {@see ComposeImporter} —
     * controller hanya mediator. Tidak ada deploy/worker yang dijalankan.
     */
    public function importStore(Request $request)
    {
        $user = current_user();
        if (!is_admin($user)) {
            throw new AppAccessDenied();
        }

        $project = trim((string) $request->post('project', ''));
        $primary = trim((string) $request->post('primary', ''));

        try {
            $importer = new ComposeImporter(
                new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock')),
                new AppStore()
            );
            $app = $importer->import($project, $primary, (string) ($user['id'] ?? ''));

            $message = 'Project "' . $app['name'] . '" diimpor. Rames hanya memantau — deploy/rebuild tetap di terminal proyek itu.';
            if ($request->expectsJson()) {
                return json(['code' => 0, 'id' => $app['id'], 'message' => $message]);
            }

            flash_set('success', $message);

            return redirect('/apps/' . $app['id']);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $e->getMessage()]);
            }

            flash_set('error', $e->getMessage());

            return redirect('/apps/import');
        }
    }

    /**
     * Peta `id server → nama` untuk **kolom Server** daftar app.
     *
     * Dipanggil dengan app yang sudah disaring `AppAccess::visible()` sehingga
     * untuk non-admin hanya memuat server yang menaungi app yang boleh ia lihat
     * (tidak ada daftar server lain). Registry dibaca **sekali** per request —
     * bukan satu pembacaan per baris app (hindari N+1).
     *
     * Registry tak terbaca ⇒ peta kosong; view memakai id server sebagai
     * fallback tampilan (tidak pernah menggagalkan halaman).
     *
     * @param array<int,array> $apps app yang boleh dilihat user
     * @return array<string,string>
     */
    private function serverNameMapFor(array $apps): array
    {
        $ids = [];
        foreach ($apps as $app) {
            if (is_array($app)) {
                $ids[AppStore::serverIdOf($app)] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $names = [];
        try {
            $servers = $this->serverResolver()->all();
        } catch (\Throwable $e) {
            return $names;
        }

        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            $id = (string) ($server['id'] ?? '');
            if ($id === '' || !isset($ids[$id])) {
                continue;
            }
            $name = trim((string) ($server['name'] ?? ''));
            $names[$id] = $name !== '' ? $name : $id;
        }

        return $names;
    }

    /**
     * Pilihan **filter server** untuk halaman `/apps` — satu pintu
     * `ServerAccess::visibleServers()`:
     *  - admin ⇒ semua server;
     *  - pemegang grant `server_select` ⇒ union server app yang terlihat +
     *    server yang boleh dipilih (allowlist);
     *  - member biasa ⇒ `[]` (perilaku lama: filter server tidak muncul).
     *
     * Hanya `id`/`name` yang keluar (tanpa token/PEM/base_url/rates).
     *
     * @param array<int,array> $apps semua app (sumber keputusan visibilitas)
     * @return array<int,array{id:string,name:string}>
     */
    private function serverFilterOptions(?array $user, array $apps): array
    {
        if (!ServerAccess::canSelectServer($user)) {
            return [];
        }

        try {
            $servers = ServerAccess::visibleServers($this->serverResolver()->all(), $user, $apps);
        } catch (\Throwable $e) {
            return [];
        }

        $options = [];
        foreach ($servers as $server) {
            if (!is_array($server)) {
                continue;
            }
            $id = (string) ($server['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($server['name'] ?? ''));
            $options[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }

        return $options;
    }
}
