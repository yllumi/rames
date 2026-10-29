<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\AppPorts;
use app\library\SSL\SslDomainRows;
use app\library\SSL\SslErrorSummary;
use app\library\SSL\SslIssuer;
use app\library\SSL\SslQueue;
use app\library\Storage\AppStore;
use support\Request;
use Webman\Http\Response;

/**
 * Halaman SSL otomatis (Let's Encrypt) — docs/SPECS.md §8a & §8b.
 *
 * Menampilkan daftar domain (subdomain bawaan app, atau custom domain bila
 * di-set — subdomain hanya redirect saat custom domain aktif) beserta status
 * SSL dan tombol "Aktifkan SSL" / "Retry". Penerbitan **tidak** dijalankan
 * langsung: permintaan masuk antrean global ({@see SslQueue}) dan dieksekusi
 * satu per satu oleh proses `ssl-queue` (worker `cli/ssl.php`). Controller
 * hanya mediator — tidak ada logika.
 *
 * **Server-aware (multi-server F2):** sertifikat diterbitkan **di server pemilik
 * app**. `enable()` menolak lebih awal bila server `kind=agent` belum mengisi
 * `APP_DOMAIN`-nya (`domain`) — vhost & permintaan sertifikat akan menunjuk
 * domain yang salah. Eksekusi tetap milik worker `cli/ssl.php`: untuk app di
 * server agent worker itu mengirim job `ssl_issue` ke server tersebut (certbot +
 * `/etc/letsencrypt` server itu), sedangkan app lokal tetap certbot di host ini.
 *
 * Seam uji `$queue` (nullable): menggantikan antrean nyata (basis data temp)
 * supaya uji tidak pernah menjalankan certbot maupun menyentuh store nyata.
 *
 * **Mode AJAX (JSON, tanpa reload halaman):** `apiEnable()` menjalankan inti
 * yang sama dengan `enable()` (`enableDomain()`) lalu merespons envelope JSON,
 * sedangkan `apiStatus()` mengembalikan baris `sslRows()` untuk memperbarui
 * tabel `/ssl` di tempat. Otorisasinya sama (`AppAccess`); `apiStatus()` murni
 * membaca store — tidak menyentuh Docker/agent.
 */
class SslController
{
    /** Seam uji: antrean SSL (null ⇒ store produksi via `config`). */
    private ?SslQueue $queue;

    public function __construct(?SslQueue $queue = null)
    {
        $this->queue = $queue;
    }

    public function index(Request $request)
    {
        $store = new AppStore();
        // Hanya app yang boleh diakses user ini (admin: semua app).
        $apps = AppAccess::visible($store->all(), current_user());

        $table = $this->sslRows($apps);

        return view('ssl/index', [
            'rows' => $table['rows'],
            'sslSupported' => $table['sslSupported'],
            'isAdmin' => is_admin(),
            'ownerNames' => is_admin() ? user_names() : [],
        ]);
    }

    /**
     * Baris tabel SSL + dukungan SSL.
     *
     * Domain efektif diambil **per server pemilik app** (`app_subdomain_of()` ⇒
     * `ServerResolver::domainForAppOrLocal()`), bukan dari `APP_DOMAIN` control
     * plane. Dukungan SSL mengikuti domain baris (satu server bisa ber-domain
     * publik sementara server lain tidak); bila tidak ada baris sama sekali
     * (semua app tanpa port host) perilaku lama dipertahankan lewat `APP_DOMAIN`
     * control plane. Dipisah dari `index()` supaya mediator ini tetap tipis dan
     * bisa diuji tanpa merender view.
     *
     * @param array<int,array<string,mixed>> $apps
     * @return array{rows:array<int,array<string,mixed>>,sslSupported:bool}
     */
    private function sslRows(array $apps): array
    {
        // Bentuk baris dimiliki `SslDomainRows` (satu sumber dengan halaman
        // `/domains`); controller hanya menghitung dukungan SSL dari domain
        // baris publik.
        $rows = SslDomainRows::appRows($apps);

        $anyPublicRow = false;
        foreach ($rows as $row) {
            if (SslIssuer::isPublicDomain((string) ($row['domain'] ?? ''))) {
                $anyPublicRow = true;
                break;
            }
        }

        // Dukungan SSL menyesuaikan domain baris; bila tidak ada baris sama
        // sekali (mis. semua app tanpa port host) perilaku lama dipertahankan
        // lewat `APP_DOMAIN` control plane.
        return [
            'rows' => $rows,
            'sslSupported' => $rows === []
                ? SslIssuer::isPublicDomain((string) config('deploy.app_domain', ''))
                : $anyPublicRow,
        ];
    }

    /**
     * Aktifkan SSL untuk sebuah domain milik app.
     *
     * Domain target dikirim via body (`domain`); bila kosong, default ke custom
     * domain bila ada, selain itu subdomain bawaan. Permintaan masuk **antrean
     * SSL global** dan dieksekusi satu per satu oleh proses `ssl-queue` (worker
     * `cli/ssl.php`) — worker itu yang memutuskan **server mana** yang
     * menjalankan certbot (app di server agent ⇒ job `ssl_issue` di server itu).
     */
    public function enable(Request $request, string $id)
    {
        $result = $this->enableDomain($request, $id);

        flash_set($result['flash'], $result['message']);

        // Tabel SSL sekarang menyatu di halaman domain (`/domains`).
        return redirect('/domains');
    }

    /**
     * Aktifkan SSL via JSON (AJAX) — `POST /api/ssl/{id}/enable`.
     *
     * Inti kerjanya persis `enable()` (`enableDomain()`), hanya responsnya beda:
     * envelope JSON `{code:0,...}` saat diterima, `{code:1,...}` untuk penolakan
     * bisnis apa pun (HTTP **200** — bukan error server, JS memutuskan dari
     * `code`/`error`). App tak ada / tanpa ability `ssl` melempar
     * `AppAccessDenied` → 404 JSON `{"code":404,...}` oleh handler webman.
     *
     * CSRF tidak dikecualikan: JS wajib mengirim `_token` (middleware global).
     */
    public function apiEnable(Request $request, string $id): Response
    {
        $result = $this->enableDomain($request, $id);

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
     * Baris tabel SSL sebagai JSON — `GET /api/ssl/status` (AJAX, tanpa reload).
     *
     * Otorisasi identik `index()`: `AppAccess::visible()` + `sslRows()` (satu
     * sumber logika domain/`hasHostPort`, tidak diduplikasi). Read-only: hanya
     * membaca store; Docker/agent tidak disentuh.
     */
    public function apiStatus(Request $request): Response
    {
        $store = new AppStore();
        // Hanya app yang boleh diakses user ini (admin: semua app).
        $apps = AppAccess::visible($store->all(), current_user());
        $table = $this->sslRows($apps);

        $rows = [];
        $pending = false;
        foreach ($table['rows'] as $row) {
            $status = (string) $row['ssl_status'];
            $pending = $pending || $status === 'pending';
            $app = is_array($row['app'] ?? null) ? $row['app'] : [];
            $rows[] = [
                'app_id' => (string) ($app['id'] ?? ''),
                'app_name' => (string) ($app['name'] ?? ''),
                'domain' => (string) $row['domain'],
                'is_custom' => (bool) $row['is_custom'],
                'status' => $status,
                'message' => $row['ssl_message'],
                'expires_at' => $row['ssl_expires_at'],
                'error' => $row['ssl_error'],
                'error_summary' => SslErrorSummary::short(
                    is_string($row['ssl_error'] ?? null) ? $row['ssl_error'] : null
                ),
                'can_ssl' => AppAccess::can('ssl', $app, current_user()),
                // Kunci baru (halaman `/domains` menyatukan app + parkir):
                // `kind` = app|parked, `role` = serve|redirect|parkir.
                'kind' => (string) ($row['kind'] ?? 'app'),
                'role' => (string) ($row['role'] ?? 'serve'),
                // Posisi antrean SSL (hanya saat `pending`; null selain itu).
                // Field ini dibaca `public/js/ssl.js` untuk catatan baris.
                'ssl_queue_position' => isset($row['ssl_queue_position']) ? (int) $row['ssl_queue_position'] : null,
                'ssl_queue_size' => isset($row['ssl_queue_size']) ? (int) $row['ssl_queue_size'] : null,
            ];
        }

        return json([
            'code' => 0,
            'ssl_supported' => $table['sslSupported'],
            'pending' => $pending,
            'rows' => $rows,
        ]);
    }

    /**
     * Inti `enable()` / `apiEnable()`: validasi → efek samping (status store +
     * enqueue job SSL) → hasil terstruktur. Satu implementasi untuk dua respons
     * (halaman & JSON) supaya validasinya tidak bisa menyimpang.
     *
     * Hasil:
     *   ok      — permintaan diterima & job masuk antrean SSL
     *   error   — pesan penolakan untuk user (= `message`, null saat diterima)
     *   status  — `pending` (diterima) | `rejected` | `already` | `failed`
     *   domain  — domain target; `''` bila belum sempat dihitung (ditolak karena
     *             port host/domain server)
     *   message — teks flash, termasuk posisi antrean
     *   flash   — tipe flash: `success` | `info` | `error`
     *
     * Efek samping hanya pada jalur diterima / gagal-enqueue, sama seperti
     * sebelum antrean (fail-fast lain tidak menyentuh store).
     *
     * @return array{ok:bool,error:?string,status:string,domain:string,message:string,flash:string}
     * @throws AppAccessDenied app tidak ada / user tanpa ability `ssl`
     */
    private function enableDomain(Request $request, string $id): array
    {
        $store = new AppStore();
        $app = $store->find($id);
        if ($app === null) {
            throw new AppAccessDenied('ssl', $id);
        }
        AppAccess::require('ssl', $app, current_user());

        // Server pemilik app (`server_id` tak dikenal ⇒ melempar; fail-fast).
        $server = (new ServerResolver())->serverForApp($app);

        // Tanpa port terpublish tidak ada vhost Nginx → tidak ada yang bisa
        // di-SSL (subdomain app memang tidak dilayani, SPECS §7.2).
        if (!AppPorts::hasHostPort($app)) {
            return $this->rejected('App ini tidak mem-publish port host — tidak ada domain yang bisa di-SSL. Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.');
        }

        // Fail-fast SEBELUM efek samping apa pun (status/worker): server agent
        // tanpa APP_DOMAIN (`domain`) tidak boleh dimintai sertifikat — domain
        // efektifnya akan diisi domain server lain dan sertifikatnya salah.
        if (!ServerResolver::isLocal($server)) {
            try {
                AgentDeployer::assertDomainBase($server, $app);
            } catch (\RuntimeException $e) {
                return $this->rejected($e->getMessage());
            }
        }

        $subdomain = app_subdomain_of($app);
        $customDomain = (string) ($app['custom_domain'] ?? '');
        $domain = strtolower(trim((string) $request->post('domain', '')));

        if ($domain === '') {
            $domain = $customDomain !== '' ? $customDomain : $subdomain;
        }
        if ($domain !== $subdomain && $domain !== $customDomain) {
            return $this->rejected("Domain {$domain} bukan milik app ini.", domain: $domain);
        }

        if (!SslIssuer::isPublicDomain($domain)) {
            return $this->rejected("Domain {$domain} bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).", domain: $domain);
        }

        $slot = ($domain === $customDomain && $customDomain !== '') ? 'custom_ssl' : 'ssl';
        $status = (string) ($app[$slot . '_status'] ?? 'disabled');
        if (in_array($status, ['active', 'pending'], true)) {
            // Status slot yang sudah jalan bukan error — di jalur halaman ini
            // flash `info` (perilaku lama), bukan `error`.
            return $this->rejected("SSL untuk {$domain} sudah {$status}.", 'already', $domain, 'info');
        }

        $store->update($id, function (array &$s) use ($slot): void {
            $s[$slot . '_status'] = 'pending';
            $s[$slot . '_stage'] = 'queued';
            $s[$slot . '_message'] = 'Menunggu antrean SSL ...';
            $s[$slot . '_error'] = null;
            $s[$slot === 'custom_ssl' ? 'custom_needs_ssl' : 'needs_ssl'] = true;
        });

        try {
            // Satu antrean global: certbot memakai lock global + rate limit
            // Let's Encrypt, jadi penerbitan dijalankan satu per satu.
            $enqueued = $this->queue()->enqueueApp($app, $domain, $slot, $this->actorId());
        } catch (\Throwable $e) {
            $this->markFailed($store, $id, $slot, 'Gagal memasukkan permintaan SSL ke antrean: ' . $e->getMessage());

            return $this->rejected('Gagal memasukkan permintaan SSL ke antrean.', 'failed', $domain);
        }

        $position = (int) ($enqueued['position'] ?? 0);
        $size = (int) ($enqueued['size'] ?? 0);

        return [
            'ok' => true,
            'error' => null,
            'status' => 'pending',
            'domain' => $domain,
            'message' => "SSL untuk {$domain} masuk antrean (posisi {$position} dari {$size}).",
            'flash' => 'success',
        ];
    }

    /**
     * Antrean SSL (seam uji: basis data temp).
     */
    private function queue(): SslQueue
    {
        return $this->queue ?? new SslQueue();
    }

    /**
     * Id user yang meminta (audit trail) — null bila di luar sesi HTTP.
     */
    private function actorId(): ?string
    {
        if (!function_exists('current_user')) {
            return null;
        }
        $user = current_user();

        return is_array($user) ? (string) ($user['id'] ?? '') : null;
    }

    /**
     * Tandai slot `failed` + alasan saat permintaan tidak bisa masuk antrean.
     */
    private function markFailed(AppStore $store, string $id, string $slot, string $message): void
    {
        try {
            $store->update($id, function (array &$s) use ($slot, $message): void {
                $s[$slot . '_status'] = 'failed';
                $s[$slot . '_stage'] = null;
                $s[$slot . '_message'] = null;
                $s[$slot . '_error'] = $message;
            });
        } catch (\Throwable) {
            // Status `pending` tanpa job bukan kegagalan yang bisa diperbaiki di
            // sini; pesan penolakan tetap dikembalikan ke user.
        }
    }

    /**
     * Hasil terstruktur penolakan bisnis (tanpa efek samping apa pun).
     *
     * @return array{ok:bool,error:?string,status:string,domain:string,message:string,flash:string}
     */
    private function rejected(string $message, string $status = 'rejected', string $domain = '', string $flash = 'error'): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'status' => $status,
            'domain' => $domain,
            'message' => $message,
            'flash' => $flash,
        ];
    }
}
