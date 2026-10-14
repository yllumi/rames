#!/usr/bin/env bash
#
# Rames — Installer server AGENT (multi-server F0; plan §8, §10.1, §10.2)
#
# Menyiapkan server #2..N sebagai "agent" yang dikendalikan dashboard:
#   1. Prasyarat: docker + docker compose v2.6+, openssl, nginx, certbot
#      (hanya DILAPORKAN — installer tidak pernah memasang paket)
#   2. Rahasia (0600): secrets/agent.env + sertifikat TLS self-signed (secrets/agent/)
#   3. Direktori artefak: apps/, database/{keys,env}, runtime/, nginx-status/, dir Nginx host
#   4. Agent: docker compose -f docker-compose.agent.yml -p rames-agent up -d --build
#   5. Verifikasi: GET https://127.0.0.1:<port>/version (dengan header token)
#   6. Blok onboarding: URL + token + fingerprint SHA-256 + PEM sertifikat
#      (bahan form Add Server di dashboard)
#
# Pemakaian:
#   ./agent/install.sh --domain domain2.com [--port-range 30000 30999]
#                      [--bind 0.0.0.0:8443] [--ip 10.0.0.12] [--force]
#
# Idempoten: aman dijalankan ulang. Tanpa `--force`, token agent, sertifikat TLS,
# dan nilai yang sudah ada di secrets/agent.env DIPERTAHANKAN (hanya diisi bila
# belum ada). `--force` meregenerasi token + sertifikat (perlu diperbarui manual
# di dashboard: Add Server / rotasi) dan memaksa recreate container.
#
# Yang TIDAK pernah dilakukan installer ini: memasang paket, mengedit nginx.conf,
# dan `docker compose down` pada project lain (project dashboard `rames`).
set -euo pipefail

# ---------------------------------------------------------------- path
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPOSE_FILE="$PROJECT_DIR/docker-compose.agent.yml"
PROJECT_NAME="rames-agent"
CONTAINER_NAME="rames-agent"

SECRETS_DIR="$PROJECT_DIR/secrets"
SECRETS_ENV="$SECRETS_DIR/agent.env"
AGENT_TLS_DIR="$SECRETS_DIR/agent"
AGENT_TLS_CERT="$AGENT_TLS_DIR/tls.crt"
AGENT_TLS_KEY="$AGENT_TLS_DIR/tls.key"

NGINX_CONF_DEFAULT="/etc/nginx/nginx.conf"
NGINX_AVAILABLE="${NGINX_CONF_PATH:-/etc/nginx/sites-available}"
NGINX_ENABLED="${NGINX_ENABLED_PATH:-/etc/nginx/sites-enabled}"

# Port internal agent DIPATOK: mapping publish di docker-compose.agent.yml
# adalah "${AGENT_PORT_PUBLISH:-8443}:8443" (keputusan 6: agent di port sendiri,
# TLS diterminasi di proses agent).
AGENT_INTERNAL_PORT=8443

# ---------------------------------------------------------------- flags
OPT_DOMAIN=""
OPT_RANGE_START=""
OPT_RANGE_END=""
OPT_BIND=""
OPT_IP=""
FORCE=0

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
Rames — Installer server agent (multi-server)

Pemakaian:
  $0 --domain <domain> [opsi]

Opsi:
  --domain <d>            APP_DOMAIN server ini (mis. domain2.com). WAJIB pada
                          instalasi pertama; nilai lama dipakai bila sudah ada.
  --port-range <s> <e>    rentang host port untuk container app
                          (default 30000 30999; nilai lama dipertahankan)
  --bind <ip:port>        AGENT_BIND — alamat listen agent DI DALAM container
                          (default 0.0.0.0:8443; port lain hanya boleh bila
                          AGENT_PORT_PUBLISH diset sama)
  --ip <ip>               IP server ini untuk SAN sertifikat & URL onboarding
                          (pakai bila deteksi otomatis gagal/salah)
  --force                 regenerasi token agent + sertifikat TLS, lalu
                          force-recreate container (perbarui di dashboard!)
  -h, --help              tampilkan bantuan ini
EOF
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --domain) OPT_DOMAIN="${2:?--domain butuh nilai}"; shift ;;
            --port-range)
                OPT_RANGE_START="${2:?--port-range butuh <start> <end>}"
                OPT_RANGE_END="${3:?--port-range butuh <start> <end>}"
                shift 2
                ;;
            --bind) OPT_BIND="${2:?--bind butuh nilai}"; shift ;;
            --ip) OPT_IP="${2:?--ip butuh nilai}"; shift ;;
            --force) FORCE=1 ;;
            -h|--help) usage; exit 0 ;;
            *) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
        esac
        shift
    done
}

# ---------------------------------------------------------------- helpers
# Baca nilai KEY=... dari berkas env (baris terakhir menang, tanda kutip dibuang).
read_env_value() {
    local key="$1" file="$2" value=""
    [ -f "$file" ] || return 0
    value="$(sed -n "s/^${key}=//p" "$file" | tail -n1)"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    printf '%s' "$value"
}

# Deteksi IP utama host (untuk SAN sertifikat & URL onboarding).
detect_ip() {
    local ip=""
    if command -v ip >/dev/null 2>&1; then
        ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") {print $(i+1); exit}}')"
    fi
    if [ -z "$ip" ] && command -v hostname >/dev/null 2>&1; then
        ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    fi
    printf '%s' "$ip"
}

# IP pertama dari SAN sertifikat yang sudah ada (cadangan bila deteksi gagal).
cert_san_ip() {
    local file="$1"
    [ -f "$file" ] || return 0
    command -v openssl >/dev/null 2>&1 || return 0
    openssl x509 -in "$file" -noout -text 2>/dev/null \
        | grep -oE 'IP Address:[0-9]{1,3}(\.[0-9]{1,3}){3}' \
        | head -n1 | cut -d: -f2
}

cert_fingerprint_sha256() {
    local file="$1" out=""
    out="$(openssl x509 -in "$file" -noout -fingerprint -sha256 2>/dev/null | sed 's/^.*=//')" || true
    printf '%s' "$out"
}

# Versi agent dibaca dari sumber tunggalnya (AgentController::VERSION) tanpa
# butuh PHP di host — kode itu dijalankan container, bukan installer.
agent_version() {
    local file="$PROJECT_DIR/app/controller/AgentController.php" version=""
    if [ -f "$file" ]; then
        version="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$file" | head -n1)"
    fi
    [ -n "$version" ] || version="unknown"
    printf '%s' "$version"
}

# ---------------------------------------------------------------- prasyarat
check_prereqs() {
    info "Memeriksa prasyarat (installer TIDAK memasang paket apa pun) ..."
    local missing=0

    if command -v docker >/dev/null 2>&1; then
        ok "docker: $(docker --version 2>/dev/null | head -n1)"
        if docker compose version >/dev/null 2>&1; then
            ok "docker compose tersedia (butuh v2.6+)"
        else
            warn "plugin 'docker compose' (butuh v2.6+) tidak tersedia — pasang Docker Engine + plugin compose: https://docs.docker.com/engine/install/"
            missing=1
        fi
        if docker info >/dev/null 2>&1; then
            ok "Docker daemon dapat dihubungi"
        else
            warn "tidak bisa bicara dengan Docker daemon — tambahkan user ini ke grup docker (sudo usermod -aG docker \$USER, lalu login ulang) atau jalankan installer ini sebagai root"
            missing=1
        fi
    else
        warn "docker tidak ditemukan — pasang Docker Engine: https://docs.docker.com/engine/install/"
        missing=1
    fi

    if command -v openssl >/dev/null 2>&1; then
        ok "openssl tersedia (token agent + sertifikat TLS self-signed)"
    else
        warn "openssl tidak ditemukan — dibutuhkan untuk token & sertifikat TLS: sudo apt-get install openssl"
        missing=1
    fi

    # nginx & certbot belum dibutuhkan saat instalasi agent (dipakai kemudian
    # untuk melayani app + menerbitkan SSL) → dilaporkan, tidak menggagalkan.
    if command -v nginx >/dev/null 2>&1; then
        ok "nginx tersedia (host melayani app)"
    else
        warn "nginx belum terpasang — WAJIB untuk melayani app di server ini: sudo apt-get install nginx (agent sendiri tetap bisa diinstall sekarang)"
    fi
    if command -v certbot >/dev/null 2>&1; then
        ok "certbot tersedia (SSL Let's Encrypt app)"
    else
        warn "certbot belum terpasang — dibutuhkan saat menerbitkan SSL app: sudo apt-get install certbot python3-certbot-nginx"
    fi
    command -v curl >/dev/null 2>&1 || warn "curl belum terpasang — verifikasi /version dilewati; perintahnya tetap dicetak di akhir"

    [ "$missing" -eq 0 ] || die "prasyarat wajib kurang (lihat tanda ! di atas) — perbaiki dulu, lalu jalankan ulang installer ini"
}

# ---------------------------------------------------------------- direktori
ensure_dirs() {
    info "Menyiapkan direktori ..."
    mkdir -p "$SECRETS_DIR" "$AGENT_TLS_DIR" || die "gagal membuat $SECRETS_DIR (periksa izin tulis repo)"
    chmod 700 "$SECRETS_DIR" "$AGENT_TLS_DIR"
    mkdir -p "$PROJECT_DIR/apps" "$PROJECT_DIR/runtime" "$PROJECT_DIR/nginx-status"
    mkdir -p "$PROJECT_DIR/database/keys" "$PROJECT_DIR/database/env"
    ok "rahasia: $SECRETS_DIR (0700) — token & sertifikat TLS agent"
    ok "artefak: apps/, database/{keys,env}, runtime/, nginx-status/"

    if mkdir -p "$NGINX_AVAILABLE" "$NGINX_ENABLED" 2>/dev/null; then
        ok "direktori Nginx: $NGINX_AVAILABLE, $NGINX_ENABLED"
    else
        warn "tidak bisa membuat $NGINX_AVAILABLE / $NGINX_ENABLED (perlu root) — jalankan: sudo mkdir -p $NGINX_AVAILABLE $NGINX_ENABLED"
    fi

    # Installer TIDAK mengedit nginx.conf; hanya memperingatkan (pola host/install.sh).
    if [ -f "$NGINX_CONF_DEFAULT" ]; then
        if grep -qE '^\s*include\s+.*sites-enabled.*;' "$NGINX_CONF_DEFAULT"; then
            ok "nginx.conf sudah meng-include $NGINX_ENABLED/"
        else
            warn "$NGINX_CONF_DEFAULT belum meng-include $NGINX_ENABLED/* — config app yang ditulis agent tidak akan dimuat. Tambahkan manual: include $NGINX_ENABLED/*;"
        fi
    else
        warn "$NGINX_CONF_DEFAULT tidak ditemukan — pastikan Nginx host terpasang & meng-include $NGINX_ENABLED/*"
    fi
}

# ---------------------------------------------------------------- rahasia
generate_token() {
    local hex=""
    if command -v openssl >/dev/null 2>&1; then
        hex="$(openssl rand -hex 32)"
    else
        hex="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    fi
    printf 'agt_%s' "$hex"
}

write_secrets_env() {
    local token="$1" domain="$2" start="$3" end="$4" bind="$5"
    info "Menulis $SECRETS_ENV (0600) ..."
    if ( umask 077; cat > "$SECRETS_ENV.tmp" <<EOF
# Rames agent — dibuat oleh agent/install.sh. JANGAN di-commit (/secrets/ gitignored).
# Sumber tunggal identitas + rahasia agent: diinjeksi ke container lewat
# `env_file: ./secrets/agent.env` di docker-compose.agent.yml.
RAMES_MODE=agent
AGENT_TOKEN=${token}
AGENT_BIND=${bind}
AGENT_TLS_CERT=${AGENT_TLS_CERT}
AGENT_TLS_KEY=${AGENT_TLS_KEY}
APP_DOMAIN=${domain}
PORT_RANGE_START=${start}
PORT_RANGE_END=${end}
EOF
    ); then
        mv -f "$SECRETS_ENV.tmp" "$SECRETS_ENV"
        chmod 600 "$SECRETS_ENV"
        ok "AGENT_TOKEN + AGENT_TLS_CERT/KEY + APP_DOMAIN + AGENT_BIND tersimpan (0600)"
    else
        rm -f "$SECRETS_ENV.tmp"
        die "gagal menulis $SECRETS_ENV"
    fi
}

ensure_certificate() {
    local san_ip="$1" san_host="$2"
    if [ -f "$AGENT_TLS_CERT" ] && [ -f "$AGENT_TLS_KEY" ] && [ "$FORCE" -eq 0 ]; then
        chmod 600 "$AGENT_TLS_KEY" 2>/dev/null || true
        ok "sertifikat TLS agent dipertahankan: $AGENT_TLS_CERT (pakai --force untuk regenerasi)"
        return 0
    fi

    info "Membuat sertifikat TLS self-signed agent (CN=rames-agent, 825 hari) ..."
    if ! openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
        -subj "/CN=rames-agent" \
        -addext "subjectAltName=IP:${san_ip},IP:127.0.0.1,DNS:${san_host},DNS:localhost" \
        -keyout "$AGENT_TLS_KEY" -out "$AGENT_TLS_CERT" >/dev/null 2>&1; then
        rm -f "$AGENT_TLS_CERT" "$AGENT_TLS_KEY"
        die "gagal membuat sertifikat self-signed (butuh openssl >= 1.1.1 untuk -addext)"
    fi
    chmod 600 "$AGENT_TLS_KEY"
    chmod 644 "$AGENT_TLS_CERT"
    ok "sertifikat: $AGENT_TLS_CERT | kunci: $AGENT_TLS_KEY (0600); SAN IP:${san_ip},DNS:${san_host}"
}

# ---------------------------------------------------------------- agent
start_agent() {
    [ -f "$COMPOSE_FILE" ] || die "berkas paket agent tidak ditemukan: $COMPOSE_FILE"
    info "Menjalankan agent: docker compose -f docker-compose.agent.yml -p $PROJECT_NAME up -d --build ..."
    local extra=()
    [ "$FORCE" -eq 1 ] && extra+=(--force-recreate)
    # PWD=$PROJECT_DIR eksplisit: compose memakai "${PWD}" untuk working_dir & bind.
    (
        cd "$PROJECT_DIR"
        PWD="$PROJECT_DIR" docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" up -d --build "${extra[@]+"${extra[@]}"}"
    ) || die "docker compose up gagal — lihat keluaran di atas"
    ok "container $CONTAINER_NAME dijalankan (project $PROJECT_NAME)"
}

wait_running() {
    local tries=0 state=""
    while [ "$tries" -lt 30 ]; do
        state="$(docker inspect -f '{{.State.Running}}' "$CONTAINER_NAME" 2>/dev/null || true)"
        if [ "$state" = "true" ]; then
            ok "container $CONTAINER_NAME running"
            return 0
        fi
        tries=$((tries + 1))
        sleep 2
    done
    warn "container $CONTAINER_NAME belum running setelah ~60 dtk — periksa: docker compose -f docker-compose.agent.yml -p $PROJECT_NAME logs webman --tail 80"
    return 0
}

verify_version() {
    local port="$1" token="$2" url="https://127.0.0.1:${port}/version" body="" code="" response=""
    local shown_cmd="curl -sS -k -H \"Authorization: Bearer <token>\" $url"
    if ! command -v curl >/dev/null 2>&1; then
        warn "curl tidak tersedia — verifikasi manual:"
        echo "     $shown_cmd"
        return 0
    fi
    info "Verifikasi: GET $url (dengan header token) ..."
    set +e
    response="$(curl -sk --max-time 10 -w '\n%{http_code}' -H "Authorization: Bearer ${token}" "$url" 2>/dev/null)"
    set -e
    if [ -z "$response" ]; then
        warn "tidak ada respons dari agent — periksa log: docker compose -f docker-compose.agent.yml -p $PROJECT_NAME logs webman --tail 80"
        echo "     $shown_cmd"
        return 0
    fi
    code="$(printf '%s' "$response" | tail -n1)"
    body="$(printf '%s' "$response" | sed '$d')"
    if [ "$code" = "200" ]; then
        ok "HTTP 200 — $body"
    else
        warn "HTTP ${code} (diharapkan 200) — periksa log: docker compose -f docker-compose.agent.yml -p $PROJECT_NAME logs webman --tail 80"
        [ -n "$body" ] && echo "     body: $body"
        echo "     perintah: $shown_cmd"
    fi
}

# ---------------------------------------------------------------- onboarding
print_onboarding() {
    local url="$1" token="$2" fingerprint="$3" version="$4" port="$5"
    cat <<EOF

================================================================================
 ONBOARDING SERVER AGENT — tempel ini ke dashboard (menu Servers → Add Server)
================================================================================
URL         : ${url}
Token       : ${token}
Fingerprint : SHA256 ${fingerprint}
Version     : rames-agent ${version}
Domain      : ${EFFECTIVE_DOMAIN}
Port range  : ${EFFECTIVE_RANGE_START}-${EFFECTIVE_RANGE_END}

Token hanya ditampilkan di sini (tersimpan 0600 di ${SECRETS_ENV}). Fingerprint
dihitung ulang dashboard dari PEM di bawah, jadi tidak ada peluang salah ketik —
nilai di atas untuk verifikasi manual admin.

-----BEGIN ONBOARDING PEM-----
EOF
    cat "$AGENT_TLS_CERT"
    cat <<EOF
-----END ONBOARDING PEM-----

PENTING — FIREWALL: port ${port} WAJIB dibatasi hanya untuk IP control plane.
Port ini pintu eksekusi Docker server ini — JANGAN dibuka ke publik.
  ufw    : sudo ufw allow from <ip-control-plane> to any port ${port} proto tcp
  iptables: sudo iptables -A INPUT -p tcp --dport ${port} -s <ip-control-plane> -j ACCEPT
            sudo iptables -A INPUT -p tcp --dport ${port} -j DROP

Rotasi rahasia: ./agent/install.sh --force (token + sertifikat baru) lalu
perbarui server di dashboard (fingerprint baru ⇒ Add Server/rotasi).
================================================================================
EOF
}

# ---------------------------------------------------------------- main
main() {
    parse_args "$@"

    [ -f "$COMPOSE_FILE" ] || die "berkas paket agent tidak ditemukan: $COMPOSE_FILE"
    check_prereqs

    # --- nilai efektif (nilai lama dipertahankan → idempoten) --------------
    EFFECTIVE_TOKEN="$(read_env_value AGENT_TOKEN "$SECRETS_ENV")"
    EFFECTIVE_DOMAIN="$(read_env_value APP_DOMAIN "$SECRETS_ENV")"
    EFFECTIVE_RANGE_START="$(read_env_value PORT_RANGE_START "$SECRETS_ENV")"
    EFFECTIVE_RANGE_END="$(read_env_value PORT_RANGE_END "$SECRETS_ENV")"
    EFFECTIVE_BIND="$(read_env_value AGENT_BIND "$SECRETS_ENV")"

    [ -n "$OPT_BIND" ] && EFFECTIVE_BIND="$OPT_BIND"
    [ -n "$EFFECTIVE_BIND" ] || EFFECTIVE_BIND="0.0.0.0:${AGENT_INTERNAL_PORT}"

    if [ -n "$OPT_DOMAIN" ]; then
        EFFECTIVE_DOMAIN="$OPT_DOMAIN"
    elif [ -z "$EFFECTIVE_DOMAIN" ]; then
        die "--domain wajib pada instalasi pertama (mis. ./agent/install.sh --domain domain2.com)"
    fi

    if [ -n "$OPT_RANGE_START" ] && [ -n "$OPT_RANGE_END" ]; then
        EFFECTIVE_RANGE_START="$OPT_RANGE_START"
        EFFECTIVE_RANGE_END="$OPT_RANGE_END"
    fi
    [ -n "$EFFECTIVE_RANGE_START" ] || EFFECTIVE_RANGE_START="30000"
    [ -n "$EFFECTIVE_RANGE_END" ] || EFFECTIVE_RANGE_END="30999"

    if [ "$FORCE" -eq 1 ] || [ -z "$EFFECTIVE_TOKEN" ]; then
        EFFECTIVE_TOKEN="$(generate_token)"
    fi

    # --- koherensi bind ↔ publish ------------------------------------------
    local bind_port="${EFFECTIVE_BIND##*:}"
    case "$bind_port" in
        ''|*[!0-9]*) die "AGENT_BIND \"$EFFECTIVE_BIND\" tidak valid — pakai bentuk IP:PORT, mis. 0.0.0.0:8443" ;;
    esac
    if [ "$bind_port" != "$AGENT_INTERNAL_PORT" ]; then
        if [ "${AGENT_PORT_PUBLISH:-}" != "$bind_port" ]; then
            die "port AGENT_BIND ($bind_port) != $AGENT_INTERNAL_PORT (port internal di docker-compose.agent.yml). Pakai $AGENT_INTERNAL_PORT, atau set AGENT_PORT_PUBLISH=$bind_port di environment agar mapping publish cocok."
        fi
        warn "AGENT_BIND memakai port $bind_port: mapping publish mengikuti AGENT_PORT_PUBLISH=$bind_port — SELALU start lewat installer ini (./agent/install.sh) agar mapping tidak kembali ke $AGENT_INTERNAL_PORT."
    fi
    EFFECTIVE_PUBLISH_PORT="${AGENT_PORT_PUBLISH:-$AGENT_INTERNAL_PORT}"
    export AGENT_PORT_PUBLISH="$EFFECTIVE_PUBLISH_PORT"

    # --- IP untuk SAN sertifikat & URL onboarding --------------------------
    EFFECTIVE_IP="$OPT_IP"
    [ -z "$EFFECTIVE_IP" ] && EFFECTIVE_IP="$(detect_ip)"
    if [ -z "$EFFECTIVE_IP" ] && [ -f "$AGENT_TLS_CERT" ]; then
        EFFECTIVE_IP="$(cert_san_ip "$AGENT_TLS_CERT")"
        [ -n "$EFFECTIVE_IP" ] && warn "deteksi IP otomatis gagal — memakai IP dari SAN sertifikat yang ada: $EFFECTIVE_IP"
    fi
    if [ -z "$EFFECTIVE_IP" ]; then
        die "tidak bisa mendeteksi IP server ini — jalankan ulang dengan --ip <ip-server> (untuk SAN sertifikat & URL onboarding)"
    fi

    local san_host=""
    san_host="$(hostname -f 2>/dev/null || hostname 2>/dev/null || true)"
    [ -n "$san_host" ] || san_host="localhost"

    info "Identitas agent: domain=$EFFECTIVE_DOMAIN | bind=$EFFECTIVE_BIND | publish=$EFFECTIVE_PUBLISH_PORT | ip=$EFFECTIVE_IP"

    ensure_dirs
    ensure_certificate "$EFFECTIVE_IP" "$san_host"
    write_secrets_env "$EFFECTIVE_TOKEN" "$EFFECTIVE_DOMAIN" "$EFFECTIVE_RANGE_START" "$EFFECTIVE_RANGE_END" "$EFFECTIVE_BIND"
    start_agent
    wait_running
    verify_version "$EFFECTIVE_PUBLISH_PORT" "$EFFECTIVE_TOKEN"

    print_onboarding \
        "https://${EFFECTIVE_IP}:${EFFECTIVE_PUBLISH_PORT}" \
        "$EFFECTIVE_TOKEN" \
        "$(cert_fingerprint_sha256 "$AGENT_TLS_CERT")" \
        "$(agent_version)" \
        "$EFFECTIVE_PUBLISH_PORT"
}

main "$@"
