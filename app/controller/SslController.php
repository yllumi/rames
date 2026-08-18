<?php
declare(strict_types=1);

namespace app\controller;

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
 */
class SslController
{
    public function index(Request $request)
    {
        $store = new AppStore();
        $apps = $store->all();

        // SSL hanya didukung bila APP_DOMAIN adalah domain publik (mis. bukan .local)
        $sslSupported = SslIssuer::isPublicDomain((string) config('deploy.app_domain', ''));

        $rows = [];
        foreach ($apps as $app) {
            $subdomain = app_subdomain($app['name']);
            $customDomain = (string) ($app['custom_domain'] ?? '');

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

        return view('ssl/index', [
            'rows' => $rows,
            'sslSupported' => $sslSupported,
        ]);
    }

    /**
     * Aktifkan SSL untuk sebuah domain milik app.
     *
     * Domain target dikirim via body (`domain`); bila kosong, default ke custom
     * domain bila ada, selain itu subdomain bawaan. Penerbitan dijalankan
     * asinkron oleh worker cli/ssl.php (detached).
     */
    public function enable(Request $request, string $id)
    {
        $store = new AppStore();
        $app = $store->find($id);
        if ($app === null) {
            flash_set('error', 'App tidak ditemukan.');
            return redirect('/ssl');
        }

        $subdomain = app_subdomain($app['name']);
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
     */
    private function spawnWorker(string $appId, string $domain): bool
    {
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
