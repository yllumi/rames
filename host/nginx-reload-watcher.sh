#!/usr/bin/env bash
#
# Rames — Nginx reload watcher (host) — SPECS.md §8.3
#
# Memantau direktori config Nginx app (sites-available/ yang di-mount ke
# dashboard container). Begitu ada file .conf baru/berubah/dihapus:
#   1. `nginx -t` — bila gagal, log error dan JANGAN reload (config lama tetap aktif)
#   2. bila valid, `nginx -s reload` (zero-downtime, bukan restart)
#   3. tulis hasil ke status file (format sama dgn NginxReloader/NginxStatusReader)
#
# Dipasang sebagai systemd service oleh host/install.sh. Berjalan sebagai user
# non-root (`rames-watcher`); reload via sudo dengan izin khusus binary nginx
# saja (host/systemd/dashboard-nginx-watcher.sudoers).
#
# Konfigurasi via environment (bisa di-set di /etc/rames/nginx-watcher.env):
#   WATCH_DIR      direktori config app yang dipantau (default: /etc/nginx/sites-available)
#   NGINX_BIN      binary nginx host        (default: /usr/sbin/nginx)
#   NGINX_CONF     config utama nginx       (default: /etc/nginx/nginx.conf)
#   STATUS_FILE    file status untuk dashboard (default: /app/nginx-status/last-reload.json)
#   SUDO_CMD       prefix sudo untuk nginx  (default: "sudo -n")
#   DEBOUNCE_SECS  jeda sebelum reload setelah event (default: 2)
set -euo pipefail

WATCH_DIR="${WATCH_DIR:-/etc/nginx/sites-available}"
NGINX_BIN="${NGINX_BIN:-/usr/sbin/nginx}"
NGINX_CONF="${NGINX_CONF:-/etc/nginx/nginx.conf}"
STATUS_FILE="${STATUS_FILE:-/app/nginx-status/last-reload.json}"
SUDO_CMD="${SUDO_CMD:-sudo -n}"
DEBOUNCE_SECS="${DEBOUNCE_SECS:-2}"

log() { echo "[$(date '+%F %T')] $*" >&2; }

now_iso() { date -Is 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z'; }

write_status() { # $1 = ok (0/1), $2 = pesan error (opsional)
    local ok="$1" err="${2:-}" dir
    dir="$(dirname "$STATUS_FILE")"
    mkdir -p "$dir" 2>/dev/null || true
    if [ "$ok" -eq 1 ]; then
        printf '{\n  "ok": true,\n  "updated_at": "%s"\n}\n' "$(now_iso)" > "$STATUS_FILE" 2>/dev/null || true
    else
        printf '{\n  "ok": false,\n  "error": "%s",\n  "updated_at": "%s"\n}\n' "$err" "$(now_iso)" > "$STATUS_FILE" 2>/dev/null || true
    fi
}

# jalankan nginx via sudo; output dikembalikan di stdout, exit code dipertahankan
nginx_capture() {
    local out
    if out="$($SUDO_CMD "$NGINX_BIN" "$@" 2>&1)"; then
        printf '%s' "$out"
        return 0
    fi
    printf '%s' "$out"
    return 1
}

reload() {
    log "Perubahan config terdeteksi — nginx -t ..."
    local err
    if ! err="$(nginx_capture -t -c "$NGINX_CONF")"; then
        err="$(printf '%s' "$err" | tail -n 3)"
        log "nginx -t GAGAL — config lama tetap aktif:"
        log "$err"
        write_status 0 "$err"
        return
    fi
    log "nginx -t valid — nginx -s reload ..."
    if err="$(nginx_capture -s reload -c "$NGINX_CONF")"; then
        log "Reload Nginx berhasil."
        write_status 1
    else
        err="$(printf '%s' "$err" | tail -n 3)"
        log "Reload Nginx GAGAL:"
        log "$err"
        write_status 0 "$err"
    fi
}

command -v inotifywait >/dev/null 2>&1 || { log "inotifywait tidak ditemukan — instal inotify-tools."; exit 1; }
[ -d "$WATCH_DIR" ] || { log "Direktori $WATCH_DIR tidak ada."; exit 1; }

log "Nginx reload watcher dimulai: watch=$WATCH_DIR status=$STATUS_FILE"

last_reload=0
inotifywait -m -e close_write -e moved_to -e moved_from -e delete \
    --format '%e %f' "$WATCH_DIR" 2>/dev/null \
    | while IFS= read -r _ev; do
        # debounce: lewati event yang datang segera setelah reload selesai
        now="$(date +%s)"
        if [ "$last_reload" -ne 0 ] && [ $((now - last_reload)) -lt "$DEBOUNCE_SECS" ]; then
            continue
        fi
        sleep "$DEBOUNCE_SECS"
        reload
        last_reload="$(date +%s)"
    done
