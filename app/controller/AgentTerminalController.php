<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentAppProjection;
use app\library\Auth\AppAccessDenied;
use app\library\Deploy\AgentDeployer;
use app\library\Storage\AppStore;
use support\Request;
use Webman\Http\Response;

/**
 * Endpoint terminal **sisi agent** (F3a multi-server, plan §4.1/§5):
 *
 * ```
 * POST /terminal/open              {app_id, container, shell?, user?}  → {code:0,data:{token,…}}
 * GET  /terminal/{token}/stream?app_id=X                              → SSE (chunked)
 * POST /terminal/{token}/input     {app_id, data}                     → {code:0}
 * POST /terminal/{token}/close     {app_id}                           → {code:0}
 * ```
 *
 * Dipanggil control plane (`TerminalController` ⇒ `AgentDeployer`), bukan UI:
 * route ini hanya terdaftar di mode `agent` dan otomatis tertutup
 * `AgentAuthMiddleware`. **Sesi hidup di sini** (proses detached + FIFO di
 * `DockerExec`), jadi `GET /terminal/{token}/stream` boleh drop kapan saja —
 * control plane menyambung ulang sesi yang SAMA (aturan repo: koneksi SSE drop
 * bukan akhir sesi; hanya `close`/proses keluar/TTL yang menutupnya).
 *
 * Kelas ini **tidak menyalin** siklus hidup SSE: ia mewarisi `TerminalController`
 * (satu sumber protokol stream: header chunked, `retry`/`cycle`/`close`/`gone`,
 * deteksi macet, heartbeat) dan hanya mengganti dua hal yang memang berbeda di
 * sisi agent:
 *  1. **sumber app** — proyeksi agent (`AppStore` agent), bukan store control
 *     plane; `AppAccess` tidak berlaku di sini karena otorisasi (ability
 *     `terminal`) sudah dilakukan control plane sebelum memanggil. App yang
 *     tidak ada di proyeksi ⇒ `AppAccessDenied` ⇒ **404** (bukan 403), sama
 *     invariannya dengan dashboard;
 *  2. **audit** — tidak ditulis di agent (identitas user hanya ada di control
 *     plane; control plane yang mencatat audit trail-nya).
 *
 * `app_id` ikut di body/query setiap panggilan supaya validasi "token milik app
 * ini" tetap satu sumber di `DockerExec::sessionInfo($token, $appId)` — token
 * saja tidak pernah cukup untuk mengakses sesi app lain. Nama container tetap
 * divalidasi `AppContainers::resolve()` terhadap Engine **server ini** sebelum
 * sesi dibuka.
 */
class AgentTerminalController extends TerminalController
{
    /** Bentuk app id yang boleh dipakai (pola sama registry/app store). */
    private const APP_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    private ?AppStore $apps;

    /**
     * @param AppStore|null $apps proyeksi app agent (seam uji; null ⇒ bawaan)
     */
    public function __construct(?AppStore $apps = null)
    {
        $this->apps = $apps;
    }

    /** `POST /terminal/open` */
    public function openSession(Request $request)
    {
        $appId = $this->appId($request);
        if ($this->appDoc($appId) === null) {
            return $this->notFound();
        }

        return parent::open($request, $appId);
    }

    /** `GET /terminal/{token}/stream` (SSE) */
    public function streamSession(Request $request, string $token)
    {
        $appId = $this->appId($request);
        if ($this->appDoc($appId) === null) {
            // EventSource hanya mengerti SSE: balasan galat pun SSE supaya klien
            // berhenti sendiri (bukan badai retry) — sama dengan sesi yang hilang.
            return $this->sseGone($request, 'App tidak ditemukan di server agent.');
        }

        return parent::stream($request, $appId, $token);
    }

    /** `POST /terminal/{token}/input` */
    public function inputSession(Request $request, string $token)
    {
        $appId = $this->appId($request);
        if ($this->appDoc($appId) === null) {
            return $this->notFound();
        }

        return parent::input($request, $appId, $token);
    }

    /** `POST /terminal/{token}/close` */
    public function closeSession(Request $request, string $token)
    {
        $appId = $this->appId($request);
        if ($this->appDoc($appId) === null) {
            return $this->notFound();
        }

        return parent::close($request, $appId, $token);
    }

    /**
     * 404 JSON eksplisit untuk API mesin (tanpa bergantung negosiasi konten):
     * `AgentClient` menerjemahkannya menjadi `AgentError('not_found')` dengan
     * pesan yang bisa diteruskan control plane apa adanya.
     */
    private function notFound(): Response
    {
        return json([
            'code' => 1,
            'error' => 'App tidak ditemukan di proyeksi server agent.',
            'reason' => 'not_found',
        ])->withStatus(404);
    }

    /**
     * Dokumen app dari **proyeksi agent**; `null` bila tidak ada (atau id tidak
     * berbentuk id yang sah — jangan sampai nama aneh masuk ke store).
     *
     * @return array<string,mixed>|null
     */
    private function appDoc(string $id): ?array
    {
        if ($id === '' || preg_match(self::APP_ID_PATTERN, $id) !== 1) {
            return null;
        }

        try {
            return $this->appStore()->find($id);
        } catch (\Throwable) {
            // Basis data agent tidak terbaca: API mesin tetap menjawab JSON.
            return null;
        }
    }

    /**
     * App dari proyeksi agent — dipakai `requireApp()` warisan (satu sumber
     * lookup, jaring pengaman bila jalur warisan memanggilnya langsung).
     *
     * @throws AppAccessDenied app tidak ada di proyeksi ⇒ 404 (bukan 403)
     */
    protected function requireApp(string $id): array
    {
        $app = $this->appDoc($id);
        if ($app === null) {
            throw new AppAccessDenied('terminal', $id);
        }

        return $app;
    }

    /**
     * Audit **tidak** ditulis di agent: identitas pelaku (user dashboard) hanya
     * diketahui control plane, dan di sanalah catatan audit operasi terminal
     * dibuat (`TerminalController::audit()`). Menulis di sini hanya akan
     * menghasilkan baris tanpa pelaku.
     */
    protected function audit(array $app, string $container, string $action): void
    {
    }

    /**
     * Agent adalah **eksekutor terakhir**: tidak ada jalur proxy lanjutan di sini
     * (yang mem-proxy adalah `TerminalController` sisi dashboard ke endpoint ini).
     * Selalu `null` ⇒ jalur lokal (`DockerExec` server ini) yang dipakai; agent
     * juga tidak perlu membaca registry server (memang tidak ada di agent).
     */
    protected function agentDeployer(array $app): ?AgentDeployer
    {
        return null;
    }

    /**
     * Id app dari body (POST JSON/form) atau query (GET) — `''` bila tidak ada,
     * yang otomatis menjadi 404 lewat {@see self::requireApp()}.
     */
    private function appId(Request $request): string
    {
        $value = $request->post('app_id');
        if (!is_scalar($value) || trim((string) $value) === '') {
            $value = $request->get('app_id', '');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Store proyeksi app agent (path mengikuti seam yang disuntikkan). */
    protected function appStore(): AppStore
    {
        return $this->apps ?? new AppStore((new AgentAppProjection())->path());
    }
}
