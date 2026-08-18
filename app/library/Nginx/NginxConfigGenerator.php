<?php
declare(strict_types=1);

namespace app\library\Nginx;

use RuntimeException;

/**
 * Generate & tulis config Nginx per app (SPECS.md §8.2).
 *
 * Dashboard hanya menulis file ke direktori yang di-mount; reload (nginx -t &&
 * nginx -s reload) dijalankan oleh watcher di host (SPECS.md §8.3).
 */
class NginxConfigGenerator
{
    public function __construct(
        private readonly string $confPath,
        private readonly string $enabledPath = '',
    ) {
    }

    /**
     * Render config Nginx untuk satu app. Satu file .conf bisa memuat banyak
     * server block (subdomain + custom domain), masing-masing dengan peran:
     *
     *   - serve block     : `{server_name, ssl}` — proxy ke app; bila `ssl=true`
     *                       render juga blok `listen 443 ssl` + redirect 80→https.
     *   - redirect block  : `{server_name, redirect_to}` — hanya `return 301
     *                       {redirect_to}$request_uri` (mis. subdomain → custom
     *                       domain). `location /.well-known/acme-challenge/`
     *                       tetap dirender SEBELUM return agar HTTP-01 tetap jalan.
     *
     * @param array<int,array{server_name:string,ssl?:bool,redirect_to?:string}> $servers
     * @return string
     */
    public function render(int $hostPort, array $servers): string
    {
        $webroot = (string) config('deploy.ssl_webroot', base_path() . '/webroot');
        $lePath = (string) config('deploy.letsencrypt_path', '/etc/letsencrypt');
        $acme = <<<ACME
    location ^~ /.well-known/acme-challenge/ {
        root {$webroot};
    }
ACME;

        $blocks = [];
        foreach ($servers as $entry) {
            $serverName = (string) ($entry['server_name'] ?? '');
            $redirectTo = ($entry['redirect_to'] ?? null) !== null ? (string) $entry['redirect_to'] : null;
            $ssl = (bool) ($entry['ssl'] ?? false);

            // Redirect block (mis. subdomain → custom domain)
            if ($redirectTo !== null && $redirectTo !== '') {
                $blocks[] = <<<NGINX
server {
    listen 80;
    server_name {$serverName};

    {$acme}

    location / {
        return 301 {$redirectTo}\$request_uri;
    }
}
NGINX;
                continue;
            }

            // Serve block HTTP (80)
            $httpBlock = <<<NGINX
server {
    listen 80;
    server_name {$serverName};

    {$acme}

    location / {
        proxy_pass http://127.0.0.1:{$hostPort};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX;

            if (!$ssl) {
                $blocks[] = $httpBlock;
                continue;
            }

            // Serve block dengan SSL: 80 → redirect https; 443 ssl serve app
            $cert = $lePath . '/live/' . $serverName . '/fullchain.pem';
            $key = $lePath . '/live/' . $serverName . '/privkey.pem';

            $blocks[] = <<<NGINX
server {
    listen 80;
    server_name {$serverName};

    {$acme}

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    server_name {$serverName};

    ssl_certificate {$cert};
    ssl_certificate_key {$key};
    ssl_protocols TLSv1.2 TLSv1.3;

    location / {
        proxy_pass http://127.0.0.1:{$hostPort};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX;
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * Cek prasyarat tulis direktori config Nginx (fail-fast sebelum deploy).
     * Pesan error dibuat jelas & actionable.
     */
    public function ensureWritable(): void
    {
        if (!is_dir($this->confPath)) {
            throw new RuntimeException(
                "Direktori config Nginx tidak ditemukan: {$this->confPath}. " .
                'Pastikan direktori tersebut tersedia / ter-mount di dashboard.'
            );
        }
        if (!is_writable($this->confPath)) {
            throw new RuntimeException($this->permissionHint());
        }
        if ($this->enabledPath !== '' && is_dir($this->enabledPath) && !is_writable($this->enabledPath)) {
            throw new RuntimeException($this->permissionHint());
        }
    }

    public function write(string $name, string $content): void
    {
        $this->ensureWritable();
        $file = $this->confPath . '/' . $name . '.conf';
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            $last = error_get_last();
            $detail = is_array($last) ? (string) ($last['message'] ?? '') : '';
            throw new RuntimeException(
                'Gagal menulis config Nginx: ' . $file .
                ($detail !== '' ? ' — ' . $detail : ' — periksa izin tulis direktori.')
            );
        }
        $this->enable($name, $file);
    }

    private function permissionHint(): string
    {
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '')
            : (string) (getenv('USER') ?: '');
        $hint = 'Dashboard tidak punya izin tulis ke ' . $this->confPath . '.';
        if ($user !== '') {
            $hint .= ' Jalankan dashboard sebagai user yang punya akses (mis. via docker-compose yang berjalan sebagai root), ' .
                'atau beri izin tulis untuk user "' . $user . '": sudo chown -R ' . $user . ' ' . $this->confPath .
                ($this->enabledPath !== '' ? ' ' . $this->enabledPath : '');
        }
        return $hint;
    }

    public function remove(string $name): void
    {
        $file = $this->confPath . '/' . $name . '.conf';
        if (is_file($file)) {
            @unlink($file);
        }
        if ($this->enabledPath !== '') {
            $link = $this->enabledPath . '/' . $name . '.conf';
            if (is_link($link) || file_exists($link)) {
                @unlink($link);
            }
        }
    }

    private function enable(string $name, string $file): void
    {
        if ($this->enabledPath === '' || !is_dir($this->enabledPath)) {
            return; // setup host tanpa pemisahan sites-enabled -> lewati symlink
        }
        $link = $this->enabledPath . '/' . $name . '.conf';
        if (!file_exists($link)) {
            @symlink($file, $link);
        }
    }
}
