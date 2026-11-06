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
use app\library\Storage\AppStore;
use support\Request;
use Webman\Http\Response;

/**
 * API eksternal fase awal (docs/SPECS.md §7.19):
 *
 *   - `POST /api/v1/apps/{slug}/build` — build ulang app (asinkron, 202);
 *   - `GET  /api/v1/apps/{slug}`       — status app untuk polling build.
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

    /**
     * @param AppStore|null         $apps    seam uji: store app temp (null = bawaan)
     * @param AppBuildService|null  $builder seam uji: pipeline build temp
     * @param ApiKeyAuth|null       $auth    seam uji: verifier API key temp
     */
    public function __construct(
        ?AppStore $apps = null,
        ?AppBuildService $builder = null,
        ?ApiKeyAuth $auth = null,
    ) {
        $this->apps = $apps;
        $this->builder = $builder;
        $this->auth = $auth;
    }

    public function build(Request $request, string $slug): Response
    {
        // Defense-in-depth: `ApiAuthMiddleware` sudah menolak key tak sah
        // sebelum controller dipanggil.
        $actor = ($this->auth ?? new ApiKeyAuth())->actorFromRequest($request);
        if ($actor === null) {
            return $this->fail(401, 'Unauthorized', 'unauthorized');
        }

        try {
            $app = $this->locate($slug, $actor);
        } catch (AmbiguousAppSlug $e) {
            return $this->fail(409, $e->getMessage(), 'ambiguous_app');
        }

        if ($app === null || !AppAccess::can('deploy', $app, $actor)) {
            return $this->fail(404, 'App tidak ditemukan.', 'not_found');
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
        $actor = ($this->auth ?? new ApiKeyAuth())->actorFromRequest($request);
        if ($actor === null) {
            return $this->fail(401, 'Unauthorized', 'unauthorized');
        }

        try {
            $app = $this->locate($slug, $actor);
        } catch (AmbiguousAppSlug $e) {
            return $this->fail(409, $e->getMessage(), 'ambiguous_app');
        }

        if ($app === null || !AppAccess::can('view', $app, $actor)) {
            return $this->fail(404, 'App tidak ditemukan.', 'not_found');
        }

        return json(['code' => 0, 'data' => AppApiPayload::summary($app)]);
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

    private function fail(int $status, string $error, string $reason): Response
    {
        return json(['code' => $status, 'error' => $error, 'reason' => $reason])->withStatus($status);
    }
}
