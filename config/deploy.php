<?php
declare(strict_types=1);

/**
 * Konfigurasi dashboard deployer.
 *
 * Nilai dibaca dari environment (.env via phpdotenv). Semua kredensial/path
 * environment-dependent, tidak ada secret hard-coded (lihat copilot-instructions).
 */

// Env adminer boleh bernilai "0" (= nonaktif) — `?:` akan menelan nilai itu,
// jadi pembacaan dilakukan eksplisit di sini.
$adminerNetworkTtlEnv = getenv('ADMINER_NETWORK_TTL');

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

    // File manager container (jelajah, unggah, unduh, edit teks, ekstrak arsip).
    'files_timeout' => (int) (getenv('FILES_TIMEOUT') ?: 120),                  // detik, perintah singkat di dalam container
    'files_transfer_timeout' => (int) (getenv('FILES_TRANSFER_TIMEOUT') ?: 600), // detik, docker cp & ekstraksi arsip

    'dashboard_container' => getenv('HOSTNAME') ?: getenv('DASHBOARD_CONTAINER') ?: 'rames-webman',
    // Timeout dump/restore logis (dipakai DbDump pada fitur backup/restore volume).
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
    // Backup volume ke S3 via restic (PLAN_VOLUME_BACKUP.md / SPECS.md §8h)
    // ---------------------------------------------------------------------
    // Namespace env: VOLUME_BACKUP_*/RESTIC_*/AWS_* — JANGAN pakai BACKUP_*
    // (§8g: backup database/*.json + config Nginx, terpisah — jangan digabung).
    // Kredensial S3 via .env → environment: compose; passphrase restic di FILE
    // `database/restic/password` (chmod 0600, gitignored) lewat --password-file.
    // TIDAK ADA secret hard-coded di sini.
    'volume_backup_enabled' => (getenv('VOLUME_BACKUP_ENABLED') ?: 'true') !== 'false', // false = matikan fitur
    // Strategi B (snapshot filesystem): `stop` = volume non-DB dibackup harian via
    // stop→snapshot→start; `skip` = hanya manual.
    'volume_backup_snapshot_policy' => getenv('VOLUME_BACKUP_SNAPSHOT_POLICY') ?: 'stop',
    // Guard: tolak snapshot bila ada container running yang me-mount volume.
    'volume_backup_require_stopped' => (getenv('VOLUME_BACKUP_REQUIRE_STOPPED') ?: 'true') !== 'false',
    'volume_backup_stop_timeout' => (int) (getenv('VOLUME_BACKUP_STOP_TIMEOUT') ?: 120),   // detik, tunggu container berhenti
    'volume_backup_dump_timeout' => (int) (getenv('VOLUME_BACKUP_DUMP_TIMEOUT') ?: 600),   // detik, timeout mysqldump/pg_dump
    // Strategi A: dump logis untuk container DB (dijalankan selagi container hidup).
    'volume_backup_db_dump_enabled' => (getenv('VOLUME_BACKUP_DB_DUMP_ENABLED') ?: 'true') !== 'false',
    'volume_backup_timeout' => (int) (getenv('VOLUME_BACKUP_TIMEOUT') ?: 3600),            // detik, timeout satu run restic
    'volume_backup_image' => getenv('VOLUME_BACKUP_IMAGE') ?: '',                          // kosong = image container dashboard
    'volume_backup_keep_daily' => (int) (getenv('VOLUME_BACKUP_KEEP_DAILY') ?: 7),         // restic forget --keep-daily
    'volume_backup_keep_weekly' => (int) (getenv('VOLUME_BACKUP_KEEP_WEEKLY') ?: 4),       // restic forget --keep-weekly
    'volume_backup_keep_monthly' => (int) (getenv('VOLUME_BACKUP_KEEP_MONTHLY') ?: 3),     // restic forget --keep-monthly
    'restic_repository' => getenv('RESTIC_REPOSITORY') ?: '',                              // mis. s3:https://s3.amazonaws.com/<bucket>/rames
    'restic_password_file' => getenv('RESTIC_PASSWORD_FILE') ?: (base_path() . '/database/restic/password'),
    'aws_access_key_id' => getenv('AWS_ACCESS_KEY_ID') ?: '',
    'aws_secret_access_key' => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
    'aws_default_region' => getenv('AWS_DEFAULT_REGION') ?: '',

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

    // ---------------------------------------------------------------------
    // Adminer sebagai mesin halaman /database (fitur D1)
    // ---------------------------------------------------------------------
    // Helper Adminer standalone (tanpa port publik) + reverse proxy HTTP dari
    // dashboard. Dashboard TIDAK PERNAH require/mengeksekusi adminer.php.
    // Peran AdminerHelper: jalankan `docker run -d --name <adminer_container>`
    // pada network internal `adminer_network`, dan attach dashboard + helper ke
    // network app target supaya helper bisa menjangkau container DB lewat DNS.
    'adminer_image' => getenv('ADMINER_IMAGE') ?: 'adminer:6',
    'adminer_container' => getenv('ADMINER_CONTAINER') ?: 'rames-adminer',
    // Network internal (internal: true): Adminer tanpa auth tidak boleh terlihat
    // container lain; kebutuhan helper hanya dashboard + network app target.
    'adminer_network' => getenv('ADMINER_NETWORK') ?: 'rames-helpers',
    // Worker `php -S` di helper (image adminer memakai php -S pada port 8080).
    'adminer_workers' => (int) (getenv('ADMINER_WORKERS') ?: 8),
    // Batas umur attach helper ke network app target (detik). Helper hanya perlu
    // berada di network app saat melayani halaman /database; attach yang idle
    // melebihi TTL dilepas oportunistik dari ensureRunning() supaya permukaan
    // jaringan (egress + Adminer tanpa auth terlihat container lain di network
    // non-internal) tidak menumpuk. 0 = nonaktif (tanpa prune). Network helper
    // (`adminer_network`) TIDAK PERNAH dilepas. Catatan waktu disimpan tanpa
    // kredensial apa pun di runtime/adminer-helper/networks.json.
    'adminer_network_ttl' => max(0, (int) ($adminerNetworkTtlEnv !== false && $adminerNetworkTtlEnv !== ''
        ? $adminerNetworkTtlEnv
        : 1800)),
    // Timeout klien HTTP proxy ke helper — aturan repo: TIDAK BOLEH > 30 detik.
    // Nilai env di-cap di bawah agar konfigurasi tidak bisa melanggarnya.
    'adminer_proxy_timeout' => min(30, max(1, (int) (getenv('ADMINER_PROXY_TIMEOUT') ?: 30))),
    // Batas ukuran respons proxy (byte, default 64 MiB). Dump/import besar harus
    // lewat fitur Volume/backup atau Terminal — bukan diakali lewat proxy.
    'adminer_proxy_max_bytes' => max(1024, (int) (getenv('ADMINER_PROXY_MAX_BYTES') ?: 67108864)),
    // Basis prefix URL publik halaman Adminer (per container: <base>/<container>/adminer).
    'adminer_prefix_base' => getenv('ADMINER_PREFIX_BASE') ?: '/database',
];
