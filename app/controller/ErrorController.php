<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Errors\ErrorQuery;
use app\library\Errors\ErrorRedactor;
use app\library\Errors\ErrorReporter;
use app\library\Errors\ErrorStore;
use app\library\Storage\AppStore;
use support\Request;
use Throwable;
use Webman\Http\Response;

/**
 * Mediator halaman Error Log ("Rekam Error") + endpoint laporan klien.
 *
 * Controller ini **tanpa logika bisnis**: query/filter/pagination ada di
 * {@see ErrorQuery}/{@see ErrorStore}, validasi payload di
 * {@see \app\library\Errors\ErrorInput}, redaksi di
 * {@see ErrorRedactor}, dan pencatatan di {@see ErrorReporter}. Di sini hanya:
 *  - penjagaan hak akses (**non-admin ⇒ 404**, bukan 403);
 *  - pemetaan input GET/POST → library;
 *  - pemetaan hasil → `view()`/`json()`/`redirect()` + flash.
 *
 * Tanpa state lintas-request: dependensi opsional di konstruktor adalah seam
 * pengujian (pola `ServerController`). `currentUser()`/`flash()`/`renderView()`
 * `protected` supaya tes bisa memanggil controller tanpa sesi HTTP.
 */
class ErrorController
{
    private const ID_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    private readonly ?ErrorQuery $queries;
    private readonly ?ErrorReporter $reporter;
    private readonly ?AppStore $apps;

    public function __construct(
        ?ErrorQuery $queries = null,
        ?ErrorReporter $reporter = null,
        ?AppStore $apps = null,
    ) {
        $this->queries = $queries;
        $this->reporter = $reporter;
        $this->apps = $apps;
    }

    // ==================================================================
    // Halaman
    // ==================================================================

    /** `GET /errors` — daftar grup error (admin-only). */
    public function index(Request $request): Response
    {
        $denied = $this->guardPage();
        if ($denied !== null) {
            return $denied;
        }

        $get = $this->queryInput($request);
        $filters = ErrorStore::normalizeFilters($get);
        $page = $this->pageOf($get);

        $data = $this->query()->index($filters, $page, $this->perPage());

        return $this->renderView('errors/index', [
            'groups' => $data['groups'],
            'filters' => $filters,
            'counts' => $data['counts'],
            'total' => $data['total'],
            'page' => $page,
            'pages' => $data['pages'],
            'apps' => $this->appOptions(),
            'active' => 'errors',
        ]);
    }

    /** `GET /errors/{id}` — detail satu grup + sampel event (admin-only). */
    public function show(Request $request, string $id): Response
    {
        $denied = $this->guardPage();
        if ($denied !== null) {
            return $denied;
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return $this->notFoundPage();
        }

        $detail = $this->query()->detail($id);
        if ($detail === null) {
            return $this->notFoundPage();
        }

        return $this->renderView('errors/detail', [
            'group' => $detail['group'],
            'events' => $detail['events'],
            'active' => 'errors',
        ]);
    }

    // ==================================================================
    // Aksi
    // ==================================================================

    /** `POST /errors/{id}/status` — ubah status grup (admin-only). */
    public function status(Request $request, string $id): Response
    {
        $denied = $this->guardPage();
        if ($denied !== null) {
            return $denied;
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return $this->notFoundPage();
        }

        $status = trim((string) $request->post('status', ''));
        if (!in_array($status, ErrorStore::STATUSES, true)) {
            $this->flash('error', 'Status error tidak valid.');

            return redirect('/errors/' . rawurlencode($id));
        }

        $user = $this->currentUser();
        $actor = is_array($user) && (string) ($user['username'] ?? '') !== ''
            ? (string) $user['username']
            : (is_array($user) ? (string) ($user['id'] ?? '') : null);

        if (!$this->query()->setStatus($id, $status, $actor)) {
            $this->flash('error', 'Error tidak ditemukan.');

            return redirect('/errors/' . rawurlencode($id));
        }

        $this->flash('success', 'Status error diperbarui menjadi "' . $status . '".');

        return redirect('/errors/' . rawurlencode($id));
    }

    /** `POST /errors/{id}/delete` — hapus grup + sampelnya (admin-only). */
    public function delete(Request $request, string $id): Response
    {
        $denied = $this->guardPage();
        if ($denied !== null) {
            return $denied;
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return $this->notFoundPage();
        }

        if (!$this->query()->deleteGroup($id)) {
            $this->flash('error', 'Error tidak ditemukan.');

            return redirect('/errors');
        }

        $this->flash('success', 'Grup error dihapus.');

        return redirect('/errors');
    }

    // ==================================================================
    // API
    // ==================================================================

    /** `GET /api/errors` — daftar grup dalam JSON (admin-only). */
    public function apiIndex(Request $request): Response
    {
        $user = $this->currentUser();
        if (!is_array($user) || !self::isAdmin($user)) {
            return json(['code' => 1, 'error' => 'Tidak ditemukan.'])->withStatus(404);
        }

        $get = $this->queryInput($request);
        $filters = ErrorStore::normalizeFilters($get);
        $page = $this->pageOf($get);

        $data = $this->query()->index($filters, $page, $this->perPage());

        return json([
            'code' => 0,
            'data' => [
                'groups' => $data['groups'],
                'total' => $data['total'],
                'page' => $page,
                'pages' => $data['pages'],
                'counts' => $data['counts'],
            ],
        ]);
    }

    /**
     * `POST /api/errors` — laporan error dari browser (user login).
     *
     * CSRF tetap wajib (tidak ada pengecualian di `CsrfMiddleware`); token
     * dikirim reporter JS sebagai `_token` di body.
     */
    public function report(Request $request): Response
    {
        $user = $this->currentUser();
        if (!is_array($user)) {
            return json(['code' => 401, 'msg' => 'Unauthorized'])->withStatus(401);
        }

        $post = $request->post();
        if (!is_array($post)) {
            $post = [];
        }

        try {
            $result = $this->reporter()->reportClient(
                $post,
                (string) $request->header('user-agent', ''),
                $user,
                ErrorRedactor::routePath($request->path())
            );
        } catch (Throwable) {
            // Kegagalan penyimpanan (mis. DB sibuk) BUKAN kesalahan payload.
            // Jangan bocorkan detail internal; klien tidak perlu retry logika.
            return json(['code' => 500, 'msg' => 'Gagal menyimpan laporan error.'])->withStatus(500);
        }

        if (($result['accepted'] ?? false) === true) {
            return json(['code' => 0]);
        }

        return match ((string) ($result['reason'] ?? '')) {
            'rate_limited' => json(['code' => 429, 'msg' => 'Terlalu banyak laporan error.'])->withStatus(429),
            'unauthenticated' => json(['code' => 401, 'msg' => 'Unauthorized'])->withStatus(401),
            'invalid' => json(['code' => 422, 'msg' => 'Payload error tidak valid.'])->withStatus(422),
            default => json(['code' => 422, 'msg' => 'Payload error tidak valid.'])->withStatus(422),
        };
    }

    // ==================================================================
    // Seam (protected agar tes dapat mengganti tanpa sesi HTTP)
    // ==================================================================

    protected function currentUser(): ?array
    {
        $user = current_user();

        return is_array($user) ? $user : null;
    }

    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
    }

    /**
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): Response
    {
        return view($template, $vars);
    }

    protected function query(): ErrorQuery
    {
        return $this->queries ?? new ErrorQuery();
    }

    protected function reporter(): ErrorReporter
    {
        return $this->reporter ?? new ErrorReporter();
    }

    protected function appStore(): AppStore
    {
        return $this->apps ?? new AppStore();
    }

    // ==================================================================
    // Internal
    // ==================================================================

    /** Non-admin ⇒ 404 (halaman). */
    private function guardPage(): ?Response
    {
        $user = $this->currentUser();
        if (is_array($user) && self::isAdmin($user)) {
            return null;
        }

        return $this->notFoundPage();
    }

    private function notFoundPage(): Response
    {
        return response('', 404)->withStatus(404);
    }

    private static function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /**
     * Query string sebagai array; nilai non-skalar tetap dibiarkan (normalizer
     * library memperlakukannya sebagai kosong).
     *
     * @return array<string,mixed>
     */
    private function queryInput(Request $request): array
    {
        $get = $request->get();

        return is_array($get) ? $get : [];
    }

    /** @param array<string,mixed> $get */
    private function pageOf(array $get): int
    {
        $page = $get['page'] ?? 1;
        if (!is_scalar($page) || preg_match('/\A\d{1,6}\z/', trim((string) $page)) !== 1) {
            return 1;
        }

        return max(1, (int) $page);
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) config('errors.per_page', 50)));
    }

    /**
     * Opsi filter app `{id,name}` urut nama (tanpa N+1; satu `all()`).
     *
     * @return list<array{id:string,name:string}>
     */
    private function appOptions(): array
    {
        $options = [];
        try {
            foreach ($this->appStore()->all() as $app) {
                if (!is_array($app)) {
                    continue;
                }
                $id = (string) ($app['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $options[] = ['id' => $id, 'name' => (string) ($app['name'] ?? '')];
            }
        } catch (Throwable) {
            return [];
        }

        usort($options, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']);
        });

        return $options;
    }
}
