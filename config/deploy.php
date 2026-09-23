<?php
declare(strict_types=1);

/**
 * Konfigurasi dashboard deployer.
 *
 * Nilai dibaca dari environment (.env via phpdotenv). Semua kredensial/path
 * environment-dependent, tidak ada secret hard-coded (lihat copilot-instructions).
 */

return [
    // Domain dasar; subdomain app = {name}.{app_domain}
    'app_domain' => getenv('APP_DOMAIN') ?: 'example.com',

    // Port akses dashboard dari luar (informasional)
    'app_port' => (int) (getenv('APP_PORT') ?: 8000),

    // Rentang host port yang diizinkan untuk container app
    'port_range' => [
        'start' => (int) (getenv('PORT_RANGE_START') ?: 30000),
        'end' => (int) (getenv('PORT_RANGE_END') ?: 30999),
    ],

    // Direktori hasil clone tiap app
    'apps_path' => getenv('APPS_PATH') ?: (base_path() . '/apps'),

    // Direktori config Nginx host yang di-mount ke container dashboard
    'nginx_conf_path' => getenv('NGINX_CONF_PATH') ?: '/etc/nginx/sites-available',
    'nginx_enabled_path' => getenv('NGINX_ENABLED_PATH') ?: '/etc/nginx/sites-enabled',

    // File status reload terakhir yang ditulis watcher di host
    'nginx_reload_status_file' => getenv('NGINX_RELOAD_STATUS_FILE') ?: (base_path() . '/nginx-status/last-reload.json'),

    // Reload nginx HOST dari dashboard (via helper container pada Docker socket).
    // Helper me-chroot ke root host (--privileged --pid host) sehingga memakai
    // binary/config/user nginx host yang persis.
    'nginx_http_conf' => getenv('NGINX_HTTP_CONF') ?: '/etc/nginx/nginx.conf',
    'nginx_bin' => getenv('NGINX_BIN') ?: '/usr/sbin/nginx',
    'nginx_reload_image' => getenv('NGINX_RELOAD_IMAGE') ?: 'alpine',

    // Direktori penyimpanan JSON (auth.json, apps.json)
    'database_path' => getenv('DATABASE_PATH') ?: (base_path() . '/database'),

    // Direktori pasangan kunci SSH (deploy key per app) + known_hosts
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
    'docker_binary' => getenv('DOCKER_BINARY') ?: 'docker',

    // Monitoring resource container & VM (SPECS §8d).
    // Path pseudo-filesystem host untuk metrik VM. Di dalam container, `/proc`
    // SUDAH menampilkan nilai host (Docker tidak men-namespace-kan stat/meminfo);
    // arahkan ke mount host eksplisit bila host memakai lxcfs (nilai /proc jadi
    // ter-scope container) sehingga "total VM" tetap benar.
    'host_proc_path' => getenv('HOST_PROC_PATH') ?: '/proc',
    // Timeout satu siklus pengambilan stats (semua container diambil paralel)
    'monitor_stats_timeout' => (int) (getenv('MONITOR_STATS_TIMEOUT') ?: 20),
    // Interval polling metrik host di halaman /monitor (milidetik, 0 = matikan).
    // Hanya membaca /proc (file lokal) sehingga 5–10 detik tetap ringan; tabel
    // container tetap dimuat ulang manual lewat tombol Refresh.
    'monitor_poll_ms' => (int) (getenv('MONITOR_POLL_MS') ?: 7000),

    // Terminal container (docker exec interaktif & one-shot run command)
    'terminal_script_bin' => getenv('TERMINAL_SCRIPT_BIN') ?: 'script',   // PTY wrapper (util-linux)
    'terminal_run_timeout' => (int) (getenv('TERMINAL_RUN_TIMEOUT') ?: 120),   // detik, run command one-shot
    'terminal_session_ttl' => (int) (getenv('TERMINAL_SESSION_TTL') ?: 3600),  // detik, umur maks sesi interaktif
    // Sesi tanpa klien/aktivitas (mis. browser ditutup tanpa POST /close) dibuang
    // setelah ini — sesi yang sedang di-stream SSE terus menandai aktivitas.
    'terminal_idle_timeout' => (int) (getenv('TERMINAL_IDLE_TIMEOUT') ?: 900),  // detik, idle maks sesi interaktif
    'terminal_max_sessions' => (int) (getenv('TERMINAL_MAX_SESSIONS') ?: 20),  // batas sesi aktif serentak

    // Database manager (phpMyAdmin mini) — koneksi PDO ke MySQL/MariaDB container
    'dashboard_container' => getenv('HOSTNAME') ?: getenv('DASHBOARD_CONTAINER') ?: 'rames-webman',
    'db_connect_timeout' => (int) (getenv('DB_CONNECT_TIMEOUT') ?: 10),   // detik, timeout koneksi PDO
    'db_browse_per_page' => (int) (getenv('DB_BROWSE_PER_PAGE') ?: 50),   // baris per halaman browse
    'db_max_rows' => (int) (getenv('DB_MAX_ROWS') ?: 500),                // batas baris hasil SQL editor
    'db_export_timeout' => (int) (getenv('DB_EXPORT_TIMEOUT') ?: 600),    // detik, timeout mysqldump
    'db_import_timeout' => (int) (getenv('DB_IMPORT_TIMEOUT') ?: 600),    // detik, timeout restore

    // Timeout (detik) untuk operasi docker compose / git yang panjang
    'deploy_timeout' => (int) (getenv('DEPLOY_TIMEOUT') ?: 600),

    // Create app mode "compose" (paste/upload docker-compose.yml tanpa repo Git).
    // Batas ukuran file unggahan — app mode ini hanya berisi compose + file
    // pendukung kecil (bind mount config), bukan source aplikasi.
    'compose_upload_max_file_bytes' => (int) (getenv('COMPOSE_UPLOAD_MAX_FILE_BYTES') ?: 1048576),   // 1 MB per file
    'compose_upload_max_total_bytes' => (int) (getenv('COMPOSE_UPLOAD_MAX_TOTAL_BYTES') ?: 4194304), // 4 MB total

    // Galeri template app siap-pakai (SPECS.md §7.2b). Satu template = satu
    // direktori di bawah path ini: `template.yml` (metadata + deklarasi env),
    // `docker-compose.yml` (image prebuilt, tanpa `build:`), `files/` (file
    // pendukung opsional yang di-bind mount). Dikelola lewat repo (bukan UI).
    'templates_path' => getenv('TEMPLATES_PATH') ?: (base_path() . '/templates'),

    // ---------------------------------------------------------------------
    // Self-update dashboard (SPECS.md §7.8 / ARCHITECTURE.md §5.14)
    // ---------------------------------------------------------------------
    // Update dijalankan oleh HELPER CONTAINER detached di luar lifecycle
    // container dashboard — proses yang menjalankan update akan mematikan
    // dirinya sendiri saat `docker compose up -d` me-recreate dashboard.
    'update_enabled' => (getenv('UPDATE_ENABLED') ?: 'true') !== 'false',      // false = sembunyikan seluruh fitur
    'update_path' => getenv('UPDATE_PATH') ?: base_path(),                     // direktori repo dashboard (host path)
    'update_branch' => getenv('UPDATE_BRANCH') ?: '',                          // kosong = branch aktif repo
    'update_check_interval' => (int) (getenv('UPDATE_CHECK_INTERVAL') ?: 1800), // detik, cek berkala (0 = mati)
    'update_health_timeout' => (int) (getenv('UPDATE_HEALTH_TIMEOUT') ?: 180),  // detik, tunggu versi BARU sehat
    'update_rollback_timeout' => (int) (getenv('UPDATE_ROLLBACK_TIMEOUT') ?: 180), // detik, tunggu versi LAMA pulih
    'update_image' => getenv('UPDATE_IMAGE') ?: '',                            // kosong = image container dashboard
    'update_check_file' => getenv('UPDATE_CHECK_FILE') ?: (base_path() . '/runtime/update/check.json'),
    'update_run_dir' => getenv('UPDATE_RUN_DIR') ?: (base_path() . '/runtime/logs/update'),
];
