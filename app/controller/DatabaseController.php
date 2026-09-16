<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Db\DbClient;
use app\library\Db\DbConnectionResolver;
use app\library\Db\DbContainerDetector;
use app\library\Db\DbCredentialResolver;
use app\library\Db\DbDump;
use app\library\Db\DbUserManager;
use app\library\Docker\DockerClient;
use app\library\Storage\AppStore;
use RuntimeException;
use support\Request;

/**
 * Database manager (phpMyAdmin mini) — mengelola MySQL/MariaDB di dalam container.
 *
 * Controller hanya mediator; seluruh logika di app/library/Db. Profile koneksi
 * (host/port/kredensial) disimpan di SESSION (bukan properti controller — Webman
 * persistent, lihat copilot-instructions). Kredensial terdeteksi otomatis dari
 * env app/container, fallback input manual per sesi.
 */
class DatabaseController
{
    /** Mode tampilan yang diizinkan (dari query string). */
    private const MODES = ['browse', 'structure', 'sql', 'users', 'export', 'import'];

    // ==================================================================
    // Daftar container DB (difilter per kepemilikan app)
    // ==================================================================

    public function index(Request $request)
    {
        $user = current_user();
        $isAdmin = is_admin($user);

        // Non-admin hanya melihat container DB milik app yang boleh diakses
        // (miliknya sendiri atau yang dibagikan kepadanya); container app user
        // lain & container eksternal disembunyikan. Admin melihat semua.
        $apps = AppAccess::visible((new AppStore())->all(), $user);

        $appById = [];
        foreach ($apps as $app) {
            $appById[(string) ($app['id'] ?? '')] = $app;
        }

        $engineError = null;
        $rows = [];
        try {
            $rows = (new DbContainerDetector())->detectAll($apps, $isAdmin);
        } catch (\Throwable $e) {
            $engineError = 'Tidak dapat mengakses Docker Engine: ' . $e->getMessage();
        }

        // Hak kelola per container: viewer hanya boleh melihat (ability 'database'
        // butuh operator ke atas); container eksternal (tanpa app) hanya admin.
        foreach ($rows as &$row) {
            $owner = $appById[(string) ($row['app_id'] ?? '')] ?? null;
            $row['can_manage'] = $owner !== null
                ? AppAccess::can('database', $owner, $user)
                : $isAdmin;
        }
        unset($row);

        // Peta appId → username owner (kolom audit untuk admin).
        $ownerNamesByApp = [];
        if ($isAdmin) {
            $names = user_names();
            foreach ($apps as $app) {
                $ownerNamesByApp[(string) ($app['id'] ?? '')] = $names[(string) ($app['owner_id'] ?? '')] ?? '';
            }
        }

        return view('db/index', [
            'rows' => $rows,
            'engineError' => $engineError,
            'isAdmin' => $isAdmin,
            'ownerNamesByApp' => $ownerNamesByApp,
        ]);
    }

    // ==================================================================
    // Halaman manager (connect / kelola)
    // ==================================================================

    public function manage(Request $request, string $container)
    {
        if (!$this->validContainerName($container)) {
            flash_set('error', 'Nama container tidak valid.');
            return redirect('/database');
        }

        // Otorisasi lebih dulu (app pemilik container) sebelum menyentuh Engine.
        $app = $this->findOwningApp($container);

        try {
            $inspect = $this->inspectDbContainer($container);
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());
            return redirect('/database');
        }

        $session = $request->session();
        $profile = $session->get('db_profile');
        $connected = is_array($profile) && ($profile['container_name'] ?? '') === $container;

        // Kredensial terdeteksi otomatis (untuk prefill form connect).
        $detected = null;
        try {
            $detected = (new DbCredentialResolver())->resolve($app ?? [], $inspect);
        } catch (\Throwable $e) {
            // abaikan — form tetap tampil manual
        }

        if (!$connected) {
            return view('db/manage', [
                'connected' => false,
                'container' => $container,
                'app' => $app,
                'hasDetected' => $detected !== null,
                'detectedUser' => $detected['username'] ?? null,
                'detectedDatabase' => $detected['database'] ?? null,
                'state' => (string) ($inspect['State']['Status'] ?? 'unknown'),
                'image' => (string) ($inspect['Config']['Image'] ?? ''),
            ]);
        }

        $data = $this->managerData($request, $container, $profile, $app);
        $data['connected'] = true;
        $data['container'] = $container;
        $data['app'] = $app;
        $data['profileUser'] = (string) ($profile['username'] ?? '');
        $data['profileDb'] = $profile['database'] ?? null;
        $data['state'] = (string) ($inspect['State']['Status'] ?? 'unknown');
        $data['image'] = (string) ($inspect['Config']['Image'] ?? '');
        $data['lastResult'] = $session->pull('db_last_result');

        return view('db/manage', $data);
    }

    /**
     * POST — buat koneksi & simpan profile di session.
     */
    public function connect(Request $request, string $container)
    {
        if (!$this->validContainerName($container)) {
            flash_set('error', 'Nama container tidak valid.');
            return redirect('/database');
        }

        $app = $this->findOwningApp($container);
        try {
            $inspect = $this->inspectDbContainer($container);
            $resolver = new DbConnectionResolver();
            $hostPort = $resolver->resolveHostPort($inspect);
            $host = (string) $hostPort['host'];
            $port = (int) $hostPort['port'];
            $internalPort = $resolver->internalPort($inspect);

            $detected = (new DbCredentialResolver())->resolve($app ?? [], $inspect);
            $username = trim((string) $request->post('username', ''));
            $password = (string) $request->post('password', '');
            $database = trim((string) $request->post('database', ''));

            // Fallback otomatis bila user tidak mengisi username (atau username sama
            // dengan hasil deteksi dan password dikosongkan).
            if ($detected !== null && ($username === '' || ($username === $detected['username'] && $password === ''))) {
                $username = (string) $detected['username'];
                $password = (string) $detected['password'];
                if ($database === '') {
                    $database = (string) ($detected['database'] ?? '');
                }
            }
            if ($username === '') {
                throw new RuntimeException('Kredensial tidak terdeteksi otomatis. Isi username & password manual.');
            }

            $profile = [
                'container_name' => $container,
                'container_id' => (string) ($inspect['Id'] ?? ''),
                'app_id' => $app !== null ? (string) ($app['id'] ?? '') : null,
                'app_name' => $app !== null ? (string) ($app['name'] ?? '') : null,
                'host' => $host,
                'port' => $port,
                'internal_port' => $internalPort,
                'username' => $username,
                'password' => $password,
                'database' => $database !== '' ? $database : null,
                'detected' => $detected !== null,
            ];

            // Uji koneksi nyata sebelum disimpan.
            $pdo = (new DbClient())->connect($profile);
            $pdo->query('SELECT 1');
        } catch (RuntimeException $e) {
            flash_set('error', 'Koneksi gagal: ' . $e->getMessage());
            return redirect('/database/' . rawurlencode($container));
        } catch (\Throwable $e) {
            flash_set('error', 'Koneksi gagal: ' . $e->getMessage());
            return redirect('/database/' . rawurlencode($container));
        }

        $request->session()->set('db_profile', $profile);
        $this->audit($app, $container, 'connect (user ' . $username . ')');
        flash_set('success', 'Terhubung ke ' . $container . ' sebagai ' . $username . '.');
        return redirect('/database/' . rawurlencode($container));
    }

    /**
     * POST — putuskan koneksi (hapus profile dari session).
     */
    public function disconnect(Request $request, string $container)
    {
        $app = $this->findOwningApp($container);
        $session = $request->session();
        $profile = $session->get('db_profile');
        if (is_array($profile) && ($profile['container_name'] ?? '') === $container) {
            $session->delete('db_profile');
            $this->audit($app, $container, 'disconnect');
        }
        flash_set('info', 'Koneksi database ditutup.');
        return redirect('/database/' . rawurlencode($container));
    }

    // ==================================================================
    // SQL editor
    // ==================================================================

    public function query(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $sql = (string) $request->post('sql', '');
        $back = $this->backUrl($container, $request);

        // Database aktif: dari sidebar (field tersembunyi di form) atau fallback
        // ke database awal hasil deteksi kredensial.
        $db = trim((string) $request->post('db', ''));
        if ($db === '') {
            $db = trim((string) ($profile['database'] ?? ''));
        }

        try {
            $dbClient = new DbClient();
            $pdo = $dbClient->connect($profile);
            if ($db !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
                $dbClient->selectDatabase($pdo, $db);
            }
            $start = microtime(true);
            $result = $dbClient->execute($pdo, $sql);
            $result['elapsedMs'] = (int) round((microtime(true) - $start) * 1000);
            $request->session()->set('db_last_result', $result);
            $this->audit($app, $container, 'query: ' . mb_substr($sql, 0, 120));
            flash_set('success', $result['isSelect'] ? 'Query berhasil (' . $result['affected'] . ' baris).' : 'Statement berhasil (' . $result['affected'] . ' baris terpengaruh).');
        } catch (\Throwable $e) {
            flash_set('error', 'Query gagal: ' . $e->getMessage());
        }
        return redirect($back);
    }

    // ==================================================================
    // CRUD baris (insert / update / delete via UI)
    // ==================================================================

    public function rowInsert(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $db = (string) $request->post('db', '');
        $table = (string) $request->post('table', '');
        $back = $this->backUrl($container, $request);

        try {
            $pdo = (new DbClient())->connect($profile);
            $data = $this->postedData($request);
            $id = (new DbClient())->insert($pdo, $db, $table, $data);
            $this->audit($app, $container, "insert {$db}.{$table}" . ($id > 0 ? " (id {$id})" : ''));
            flash_set('success', 'Baris ditambahkan' . ($id > 0 ? ' (id ' . $id . ')' : '') . '.');
        } catch (\Throwable $e) {
            flash_set('error', 'Insert gagal: ' . $e->getMessage());
        }
        return redirect($back);
    }

    public function rowUpdate(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $db = (string) $request->post('db', '');
        $table = (string) $request->post('table', '');
        $pkCol = (string) $request->post('pk_col', '');
        $pkVal = (string) $request->post('pk_val', '');
        $back = $this->backUrl($container, $request);

        try {
            $pdo = (new DbClient())->connect($profile);
            $data = $this->postedData($request);
            $affected = (new DbClient())->update($pdo, $db, $table, $pkCol, $pkVal, $data);
            $this->audit($app, $container, "update {$db}.{$table} where {$pkCol}={$pkVal}");
            flash_set('success', $affected . ' baris diperbarui.');
        } catch (\Throwable $e) {
            flash_set('error', 'Update gagal: ' . $e->getMessage());
        }
        return redirect($back);
    }

    public function rowDelete(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $db = (string) $request->post('db', '');
        $table = (string) $request->post('table', '');
        $pkCol = (string) $request->post('pk_col', '');
        $pkVal = (string) $request->post('pk_val', '');
        $back = $this->backUrl($container, $request);

        try {
            $pdo = (new DbClient())->connect($profile);
            $affected = (new DbClient())->delete($pdo, $db, $table, $pkCol, $pkVal);
            $this->audit($app, $container, "delete {$db}.{$table} where {$pkCol}={$pkVal}");
            flash_set('success', $affected . ' baris dihapus.');
        } catch (\Throwable $e) {
            flash_set('error', 'Hapus gagal: ' . $e->getMessage());
        }
        return redirect($back);
    }

    // ==================================================================
    // Kelola user & hak akses
    // ==================================================================

    public function userCreate(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $back = $this->backUrl($container, $request);

        try {
            $pdo = (new DbClient())->connect($profile);
            (new DbUserManager())->createUser(
                $pdo,
                (string) $request->post('user', ''),
                (string) $request->post('host', '%'),
                (string) $request->post('password', '')
            );
            $this->audit($app, $container, 'create user ' . $request->post('user', ''));
            flash_set('success', 'User dibuat.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal membuat user: ' . $e->getMessage());
        }
        return redirect($back);
    }

    public function userDelete(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $back = $this->backUrl($container, $request);

        try {
            $pdo = (new DbClient())->connect($profile);
            (new DbUserManager())->dropUser(
                $pdo,
                (string) $request->post('user', ''),
                (string) $request->post('host', '%')
            );
            $this->audit($app, $container, 'drop user ' . $request->post('user', ''));
            flash_set('success', 'User dihapus.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal menghapus user: ' . $e->getMessage());
        }
        return redirect($back);
    }

    public function userGrant(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $back = $this->backUrl($container, $request);

        try {
            $pdo = (new DbClient())->connect($profile);
            (new DbUserManager())->grant(
                $pdo,
                (string) $request->post('user', ''),
                (string) $request->post('host', '%'),
                (string) $request->post('db', ''),
                array_values((array) $request->post('privileges', []))
            );
            $this->audit($app, $container, 'grant ' . $request->post('user', '') . ' on ' . $request->post('db', ''));
            flash_set('success', 'Privilege diberikan.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal grant privilege: ' . $e->getMessage());
        }
        return redirect($back);
    }

    public function userRevoke(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $back = $this->backUrl($container, $request);

        try {
            $pdo = (new DbClient())->connect($profile);
            (new DbUserManager())->revoke(
                $pdo,
                (string) $request->post('user', ''),
                (string) $request->post('host', '%'),
                (string) $request->post('db', ''),
                array_values((array) $request->post('privileges', []))
            );
            $this->audit($app, $container, 'revoke ' . $request->post('user', '') . ' on ' . $request->post('db', ''));
            flash_set('success', 'Privilege dicabut.');
        } catch (\Throwable $e) {
            flash_set('error', 'Gagal revoke privilege: ' . $e->getMessage());
        }
        return redirect($back);
    }

    // ==================================================================
    // Export / import
    // ==================================================================

    public function export(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $db = trim((string) $request->post('db', ''));
        if ($db === '') {
            $db = null; // semua database
        }

        $exportDir = runtime_path() . '/db-export';
        if (!is_dir($exportDir)) {
            @mkdir($exportDir, 0775, true);
        }
        $filename = ($db ?? 'all-databases') . '-' . date('Ymd-His') . '.sql';
        $path = $exportDir . '/' . $filename;

        try {
            (new DbDump())->export($container, $profile, $db, $path);
            $this->audit($app, $container, 'export ' . ($db ?? 'all-databases'));
            return response()->download($path, $filename);
        } catch (\Throwable $e) {
            flash_set('error', 'Export gagal: ' . $e->getMessage());
            return redirect($this->backUrl($container, $request));
        }
    }

    public function import(Request $request, string $container)
    {
        $profile = $this->sessionProfile($request, $container);
        if ($profile === null) {
            return $this->notConnected($container);
        }
        $app = $this->findOwningApp($container);
        $db = trim((string) $request->post('db', ''));
        $back = $this->backUrl($container, $request);

        $file = $request->file('sql');
        if ($file === null || !is_object($file)) {
            flash_set('error', 'Pilih file .sql untuk di-import.');
            return redirect($back);
        }
        $sql = @file_get_contents((string) $file->getPathname());
        if ($sql === false || $sql === '') {
            flash_set('error', 'File SQL kosong atau tidak dapat dibaca.');
            return redirect($back);
        }

        try {
            $result = (new DbDump())->import($container, $profile, $db, $sql);
            if ($result['code'] !== 0) {
                flash_set('error', 'Import gagal (exit ' . $result['code'] . '): ' . trim((string) $result['stderr']));
            } else {
                $this->audit($app, $container, 'import ' . $db);
                flash_set('success', 'Import selesai.');
            }
        } catch (\Throwable $e) {
            flash_set('error', 'Import gagal: ' . $e->getMessage());
        }
        return redirect($back);
    }

    // ==================================================================
    // Helper internal
    // ==================================================================

    /**
     * Kumpulkan data untuk halaman manager (daftar db, tabel, kolom, baris, user).
     */
    private function managerData(Request $request, string $container, array $profile, ?array $app): array
    {
        $dbClient = new DbClient();
        $pdo = $dbClient->connect($profile);

        $dbName = (string) $request->get('db', '');
        $tableName = (string) $request->get('table', '');
        $mode = (string) $request->get('mode', 'browse');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'browse';
        }

        $databases = $dbClient->databases($pdo);
        if ($dbName === '') {
            // Default ke database awal hasil deteksi kredensial bila valid.
            $defaultDb = trim((string) ($profile['database'] ?? ''));
            if ($defaultDb !== '' && in_array($defaultDb, $databases, true)) {
                $dbName = $defaultDb;
            }
        }
        if ($dbName !== '' && !in_array($dbName, $databases, true)) {
            $dbName = '';
        }
        $tables = [];
        if ($dbName !== '') {
            $tables = $dbClient->tables($pdo, $dbName);
        }

        $columns = [];
        $indexes = [];
        $pk = null;
        $rows = [];
        $total = 0;
        $page = max(1, (int) $request->get('page', 1));
        $perPage = (int) config('deploy.db_browse_per_page', 50);

        if ($dbName !== '' && $tableName !== '' && in_array($mode, ['browse', 'structure'], true)) {
            $columns = $dbClient->columns($pdo, $dbName, $tableName);
            $pk = $dbClient->primaryKey($columns);
            if ($mode === 'structure') {
                $indexes = $dbClient->indexes($pdo, $dbName, $tableName);
            } else {
                $total = $dbClient->countRows($pdo, $dbName, $tableName);
                $rows = $dbClient->rows($pdo, $dbName, $tableName, $page, $perPage);
            }
        }

        $users = [];
        $userError = null;
        if ($mode === 'users') {
            try {
                $users = (new DbUserManager())->users($pdo);
            } catch (\Throwable $e) {
                $userError = $e->getMessage();
            }
        }

        return [
            'databases' => $databases,
            'tables' => $tables,
            'db' => $dbName,
            'table' => $tableName,
            'mode' => $mode,
            'columns' => $columns,
            'indexes' => $indexes,
            'pk' => $pk,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'users' => $users,
            'userError' => $userError,
            'privileges' => DbUserManager::PRIVILEGES,
        ];
    }

    /**
     * Profile koneksi aktif untuk container, atau null bila belum terhubung.
     */
    private function sessionProfile(Request $request, string $container): ?array
    {
        $profile = $request->session()->get('db_profile');
        if (!is_array($profile) || ($profile['container_name'] ?? '') !== $container) {
            return null;
        }
        return $profile;
    }

    private function notConnected(string $container)
    {
        flash_set('error', 'Belum terhubung ke container ini. Buat koneksi dulu.');
        return redirect('/database/' . rawurlencode($container));
    }

    private function backUrl(string $container, Request $request): string
    {
        $db = (string) $request->post('db', (string) $request->get('db', ''));
        $table = (string) $request->post('table', (string) $request->get('table', ''));
        $mode = (string) $request->post('mode', (string) $request->get('mode', 'browse'));
        $query = http_build_query(array_filter([
            'db' => $db,
            'table' => $table,
            'mode' => $mode,
        ]));
        return '/database/' . rawurlencode($container) . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Ambil data kolom => nilai dari POST (field `cols[<nama>]`).
     *
     * @return array<string,string>
     */
    private function postedData(Request $request): array
    {
        $cols = (array) $request->post('cols', []);
        $data = [];
        foreach ($cols as $col => $value) {
            if ($col === '' || !preg_match('/^[a-zA-Z0-9_]+$/', (string) $col)) {
                continue;
            }
            $data[(string) $col] = (string) $value;
        }
        return $data;
    }

    private function inspectDbContainer(string $container): array
    {
        $docker = new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'));
        $inspect = $docker->inspectContainer($container);
        if (!(new DbContainerDetector($docker))->isDbContainer($inspect)) {
            throw new RuntimeException('Container "' . $container . '" bukan server MySQL/MariaDB.');
        }
        return $inspect;
    }

    /**
     * Cari app pemilik container DB + pastikan user berhak mengelolanya.
     *
     * Semua endpoint manager memakai method ini, sehingga otorisasi hanya ada
     * di satu tempat. Container DB yang tidak terdaftar di apps.json (mis.
     * dibuat manual di host) hanya boleh diakses admin.
     *
     * @throws AppAccessDenied dirender sebagai 404 oleh webman
     */
    private function findOwningApp(string $container): ?array
    {
        $owner = null;
        foreach ((new AppStore())->all() as $app) {
            foreach (($app['containers'] ?? []) as $c) {
                if (($c['container_name'] ?? '') === $container) {
                    $owner = $app;
                    break 2;
                }
            }
        }

        if ($owner === null) {
            if (!is_admin()) {
                throw new AppAccessDenied('database', null);
            }
            return null;
        }

        AppAccess::require('database', $owner, current_user());

        return $owner;
    }

    private function validContainerName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) === 1;
    }

    private function audit(?array $app, string $container, string $action): void
    {
        $dir = runtime_path() . '/logs/db';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $user = '?';
        try {
            if (function_exists('current_user')) {
                $cu = current_user();
                if (is_array($cu)) {
                    $user = (string) ($cu['username'] ?? '?');
                }
            }
        } catch (\Throwable $e) {
            $user = '?';
        }
        $line = sprintf(
            "[%s] %s | container=%s | app=%s | %s\n",
            date('c'),
            $user,
            $container,
            $app['name'] ?? 'external',
            $action
        );
        @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
