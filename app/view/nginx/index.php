<?php $pageTitle = 'Nginx'; $active = 'nginx'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Nginx</h1>
    <p class="text-muted mb-0">
      Status &amp; reload <strong>Nginx host</strong> — berlaku global untuk semua site.
      Reload dijalankan lewat helper container via Docker socket (validasi
      <span class="mono">nginx -t</span> lalu reload tanpa downtime).
    </p>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="/sites">&larr; Sites</a>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
    <h2 class="h6 mb-0">Status Reload</h2>
    <form method="post" action="/nginx/reload" class="d-inline">
      <?= csrf_field() ?>
      <button class="btn btn-outline-secondary btn-sm" title="Validasi config lalu reload nginx host">↻ Reload Nginx</button>
    </form>
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

<?php include app_path() . '/view/partials/footer.php'; ?>
