  <!-- ============ Tab: Deployment ============ -->
  <div class="tab-pane fade" id="tab-deploy" role="tabpanel" aria-labelledby="tab-deploy-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Riwayat Deployment</h2>
    <div class="d-flex align-items-center gap-2">
      <?php if ($isCompose): ?>
        <span class="text-muted small">Sumber: file compose <span class="mono"><?= e((string) ($compose['main_file'] ?? 'docker-compose.yml')) ?></span> · tanpa checkpoint Git</span>
      <?php else: ?>
        <span class="text-muted small">Versi aktif: <code><?= $activeSha !== '' ? e(substr((string) $activeSha, 0, 7)) : '-' ?></code></span>
        <a class="btn btn-outline-secondary btn-sm" href="/apps/<?= e($app['id']) ?>/versions">Semua versi &rarr;</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (empty($deployHistory)): ?>
    <div class="card-body text-muted small">Belum ada riwayat deploy. Riwayat tercatat otomatis setiap deploy/rebuild<?= $isCompose ? '/deploy ulang' : '/rollback' ?> yang sukses.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Commit</th><th>Waktu</th><th>Aksi</th><th>Status</th><th class="text-end">Aksi</th></tr>
    </thead>
    <tbody>
      <?php foreach (array_slice($deployHistory, 0, 5) as $h): ?>
      <?php
        $hStatus = (string) ($h['status'] ?? '');
        $hShort = (string) ($h['short'] ?? substr((string) ($h['sha'] ?? ''), 0, 7));
        $hBadge = in_array($hStatus, ['success', 'restored'], true) ? 'running' : ($hStatus === 'error' ? 'error' : 'stopped');
        $hMsg = (string) ($h['message'] ?? '');
        $hMsgShort = strlen($hMsg) > 80 ? substr($hMsg, 0, 80) . '…' : $hMsg;
        $isRollbackTarget = !$isCompose && in_array($hStatus, ['success', 'restored'], true) && ($h['sha'] ?? '') !== $activeSha && !$isBusy
            && $canOperate;
      ?>
      <tr>
        <td><code><?= $hShort !== '' ? e($hShort) : '&mdash;' ?></code><?= $hShort !== '' && ($h['sha'] ?? '') === $activeSha ? ' <span class="text-muted small">(aktif)</span>' : '' ?></td>
        <td class="small"><?= e((string) ($h['created_at'] ?? '-')) ?></td>
        <td class="small"><?= e((string) ($h['action'] ?? '-')) ?></td>
        <td>
          <span class="badge badge-<?= e($hBadge) ?>"><?= e($hStatus) ?></span>
          <?php if ($hMsg !== ''): ?>
            <span class="text-muted small d-block" title="<?= e($hMsg) ?>"><?= e($hMsgShort) ?></span>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <?php if ($isRollbackTarget): ?>
            <form method="post" action="/apps/<?= e($app['id']) ?>/rollback" class="d-inline"
                  onsubmit="return confirm('Rollback app <?= e($app['name']) ?> ke commit <?= e($hShort) ?>?\n\nSource code akan diganti ke versi itu dan container di-build ulang. Volume/data tidak dihapus.');">
              <?= csrf_field() ?>
              <input type="hidden" name="ref" value="<?= e((string) ($h['sha'] ?? '')) ?>">
              <button class="btn btn-outline-warning btn-sm">↶ Rollback</button>
            </form>
          <?php else: ?>
            <span class="text-muted small">-</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php if (!$isCompose && count($deployHistory) > 5): ?>
  <div class="card-footer text-end">
    <a class="btn btn-outline-secondary btn-sm" href="/apps/<?= e($app['id']) ?>/versions">Lihat semua <?= count($deployHistory) ?> versi &rarr;</a>
  </div>
  <?php endif; ?>
  </section>
  </div>
