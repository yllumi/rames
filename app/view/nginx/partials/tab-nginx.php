<?php
// Tab "Nginx" — status reload terakhir + aksi reload nginx HOST (global untuk
// semua app). Dipindah apa adanya dari index.php ke partial tab; satu tambahan:
// hidden input `tab=nginx` supaya redirect setelah reload kembali ke tab ini
// (form POST adalah navigasi penuh, jadi JS tab-sync tidak ikut berjalan).
$status = is_array($status ?? null) ? $status : null;
?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
    <h2 class="h6 mb-0">Status Reload</h2>
    <?php if (is_admin()): ?>
    <form method="post" action="/nginx/reload" class="d-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="tab" value="nginx">
      <button class="btn btn-outline-secondary btn-sm" title="Validasi config lalu reload nginx host">↻ Reload Nginx</button>
    </form>
    <?php else: ?>
    <span class="text-muted small">Reload hanya untuk admin (berlaku untuk seluruh host).</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($status): ?>
      <?php if (!empty($status['ok'])): ?>
        <div class="alert alert-success mb-0">Reload Nginx terakhir berhasil (<?= e($status['updated_at'] ?? '?') ?>).</div>
      <?php else: ?>
        <div class="alert alert-danger mb-0">Reload Nginx terakhir GAGAL: <?= e($status['error'] ?? 'unknown') ?></div>
      <?php endif; ?>
    <?php else: ?>
      <div class="text-muted small mb-0">Belum ada status reload. Klik <strong>Reload Nginx</strong> untuk memuat &amp; mengaktifkan config terbaru (mis. setelah deploy, set custom domain, atau aktifkan SSL).</div>
    <?php endif; ?>
  </div>
</div>
