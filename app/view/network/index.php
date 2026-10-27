<?php $pageTitle = 'Networks'; $active = 'networks'; ?>
<?php
// Cakupan server (multi-server F3c). Pemilih server mengikuti daftar `$servers`
// dari controller (`ServerAccess::visibleServers()`): admin melihat semua server,
// member bergrant `server_select` melihat server yang boleh ia pilih, dan member
// lain hanya server yang menaungi app yang boleh ia lihat. View **tidak** menambah
// gate `is_admin()` sendiri — penyaringan server adalah milik controller, dan
// server tetap memvalidasi ulang `?server=` (di luar jangkauan ⇒ 404).
$__servers = is_array($servers ?? null) ? array_values(array_filter($servers, 'is_array')) : [];
$__serverId = (string) ($serverId ?? '');
$__serverName = trim((string) ($serverName ?? ''));
$__serverQuery = $__serverId !== '' ? '?server=' . rawurlencode($__serverId) : '';

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Networks', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
$freeCount = 0;
foreach ($rows as $r) {
    if ($r['can_delete']) {
        $freeCount++;
    }
}
?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Networks</h1>
    <p class="text-muted mb-0">
      Kelola <span class="mono">network Docker</span> host: buat <strong>shared network</strong>
      lintas-app, hubungkan/putuskan container, dan hapus network yang tidak terpakai.
      Network built-in &amp; milik app aktif tidak bisa dihapus dari sini.
      <?php if (!($canManage ?? false)): ?>
        <br><span class="small">Anda hanya melihat network milik app yang bisa Anda akses — pembuatan &amp; penghapusan network hanya untuk admin.</span>
      <?php endif; ?>
    </p>
  </div>
  <?php // Pemilih server dirender bila controller mengirim >1 pilihan (admin atau
        // member yang boleh memilih server); instalasi satu server tidak perlu
        // dropdown berisi satu opsi. Server tetap memvalidasi ulang `?server=`. ?>
  <?php if (count($__servers) > 1): ?>
  <form method="get" action="/networks" class="d-flex align-items-end gap-2">
    <div>
      <label class="form-label small fw-semibold mb-1" for="network-server">Server</label>
      <select class="form-select form-select-sm" id="network-server" name="server" onchange="this.form.submit()">
        <?php foreach ($__servers as $__srv): ?>
          <?php $__srvId = (string) ($__srv['id'] ?? ''); $__srvName = trim((string) ($__srv['name'] ?? '')); ?>
          <?php $__srvSelected = $__srvId === $__serverId || ($__serverId === '' && in_array($__srvId, ['', 'local'], true)); ?>
          <option value="<?= e($__srvId) ?>"<?= $__srvSelected ? ' selected' : '' ?>><?= e($__srvName !== '' ? $__srvName : $__srvId) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-outline-secondary btn-sm">Tampilkan</button></noscript>
  </form>
  <?php endif; ?>
</div>

<?php if ($__serverName !== ''): ?>
  <div class="alert alert-info py-2 small" role="alert">
    Menampilkan network di server <strong><?= e($__serverName) ?></strong>. Network Docker bersifat
    <strong>per server</strong> — container di server lain tidak dapat bergabung ke network di sini.
  </div>
<?php endif; ?>

<?php if ($engineError): ?>
  <div class="alert alert-warning" role="alert"><?= e($engineError) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">

  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Daftar Network</h2>
        <span class="text-muted small"><?= count($rows) ?> network (<?= $freeCount ?> bisa dihapus)</span>
      </div>
      <?php if (empty($rows)): ?>
        <div class="card-body text-muted small">Tidak ada network (atau Docker Engine tidak dapat diakses).</div>
      <?php else: ?>
      <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Network</th>
            <th>Driver</th>
            <th>Scope</th>
            <th>Container</th>
            <th>Dibuat</th>
            <th>Status</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <a class="mono" href="/networks/<?= e(rawurlencode($r['name'])) ?><?= $__serverQuery ?>"><?= e($r['name']) ?></a>
              <?php if ($r['project'] !== ''): ?>
                <span class="text-muted small d-block">project: <span class="mono"><?= e($r['project']) ?></span></span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($r['driver']) ?></td>
            <td class="small"><?= e($r['scope']) ?></td>
            <td class="small"><?= (int) $r['container_count'] ?></td>
            <td class="small text-muted"><?= e($r['created_at']) ?></td>
            <td>
              <?php if ($r['builtin']): ?>
                <span class="badge badge-stopped">built-in</span>
              <?php elseif ($r['managed']): ?>
                <span class="badge badge-stopped">dikelola app</span>
              <?php elseif ($r['in_use']): ?>
                <span class="badge badge-running">dipakai</span>
              <?php else: ?>
                <span class="badge badge-error">bebas</span>
              <?php endif; ?>
              <?php if ($r['internal']): ?><span class="badge badge-stopped ms-1">internal</span><?php endif; ?>
              <?php if ($r['attachable']): ?><span class="badge badge-deploying ms-1">attachable</span><?php endif; ?>
            </td>
            <td class="text-end">
              <a class="btn btn-outline-secondary btn-sm" href="/networks/<?= e(rawurlencode($r['name'])) ?><?= $__serverQuery ?>">Detail</a>
              <?php if ($r['can_delete']): ?>
                <form method="post" action="/networks/<?= e(rawurlencode($r['name'])) ?>/delete" class="d-inline"
                      onsubmit="return confirm('Hapus network <?= e($r['name']) ?>? Pastikan tidak dipakai container lain.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="server" value="<?= e($__serverId) ?>">
                  <button class="btn btn-outline-danger btn-sm" title="Hapus network">✕</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <?php if ($canManage ?? false): ?>
    <div class="card">
      <div class="card-header"><h2 class="h6 mb-0">Buat Network</h2></div>
      <div class="card-body">
        <?php if ($__serverName !== ''): ?>
          <p class="text-muted small mb-2">Network baru dibuat di server <strong><?= e($__serverName) ?></strong>.</p>
        <?php endif; ?>
        <form method="post" action="/networks/create" class="vstack gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="server" value="<?= e($__serverId) ?>">
          <div>
            <label class="form-label small fw-semibold">Nama</label>
            <input type="text" name="name" class="form-control form-control-sm mono" placeholder="mis. shared-app" required>
          </div>
          <div>
            <label class="form-label small fw-semibold">Driver</label>
            <select name="driver" class="form-select form-select-sm">
              <option value="bridge">bridge</option>
              <option value="overlay">overlay (swarm)</option>
              <option value="macvlan">macvlan</option>
            </select>
          </div>
          <div>
            <label class="form-label small fw-semibold">Subnet (CIDR, opsional)</label>
            <input type="text" name="subnet" class="form-control form-control-sm mono" placeholder="mis. 172.20.0.0/16">
          </div>
          <div class="row g-2">
            <div class="col">
              <label class="form-label small fw-semibold">Gateway (opsional)</label>
              <input type="text" name="gateway" class="form-control form-control-sm mono" placeholder="172.20.0.1">
            </div>
            <div class="col">
              <label class="form-label small fw-semibold">IP Range (opsional)</label>
              <input type="text" name="ip_range" class="form-control form-control-sm mono" placeholder="172.20.0.0/24">
            </div>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="attachable" value="1" id="net-attachable" checked>
            <label class="form-check-label small" for="net-attachable">
              <strong>Attachable</strong> — izinkan container lain / app lain ikut bergabung (untuk shared network lintas-app)
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="internal" value="1" id="net-internal">
            <label class="form-check-label small" for="net-internal">Internal — tanpa akses internet keluar</label>
          </div>
          <button class="btn btn-primary btn-sm">Buat Network</button>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><h2 class="h6 mb-0">Shared Network</h2></div>
      <div class="card-body">
        <p class="text-muted small mb-2">
          Butuh network bersama antar app? Minta <strong>admin</strong> membuatnya di halaman ini,
          lalu pasang di tab <strong>Network</strong> pada detail app Anda (koneksi persisten).
        </p>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php include app_path() . '/view/partials/footer.php'; ?>
