#!/usr/bin/env bash
#
# Rames — Installer server AGENT (multi-server F0; plan §8, §10.1, §10.2)
#
# Menyiapkan server #2..N sebagai "agent" yang dikendalikan dashboard:
#   1. Prasyarat: docker + docker compose v2.6+, openssl, nginx, certbot
#      (mode webroot/HTTP-01; plugin DNS Cloudflare opsional)
#      (hanya DILAPORKAN — installer tidak pernah memasang paket)
#   2. Rahasia (0600): secrets/agent.env + sertifikat TLS self-signed (secrets/agent/)
#   3. Direktori artefak: apps/, database/{keys,env}, runtime/, nginx-status/, dir Nginx host
#   4. Agent: docker compose -f docker-compose.agent.yml -p rames-agent up -d --build
#   5. Verifikasi: GET https://127.0.0.1:<port>/version (dengan header token)
#   6. Blok onboarding: URL + token + fingerprint SHA-256 + PEM sertifikat
#      (bahan form Add Server di dashboard)
#
# Pemakaian:
#   ./agent/install.sh --domain domain2.com --email ops@domain2.com \
#                      [--port-range 30000 30999]
#                      [--bind 0.0.0.0:8443] [--ip 10.0.0.12] [--force]
#                      [--ssl-challenge http|dns-cloudflare]
#                      [--ssl-ca-server production|staging]
#                      [--cloudflare-creds /path/cloudflare.ini]
#
# Idempoten: aman dijalankan ulang. Tanpa `--force`, token agent, sertifikat TLS,
# dan nilai yang sudah ada di secrets/agent.env DIPERTAHANKAN (hanya diisi bila
# belum ada, termasuk ADMIN_EMAIL & opsi SSL: nilai lama menang bila opsi tidak
# diberikan). Baris/kunci DI LUAR blok kelolaan installer (mis. SSL_WEBROOT,
# LETSENCRYPT_PATH, kunci kustom operator) juga dipertahankan apa adanya — lihat
# sentinel '# rames-installer:begin' … '# rames-installer:end'. `--force`
# meregenerasi token + sertifikat (perlu diperbarui manual di dashboard:
# Add Server / rotasi) dan memaksa recreate container.
#
# Semua opsi divalidasi fail-fast SEBELUM efek samping apa pun; nilai yang
# berasal dari berkas env dibedakan dari nilai opsi CLI di pesan galat.
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
OPT_EMAIL=""
OPT_SSL_CHALLENGE=""
OPT_SSL_CA_SERVER=""
OPT_CLOUDFLARE_CREDS=""
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
                          Bentuk divalidasi seperti registry server
                          (ServerResolver): huruf kecil, angka, titik, strip —
                          tanpa skema/path/port/spasi (dinormalkan ke lowercase).
  --email <e>             ADMIN_EMAIL — alamat kontak untuk penerbitan SSL
                          Let's Encrypt di server ini (WAJIB ada sebelum job
                          ssl_issue bisa jalan di agent; tanpa ini job SSL
                          selalu gagal "ADMIN_EMAIL belum diatur").
                          Nilai lama dipertahankan bila opsi tidak diberikan
                          atau diberikan kosong (--email "").
  --port-range <s> <e>    rentang host port untuk container app — dua bilangan
                          bulat 1..65535 dengan start < end
                          (default 30000 30999; nilai lama dipertahankan)
  --bind <ip:port>        AGENT_BIND — alamat listen agent DI DALAM container
                          (default 0.0.0.0:8443; port lain hanya boleh bila
                          AGENT_PORT_PUBLISH diset sama)
  --ip <ip>               IPv4 server ini untuk SAN sertifikat & URL onboarding
                          (mis. 10.0.0.12; pakai bila deteksi otomatis
                          gagal/salah — hostname TIDAK diterima, SAN memakai
                          bentuk IP:)
  --ssl-challenge <m>     SSL_CHALLENGE — mode challenge Let's Encrypt:
                            http (default, webroot HTTP-01) | dns-cloudflare
  --ssl-ca-server <s>     SSL_CA_SERVER — production (default) | staging
                          (staging untuk uji coba: sertifikat TIDAK dipercaya)
  --cloudflare-creds <p>  CLOUDFLARE_CREDS — path ABSOLUT berkas kredensial
                          Cloudflare (ini-file untuk plugin certbot
                          --dns-cloudflare), mis. /srv/rames/cloudflare.ini.
                          Harus terbaca container agent (repo di-mount di path
                          yang sama di dalamnya).
  --force                 regenerasi token agent + sertifikat TLS, lalu
                          force-recreate container (perbarui di dashboard!)
  -h, --help              tampilkan bantuan ini

Kunci di luar blok kelolaan installer (mis. SSL_WEBROOT, LETSENCRYPT_PATH, atau
kunci kustom operator) DIPERTAHANKAN apa adanya saat installer dijalankan ulang.
Blok kelolaan ditandai '# rames-installer:begin' … '# rames-installer:end' —
jangan menaruh penyetelan manual di dalamnya (ditimpa tiap rerun).

Contoh:
  ./agent/install.sh --domain domain2.com --email ops@domain2.com
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
            --email) OPT_EMAIL="${2?--email butuh nilai}"; shift ;;
            # Opsi SSL opsional: string KOSONG yang diberikan eksplisit berarti
            # "jangan ubah nilai lama", jadi ${2?} (bukan ${2:?}) — hanya nilai
            # yang benar-benar hilang yang ditolak.
            --ssl-challenge) OPT_SSL_CHALLENGE="${2?--ssl-challenge butuh nilai}"; shift ;;
            --ssl-ca-server) OPT_SSL_CA_SERVER="${2?--ssl-ca-server butuh nilai}"; shift ;;
            --cloudflare-creds) OPT_CLOUDFLARE_CREDS="${2?--cloudflare-creds butuh nilai}"; shift ;;
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

# ---------------------------------------------------------------- validasi
# Semua validator "die" SEBELUM efek samping apa pun (fail-fast) dan MEMBEDAKAN
# nilai dari opsi CLI vs nilai dari berkas env: pesan yang salah sumber
# menyesatkan operator (mis. disuruh memperbaiki `--email` padahal asalnya baris
# di secrets/agent.env). Nilai tervalidasi/ternormalisasi ditaruh di
# VALIDATED_VALUE (pola out-param global, sama seperti EFFECTIVE_* di main()).

# Pesan galat nilai tak sah. `origin`: cli | file | lainnya (mis. auto).
invalid_value_die() {
    local origin="$1" cli_flag="$2" file_key="$3" problem="$4" example="$5"
    case "$origin" in
        file)
            die "$file_key di $SECRETS_ENV tidak valid: $problem — perbaiki baris itu di berkas tersebut, atau jalankan ulang installer dengan ${cli_flag} <nilai benar> untuk menimpanya (contoh: $example)" ;;
        cli)
            die "nilai ${cli_flag} tidak valid: $problem — contoh: $example (lihat --help)" ;;
        *)
            die "nilai tak sah: $problem — jalankan ulang installer dengan $example (lihat --help)" ;;
    esac
}

# Tolak nilai yang bisa merusak berkas env: spasi/tab/baris baru memecah
# `KEY=value`, dan kutip/`#`/`$`/backtick/backslash bisa disalah-artikan `env_file`
# compose atau shell lain yang membaca berkas ini. Whitelist karakter di bawah.
assert_env_safe() {
    local origin="$1" cli_flag="$2" file_key="$3" value="$4" example="$5"
    case "$value" in
        *[[:space:]]*)
            invalid_value_die "$origin" "$cli_flag" "$file_key" \
                "\"$value\" memuat spasi/tab/baris baru" "$example" ;;
    esac
    if [ -n "$(printf '%s' "$value" | LC_ALL=C tr -d 'A-Za-z0-9._%+@/:=-')" ]; then
        invalid_value_die "$origin" "$cli_flag" "$file_key" \
            "\"$value\" memuat karakter di luar huruf, angka, dan . _ % + @ / : = -" "$example"
    fi
}

# Validasi (+normalisasi) satu kunci. SPEC = nama kunci kanonik; pemetaan
# opsi CLI ↔ kunci env ↔ contoh perbaikan sengaja terkumpul di sini.
# origin = "none" ⇒ tidak ada nilai (dibiarkan pada bawaan) ⇒ lolos.
validate_value() {
    local spec="$1" value="$2" origin="$3"
    local cli_flag="" file_key="$spec" example="" allowed="" d o
    local octets=()

    if [ "$origin" = "none" ]; then
        VALIDATED_VALUE="$value"
        return 0
    fi

    case "$spec" in
        ADMIN_EMAIL)      cli_flag="--email";            example="--email ops@domain2.com" ;;
        SSL_CHALLENGE)    cli_flag="--ssl-challenge";    example="--ssl-challenge http|dns-cloudflare" ;;
        SSL_CA_SERVER)    cli_flag="--ssl-ca-server";    example="--ssl-ca-server production|staging" ;;
        CLOUDFLARE_CREDS) cli_flag="--cloudflare-creds"; example="--cloudflare-creds /srv/rames/cloudflare.ini" ;;
        APP_DOMAIN)       cli_flag="--domain";           example="--domain domain2.com" ;;
        PORT_RANGE_START|PORT_RANGE_END)
                          cli_flag="--port-range";       example="--port-range 30000 30999" ;;
        HOST_IP)          cli_flag="--ip";               example="--ip 10.0.0.12" ;;
    esac

    assert_env_safe "$origin" "$cli_flag" "$file_key" "$value" "$example"
    VALIDATED_VALUE="$value"

    case "$spec" in
        ADMIN_EMAIL)
            # Bentuk minimal: tepat satu '@', kedua sisi terisi (charset sudah dijaga).
            case "$value" in
                *@*@*) invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" memuat lebih dari satu '@'" "$example" ;;
                @*|*@) invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" kurang bagian sebelum/sesudah '@'" "$example" ;;
                *@*) ;;
                *)     invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" bukan alamat email (bentuk nama@domain)" "$example" ;;
            esac
            ;;
        SSL_CHALLENGE|SSL_CA_SERVER)
            case "$spec" in
                SSL_CHALLENGE) allowed=" http dns-cloudflare " ;;
                *)             allowed=" production staging " ;;
            esac
            case "$allowed" in
                *" $value "*) ;;
                *) invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" tidak dikenal" "$example" ;;
            esac
            ;;
        CLOUDFLARE_CREDS)
            case "$value" in
                /*) ;;
                *) invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" bukan path absolut (harus terbaca container agent)" "$example" ;;
            esac
            ;;
        APP_DOMAIN)
            # Normalisasi = pola ServerResolver::normalizeDomain()
            # (app/library/Deploy/ServerResolver.php): lowercase, tanpa titik
            # akhir, tanpa skema/path/port, pola label FQDN maks 253 karakter.
            # Aturan sengaja TIDAK lebih longgar dari jalur registry server.
            d="$(printf '%s' "$value" | LC_ALL=C tr 'A-Z' 'a-z')"
            d="${d#"${d%%[![:space:]]*}"}"
            d="${d%"${d##*[![:space:]]}"}"
            while [ "${d%.}" != "$d" ]; do d="${d%.}"; done
            if [ -z "$d" ]; then
                invalid_value_die "$origin" "$cli_flag" "$file_key" "kosong setelah normalisasi" "$example"
            fi
            if [ "${#d}" -gt 253 ]; then
                invalid_value_die "$origin" "$cli_flag" "$file_key" "terlalu panjang (${#d} > 253 karakter)" "$example"
            fi
            case "$d" in
                *://*|*/*|*:*)
                    invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" memuat skema/path/port" "$example" ;;
            esac
            if ! printf '%s' "$d" | grep -qE '^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$'; then
                invalid_value_die "$origin" "$cli_flag" "$file_key" \
                    "\"$value\" bukan nama domain/IP (huruf kecil, angka, titik, strip — tanpa spasi)" "$example"
            fi
            VALIDATED_VALUE="$d"
            ;;
        PORT_RANGE_START|PORT_RANGE_END)
            case "$value" in
                ''|*[!0-9]*)
                    invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" bukan bilangan bulat" "$example" ;;
            esac
            if [ "${#value}" -gt 5 ]; then
                invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" lebih dari 5 digit (port 1..65535)" "$example"
            fi
            # 10# aman: seluruh karakter sudah dipastikan digit di atas.
            VALIDATED_VALUE="$((10#$value))"
            if [ "$VALIDATED_VALUE" -lt 1 ] || [ "$VALIDATED_VALUE" -gt 65535 ]; then
                invalid_value_die "$origin" "$cli_flag" "$file_key" "port $VALIDATED_VALUE di luar 1..65535" "$example"
            fi
            ;;
        HOST_IP)
            # IPv4 saja: nilai ini masuk ke SAN sertifikat sebagai `IP:` dan ke
            # URL onboarding — openssl MENOLAK `IP:<hostname>`.
            case "$value" in
                *[!0-9.]*|.*|*..*|*.)
                    invalid_value_die "$origin" "$cli_flag" "$file_key" \
                        "\"$value\" bukan alamat IPv4 (bukan hostname)" "$example" ;;
            esac
            IFS='.' read -r -a octets <<< "$value"
            if [ "${#octets[@]}" -ne 4 ]; then
                invalid_value_die "$origin" "$cli_flag" "$file_key" "\"$value\" bukan IPv4 (harus 4 oktet)" "$example"
            fi
            for o in "${octets[@]}"; do
                case "$o" in
                    0|[1-9]|[1-9][0-9]|[1-9][0-9][0-9]) ;;
                    *) invalid_value_die "$origin" "$cli_flag" "$file_key" "oktet \"$o\" tidak sah (0..255, tanpa nol di depan)" "$example" ;;
                esac
                [ "$o" -le 255 ] || invalid_value_die "$origin" "$cli_flag" "$file_key" "oktet \"$o\" lebih dari 255" "$example"
            done
            VALIDATED_VALUE="$value"
            ;;
    esac
}

# start < end — dicek terpisah karena butuh dua nilai sekaligus.
validate_port_range_order() {
    local start="$1" end="$2" origin="$3"
    [ "$origin" = "none" ] && return 0
    [ "$start" -lt "$end" ] && return 0
    invalid_value_die "$origin" "--port-range" "PORT_RANGE_START/PORT_RANGE_END" \
        "rentang tidak masuk akal: start ($start) harus lebih kecil dari end ($end)" "--port-range 30000 30999"
}

# Nilai efektif satu kunci: opsi CLI menang bila NON-KOSONG, lalu nilai berkas,
# lalu kosong. Sumbernya dicatat untuk pesan galat (lihat invalid_value_die).
resolve_option() {  # $1=var tujuan  $2=opsi CLI  $3=nilai berkas  $4=var origin
    local var="$1" opt="$2" from_file="$3" origin_var="$4"
    if [ -n "$opt" ]; then
        printf -v "$var" '%s' "$opt"
        printf -v "$origin_var" '%s' cli
    elif [ -n "$from_file" ]; then
        printf -v "$var" '%s' "$from_file"
        printf -v "$origin_var" '%s' file
    else
        printf -v "$var" '%s' ''
        printf -v "$origin_var" '%s' none
    fi
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
# Dua bentuk didukung: literal (`const VERSION = '0.1.0';`) dan referensi
# konstanta kelas (`const VERSION = AgentVersionGate::CODEBASE_VERSION;`) —
# bentuk kedua diresolusi ke berkas kelasnya (PSR-4: nama berkas = nama kelas).
# Apa pun yang tidak terpecahkan ⇒ "unknown" (jangan menggagalkan installer
# hanya karena versi tak terbaca — mis. checkout tanpa app/).
agent_version() {
    local file="$PROJECT_DIR/app/controller/AgentController.php" version="" ref="" cls="" cst="" ref_file=""

    if [ -f "$file" ]; then
        version="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$file" | head -n1)"
        if [ -z "$version" ]; then
            ref="$(sed -n 's/.*const VERSION *= *\([A-Za-z_][A-Za-z0-9_]*\)::\([A-Za-z_][A-Za-z0-9_]*\).*/\1::\2/p' "$file" | head -n1)"
        fi
    fi

    if [ -z "$version" ] && [ -n "$ref" ] && command -v find >/dev/null 2>&1; then
        cls="${ref%%::*}"
        cst="${ref##*::}"
        ref_file="$(find "$PROJECT_DIR/app" -maxdepth 6 -type f -name "$cls.php" -print -quit 2>/dev/null || true)"
        if [ -n "$ref_file" ] && [ -f "$ref_file" ]; then
            version="$(sed -n "s/.*const ${cst} = '\([^']*\)'.*/\1/p" "$ref_file" | head -n1)"
        fi
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
        ok "certbot tersedia (penerbitan SSL app — Rames memakai mode webroot/HTTP-01, bukan plugin nginx)"
    else
        warn "certbot belum terpasang — dibutuhkan saat menerbitkan SSL app (mode webroot/HTTP-01): sudo apt-get install certbot"
        warn "  untuk mode DNS-01 Cloudflare (SSL_CHALLENGE=dns-cloudflare) plugin DNS-nya juga perlu: sudo apt-get install python3-certbot-dns-cloudflare"
        warn "  plugin certbot-nginx TIDAK dipakai Rames (vhost dikelola sendiri) — jangan pasang hanya untuk itu"
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

# Kunci yang DIKELOLA installer (satu-satunya isi blok kelolaan
# '# rames-installer:begin' … '# rames-installer:end'). Daftar ini juga dipakai
# untuk membuang salinan lama/kembar kunci tersebut di wilayah operator (mis.
# isian manual dari versi lama) supaya berkas env tidak pernah punya kunci ganda.
MANAGED_ENV_KEYS=(
    RAMES_MODE AGENT_TOKEN AGENT_BIND AGENT_TLS_CERT AGENT_TLS_KEY
    APP_DOMAIN PORT_RANGE_START PORT_RANGE_END
    ADMIN_EMAIL SSL_CHALLENGE SSL_CA_SERVER CLOUDFLARE_CREDS
)

is_managed_env_key() {
    local key="$1" k
    for k in "${MANAGED_ENV_KEYS[@]}"; do
        [ "$key" = "$k" ] && return 0
    done
    return 1
}

# Salin baris yang TIDAK dikelola installer dari berkas env lama ke $2 — VERBATIM
# (komentar operator, baris kosong, `SSL_WEBROOT`, `LETSENCRYPT_PATH`, kunci
# kustom apa pun; nilai ber-spasi/kutip tidak pernah di-parse ulang). Yang dibuang
# hanya: isi blok kelolaan, penanda sentinel, dan salinan kunci terkelola di
# wilayah operator. Tanpa ini, rerun installer menghapus penyetelan operator
# (mis. `SSL_WEBROOT`) dan merusak SSL HTTP-01 yang baru saja diperbaiki.
preserve_unmanaged_env_lines() {
    local src="$1" dst="$2" in_block=0 line key
    : > "$dst" || return 1
    [ -f "$src" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            '# rames-installer:begin'*)     in_block=1; continue ;;
            '# rames-installer:end'*)       in_block=0; continue ;;
            '# rames-installer:preserved'*) continue ;;
        esac
        [ "$in_block" -eq 1 ] && continue
        key="${line%%=*}"
        if [ "$key" != "$line" ] && is_managed_env_key "$key"; then
            continue
        fi
        printf '%s\n' "$line" >> "$dst" || return 1
    done < "$src"
    return 0
}

# Buang baris kosong di ujung salinan (bukan di tengah): tanpa ini, baris kosong
# yang berpindah posisi tiap run membuat hasil akhir TIDAK idempoten.
trim_blank_edges() {
    local src="$1" dst="$2"
    awk '{ lines[NR] = $0; if (NF > 0) { if (first == 0) first = NR; last = NR } }
         END { for (i = first; i <= last; i++) print lines[i] }' "$src" > "$dst"
}

write_secrets_env() {
    local token="$1" domain="$2" start="$3" end="$4" bind="$5"
    local email="${6:-}" challenge="${7:-}" ca_server="${8:-}" cf_creds="${9:-}"
    local extra_keys="" kept=0
    local raw="$SECRETS_ENV.raw" preserved="$SECRETS_ENV.preserved"

    [ -n "$email" ]      && extra_keys="${extra_keys} ADMIN_EMAIL"
    [ -n "$challenge" ]  && extra_keys="${extra_keys} SSL_CHALLENGE"
    [ -n "$ca_server" ]  && extra_keys="${extra_keys} SSL_CA_SERVER"
    [ -n "$cf_creds" ]   && extra_keys="${extra_keys} CLOUDFLARE_CREDS"

    info "Menulis $SECRETS_ENV (0600) ..."

    # Baris operator diambil DULU (dari berkas lama) sebelum berkas ditimpa.
    if ! preserve_unmanaged_env_lines "$SECRETS_ENV" "$raw" \
        || ! trim_blank_edges "$raw" "$preserved"; then
        rm -f "$raw" "$preserved"
        die "gagal menyiapkan salinan baris non-kelolaan dari $SECRETS_ENV"
    fi
    kept="$(grep -c '[^[:space:]]' "$preserved" 2>/dev/null || true)"
    [ -n "$kept" ] || kept=0

    if ( umask 077
        {
            cat <<EOF
# rames-installer:begin (blok kelolaan — diperbarui tiap installer dijalankan)
# Rames agent — dibuat oleh agent/install.sh. JANGAN di-commit (/secrets/ gitignored).
# Sumber tunggal identitas + rahasia agent: diinjeksi ke container lewat
# 'env_file: ./secrets/agent.env' di docker-compose.agent.yml.
# JANGAN menambah/mengubah kunci di dalam blok ini (ditimpa tiap rerun); taruh
# penyetelan manual (mis. SSL_WEBROOT) DI LUAR blok — dipertahankan apa adanya.
RAMES_MODE=agent
AGENT_TOKEN=${token}
AGENT_BIND=${bind}
AGENT_TLS_CERT=${AGENT_TLS_CERT}
AGENT_TLS_KEY=${AGENT_TLS_KEY}
APP_DOMAIN=${domain}
PORT_RANGE_START=${start}
PORT_RANGE_END=${end}
EOF

            # --- SSL (opsional) ------------------------------------------
            # Hanya ditulis bila non-kosong; nilai lama sudah dibaca main() dari
            # berkas ini dan dipertahankan, jadi di sini tidak ada yang hilang.
            # Job SSL berjalan DI SERVER INI (cli/deploy.php ssl_issue) memakai
            # konfigurasi server ini → ADMIN_EMAIL wajib ada di sini.
            if [ -n "$email" ]; then
                printf 'ADMIN_EMAIL=%s\n' "$email"
            fi
            if [ -n "$challenge" ]; then
                printf 'SSL_CHALLENGE=%s\n' "$challenge"
            fi
            if [ -n "$ca_server" ]; then
                printf 'SSL_CA_SERVER=%s\n' "$ca_server"
            fi
            if [ -n "$cf_creds" ]; then
                printf 'CLOUDFLARE_CREDS=%s\n' "$cf_creds"
            fi
            printf '# rames-installer:end\n'

            # --- baris operator (tidak dikelola) ---------------------------
            # Dipertahankan VERBATIM supaya penyetelan manual (SSL_WEBROOT,
            # LETSENCRYPT_PATH, kunci kustom) tidak hilang saat rerun.
            if [ "$kept" -gt 0 ]; then
                printf '\n'
                printf '# rames-installer:preserved  ↓ baris di bawah TIDAK dikelola installer\n'
                printf '# rames-installer:preserved    (mis. SSL_WEBROOT, LETSENCRYPT_PATH, kunci kustom)\n'
                cat "$preserved"
            fi
        } > "$SECRETS_ENV.tmp"
    ); then
        mv -f "$SECRETS_ENV.tmp" "$SECRETS_ENV"
        chmod 600 "$SECRETS_ENV"
        rm -f "$raw" "$preserved"
        ok "AGENT_TOKEN + AGENT_TLS_CERT/KEY + APP_DOMAIN + AGENT_BIND tersimpan (0600)${extra_keys:+ | SSL:${extra_keys}}"
        [ "$kept" -eq 0 ] || ok "$kept baris operator di luar blok kelolaan dipertahankan (mis. SSL_WEBROOT)"
    else
        rm -f "$SECRETS_ENV.tmp" "$raw" "$preserved"
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
    local port="$1" token="$2" body="" code="" response=""
    # CATATAN: `local` mengekspansi SEMUA kata sebelum menetapkan apa pun, jadi
    # `url` wajib di baris terpisah — kalau digabung, `${port}` masih "unbound"
    # dan `set -u` mematikan installer di akhir run (bug pra-ada).
    local url="https://127.0.0.1:${port}/version"
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

    # --- nilai dari berkas (dipakai bila opsi tidak diberikan → idempoten) --
    EFFECTIVE_TOKEN="$(read_env_value AGENT_TOKEN "$SECRETS_ENV")"
    ENV_DOMAIN="$(read_env_value APP_DOMAIN "$SECRETS_ENV")"
    ENV_RANGE_START="$(read_env_value PORT_RANGE_START "$SECRETS_ENV")"
    ENV_RANGE_END="$(read_env_value PORT_RANGE_END "$SECRETS_ENV")"
    EFFECTIVE_BIND="$(read_env_value AGENT_BIND "$SECRETS_ENV")"
    ENV_EMAIL="$(read_env_value ADMIN_EMAIL "$SECRETS_ENV")"
    ENV_SSL_CHALLENGE="$(read_env_value SSL_CHALLENGE "$SECRETS_ENV")"
    ENV_SSL_CA_SERVER="$(read_env_value SSL_CA_SERVER "$SECRETS_ENV")"
    ENV_CLOUDFLARE_CREDS="$(read_env_value CLOUDFLARE_CREDS "$SECRETS_ENV")"

    [ -n "$OPT_BIND" ] && EFFECTIVE_BIND="$OPT_BIND"
    [ -n "$EFFECTIVE_BIND" ] || EFFECTIVE_BIND="0.0.0.0:${AGENT_INTERNAL_PORT}"

    # --- APP_DOMAIN: opsi CLI > berkas > (wajib) ---------------------------
    RAW_DOMAIN=""
    ORIGIN_DOMAIN="none"
    if [ -n "$OPT_DOMAIN" ]; then
        RAW_DOMAIN="$OPT_DOMAIN"; ORIGIN_DOMAIN="cli"
    elif [ -n "$ENV_DOMAIN" ]; then
        RAW_DOMAIN="$ENV_DOMAIN"; ORIGIN_DOMAIN="file"
    fi
    if [ "$ORIGIN_DOMAIN" = "none" ]; then
        die "--domain wajib pada instalasi pertama (mis. ./agent/install.sh --domain domain2.com)"
    fi
    validate_value APP_DOMAIN "$RAW_DOMAIN" "$ORIGIN_DOMAIN"
    EFFECTIVE_DOMAIN="$VALIDATED_VALUE"   # ternormalisasi (lowercase, tanpa titik akhir)

    # --- rentang port: opsi CLI (keduanya) > berkas > bawaan ---------------
    RAW_RANGE_START=""
    RAW_RANGE_END=""
    ORIGIN_RANGE="none"
    if [ -n "$OPT_RANGE_START" ] && [ -n "$OPT_RANGE_END" ]; then
        RAW_RANGE_START="$OPT_RANGE_START"; RAW_RANGE_END="$OPT_RANGE_END"; ORIGIN_RANGE="cli"
    elif [ -n "$ENV_RANGE_START" ] || [ -n "$ENV_RANGE_END" ]; then
        # hanya satu sisi terisi (berkas lama/edit manual) → lengkapi dengan bawaan
        RAW_RANGE_START="${ENV_RANGE_START:-30000}"; RAW_RANGE_END="${ENV_RANGE_END:-30999}"; ORIGIN_RANGE="file"
    fi
    EFFECTIVE_RANGE_START="30000"
    EFFECTIVE_RANGE_END="30999"
    if [ "$ORIGIN_RANGE" != "none" ]; then
        validate_value PORT_RANGE_START "$RAW_RANGE_START" "$ORIGIN_RANGE"
        EFFECTIVE_RANGE_START="$VALIDATED_VALUE"
        validate_value PORT_RANGE_END "$RAW_RANGE_END" "$ORIGIN_RANGE"
        EFFECTIVE_RANGE_END="$VALIDATED_VALUE"
        validate_port_range_order "$EFFECTIVE_RANGE_START" "$EFFECTIVE_RANGE_END" "$ORIGIN_RANGE"
    fi

    if [ "$FORCE" -eq 1 ] || [ -z "$EFFECTIVE_TOKEN" ]; then
        EFFECTIVE_TOKEN="$(generate_token)"
    fi

    # --- SSL: opsi CLI > berkas > kosong (idempoten) -----------------------
    # Job SSL dijalankan DI SERVER INI (cli/deploy.php mode ssl_issue) dan
    # membaca env server ini → `ADMIN_EMAIL` wajib ada di secrets/agent.env
    # (dulu tidak pernah ditulis installer → job SSL mustahil jalan).
    resolve_option EFFECTIVE_EMAIL "$OPT_EMAIL" "$ENV_EMAIL" ORIGIN_EMAIL
    resolve_option EFFECTIVE_SSL_CHALLENGE "$OPT_SSL_CHALLENGE" "$ENV_SSL_CHALLENGE" ORIGIN_SSL_CHALLENGE
    resolve_option EFFECTIVE_SSL_CA_SERVER "$OPT_SSL_CA_SERVER" "$ENV_SSL_CA_SERVER" ORIGIN_SSL_CA_SERVER
    resolve_option EFFECTIVE_CLOUDFLARE_CREDS "$OPT_CLOUDFLARE_CREDS" "$ENV_CLOUDFLARE_CREDS" ORIGIN_CLOUDFLARE_CREDS

    # Validasi SEBELUM efek samping apa pun (ensure_dirs/ensure_certificate/tulis env).
    validate_value ADMIN_EMAIL "$EFFECTIVE_EMAIL" "$ORIGIN_EMAIL"
    EFFECTIVE_EMAIL="$VALIDATED_VALUE"
    validate_value SSL_CHALLENGE "$EFFECTIVE_SSL_CHALLENGE" "$ORIGIN_SSL_CHALLENGE"
    EFFECTIVE_SSL_CHALLENGE="$VALIDATED_VALUE"
    validate_value SSL_CA_SERVER "$EFFECTIVE_SSL_CA_SERVER" "$ORIGIN_SSL_CA_SERVER"
    EFFECTIVE_SSL_CA_SERVER="$VALIDATED_VALUE"
    validate_value CLOUDFLARE_CREDS "$EFFECTIVE_CLOUDFLARE_CREDS" "$ORIGIN_CLOUDFLARE_CREDS"
    EFFECTIVE_CLOUDFLARE_CREDS="$VALIDATED_VALUE"
    if [ "$EFFECTIVE_SSL_CHALLENGE" = "dns-cloudflare" ] && [ -z "$EFFECTIVE_CLOUDFLARE_CREDS" ]; then
        warn "SSL_CHALLENGE=dns-cloudflare tanpa CLOUDFLARE_CREDS — job SSL akan gagal sampai kredensial Cloudflare diatur (--cloudflare-creds)"
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
    EFFECTIVE_IP=""
    ORIGIN_IP="none"
    if [ -n "$OPT_IP" ]; then
        EFFECTIVE_IP="$OPT_IP"
        ORIGIN_IP="cli"
    else
        EFFECTIVE_IP="$(detect_ip)"
        if [ -n "$EFFECTIVE_IP" ]; then
            ORIGIN_IP="auto"
        elif [ -f "$AGENT_TLS_CERT" ]; then
            EFFECTIVE_IP="$(cert_san_ip "$AGENT_TLS_CERT")"
            if [ -n "$EFFECTIVE_IP" ]; then
                ORIGIN_IP="auto"
                warn "deteksi IP otomatis gagal — memakai IP dari SAN sertifikat yang ada: $EFFECTIVE_IP"
            fi
        fi
    fi
    if [ -z "$EFFECTIVE_IP" ]; then
        die "tidak bisa mendeteksi IP server ini — jalankan ulang dengan --ip <ip-server> (untuk SAN sertifikat & URL onboarding)"
    fi
    # IPv4 saja: openssl menolak `IP:<hostname>` di SAN (diuji) — gagal di sini
    # jauh lebih jelas daripada gagal saat membuat sertifikat.
    validate_value HOST_IP "$EFFECTIVE_IP" "$ORIGIN_IP"
    EFFECTIVE_IP="$VALIDATED_VALUE"

    local san_host=""
    san_host="$(hostname -f 2>/dev/null || hostname 2>/dev/null || true)"
    [ -n "$san_host" ] || san_host="localhost"

    info "Identitas agent: domain=$EFFECTIVE_DOMAIN | bind=$EFFECTIVE_BIND | publish=$EFFECTIVE_PUBLISH_PORT | ip=$EFFECTIVE_IP"
    if [ -n "$EFFECTIVE_EMAIL" ]; then
        ok "SSL: ADMIN_EMAIL=$EFFECTIVE_EMAIL | challenge=${EFFECTIVE_SSL_CHALLENGE:-http (bawaan)} | CA=${EFFECTIVE_SSL_CA_SERVER:-production (bawaan)}${EFFECTIVE_CLOUDFLARE_CREDS:+ | creds CF=$EFFECTIVE_CLOUDFLARE_CREDS}"
    else
        warn "ADMIN_EMAIL belum diatur — job SSL (ssl_issue) akan GAGAL sampai diisi: ./agent/install.sh --domain $EFFECTIVE_DOMAIN --email nama@domain"
    fi

    ensure_dirs
    ensure_certificate "$EFFECTIVE_IP" "$san_host"
    write_secrets_env "$EFFECTIVE_TOKEN" "$EFFECTIVE_DOMAIN" "$EFFECTIVE_RANGE_START" "$EFFECTIVE_RANGE_END" "$EFFECTIVE_BIND" \
        "$EFFECTIVE_EMAIL" "$EFFECTIVE_SSL_CHALLENGE" "$EFFECTIVE_SSL_CA_SERVER" "$EFFECTIVE_CLOUDFLARE_CREDS"
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
