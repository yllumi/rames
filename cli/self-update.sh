#!/bin/sh
#
# Rames self-update helper (SPECS.md §7.8 / ARCHITECTURE.md §5.14).
#
# Dijalankan di dalam HELPER CONTAINER yang terpisah dari container dashboard —
# bukan bagian compose project, sehingga ia tetap hidup saat `docker compose up`
# men-ciptakan ulang dashboard (proses yang meng-update tidak boleh mematikan
# dirinya sendiri di tengah pekerjaan).
#
# Argumen: $1 = path plan.json (ditulis dashboard; seluruh nilainya sudah
# divalidasi di PHP: SHA heksadesimal, branch, path). Skrip ini TIDAK
# meng-interpolasi input user ke dalam shell — semua pembacaan lewat
# `update-report.php get`, semua nilai dipakai sebagai argumen terkutip.
#
# Alur:
#   preflight (docker) → fetch → merge/checkout → composer (bila perlu) →
#   compose up --build --force-recreate → tunggu /healthz → sukses
#   …atau bila gagal/tidak sehat → reset ke SHA lama → rebuild → tunggu /healthz
#
# Seluruh keluaran command mentah masuk ke berkas log (ditampilkan di panel).

set -u

PLAN="${1:-}"
REPORT=/tmp/rames-update-report.php
DONE=0
PRE_ERROR=""
BUILD_OK=0

if [ -z "$PLAN" ] || [ ! -f "$PLAN" ]; then
  echo "Plan update tidak ditemukan: $PLAN" >&2
  exit 1
fi

cfg() { php "$REPORT" get "$PLAN" "$1" "${2:-}"; }
rep() { php "$REPORT" set "$PLAN" "$1" "$2" "${3:-}" >/dev/null 2>&1 || true; }
st()  { php "$REPORT" state "$PLAN" "$1" "$2" >/dev/null 2>&1 || true; }

ROOT=$(cfg root)
PROJECT=$(cfg project)
SERVICE=$(cfg service)
BRANCH=$(cfg branch)
MODE=$(cfg mode)
OLD_SHA=$(cfg old_sha)
TARGET_SHA=$(cfg target_sha)
HEALTH_URL=$(cfg health_url)
HEALTH_TIMEOUT=$(cfg health_timeout 180)
ROLLBACK_TIMEOUT=$(cfg rollback_timeout 180)
LOG=$(cfg log_file)
RUN_ID=$(cfg id)

mkdir -p "$(dirname "$LOG")" 2>/dev/null || true

log() {
  printf '[%s] %s\n' "$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S%z')" "$1" >>"$LOG" 2>/dev/null || true
}

# Laporan akhir wajib ada: kalau skrip berhenti di tengah (kill, OOM, error tak
# terduga) UI tidak boleh macet di status "sedang berjalan" selamanya.
on_exit() {
  code=$?
  if [ "$DONE" -ne 1 ]; then
    log "helper keluar tanpa laporan akhir (exit $code)"
    rep finished error "Helper berhenti tak terduga (exit $code) — lihat log."
  fi
  exit "$code"
}
trap on_exit EXIT

log "=== mulai: id=$RUN_ID mode=$MODE root=$ROOT branch=$BRANCH uid=$(id -u):$(id -g) ==="
rep preflight running "Memeriksa prasyarat helper…"

# ----------------------------------------------------------------------
# 1) Preflight: Docker socket harus bisa dipakai SEBELUM menyentuh git.
#    (Kalau tidak, repo sudah berubah tapi container tidak pernah dibuat ulang
#    → kode di disk dan proses yang jalan jadi tidak sinkron.)
# ----------------------------------------------------------------------
if ! docker version --format '{{.Server.Version}}' >/dev/null 2>&1; then
  log "docker tidak dapat diakses oleh uid $(id -u) gid $(id -g)"
  rep preflight error "Helper tidak bisa mengakses Docker socket (grup socket tidak sesuai)."
  DONE=1
  exit 1
fi
log "docker OK: $(docker version --format '{{.Server.Version}}' 2>/dev/null)"

if [ ! -d "$ROOT/.git" ]; then
  log "bukan repo git: $ROOT"
  rep preflight error "Direktori repo tidak ditemukan di helper: $ROOT"
  DONE=1
  exit 1
fi

cd "$ROOT" || {
  log "tidak bisa masuk ke $ROOT"
  rep preflight error "Tidak bisa masuk ke direktori repo: $ROOT"
  DONE=1
  exit 1
}

# ----------------------------------------------------------------------
# 2) Ambil versi target dari remote
# ----------------------------------------------------------------------
if [ "$MODE" = "rollback" ]; then
  rep fetch running "Mengambil versi lama ${TARGET_SHA}…"
  if ! git fetch --prune origin "$BRANCH" >>"$LOG" 2>&1; then
    log "git fetch gagal (rollback)"
    rep fetch error "git fetch gagal — lihat log."
    DONE=1
    exit 1
  fi
  if ! git checkout --force "$TARGET_SHA" >>"$LOG" 2>&1; then
    log "git checkout $TARGET_SHA gagal"
    rep fetch error "Tidak bisa kembali ke versi $TARGET_SHA — lihat log."
    DONE=1
    exit 1
  fi
else
  rep fetch running "Mengambil pembaruan dari origin/$BRANCH…"
  if ! git fetch --prune origin "$BRANCH" >>"$LOG" 2>&1; then
    log "git fetch gagal"
    rep fetch error "git fetch gagal (jaringan/DNS/URL remote?) — lihat log."
    DONE=1
    exit 1
  fi
  # Keluar dari detached HEAD (mis. sisa rollback sebelumnya) sebelum merge.
  if ! git checkout --force "$BRANCH" >>"$LOG" 2>&1; then
    log "git checkout $BRANCH gagal"
    rep fetch error "Tidak bisa berpindah ke branch $BRANCH — lihat log."
    DONE=1
    exit 1
  fi

  rep merge running "Menerapkan versi terbaru…"
  if ! git merge --ff-only "origin/$BRANCH" >>"$LOG" 2>&1; then
    log "merge --ff-only gagal (branch lokal menyimpang dari origin)"
    rep merge error "Tidak bisa fast-forward ke origin/$BRANCH (branch lokal menyimpang) — lihat log."
    DONE=1
    exit 1
  fi
fi

NEW_SHA=$(git rev-parse HEAD 2>/dev/null || echo "")
st target_sha "$NEW_SHA"
log "HEAD sekarang: $NEW_SHA"

# ----------------------------------------------------------------------
# 3) Dependensi Composer (hanya bila vendor hilang atau composer.* berubah)
# ----------------------------------------------------------------------
CHANGED=$(git diff --name-only "$OLD_SHA" "$NEW_SHA" 2>/dev/null || true)
NEED_COMPOSER=0
if [ ! -f vendor/autoload.php ]; then
  NEED_COMPOSER=1
fi
case "$CHANGED" in
  *composer.json*|*composer.lock*) NEED_COMPOSER=1 ;;
esac

if [ "$NEED_COMPOSER" -eq 1 ]; then
  rep composer running "Memasang dependensi Composer…"
  log "composer install dijalankan"
  if ! composer install --no-dev --optimize-autoloader --no-interaction --no-progress >>"$LOG" 2>&1; then
    log "composer install GAGAL"
    PRE_ERROR="composer install gagal"
  fi
fi

# ----------------------------------------------------------------------
# 4) Build + recreate container dashboard
# ----------------------------------------------------------------------
HEALTHY=0
if [ -z "$PRE_ERROR" ]; then
  rep build running "Membangun image & menciptakan ulang container dashboard…"
  log "docker compose up -d --build --force-recreate $SERVICE"
  if docker compose -p "$PROJECT" --project-directory "$ROOT" up -d --build --force-recreate "$SERVICE" >>"$LOG" 2>&1; then
    BUILD_OK=1
  else
    log "docker compose up GAGAL"
    BUILD_OK=0
  fi

  # --------------------------------------------------------------------
  # 5) Verifikasi versi baru benar-benar melayani request
  #    (`--force-recreate` menjamin proses PHP baru → kode dari bind mount
  #     benar-benar dimuat, walau image-nya identik.)
  # --------------------------------------------------------------------
  if [ "$BUILD_OK" -eq 1 ]; then
    rep health running "Menunggu dashboard melayani request…"
    waited=0
    while [ "$waited" -lt "$HEALTH_TIMEOUT" ]; do
      body=$(curl -fsS --max-time 5 "$HEALTH_URL" 2>/dev/null || true)
      case "$body" in
        *'"ok":true'*)
          HEALTHY=1
          log "healthz OK setelah ${waited}s: $body"
          break
          ;;
      esac
      sleep 3
      waited=$((waited + 3))
    done
    if [ "$HEALTHY" -ne 1 ]; then
      log "healthz TIDAK sehat setelah ${HEALTH_TIMEOUT}s ($HEALTH_URL)"
      PRE_ERROR="versi baru tidak melayani request (healthz gagal)"
    fi
  else
    PRE_ERROR="build/recreate gagal"
  fi
fi

if [ "$HEALTHY" -eq 1 ]; then
  log "selesai: versi baru sehat ($NEW_SHA)"
  rep finished success "Dashboard diperbarui ke ${NEW_SHA} dan sehat."
  DONE=1
  exit 0
fi

# ----------------------------------------------------------------------
# 6) Gagal → rollback otomatis ke versi sebelumnya
# ----------------------------------------------------------------------
log "GAGAL: $PRE_ERROR — rollback ke $OLD_SHA"
st rollback_from "$NEW_SHA"
rep rolling_back running "Gagal (${PRE_ERROR}) — mengembalikan ke versi sebelumnya…"

if [ -z "$OLD_SHA" ]; then
  rep finished error "Update gagal (${PRE_ERROR}) dan tidak ada versi sebelumnya untuk dikembalikan — lihat log."
  DONE=1
  exit 1
fi

git fetch --prune origin "$BRANCH" >>"$LOG" 2>&1 || true
if ! git reset --hard "$OLD_SHA" >>"$LOG" 2>&1; then
  rep finished error "Rollback gagal: tidak bisa kembali ke ${OLD_SHA} — lihat log."
  DONE=1
  exit 1
fi
st target_sha "$OLD_SHA"
log "kode dikembalikan ke $OLD_SHA; rebuild & recreate"

if ! docker compose -p "$PROJECT" --project-directory "$ROOT" up -d --build --force-recreate "$SERVICE" >>"$LOG" 2>&1; then
  rep finished error "Rollback gagal: rebuild versi lama (${OLD_SHA}) error — lihat log."
  DONE=1
  exit 1
fi

waited=0
while [ "$waited" -lt "$ROLLBACK_TIMEOUT" ]; do
  body=$(curl -fsS --max-time 5 "$HEALTH_URL" 2>/dev/null || true)
  case "$body" in
    *'"ok":true'*)
      log "rollback sehat kembali setelah ${waited}s"
      rep finished rolled_back "Update dibatalkan (${PRE_ERROR}) — dashboard kembali ke ${OLD_SHA}."
      DONE=1
      exit 0
      ;;
  esac
  sleep 3
  waited=$((waited + 3))
done

rep finished error "Rollback dijalankan tetapi dashboard (${OLD_SHA}) tetap tidak sehat — perlu pemulihan manual (lihat log)."
DONE=1
exit 1
