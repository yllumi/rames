<?php
/**
 * Tab "Branding" — nama & logo dashboard (rebranding).
 *
 * Presentasi murni: seluruh nilai di-escape dengan `e()`, tidak ada query/logika
 * bisnis dan tidak ada pembacaan request (`$_POST`/`$_FILES`). Nilai yang gagal
 * diunggah ditangani controller (flash + redirect), view hanya merender bentuk
 * form-nya.
 *
 * Kontrak data dari `NginxController::brandingPanel()`:
 *   $branding: array{
 *       name:string, logo_url:string, logo_custom:bool, updated_at:?string,
 *       max_name:int, max_bytes:int, allowed:string
 *   }
 * Normalisasi defensif di bawah menjaga partial tetap dirender walau payload
 * hilang/berubah bentuk (mis. smoke render tanpa controller) — bukan fallback
 * dari request. Nilai bawaan diambil dari konstanta library (satu sumber).
 */
use app\library\Branding\BrandingLogo;
use app\library\Branding\BrandingManager;
use app\library\Storage\BrandingStore;

$branding = is_array($branding ?? null) ? $branding : [];

$name = trim((string) (is_string($branding['name'] ?? null) ? $branding['name'] : ''));
if ($name === '') {
    $name = BrandingStore::DEFAULT_NAME;
}

$logoUrl = (string) (is_string($branding['logo_url'] ?? null) ? $branding['logo_url'] : '');
if ($logoUrl === '') {
    $logoUrl = BrandingManager::DEFAULT_LOGO_URL;
}

$logoCustom = !empty($branding['logo_custom']);

$maxName = is_int($branding['max_name'] ?? null) && $branding['max_name'] > 0
    ? (int) $branding['max_name']
    : BrandingManager::MAX_NAME;

$maxBytes = is_int($branding['max_bytes'] ?? null) && $branding['max_bytes'] > 0
    ? (int) $branding['max_bytes']
    : BrandingLogo::MAX_BYTES;

$allowed = trim((string) (is_string($branding['allowed'] ?? null) ? $branding['allowed'] : ''));
if ($allowed === '') {
    $allowed = 'PNG, JPEG, WebP';
}

// Batas ukuran untuk teks bantuan: 524288 byte = "512 KB".
$maxSizeLabel = $maxBytes === BrandingLogo::MAX_BYTES ? '512 KB' : number_format($maxBytes / 1024) . ' KB';

$updatedAt = trim((string) (is_string($branding['updated_at'] ?? null) ? $branding['updated_at'] : ''));
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">
        <h2 class="h6 mb-0">Identitas dashboard</h2>
      </div>
      <div class="card-body">
        <div class="border rounded p-3 bg-light text-center mb-3">
          <img src="<?= e($logoUrl) ?>" alt="<?= e($name) ?>" style="max-height:96px;max-width:100%">
          <div class="mt-2 small">
            <span class="badge <?= $logoCustom ? 'text-bg-info' : 'text-bg-secondary' ?>"><?= $logoCustom ? 'Kustom' : 'Bawaan' ?></span>
            <?php if ($updatedAt !== ''): ?>
              <span class="text-muted ms-1">Diperbarui <?= e($updatedAt) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <form method="post" action="/nginx/branding" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="branding-name">Nama brand</label>
            <input type="text" class="form-control" id="branding-name" name="name" required
                   maxlength="<?= (int) $maxName ?>" value="<?= e($name) ?>">
            <div class="form-text">Maksimal <?= (int) $maxName ?> karakter. Tampil di sidebar, judul halaman, dan halaman login.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="branding-logo">Logo baru</label>
            <input type="file" class="form-control" id="branding-logo" name="logo"
                   accept="image/png,image/jpeg,image/webp">
            <div class="form-text">Format <?= e($allowed) ?>. Maksimal <?= e($maxSizeLabel) ?>. Kosongkan bila tidak ingin mengganti logo.</div>
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Simpan branding</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <h2 class="h6 mb-0">Kembalikan ke default</h2>
      </div>
      <div class="card-body d-flex flex-column">
        <p class="text-muted small mb-3">
          Mengembalikan nama dashboard ke <strong><?= e(BrandingStore::DEFAULT_NAME) ?></strong> dan logo ke bawaan
          (<span class="mono"><?= e(BrandingManager::DEFAULT_LOGO_URL) ?></span>). Tindakan ini mengganti logo kustom yang sedang aktif.
        </p>
        <form method="post" action="/nginx/branding/reset" class="mt-auto"
              onsubmit="return confirm('Kembalikan nama dan logo dashboard ke default?')">
          <?= csrf_field() ?>
          <button class="btn btn-outline-danger btn-sm" type="submit">Kembalikan ke default</button>
        </form>
      </div>
    </div>
  </div>
</div>
