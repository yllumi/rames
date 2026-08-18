#!/usr/bin/env bash
#
# Rames — Renewal sertifikat Let's Encrypt (host) — SPECS.md §8a
#
# Menjalankan `certbot renew` secara terjadwal (systemd timer, 2×/hari).
# Sertifikat diterbitkan dengan `--keep-until-expiring`, sehingga perintah ini
# idempoten: tidak menerbitkan ulang selama sertifikat masih valid.
#
# Bila ada sertifikat yang BENAR-BENAR diperbarui (mtime live/*/fullchain.pem
# berubah), jalankan `nginx -s reload` (zero-downtime) dan tulis status ke file
# yang dibaca dashboard (nginx-status/last-reload.json).
#
# Dipasang oleh host/install.sh sebagai systemd timer
# (host/systemd/certbot-renew.{service,timer}).
#
# Konfigurasi via environment (bisa di-set di /etc/rames/certbot-renew.env):
#   CERTBOT      binary certbot       (default: certbot)
#   NGINX_BIN    binary nginx host    (default: /usr/sbin/nginx)
#   NGINX_CONF   config utama nginx   (default: /etc/nginx/nginx.conf)
#   LE_PATH      direktori Let's Encrypt (default: /etc/letsencrypt)
#   STATUS_FILE  file status untuk dashboard (default: /app/nginx-status/last-reload.json)
#   SUDO_CMD     prefix sudo untuk nginx (default: "sudo -n"; kosongkan bila jalan root)
set -euo pipefail

CERTBOT="${CERTBOT:-certbot}"
NGINX_BIN="${NGINX_BIN:-/usr/sbin/nginx}"
NGINX_CONF="${NGINX_CONF:-/etc/nginx/nginx.conf}"
LE_PATH="${LE_PATH:-/etc/letsencrypt}"
STATUS_FILE="${STATUS_FILE:-/app/nginx-status/last-reload.json}"
SUDO_CMD="${SUDO_CMD:-sudo -n}"

log() { echo "[$(date '+%F %T')] $*" >&2; }

now_iso() { date -Is 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z'; }

write_status() { # $1 = ok (0/1), $2 = pesan error (opsional)
    local ok="$1" err="${2:-}" dir
    dir="$(dirname "$STATUS_FILE")"
    mkdir -p "$dir" 2>/dev/null || true
    if [ "$ok" -eq 1 ]; then
        printf '{"ok": true, "updated_at": "%s"}\n' "$(now_iso)" > "$STATUS_FILE" 2>/dev/null || true
    else
        printf '{"ok": false, "error": "%s", "updated_at": "%s"}\n' "$err" "$(now_iso)" > "$STATUS_FILE" 2>/dev/null || true
    fi
}

# snapshot mtime fullchain per domain (untuk mendeteksi renewal)
snapshot_mtimes() {
    find "$LE_PATH/live" -maxdepth 2 -name fullchain.pem -exec stat -c '%Y %n' {} \; 2>/dev/null | sort
}

command -v "$CERTBOT" >/dev/null 2>&1 || { log "certbot tidak ditemukan — instal via host/install.sh."; exit 1; }
[ -d "$LE_PATH/live" ] || { log "Belum ada sertifikat di $LE_PATH/live (skip)."; exit 0; }

before="$(snapshot_mtimes)"

set +e
"$CERTBOT" renew --non-interactive --quiet --keep-until-expiring \
    --config-dir "$LE_PATH" \
    --work-dir /var/lib/letsencrypt \
    --logs-dir /var/log/letsencrypt
rc=$?
set -e

after="$(snapshot_mtimes)"

if [ "$rc" -ne 0 ]; then
    log "certbot renew gagal (exit $rc). Cek /var/log/letsencrypt/letsencrypt.log"
    write_status 0 "certbot renew gagal (exit $rc)"
    exit "$rc"
fi

if [ "$before" != "$after" ]; then
    log "Sertifikat diperbarui — nginx -s reload ..."
    if err="$($SUDO_CMD "$NGINX_BIN" -s reload -c "$NGINX_CONF" 2>&1)"; then
        log "Reload Nginx berhasil."
        write_status 1
    else
        log "Reload Nginx GAGAL: $err"
        write_status 0 "$err"
    fi
else
    log "Tidak ada sertifikat yang perlu diperbarui."
fi
