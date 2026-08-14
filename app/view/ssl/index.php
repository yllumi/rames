<?php $pageTitle = 'SSL'; $active = 'ssl'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
$badges = [
    'active' => 'badge-running',
    'pending' => 'badge-deploying',
    'failed' => 'badge-error',
    'expired' => 'badge-error',
    'disabled' => 'badge-stopped',
];
$anyPending = false;
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">SSL / Let's Encrypt</h1>
    <p class="text-muted mb-0">Sertifikat TLS otomatis per domain (subdomain atau custom domain site).</p>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="/sites">&larr; Sites</a>
</div>

<?php if (!$sslSupported): ?>
  <div class="alert alert-warning" role="alert">
    <strong>SSL dinonaktifkan.</strong> APP_DOMAIN (<span class="mono"><?= e(config('deploy.app_domain')) ?></span>) bukan domain publik,
    sehingga Let's Encrypt tidak bisa menerbitkan sertifikat. Set <code>APP_DOMAIN</code> ke domain publik (mis. <code>example.com</code>)
    untuk mengaktifkan fitur ini.
  </div>
<?php endif; ?>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="card-body text-muted small">Belum ada site/domain.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Domain</th><th>Site</th><th>Status SSL</th><th>Kedaluwarsa</th><th>Aksi</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php $st = $r['ssl_status']; $anyPending = $anyPending || $st === 'pending'; ?>
        <tr>
          <td>
            <span class="mono"><?= e($r['domain']) ?></span>
            <?php if (!empty($r['is_custom'])): ?><span class="text-muted small">(custom)</span><?php endif; ?>
          </td>
          <td>
            <a href="/sites/<?= e($r['site']['id']) ?>"><?= e($r['site']['name']) ?></a>
            <span class="text-muted small">(<?= e($r['site']['status'] ?? 'unknown') ?>)</span>
          </td>
          <td>
            <span class="badge <?= e($badges[$st] ?? 'badge-stopped') ?>"><?= e($st) ?></span>
            <?php if ($st === 'pending' && !empty($r['ssl_message'])): ?>
              <div class="text-muted small"><?= e($r['ssl_message']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= $r['ssl_expires_at'] ? e($r['ssl_expires_at']) : '<span class="text-muted">-</span>' ?></td>
          <td>
            <?php if ($st === 'pending'): ?>
              <span class="text-muted small">proses ...</span>
            <?php elseif ($st === 'active'): ?>
              <span class="text-muted small">aktif</span>
            <?php elseif ($sslSupported): ?>
              <form method="post" action="/ssl/<?= e($r['site']['id']) ?>/enable" class="d-inline">
                <?= csrf_field() ?>
                <button class="btn btn-<?= $st === 'failed' ? 'outline-danger' : 'primary' ?> btn-sm">
                  <?= $st === 'failed' ? '↻ Retry SSL' : 'Aktifkan SSL' ?>
                </button>
              </form>
              <?php if ($st === 'failed' && !empty($r['ssl_error'])): ?>
                <div class="text-danger small mt-1"><?= e($r['ssl_error']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted small">&mdash;</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php include app_path() . '/view/partials/footer.php'; ?>

<?php if ($anyPending): ?>
<script>
(function () {
  setTimeout(function () { window.location.reload(); }, 5000);
})();
</script>
<?php endif; ?>
