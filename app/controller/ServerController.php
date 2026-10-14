<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Agent\AgentClient;
use app\library\Agent\AgentError;
use app\library\Agent\TlsPinMaterializer;
use app\library\Auth\AppAccessDenied;
use app\library\Auth\ServerAccess;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\DeployWorkerLauncher;
use app\library\Deploy\ServerResolver;
use app\library\Storage\ServerStore;
use app\library\Update\AgentVersionGate;
use app\library\Update\UpdateService;
use RuntimeException;
use support\Request;
use Webman\Http\Response;

/**
 * Registry server (multi-server, fase F0) — **admin-only**.
 *
 * Halaman & aksi untuk mengelola server yang dikendalikan dashboard: server
 * lokal (diri sendiri) + server `kind=agent` (plan §4.2, §9.3, §10.2).
 *
 * Controller ini **mediator tipis**: tidak ada logika bisnis di sini. Validasi &
 * normalisasi ada di {@see ServerResolver} (termasuk perhitungan `tls_fingerprint`
 * dari PEM lewat `fingerprintFromPem()`), penyimpanan di {@see ServerStore}, dan
 * komunikasi ke agent di {@see AgentClient}. Di controller hanya: penjagaan hak
 * akses, pemetaan input form → library, dan pemetaan hasil → respons
 * (`view()`/`json()`/`redirect()`).
 *
 * Invarian yang ditegakkan:
 *  - **non-admin ⇒ 404** (bukan 403) di **semua** route, mengikuti pola
 *    `NginxController::index()`: keberadaan registry server tidak bocor ke member;
 *  - **cek otorisasi SEBELUM efek samping** apa pun (tidak ada panggilan store,
 *    tidak ada panggilan jaringan, tidak ada flash);
 *  - server **lokal** hanya boleh dilihat; identitas & field agent-nya dikunci di
 *    sisi server (bukan hanya di UI) dan penghapusannya ditolak;
 *  - **tanpa state properti lintas-request**: satu-satunya properti adalah seam
 *    pengujian (store/resolver/materializer berpath temp) yang dibangun
 *    per-request bila `null` — pola `CreditController`;
 *  - token & PEM **tidak pernah** ditulis ke log, pesan flash, atau payload yang
 *    dirender dari dokumen tersimpan (lihat `publicServer()`/`formServer()`).
 *
 * Catatan desain: aturan baris **server lokal** (field mana yang boleh berubah vs
 * dikunci, termasuk mengapa `port_range` lokal sengaja dikunci) seluruhnya ada di
 * `ServerResolver::validateLocal()` — controller hanya memanggilnya, tanpa salinan
 * aturan. Baris lokal hanya bisa mengubah `domain` & `notes`; `kind` mustahil
 * berubah dari `local`.
 */
class ServerController
{
    /** Field form server yang dibaca dari POST; field lain dari dokumen diabaikan. */
    private const FORM_FIELDS = [
        'name',
        'kind',
        'base_url',
        'token',
        'tls_cert_pem',
        'domain',
        'notes',
        'enabled',
    ];

    private ?ServerStore $servers;
    private ?ServerResolver $resolver;
    private ?TlsPinMaterializer $pins;

    /**
     * Dependensi opsional = **seam pengujian** (pola `CreditController`).
     * `null` ⇒ dibangun bawaan per-request (tanpa memo lintas-request), sehingga
     * tidak ada state yang bocor antar request pada worker Webman persistent.
     */
    public function __construct(
        ?ServerStore $servers = null,
        ?ServerResolver $resolver = null,
        ?TlsPinMaterializer $pins = null,
    ) {
        $this->servers = $servers;
        $this->resolver = $resolver;
        $this->pins = $pins;
    }

    // ==================================================================
    // Halaman
    // ==================================================================

    /**
     * `GET /servers` — daftar server.
     *
     * Payload view: `servers` (dokumen server **tanpa** token/PEM, ditambah
     * `has_token`), `localId`, `active`.
     */
    public function index(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        return $this->renderView('server/index', [
            'servers' => array_map([$this, 'publicServer'], $this->servers()->all()),
            'localId' => ServerStore::LOCAL_ID,
            'active' => 'servers',
        ]);
    }

    /**
     * `GET /servers/create` — form server baru.
     *
     * Payload view: `server` (`null`), `errors` (`[]`), `isEdit` (`false`),
     * `active` (`'servers'`).
     */
    public function createForm(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        return $this->renderView('server/form', $this->formVars(null, [], false));
    }

    /**
     * `GET /servers/{id}` — form edit server.
     *
     * Payload view: `server` (dokumen; `token` **dikosongkan**, `tls_cert_pem`
     * apa adanya + `has_token`), `errors` (`[]`), `isEdit` (`true`), `active`.
     */
    public function editForm(Request $request, string $id): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $server = $this->servers()->find($id);
        if ($server === null) {
            return $this->notFound($request);
        }

        return $this->renderView('server/form', $this->formVars($server, [], true));
    }

    // ==================================================================
    // Aksi
    // ==================================================================

    /**
     * `POST /servers` — tambah server agent.
     *
     * Sukses ⇒ redirect `/servers` + flash. Gagal validasi ⇒ **422 + JSON**
     * `{code:1,error}` bila `expectsJson()`, selain itu render ulang form dengan
     * `errors` + input lama (tidak ada input yang hilang).
     */
    public function store(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $input = $this->serverInput($request);

        try {
            // `validate()` menormalkan seluruh field, **menghitung
            // `tls_fingerprint`** dari PEM (`ServerResolver::fingerprintFromPem()`),
            // dan menolak `kind=local` (server lokal hanya milik sistem).
            $server = $this->resolver()->validate($input);
            $server['tls_fingerprint'] = self::fingerprint($server);
            $server['status'] = 'unknown';
            $created = $this->servers()->create($server);
        } catch (RuntimeException $e) {
            return $this->invalidForm($request, $input, false, $e->getMessage());
        }

        $this->flash(
            'success',
            sprintf('Server "%s" ditambahkan. Jalankan Test connection untuk memeriksa koneksi agent.', $created['name'])
        );

        return redirect('/servers');
    }

    /**
     * `POST /servers/{id}` — perbarui server.
     *
     * Server agent: seluruh field boleh berubah (token/PEM kosong = tidak
     * diubah). Bila `base_url`/`token`/`tls_cert_pem` berubah, state koneksi lama
     * (`status`, `agent_version`, `capabilities`, `last_seen_at`) direset ke
     * `unknown` karena tidak lagi menggambarkan endpoint yang baru.
     *
     * Server lokal: hanya `domain` & `notes` yang boleh berubah; perubahan field
     * lain ⇒ flash error + redirect (tidak ada yang ditulis).
     */
    public function update(Request $request, string $id): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $server = $this->servers()->find($id);
        if ($server === null) {
            return $this->notFound($request);
        }

        $input = $this->serverInput($request);

        if (ServerResolver::isLocal($server)) {
            return $this->updateLocal($request, $server, $input);
        }

        // Token/PEM yang dikosongkan (form edit tidak menampilkan rahasia) berarti
        // "tidak diubah" — nilai tersimpan dipakai agar validasi tetap lengkap
        // (alias lama `token_ref` tetap dihormati saat membaca).
        $storedToken = (string) ($server['token'] ?? $server['token_ref'] ?? '');
        $storedPem = (string) ($server['tls_cert_pem'] ?? '');
        if (trim((string) ($input['token'] ?? '')) === '') {
            $input['token'] = $storedToken;
        }
        if (trim((string) ($input['tls_cert_pem'] ?? '')) === '') {
            $input['tls_cert_pem'] = $storedPem;
        }
        $input['kind'] = 'agent';

        try {
            $doc = $this->resolver()->validate($input);
            $doc['tls_fingerprint'] = self::fingerprint($doc);

            if ($this->nameTakenByOther($doc['name'], $id)) {
                throw new RuntimeException(sprintf('Nama server "%s" sudah dipakai server lain.', $doc['name']));
            }

            // Endpoint/kredensial berganti ⇒ jejak koneksi lama tidak berlaku lagi.
            $identityChanged = $doc['base_url'] !== (string) ($server['base_url'] ?? '')
                || $doc['token'] !== $storedToken
                || $doc['tls_cert_pem'] !== trim($storedPem);

            $this->servers()->update($id, static function (array &$row) use ($doc, $identityChanged): void {
                // Hanya field yang dikelola form ini yang disentuh; `id`, `kind`,
                // `created_at`, `status`, `agent_version`, `capabilities`, dan
                // `last_seen_at` dipertahankan apa adanya (kecuali reset di bawah).
                $row['name'] = $doc['name'];
                $row['base_url'] = $doc['base_url'];
                $row['token'] = $doc['token'];
                unset($row['token_ref']); // bentuk kanonik memakai `token`
                $row['tls_cert_pem'] = $doc['tls_cert_pem'];
                $row['tls_fingerprint'] = $doc['tls_fingerprint'];
                $row['domain'] = $doc['domain'];
                $row['notes'] = $doc['notes'];
                $row['enabled'] = $doc['enabled'];

                if (array_key_exists('port_range', $doc)) {
                    $row['port_range'] = $doc['port_range'];
                } else {
                    unset($row['port_range']);
                }

                if ($identityChanged) {
                    $row['status'] = 'unknown';
                    unset(
                        $row['agent_version'],
                        $row['capabilities'],
                        $row['min_control_plane_version'],
                        $row['last_seen_at']
                    );
                }
            });
        } catch (RuntimeException $e) {
            return $this->invalidForm($request, $input + ['id' => $id], true, $e->getMessage(), $storedToken !== '');
        }

        $this->flash('success', sprintf('Server "%s" diperbarui.', $doc['name']));

        return redirect('/servers');
    }

    /**
     * `POST /servers/{id}/delete` — hapus server.
     *
     * Server lokal ditolak (`ServerStore::delete()` melempar) dengan pesan jelas.
     * Berkas pin TLS yang tersisa dibersihkan setelah server benar-benar hilang.
     */
    public function delete(Request $request, string $id): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $server = $this->servers()->find($id);
        if ($server === null) {
            return $this->notFound($request);
        }

        try {
            $this->servers()->delete($id);
        } catch (RuntimeException $e) {
            return $this->rejected($request, $e->getMessage(), '/servers');
        }

        $pruned = $this->pinMaterializer()->prune(array_column($this->servers()->all(), 'id'));

        $this->flash('success', sprintf(
            'Server "%s" dihapus%s',
            (string) ($server['name'] ?? $id),
            $pruned > 0 ? '; berkas pin TLS-nya ikut dibersihkan.' : '.'
        ));

        return redirect('/servers');
    }

    /**
     * `POST /servers/{id}/test` — uji koneksi ke agent (JSON).
     *
     * Memanggil `GET /healthz` + `GET /version` (masing-masing ≤30 s) lalu
     * menyimpan hasil observasi: `status`, `agent_version`, `capabilities`,
     * `last_seen_at`. Server lokal **tidak** dipanggil lewat jaringan (ia adalah
     * dashboard ini sendiri) dan langsung ditandai `online`.
     *
     * Gagal ⇒ `{code:1, error: <pesan bahasa Indonesia>, reason: <AgentError::*>}`
     * dengan `status='offline'`; pesan **tidak pernah** memuat token/PEM.
     */
    public function testConnection(Request $request, string $id): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $server = $this->servers()->find($id);
        if ($server === null) {
            return json(['code' => 1, 'error' => 'Server tidak ditemukan.'])->withStatus(404);
        }

        if (ServerResolver::isLocal($server)) {
            // Dashboard berjalan di server ini; tidak ada agent untuk dihubungi.
            return json(['code' => 0, 'server' => $this->recordResult($id, 'online', null, [], null)]);
        }

        try {
            $client = $this->agentClient($server);
            $health = $client->health();
            $version = $client->version();
        } catch (AgentError $e) {
            $this->recordResult($id, 'offline', null, null, null);

            return json([
                'code' => 1,
                'error' => $this->friendlyError($e, $server),
                'reason' => $e->reason,
            ]);
        } catch (RuntimeException $e) {
            $this->recordResult($id, 'offline', null, null, null);

            return json([
                'code' => 1,
                'error' => 'Konfigurasi server tidak bisa dipakai untuk menghubungi agent: ' . $e->getMessage(),
                'reason' => AgentError::INVALID_CONFIG,
            ]);
        }

        $agentVersion = trim((string) ($version['agent_version'] ?? $health['version'] ?? ''));
        $capabilities = $this->capabilities($version['capabilities'] ?? []);
        // Versi control plane minimum yang diminta agen (F4 lanjutan): disimpan
        // per server supaya gerbang versi (`AgentVersionGate`) bisa menolak op
        // sebelum efek samping, tanpa memanggil agen tiap kali.
        $minControlPlane = is_scalar($version['min_control_plane_version'] ?? null)
            ? trim((string) $version['min_control_plane_version'])
            : '';

        return json([
            'code' => 0,
            'server' => $this->recordResult($id, 'online', $agentVersion, $capabilities, $minControlPlane),
        ]);
    }

    /**
     * `POST /servers/{id}/agent-update` — perbarui **agen** di server itu
     * (F4 lanjutan; JSON, admin-only).
     *
     * Update dijalankan **di agen** (job async `self_update` → worker
     * `cli/deploy.php self_update` di sana, yang memakai helper container di luar
     * compose project — pola self-update dashboard). Control plane **tidak
     * pernah** me-recreate container agen dan tidak menjalankan update apa pun
     * untuk server itu di sini.
     *
     * Urutan penjagaan (fail-fast sebelum efek samping):
     *  1. otorisasi (`guard()` ⇒ 404 untuk non-admin);
     *  2. server ada & `kind=agent` (baris lokal ditolak: dashboard-nya diupdate
     *     lewat `Config → Update`);
     *  3. `mode` ∈ `update|rollback`;
     *  4. gerbang versi & kapabilitas ({@see AgentVersionGate::check()} dengan op
     *     `self_update`): agen yang terlalu tua, control plane yang terlalu tua
     *     untuk agen itu, atau agen yang belum melaporkan dukungan `self_update`
     *     ditolak dengan pesan jelas — agen **tidak** akan diperbarui ke versi
     *     yang tidak kompatibel dengan dashboard ini.
     *
     * Respons: `{code:0, data:{job_id, server_id, mode}, msg}` — pesannya
     * mengingatkan bahwa agen akan restart (proses job bisa terputus saat
     * container di-recreate) sehingga verifikasi versi baru dilakukan lewat
     * **Test connection**.
     */
    public function agentUpdate(Request $request, string $id): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        $server = $this->servers()->find($id);
        if ($server === null) {
            return json(['code' => 1, 'error' => 'Server tidak ditemukan.'])->withStatus(404);
        }

        if (ServerResolver::isLocal($server)) {
            return json([
                'code' => 400,
                'msg' => 'Server lokal adalah dashboard ini sendiri — perbarui lewat halaman Config → tab Update, bukan lewat agen.',
            ]);
        }

        $mode = trim((string) $request->post('mode', 'update'));
        if (!in_array($mode, UpdateService::MODES, true)) {
            return json(['code' => 400, 'msg' => 'Mode update tidak dikenal (update|rollback).']);
        }

        $compat = AgentVersionGate::check($server, DeployWorkerLauncher::AGENT_UPDATE_OP);
        if (!$compat['ok']) {
            return json(['code' => 400, 'msg' => implode(' ', $compat['errors'])]);
        }

        try {
            $deployer = $this->agentDeployerForServer($server);
            if (!$deployer instanceof AgentDeployer) {
                throw new RuntimeException('Update agen membutuhkan server `kind=agent` yang valid.');
            }

            $result = $deployer->startServerJob(
                DeployWorkerLauncher::AGENT_UPDATE_OP,
                DeployWorkerLauncher::AGENT_UPDATE_TARGET,
                [
                    'update_mode' => $mode,
                    // Versi control plane pemanggil: gerbang kompatibilitas sisi
                    // agen (bukan kredensial) — agen menolak perintah dari
                    // dashboard yang terlalu tua untuknya.
                    'control_plane_version' => AgentVersionGate::CODEBASE_VERSION,
                ]
            );
        } catch (AgentError $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'reason' => $e->reason]);
        } catch (RuntimeException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        }

        return json([
            'code' => 0,
            'data' => [
                'job_id' => $result['job_id'],
                'server_id' => $id,
                'mode' => $mode,
                'agent_version' => (string) ($server['agent_version'] ?? ''),
            ],
            'msg' => 'Update agen dimulai di server "' . (string) ($server['name'] ?? $id)
                . '" (job ' . $result['job_id'] . '). Agen akan restart; jalankan Test connection setelahnya '
                . 'untuk memverifikasi versi baru.',
        ]);
    }

    /**
     * `GET /api/servers` — daftar server untuk konsumsi JS (JSON).
     *
     * **Tanpa** `token`/`token_ref`/`tls_cert_pem` (hanya `has_token` boolean).
     * Bentuk respons mengikuti konvensi repo: `{code:0, data:[…]}`.
     */
    public function apiIndex(Request $request): Response
    {
        $denied = $this->guard($request);
        if ($denied !== null) {
            return $denied;
        }

        return json([
            'code' => 0,
            'data' => array_map([$this, 'publicServer'], $this->servers()->all()),
        ]);
    }

    // ==================================================================
    // Server lokal (baris `local`) — hanya domain & notes
    // ==================================================================

    /**
     * Perbarui baris server lokal: seluruh aturan (field boleh berubah vs dikunci)
     * ada di `ServerResolver::validateLocal()` — satu sumber aturan, tanpa salinan
     * di controller.
     *
     * @param array<string,mixed> $server dokumen tersimpan
     * @param array<string,mixed> $input  hanya field yang benar-benar dikirim form
     */
    private function updateLocal(Request $request, array $server, array $input): Response
    {
        try {
            $doc = ServerResolver::validateLocal($input, $server);
        } catch (RuntimeException $e) {
            return $this->rejected($request, $e->getMessage());
        }

        // Hanya field yang dikembalikan validator (domain/notes yang dikirim) yang
        // ditulis; sisanya dipertahankan apa adanya.
        $this->servers()->update(ServerStore::LOCAL_ID, static function (array &$row) use ($doc): void {
            foreach ($doc as $field => $value) {
                $row[$field] = $value;
            }
        });

        $this->flash('success', 'Server lokal diperbarui.');

        return redirect('/servers/' . ServerStore::LOCAL_ID);
    }

    /**
     * Fingerprint SHA-256 sertifikat agent untuk dokumen server.
     *
     * `ServerResolver::validate()` sudah menghitungnya; helper ini menjaga
     * invarian "fingerprint selalu ada & konsisten dengan PEM" tanpa menghitung
     * ulang saat nilainya sudah ada (openssl tidak dipanggil dua kali).
     *
     * @param array<string,mixed> $server
     */
    private static function fingerprint(array $server): string
    {
        $fingerprint = trim((string) ($server['tls_fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            return $fingerprint;
        }

        return ServerResolver::fingerprintFromPem((string) ($server['tls_cert_pem'] ?? ''));
    }

    // ==================================================================
    // Input form
    // ==================================================================

    /**
     * Input form server: **hanya** field yang benar-benar dikirim (field kosong
     * yang tidak dikirim tidak dianggap "diubah"/"diisi"), nilai non-skalar
     * ditolak-diam (validator yang memberi pesan jelas), `port_range` menerima
     * `port_range[start]`/`[end]`, teks `"31000-31999"`, atau pasangan
     * `port_range_start`/`port_range_end`.
     *
     * @return array<string,mixed>
     */
    private function serverInput(Request $request): array
    {
        $input = [];
        foreach (self::FORM_FIELDS as $field) {
            $value = $request->post($field);
            if (is_string($value) || is_int($value) || is_float($value)) {
                $input[$field] = (string) $value;
            }
        }

        $range = $request->post('port_range');
        if (is_array($range)) {
            $input['port_range'] = $range;
        } elseif (is_string($range) && trim($range) !== '') {
            $input['port_range'] = trim($range);
        } else {
            $start = $request->post('port_range_start');
            $end = $request->post('port_range_end');
            $start = is_scalar($start) ? trim((string) $start) : '';
            $end = is_scalar($end) ? trim((string) $end) : '';
            if ($start !== '' || $end !== '') {
                $input['port_range'] = ['start' => $start, 'end' => $end];
            }
        }

        return $input;
    }

    // ==================================================================
    // Payload view
    // ==================================================================

    /**
     * Dokumen server untuk halaman/JSON: rahasia dibuang, ditambah penanda
     * `has_token` supaya UI tetap tahu token sudah terpasang.
     *
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    private function publicServer(array $server): array
    {
        $hasToken = trim((string) ($server['token'] ?? $server['token_ref'] ?? '')) !== '';
        unset($server['token'], $server['token_ref'], $server['tls_cert_pem']);
        $server['has_token'] = $hasToken;
        // Ringkasan kompatibilitas versi (F4 lanjutan) untuk halaman Servers:
        // `ok`/`errors`/`warnings` + versi yang terlibat. Murni turunan dokumen
        // server (tanpa jaringan, tanpa rahasia) — server lokal selalu `ok`.
        $server['agent_compat'] = AgentVersionGate::check($server);

        return $server;
    }

    /**
     * Dokumen server untuk form edit: token **dikosongkan** (rahasia tidak
     * dirender; kosong saat submit = tidak diubah), PEM dibiarkan apa adanya
     * (sertifikat self-signed bukan rahasia, plan §11.5) + `has_token`.
     *
     * @param array<string,mixed>|null $server
     * @param bool|null                $hasToken penanda "token sudah terpasang";
     *                                           `null` = disimpulkan dari dokumen
     *                                           (dipakai saat form dirender ulang
     *                                           setelah update gagal, karena input
     *                                           lama tokennya memang kosong)
     * @return array<string,mixed>|null
     */
    private function formServer(?array $server, ?bool $hasToken = null): ?array
    {
        if ($server === null) {
            return null;
        }

        $hasToken ??= trim((string) ($server['token'] ?? $server['token_ref'] ?? '')) !== '';
        unset($server['token_ref']);
        // Rahasia tidak pernah dirender: token dikosongkan (kosong saat submit =
        // tidak diubah; nilai tersimpan tetap dipertahankan controller).
        $server['token'] = '';
        $server['has_token'] = $hasToken;

        return $server;
    }

    /**
     * @param array<string,mixed>|null $server   dokumen (edit) atau input lama (form gagal)
     * @param array<int,string>        $errors
     * @param bool|null                $hasToken penanda token tersimpan (lihat `formServer()`)
     * @return array<string,mixed>
     */
    private function formVars(?array $server, array $errors, bool $isEdit, ?bool $hasToken = null): array
    {
        return [
            'server' => $isEdit ? $this->formServer($server, $hasToken) : $server,
            'errors' => $errors,
            'isEdit' => $isEdit,
            'active' => 'servers',
        ];
    }

    // ==================================================================
    // Respons
    // ==================================================================

    /**
     * Penjagaan hak akses: **non-admin ⇒ 404** (bukan 403, tanpa membocorkan
     * keberadaan registry server). `null` = boleh lanjut.
     */
    private function guard(Request $request): ?Response
    {
        try {
            ServerAccess::requireManageServers($this->currentUser());
        } catch (AppAccessDenied $e) {
            return $e->render($request) ?? response('', 404)->withStatus(404);
        }

        return null;
    }

    /**
     * Form gagal validasi: JSON 422 untuk pemanggil AJAX, selain itu form dirender
     * ulang dengan pesan galat + input lama.
     *
     * @param array<string,mixed> $input
     */
    private function invalidForm(
        Request $request,
        array $input,
        bool $isEdit,
        string $message,
        ?bool $hasToken = null,
    ): Response {
        if ($request->expectsJson()) {
            return json(['code' => 1, 'error' => $message])->withStatus(422);
        }

        return $this->renderView('server/form', $this->formVars($input, [$message], $isEdit, $hasToken));
    }

    /**
     * Aksi yang sah secara hak akses tetapi tidak boleh dilakukan: flash error +
     * redirect (JSON 422 untuk pemanggil AJAX). Tidak ada efek samping.
     */
    private function rejected(Request $request, string $message, ?string $redirectTo = null): Response
    {
        if ($request->expectsJson()) {
            return json(['code' => 1, 'error' => $message])->withStatus(422);
        }

        $this->flash('error', $message);

        return redirect($redirectTo ?? ('/servers/' . ServerStore::LOCAL_ID));
    }

    private function notFound(Request $request): Response
    {
        if ($request->expectsJson()) {
            return json(['code' => 1, 'error' => 'Server tidak ditemukan.'])->withStatus(404);
        }

        return response('', 404)->withStatus(404);
    }

    /**
     * Simpan hasil observasi koneksi dan kembalikan bentuk ringkasnya untuk UI.
     *
     * `$agentVersion`/`$capabilities` `null` = **tidak** ditulis (dipakai saat
     * gagal: nilai terakhir yang diketahui dibiarkan, tidak dikosongkan).
     *
     * @param array<int,string>|null $capabilities
     * @return array<string,mixed>
     */
    private function recordResult(
        string $id,
        string $status,
        ?string $agentVersion,
        ?array $capabilities,
        ?string $minControlPlaneVersion = null,
    ): array {
        $seenAt = date('c');

        $this->servers()->update($id, static function (array &$row) use ($status, $agentVersion, $capabilities, $minControlPlaneVersion, $seenAt): void {
            $row['status'] = $status;
            if ($agentVersion !== null && $agentVersion !== '') {
                $row['agent_version'] = $agentVersion;
            }
            if ($capabilities !== null) {
                $row['capabilities'] = $capabilities;
            }
            if ($minControlPlaneVersion !== null && $minControlPlaneVersion !== '') {
                $row['min_control_plane_version'] = $minControlPlaneVersion;
            }
            if ($status === 'online') {
                $row['last_seen_at'] = $seenAt;
            }
        });

        $stored = $this->servers()->find($id) ?? [];

        return [
            'id' => $id,
            'status' => $status,
            'agent_version' => (string) ($stored['agent_version'] ?? ''),
            'capabilities' => $this->capabilities($stored['capabilities'] ?? []),
            'min_control_plane_version' => (string) ($stored['min_control_plane_version'] ?? ''),
            'last_seen_at' => (string) ($stored['last_seen_at'] ?? ''),
        ];
    }

    /**
     * Daftar capabilities dari respons `/version` (array string; entri lain
     * dibuang) — dipakai control plane untuk menolak op yang tidak didukung.
     *
     * @return array<int,string>
     */
    private function capabilities(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Pesan galat koneksi dalam bahasa Indonesia + petunjuk tindakan.
     *
     * Pesan `AgentError` sudah disanitasi pemiliknya (token/signature di-redaksi);
     * sanitasi diulang di sini sebagai sabuk pengaman supaya nilai token yang
     * tersimpan **tidak pernah** bisa ikut terkirim ke UI.
     *
     * @param array<string,mixed> $server
     */
    private function friendlyError(AgentError $e, array $server): string
    {
        $detail = AgentError::sanitize($e->getMessage(), trim((string) ($server['token'] ?? '')));

        $hint = match ($e->reason) {
            AgentError::UNREACHABLE => 'Agent tidak bisa dihubungi (koneksi/timeout/DNS). Periksa URL, apakah container agent hidup, dan firewall allowlist IP dashboard.',
            AgentError::TLS_FAILED => 'Verifikasi TLS gagal: sertifikat agent kemungkinan berubah. Bandingkan fingerprint di form edit server dengan keluaran agent/install.sh di server itu, lalu tempel PEM sertifikat yang baru.',
            AgentError::UNAUTHORIZED => 'Token agent ditolak. Salin ulang token dari keluaran agent/install.sh di server itu (token hanya ditampilkan sekali saat instalasi).',
            AgentError::NOT_FOUND => 'Endpoint agent tidak ditemukan (404) — versi agent mungkin tidak cocok dengan control plane ini.',
            AgentError::UNSUPPORTED => 'Agent tidak mendukung permintaan ini (versi agent terlalu lama?).',
            AgentError::SERVER_ERROR => 'Agent gagal memproses permintaan (5xx). Periksa log agent di server itu.',
            AgentError::BAD_RESPONSE => 'Respons agent tidak sesuai kontrak (bukan JSON). Periksa versi agent.',
            AgentError::INVALID_CONFIG => 'Konfigurasi server cacat sehingga panggilan ke agent dibatalkan.',
            default => 'Gagal menghubungi agent.',
        };

        return $detail === '' ? $hint : $hint . ' Detail: ' . $detail;
    }

    // ==================================================================
    // Seam & dependensi
    // ==================================================================

    /** User yang sedang login (seam; default membaca session). */
    protected function currentUser(): ?array
    {
        return current_user();
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

    /** Klien agent (seam pengujian: handler Guzzle tiruan + cert di path temp). */
    protected function agentClient(array $server): AgentClient
    {
        return new AgentClient($server, null, AgentClient::DEFAULT_TIMEOUT, $this->pinMaterializer());
    }

    /**
     * Deployer agent untuk satu dokumen server (seam pengujian) — pola yang sama
     * dengan `BackupController::agentDeployerForServer()`. `null` = server lokal.
     *
     * @param array<string,mixed> $server
     */
    protected function agentDeployerForServer(array $server): ?AgentDeployer
    {
        if (ServerResolver::isLocal($server)) {
            return null;
        }

        return AgentDeployer::forServer($server, $this->agentClient($server));
    }

    /** Materializer sertifikat pin (seam pengujian: direktori temp). */
    protected function pinMaterializer(): TlsPinMaterializer
    {
        return $this->pins ?? new TlsPinMaterializer();
    }

    private function servers(): ServerStore
    {
        return $this->servers ?? new ServerStore();
    }

    private function resolver(): ServerResolver
    {
        return $this->resolver ?? new ServerResolver($this->servers());
    }

    /** Nama sudah dipakai server **lain**? (keunikan nama = global di store). */
    private function nameTakenByOther(string $name, string $id): bool
    {
        $other = $this->servers()->findByName($name);

        return $other !== null && (string) ($other['id'] ?? '') !== $id;
    }
}
