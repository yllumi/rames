<?php $pageTitle = 'Network · ' . $name; $active = 'networks'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
// Null-safe: saat network null (engine error), hindari warning offset.
$ipam = is_array($network['IPAM'] ?? null) ? ($network['IPAM']['Config'][0] ?? []) : [];
$labels = is_array($network['Labels'] ?? null) ? $network['Labels'] : [];
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <h1 class="h3 mb-0 mono"><?= e($name) ?></h1>
    <?php if ($builtin): ?>
      <span class="badge badge-stopped">built-in</span>
    <?php endif; ?>
    <?php if (!empty($network['Internal'])): ?><span class="badge badge-stopped">internal</span><?php endif; ?>
    <?php if (!empty($network['Attachable'])): ?><span class="badge badge-deploying">attachable</span><?php endif; ?>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="/networks">&larr; Networks</a>
</div>

<?php if ($engineError): ?>
  <div class="alert alert-warning" role="alert"><?= e($engineError) ?></div>
<?php endif; ?>

<?php if ($network !== null): ?>
<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><h2 class="h6 mb-0">Info Network</h2></div>
      <div class="card-body py-2">
        <dl class="app-info mb-0">
          <div class="app-info-item">
            <dt class="k">Driver</dt>
            <dd class="v mb-0"><?= e((string) ($network['Driver'] ?? '-')) ?></dd>
          </div>
          <div class="app-info-item">
            <dt class="k">Scope</dt>
            <dd class="v mb-0"><?= e((string) ($network['Scope'] ?? '-')) ?></dd>
          </div>
          <div class="app-info-item">
            <dt class="k">Subnet</dt>
            <dd class="v mb-0 small mono"><?= e((string) ($ipam['Subnet'] ?? '-')) ?></dd>
          </div>
          <div class="app-info-item">
            <dt class="k">Gateway</dt>
            <dd class="v mb-0 small mono"><?= e((string) ($ipam['Gateway'] ?? '-')) ?></dd>
          </div>
          <div class="app-info-item">
            <dt class="k">IP Range</dt>
            <dd class="v mb-0 small mono"><?= e((string) ($ipam['IPRange'] ?? '-')) ?></dd>
          </div>
          <div class="app-info-item">
            <dt class="k">Dibuat</dt>
            <dd class="v mb-0 small"><?= e((string) ($network['Created'] ?? '-')) ?></dd>
          </div>
          <div class="app-info-item">
            <dt class="k">ID</dt>
            <dd class="v mb-0 small mono"><?= e(substr((string) ($network['Id'] ?? ''), 0, 12)) ?></dd>
          </div>
          <?php if (is_array($labels) && $labels !== []): ?>
          <div class="app-info-item">
            <dt class="k">Labels</dt>
            <dd class="v mb-0 small">
              <?php foreach ($labels as $lk => $lv): ?>
                <span class="mono d-block"><?= e((string) $lk) ?>=<?= e((string) $lv) ?></span>
              <?php endforeach; ?>
            </dd>
          </div>
          <?php endif; ?>
        </dl>
      </div>
      <?php if (!$builtin): ?>
      <div class="card-footer">
        <form method="post" action="/networks/<?= e(rawurlencode($name)) ?>/delete"
              onsubmit="return confirm('Hapus network <?= e($name) ?>? Pastikan tidak dipakai container lain.');">
          <?= csrf_field() ?>
          <button class="btn btn-outline-danger btn-sm">✕ Hapus network</button>
          <span class="text-muted small ms-2">Network yang masih dipakai container akan ditolak.</span>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h2 class="h6 mb-0">Hubungkan Container</h2></div>
      <div class="card-body">
        <?php if (empty($candidates)): ?>
          <p class="text-muted small mb-0">Tidak ada container yang bisa dihubungkan (semua container sudah berada di network ini, atau Docker Engine tidak dapat diakses).</p>
        <?php else: ?>
        <form method="post" action="/networks/<?= e(rawurlencode($name)) ?>/connect" class="vstack gap-3">
          <?= csrf_field() ?>
          <div>
            <label class="form-label small fw-semibold">Container</label>
            <select name="container" class="form-select form-select-sm" required>
              <?php foreach ($candidates as $c): ?>
                <option value="<?= e($c['name']) ?>"><?= e($c['name']) ?> <?= e($c['status']) !== '' ? '· ' . e($c['status']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label small fw-semibold">Alias network (opsional)</label>
            <input type="text" name="alias" class="form-control form-control-sm mono" placeholder="mis. db-shared" maxlength="64">
            <div class="form-text">Nama alternatif yang bisa dipakai container lain untuk menjangkau container ini di network yang sama.</div>
          </div>
          <button class="btn btn-primary btn-sm">Hubungkan container</button>
          <p class="text-muted small mb-0">
            Catatan: koneksi manual ini <strong>hilang</strong> bila container diciptakan ulang
            compose (Rebuild/Rollback/Stop-Start). Untuk koneksi lintas-app yang persisten,
            gunakan tab <strong>Network</strong> di detail app (external network via compose).
          </p>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<section class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Container Terhubung</h2>
    <span class="text-muted small"><?= count($attached) ?> container</span>
  </div>
  <?php if (empty($attached)): ?>
    <div class="card-body text-muted small">Belum ada container terhubung ke network ini.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Container</th><th>IPv4</th><th>IPv6</th><th>MAC</th><th class="text-end">Aksi</th></tr>
    </thead>
    <tbody>
      <?php foreach ($attached as $c): ?>
      <tr>
        <td><span class="mono"><?= e($c['name']) ?></span></td>
        <td class="small mono"><?= e($c['ipv4'] !== '' ? $c['ipv4'] : '-') ?></td>
        <td class="small mono"><?= e($c['ipv6'] !== '' ? $c['ipv6'] : '-') ?></td>
        <td class="small mono"><?= e($c['mac'] !== '' ? $c['mac'] : '-') ?></td>
        <td class="text-end">
          <form method="post" action="/networks/<?= e(rawurlencode($name)) ?>/disconnect" class="d-inline"
                onsubmit="return confirm('Putuskan container <?= e($c['name']) ?> dari network <?= e($name) ?>?');">
            <?= csrf_field() ?>
            <input type="hidden" name="container" value="<?= e($c['name']) ?>">
            <button class="btn btn-outline-secondary btn-sm">Putus</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
