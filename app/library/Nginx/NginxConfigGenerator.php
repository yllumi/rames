<?php
declare(strict_types=1);

namespace app\library\Nginx;

use RuntimeException;

/**
 * Generate & tulis config Nginx per site (SPECS.md §8.2).
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

    public function render(string $name, string $domain, int $hostPort): string
    {
        return <<<NGINX
server {
    listen 80;
    server_name {$name}.{$domain};

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
