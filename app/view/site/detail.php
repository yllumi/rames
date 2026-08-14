<?php
$pageTitle = $site['name'];
$active = 'sites';
$status = $site['status'] ?? 'unknown';
$isBusy = in_array($status, ['deploying'], true);

// overlay status container live di atas data tersimpan
$liveByName = [];
foreach ($live as $lc) {
    $liveByName[$lc['container_name']] = $lc;
}
$containers = $site['containers'] ?? [];
foreach ($containers as &$c) {
    $lc = $liveByName[$c['container_name']] ?? null;
    if ($lc) {
        $c['status'] = $lc['status'] ?? $c['status'];
        $c['host_port'] = $lc['host_port'] ?? $c['host_port'];
        $c['internal_port'] = $lc['internal_port'] ?? $c['internal_port'];
    }
}
unset($c);
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <h1 class="h3 mb-0 mono"><?= e($site['name']) ?></h1>
    <span class="badge badge-<?= e($status) ?>" id="site-status"><?= e($status) ?></span>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="/sites">&larr; Daftar Sites</a>
</div>

<?php if ($isBusy): ?>
  <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
    <span class="spinner-border spinner-border-sm text-info mt-1 flex-shrink-0" role="status" aria-hidden="true"></span>
    <div>
      Sedang <strong><?= e($site['stage'] ?? 'deploying') ?></strong>:
      <span id="site-message"><?= e($site['message'] ?? '') ?></span><br>
      <span class="text-muted small">(halaman akan refresh otomatis)</span>
    </div>
  </div>
<?php elseif ($status === 'error'): ?>
  <div class="alert alert-danger" role="alert"><strong>Error:</strong> <?= e($site['error'] ?? $site['message'] ?? '') ?></div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body py-2">
    <dl class="site-info mb-0">
      <div class="site-info-item">
        <dt class="k">Subdomain</dt>
        <dd class="v mb-0"><a href="http://<?= e($site['subdomain']) ?>" target="_blank" rel="noopener"><?= e($site['subdomain']) ?></a></dd>
      </div>
      <div class="site-info-item">
        <dt class="k">Repo</dt>
        <dd class="v mb-0"><?= e($site['repo_url']) ?></dd>
      </div>
      <div class="site-info-item">
        <dt class="k">Branch</dt>
        <dd class="v mb-0"><?= e($site['branch'] ?? 'main') ?></dd>
      </div>
      <div class="site-info-item">
        <dt class="k">Akses repo</dt>
        <dd class="v mb-0">
          <?php if (($site['auth_method'] ?? 'none') === 'ssh'): ?>
            SSH deploy key
            <?php if ($sshPubkey): ?>
              <a class="small fw-normal ms-1" data-bs-toggle="collapse" href="#deploykey-card" role="button" aria-expanded="false" aria-controls="deploykey-card">lihat public key</a>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted fw-normal">Publik (anonim)</span>
          <?php endif; ?>
        </dd>
      </div>
      <div class="site-info-item">
        <dt class="k">Primary Service</dt>
        <dd class="v mb-0"><?= e($site['primary_service'] ?? '-') ?></dd>
      </div>
      <div class="site-info-item">
        <dt class="k">Lokasi</dt>
        <dd class="v mb-0 small"><?= e($site['local_path'] ?? '') ?></dd>
      </div>
      <div class="site-info-item">
        <dt class="k">Compose Files</dt>
        <dd class="v mb-0 small"><?= e(implode(', ', $site['compose_files'] ?? ['docker-compose.yml'])) ?></dd>
      </div>
    </dl>
  </div>
</div>

<?php if (($site['auth_method'] ?? 'none') === 'ssh' && $sshPubkey): ?>
<div class="collapse" id="deploykey-card">
  <div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0">SSH Deploy Key</h2></div>
    <div class="card-body">
      <p class="text-muted small mb-2">Tambahkan public key ini sebagai <strong>Deploy Key</strong> di repo Anda bila belum (GitHub/GitLab: <em>Settings → Deploy keys</em>). Diperlukan untuk <code>git pull</code> saat <strong>Rebuild</strong>.</p>
      <div class="input-group">
        <textarea id="ssh-pubkey-detail" class="form-control mono form-control-sm" rows="4" readonly><?= e($sshPubkey) ?></textarea>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyDetailKey()">Salin</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$isBusy): ?>
<div class="d-flex flex-wrap gap-2 mb-4">
  <form method="post" action="/sites/<?= e($site['id']) ?>/rebuild"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">↻ Rebuild</button></form>

  <?php if ($status === 'running'): ?>
    <form method="post" action="/sites/<?= e($site['id']) ?>/stop"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">■ Stop</button></form>
  <?php elseif ($status === 'stopped'): ?>
    <form method="post" action="/sites/<?= e($site['id']) ?>/start"><?= csrf_field() ?><button class="btn btn-success btn-sm">▶ Start</button></form>
  <?php endif; ?>

  <form method="post" action="/sites/<?= e($site['id']) ?>/delete"
        onsubmit="return confirm('Hapus site <?= e($site['name']) ?>? Container (down -v), config Nginx, dan direktori lokal akan dihapus.');">
    <?= csrf_field() ?><button class="btn btn-danger btn-sm">✕ Delete</button>
  </form>
</div>
<?php endif; ?>

<section class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Containers</h2>
    <span class="text-muted small"><?= count($containers) ?> container</span>
  </div>
  <?php if (empty($containers)): ?>
    <div class="card-body text-muted small">Belum ada data container.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Service</th><th>Container</th><th>Image</th><th>Port</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($containers as $c): ?>
      <tr>
        <td><strong><?= e($c['service_name'] ?? '-') ?></strong><?= ($site['primary_service'] ?? '') === ($c['service_name'] ?? '') ? ' <span class="text-muted small">(primary)</span>' : '' ?></td>
        <td><?= e($c['container_name'] ?? '-') ?></td>
        <td class="small"><?= e($c['image'] ?? '-') ?></td>
        <td class="small">
          <?php if (!empty($c['host_port'])): ?>
            <?= e($c['host_port']) ?><span class="text-muted">:<?= e($c['internal_port'] ?? '?') ?></span>
          <?php else: ?>
            <span class="text-muted">-</span>
          <?php endif; ?>
        </td>
        <td><span class="badge badge-<?= e($c['status'] ?? 'unknown') ?>"><?= e($c['status'] ?? 'unknown') ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<section class="card mb-4">
  <div class="card-header"><h2 class="h6 mb-0">Nginx</h2></div>
  <div class="card-body">
    <?php if ($nginxStatus): ?>
      <?php if (!empty($nginxStatus['ok'])): ?>
        <div class="alert alert-success mb-0">Reload Nginx terakhir berhasil (<?= e($nginxStatus['updated_at'] ?? '?') ?>).</div>
      <?php else: ?>
        <div class="alert alert-danger mb-0">Reload Nginx terakhir GAGAL: <?= e($nginxStatus['error'] ?? 'unknown') ?></div>
      <?php endif; ?>
    <?php else: ?>
      <div class="text-muted small mb-0">Belum ada status reload (watcher di host belum menulis <?= e(config('deploy.nginx_reload_status_file')) ?>).</div>
    <?php endif; ?>
  </div>
</section>

<script>
function copyDetailKey() {
  var t = document.getElementById('ssh-pubkey-detail');
  if (!t) return;
  t.select();
  t.setSelectionRange(0, 99999);
  try { navigator.clipboard.writeText(t.value); } catch (e) {}
  try { document.execCommand('copy'); } catch (e) {}
}
</script>

<?php include app_path() . '/view/partials/footer.php'; ?>

<?php if ($isBusy): ?>
<script>
(function () {
  var url = '/api/sites/<?= e($site['id']) ?>/status';
  var ticks = 0;
  var timer = setInterval(function () {
    ticks++;
    fetch(url).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.site) {
        var s = document.getElementById('site-status');
        var m = document.getElementById('site-message');
        if (s) s.textContent = d.site.status;
        if (m) m.textContent = d.site.message || '';
        if (d.site.status !== 'deploying') {
          clearInterval(timer);
          window.location.reload();
        }
      }
    }).catch(function () {});
    // batas aman polling
    if (ticks > 600) clearInterval(timer);
  }, 3000);
})();
</script>
<?php endif; ?>
