<?php
declare(strict_types=1);

namespace app\controller\api;

use app\controller\app\AppControllerBase;
use app\library\Api\AmbiguousAppSlug;
use app\library\Api\ApiAppLocator;
use app\library\Api\ApiKeyAuth;
use app\library\Api\AppApiPayload;
use app\library\Auth\AppAccess;
use app\library\Billing\InsufficientCredits;
use app\library\Deploy\AppBuildBlocked;
use app\library\Deploy\AppBuildService;
use app\library\Domain\AppDomainNotFound;
use app\library\Domain\AppDomainService;
use app\library\SSL\SslActivationService;
use app\library\Storage\AppStore;
use support\Request;
use Webman\Http\Response;

/**
 * API eksternal (docs/SPECS.md §7.19). Endpoint siklus hidup & domain app:
 *
 *   - `POST /api/v1/apps/{slug}/build` — build ulang app (asinkron, 202);
 *   - `GET  /api/v1/apps/{slug}`       — status app untuk polling build;
 *   - `GET  /api/v1/apps/{slug}/domains` — status domain yang sudah diset;
 *   - `PUT  /api/v1/apps/{slug}/domain` — set custom domain utama;
 *   - `DELETE /api/v1/apps/{slug}/domain` — hapus custom domain utama;
 *   - `POST /api/v1/apps/{slug}/domains` — tambah domain tambahan (alias);
 *   - `PATCH/DELETE /api/v1/apps/{slug}/domains/{domain}` — ubah tujuan proxy /
 *     hapus satu domain tambahan;
 *   - `POST /api/v1/apps/{slug}/domains/{domain}/ssl` — ajukan aktivasi SSL
 *     (antrean global, 202; reload/certbot dijalankan worker).
 *
 * Aksi domain memakai ability `domain` (operator+) dan perilaku identik UI —
 * seluruh validasi & efek samping ada di {@see AppDomainService} (satu sumber
 * dengan `DomainSettingsController`).
 *
 * Autentikasi **bukan** di sini: `ApiAuthMiddleware` (global) menolak request
 * tanpa bearer token sah sebelum controller dipanggil. Controller hanya
 * **meresolusi user aktor** (dari token → user dashboard) supaya otorisasi app
 * dan gerbang kredit memakai model hak yang sama dengan UI:
 *
 *   - pencarian app lewat {@see ApiAppLocator} (`AppAccess::visible()`);
 *   - ability `deploy`/`view` lewat `AppAccess` — penolakan **404** yang sama
 *     untuk "tidak ada" dan "tidak berhak" (tidak ada oracle keberadaan app);
 *   - kredit lewat `billingAssertCanStart()` milik base bersama controller app
 *     (satu sumber kebijakan; lihat docblock kelas itu).
 *
 * Karena base itu dipakai, controller ini **tidak** menyimpan state properti
 * lintas-request (larangan #1) dan tidak menyentuh session/view.
 */
final class AppApiController extends AppControllerBase
{
    private readonly ?AppStore $apps;
    private readonly ?AppBuildService $builder;
    private readonly ?ApiKeyAuth $auth;
    private readonly ?AppDomainService $domains;
    private readonly ?SslActivationService $ssl;

    /**
     * @param AppStore|null             $apps    seam uji: store app temp (null = bawaan)
     * @param AppBuildService|null      $builder seam uji: pipeline build temp
     * @param ApiKeyAuth|null           $auth    seam uji: verifier API key temp
     * @param AppDomainService|null     $domains seam uji: service domain app temp
     * @param SslActivationService|null $ssl     seam uji: aktivasi SSL (antrean temp)
     */
    public function __construct(
        ?AppStore $apps = null,
        ?AppBuildService $builder = null,
        ?ApiKeyAuth $auth = null,
        ?AppDomainService $domains = null,
        ?SslActivationService $ssl = null,
    ) {
        $this->apps = $apps;
        $this->builder = $builder;
        $this->auth = $auth;
        $this->domains = $domains;
        $this->ssl = $ssl;
    }

    public function build(Request $request, string $slug): Response
    {
        [$error, $app, $actor] = $this->authorize($request, $slug, 'deploy');
        if ($error !== null) {
            return $error;
        }

        try {
            $result = ($this->builder ?? new AppBuildService())->rebuild(
                $app,
                fn () => $this->billingAssertCanStart($app, $actor),
                (string) ($actor['id'] ?? '')
            );
        } catch (InsufficientCredits $e) {
            return $this->fail(402, $e->getMessage(), 'insufficient_credits');
        } catch (AppBuildBlocked $e) {
            return $e->busy()
                ? $this->fail(409, $e->getMessage(), 'app_busy')
                : $this->fail(422, $e->getMessage(), 'build_rejected');
        }

        $name = (string) ($app['name'] ?? '');

        return json([
            'code' => 0,
            'data' => [
                'slug' => $name,
                'id' => $result['id'],
                'status' => $result['status'],
                'stage' => $result['stage'],
                'message' => $result['message'],
                'poll_url' => '/api/v1/apps/' . rawurlencode($name),
            ],
        ])->withStatus(202);
    }

    public function show(Request $request, string $slug): Response
    {
        [$error, $app] = $this->authorize($request, $slug, 'view');
        if ($error !== null) {
            return $error;
        }

        return json(['code' => 0, 'data' => AppApiPayload::summary($app)]);
    }

    /**
     * `GET /api/v1/apps/{slug}/domains` — status domain yang sudah diset.
     *
     * Menampilkan subdomain efektif, custom domain utama, dan domain tambahan
     * beserta status SSL per slot (`status`/`stage`/`message`/`expires_at`/
     * `error_summary`) — tanpa secret & tanpa keluaran certbot mentah.
     * Ability `view` (404 seragam untuk app tak ada / tak berhak).
     */
    public function domains(Request $request, string $slug): Response
    {
        [$error, $app] = $this->authorize($request, $slug, 'view');
        if ($error !== null) {
            return $error;
        }

        return json(['code' => 0, 'data' => AppDomainService::status($app)]);
    }

    /**
     * `PUT /api/v1/apps/{slug}/domain` — set/ganti custom domain **utama**.
     *
     * Body JSON `{"domain": "example.org"}`. Ability `domain`. Perilaku
     * identik form dashboard: sertifikat domain lama di-revoke, config Nginx
     * ditulis ulang di server pemilik app, subdomain dialihkan (301) ke domain
     * ini. Kegagalan tulis/reload Nginx dilaporkan lewat `warnings` — data
     * tetap tersimpan (`applied=false`), bukan respons gagal palsu.
     */
    public function setPrimaryDomain(Request $request, string $slug): Response
    {
        [$error, $app] = $this->authorize($request, $slug, 'domain');
        if ($error !== null) {
            return $error;
        }

        $input = $this->input($request);
        if ($input === null) {
            return $this->fail(422, 'Body harus berupa objek JSON yang valid.', 'invalid_json');
        }

        $domain = $this->textField($input, 'domain');
        if ($domain === null) {
            return $this->fail(422, 'Field "domain" wajib berupa string.', 'invalid_field');
        }

        try {
            $result = $this->domainService()->setPrimary($app, $domain);
        } catch (\RuntimeException $e) {
            return $this->fail(422, $this->appErrorText($app, $e), 'validation_failed');
        }

        return $this->domainResult($app, $result);
    }

    /**
     * `DELETE /api/v1/apps/{slug}/domain` — hapus custom domain utama.
     *
     * Idempoten: app tanpa custom domain tetap `200` dengan `changed=false`.
     * Sertifikat SSL domain di-revoke best-effort; kegagalan revoke/reload
     * dilaporkan di `warnings`.
     */
    public function removePrimaryDomain(Request $request, string $slug): Response
    {
        [$error, $app] = $this->authorize($request, $slug, 'domain');
        if ($error !== null) {
            return $error;
        }

        try {
            $result = $this->domainService()->removePrimary($app);
        } catch (\RuntimeException $e) {
            return $this->fail(422, $this->appErrorText($app, $e), 'validation_failed');
        }

        return $this->domainResult($app, $result);
    }

    /**
     * `POST /api/v1/apps/{slug}/domains` — tambah domain tambahan (alias).
     *
     * Body JSON `{"domain": "alias.example.org", "target_host_port": 30002}`;
     * `target_host_port` opsional (`0`/kosong = primary service app). Alias
     * melayani app langsung (tanpa redirect) supaya app multi-site bisa
     * merutekan berdasarkan header `Host`. Ability `domain`, maks 20 alias.
     */
    public function addDomainAlias(Request $request, string $slug): Response
    {
        [$error, $app] = $this->authorize($request, $slug, 'domain');
        if ($error !== null) {
            return $error;
        }

        $input = $this->input($request);
        if ($input === null) {
            return $this->fail(422, 'Body harus berupa objek JSON yang valid.', 'invalid_json');
        }

        $domain = $this->textField($input, 'domain');
        $target = $this->textField($input, 'target_host_port');
        if ($domain === null || $target === null) {
            return $this->fail(422, 'Field "domain" dan "target_host_port" harus berupa string/angka.', 'invalid_field');
        }

        try {
            $result = $this->domainService()->addAlias($app, $domain, $target);
        } catch (\RuntimeException $e) {
            return $this->fail(422, $this->appErrorText($app, $e), 'validation_failed');
        }

        return $this->domainResult($app, $result);
    }

    /**
     * `PATCH /api/v1/apps/{slug}/domains/{domain}` — ubah tujuan proxy alias.
     *
     * Body JSON `{"target_host_port": 30002}` (`0`/kosong = kembali ke primary
     * service). Hanya field `target_host_port` yang bisa diubah; nama domain
     * adalah identitas entri. Ability `domain`; alias tak dikenal ⇒ 404.
     */
    public function updateDomainAlias(Request $request, string $slug, string $domain): Response
    {
        [$error, $app] = $this->authorize($request, $slug, 'domain');
        if ($error !== null) {
            return $error;
        }

        $input = $this->input($request);
        if ($input === null) {
            return $this->fail(422, 'Body harus berupa objek JSON yang valid.', 'invalid_json');
        }

        $target = $this->textField($input, 'target_host_port');
        if ($target === null) {
            return $this->fail(422, 'Field "target_host_port" harus berupa string/angka.', 'invalid_field');
        }

        try {
            $result = $this->domainService()->updateAliasTarget($app, $domain, $target);
        } catch (AppDomainNotFound $e) {
            return $this->fail(404, $this->appErrorText($app, $e), 'alias_not_found');
        } catch (\RuntimeException $e) {
            return $this->fail(422, $this->appErrorText($app, $e), 'validation_failed');
        }

        return $this->domainResult($app, $result);
    }

    /**
     * `DELETE /api/v1/apps/{slug}/domains/{domain}` — hapus satu alias.
     *
     * Alias yang tidak terdaftar ⇒ 404 `alias_not_found` (app sudah lolos
     * otorisasi, jadi tidak ada oracle app lain). Sertifikat SSL alias
     * di-revoke best-effort.
     */
    public function removeDomainAlias(Request $request, string $slug, string $domain): Response
    {
        [$error, $app] = $this->authorize($request, $slug, 'domain');
        if ($error !== null) {
            return $error;
        }

        try {
            $result = $this->domainService()->removeAlias($app, $domain);
        } catch (\RuntimeException $e) {
            return $this->fail(422, $this->appErrorText($app, $e), 'validation_failed');
        }

        if (($result['found'] ?? false) !== true) {
            return $this->fail(404, 'Domain tambahan tidak ditemukan pada app ini.', 'alias_not_found');
        }

        return $this->domainResult($app, $result);
    }

    /**
     * `POST /api/v1/apps/{slug}/domains/{domain}/ssl` — ajukan aktivasi SSL.
     *
     * Ability `ssl` (operator+). Penerbitan sertifikat berjalan **asinkron** di
     * antrean global (`ssl_jobs`) karena certbot memakai lock global + rate
     * limit Let's Encrypt: respons `202` hanya berarti job sudah masuk antrean.
     * Worker `cli/ssl.php` yang menulis ulang config Nginx (blok ACME/443),
     * me-reload Nginx di server pemilik app, lalu menjalankan certbot.
     *
     * Idempoten: slot yang sudah `active`/`pending` ⇒ `200` `already: true`
     * tanpa job baru. Domain wajib milik app (subdomain/utama/alias) — domain
     * lain ⇒ `404 domain_not_found`. Pantau hasil lewat
     * `GET /api/v1/apps/{slug}/domains`.
     */
    public function enableDomainSsl(Request $request, string $slug, string $domain): Response
    {
        [$error, $app, $actor] = $this->authorize($request, $slug, 'ssl');
        if ($error !== null) {
            return $error;
        }

        try {
            $result = $this->sslService()->request($app, $domain, (string) ($actor['id'] ?? ''));
        } catch (\RuntimeException $e) {
            return $this->fail(422, $this->appErrorText($app, $e), 'validation_failed');
        }

        $data = [
            'slug' => (string) ($app['name'] ?? ''),
            'domain' => $result['domain'],
            'slot' => $result['slot'],
            'status' => $result['status'],
            'message' => $result['message'],
            'poll_url' => '/api/v1/apps/' . rawurlencode((string) ($app['name'] ?? '')) . '/domains',
        ];

        if ($result['ok']) {
            return json([
                'code' => 0,
                'data' => $data + [
                    'queued' => $result['queued'],
                    'queue_position' => $result['queue_position'],
                    'queue_size' => $result['queue_size'],
                ],
            ])->withStatus(202);
        }

        if ($result['reason'] === 'already') {
            return json(['code' => 0, 'data' => $data + ['already' => true]]);
        }

        if ($result['reason'] === 'not_owned') {
            return $this->fail(404, (string) $result['error'], 'domain_not_found');
        }

        if ($result['reason'] === 'queue_failed') {
            return $this->fail(503, (string) $result['error'], 'ssl_queue_failed');
        }

        return $this->fail(422, (string) $result['error'], 'validation_failed');
    }

    /**
     * Resolusi aktor **dan** app sekaligus, dengan penolakan seragam:
     * 401 tanpa key sah, 409 slug ambigu, 404 untuk app tak ada **atau** aktor
     * tidak punya ability (tanpa oracle keberadaan app).
     *
     * @return array{0:?Response,1:?array<string,mixed>,2:?array<string,mixed>}
     */
    private function authorize(Request $request, string $slug, string $ability): array
    {
        // Defense-in-depth: `ApiAuthMiddleware` sudah menolak key tak sah
        // sebelum controller dipanggil.
        $actor = ($this->auth ?? new ApiKeyAuth())->actorFromRequest($request);
        if ($actor === null) {
            return [$this->fail(401, 'Unauthorized', 'unauthorized'), null, null];
        }

        try {
            $app = $this->locate($slug, $actor);
        } catch (AmbiguousAppSlug $e) {
            return [$this->fail(409, $e->getMessage(), 'ambiguous_app'), null, $actor];
        }

        if ($app === null || !AppAccess::can($ability, $app, $actor)) {
            return [$this->fail(404, 'App tidak ditemukan.', 'not_found'), null, $actor];
        }

        return [null, $app, $actor];
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>|null
     * @throws AmbiguousAppSlug
     */
    private function locate(string $slug, ?array $actor): ?array
    {
        return (new ApiAppLocator($this->apps))->findVisible($slug, $actor);
    }

    /**
     * Body request API: objek JSON (`application/json`) atau form-urlencoded.
     *
     * @return array<string,mixed>|null null = body JSON tidak valid/bukan objek
     */
    private function input(Request $request): ?array
    {
        $contentType = strtolower((string) $request->header('content-type', ''));
        if (!str_contains($contentType, 'application/json')) {
            $post = $request->post();

            return is_array($post) ? $post : [];
        }

        $raw = trim((string) $request->rawBody());
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Nilai teks dari body: string apa adanya, int dikonversi, field hilang ⇒
     * string kosong. Nilai non-skalar (array/objek/bool) ⇒ `null` (tolak 422).
     *
     * @param array<string,mixed> $input
     */
    private function textField(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input)) {
            return '';
        }

        $value = $input[$key];
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Respons sukses operasi domain: status domain **terkini** + `warnings`
     * untuk kegagalan tulis/reload/revoke yang tidak membatalkan data.
     *
     * @param array<string,mixed> $app
     * @param array<string,mixed> $result keluaran {@see AppDomainService}
     */
    private function domainResult(array $app, array $result): Response
    {
        $warnings = [];
        if (($result['revoke_error'] ?? null) !== null) {
            $warnings[] = 'Gagal revoke sertifikat domain: ' . $this->appErrorText($app, (string) $result['revoke_error']);
        }
        if (($result['write_error'] ?? null) !== null) {
            $warnings[] = 'Gagal menulis config Nginx: ' . $this->appErrorText($app, (string) $result['write_error'])
                . ' (jalankan Rebuild untuk menerapkan).';
        } elseif (($result['reload_error'] ?? null) !== null) {
            $warnings[] = 'Reload Nginx GAGAL: ' . $this->appErrorText($app, (string) $result['reload_error']);
        }

        $fresh = is_array($result['app'] ?? null) ? $result['app'] : $app;

        return json([
            'code' => 0,
            'data' => [
                'changed' => (bool) ($result['changed'] ?? false),
                'applied' => (bool) ($result['applied'] ?? false),
                'reloaded' => (bool) ($result['reloaded'] ?? false),
                'domain' => (string) ($result['domain'] ?? ''),
                'target_host_port' => (int) ($result['target_host_port'] ?? 0),
                'warnings' => $warnings,
                'domains' => AppDomainService::status($fresh),
            ],
        ]);
    }

    private function domainService(): AppDomainService
    {
        return $this->domains ?? new AppDomainService($this->apps);
    }

    private function sslService(): SslActivationService
    {
        return $this->ssl ?? new SslActivationService($this->apps);
    }

    private function fail(int $status, string $error, string $reason): Response
    {
        return json(['code' => $status, 'error' => $error, 'reason' => $reason])->withStatus($status);
    }
}
