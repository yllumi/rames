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
     * Blok rute proxy tambahan (`$routes`, lihat {@see NginxRoutes}) dirender
     * SETELAH `location / { ... }` **hanya** di serve block (HTTP 80 & HTTPS 443)
     * — tidak di redirect block / blok 80→https, karena blok-blok itu tidak
     * mem-proxy ke app.
     *
     * @param array<int,array{server_name:string,ssl?:bool,redirect_to?:string}> $servers
     * @param array<int,array{path:string,target:string}> $routes
     * @return string
     */
    public function render(int $hostPort, array $servers, array $routes = []): string
    {
        // Validasi fail-fast: rute tak valid tidak boleh masuk config Nginx
        // (satu config rusak menggagalkan reload SELURUH host). all() sudah
        // menyaring data apps.json, jadi umumnya di sini tidak ada yang gagal.
        $routes = NginxRoutes::normalize($routes);
        $routeSuffix = $routes === [] ? '' : "\n\n" . $this->routeBlocks($routes);

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

        # Teruskan WebSocket (Upgrade) ke app: HTTP/1.1 + header Upgrade/Connection.
        # Untuk request HTTP biasa \$http_upgrade kosong, sehingga nginx tidak
        # mengirim header Upgrade & request tetap berjalan normal.
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }{$routeSuffix}
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

        # Teruskan WebSocket (Upgrade) ke app: HTTP/1.1 + header Upgrade/Connection.
        # Untuk request HTTP biasa \$http_upgrade kosong, sehingga nginx tidak
        # mengirim header Upgrade & request tetap berjalan normal.
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }{$routeSuffix}
}
NGINX;
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * Blok `location ^~ {path} { proxy_pass {target}; ... }` (indent 4 spasi).
     *
     * `^~` dipakai agar rute tidak pernah kalah dari regex location milik app
     * di belakang proxy; direktif WebSocket disertakan supaya app real-time di
     * target rute (mis. gateway API) tetap dapat Upgrade.
     *
     * @param array<int,array{path:string,target:string}> $routes
     */
    private function routeBlocks(array $routes): string
    {
        $blocks = [];
        foreach ($routes as $route) {
            $path = $route['path'];
            $target = $route['target'];
            $blocks[] = <<<NGINX
    location ^~ {$path} {
        proxy_pass {$target};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }
NGINX;
        }

        return implode("\n\n", $blocks);
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
