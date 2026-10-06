<?php
$pageTitle = 'Panduan Setup Restic';
$active = 'backups';

// Halaman statis (admin-only): merender Markdown `host/restic-setup.md` yang
// sudah disanitasi `Markdown::toHtml()` oleh BackupController::guideHtml().
// Sengaja TIDAK memuat `backup.js` — tidak ada aksi/AJAX di sini. HTML panduan
// dirender tanpa `e()` (sudah aman), persis seperti panduan app di detail.php.
$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Backup', 'href' => '/backups'],
    ['label' => 'Panduan', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Panduan Setup Restic</h1>
    <p class="text-muted mb-0">
      Langkah-langkah menyiapkan <span class="mono">restic</span> &amp; object storage (S3) untuk backup
      volume app — pembuatan bucket, kredensial, repositori, hingga penjadwalan harian.
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <a class="btn btn-outline-secondary btn-sm" href="/backups">← Kembali ke Backup</a>
  </div>
</div>

<?php if (($guideHtml ?? '') === ''): ?>
  <div class="alert alert-info" role="alert">Panduan belum tersedia.</div>
<?php else: ?>
  <!-- HTML hasil sanitasi Markdown::toHtml() — sengaja dirender tanpa e(). -->
  <div class="guide-content"><?= $guideHtml ?></div>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
