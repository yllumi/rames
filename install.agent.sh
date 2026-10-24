#!/usr/bin/env bash
#
# Rames — Bootstrap installer server AGENT (one-liner)
#
#   curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.agent.sh -o /tmp/rames-install-agent.sh \
#     && sudo bash /tmp/rames-install-agent.sh --domain agent.example.com --email ops@example.com
#
# atau (pipe; prompt tetap jalan lewat /dev/tty):
#
#   curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.agent.sh \
#     | sudo bash -s -- --domain agent.example.com --email ops@example.com
#
# Tugas skrip ini HANYA: pastikan paket dasar untuk clone ada, unduh repo ke
# INSTALL_DIR (default /opt/rames-agent), pasang dependensi host lewat
# `host/install.sh --deps-only`, lalu serahkan seluruh instalasi server agent
# ke `agent/install.sh` (satu sumber kebenaran untuk rahasia agent, TLS,
# compose agent, dan blok onboarding).
#
# Skrip ini SENGAJA mandiri (tidak `source` berkas repo): dijalankan sebelum
# repo ada. Boilerplate guard OS/root/clone diduplikasi dari `install.sh`.
# Tidak ada secret yang dicetak.
set -euo pipefail

# ---------------------------------------------------------------- konstanta
REPO_URL="${RAMES_REPO_URL:-https://github.com/yllumi/rames.git}"
REF="${RAMES_REF:-main}"
# Beda dari dashboard (/opt/rames): agent & dashboard boleh satu host.
INSTALL_DIR="/opt/rames-agent"
SELF="${BASH_SOURCE[0]:-}"

DIR_GIVEN=0
NO_DEPS=0
DOMAIN_GIVEN=0
# 1 = direktori instalasi BARU di-clone proses ini (boleh chmod +x skrip host);
# 0 = checkout dipakai ulang (jangan ubah mode berkas: `git diff` harus bersih).
CLONED=0
PASS_ARGS=()

BASE_PKGS=(ca-certificates curl git gnupg lsb-release openssl)

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
Rames — Bootstrap installer server AGENT

Pemakaian (Ubuntu 22.04/24.04, Debian sebagai fallback):
  curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.agent.sh -o /tmp/rames-install-agent.sh
  sudo bash /tmp/rames-install-agent.sh --domain agent.example.com --email ops@example.com

Bentuk pipe juga didukung (prompt dibaca dari /dev/tty):
  curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.agent.sh \\
    | sudo bash -s -- --domain agent.example.com --email ops@example.com

Skrip ini memasang Rames sebagai SERVER AGENT (dikendalikan dashboard) di
${INSTALL_DIR} — berbeda dari installer dashboard (install.sh, /opt/rames),
sehingga agent dan dashboard boleh hidup di host yang sama.

Opsi bootstrap:
  --dir <path>       direktori instalasi (default /opt/rames-agent)
  --ref <ref>        branch/tag yang di-clone (default main)
  --repo <url>       URL repo Git (default https://github.com/yllumi/rames.git)
  --no-deps          lewati paket dasar + dependensi host (Docker Engine +
                     Compose v2, nginx, certbot); agent/install.sh hanya
                     memeriksa prasyaratnya
  -h, --help         tampilkan bantuan ini

Opsi agent (diteruskan apa adanya ke agent/install.sh):
  --domain <d>            domain server agent ini (mis. agent.example.com).
                          WAJIB pada instalasi pertama (tanpa --domain skrip
                          akan bertanya; rerun boleh tanpa --domain bila
                          secrets/agent.env sudah berisi APP_DOMAIN)
  --email <e>             email admin untuk SSL Let's Encrypt
  --ip <ip>               IPv4 server ini (SAN sertifikat & URL onboarding)
  --bind <ip:port>        AGENT_BIND (mis. 0.0.0.0:8443)
  --port-range <s> <e>    rentang host port app — DUA nilai (mis. 31000 31999)
  --ssl-challenge <m>     http (default) | dns-cloudflare
  --ssl-ca-server <s>     production (default) | staging
  --cloudflare-creds <p>  path berkas kredensial Cloudflare (dns-cloudflare)
  --force                 regenerasi token agent + sertifikat TLS

Catatan: pada instalasi PERTAMA server agent, --domain wajib (interaktif atau
lewat flag). Setelah secrets/agent.env berisi APP_DOMAIN, rerun bersifat
idempoten dan tidak menanyakan identitas lagi.

Contoh:
  sudo bash install.agent.sh --domain agent.example.com --email ops@example.com
  sudo bash install.agent.sh --domain agent.example.com --email ops@example.com \\
    --port-range 31000 31999 --bind 0.0.0.0:8443

Bila direktori instalasi sudah berisi checkout Rames agent, skrip memakai ulang
tanpa reset/checkout — update dilakukan lewat 'git pull'. Direktori yang sudah
terpasang sebagai DASHBOARD Rames (ada .env / database/apps.json / data di apps/)
DITOLAK: agent tidak pernah menimpa instalasi dashboard.
EOF
}

# ---------------------------------------------------------------- helpers
abspath() {
    case "$1" in
        /*) printf '%s' "$1" ;;
        *) printf '%s/%s' "$PWD" "$1" ;;
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

require_os() {
    [ -r /etc/os-release ] || die "/etc/os-release tidak ditemukan — distro tidak dikenal."
    local id like pretty
    id="$(read_os_field ID)"
    like="$(read_os_field ID_LIKE)"
    pretty="$(read_os_field PRETTY_NAME)"
    pretty="${pretty:-$id}"

    case " $id $like " in
        *" ubuntu "*|*" debian "*) ;;
        *)
            die "Distro '$pretty' belum didukung. Saat ini installer hanya mendukung Ubuntu 22.04/24.04 (Debian sebagai fallback)."
            ;;
    esac
}

require_arch() {
    local arch
    arch="$(uname -m)"
    case "$arch" in
        x86_64|aarch64|arm64) ;;
        *) die "Arsitektur '$arch' belum didukung (hanya x86_64/aarch64)." ;;
    esac
}

ensure_root() {
    [ "$(id -u)" -eq 0 ] && return 0

    command -v sudo >/dev/null 2>&1 \
        || die "Installer harus dijalankan sebagai root dan 'sudo' tidak tersedia."

    if [ -n "$SELF" ] && [ -f "$SELF" ]; then
        warn "Perlu akses root — menjalankan ulang lewat sudo ..."
        exec sudo -E bash "$SELF" "$@"
    fi

    die "Bentuk pipe tidak bisa dieskalasi ke root. Unduh dulu lalu jalankan: curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.agent.sh -o /tmp/rames-install-agent.sh && sudo bash /tmp/rames-install-agent.sh --domain <domain> (atau pipe langsung ke 'sudo bash -s --')."
}

# Dipanggil SETELAH eskalasi root. Bila stdin bukan TTY (mis. curl | bash),
# buka /dev/tty di fd 3 dan ekspor RAMES_PROMPT_FD agar prompt identitas bisa
# bertanya tanpa menelan isi pipe. Bila TTY tidak tersedia, skrip tetap jalan
# (mode non-interaktif) — prompt identitas yang akan menolak tanpa --domain.
setup_prompt_fd() {
    if [ -n "${RAMES_PROMPT_FD:-}" ]; then
        export RAMES_PROMPT_FD
        return 0
    fi
    if [ -t 0 ]; then
        export RAMES_PROMPT_FD=0
        return 0
    fi
    if { exec 3</dev/tty; } 2>/dev/null; then
        export RAMES_PROMPT_FD=3
        return 0
    fi
    warn "Tidak ada TTY untuk prompt — berjalan non-interaktif."
}

install_base_packages() {
    if [ "$NO_DEPS" -eq 1 ]; then
        info "--no-deps: melewatkan instalasi paket dasar."
        return 0
    fi
    command -v apt-get >/dev/null 2>&1 \
        || die "apt-get tidak ditemukan — bootstrap hanya mendukung Debian/Ubuntu."

    info "Menginstal paket dasar: ${BASE_PKGS[*]}"
    apt-get update -qq || die "apt-get update gagal — periksa koneksi/repo APT."
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${BASE_PKGS[@]}" \
        || die "Instalasi paket dasar gagal."
}

# Checkout Rames untuk MODE AGENT: docker-compose.agent.yml + agent/install.sh
# (+ app/functions.php). `docker-compose.yml` (dashboard) TIDAK disyaratkan —
# agent boleh berdiri di repo yang sama tanpa menyentuh instalasi dashboard.
is_agent_checkout() { # dir
    [ -f "$1/docker-compose.agent.yml" ] && [ -f "$1/agent/install.sh" ] && [ -f "$1/app/functions.php" ]
}

# Penanda INSTALASI (bukan struktur repo): checkout Rames dipakai bersama oleh
# dashboard & agent, jadi struktur repo saja tidak bisa membedakan peran.
# Dashboard terpasang: `.env` hasil wizard host/install.sh, `database/apps.json`
# yang dibuat aplikasi, atau `apps/` berisi data app.
is_dashboard_install() { # dir
    [ -f "$1/.env" ] && return 0
    [ -f "$1/database/apps.json" ] && return 0
    [ -d "$1/apps" ] && [ -n "$(ls -A "$1/apps" 2>/dev/null)" ] && return 0
    return 1
}

# Agent terpasang: rahasia agent sudah ditulis agent/install.sh.
is_agent_install() { # dir
    [ -f "$1/secrets/agent.env" ]
}

# Klasifikasi direktori instalasi → reuse|clone|reject.
# PRIORITAS: penanda dashboard MENANG atas penanda agent. Menolak (die) selalu
# lebih aman daripada menimpa instalasi dashboard yang sedang hidup — kasus
# nyata: agent pernah naik di repo dashboard karena hanya struktur repo dicek.
install_dir_verdict() { # dir
    [ -e "$1" ] || { printf 'clone'; return 0; }
    [ -d "$1" ] || { printf 'reject'; return 0; }
    if is_agent_checkout "$1"; then
        if is_dashboard_install "$1"; then
            printf 'reject'
        else
            printf 'reuse'
        fi
        return 0
    fi
    [ -z "$(ls -A "$1" 2>/dev/null)" ] && { printf 'clone'; return 0; }
    printf 'reject'
}

refuse_install_dir() { # dir
    if [ -d "$1" ] && is_agent_checkout "$1" && is_dashboard_install "$1"; then
        die "Direktori $1 adalah instalasi DASHBOARD Rames (.env / database/apps.json / apps/ tidak kosong). Pilih --dir lain (default /opt/rames-agent) agar agent tidak menimpa instalasi dashboard."
    fi
    die "Direktori $1 sudah ada tetapi bukan instalasi Rames. Pilih --dir lain atau kosongkan direktori itu."
}

# Fail-fast SEBELUM paket dasar dipasang: direktori yang jelas tidak boleh
# dipakai tidak perlu menunggu `apt-get install`. Tanpa --dir, direktori CWD
# yang merupakan checkout Rames juga dinilai (alur yang sama seperti
# resolve_install_dir, tanpa menduplikasi pesan die).
assert_install_dir_sane() {
    local target="$INSTALL_DIR"
    if [ "$DIR_GIVEN" -eq 0 ] && is_agent_checkout "$PWD"; then
        target="$PWD"
    fi
    case "$(install_dir_verdict "$target")" in
        reject) refuse_install_dir "$target" ;;
    esac
}

is_git_repo() { # dir
    git -c safe.directory="$1" -C "$1" rev-parse --is-inside-work-tree >/dev/null 2>&1
}

resolve_install_dir() {
    # 1) Jalankan dari dalam checkout Rames tanpa --dir → pakai CWD.
    if [ "$DIR_GIVEN" -eq 0 ] && is_agent_checkout "$PWD"; then
        case "$(install_dir_verdict "$PWD")" in
            reject) refuse_install_dir "$PWD" ;;
        esac
        INSTALL_DIR="$PWD"
        ok "Menggunakan checkout Rames agent yang sudah ada di $INSTALL_DIR"
        if ! is_agent_install "$INSTALL_DIR"; then
            warn "Direktori $INSTALL_DIR belum berisi identitas agent (secrets/agent.env) — dipakai apa adanya sebagai checkout Rames."
        fi
        return 0
    fi

    # 2) Direktori sudah ada.
    if [ -e "$INSTALL_DIR" ]; then
        case "$(install_dir_verdict "$INSTALL_DIR")" in
            reuse)
                warn "Instalasi Rames agent sudah ada di $INSTALL_DIR — memakai ulang TANPA reset/checkout."
                if is_git_repo "$INSTALL_DIR"; then
                    warn "Perubahan lokal tidak disentuh. Update lewat: git -C \"$INSTALL_DIR\" pull"
                else
                    warn "Checkout ini bukan repo Git — update manual diperlukan."
                fi
                if ! is_agent_install "$INSTALL_DIR"; then
                    warn "Direktori $INSTALL_DIR belum berisi identitas agent (secrets/agent.env) — dipakai apa adanya sebagai checkout Rames."
                fi
                return 0
                ;;
            clone)
                : # direktori kosong → aman untuk clone
                ;;
            *)
                refuse_install_dir "$INSTALL_DIR"
                ;;
        esac
    fi

    clone_repo
}

clone_repo() {
    command -v git >/dev/null 2>&1 \
        || die "git tidak ditemukan. Jalankan tanpa --no-deps atau pasang git lebih dulu."
    info "Mengunduh Rames (ref: $REF) ke $INSTALL_DIR ..."
    mkdir -p "$(dirname "$INSTALL_DIR")"
    git clone --depth=1 --single-branch --branch "$REF" "$REPO_URL" "$INSTALL_DIR" \
        || die "git clone gagal. Periksa koneksi, URL repo ($REPO_URL), dan ref ($REF)."
    CLONED=1
    ok "Repo terunduh di $INSTALL_DIR"
}

# APP_DOMAIN yang sudah tercatat di secrets/agent.env (jalur env_file agent).
agent_env_domain() {
    local file="$INSTALL_DIR/secrets/agent.env" line="" val=""
    [ -r "$file" ] || return 1
    line="$(grep -E '^APP_DOMAIN=' "$file" 2>/dev/null | tail -n1)" || return 1
    [ -n "$line" ] || return 1
    val="${line#APP_DOMAIN=}"
    val="${val%\"}"; val="${val#\"}"
    val="${val%\'}"; val="${val#\'}"
    case "$val" in
        *[![:space:]]*) ;;
        *) return 1 ;;
    esac
    printf '%s' "$val"
}

# Prompt identitas HANYA bila perlu (instalasi pertama). Rerun idempoten:
# --domain diberikan ATAU secrets/agent.env sudah berisi APP_DOMAIN ⇒ lewati.
prompt_identity() {
    local existing="" fd="${RAMES_PROMPT_FD:-0}" domain="" email=""

    if [ "$DOMAIN_GIVEN" -eq 1 ]; then
        return 0
    fi
    if existing="$(agent_env_domain)"; then
        ok "Domain agent sudah tercatat ($existing) — identitas tidak ditanyakan lagi."
        return 0
    fi
    if ! [ -t "$fd" ]; then
        die "Instalasi pertama server agent membutuhkan --domain <mis. agent.example.com> (dan sebaiknya --email <ops@example.com>). Tidak ada TTY untuk prompt identitas."
    fi

    info "Identitas server agent"
    while [ -z "$domain" ]; do
        printf '  Domain server agent ini (mis. agent.example.com): ' >&2
        if ! read -r -u "$fd" domain; then
            die "Domain wajib diisi pada instalasi pertama — jalankan ulang dengan --domain <domain>."
        fi
        [ -n "$domain" ] || warn "Domain tidak boleh kosong."
    done
    printf '  Email admin untuk SSL Let%s Encrypt (boleh kosong): ' "'s" >&2
    read -r -u "$fd" email || email=""

    PASS_ARGS+=(--domain "$domain")
    if [ -n "$email" ]; then
        PASS_ARGS+=(--email "$email")
        ok "Identitas: $domain ($email)"
    else
        ok "Identitas: $domain"
        warn "Email kosong — agent/install.sh akan memperingatkan; job SSL gagal sampai ADMIN_EMAIL diisi."
    fi
}

install_host_deps() {
    local script="$INSTALL_DIR/host/install.sh"
    [ -f "$script" ] || die "$script tidak ditemukan — checkout tidak lengkap."
    info "Memasang dependensi host lewat host/install.sh --deps-only ..."
    bash "$script" --deps-only || die "Instalasi dependensi host gagal (host/install.sh --deps-only)."
}

# chmod HANYA untuk checkout yang baru di-clone proses ini. Checkout yang
# dipakai ulang tidak boleh diubah modenya — `chmod +x` di sana mengotori repo
# pemilik (mis. host/certbot-renew.sh 644→755 di `git diff`) dan bisa membuat
# `git pull` gagal. Skrip agent yang belum executable dijalankan lewat `bash`.
ensure_host_scripts_exec() {
    [ "$CLONED" -eq 1 ] || return 0
    local f
    for f in "$INSTALL_DIR"/host/*.sh "$INSTALL_DIR/agent/install.sh"; do
        [ -f "$f" ] || continue
        [ -x "$f" ] || chmod +x "$f"
    done
}

is_port_number() { # nilai → 0 bila bilangan bulat 1..65535
    case "$1" in
        ''|*[!0-9]*) return 1 ;;
    esac
    [ "${#1}" -le 5 ] && [ "$1" -ge 1 ] && [ "$1" -le 65535 ]
}

# ---------------------------------------------------------------- args
SINGLE_VALUE_AGENT_OPTS="--email --ip --bind --ssl-challenge --ssl-ca-server --cloudflare-creds"

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dir)
                [ "$#" -ge 2 ] && [ -n "$2" ] || die "--dir butuh nilai."
                INSTALL_DIR="$2"; DIR_GIVEN=1; shift ;;
            --ref)
                [ "$#" -ge 2 ] && [ -n "$2" ] || die "--ref butuh nilai."
                REF="$2"; shift ;;
            --repo)
                [ "$#" -ge 2 ] && [ -n "$2" ] || die "--repo butuh nilai."
                REPO_URL="$2"; shift ;;
            --no-deps) NO_DEPS=1 ;;
            -h|--help) usage; exit 0 ;;
            --domain)
                [ "$#" -ge 2 ] || die "--domain butuh nilai."
                [ -n "$2" ] || die "--domain tidak boleh kosong."
                DOMAIN_GIVEN=1
                PASS_ARGS+=(--domain "$2")
                shift
                ;;
            --port-range)
                # Dua nilai wajib: tanpa validasi ini `--port-range 31000 --force`
                # menelan flag berikutnya sebagai port akhir.
                if [ "$#" -lt 3 ] || ! is_port_number "$2" || ! is_port_number "$3"; then
                    die "--port-range butuh DUA bilangan bulat 1..65535: <start> <end>."
                fi
                PASS_ARGS+=(--port-range "$2" "$3")
                shift 2
                ;;
            --force)
                PASS_ARGS+=(--force)
                ;;
            *)
                case " $SINGLE_VALUE_AGENT_OPTS " in
                    *" $1 "*)
                        [ "$#" -ge 2 ] || die "Opsi $1 butuh nilai."
                        PASS_ARGS+=("$1" "$2")
                        shift
                        ;;
                    *)
                        die "Opsi/argumen tidak dikenal: $1 (lihat --help)"
                        ;;
                esac
                ;;
        esac
        shift
    done

    [ -n "$REPO_URL" ] || die "--repo tidak boleh kosong."
    [ -n "$REF" ] || die "--ref tidak boleh kosong."
    [ -n "$INSTALL_DIR" ] || die "--dir tidak boleh kosong."
    if [ "$DIR_GIVEN" -eq 1 ]; then
        INSTALL_DIR="$(abspath "$INSTALL_DIR")"
    fi
}

# ---------------------------------------------------------------- main
main() {
    # --help (dan validasi argumen) SEBELUM cek root.
    parse_args "$@"

    if [ -n "$SELF" ] && [ -f "$SELF" ]; then
        # Resolusi absolut SEBELUM sudo: cwd bisa berbeda di proses hasil eskalasi.
        SELF="$(cd "$(dirname "$SELF")" && pwd)/$(basename "$SELF")"
    fi

    ensure_root "$@"
    require_os
    require_arch
    setup_prompt_fd

    info "Rames agent bootstrap — repo: $REPO_URL (ref: $REF)"

    # Tolak direktori yang salah SEBELUM paket dasar dipasang (fail-fast).
    assert_install_dir_sane
    install_base_packages
    resolve_install_dir
    prompt_identity

    if [ "$NO_DEPS" -eq 1 ]; then
        warn "--no-deps: dependensi host dilewati — agent/install.sh hanya akan memeriksa docker/compose/openssl/nginx."
    else
        install_host_deps
    fi

    ensure_host_scripts_exec

    info "Menyerahkan instalasi server agent ke $INSTALL_DIR/agent/install.sh ..."
    if [ -x "$INSTALL_DIR/agent/install.sh" ]; then
        exec "$INSTALL_DIR/agent/install.sh" ${PASS_ARGS[@]+"${PASS_ARGS[@]}"}
    fi
    # Checkout yang dipakai ulang tidak boleh di-chmod; eksekusi tetap jalan.
    warn "$INSTALL_DIR/agent/install.sh belum executable — menjalankan lewat bash (mode berkas tidak diubah)."
    exec bash "$INSTALL_DIR/agent/install.sh" ${PASS_ARGS[@]+"${PASS_ARGS[@]}"}
}

main "$@"
