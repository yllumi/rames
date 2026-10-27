#!/usr/bin/env bash
#
# Rames — Provisioning user admin dashboard (host) — docs/SPECS.md §2, §6.3
#
# Membuat user admin pertama lewat `php webman make:admin` DI DALAM container
# dashboard `rames-webman`. Dipakai `host/install.sh` (langkah "membuat user
# admin pertama") dan bisa dijalankan manual kapan saja:
#
#   sudo ./host/create-admin.sh [--user admin] [--password <pass>] [--wait 300]
#   sudo ./host/create-admin.sh admin passwordku
#
# KENAPA skrip ini terpisah & KENAPA menunggu dulu: `command:` di
# docker-compose.yml menjalankan bootstrap panjang SEBELUM server start —
#   composer install (bila vendor/autoload.php belum ada) && php cli/db.php migrate
#   && php cli/db.php import-json && php start.php start
# Container sudah berstatus "running" sejak DETIK PERTAMA bootstrap, jadi
# `docker exec rames-webman php webman make:admin` yang dipanggil terlalu cepat
# gagal dengan fatal error
#   require_once(/…/vendor/autoload.php): Failed to open stream
# (vendor belum terpasang / skema SQLite belum termigrasi). Skrip ini menunggu
# readiness lebih dulu: `GET /healthz` dari DALAM container harus balas JSON
# `"ok":true` — itu satu-satunya tanda vendor siap + migrasi DB selesai +
# server listening. Baru setelah itu make:admin dijalankan.
#
# Idempoten: bila store user sudah berisi user, tidak ada user yang dibuat ulang
# (kelola user lewat halaman "Manage Users" di dashboard).
set -euo pipefail

# ---------------------------------------------------------------- path
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPOSE_FILE="$PROJECT_DIR/docker-compose.yml"
CONTAINER_NAME="${RAMES_CONTAINER:-rames-webman}"

# Port HTTP DI DALAM container — dipatok config/process.php
# (`listen => 'http://0.0.0.0:8787'`) dan di-publish compose sebagai
# "${APP_PORT}:8787". Diprobe dari dalam container supaya tidak bergantung pada
# nilai APP_PORT atau aturan firewall host.
CONTAINER_HTTP_PORT="${RAMES_CONTAINER_HTTP_PORT:-8787}"

# ---------------------------------------------------------------- flags
OPT_USER="admin"
OPT_PASS=""
WAIT_SECS=300

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
Rames — buat user admin dashboard (provisioning awal)

Pemakaian:
  sudo $0 [opsi] [username] [password]

Opsi:
  --user <u>        username admin (default: admin)
  --password <p>    password admin (default: di-generate acak & dicetak sekali)
  --wait <detik>    batas tunggu dashboard siap (default: 300)
  -h, --help        tampilkan bantuan ini

Catatan:
  - Container 'rames-webman' harus sudah dijalankan (docker compose up -d --build).
    Skrip menunggu sendiri sampai bootstrap-nya selesai (composer install +
    migrasi DB + server start), diprobe lewat 'GET /healthz' dari dalam container.
  - Bila store user sudah punya user, tidak ada user yang dibuat ulang.
  - Password yang diberikan lewat argumen terlihat di daftar proses (ps) selama
    perintah berjalan — di host bersama, biarkan kosong agar di-generate acak,
    lalu ganti lewat halaman 'Manage Users' di dashboard.
EOF
}

parse_args() {
    local positional=()
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --user) OPT_USER="${2:?--user butuh nilai}"; shift ;;
            --password) OPT_PASS="${2:?--password butuh nilai}"; shift ;;
            --wait) WAIT_SECS="${2:?--wait butuh nilai}"; shift ;;
            -h|--help) usage; exit 0 ;;
            --) shift
                while [ "$#" -gt 0 ]; do positional+=("$1"); shift; done
                break ;;
            -*) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
            *) positional+=("$1") ;;
        esac
        shift
    done

    if [ "${#positional[@]}" -ge 1 ]; then OPT_USER="${positional[0]}"; fi
    if [ "${#positional[@]}" -ge 2 ]; then OPT_PASS="${positional[1]}"; fi
    if [ "${#positional[@]}" -gt 2 ]; then die "Terlalu banyak argumen — lihat --help"; fi

    if [ -z "$OPT_USER" ]; then die "Username tidak boleh kosong."; fi
    if ! printf '%s' "$WAIT_SECS" | grep -qE '^[0-9]+$'; then die "--wait harus angka (detik)."; fi
}

# ---------------------------------------------------------------- docker
require_docker() {
    command -v docker >/dev/null 2>&1 || die "Perintah 'docker' tidak ditemukan."
    docker info >/dev/null 2>&1 \
        || die "Tidak bisa mengakses Docker daemon — jalankan dengan sudo (atau sebagai user di grup 'docker')."
}

container_exists() {
    docker inspect -f '{{.Id}}' "$CONTAINER_NAME" >/dev/null 2>&1
}

container_running() {
    [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER_NAME" 2>/dev/null || true)" = "true" ]
}

print_container_logs() {
    warn "40 baris terakhir log container $CONTAINER_NAME:"
    docker logs --tail 40 "$CONTAINER_NAME" 2>&1 | sed 's/^/      /' || true
}

# `GET /healthz` dari DALAM container. 200 + `"ok":true` ⇔ vendor terpasang,
# skema SQLite termigrasi, dan server listening (urutan yang sama dipakai
# `command:` compose), jadi tidak ada lagi jendela balapan.
probe_ready() {
    docker exec "$CONTAINER_NAME" php -r '
        $ctx  = stream_context_create(["http" => ["timeout" => 5, "ignore_errors" => true]]);
        $body = @file_get_contents("http://127.0.0.1:" . $argv[1] . "/healthz", false, $ctx);
        exit(is_string($body) && str_contains($body, "\"ok\":true") ? 0 : 1);
    ' "$CONTAINER_HTTP_PORT" >/dev/null 2>&1
}

wait_ready() {
    local waited=0
    info "Menunggu dashboard siap (composer install + migrasi DB + server start) ..."
    while [ "$waited" -lt "$WAIT_SECS" ]; do
        if container_running; then
            if probe_ready; then
                ok "Dashboard siap (GET /healthz dari dalam container → 200)."
                return 0
            fi
        elif container_exists; then
            # Container ADA tapi tidak 'running' ⇒ bootstrap fail-fast/container
            # berhenti: menunggu lebih lama tidak akan menolong.
            if [ "$waited" -ge 6 ]; then
                warn "Container $CONTAINER_NAME tidak berjalan — bootstrap-nya berhenti."
                print_container_logs
                return 1
            fi
        elif [ "$waited" -ge 15 ]; then
            warn "Container $CONTAINER_NAME belum ada."
            warn "Jalankan dulu: (cd $PROJECT_DIR && docker compose up -d --build)"
            return 1
        fi
        sleep 2
        waited=$((waited + 2))
        if [ $((waited % 30)) -eq 0 ]; then
            info "  ... masih menunggu bootstrap (${waited}s / ${WAIT_SECS}s)"
        fi
    done
    warn "Dashboard belum siap setelah ${WAIT_SECS}s — user admin TIDAK dibuat."
    print_container_logs
    return 1
}

# ---------------------------------------------------------------- make:admin
# Echo keluaran make:admin; exit code dari percobaan terakhir.
run_make_admin() {
    local -a args=(php webman make:admin "$OPT_USER")
    if [ -n "$OPT_PASS" ]; then
        args+=("$OPT_PASS")
    fi

    local all_out="" out="" rc=0 attempt=1
    while [ "$attempt" -le 3 ]; do
        rc=0
        out="$(docker exec "$CONTAINER_NAME" "${args[@]}" 2>&1)" || rc=$?
        all_out+="${out}"$'\n'
        if [ "$rc" -eq 0 ]; then
            break
        fi
        if printf '%s' "$out" | grep -qi 'sudah punya user'; then
            break
        fi
        if [ "$attempt" -lt 3 ]; then
            # ke stderr: keluaran fungsi ini ditangkap pemanggil (command substitution)
            warn "make:admin gagal (exit $rc) — coba lagi dalam 3 detik ..." >&2
            sleep 3
        fi
        attempt=$((attempt + 1))
    done

    printf '%s' "$all_out"
    return "$rc"
}

main() {
    parse_args "$@"
    require_docker

    if ! container_exists; then
        die "Container $CONTAINER_NAME belum ada — jalankan dulu: (cd $PROJECT_DIR && docker compose up -d --build)"
    fi
    if [ ! -f "$COMPOSE_FILE" ]; then
        warn "docker-compose.yml tidak ditemukan di $PROJECT_DIR (lanjut: container $CONTAINER_NAME sudah ada)."
    fi

    wait_ready || die "Dashboard belum siap. Periksa log di atas, lalu ulangi: sudo $0 --user $OPT_USER"

    info "Membuat user admin (php webman make:admin) ..."
    local out="" rc=0
    out="$(run_make_admin)" || rc=$?

    if printf '%s' "$out" | grep -qi 'sudah punya user'; then
        printf '%s\n' "$out"
        warn "Store user sudah berisi user — tidak ada user admin baru. Kelola user lewat halaman 'Manage Users'."
        return 0
    fi

    if [ "$rc" -ne 0 ]; then
        warn "Gagal membuat user admin (exit $rc) — keluaran make:admin:" >&2
        printf '%s\n' "$out" | sed 's/^/      /' >&2
        print_container_logs >&2
        die "Periksa log container di atas, lalu ulangi perintah yang sama."
    fi

    printf '%s\n' "$out"
    warn "Simpan kredensial di atas — password hanya tampil SEKALI. Segera ganti lewat halaman 'Manage Users'."
}

main "$@"
