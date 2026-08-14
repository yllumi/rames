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

    // Direktori pasangan kunci SSH (deploy key per site) + known_hosts
    'ssh_keys_path' => getenv('SSH_KEYS_PATH') ?: (base_path() . '/database/keys'),
    'git_known_hosts' => getenv('GIT_KNOWN_HOSTS') ?: (base_path() . '/database/keys/known_hosts'),

    // Email admin untuk penerimaan syarat & notifikasi Let's Encrypt
    'admin_email' => getenv('ADMIN_EMAIL') ?: '',

    // SSL otomatis Let's Encrypt (SPECS.md §8a)
    'ssl_challenge' => getenv('SSL_CHALLENGE') ?: 'http',        // http | dns-cloudflare
    'ssl_ca_server' => getenv('SSL_CA_SERVER') ?: 'production',  // production | staging
    'ssl_webroot' => getenv('SSL_WEBROOT') ?: (base_path() . '/webroot'),
    'letsencrypt_path' => getenv('LETSENCRYPT_PATH') ?: '/etc/letsencrypt',
    'cloudflare_creds' => getenv('CLOUDFLARE_CREDS') ?: '',

    // Socket Docker Engine
    'docker_socket' => getenv('DOCKER_SOCKET') ?: '/var/run/docker.sock',

    // Timeout (detik) untuk operasi docker compose / git yang panjang
    'deploy_timeout' => (int) (getenv('DEPLOY_TIMEOUT') ?: 600),
];
