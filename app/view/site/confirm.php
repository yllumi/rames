<?php $pageTitle = 'Confirm Site'; $active = 'sites'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Konfirmasi Deploy</h1>
    <p class="text-muted small mb-0">Repo: <span class="mono"><?= e($pending['repo_url']) ?></span> · branch <span class="mono"><?= e($pending['branch']) ?></span> · <?= e($pending['local_path']) ?> · compose: <span class="mono"><?= e($pending['compose_file']) ?></span></p>
  </div>
</div>

<form method="post" action="/sites/create/confirm">
  <?= csrf_field() ?>

  <div class="card mb-3">
    <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th>Service</th>
          <th>Container Port</th>
          <th>Host Port (edit)</th>
          <th>Primary</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending['services'] as $svcName => $svc): ?>
        <?php $hasPorts = !empty($svc['ports']); ?>
        <tr>
          <td>
            <strong class="mono"><?= e($svcName) ?></strong>
            <?php if (!$hasPorts): ?><span class="text-muted small">(internal)</span><?php endif; ?>
          </td>
          <td class="mono text-muted"><?= e($svc['internal_port'] ?? '-') ?></td>
          <td>
            <?php if ($hasPorts): ?>
            <input type="number" class="form-control form-control-sm port-input" name="services[<?= e($svcName) ?>][host_port]"
                   value="<?= e($svc['host_port'] ?? '') ?>" min="1" max="65535" required style="max-width:160px;">
            <?php else: ?>
            <span class="text-muted">&mdash;</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($hasPorts): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="primary_service" value="<?= e($svcName) ?>" id="primary-<?= e($svcName) ?>"
                     <?= ($pending['primary_service'] ?? '') === $svcName ? 'checked' : '' ?>>
              <label class="form-check-label" for="primary-<?= e($svcName) ?>">Primary</label>
            </div>
            <?php else: ?>
            <span class="text-muted">&mdash;</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <p class="text-muted small">Port yang berkonflik sudah otomatis diganti dari rentang <code><?= e(config('deploy.port_range.start')) ?>–<?= e(config('deploy.port_range.end')) ?></code>. Sesuaikan bila perlu.</p>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">Deploy Site</button>
    <a class="btn btn-outline-secondary" href="/sites/create">Kembali</a>
  </div>
</form>

<?php include app_path() . '/view/partials/footer.php'; ?>
