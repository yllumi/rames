#!/usr/bin/env bash
#
# Rames — Installer otomatis (host) — SPECS.md §2 (fitur 1)
#
# Menyiapkan host & dashboard dalam satu perintah:
#   1. Prasyarat: nginx, inotify-tools, certbot (+ plugin cloudflare), docker + compose
#   2. Konfigurasi: buat .env dari .env.example (interaktif / flag)
#   3. Nginx: pastikan direktori sites-available/enabled + include di nginx.conf
#   4. Dashboard: docker compose up -d --build + user admin pertama
#   5. Watcher nginx reload (systemd) — SPECS §8.3 (fitur 2)
#   6. Renewal certbot otomatis (systemd timer) — SPECS §8a (fitur 3)
#
# Pemakaian:
#   sudo ./host/install.sh [--no-deps] [--non-interactive]
#                          [--domain example.com] [--port 8000]
#                          [--email admin@example.com]
#                          [--admin-user admin] [--admin-password <pass>]
#
# Idempoten: aman dijalankan ulang. .env yang sudah ada dipertahankan, user
# admin hanya dibuat bila auth.json masih kosong, unit systemd di-reload.
set -euo pipefail

# ---------------------------------------------------------------- path
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
NGINX_CONF_DEFAULT="/etc/nginx/nginx.conf"
NGINX_AVAILABLE_DEFAULT="/etc/nginx/sites-available"
NGINX_ENABLED_DEFAULT="/etc/nginx/sites-enabled"
ENV_FILE="$PROJECT_DIR/.env"
ENV_EXAMPLE="$PROJECT_DIR/.env.example"
COMPOSE_FILE="$PROJECT_DIR/docker-compose.yml"
CONTAINER_NAME="rames-webman"
WATCHER_USER="rames-watcher"

# ---------------------------------------------------------------- flags
NO_DEPS=0
NON_INTERACTIVE=0
OPT_DOMAIN=""
OPT_PORT=""
OPT_EMAIL=""
OPT_ADMIN_USER="admin"
OPT_ADMIN_PASS=""
OPT_SESSION_SECRET=""

# ---------------------------------------------------------------- colors
if [ -t 1 ]; then
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
else
    C_RED=""; C_GREEN=""; C_YELLOW=""; C_BOLD=""; C_RESET=""
fi
info() { echo "${C_BOLD}==>${C_RESET} $*"; }
ok()   { echo "${C_GREEN}   ✓${C_RESET} $*"; }
warn() { echo "${C_YELLOW}   !${C_RESET} $*"; }
die()  { echo "${C_RED}ERROR:${C_RESET} $*" >&2; exit 1; }

usage() {
    cat <<EOF
Rames — Installer otomatis (host)

Pemakaian:
  sudo $0 [opsi]

Opsi:
  --no-deps             lewati instalasi paket host (pakai yang sudah terpasang)
  --non-interactive     tanpa prompt (nilai default dipakai)
  --domain <d>          APP_DOMAIN (mis. example.com)
  --port <p>            APP_PORT (default 8000)
  --email <e>           ADMIN_EMAIL (untuk SSL Let's Encrypt)
  --admin-user <u>      username admin pertama (default admin)
  --admin-password <p>  password admin (default: di-generate acak)
  --session-secret <s>  SESSION_SECRET (default: di-generate acak)
  -h, --help            tampilkan bantuan ini
EOF
}

# ---------------------------------------------------------------- helpers
ensure_root() {
    if [ "$(id -u)" -eq 0 ]; then return; fi
    if command -v sudo >/dev/null 2>&1; then
        warn "Perlu akses root — menjalankan ulang lewat sudo ..."
        exec sudo -E env SCRIPT_RESTARTED=1 "$0" "$@"
    fi
    die "Jalankan installer sebagai root: sudo $0 ..."
}

random_password() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 30 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 20
    else
        head -c 30 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20
    fi
    echo
}

detect_pkgmgr() {
    if command -v apt-get >/dev/null 2>&1; then PKGMGR="apt";
    elif command -v apk >/dev/null 2>&1; then PKGMGR="apk";
    elif command -v dnf >/dev/null 2>&1; then PKGMGR="dnf";
    elif command -v yum >/dev/null 2>&1; then PKGMGR="yum";
    else PKGMGR="";
    fi
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --no-deps) NO_DEPS=1 ;;
            --non-interactive) NON_INTERACTIVE=1 ;;
            --domain) OPT_DOMAIN="${2:?--domain butuh nilai}"; shift ;;
            --port) OPT_PORT="${2:?--port butuh nilai}"; shift ;;
            --email) OPT_EMAIL="${2:?--email butuh nilai}"; shift ;;
            --admin-user) OPT_ADMIN_USER="${2:?--admin-user butuh nilai}"; shift ;;
            --admin-password) OPT_ADMIN_PASS="${2:?--admin-password butuh nilai}"; shift ;;
            --session-secret) OPT_SESSION_SECRET="${2:?--session-secret butuh nilai}"; shift ;;
            -h|--help) usage; exit 0 ;;
            *) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
        esac
        shift
    done
    if [ -n "$OPT_PORT" ] && ! printf '%s' "$OPT_PORT" | grep -qE '^[0-9]+$'; then
        die "APP_PORT harus angka."
    fi
}

# ---------------------------------------------------------------- install deps
install_deps() {
    local pkgs=()
    case "$PKGMGR" in
        apt) pkgs=(nginx inotify-tools certbot python3-certbot-dns-cloudflare) ;;
        apk) pkgs=(nginx inotify-tools certbot certbot-dns-cloudflare) ;;
        dnf|yum) pkgs=(nginx inotify-tools certbot python3-certbot-dns-cloudflare) ;;
        *) warn "Package manager tidak dikenal — lewati instalasi paket (gunakan --no-deps bila sengaja)."; return ;;
    esac

    info "Menginstal paket host: ${pkgs[*]}"
    case "$PKGMGR" in
        apt) apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y "${pkgs[@]}" ;;
        apk) apk add --no-cache "${pkgs[@]}" ;;
        dnf|yum) "$PKGMGR" install -y "${pkgs[@]}" ;;
    esac

    # Docker: bila belum ada, instal paket distro (compose plugin dicoba terpisah)
    if ! command -v docker >/dev/null 2>&1; then
        warn "docker tidak ditemukan — mencoba instal paket distro ..."
        case "$PKGMGR" in
            apt) DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io docker-compose-v2 \
                     || warn "Gagal instal docker.io/docker-compose-v2 — pasang Docker manual (https://docs.docker.com/engine/install/)." ;;
            apk) apk add --no-cache docker docker-cli-compose \
                     || warn "Gagal instal docker — pasang Docker manual." ;;
            dnf|yum) "$PKGMGR" install -y docker docker-compose-plugin \
                     || warn "Gagal instal docker — pasang Docker manual." ;;
        esac
    fi
}

preflight() {
    command -v docker >/dev/null 2>&1 || die "Perintah 'docker' tidak ditemukan."
    docker compose version >/dev/null 2>&1 || die "Plugin 'docker compose' (v2.6+) tidak tersedia. Pasang Docker sesuai https://docs.docker.com/engine/install/"
    [ -f "$COMPOSE_FILE" ] || die "docker-compose.yml tidak ditemukan di $PROJECT_DIR"
}

# ---------------------------------------------------------------- nginx
ensure_nginx_dirs() {
    mkdir -p "$NGINX_AVAILABLE_DEFAULT" "$NGINX_ENABLED_DEFAULT"
    ok "Direktori Nginx siap: $NGINX_AVAILABLE_DEFAULT / $NGINX_ENABLED_DEFAULT"
}

ensure_nginx_include() {
    [ -f "$NGINX_CONF_DEFAULT" ] || { warn "nginx.conf tidak ditemukan di $NGINX_CONF_DEFAULT — lewati penambahan include."; return; }
    if grep -qE '^\s*include\s+.*sites-enabled.*;' "$NGINX_CONF_DEFAULT"; then
        ok "nginx.conf sudah meng-include sites-enabled."
        return
    fi
    warn "nginx.conf belum meng-include /etc/nginx/sites-enabled/* — menambahkannya (backup dibuat)."
    cp -a "$NGINX_CONF_DEFAULT" "$NGINX_CONF_DEFAULT.rames-bak-$(date +%Y%m%d-%H%M%S)"
    awk '
        { lines[NR] = $0 }
        END {
            last = 0
            for (i = NR; i >= 1; i--) {
                if (lines[i] ~ /^[[:space:]]*}[[:space:]]*$/) { last = i; break }
            }
            if (last == 0) last = NR
            for (i = 1; i <= NR; i++) {
                if (i == last) {
                    print "    # Added by Rames install.sh"
                    print "    include /etc/nginx/sites-enabled/*;"
                }
                print lines[i]
            }
        }
    ' "$NGINX_CONF_DEFAULT" > "$NGINX_CONF_DEFAULT.rames-tmp" && mv "$NGINX_CONF_DEFAULT.rames-tmp" "$NGINX_CONF_DEFAULT"
    nginx -t >/dev/null 2>&1 || warn "nginx -t gagal setelah edit nginx.conf — periksa manual."
}

# ---------------------------------------------------------------- .env
generate_env() {
    [ -f "$ENV_EXAMPLE" ] || die ".env.example tidak ditemukan di $PROJECT_DIR"

    if [ -f "$ENV_FILE" ]; then
        ok ".env sudah ada — mempertahankan nilai yang ada."
        if grep -q '^SESSION_SECRET=change-me' "$ENV_FILE" && [ "$NON_INTERACTIVE" -eq 0 ]; then
            read -r -p "  SESSION_SECRET masih 'change-me' — generate acak? [Y/n]: " ans
            case "${ans:-Y}" in
                Y|y|'') sed -i "s|^SESSION_SECRET=.*|SESSION_SECRET=$(random_password)|" "$ENV_FILE"; ok "SESSION_SECRET di-generate." ;;
            esac
        fi
        return
    fi

    info "Membuat $ENV_FILE dari .env.example"
    cp "$ENV_EXAMPLE" "$ENV_FILE"

    local domain="$OPT_DOMAIN" port="$OPT_PORT" email="$OPT_EMAIL" secret="$OPT_SESSION_SECRET"

    if [ "$NON_INTERACTIVE" -eq 0 ]; then
        if [ -z "$domain" ]; then read -r -p "  APP_DOMAIN [example.com]: " domain; fi
        if [ -z "$port" ]; then read -r -p "  APP_PORT [8000]: " port; fi
        if [ -z "$email" ]; then read -r -p "  ADMIN_EMAIL (mis. admin@example.com): " email; fi
    fi
    domain="${domain:-example.com}"
    port="${port:-8000}"
    email="${email:-admin@example.com}"
    secret="${secret:-$(random_password)}"

    sed -i "s|^APP_DOMAIN=.*|APP_DOMAIN=$domain|" "$ENV_FILE"
    sed -i "s|^APP_PORT=.*|APP_PORT=$port|" "$ENV_FILE"
    sed -i "s|^ADMIN_EMAIL=.*|ADMIN_EMAIL=$email|" "$ENV_FILE"
    sed -i "s|^SESSION_SECRET=.*|SESSION_SECRET=$secret|" "$ENV_FILE"
    ok ".env dibuat (APP_DOMAIN=$domain, APP_PORT=$port)"
}

# ---------------------------------------------------------------- dashboard
start_dashboard() {
    info "Membangun & menjalankan dashboard (docker compose up -d --build) ..."
    ( cd "$PROJECT_DIR" && docker compose up -d --build )
    local i
    for i in $(seq 1 60); do
        if docker inspect -f '{{.State.Running}}' "$CONTAINER_NAME" 2>/dev/null | grep -q true; then
            sleep 2
            ok "Container dashboard siap ($CONTAINER_NAME)."
            return 0
        fi
        sleep 2
    done
    warn "Container $CONTAINER_NAME belum 'running' — periksa 'docker compose ps' & 'docker compose logs webman'."
}

create_admin() {
    docker inspect -f '{{.State.Running}}' "$CONTAINER_NAME" >/dev/null 2>&1 \
        || { warn "Container dashboard tidak berjalan — lewati pembuatan user admin."; return; }
    info "Membuat user admin pertama (auth.json) ..."
    local out
    out="$(docker exec "$CONTAINER_NAME" php webman make:admin "$OPT_ADMIN_USER" "$OPT_ADMIN_PASS" 2>&1 || true)"
    if printf '%s' "$out" | grep -qi 'sudah punya user'; then
        warn "auth.json sudah punya user — admin tidak dibuat ulang (gunakan halaman Manage Users)."
    else
        printf '%s\n' "$out"
        warn "Simpan kredensial di atas — password hanya tampil sekali."
    fi
}

# ---------------------------------------------------------------- watcher user
create_watcher_user() {
    if id "$WATCHER_USER" >/dev/null 2>&1; then
        ok "User $WATCHER_USER sudah ada."
    else
        if command -v useradd >/dev/null 2>&1; then
            useradd --system --create-home --shell /usr/sbin/nologin "$WATCHER_USER" 2>/dev/null \
                || useradd --system "$WATCHER_USER"
        else
            adduser -S -D -h /var/lib/rames -s /bin/false "$WATCHER_USER" 2>/dev/null \
                || adduser -D "$WATCHER_USER"
        fi
        ok "User $WATCHER_USER dibuat."
    fi
    # status file dapat ditulis watcher (host) & dibaca container (root di dalam)
    mkdir -p "$PROJECT_DIR/nginx-status"
    chown -R "$WATCHER_USER:$WATCHER_USER" "$PROJECT_DIR/nginx-status"
    chmod 0775 "$PROJECT_DIR/nginx-status"
    ok "Direktori status siap: $PROJECT_DIR/nginx-status"
}

# ---------------------------------------------------------------- watcher nginx (fitur 2)
install_watcher() {
    local script_src="$SCRIPT_DIR/nginx-reload-watcher.sh"
    local unit_src="$SCRIPT_DIR/systemd/dashboard-nginx-watcher.service"
    local sudoers_src="$SCRIPT_DIR/systemd/dashboard-nginx-watcher.sudoers"
    local script_dst="/usr/local/bin/rames-nginx-reload-watcher.sh"
    local unit_dst="/etc/systemd/system/dashboard-nginx-watcher.service"
    local sudoers_dst="/etc/sudoers.d/rames-nginx-watcher"
    local env_dst="/etc/rames/nginx-watcher.env"

    [ -f "$script_src" ] && [ -f "$unit_src" ] && [ -f "$sudoers_src" ] \
        || { warn "File watcher tidak lengkap di host/ — lewati instalasi watcher."; return; }

    install -m 0755 "$script_src" "$script_dst"
    install -m 0644 "$unit_src" "$unit_dst"
    install -m 0440 "$sudoers_src" "$sudoers_dst"

    mkdir -p /etc/rames
    cat > "$env_dst" <<EOF
WATCH_DIR=$NGINX_AVAILABLE_DEFAULT
NGINX_BIN=/usr/sbin/nginx
NGINX_CONF=$NGINX_CONF_DEFAULT
STATUS_FILE=$PROJECT_DIR/nginx-status/last-reload.json
SUDO_CMD=sudo -n
DEBOUNCE_SECS=2
EOF
    chmod 0640 "$env_dst"

    # validasi sudoers — jangan sampai merusak sudo bila file rusak
    visudo -cf "$sudoers_dst" >/dev/null 2>&1 \
        || { rm -f "$sudoers_dst"; warn "sudoers tidak valid — watcher dipasang tanpa izin reload (reload akan gagal)."; }

    systemctl daemon-reload
    systemctl enable dashboard-nginx-watcher.service >/dev/null 2>&1 || true
    systemctl restart dashboard-nginx-watcher.service
    ok "Watcher Nginx diinstal & dijalankan (dashboard-nginx-watcher.service)."
}

# ---------------------------------------------------------------- renewal certbot (fitur 3)
install_certbot_timer() {
    local script_src="$SCRIPT_DIR/certbot-renew.sh"
    local unit_src="$SCRIPT_DIR/systemd/certbot-renew.service"
    local timer_src="$SCRIPT_DIR/systemd/certbot-renew.timer"
    local script_dst="/usr/local/bin/rames-certbot-renew.sh"
    local unit_dst="/etc/systemd/system/certbot-renew.service"
    local timer_dst="/etc/systemd/system/certbot-renew.timer"
    local env_dst="/etc/rames/certbot-renew.env"

    [ -f "$script_src" ] && [ -f "$unit_src" ] && [ -f "$timer_src" ] \
        || { warn "File renewal tidak lengkap di host/ — lewati instalasi timer certbot."; return; }

    install -m 0755 "$script_src" "$script_dst"
    install -m 0644 "$unit_src" "$unit_dst"
    install -m 0644 "$timer_src" "$timer_dst"

    mkdir -p /etc/rames
    cat > "$env_dst" <<EOF
NGINX_BIN=/usr/sbin/nginx
NGINX_CONF=$NGINX_CONF_DEFAULT
LE_PATH=/etc/letsencrypt
STATUS_FILE=$PROJECT_DIR/nginx-status/last-reload.json
SUDO_CMD=
EOF
    chmod 0640 "$env_dst"

    systemctl daemon-reload
    systemctl enable --now certbot-renew.timer >/dev/null 2>&1 || true
    ok "Timer renewal certbot diinstal (certbot-renew.timer, 2×/hari)."
}

# ---------------------------------------------------------------- summary
print_summary() {
    local domain port
    domain="$(grep -E '^APP_DOMAIN=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
    domain="${domain:-example.com}"
    port="$(grep -E '^APP_PORT=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"
    port="${port:-8000}"
    echo
    echo "${C_GREEN}==================================================${C_RESET}"
    echo "${C_GREEN} Rames terpasang!${C_RESET}"
    echo "${C_GREEN}==================================================${C_RESET}"
    echo "  Dashboard : http://localhost:${port}"
    echo "  Subdomain : {site}.${domain}"
    echo
    echo "  Service aktif:"
    echo "    - dashboard-nginx-watcher.service (reload otomatis Nginx)"
    echo "    - certbot-renew.timer (renewal SSL 2×/hari)"
    echo
    echo "  Langkah berikutnya:"
    echo "    - Buka dashboard, login, lalu Create Site dari repo Git berisi docker-compose.yml"
    echo "    - Pastikan DNS *.${domain} mengarah ke IP server ini"
    echo "    - Periksa service: systemctl status dashboard-nginx-watcher certbot-renew.timer"
}

# ---------------------------------------------------------------- main
main() {
    ensure_root "$@"
    parse_args "$@"
    info "Rames installer — project: $PROJECT_DIR"

    detect_pkgmgr
    if [ "$NO_DEPS" -eq 0 ]; then
        install_deps
    else
        info "--no-deps: melewatkan instalasi paket host."
    fi

    preflight
    ensure_nginx_dirs
    ensure_nginx_include
    generate_env
    create_watcher_user
    start_dashboard
    create_admin
    install_watcher
    install_certbot_timer
    print_summary
}

main "$@"
