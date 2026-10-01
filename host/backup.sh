#!/usr/bin/env bash
#
# Rames — Backup volume harian ke S3 via restic (host) — PLAN_VOLUME_BACKUP.md
#
# Dijalankan terjadwal oleh systemd timer (host/systemd/volume-backup.timer),
# memicu worker di dalam container dashboard:
#
#   docker exec <container> php cli/backup.php run
#
# Seluruh logika backup (restic, pemilihan strategi dump/snapshot) ada di
# cli/backup.php; skrip ini HANYA menjembatani timer host → worker container
# (pola certbot-renew.sh, SPECS §8a). Log ditulis ke stdout sehingga tertangkap
# systemd journal:
#
#   journalctl -u volume-backup.service
#
# Dipasang oleh host/install.sh sebagai systemd timer
# (host/systemd/volume-backup.{service,timer}).
#
# Konfigurasi via environment (bisa di-set di /etc/rames/volume-backup.env):
#   VOLUME_BACKUP_HOST_CONTAINER  nama container dashboard     (default: rames-webman)
#   DOCKER                        binary docker host           (default: docker)
#   VOLUME_BACKUP_TIMEOUT         batas tunggu run, detik      (default: 3900)
#
# Kredensial S3 (AWS_*/RESTIC_REPOSITORY) & passphrase restic TIDAK dibaca di
# sini: kredensial diteruskan ke container lewat `environment:` compose, dan
# passphrase ada di file `database/restic/password` (chmod 0600, gitignored).
# JANGAN menyimpan secret di file env host ini — ia dapat dibaca proses lain.
#
# Sengaja TANPA `set -e`: bila worker gagal kita ingin melihat diagnostik dan
# meneruskan exit code-nya (pola certbot-renew.sh), bukan mati senyap.

set -uo pipefail

log() { echo "[$(date '+%F %T')] $*"; }

VOLUME_BACKUP_HOST_CONTAINER="${VOLUME_BACKUP_HOST_CONTAINER:-rames-webman}"
DOCKER="${DOCKER:-docker}"
VOLUME_BACKUP_TIMEOUT="${VOLUME_BACKUP_TIMEOUT:-3900}"

command -v "$DOCKER" >/dev/null 2>&1 || { log "Perintah 'docker' tidak ditemukan."; exit 1; }

# Container harus benar-benar berjalan sebelum `docker exec` (hindari pesan
# membingungkan dari docker bila dashboard sedang mati/di-recreate).
running="$("$DOCKER" inspect -f '{{.State.Running}}' "$VOLUME_BACKUP_HOST_CONTAINER" 2>/dev/null)"
if [ "$running" != "true" ]; then
    log "Container dashboard '$VOLUME_BACKUP_HOST_CONTAINER' tidak berjalan — backup dilewati."
    exit 1
fi

log "Menjalankan backup volume: docker exec $VOLUME_BACKUP_HOST_CONTAINER php cli/backup.php run"

if command -v timeout >/dev/null 2>&1; then
    timeout --signal=TERM "$VOLUME_BACKUP_TIMEOUT" \
        "$DOCKER" exec "$VOLUME_BACKUP_HOST_CONTAINER" php cli/backup.php run
    rc=$?
else
    "$DOCKER" exec "$VOLUME_BACKUP_HOST_CONTAINER" php cli/backup.php run
    rc=$?
fi

if [ "$rc" -eq 124 ]; then
    log "Backup melewati batas waktu ${VOLUME_BACKUP_TIMEOUT}s — proses dihentikan."
fi

if [ "$rc" -ne 0 ]; then
    log "Backup GAGAL (exit $rc). Cek: journalctl -u volume-backup.service, runtime/logs/backup/, dan runtime/backup/status.json."
    exit "$rc"
fi

log "Backup selesai (exit 0)."
exit 0
