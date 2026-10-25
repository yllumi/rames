<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Domain\DomainFilter;
use app\library\Domain\ParkedDomainContent;
use app\library\Domain\ParkedDomainManager;
use app\library\Domain\ParkedDomainValidator;
use app\library\Nginx\NginxConfigGenerator;
use app\library\Nginx\NginxReloader;
use app\library\SSL\ParkedSslEnabler;
use app\library\SSL\SslDomainRows;
use app\library\SSL\SslErrorSummary;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use app\library\Storage\ParkedDomainStore;
use support\Request;
use Webman\Http\Response;
use Webman\Http\UploadFile;

/**
 * Halaman **domain parking** (`/domains`) — SPECS.md §8j, ARCHITECTURE.md §5.38.
 *
 * Domain parking adalah entitas **global, tidak terikat app**: daftar FQDN yang
 * dilayani halaman statis (`mode='html'`) atau dialihkan (`mode='redirect'`).
 * Fase 1 hanya server lokal.
 *
 * Controller ini **mediator tipis**:
 *  - seluruh validasi bisnis, penyimpanan metadata, penulisan berkas konten,
 *    penulisan config Nginx, reload, dan rollback ada di
 *    {@see ParkedDomainManager} + {@see ParkedDomainValidator};
 *  - di sini hanya penjagaan hak akses, pemetaan input form → manager, dan
 *    pemetaan hasil → respons (`view()`/`json()`/`redirect()`);
 *  - **tanpa state properti lintas-request** (worker Webman persistent): store,
 *    content, dan manager dibangun per pemanggilan lewat seam di bawah.
 *
 * Sejak halaman `/domains` menjadi tabel **"Semua Domain"** (app + parkir),
 * `index()`/`apiStatus()` terbuka untuk **semua user login**: baris app
 * disaring `AppAccess::visible()` dan tombol SSL mengikuti ability `ssl`
 * (didekorasi `can_ssl`). Pengelolaan domain parkir (CRUD + aktifkan SSL)
 * tetap **admin-only**.
 *
 * Invarian:
 *  - **non-admin ⇒ 404** (bukan 403) untuk aksi/sisi pengelolaan parkir
 *    (`createForm`, `create`, `editForm`, `update`, `delete`, `enableParked`,
 *    `apiEnableParked`), mengikuti pola `NginxController`/`ServerController` —
 *    keberadaan pengelolaan tidak bocor ke member;
 *  - cek otorisasi dilakukan **sebelum** membaca store atau memanggil manager;
 *  - kegagalan validasi dirender ulang sebagai form (HTTP 200) dengan input
 *    lama, bukan redirect, supaya isian user tidak hilang; pemanggil AJAX tetap
 *    menerima JSON `{code:1,error}` status 422;
 *  - pesan `error` dari manager selalu ditampilkan, termasuk saat `ok=true`
 *    (mis. catatan pembersihan konten lama yang gagal) — `rolled_back` saja
 *    tidak cukup untuk menyimpulkan sukses bersih.
 */
class DomainController
{
    /** @var (\Closure(string):bool)|null seam uji: (parkedId) => spawn berhasil? */
    private ?\Closure $spawn;

    public function __construct(?\Closure $spawn = null)
    {
        $this->spawn = $spawn;
    }

    // ==================================================================
    // Halaman
    // ==================================================================

    /**
     * `GET /domains` — tabel **"Semua Domain"** (app + domain parkir).
     *
     * Terbuka untuk semua user login: baris app disaring `AppAccess::visible()`
     * (server tetap sumber kebenaran, tombol hanya lapisan kedua). Baris domain
     * parkir **hanya** untuk admin (`$parked = []` untuk non-admin) karena
     * pengelolaannya admin-only. Hanya membaca store lokal — **tidak** menyentuh
     * Docker/agent.
     *
     * Payload view: `rows`, `domains`, `isAdmin`, `tab`, `active`,
     * `maxHtmlBytes`, `appDomain`, `errors`, `sslSupported`, `filter`,
     * `filterActive`.
     *
     * Filter `?domain=` ({@see DomainFilter}) diterapkan **setelah** otorisasi:
     * `rows` dan `domains` sama-sama disaring substring case-insensitive pada
     * `domain`, sehingga kedua tab konsisten dan filter tidak pernah menambah
     * baris yang tidak berhak dilihat. `sslSupported` sengaja dihitung dari
     * baris **tidak** tersaring — ketersediaan fitur SSL tidak boleh berubah
     * hanya karena filter menyembunyikan domain publik.
     */
    public function index(Request $request): Response
    {
        $table = $this->table();
        $filter = DomainFilter::normalize($request->get(DomainFilter::QUERY_KEY, ''));

        return $this->renderView('domain/index', [
            'rows' => DomainFilter::apply($table['rows'], $filter),
            'domains' => DomainFilter::apply($table['parked'], $filter),
            'isAdmin' => $table['isAdmin'],
            'tab' => $this->tab($request, $table['isAdmin']),
            'active' => 'domains',
            'maxHtmlBytes' => ParkedDomainValidator::MAX_HTML_BYTES,
            'appDomain' => (string) config('deploy.app_domain', ''),
            'errors' => [],
            'sslSupported' => $this->sslSupported($table['rows']),
            'filter' => $filter,
            'filterActive' => DomainFilter::isActive($filter),
        ]);
    }

    /**
     * `GET /ssl` — alias kompatibel: tabel SSL kini menyatu di `/domains`.
     */
    public function legacySsl(Request $request): Response
    {
        return redirect('/domains');
    }

    /**
     * `GET /domains/create` — form domain parkir baru.
     */
    public function createForm(Request $request): Response
    {
        $denied = $this->guard();
        if ($denied !== null) {
            return $denied;
        }

        return $this->renderView('domain/form', $this->formVars(null, [], false));
    }

    /**
     * `GET /domains/{id}/edit` — form edit domain parkir.
     *
     * Entri tidak ada ⇒ 404 (tanpa membedakan "tidak berhak" — halaman ini
     * admin-only, jadi 404 sudah menjadi jawaban baku untuk keduanya).
     */
    public function editForm(Request $request, string $id): Response
    {
        $denied = $this->guard();
        if ($denied !== null) {
            return $denied;
        }

        $entry = $this->store()->find($id);
        if ($entry === null) {
            return $this->notFound($request);
        }

        return $this->renderView('domain/form', $this->formVars($entry, [], true, $this->inputFromEntry($entry, $id)));
    }

    // ==================================================================
    // Aksi
    // ==================================================================

    /**
     * `POST /domains` — tambah domain parkir (1–2 entri bila `add_www`).
     *
     * Sukses ⇒ flash + redirect `/domains`. Gagal ⇒ JSON 422 (AJAX) atau form
     * dirender ulang dengan `errors` + input lama.
     */
    public function create(Request $request): Response
    {
        $denied = $this->guard();
        if ($denied !== null) {
            return $denied;
        }

        $input = $this->inputFrom($request, false);
        $uploadError = $this->applyHtmlUpload($request, $input);
        if ($uploadError !== null) {
            return $this->invalidForm($request, null, $input, false, $uploadError);
        }

        $user = $this->currentUser();
        $result = $this->manager()->create($input, (string) ($user['id'] ?? ''));

        if ((bool) ($result['ok'] ?? false)) {
            $error = (string) ($result['error'] ?? '');
            if ($error !== '') {
                // Operasi tersimpan tetapi ada catatan penting (mis. rollback
                // sebagian) — tampilkan apa adanya, jangan anggap sukses bersih.
                $this->flash('error', $error);

                return redirect('/domains');
            }

            $created = is_array($result['created'] ?? null) ? $result['created'] : [];
            $domain = (string) ($created[0]['domain'] ?? '');
            if ($domain === '' && is_string($input['domain'] ?? null)) {
                $domain = $input['domain'];
            }
            $message = sprintf('Domain parkir %s ditambahkan.', $domain);
            if (count($created) > 1) {
                $message .= ' Varian www juga ditambahkan: ' . (string) ($created[1]['domain'] ?? 'www.' . $domain);
            }
            $this->flash('success', $message);

            return redirect('/domains');
        }

        return $this->invalidForm(
            $request,
            null,
            $input,
            false,
            $this->resultError($result, 'Gagal menambahkan domain parkir.')
        );
    }

    /**
     * `POST /domains/{id}` — ubah satu entri parkir.
     *
     * `add_www` sengaja tidak diproses di sini: menambah/menghapus varian www
     * dilakukan lewat create/delete terpisah (kontrak manager).
     */
    public function update(Request $request, string $id): Response
    {
        $denied = $this->guard();
        if ($denied !== null) {
            return $denied;
        }

        $entry = $this->store()->find($id);
        if ($entry === null) {
            return $this->notFound($request);
        }

        $input = $this->inputFrom($request, true);
        $uploadError = $this->applyHtmlUpload($request, $input);
        if ($uploadError !== null) {
            return $this->invalidForm($request, $entry, $input, true, $uploadError);
        }

        $result = $this->manager()->update($id, $input);

        if ((bool) ($result['ok'] ?? false)) {
            $error = (string) ($result['error'] ?? '');
            if ($error !== '') {
                $this->flash('error', $error);

                return redirect('/domains');
            }

            $updated = is_array($result['domain'] ?? null) ? $result['domain'] : [];
            $domain = (string) ($updated['domain'] ?? '');
            if ($domain === '' && is_string($input['domain'] ?? null)) {
                $domain = $input['domain'];
            }
            $this->flash('success', sprintf('Domain parkir %s diperbarui.', $domain));

            return redirect('/domains');
        }

        return $this->invalidForm(
            $request,
            $entry,
            $input,
            true,
            $this->resultError($result, 'Gagal memperbarui domain parkir.')
        );
    }

    /**
     * `POST /domains/{id}/delete` — hapus satu entri parkir.
     *
     * Gagal ⇒ flash error + redirect `/domains`; pemanggil AJAX mendapat JSON
     * 422. Entri yang tidak ada ⇒ 404 sebelum efek samping apa pun.
     */
    public function delete(Request $request, string $id): Response
    {
        $denied = $this->guard();
        if ($denied !== null) {
            return $denied;
        }

        $entry = $this->store()->find($id);
        if ($entry === null) {
            return $this->notFound($request);
        }

        $result = $this->manager()->delete($id);
        $error = $this->resultError($result, 'Gagal menghapus domain parkir.');

        if (!(bool) ($result['ok'] ?? false)) {
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $error])->withStatus(422);
            }

            $this->flash('error', $error);

            return redirect('/domains');
        }

        $message = sprintf('Domain parkir %s dihapus.', (string) ($entry['domain'] ?? $id));
        if ((string) ($result['error'] ?? '') !== '') {
            $message .= ' ' . (string) $result['error'];
        }
        $this->flash('success', $message);

        return redirect('/domains');
    }

    /**
     * `POST /domains/parked/{id}/ssl` — aktifkan SSL satu domain parkir.
     *
     * Admin-only (non-admin ⇒ 404 **sebelum** membaca store). Inti validasi &
     * spawn worker ada di {@see ParkedSslEnabler}; controller hanya memetakan
     * hasil ke flash + redirect kembali ke tab parkir.
     */
    public function enableParked(Request $request, string $id): Response
    {
        $denied = $this->guard();
        if ($denied !== null) {
            return $denied;
        }

        $result = $this->parkedSslEnabler()->enable($id);
        $this->flash((string) $result['flash'], (string) $result['message']);

        return redirect('/domains?tab=parking');
    }

    /**
     * `POST /api/domains/parked/{id}/enable` — aktifkan SSL domain parkir (AJAX).
     *
     * Admin-only: non-admin menerima JSON 404 (bukan 403) tanpa menyentuh store.
     * Penolakan bisnis ⇒ HTTP 200 `{code:1,ok:false,error}` (JS memutuskan dari
     * `code`), sukses ⇒ `{code:0,ok:true,status,domain,message}`.
     */
    public function apiEnableParked(Request $request, string $id): Response
    {
        if (!$this->isAdmin()) {
            return json(['code' => 404, 'ok' => false, 'error' => 'Tidak ditemukan.'])->withStatus(404);
        }

        $result = $this->parkedSslEnabler()->enable($id);
        if (!$result['ok']) {
            return json(['code' => 1, 'ok' => false, 'error' => $result['error']]);
        }

        return json([
            'code' => 0,
            'ok' => true,
            'status' => $result['status'],
            'domain' => $result['domain'],
            'message' => $result['message'],
        ]);
    }

    /**
     * `GET /api/domains/status` — baris tabel "Semua Domain" sebagai JSON.
     *
     * Envelope sama dengan `/api/ssl/status` (yang tetap ada sebagai alias),
     * tetapi memuat **baris terdekorasi yang sama** dengan `index()` — termasuk
     * domain parkir untuk admin — tanpa objek `app` (tidak membocorkan data app
     * mentah). Read-only: tidak menyentuh Docker/agent.
     */
    public function apiStatus(Request $request): Response
    {
        $table = $this->table();

        $pending = false;
        foreach ($table['rows'] as $row) {
            if ((string) ($row['ssl_status'] ?? '') === 'pending') {
                $pending = true;
                break;
            }
        }

        return json([
            'code' => 0,
            'ssl_supported' => $this->sslSupported($table['rows']),
            'pending' => $pending,
            'rows' => $table['rows'],
        ]);
    }

    // ==================================================================
    // Input & payload view
    // ==================================================================

    /**
     * Input form mentah. Hanya **trim nilai string** — tidak ada validasi bisnis
     * di controller (validator/manager yang memutuskan). `redirect_code`
     * dikirim mentah karena normalisasinya milik `ParkedDomainValidator`.
     *
     * @return array<string,mixed>
     */
    private function inputFrom(Request $request, bool $isEdit): array
    {
        return [
            'domain' => self::trimString($request->post('domain', '')),
            'mode' => self::trimString($request->post('mode', 'html')),
            'html' => self::trimString($request->post('html', '')),
            'redirect_to' => self::trimString($request->post('redirect_to', '')),
            'redirect_code' => $request->post('redirect_code'),
            // Hanya create yang memakai opsi ini; manager mengabaikannya saat update.
            'add_www' => $isEdit ? false : $request->post('add_www', false),
        ];
    }

    /**
     * Nilai awal form edit: seluruh field entri + isi HTML dari berkas konten.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function inputFromEntry(array $entry, string $id): array
    {
        return array_merge($entry, [
            'html' => $this->content()->read($id) ?? '',
            'add_www' => false,
        ]);
    }

    /**
     * Payload view `domain/form`.
     *
     * @param array<string,mixed>|null $domain
     * @param array<int,string>        $errors
     * @param array<string,mixed>|null $input
     * @return array<string,mixed>
     */
    private function formVars(?array $domain, array $errors, bool $isEdit, ?array $input = null): array
    {
        $input ??= [
            'domain' => '',
            'mode' => 'html',
            'html' => '',
            'redirect_to' => '',
            'redirect_code' => 301,
            'add_www' => false,
        ];

        $wwwSuggestion = '';
        if (!$isEdit) {
            $candidate = is_string($input['domain'] ?? null) ? $input['domain'] : '';
            $wwwSuggestion = ParkedDomainValidator::suggestedWwwVariant($candidate) ?? '';
        }

        $sslId = is_array($domain) ? (string) ($domain['id'] ?? '') : '';

        return [
            'domain' => $domain,
            'input' => $input,
            'errors' => $errors,
            'isEdit' => $isEdit,
            'active' => 'domains',
            'maxHtmlBytes' => ParkedDomainValidator::MAX_HTML_BYTES,
            'appDomain' => (string) config('deploy.app_domain', ''),
            'wwwSuggestion' => $wwwSuggestion,
            // Status SSL entri parkir (form edit/create). Hanya informatif —
            // aktivasi lewat route khusus admin (`/domains/parked/{id}/ssl`).
            'ssl' => [
                'status' => is_array($domain) ? (string) ($domain['ssl_status'] ?? 'disabled') : 'disabled',
                'message' => is_array($domain) ? ($domain['ssl_message'] ?? null) : null,
                'error' => is_array($domain) ? ($domain['ssl_error'] ?? null) : null,
                'expires_at' => is_array($domain) ? ($domain['ssl_expires_at'] ?? null) : null,
                'can_ssl' => $this->isAdmin(),
                'form_url' => $sslId !== '' ? '/domains/parked/' . $sslId . '/ssl' : '',
                'ajax_url' => $sslId !== '' ? '/api/domains/parked/' . $sslId . '/enable' : '',
            ],
        ];
    }

    // ==================================================================
    // Respons
    // ==================================================================

    /**
     * Penjagaan hak akses: **non-admin ⇒ 404** (bukan 403, tanpa membocorkan
     * keberadaan halaman). `null` = boleh lanjut.
     */
    private function guard(): ?Response
    {
        if ($this->isAdmin()) {
            return null;
        }

        return response('', 404)->withStatus(404);
    }

    /**
     * Form gagal validasi: JSON 422 untuk pemanggil AJAX, selain itu form
     * dirender ulang (HTTP 200) dengan pesan galat + input lama.
     *
     * @param array<string,mixed>|null $domain
     * @param array<string,mixed>      $input
     */
    private function invalidForm(
        Request $request,
        ?array $domain,
        array $input,
        bool $isEdit,
        string $message,
    ): Response {
        if ($request->expectsJson()) {
            return json(['code' => 1, 'error' => $message])->withStatus(422);
        }

        return $this->renderView('domain/form', $this->formVars($domain, [$message], $isEdit, $input));
    }

    private function notFound(Request $request): Response
    {
        if ($request->expectsJson()) {
            return json(['code' => 1, 'error' => 'Domain parkir tidak ditemukan.'])->withStatus(404);
        }

        return response('', 404)->withStatus(404);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function resultError(array $result, string $fallback): string
    {
        $error = (string) ($result['error'] ?? '');

        return $error !== '' ? $error : $fallback;
    }

    // ==================================================================
    // Unggahan HTML
    // ==================================================================

    /**
     * Terapkan unggahan `html_file` (bila ada) ke `$input['html']` — unggahan
     * **menimpa** isi textarea. Mengembalikan pesan galat atau `null`.
     *
     * Batas ukuran diperiksa dari metadata (getSize) sebelum manager dipanggil;
     * panjang isi juga diperiksa ulang setelah dibaca (metadata bisa menyesatkan).
     *
     * @param array<string,mixed> $input
     */
    private function applyHtmlUpload(Request $request, array &$input): ?string
    {
        $file = $this->uploadedHtmlFile($request);
        if ($file === null) {
            return null;
        }

        $errorCode = (int) ($file->getUploadErrorCode() ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($errorCode !== UPLOAD_ERR_OK || !$file->isValid()) {
            return 'Upload file HTML gagal (kode error ' . $errorCode . ').';
        }

        try {
            $size = (int) $file->getSize();
        } catch (\Throwable) {
            $size = 0; // jatuh ke pemeriksaan panjang isi di bawah
        }
        if ($size > ParkedDomainValidator::MAX_HTML_BYTES) {
            return self::tooLargeMessage($size);
        }

        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '') {
            return 'Gagal membaca file HTML yang di-upload (path berkas tidak tersedia).';
        }

        $content = @file_get_contents($realPath);
        if ($content === false) {
            return 'Gagal membaca file HTML yang di-upload.';
        }
        if (strlen($content) > ParkedDomainValidator::MAX_HTML_BYTES) {
            return self::tooLargeMessage(strlen($content));
        }

        $input['html'] = $content;

        return null;
    }

    private static function tooLargeMessage(int $size): string
    {
        return sprintf(
            'File HTML terlalu besar: %d byte, batas %d byte (1 MiB). Ringkas halaman parkir Anda.',
            $size,
            ParkedDomainValidator::MAX_HTML_BYTES
        );
    }

    // ==================================================================
    // Baris tabel "Semua Domain"
    // ==================================================================

    /**
     * Baris terdekorasi untuk `index()` dan `apiStatus()` — satu sumber.
     *
     * @return array{rows:array<int,array<string,mixed>>,parked:array<int,array<string,mixed>>,isAdmin:bool}
     */
    private function table(): array
    {
        $user = $this->currentUser();
        $isAdmin = $this->isAdmin();

        $apps = AppAccess::visible($this->appStore()->all(), $user);
        // Domain parkir admin-only: non-admin tidak menerima satu baris pun
        // (bukan sekadar tombol disembunyikan).
        $parked = $isAdmin ? $this->store()->all() : [];

        $rows = array_merge(
            SslDomainRows::appRows($apps),
            SslDomainRows::parkedRows($parked)
        );

        return [
            'rows' => $this->decorateRows($rows, $user, $isAdmin),
            'parked' => $parked,
            'isAdmin' => $isAdmin,
        ];
    }

    /**
     * Tambah `can_ssl`/`form_url`/`ajax_url`/`source` per baris, lalu buang
     * objek `app` (payload view/JSON tidak boleh memuat dokumen app mentah).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function decorateRows(array $rows, ?array $user, bool $isAdmin): array
    {
        $decorated = [];

        foreach ($rows as $row) {
            $kind = (string) ($row['kind'] ?? 'app');
            $id = (string) ($row['id'] ?? '');
            $app = is_array($row['app'] ?? null) ? $row['app'] : null;

            $row['can_ssl'] = $kind === 'app'
                ? AppAccess::can('ssl', $app ?? [], $user)
                : $isAdmin;
            $row['form_url'] = $kind === 'app'
                ? '/ssl/' . rawurlencode($id) . '/enable'
                : '/domains/parked/' . rawurlencode($id) . '/ssl';
            $row['ajax_url'] = $kind === 'app'
                ? '/api/ssl/' . rawurlencode($id) . '/enable'
                : '/api/domains/parked/' . rawurlencode($id) . '/enable';
            $row['source'] = $kind === 'app'
                ? 'App: ' . (string) ($row['app_name'] ?? '')
                : 'Domain parkir';
            // Ringkasan pendek untuk kolom Aksi (`ssl_error` utuh tetap disimpan
            // dan dibuka lewat tombol Log ⇒ modal).
            $row['ssl_error_summary'] = SslErrorSummary::short(
                is_string($row['ssl_error'] ?? null) ? $row['ssl_error'] : null
            );

            unset($row['app']);
            $decorated[] = $row;
        }

        return $decorated;
    }

    /**
     * Dukungan SSL: ada minimal satu domain publik di baris?
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function sslSupported(array $rows): bool
    {
        foreach ($rows as $row) {
            if (SslIssuer::isPublicDomain((string) ($row['domain'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tab aktif dari query `tab`: whitelist `all|parking`; non-admin selalu
     * `all` (tab parkir tidak pernah dirender untuknya).
     */
    private function tab(Request $request, bool $isAdmin): string
    {
        $tab = (string) $request->get('tab', 'all');

        return $isAdmin && $tab === 'parking' ? 'parking' : 'all';
    }

    // ==================================================================
    // Seam & dependensi
    // ==================================================================

    /** User yang sedang login (seam; default membaca session). */
    protected function currentUser(): ?array
    {
        return current_user();
    }

    /** Admin global? (seam; default memakai `is_admin()`). */
    protected function isAdmin(): bool
    {
        return is_admin($this->currentUser());
    }

    /** Set flash message (seam; default memakai session). */
    protected function flash(string $type, string $message): void
    {
        flash_set($type, $message);
    }

    /**
     * Render view (seam pengujian: `view()` butuh konteks HTTP).
     *
     * @param array<string,mixed> $vars
     */
    protected function renderView(string $template, array $vars): Response
    {
        return view($template, $vars);
    }

    /** Store app (seam pengujian: basis data temp). */
    protected function appStore(): AppStore
    {
        return new AppStore();
    }

    /** Pengaktif SSL domain parkir (seam pengujian: store + spawn temp). */
    protected function parkedSslEnabler(): ParkedSslEnabler
    {
        return new ParkedSslEnabler($this->spawn, $this->store());
    }

    /** Manager domain parkir (seam pengujian: dependensi berpath temp). */
    protected function manager(): ParkedDomainManager
    {
        return new ParkedDomainManager(
            $this->store(),
            new AppStore(),
            new NginxReloader(),
            new NginxConfigGenerator(
                (string) config('deploy.nginx_conf_path'),
                (string) config('deploy.nginx_enabled_path')
            ),
            $this->content(),
            new SslIssuer()
        );
    }

    /** Penyimpanan berkas konten HTML (seam pengujian: direktori temp). */
    protected function content(): ParkedDomainContent
    {
        return new ParkedDomainContent((string) config('deploy.parked_root'));
    }

    /** Store metadata domain parkir (seam pengujian: berkas sqlite temp). */
    protected function store(): ParkedDomainStore
    {
        return new ParkedDomainStore();
    }

    /**
     * Berkas `html_file` yang benar-benar diunggah (seam pengujian). `null` bila
     * tidak ada / field file kosong (`getUploadName() === ''`).
     */
    protected function uploadedHtmlFile(Request $request): ?UploadFile
    {
        $file = $request->file('html_file');
        if (is_array($file)) {
            $file = array_values($file)[0] ?? null;
        }
        if (!$file instanceof UploadFile) {
            return null;
        }
        if (trim((string) $file->getUploadName()) === '') {
            return null;
        }

        return $file;
    }

    /** Trim hanya untuk nilai string; tipe lain diteruskan apa adanya. */
    private static function trimString(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
