#!/usr/bin/env bash
#
# Rames — Bootstrap installer (one-liner)
#
#   curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.sh -o /tmp/rames-install.sh \
#     && sudo bash /tmp/rames-install.sh
#
# atau (pipe; prompt tetap jalan lewat /dev/tty):
#
#   curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.sh | sudo bash
#
# Tugas skrip ini HANYA: pastikan paket dasar untuk clone ada, unduh repo ke
# INSTALL_DIR (default /opt/rames), lalu serahkan seluruh instalasi host ke
# `host/install.sh` (satu sumber kebenaran untuk Docker/Nginx/certbot + wizard
# .env). Skrip ini TIDAK menginstal Docker/Nginx/certbot sendiri.
#
# Tidak ada secret yang dicetak. Argumen env diteruskan apa adanya ke
# host/install.sh (lihat --help).
set -euo pipefail

# ---------------------------------------------------------------- konstanta
REPO_URL="${RAMES_REPO_URL:-https://github.com/yllumi/rames.git}"
REF="${RAMES_REF:-main}"
INSTALL_DIR="/opt/rames"
SELF="${BASH_SOURCE[0]:-}"

DIR_GIVEN=0
NON_INTERACTIVE=0
NO_DEPS=0
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
Rames — Bootstrap installer

Pemakaian (Ubuntu 22.04/24.04, Debian sebagai fallback):
  curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.sh -o /tmp/rames-install.sh
  sudo bash /tmp/rames-install.sh [opsi]

Bentuk pipe juga didukung (prompt dibaca dari /dev/tty):
  curl -fsSL https://raw.githubusercontent.com/yllumi/rames/main/install.sh | sudo bash

Opsi bootstrap:
  --dir <path>       direktori instalasi (default /opt/rames)
  --ref <ref>        branch/tag yang di-clone (default main)
  --repo <url>       URL repo Git (default https://github.com/yllumi/rames.git)
  --non-interactive  tanpa prompt (wajib disertai --domain)
  --no-deps          lewati instalasi paket dasar (diteruskan ke host/install.sh)
  -h, --help         tampilkan bantuan ini

Opsi env (diteruskan apa adanya ke host/install.sh):
  --domain <d> --email <e> --port <p>
  --admin-user <u> --admin-password <p> --session-secret <s>
  --port-range-start <p> --port-range-end <p>
  --ssl-challenge <http|dns-cloudflare> --cloudflare-creds <path>
  --billing <true|false>
  --restic-repository <url> --aws-access-key-id <id>
  --aws-secret-access-key <s> --aws-default-region <r>

Contoh non-interaktif:
  sudo bash install.sh --non-interactive --domain example.com \\
    --email admin@example.com --port 8000

Bila direktori instalasi sudah berisi instalasi Rames, skrip memakai ulang
tanpa reset/checkout — update dilakukan lewat panel atau 'git pull'.
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

    die "Jalankan dengan sudo, mis.: curl -fsSL ... -o /tmp/rames-install.sh && sudo bash /tmp/rames-install.sh"
}

# Dipanggil SETELAH eskalasi root. Bila stdin bukan TTY (mis. curl | bash),
# buka /dev/tty di fd 3 dan ekspor RAMES_PROMPT_FD agar host/install.sh bisa
# bertanya tanpa menelan isi pipe.
setup_prompt_fd() {
    if [ "$NON_INTERACTIVE" -eq 1 ]; then
        return 0
    fi
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
    die "Tidak ada TTY untuk prompt. Unduh dulu lalu jalankan: curl -fsSL ... -o /tmp/rames-install.sh && sudo bash /tmp/rames-install.sh (atau pakai --non-interactive)."
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

is_rames_checkout() { # dir
    [ -f "$1/docker-compose.yml" ] && [ -f "$1/host/install.sh" ] && [ -f "$1/app/functions.php" ]
}

is_git_repo() { # dir
    git -c safe.directory="$1" -C "$1" rev-parse --is-inside-work-tree >/dev/null 2>&1
}

resolve_install_dir() {
    # 1) Jalankan dari dalam checkout Rames tanpa --dir → pakai CWD (tanpa clone).
    if [ "$DIR_GIVEN" -eq 0 ] && is_rames_checkout "$PWD"; then
        INSTALL_DIR="$PWD"
        ok "Menggunakan checkout Rames yang sudah ada di $INSTALL_DIR"
        return 0
    fi

    # 2) Direktori sudah ada.
    if [ -e "$INSTALL_DIR" ]; then
        if [ -d "$INSTALL_DIR" ] && is_rames_checkout "$INSTALL_DIR"; then
            warn "Instalasi Rames sudah ada di $INSTALL_DIR — memakai ulang TANPA reset/checkout."
            if is_git_repo "$INSTALL_DIR"; then
                warn "Perubahan lokal tidak disentuh. Update lewat panel atau: git -C \"$INSTALL_DIR\" pull"
            else
                warn "Checkout ini bukan repo Git — panel self-update tidak akan berfungsi."
            fi
            return 0
        fi
        if [ -d "$INSTALL_DIR" ] && [ -z "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]; then
            : # direktori kosong → aman untuk clone
        else
            die "Direktori $INSTALL_DIR sudah ada tetapi bukan instalasi Rames. Pilih --dir lain atau kosongkan direktori itu."
        fi
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
    ok "Repo terunduh di $INSTALL_DIR"
}

ensure_host_scripts_exec() {
    local f
    for f in "$INSTALL_DIR"/host/*.sh; do
        [ -f "$f" ] || continue
        [ -x "$f" ] || chmod +x "$f"
    done
}

# ---------------------------------------------------------------- args
parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dir) INSTALL_DIR="${2:?--dir butuh nilai}"; DIR_GIVEN=1; shift ;;
            --ref) REF="${2:?--ref butuh nilai}"; shift ;;
            --repo) REPO_URL="${2:?--repo butuh nilai}"; shift ;;
            --non-interactive) NON_INTERACTIVE=1; PASS_ARGS+=(--non-interactive) ;;
            --no-deps) NO_DEPS=1; PASS_ARGS+=(--no-deps) ;;
            -h|--help) usage; exit 0 ;;
            --)
                shift
                while [ "$#" -gt 0 ]; do PASS_ARGS+=("$1"); shift; done
                break
                ;;
            --domain|--email|--port|--admin-user|--admin-password|--session-secret|\
            --port-range-start|--port-range-end|--ssl-challenge|--cloudflare-creds|\
            --billing|--restic-repository|--aws-access-key-id|--aws-secret-access-key|\
            --aws-default-region)
                [ "$#" -ge 2 ] || die "Opsi $1 butuh nilai."
                PASS_ARGS+=("$1" "$2")
                shift
                ;;
            *)
                die "Opsi/argumen tidak dikenal: $1 (lihat --help)"
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

    info "Rames bootstrap — repo: $REPO_URL (ref: $REF)"

    install_base_packages
    resolve_install_dir
    ensure_host_scripts_exec

    info "Menyerahkan instalasi ke $INSTALL_DIR/host/install.sh ..."
    exec "$INSTALL_DIR/host/install.sh" ${PASS_ARGS[@]+"${PASS_ARGS[@]}"}
}

main "$@"
