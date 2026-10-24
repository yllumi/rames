#!/usr/bin/env bash
#
# Rames — Installer otomatis (host) — SPECS.md §2 (fitur 1)
#
# Menyiapkan host & dashboard dalam satu perintah:
#   1. Prasyarat: nginx, inotify-tools, certbot (+ plugin cloudflare),
#      Docker Engine + Compose v2 (repo resmi Docker, fallback paket distro)
#   2. Konfigurasi: wizard .env dari .env.example (interaktif / flag)
#   3. Nginx: pastikan direktori sites-available/enabled + include di nginx.conf
#   4. Dashboard: docker compose up -d --build + user admin pertama
#      (delegasi `host/create-admin.sh` — menunggu bootstrap container selesai
#      dulu; container sudah "running" sejak detik pertama `composer install`)
#   5. Watcher nginx reload (systemd) — SPECS §8.3 (fitur 2)
#   6. Renewal certbot otomatis (systemd timer) — SPECS §8a (fitur 3)
#   7. Backup volume harian ke S3 via restic (systemd timer) — PLAN_VOLUME_BACKUP.md
#
# Pemakaian:
#   sudo ./host/install.sh [opsi]            (lihat --help)
#
# Idempoten: aman dijalankan ulang. .env yang sudah ada dipertahankan, user
# admin hanya dibuat bila store user masih kosong, unit systemd di-reload.
#
# Secret (SESSION_SECRET, AWS keys, password admin) TIDAK pernah dicetak.
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
ENV_ONLY=0
DEPS_ONLY=0
OPT_DOMAIN=""
OPT_PORT=""
OPT_EMAIL=""
OPT_ADMIN_USER=""
ADMIN_USER_GIVEN=0
OPT_ADMIN_PASS=""
OPT_SESSION_SECRET=""
OPT_RANGE_START=""
OPT_RANGE_END=""
OPT_SSL_CHALLENGE=""
OPT_CLOUDFLARE_CREDS=""
OPT_BILLING=""
OPT_RESTIC_REPOSITORY=""
OPT_AWS_ACCESS_KEY_ID=""
OPT_AWS_SECRET_ACCESS_KEY=""
OPT_AWS_DEFAULT_REGION=""

# Nilai hasil wizard (dipakai saat .env BELUM ada)
CONF_ADV=0
CONF_DOMAIN=""
CONF_EMAIL=""
CONF_PORT=""
CONF_RANGE_START=""
CONF_RANGE_END=""
CONF_SECRET=""
CONF_SSL_CHALLENGE=""
CONF_CLOUDFLARE_CREDS=""
CONF_BILLING=""
CONF_RESTIC_REPOSITORY=""
CONF_AWS_ACCESS_KEY_ID=""
CONF_AWS_SECRET_ACCESS_KEY=""
CONF_AWS_DEFAULT_REGION=""

# FD untuk membaca jawaban wizard. Bootstrap (`install.sh` di root repo)
# mengekspor RAMES_PROMPT_FD=3 saat skrip dijalankan lewat pipe (curl | bash),
# karena stdin saat itu berisi isi skrip — bukan keyboard.
PROMPT_FD="${RAMES_PROMPT_FD:-0}"
case "$PROMPT_FD" in
    ''|*[!0-9]*) PROMPT_FD=0 ;;
esac

# Di-set bila `host/create-admin.sh` gagal — dilaporkan tegas di ringkasan akhir
ADMIN_CREATE_FAILED=0

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

Opsi umum:
  --no-deps             lewati instalasi paket host (pakai yang sudah terpasang)
  --non-interactive     tanpa prompt (nilai default/flag dipakai; wajib --domain)
  --domain <d>          APP_DOMAIN (mis. example.com) — wajib saat .env baru
  --email <e>           ADMIN_EMAIL (default: admin@<domain>)
  --port <p>            APP_PORT dashboard (default 8000; bukan 80/443)
  --port-range-start <p> PORT_RANGE_START untuk app (default 30000)
  --port-range-end <p>   PORT_RANGE_END untuk app (default 30999)
  --admin-user <u>      username admin pertama (default admin)
  --admin-password <p>  password admin (default: di-generate acak oleh create-admin.sh)
  --session-secret <s>  SESSION_SECRET (default: di-generate acak; 'change-me' ditolak)
  --deps-only           hanya pasang dependensi host (Docker Engine + Compose v2,
                        nginx, certbot) lalu keluar — dipakai installer agent
                        (install.agent.sh); .env, preflight, dan compose TIDAK
                        disentuh, jadi aman di host yang sudah punya dashboard

Opsi lanjutan (opsional):
  --ssl-challenge <m>        http (default) | dns-cloudflare
  --cloudflare-creds <path>  file kredensial Cloudflare (wajib bila dns-cloudflare)
  --billing <true|false>     BILLING_ENABLED (default true)
  --restic-repository <url>  RESTIC_REPOSITORY (kosong = backup S3 dilewati)
  --aws-access-key-id <id>   AWS_ACCESS_KEY_ID (restic/S3)
  --aws-secret-access-key <s> AWS_SECRET_ACCESS_KEY (restic/S3)
  --aws-default-region <r>   AWS_DEFAULT_REGION (default ap-southeast-1)

Internal/pengujian:
  --env-only            hanya tulis .env lalu keluar (tidak menyentuh Docker/systemd)
  -h, --help            tampilkan bantuan ini

.env yang sudah ada SELALU dipertahankan (idempoten); flag di atas diabaikan
kecuali --admin-user/--admin-password yang tetap dipakai untuk membuat admin.
EOF
}

# ---------------------------------------------------------------- helpers
ensure_root() {
    if [ "$(id -u)" -eq 0 ]; then return 0; fi
    if command -v sudo >/dev/null 2>&1; then
        warn "Perlu akses root — menjalankan ulang lewat sudo ..."
        exec sudo -E "$0" "$@"
    fi
    die "Jalankan installer sebagai root: sudo $0 ..."
}

is_interactive() {
    [ "$NON_INTERACTIVE" -eq 0 ] && [ -t "$PROMPT_FD" ]
}

# ask VAR "prompt" "default" — isi VAR dengan jawaban; pakai default saat EOF
# atau saat mode non-interaktif.
ask() {
    local __var="$1" __prompt="$2" __def="${3-}" __ans=""
    if ! is_interactive; then
        printf -v "$__var" '%s' "$__def"
        return 0
    fi
    if [ -n "$__def" ]; then
        printf '  %s [%s]: ' "$__prompt" "$__def" >&2
    else
        printf '  %s: ' "$__prompt" >&2
    fi
    read -r -u "$PROMPT_FD" __ans || __ans=""
    if [ -z "$__ans" ]; then __ans="$__def"; fi
    printf -v "$__var" '%s' "$__ans"
}

# ask_secret VAR "prompt" — seperti ask tanpa default & tanpa echo (password/secret)
ask_secret() {
    local __var="$1" __prompt="$2" __ans=""
    if ! is_interactive; then
        printf -v "$__var" '%s' ""
        return 0
    fi
    printf '  %s: ' "$__prompt" >&2
    read -rs -u "$PROMPT_FD" __ans || __ans=""
    printf '\n' >&2
    printf -v "$__var" '%s' "$__ans"
}

gen_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32
    else
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'
    fi
}

# ---------------------------------------------------------------- validasi
is_uint() { case "$1" in ''|*[!0-9]*) return 1 ;; esac; return 0; }

validate_port_value() { # label value
    local label="$1" val="$2" n
    is_uint "$val" || die "$label harus angka 1-65535 (diberikan: '$val')."
    n=$((10#$val))
    [ "$n" -ge 1 ] && [ "$n" -le 65535 ] || die "$label harus 1-65535 (diberikan: $val)."
}

valid_domain() {
    printf '%s' "$1" | grep -qE '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$'
}

valid_email() {
    printf '%s' "$1" | grep -qE '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
}

# ---------------------------------------------------------------- args
parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --no-deps) NO_DEPS=1 ;;
            --non-interactive) NON_INTERACTIVE=1 ;;
            --env-only) ENV_ONLY=1 ;;
            --deps-only) DEPS_ONLY=1 ;;
            --domain)
                OPT_DOMAIN="${2:?--domain butuh nilai}"; shift
                valid_domain "$OPT_DOMAIN" || die "--domain tidak valid: '$OPT_DOMAIN' (huruf kecil, mis. example.com)."
                ;;
            --email)
                OPT_EMAIL="${2:?--email butuh nilai}"; shift
                valid_email "$OPT_EMAIL" || die "--email tidak valid: '$OPT_EMAIL'."
                ;;
            --port)
                OPT_PORT="${2:?--port butuh nilai}"; shift
                validate_port_value "--port" "$OPT_PORT"
                OPT_PORT=$((10#$OPT_PORT))
                case "$OPT_PORT" in 80|443) die "--port $OPT_PORT tidak boleh — port itu dipakai Nginx." ;; esac
                ;;
            --port-range-start)
                OPT_RANGE_START="${2:?--port-range-start butuh nilai}"; shift
                validate_port_value "--port-range-start" "$OPT_RANGE_START"
                OPT_RANGE_START=$((10#$OPT_RANGE_START))
                ;;
            --port-range-end)
                OPT_RANGE_END="${2:?--port-range-end butuh nilai}"; shift
                validate_port_value "--port-range-end" "$OPT_RANGE_END"
                OPT_RANGE_END=$((10#$OPT_RANGE_END))
                ;;
            --admin-user) OPT_ADMIN_USER="${2:?--admin-user butuh nilai}"; ADMIN_USER_GIVEN=1; shift ;;
            --admin-password) OPT_ADMIN_PASS="${2:?--admin-password butuh nilai}"; shift ;;
            --session-secret)
                OPT_SESSION_SECRET="${2:?--session-secret butuh nilai}"; shift
                [ "$OPT_SESSION_SECRET" != "change-me" ] || die "--session-secret tidak boleh 'change-me'."
                ;;
            --ssl-challenge)
                OPT_SSL_CHALLENGE="${2:?--ssl-challenge butuh nilai}"; shift
                case "$OPT_SSL_CHALLENGE" in
                    http|dns-cloudflare) ;;
                    *) die "--ssl-challenge harus 'http' atau 'dns-cloudflare' (diberikan: '$OPT_SSL_CHALLENGE')." ;;
                esac
                ;;
            --cloudflare-creds) OPT_CLOUDFLARE_CREDS="${2:?--cloudflare-creds butuh nilai}"; shift ;;
            --billing)
                OPT_BILLING="${2:?--billing butuh nilai}"; shift
                case "$OPT_BILLING" in
                    true|false) ;;
                    *) die "--billing harus 'true' atau 'false' (diberikan: '$OPT_BILLING')." ;;
                esac
                ;;
            --restic-repository) OPT_RESTIC_REPOSITORY="${2:?--restic-repository butuh nilai}"; shift ;;
            --aws-access-key-id) OPT_AWS_ACCESS_KEY_ID="${2:?--aws-access-key-id butuh nilai}"; shift ;;
            --aws-secret-access-key) OPT_AWS_SECRET_ACCESS_KEY="${2:?--aws-secret-access-key butuh nilai}"; shift ;;
            --aws-default-region) OPT_AWS_DEFAULT_REGION="${2:?--aws-default-region butuh nilai}"; shift ;;
            -h|--help) usage; exit 0 ;;
            *) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
        esac
        shift
    done

    if [ -n "$OPT_ADMIN_USER" ] && ! printf '%s' "$OPT_ADMIN_USER" | grep -qE '^[a-z0-9][a-z0-9._-]{0,31}$'; then
        die "--admin-user tidak valid: '$OPT_ADMIN_USER' (huruf kecil/angka/._-, 1-32 karakter)."
    fi

    if [ "$DEPS_ONLY" -eq 1 ] && [ "$ENV_ONLY" -eq 1 ]; then
        die "--deps-only tidak bisa digabung dengan --env-only (pilih salah satu)."
    fi
}

# ---------------------------------------------------------------- OS & paket
detect_os() {
    [ -r /etc/os-release ] || die "/etc/os-release tidak ditemukan — distro tidak dikenal."
    OS_ID="$(read_os_field ID)"
    OS_LIKE="$(read_os_field ID_LIKE)"
    OS_CODENAME="$(read_os_field VERSION_CODENAME)"
    OS_PRETTY="$(read_os_field PRETTY_NAME)"
    OS_PRETTY="${OS_PRETTY:-$OS_ID}"
    case " $OS_ID $OS_LIKE " in
        *" ubuntu "*|*" debian "*) ;;
        *)
            die "Distro '$OS_PRETTY' belum didukung installer otomatis. Saat ini installer hanya mendukung Ubuntu 22.04/24.04 (dan Debian sebagai fallback)."
            ;;
    esac
}

read_os_field() {
    local key="$1" line=""
    line="$(grep -E "^${key}=" /etc/os-release 2>/dev/null | tail -n1)" || line=""
    line="${line#*=}"
    line="${line%\"}"; line="${line#\"}"
    line="${line%\'}"; line="${line#\'}"
    printf '%s' "$line"
}

detect_pkgmgr() {
    if command -v apt-get >/dev/null 2>&1; then PKGMGR="apt";
    elif command -v apk >/dev/null 2>&1; then PKGMGR="apk";
    elif command -v dnf >/dev/null 2>&1; then PKGMGR="dnf";
    elif command -v yum >/dev/null 2>&1; then PKGMGR="yum";
    else PKGMGR="";
    fi
}

# ---------------------------------------------------------------- .env helpers
set_env() {
    local key="$1" value="${2-}" escaped
    value="${value//$'\r'/}"
    value="${value//$'\n'/}"
    escaped="$(printf '%s' "$value" | sed -e 's/[&|\\]/\\&/g')"
    if grep -qE "^${key}=" "$ENV_FILE" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" "$ENV_FILE"
    else
        # .env.example bisa berakhir tanpa newline → jangan menempel ke baris terakhir.
        if [ -n "$(tail -c 1 "$ENV_FILE" 2>/dev/null)" ]; then
            printf '\n' >> "$ENV_FILE"
        fi
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

env_get() {
    grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- || true
}

# ---------------------------------------------------------------- wizard
collect_config() {
    local adv="n"

    if is_interactive && [ -z "$OPT_DOMAIN" ]; then
        ask OPT_DOMAIN "Domain dasar dashboard (mis. example.com)" ""
    fi
    [ -n "$OPT_DOMAIN" ] || die "APP_DOMAIN wajib diisi — gunakan --domain <domain> (atau jalankan interaktif)."

    if [ -z "$OPT_EMAIL" ]; then
        if is_interactive; then
            ask OPT_EMAIL "Email admin (untuk SSL Let's Encrypt)" "admin@${OPT_DOMAIN}"
        else
            OPT_EMAIL="admin@${OPT_DOMAIN}"
        fi
    fi

    if [ -z "$OPT_PORT" ] && is_interactive; then
        ask OPT_PORT "Port dashboard (APP_PORT)" "8000"
    fi
    OPT_PORT="${OPT_PORT:-8000}"

    if [ -z "$OPT_RANGE_START" ] && is_interactive; then
        ask OPT_RANGE_START "Rentang port app — mulai (PORT_RANGE_START)" "30000"
    fi
    OPT_RANGE_START="${OPT_RANGE_START:-30000}"

    if [ -z "$OPT_RANGE_END" ] && is_interactive; then
        ask OPT_RANGE_END "Rentang port app — akhir (PORT_RANGE_END)" "30999"
    fi
    OPT_RANGE_END="${OPT_RANGE_END:-30999}"

    if is_interactive && [ "$ADMIN_USER_GIVEN" -eq 0 ]; then
        ask OPT_ADMIN_USER "Username admin" "admin"
    fi
    OPT_ADMIN_USER="${OPT_ADMIN_USER:-admin}"

    # Password admin TIDAK ditanyakan di sini: `create-admin.sh` membuat acak &
    # mencetaknya sekali. Mengirim password lewat argv (`--password`) membuatnya
    # terlihat di `ps`; biarkan kosong kecuali untuk otomasi tepercaya.
    if [ -n "$OPT_ADMIN_PASS" ]; then
        warn "--admin-password diberikan lewat argv (terlihat di ps selama proses berjalan)."
    fi

    if is_interactive; then
        printf '  Konfigurasi lanjutan (DNS Cloudflare, billing, S3/restic)? [y/N]: ' >&2
        read -r -u "$PROMPT_FD" adv || adv=""
    fi
    case "${adv:-n}" in
        y|Y|yes|YES) CONF_ADV=1 ;;
        *) CONF_ADV=0 ;;
    esac

    if [ "$CONF_ADV" -eq 1 ]; then
        if [ -z "$OPT_SSL_CHALLENGE" ]; then
            ask OPT_SSL_CHALLENGE "Mode challenge SSL (http|dns-cloudflare)" "http"
        fi
        if [ "$OPT_SSL_CHALLENGE" = "dns-cloudflare" ] && [ -z "$OPT_CLOUDFLARE_CREDS" ]; then
            ask OPT_CLOUDFLARE_CREDS "Path file kredensial Cloudflare di host" "$PROJECT_DIR/secrets/cloudflare.ini"
        fi
        if [ -z "$OPT_BILLING" ]; then
            ask OPT_BILLING "Billing aktif? (true|false)" "true"
        fi
        if [ -z "$OPT_RESTIC_REPOSITORY" ]; then
            ask OPT_RESTIC_REPOSITORY "RESTIC_REPOSITORY (kosong = lewati backup S3)" ""
        fi
        if [ -n "$OPT_RESTIC_REPOSITORY" ]; then
            if [ -z "$OPT_AWS_ACCESS_KEY_ID" ]; then
                ask_secret OPT_AWS_ACCESS_KEY_ID "AWS_ACCESS_KEY_ID"
            fi
            if [ -z "$OPT_AWS_SECRET_ACCESS_KEY" ]; then
                ask_secret OPT_AWS_SECRET_ACCESS_KEY "AWS_SECRET_ACCESS_KEY"
            fi
            if [ -z "$OPT_AWS_DEFAULT_REGION" ]; then
                ask OPT_AWS_DEFAULT_REGION "AWS_DEFAULT_REGION" "ap-southeast-1"
            fi
        fi
    fi

    CONF_DOMAIN="$OPT_DOMAIN"
    CONF_EMAIL="$OPT_EMAIL"
    CONF_PORT="$OPT_PORT"
    CONF_RANGE_START="$OPT_RANGE_START"
    CONF_RANGE_END="$OPT_RANGE_END"
    CONF_SSL_CHALLENGE="${OPT_SSL_CHALLENGE:-http}"
    CONF_CLOUDFLARE_CREDS="$OPT_CLOUDFLARE_CREDS"
    CONF_BILLING="${OPT_BILLING:-true}"
    CONF_RESTIC_REPOSITORY="$OPT_RESTIC_REPOSITORY"
    CONF_AWS_ACCESS_KEY_ID="$OPT_AWS_ACCESS_KEY_ID"
    CONF_AWS_SECRET_ACCESS_KEY="$OPT_AWS_SECRET_ACCESS_KEY"
    CONF_AWS_DEFAULT_REGION="${OPT_AWS_DEFAULT_REGION:-ap-southeast-1}"

    # SESSION_SECRET SELALU acak (tidak pernah ditanyakan, tidak pernah change-me).
    CONF_SECRET="${OPT_SESSION_SECRET:-$(gen_secret)}"
}

validate_config() {
    valid_domain "$CONF_DOMAIN" \
        || die "APP_DOMAIN tidak valid: '$CONF_DOMAIN' (huruf kecil tanpa http://, mis. example.com)."
    if [ "$CONF_DOMAIN" = "example.com" ]; then
        warn "APP_DOMAIN masih 'example.com' — ganti sebelum dipakai produksi."
    fi

    valid_email "$CONF_EMAIL" || die "ADMIN_EMAIL tidak valid: '$CONF_EMAIL'."

    validate_port_value APP_PORT "$CONF_PORT"
    CONF_PORT=$((10#$CONF_PORT))
    case "$CONF_PORT" in
        80|443) die "APP_PORT $CONF_PORT tidak boleh — port itu dipakai Nginx." ;;
    esac

    validate_port_value PORT_RANGE_START "$CONF_RANGE_START"
    validate_port_value PORT_RANGE_END "$CONF_RANGE_END"
    CONF_RANGE_START=$((10#$CONF_RANGE_START))
    CONF_RANGE_END=$((10#$CONF_RANGE_END))

    local p rs re
    p=$CONF_PORT; rs=$CONF_RANGE_START; re=$CONF_RANGE_END
    [ "$rs" -lt "$re" ] || die "PORT_RANGE_START ($CONF_RANGE_START) harus lebih kecil dari PORT_RANGE_END ($CONF_RANGE_END)."
    if [ "$p" -ge "$rs" ] && [ "$p" -le "$re" ]; then
        die "APP_PORT ($CONF_PORT) tidak boleh berada di dalam PORT_RANGE_START..PORT_RANGE_END ($CONF_RANGE_START..$CONF_RANGE_END)."
    fi

    case "$CONF_SSL_CHALLENGE" in
        http) ;;
        dns-cloudflare)
            [ -n "$CONF_CLOUDFLARE_CREDS" ] \
                || die "SSL_CHALLENGE=dns-cloudflare membutuhkan --cloudflare-creds <file>."
            [ -r "$CONF_CLOUDFLARE_CREDS" ] \
                || die "File kredensial Cloudflare tidak ada/tidak terbaca: $CONF_CLOUDFLARE_CREDS"
            ;;
        *) die "SSL_CHALLENGE tidak dikenal: '$CONF_SSL_CHALLENGE' (http|dns-cloudflare)." ;;
    esac

    case "$CONF_BILLING" in
        true|false) ;;
        *) die "BILLING_ENABLED harus 'true' atau 'false' (diberikan: '$CONF_BILLING')." ;;
    esac

    if [ -n "$CONF_RESTIC_REPOSITORY" ] \
        && { [ -z "$CONF_AWS_ACCESS_KEY_ID" ] || [ -z "$CONF_AWS_SECRET_ACCESS_KEY" ]; }; then
        warn "RESTIC_REPOSITORY diisi tetapi AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY kosong — backup S3 akan gagal sampai kredensial dilengkapi."
    fi

    [ -n "$CONF_SECRET" ] && [ "$CONF_SECRET" != "change-me" ] \
        || die "SESSION_SECRET tidak valid (harus nilai acak, bukan 'change-me')."
}

write_env() {
    [ -f "$ENV_EXAMPLE" ] || die ".env.example tidak ditemukan di $PROJECT_DIR"

    info "Membuat $ENV_FILE dari .env.example"
    # umask 077 → file berisi secret dibuat 0600 sejak awal (bukan 0644 sesaat).
    ( umask 077; cp "$ENV_EXAMPLE" "$ENV_FILE" )

    set_env APP_DOMAIN "$CONF_DOMAIN"
    set_env APP_PORT "$CONF_PORT"
    set_env ADMIN_EMAIL "$CONF_EMAIL"
    set_env PORT_RANGE_START "$CONF_RANGE_START"
    set_env PORT_RANGE_END "$CONF_RANGE_END"
    set_env SESSION_SECRET "$CONF_SECRET"
    set_env SSL_CHALLENGE "$CONF_SSL_CHALLENGE"
    set_env CLOUDFLARE_CREDS "$CONF_CLOUDFLARE_CREDS"
    set_env BILLING_ENABLED "$CONF_BILLING"
    set_env RESTIC_REPOSITORY "$CONF_RESTIC_REPOSITORY"
    set_env AWS_ACCESS_KEY_ID "$CONF_AWS_ACCESS_KEY_ID"
    set_env AWS_SECRET_ACCESS_KEY "$CONF_AWS_SECRET_ACCESS_KEY"
    set_env AWS_DEFAULT_REGION "$CONF_AWS_DEFAULT_REGION"

    # Berisi SESSION_SECRET + kredensial S3 → hanya root yang boleh membaca.
    chmod 0600 "$ENV_FILE"
    ok ".env dibuat (APP_DOMAIN=$CONF_DOMAIN, APP_PORT=$CONF_PORT, PORT_RANGE=$CONF_RANGE_START-$CONF_RANGE_END)"
}

handle_existing_env() {
    ok ".env sudah ada — mempertahankan nilai yang ada."
    if [ -n "$OPT_DOMAIN$OPT_PORT$OPT_EMAIL$OPT_RANGE_START$OPT_RANGE_END$OPT_SSL_CHALLENGE$OPT_BILLING$OPT_RESTIC_REPOSITORY$OPT_CLOUDFLARE_CREDS$OPT_SESSION_SECRET$OPT_AWS_ACCESS_KEY_ID$OPT_AWS_SECRET_ACCESS_KEY$OPT_AWS_DEFAULT_REGION" ]; then
        warn "Flag env diabaikan karena .env sudah ada (hapus .env bila ingin dibuat ulang dari awal)."
    fi

    local cur rotate=1 ans=""
    cur="$(env_get SESSION_SECRET)"
    if [ -z "$cur" ] || [ "$cur" = "change-me" ]; then
        if is_interactive; then
            printf '  SESSION_SECRET masih kosong/"change-me" — generate acak? [Y/n]: ' >&2
            read -r -u "$PROMPT_FD" ans || ans=""
            case "${ans:-Y}" in
                y|Y|'') rotate=1 ;;
                *) rotate=0 ;;
            esac
        fi
        if [ "$rotate" -eq 1 ]; then
            set_env SESSION_SECRET "$(gen_secret)"
            ok "SESSION_SECRET diganti nilai acak."
        fi
    fi
    chmod 0600 "$ENV_FILE"
}

# ---------------------------------------------------------------- install deps
install_deps() {
    detect_pkgmgr
    case "$PKGMGR" in
        apt) detect_os; install_deps_apt ;;
        apk|dnf|yum) install_deps_alt ;;
        *) warn "Package manager tidak dikenal — lewati instalasi paket (gunakan --no-deps bila sengaja)." ;;
    esac
}

install_deps_apt() {
    local pkgs=(nginx inotify-tools certbot python3-certbot-dns-cloudflare git ca-certificates curl gnupg lsb-release openssl)

    info "Menginstal paket host: ${pkgs[*]}"
    apt-get update -qq || die "apt-get update gagal — periksa koneksi/repo APT."
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${pkgs[@]}" \
        || die "Instalasi paket host gagal. Perbaiki repo APT lalu ulangi."

    ensure_docker
    ensure_nginx_running
}

install_deps_alt() {
    local pkgs=()
    case "$PKGMGR" in
        apk) pkgs=(nginx inotify-tools certbot certbot-dns-cloudflare) ;;
        dnf|yum) pkgs=(nginx inotify-tools certbot python3-certbot-dns-cloudflare) ;;
    esac

    info "Menginstal paket host: ${pkgs[*]}"
    case "$PKGMGR" in
        apk) apk add --no-cache "${pkgs[@]}" ;;
        dnf|yum) "$PKGMGR" install -y "${pkgs[@]}" ;;
    esac

    if ! docker_ready; then
        warn "docker/compose v2 belum siap — mencoba instal paket distro ..."
        case "$PKGMGR" in
            apk) apk add --no-cache docker docker-cli-compose \
                     || warn "Gagal instal docker — pasang Docker manual." ;;
            dnf|yum) "$PKGMGR" install -y docker docker-compose-plugin \
                     || warn "Gagal instal docker — pasang Docker manual." ;;
        esac
        systemctl enable --now docker >/dev/null 2>&1 || true
        wait_docker || warn "Docker daemon belum siap — periksa manual sebelum Deploy."
    fi
}

docker_ready() {
    command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1
}

install_docker_repo() {
    local id="$OS_ID" codename="$OS_CODENAME" arch

    [ -n "$id" ] && [ -n "$codename" ] || return 1
    arch="$(dpkg --print-architecture 2>/dev/null)" || return 1
    [ -n "$arch" ] || return 1

    install -m 0755 -d /etc/apt/keyrings || return 1
    curl -fsSL --connect-timeout 10 --max-time 30 "https://download.docker.com/linux/${id}/gpg" \
        -o /etc/apt/keyrings/docker.asc || return 1
    chmod a+r /etc/apt/keyrings/docker.asc || return 1
    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/%s %s stable\n' \
        "$arch" "$id" "$codename" > /etc/apt/sources.list.d/docker.list || return 1

    apt-get update -qq || return 1
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

ensure_docker() {
    if docker_ready; then
        # Binary + compose ada, tetapi daemon bisa saja mati (mis. setelah reboot).
        systemctl enable --now docker >/dev/null 2>&1 \
            || warn "systemctl enable --now docker gagal — Docker mungkin perlu dijalankan manual."
        wait_docker || die "Docker terpasang tetapi daemon tidak siap. Periksa: systemctl status docker; journalctl -u docker -n 50"
        ok "Docker Engine + Compose v2 sudah tersedia & daemon berjalan."
        return 0
    fi

    info "Menginstal Docker Engine + Compose v2 (repo resmi Docker) ..."
    if install_docker_repo; then
        ok "Docker Engine + Compose v2 terpasang dari repo resmi."
    else
        warn "Repo resmi Docker gagal — beralih ke paket distro (versi mungkin lebih lama)."
        rm -f /etc/apt/sources.list.d/docker.list
        apt-get update -qq || true
        if DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io docker-compose-v2; then
            ok "Docker dari paket distro terpasang (docker.io + docker-compose-v2)."
        elif DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io docker-compose-plugin; then
            ok "Docker dari paket distro terpasang (docker.io + docker-compose-plugin)."
        else
            die "Gagal memasang Docker. Pasang manual: https://docs.docker.com/engine/install/ lalu ulangi installer."
        fi
    fi

    systemctl enable --now docker >/dev/null 2>&1 \
        || warn "systemctl enable --now docker gagal — Docker mungkin perlu dijalankan manual."
    wait_docker || die "Docker daemon belum siap setelah 60 detik. Periksa: systemctl status docker; journalctl -u docker -n 50"
}

wait_docker() {
    local i
    for i in $(seq 1 30); do
        if docker info >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    return 1
}

ensure_nginx_running() {
    systemctl enable --now nginx >/dev/null 2>&1 \
        || warn "systemctl enable --now nginx gagal — pastikan Nginx berjalan manual."
    nginx -t >/dev/null 2>&1 \
        || warn "nginx -t gagal — periksa konfigurasi Nginx sebelum deploy app."
}

preflight() {
    command -v docker >/dev/null 2>&1 || die "Perintah 'docker' tidak ditemukan."
    if ! docker info >/dev/null 2>&1; then
        # `--no-deps` tidak memanggil install_deps, jadi pastikan daemon yang
        # SUDAH terpasang tetap dinyalakan sebelum menyerah.
        systemctl enable --now docker >/dev/null 2>&1 \
            || warn "systemctl enable --now docker gagal — Docker mungkin perlu dijalankan manual."
        wait_docker \
            || die "Docker daemon tidak berjalan. Jalankan: systemctl enable --now docker (lalu 'docker info')."
    fi
    docker compose version >/dev/null 2>&1 \
        || die "Plugin 'docker compose' (v2.6+) tidak tersedia. Pasang Docker sesuai https://docs.docker.com/engine/install/"

    command -v nginx >/dev/null 2>&1 || die "Nginx tidak ditemukan. Pasang: apt-get install -y nginx"
    ensure_nginx_running

    if command -v certbot >/dev/null 2>&1; then
        certbot --version >/dev/null 2>&1 || warn "certbot --version gagal — penerbitan SSL mungkin tidak berjalan."
    else
        warn "certbot tidak ditemukan — fitur SSL Let's Encrypt tidak akan berfungsi. Pasang: apt-get install -y certbot python3-certbot-dns-cloudflare"
    fi

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

# ---------------------------------------------------------------- dashboard
start_dashboard() {
    info "Membangun & menjalankan dashboard (docker compose up -d --build) ..."
    ( cd "$PROJECT_DIR" && docker compose up -d --build ) \
        || die "docker compose up gagal — lihat keluaran di atas."
    local i
    for i in $(seq 1 60); do
        if docker inspect -f '{{.State.Running}}' "$CONTAINER_NAME" 2>/dev/null | grep -q true; then
            ok "Container dashboard berjalan ($CONTAINER_NAME) — bootstrap container (composer install + migrasi DB) masih berjalan."
            return 0
        fi
        sleep 2
    done
    warn "Container $CONTAINER_NAME belum 'running' setelah ~2 menit — 40 baris terakhir lognya:"
    docker logs --tail 40 "$CONTAINER_NAME" 2>&1 | sed 's/^/      /' || true
    warn "Periksa 'docker compose ps' & 'docker compose logs webman'."
}

# User admin pertama dibuat oleh `host/create-admin.sh` (satu sumber kebenaran):
# script itu MENUNGGU bootstrap container selesai (GET /healthz dari dalam
# container) sebelum `php webman make:admin` — tanpa itu make:admin balapan
# dengan `composer install` dan gagal 'Failed opening required vendor/autoload.php'.
create_admin() {
    local script="$SCRIPT_DIR/create-admin.sh"
    if [ ! -x "$script" ]; then
        ADMIN_CREATE_FAILED=1
        warn "host/create-admin.sh tidak ditemukan/tidak executable — user admin pertama tidak dibuat."
        return 0
    fi

    info "Membuat user admin pertama (tunggu dashboard siap) ..."
    local args=(--user "$OPT_ADMIN_USER")
    if [ -n "$OPT_ADMIN_PASS" ]; then
        args+=(--password "$OPT_ADMIN_PASS")
    fi

    local rc=0
    "$script" "${args[@]}" || rc=$?

    if [ "$rc" -eq 0 ]; then
        ok "Langkah user admin selesai."
        return 0
    fi

    ADMIN_CREATE_FAILED=1
    warn "User admin pertama BELUM dibuat (exit $rc) — ulangi setelah dashboard siap:"
    warn "  sudo $script --user $OPT_ADMIN_USER"
    return 0
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

# ---------------------------------------------------------------- backup volume (fitur 7)
install_volume_backup_timer() {
    local script_src="$SCRIPT_DIR/backup.sh"
    local unit_src="$SCRIPT_DIR/systemd/volume-backup.service"
    local timer_src="$SCRIPT_DIR/systemd/volume-backup.timer"
    local script_dst="/usr/local/bin/rames-volume-backup.sh"
    local unit_dst="/etc/systemd/system/volume-backup.service"
    local timer_dst="/etc/systemd/system/volume-backup.timer"
    local env_dst="/etc/rames/volume-backup.env"

    [ -f "$script_src" ] && [ -f "$unit_src" ] && [ -f "$timer_src" ] \
        || { warn "File backup tidak lengkap di host/ — lewati instalasi timer backup volume."; return; }

    install -m 0755 "$script_src" "$script_dst"
    install -m 0644 "$unit_src" "$unit_dst"
    install -m 0644 "$timer_src" "$timer_dst"

    mkdir -p /etc/rames
    cat > "$env_dst" <<EOF
VOLUME_BACKUP_HOST_CONTAINER=$CONTAINER_NAME
DOCKER=docker
VOLUME_BACKUP_TIMEOUT=3900
EOF
    chmod 0640 "$env_dst"

    systemctl daemon-reload
    systemctl enable --now volume-backup.timer >/dev/null 2>&1 || true
    ok "Timer backup volume diinstal (volume-backup.timer, harian 02:30)."
    warn "Isi kredensial S3 di .env (RESTIC_REPOSITORY, AWS_*) & passphrase restic di database/restic/password (chmod 0600) sebelum backup pertama."
}

# ---------------------------------------------------------------- summary
detect_host_ip() {
    local ip=""
    if command -v ip >/dev/null 2>&1; then
        ip="$(ip route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')" || ip=""
    fi
    if [ -z "$ip" ] && command -v hostname >/dev/null 2>&1; then
        ip="$(hostname -I 2>/dev/null | awk '{print $1}')" || ip=""
    fi
    printf '%s' "${ip:-localhost}"
}

print_summary() {
    local domain port ip repo_uid git_prefix=""
    domain="$(env_get APP_DOMAIN)"
    domain="${domain:-example.com}"
    port="$(env_get APP_PORT)"
    port="${port:-8000}"
    ip="$(detect_host_ip)"

    # Clone default (/opt/rames) dimiliki root; checkout pilihan user dimiliki
    # user itu. `sudo git pull` pada repo milik user justru membuat .git milik
    # root dan mematahkan self-update (lihat README Prasyarat Host).
    repo_uid="$(stat -c '%u' "$PROJECT_DIR" 2>/dev/null || printf '0')"
    if [ "$repo_uid" = "0" ]; then
        git_prefix="sudo "
    fi

    echo
    echo "${C_GREEN}==================================================${C_RESET}"
    echo "${C_GREEN} Rames terpasang!${C_RESET}"
    echo "${C_GREEN}==================================================${C_RESET}"
    echo "  Dashboard : http://${ip}:${port}"
    echo "  Subdomain : {app}.${domain}"
    echo "  Lokasi    : $PROJECT_DIR"
    echo
    echo "  Update:"
    echo "    ${git_prefix}git -C \"$PROJECT_DIR\" pull && sudo \"$PROJECT_DIR/host/install.sh\" --no-deps"
    echo
    echo "  Service aktif:"
    echo "    - dashboard-nginx-watcher.service (reload otomatis Nginx)"
    echo "    - certbot-renew.timer (renewal SSL 2×/hari)"
    echo "    - volume-backup.timer (backup volume ke S3, harian 02:30)"
    echo
    echo "  Langkah berikutnya:"
    echo "    - Log in dengan kredensial admin (dicetak saat admin pertama dibuat)"
    echo "    - Buka dashboard, login, lalu Create App dari repo Git berisi docker-compose.yml"
    echo "    - Buat/ulang user admin kapan saja: sudo $SCRIPT_DIR/create-admin.sh [username] [password]"
    echo "    - DNS: arahkan ${domain} DAN wildcard *.${domain} ke IP server ini ($ip)"
    echo "    - Isi kredensial S3 di .env (RESTIC_REPOSITORY, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION)"
    echo "      dan passphrase restic di database/restic/password (chmod 0600) agar backup volume berjalan"
    echo "    - Periksa service: systemctl status dashboard-nginx-watcher certbot-renew.timer volume-backup.timer"

    if [ "$ADMIN_CREATE_FAILED" -ne 0 ]; then
        echo
        echo "${C_RED}==================================================${C_RESET}"
        echo "${C_RED} PERHATIAN: user admin belum dibuat — tanpa itu tidak ada yang bisa login.${C_RESET}"
        echo "${C_RED}==================================================${C_RESET}"
        echo "  Setelah dashboard siap (bootstrap container selesai), jalankan:"
        echo "    sudo $SCRIPT_DIR/create-admin.sh --user $OPT_ADMIN_USER"
    fi

    if [ -z "$(env_get SESSION_SECRET)" ] || [ "$(env_get SESSION_SECRET)" = "change-me" ]; then
        echo
        warn "SESSION_SECRET masih kosong/change-me — dashboard menolak start dengan secret default; isi di $ENV_FILE."
    fi
}

# ---------------------------------------------------------------- main
main() {
    parse_args "$@"
    OPT_ADMIN_USER="${OPT_ADMIN_USER:-admin}"

    # --env-only hanya menulis .env (tanpa /etc, Docker, systemd) → tidak butuh root.
    if [ "$ENV_ONLY" -eq 0 ]; then
        ensure_root "$@"
    fi

    info "Rames installer — project: $PROJECT_DIR"

    # --deps-only: pasang dependensi host lalu keluar SEBELUM wizard .env,
    # preflight, dan `compose up`. Dipakai `install.agent.sh` (server agent).
    # Aman di host yang sudah punya dashboard: .env/compose/container tidak disentuh.
    if [ "$DEPS_ONLY" -eq 1 ]; then
        if [ "$NO_DEPS" -eq 0 ]; then
            install_deps
        else
            info "--no-deps: melewatkan instalasi paket host."
        fi
        # Klaim "siap" hanya bila benar-benar siap: --no-deps atau package
        # manager tak dikenal bisa membuat instalasi di atas dilewati.
        if docker compose version >/dev/null 2>&1 && command -v nginx >/dev/null 2>&1 && command -v certbot >/dev/null 2>&1; then
            ok "Dependensi host siap (docker compose, nginx, certbot)."
        else
            warn "Sebagian dependensi host belum siap — periksa keluaran di atas (docker compose / nginx / certbot)."
        fi
        return 0
    fi

    # Nilai env dikumpulkan & divalidasi lebih dulu, tetapi .env BARU ditulis
    # setelah preflight lolos — kegagalan Docker/Nginx tidak meninggalkan .env
    # setengah jadi yang akan dipakai ulang (dan mengabaikan flag) pada rerun.
    NEED_ENV=0
    if [ -f "$ENV_FILE" ]; then
        handle_existing_env
    else
        collect_config
        validate_config
        NEED_ENV=1
    fi

    if [ "$ENV_ONLY" -eq 1 ]; then
        if [ "$NEED_ENV" -eq 1 ]; then
            write_env
        fi
        ok "--env-only: .env siap; instalasi paket/Docker/systemd dilewati."
        return 0
    fi

    if [ "$NO_DEPS" -eq 0 ]; then
        install_deps
    else
        info "--no-deps: melewatkan instalasi paket host."
    fi

    preflight

    if [ "$NEED_ENV" -eq 1 ]; then
        write_env
    fi

    ensure_nginx_dirs
    ensure_nginx_include
    create_watcher_user
    start_dashboard
    create_admin
    install_watcher
    install_certbot_timer
    install_volume_backup_timer
    print_summary
}

main "$@"
