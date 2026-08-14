<?php
declare(strict_types=1);

/**
 * Konfigurasi dashboard deployer.
 *
 * Nilai dibaca dari environment (.env via phpdotenv). Semua kredensial/path
 * environment-dependent, tidak ada secret hard-coded (lihat copilot-instructions).
 */

return [
    // Domain dasar; subdomain site = {name}.{app_domain}
    'app_domain' => getenv('APP_DOMAIN') ?: 'example.com',

    // Port akses dashboard dari luar (informasional)
    'app_port' => (int) (getenv('APP_PORT') ?: 8000),

    // Rentang host port yang diizinkan untuk container site
    'port_range' => [
        'start' => (int) (getenv('PORT_RANGE_START') ?: 30000),
        'end' => (int) (getenv('PORT_RANGE_END') ?: 30999),
    ],

    // Direktori hasil clone tiap site
    'sites_path' => getenv('SITES_PATH') ?: (base_path() . '/sites'),

    // Direktori config Nginx host yang di-mount ke container dashboard
    'nginx_conf_path' => getenv('NGINX_CONF_PATH') ?: '/etc/nginx/sites-available',
    'nginx_enabled_path' => getenv('NGINX_ENABLED_PATH') ?: '/etc/nginx/sites-enabled',

    // File status reload terakhir yang ditulis watcher di host
    'nginx_reload_status_file' => getenv('NGINX_RELOAD_STATUS_FILE') ?: (base_path() . '/nginx-status/last-reload.json'),

    // Direktori penyimpanan JSON (auth.json, sites.json)
    'database_path' => base_path() . '/database',

    // Email admin (cadangan untuk SSL di iterasi berikutnya)
    'admin_email' => getenv('ADMIN_EMAIL') ?: '',

    // Socket Docker Engine
    'docker_socket' => getenv('DOCKER_SOCKET') ?: '/var/run/docker.sock',

    // Timeout (detik) untuk operasi docker compose / git yang panjang
    'deploy_timeout' => (int) (getenv('DEPLOY_TIMEOUT') ?: 600),
];
