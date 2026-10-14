<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Auth\AppAccess;
use app\library\Auth\AppAccessDenied;
use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Docker\AppPorts;
use app\library\SSL\SslIssuer;
use app\library\Storage\AppStore;
use support\Request;

/**
 * Halaman SSL otomatis (Let's Encrypt) — SPECS.md §8a & §8b.
 *
 * Menampilkan daftar domain (subdomain bawaan app, atau custom domain bila
 * di-set — subdomain hanya redirect saat custom domain aktif) beserta status
 * SSL dan tombol "Aktifkan SSL" / "Retry". Penerbitan dijalankan asinkron oleh
 * worker cli/ssl.php (detached). Controller hanya mediator — tidak ada logika.
 *
 * **Server-aware (multi-server F2):** sertifikat diterbitkan **di server pemilik
 * app**. `enable()` menolak lebih awal bila server `kind=agent` belum mengisi
 * `APP_DOMAIN`-nya (`domain`) — vhost & permintaan sertifikat akan menunjuk
 * domain yang salah. Eksekusi tetap milik worker `cli/ssl.php`: untuk app di
 * server agent worker itu mengirim job `ssl_issue` ke server tersebut (certbot +
 * `/etc/letsencrypt` server itu), sedangkan app lokal tetap certbot di host ini.
 *
 * Seam uji `$spawn` (nullable, pola `AgentJobController`): menggantikan
 * peluncuran worker SSL supaya uji tidak pernah menjalankan certbot nyata.
 */
class SslController
{
    /** @var \Closure(string,string):bool|null seam uji: (appId, domain) => berhasil? */
    private ?\Closure $spawn;

    public function __construct(?\Closure $spawn = null)
    {
        $this->spawn = $spawn;
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
        $sslSupported = SslIssuer::isPublicDomain((string) config('deploy.app_domain', ''));

        $rows = [];
        $anyPublicRow = false;
        foreach ($apps as $app) {
            // App tanpa port terpublish tidak di-proxy Nginx (vhost/subdomain
            // dilewati saat deploy) → tidak ada domain yang bisa di-SSL.
            if (!AppPorts::hasHostPort($app)) {
                continue;
            }

            $subdomain = app_subdomain_of($app);
            $customDomain = (string) ($app['custom_domain'] ?? '');

            if (SslIssuer::isPublicDomain($customDomain !== '' ? $customDomain : $subdomain)) {
                $anyPublicRow = true;
            }

            // Bila custom domain di-set, subdomain hanya redirect → SSL yang
            // relevan adalah SSL custom domain (subdomain tidak lagi melayani app).
            if ($customDomain !== '') {
                $rows[] = [
                    'app' => $app,
                    'domain' => $customDomain,
                    'is_custom' => true,
                    'ssl_status' => (string) ($app['custom_ssl_status'] ?? 'disabled'),
                    'ssl_message' => $app['custom_ssl_message'] ?? null,
                    'ssl_expires_at' => $app['custom_ssl_expires_at'] ?? null,
                    'ssl_error' => $app['custom_ssl_error'] ?? null,
                ];
            } else {
                $rows[] = [
                    'app' => $app,
                    'domain' => $subdomain,
                    'is_custom' => false,
                    'ssl_status' => (string) ($app['ssl_status'] ?? 'disabled'),
                    'ssl_message' => $app['ssl_message'] ?? null,
                    'ssl_expires_at' => $app['ssl_expires_at'] ?? null,
                    'ssl_error' => $app['ssl_error'] ?? null,
                ];
            }
        }

        // Dukungan SSL menyesuaikan domain baris; bila tidak ada baris sama
        // sekali (mis. semua app tanpa port host) perilaku lama dipertahankan
        // lewat `APP_DOMAIN` control plane.
        return [
            'rows' => $rows,
            'sslSupported' => $rows === [] ? $sslSupported : $anyPublicRow,
        ];
    }

    /**
     * Aktifkan SSL untuk sebuah domain milik app.
     *
     * Domain target dikirim via body (`domain`); bila kosong, default ke custom
     * domain bila ada, selain itu subdomain bawaan. Penerbitan dijalankan
     * asinkron oleh worker cli/ssl.php (detached) — worker itu yang memutuskan
     * **server mana** yang menjalankan certbot (app di server agent ⇒ job
     * `ssl_issue` di server itu).
     */
    public function enable(Request $request, string $id)
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
            flash_set('error', 'App ini tidak mem-publish port host — tidak ada domain yang bisa di-SSL. Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.');
            return redirect('/ssl');
        }

        // Fail-fast SEBELUM efek samping apa pun (status/worker): server agent
        // tanpa APP_DOMAIN (`domain`) tidak boleh dimintai sertifikat — domain
        // efektifnya akan diisi domain server lain dan sertifikatnya salah.
        if (!ServerResolver::isLocal($server)) {
            try {
                AgentDeployer::assertDomainBase($server, $app);
            } catch (\RuntimeException $e) {
                flash_set('error', $e->getMessage());
                return redirect('/ssl');
            }
        }

        $subdomain = app_subdomain_of($app);
        $customDomain = (string) ($app['custom_domain'] ?? '');
        $domain = strtolower(trim((string) $request->post('domain', '')));

        if ($domain === '') {
            $domain = $customDomain !== '' ? $customDomain : $subdomain;
        }
        if ($domain !== $subdomain && $domain !== $customDomain) {
            flash_set('error', "Domain {$domain} bukan milik app ini.");
            return redirect('/ssl');
        }

        if (!SslIssuer::isPublicDomain($domain)) {
            flash_set('error', "Domain {$domain} bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).");
            return redirect('/ssl');
        }

        $slot = ($domain === $customDomain && $customDomain !== '') ? 'custom_ssl' : 'ssl';
        $status = (string) ($app[$slot . '_status'] ?? 'disabled');
        if (in_array($status, ['active', 'pending'], true)) {
            flash_set('info', "SSL untuk {$domain} sudah {$status}.");
            return redirect('/ssl');
        }

        $store->update($id, function (array &$s) use ($slot): void {
            $s[$slot . '_status'] = 'pending';
            $s[$slot . '_stage'] = 'queued';
            $s[$slot . '_message'] = 'Menunggu worker SSL ...';
            $s[$slot . '_error'] = null;
            $s[$slot === 'custom_ssl' ? 'custom_needs_ssl' : 'needs_ssl'] = true;
        });

        if (!$this->spawnWorker($id, $domain)) {
            $store->update($id, function (array &$s) use ($slot): void {
                $s[$slot . '_status'] = 'failed';
                $s[$slot . '_stage'] = null;
                $s[$slot . '_message'] = null;
                $s[$slot . '_error'] = 'Gagal spawn worker SSL. Cek log runtime/logs/ssl/{id}.log.';
            });
            flash_set('error', 'Gagal menjalankan worker SSL.');
        } else {
            flash_set('success', "Penerbitan SSL untuk {$domain} dijalankan.");
        }
        return redirect('/ssl');
    }

    /**
     * Spawn background worker SSL (detached), stdout/stderr -> log file.
     *
     * Seam uji (`$this->spawn`) menggantikan peluncuran proses nyata — uji tidak
     * pernah menjalankan certbot. Jalur produksi tetap `proc_open` +
     * `bypass_shell` (array argumen, tanpa shell), bentuk yang sama seperti
     * sebelum multi-server.
     */
    private function spawnWorker(string $appId, string $domain): bool
    {
        if ($this->spawn !== null) {
            return (bool) ($this->spawn)($appId, $domain);
        }

        $logDir = runtime_path('logs/ssl');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/' . $appId . '.log';

        $command = [PHP_BINARY, base_path('cli/ssl.php'), $appId, $domain];
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
}
