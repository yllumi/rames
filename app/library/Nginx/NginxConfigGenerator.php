<?php
declare(strict_types=1);

namespace app\library\Nginx;

use RuntimeException;

/**
 * Generate & tulis config Nginx per app (docs/SPECS.md §8.2).
 *
 * Dashboard hanya menulis file ke direktori yang di-mount; reload (nginx -t &&
 * nginx -s reload) dijalankan oleh watcher di host (docs/SPECS.md §8.3).
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
     *   - redirect block  : `{server_name, redirect_to, ssl?}` — hanya `return 301
     *                       {redirect_to}$request_uri` (mis. subdomain → custom
     *                       domain). `location /.well-known/acme-challenge/`
     *                       tetap dirender SEBELUM return agar HTTP-01 tetap jalan.
     *                       Bila `ssl=true`, blok 80 hanya 301 ke https (ACME tetap
     *                       di 80) dan blok `listen 443 ssl` meneruskan 301 ke
     *                       target — supaya subdomain redirect juga bisa punya
     *                       sertifikatnya sendiri.
     *
     * Blok rute proxy tambahan (`$routes`, lihat {@see NginxRoutes}) dirender
     * SETELAH `location / { ... }` **hanya** di serve block (HTTP 80 & HTTPS 443)
     * — tidak di redirect block / blok 80→https, karena blok-blok itu tidak
     * mem-proxy ke app.
     *
     * Rute memakai semantik **mount subpath**: prefix `path` DILEPAS sebelum
     * diteruskan (`proxy_pass {target}/`), URL balik upstream (Location relatif
     * maupun absolut ke origin target) di-`proxy_redirect` ke prefix, dan
     * upstream menerima prefix lewat `X-Forwarded-Prefix`. Tanpa ini aset &
     * link absolut upstream (yang mengasumsikan dirinya di root `/`) menunjuk
     * ke app utama, bukan rute. Bila `path` = `/dashboard/` maka
     * `/dashboard/app.js` diteruskan sebagai `/app.js` +
     * `X-Forwarded-Prefix: /dashboard`.
     *
     * **`Connection "upgrade"` literal, bukan `map $http_upgrade`.** `map`
     * mendaftarkan variabel baru di konteks `http`; satu map per berkas app
     * membuat host dengan config padat gagal `nginx -t`
     * (`could not build variables_hash, you should increase
     * variables_hash_bucket_size`) — kegagalan yang memblokir reload SELURUH
     * host. Literal ini sama dengan yang dipakai `location /` app (sudah terbukti
     * jalan di host); varian `$connection_upgrade` hanya boleh didefinisikan
     * **sekali** di `nginx.conf` host, di luar kendali dashboard.
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
                if (!$ssl) {
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

                // Redirect + SSL: blok 80 (ACME + 301 ke https) lalu blok 443
                // yang meneruskan 301 ke target. Tidak ada proxy/WebSocket di
                // kedua blok karena tidak ada app di belakangnya.
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
     * Blok rute `location ^~ {path} { ... }` (indent 4 spasi) dengan semantik
     * mount subpath: prefix `path` dilepas (`proxy_pass {target}/`), redirect
     * absolut upstream dikembalikan ke prefix, dan prefix dikirim sebagai
     * `X-Forwarded-Prefix`.
     *
     * `^~` dipertahankan agar rute tidak pernah kalah dari regex location milik
     * app di belakang proxy; direktif WebSocket disertakan supaya app real-time
     * di target rute (mis. gateway API) tetap dapat Upgrade.
     *
     * @param array<int,array{path:string,target:string}> $routes
     */
    private function routeBlocks(array $routes): string
    {
        $blocks = [];
        foreach ($routes as $route) {
            $path = $route['path'];
            $target = $route['target'];

            // Prefix yang dilepas dari request (tanpa garis miring akhir).
            $prefix = rtrim($path, '/');
            $base = rtrim($target, '/');

            // `path` boleh memuat `.` (mis. `/v1.2/`) → escape agar rewrite
            // mencocokkan literal, bukan wildcard regex.
            $pathRegex = preg_quote($prefix, '#');

            // Perlakukan path pada target: `proxy_pass .../app/` membuat
            // upstream menerima `/app/...`, sehingga Location absolutnya juga
            // berawalan `/app` — regex redirect wajib mengikuti.
            $targetPath = rtrim((string) (parse_url($target, PHP_URL_PATH) ?? ''), '/');
            $locationPath = $targetPath === '' ? '' : preg_quote($targetPath, '#');

            // Redirect dari upstream wajib kembali ke bawah prefix; tanpa ini
            // browser diarahkan ke path root app (atau ke host internal upstream).
            $redirects = [
                "proxy_redirect ~^{$locationPath}/(.*)\$ {$prefix}/\$1;",
            ];
            $host = preg_quote((string) (parse_url($target, PHP_URL_HOST) ?? ''), '#');
            if ($host !== '') {
                $port = parse_url($target, PHP_URL_PORT);
                $portRegex = is_int($port) ? ':' . $port : '(?::\d+)?';
                $redirects[] = "proxy_redirect ~^https?://{$host}{$portRegex}{$locationPath}/(.*)\$ {$prefix}/\$1;";
            }
            $redirectBlock = implode("\n        ", $redirects);

            $blocks[] = <<<NGINX
    location ^~ {$path} {
        rewrite ^{$pathRegex}/(.+)/\$ \$1 permanent;

        proxy_pass {$base}/;
        {$redirectBlock}

        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Prefix {$prefix};

        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";

        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
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

    /**
     * Tulis config Nginx secara atomik: isi ditulis ke berkas sementara di
     * direktori yang sama lalu `rename()`. Pembaca (watcher/reload host) tidak
     * pernah melihat `.conf` terpotong bila proses berhenti di tengah tulis;
     * kegagalan apa pun membersihkan berkas sementara.
     */
    public function write(string $name, string $content): void
    {
        $this->ensureWritable();
        $file = $this->confPath . '/' . $name . '.conf';
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            $last = error_get_last();
            $detail = is_array($last) ? (string) ($last['message'] ?? '') : '';
            @unlink($tmp);
            throw new RuntimeException(
                'Gagal menulis config Nginx: ' . $file .
                ($detail !== '' ? ' — ' . $detail : ' — periksa izin tulis direktori.')
            );
        }

        if (!@rename($tmp, $file)) {
            $last = error_get_last();
            $detail = is_array($last) ? (string) ($last['message'] ?? '') : '';
            @unlink($tmp);
            throw new RuntimeException(
                'Gagal menyimpan config Nginx: ' . $file .
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
