<?php
$pageTitle = $site['name'] . ' · Versi';
$active = 'sites';
$status = $site['status'] ?? 'unknown';
$isBusy = in_array($status, ['deploying'], true);
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <h1 class="h3 mb-0 mono"><?= e($site['name']) ?></h1>
    <span class="badge badge-<?= e($status) ?>" id="site-status"><?= e($status) ?></span>
    <span class="text-muted small">Riwayat versi &amp; rollback</span>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="/sites/<?= e($site['id']) ?>">&larr; Detail site</a>
</div>

<?php if ($isBusy): ?>
  <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
    <span class="spinner-border spinner-border-sm text-info mt-1 flex-shrink-0" role="status" aria-hidden="true"></span>
    <div>Sedang <strong><?= e($site['stage'] ?? 'deploying') ?></strong>: <?= e($site['message'] ?? '') ?></div>
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body py-2">
    <dl class="site-info mb-0">
      <div class="site-info-item">
        <dt class="k">Versi aktif</dt>
        <dd class="v mb-0">
          <?php if ($activeSha !== ''): ?>
            <code><?= e($activeSha) ?></code>
          <?php else: ?>
            <span class="text-muted">- (belum ada riwayat — jalankan deploy/rebuild)</span>
          <?php endif; ?>
        </dd>
      </div>
      <div class="site-info-item">
        <dt class="k">Repo</dt>
        <dd class="v mb-0"><?= e($site['repo_url']) ?></dd>
      </div>
      <div class="site-info-item">
        <dt class="k">Branch</dt>
        <dd class="v mb-0"><?= e($site['branch'] ?? 'main') ?></dd>
      </div>
    </dl>
  </div>
</div>

<?php if (empty($deployHistory)): ?>
  <div class="alert alert-info" role="alert">Belum ada riwayat deploy. Setiap deploy/rebuild yang sukses akan tercatat di sini dan bisa di-rollback.</div>
<?php else: ?>
<section class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Checkpoint Rollback (<?= count($deployHistory) ?>)</h2>
    <span class="text-muted small">terbaru dulu</span>
  </div>
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Commit (SHA)</th><th>Waktu</th><th>Aksi</th><th>Status</th><th>Catatan</th><th class="text-end">Rollback</th></tr>
    </thead>
    <tbody>
      <?php foreach ($deployHistory as $h): ?>
      <?php
        $hStatus = (string) ($h['status'] ?? '');
        $hSha = (string) ($h['sha'] ?? '');
        $hShort = (string) ($h['short'] ?? substr($hSha, 0, 7));
        $hBadge = in_array($hStatus, ['success', 'restored'], true) ? 'running' : ($hStatus === 'error' ? 'error' : 'stopped');
        $hMsg = (string) ($h['message'] ?? '');
        $isActive = $hSha !== '' && $hSha === $activeSha;
        $canRollback = in_array($hStatus, ['success', 'restored'], true) && !$isActive && !$isBusy;
      ?>
      <tr class="<?= $isActive ? 'table-active' : '' ?>">
        <td class="small">
          <code><?= e($hSha) ?></code><br>
          <span class="text-muted"><?= e($hShort) ?></span>
          <?php if ($isActive): ?><span class="badge badge-running ms-1">aktif</span><?php endif; ?>
        </td>
        <td class="small text-nowrap"><?= e((string) ($h['created_at'] ?? '-')) ?></td>
        <td class="small"><?= e((string) ($h['action'] ?? '-')) ?></td>
        <td><span class="badge badge-<?= e($hBadge) ?>"><?= e($hStatus) ?></span></td>
        <td class="small text-muted"><?= $hMsg !== '' ? e($hMsg) : '-' ?></td>
        <td class="text-end">
          <?php if ($canRollback): ?>
            <form method="post" action="/sites/<?= e($site['id']) ?>/rollback" class="d-inline"
                  onsubmit="return confirm('Rollback site <?= e($site['name']) ?> ke commit <?= e($hShort) ?>?\n\nSource code akan diganti ke versi itu dan container di-build ulang. Volume/data tidak dihapus.');">
              <?= csrf_field() ?>
              <input type="hidden" name="ref" value="<?= e($hSha) ?>">
              <button class="btn btn-outline-warning btn-sm">↶ Rollback</button>
            </form>
          <?php elseif ($isActive): ?>
            <span class="text-muted small">versi aktif</span>
          <?php else: ?>
            <span class="text-muted small">-</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<div class="alert alert-secondary small mb-0" role="alert">
  <strong>Catatan:</strong> Rollback non-destruktif — volume Docker (data DB) dipertahankan; hanya source code yang dikembalikan ke versi tersebut lalu container di-build ulang. Bila build versi lama gagal, sistem otomatis kembali ke versi yang tadinya aktif.
</div>

<?php include app_path() . '/view/partials/footer.php'; ?>
