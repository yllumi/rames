<?php
declare(strict_types=1);

namespace app\controller;

use app\library\SSL\SslIssuer;
use app\library\Storage\SiteStore;
use support\Request;

/**
 * Halaman SSL otomatis (Let's Encrypt) — SPECS.md §8a.
 *
 * Menampilkan daftar domain (= subdomain tiap site) beserta status SSL dan
 * tombol "Aktifkan SSL" / "Retry". Penerbitan dijalankan asinkron oleh worker
 * cli/ssl.php (detached). Controller hanya mediator — tidak ada logika bisnis.
 */
class SslController
{
    public function index(Request $request)
    {
        $store = new SiteStore();
        $sites = $store->all();

        // SSL hanya didukung bila APP_DOMAIN adalah domain publik (mis. bukan .local)
        $sslSupported = SslIssuer::isPublicDomain((string) config('deploy.app_domain', ''));

        $rows = [];
        foreach ($sites as $site) {
            $rows[] = [
                'site' => $site,
                'domain' => site_subdomain($site['name']),
                'ssl_status' => (string) ($site['ssl_status'] ?? 'disabled'),
                'ssl_expires_at' => $site['ssl_expires_at'] ?? null,
                'ssl_error' => $site['ssl_error'] ?? null,
            ];
        }

        return view('ssl/index', [
            'rows' => $rows,
            'sslSupported' => $sslSupported,
        ]);
    }

    public function enable(Request $request, string $id)
    {
        $store = new SiteStore();
        $site = $store->find($id);
        if ($site === null) {
            flash_set('error', 'Site tidak ditemukan.');
            return redirect('/ssl');
        }

        $domain = site_subdomain($site['name']);
        if (!SslIssuer::isPublicDomain($domain)) {
            flash_set('error', "Domain {$domain} bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).");
            return redirect('/ssl');
        }

        $status = (string) ($site['ssl_status'] ?? 'disabled');
        if (in_array($status, ['active', 'pending'], true)) {
            flash_set('info', "SSL untuk {$domain} sudah {$status}.");
            return redirect('/ssl');
        }

        $store->update($id, function (array &$s): void {
            $s['ssl_status'] = 'pending';
            $s['ssl_stage'] = 'queued';
            $s['ssl_message'] = 'Menunggu worker SSL ...';
            $s['ssl_error'] = null;
            $s['needs_ssl'] = true;
        });

        if (!$this->spawnWorker($id)) {
            $store->update($id, function (array &$s): void {
                $s['ssl_status'] = 'failed';
                $s['ssl_stage'] = null;
                $s['ssl_message'] = null;
                $s['ssl_error'] = 'Gagal spawn worker SSL. Cek log runtime/logs/ssl/{id}.log.';
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
    private function spawnWorker(string $siteId): bool
    {
        $logDir = runtime_path('logs/ssl');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/' . $siteId . '.log';

        $command = [PHP_BINARY, base_path('cli/ssl.php'), $siteId];
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
