<?php $pageTitle = 'Config'; $active = 'nginx'; ?>
<?php
$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Config', 'href' => null],
];

// ---------------------------------------------------------------------------
// Halaman Config = tiga tab: **Update Rames** (self-update dashboard),
// **Nginx** (status + reload nginx host), **Template** (katalog template app +
// tarik image). Shell halaman (page-head + nav tab + include partial tab +
// modal konfirmasi + skrip) ada di sini; isi tiap tab di `partials/tab-*.php`.
//
// Tab awal datang dari controller (`$tab`, dari query `?tab=` yang sudah
// divalidasi whitelist di sana) supaya refresh/berbagi tautan mendarat di tab
// yang sama; saat tab diklik, skrip di bawah memperbarui URL tanpa reload.
// View TIDAK membaca request sendiri; fallback `update` menjaga render tanpa
// controller (smoke) tetap utuh tanpa menduplikasi daftar tab.
// ---------------------------------------------------------------------------
$tab = is_string($tab ?? null) && in_array($tab, (array) ($tabs ?? []), true) ? $tab : 'update';

// $templates/$pull = kontrak NginxController::index(); normalisasi defensif agar
// partial tab tidak pernah mengakses array pada null.
$templates = is_array($templates ?? null) ? $templates : [];
$pull = is_array($pull ?? null) ? $pull : null;
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Config</h1>
    <p class="text-muted mb-0">
      Konfigurasi host &amp; katalog app dalam tiga tab:
      <strong>Update Rames</strong> — self-update dashboard,
      <strong>Nginx</strong> — status &amp; reload <strong>Nginx host</strong> (berlaku global untuk semua
      app; reload dijalankan lewat helper container via Docker socket — validasi
      <span class="mono">nginx -t</span> lalu reload tanpa downtime), dan
      <strong>Template</strong> — katalog template app siap-pakai beserta tombol tarik image
      (<span class="mono">docker pull</span>).
    </p>
  </div>
</div>

<ul class="nav nav-tabs mb-3" id="config-tabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link<?= $tab === 'update' ? ' active' : '' ?>" id="config-tab-update" data-bs-toggle="tab"
            data-bs-target="#pane-update" data-tab="update" type="button" role="tab"
            aria-controls="pane-update" aria-selected="<?= $tab === 'update' ? 'true' : 'false' ?>">Update Rames<?php if (!empty($update['check']['update_available'])): ?> <span class="badge text-bg-warning">ada pembaruan</span><?php endif; ?></button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link<?= $tab === 'nginx' ? ' active' : '' ?>" id="config-tab-nginx" data-bs-toggle="tab"
            data-bs-target="#pane-nginx" data-tab="nginx" type="button" role="tab"
            aria-controls="pane-nginx" aria-selected="<?= $tab === 'nginx' ? 'true' : 'false' ?>">Nginx</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link<?= $tab === 'template' ? ' active' : '' ?>" id="config-tab-template" data-bs-toggle="tab"
            data-bs-target="#pane-template" data-tab="template" type="button" role="tab"
            aria-controls="pane-template" aria-selected="<?= $tab === 'template' ? 'true' : 'false' ?>">Template<?php if ($templates !== []): ?> <span class="badge text-bg-secondary"><?= count($templates) ?></span><?php endif; ?></button>
  </li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade<?= $tab === 'update' ? ' show active' : '' ?>" id="pane-update" role="tabpanel"
       aria-labelledby="config-tab-update" tabindex="0">
    <?php include app_path() . '/view/nginx/partials/tab-update.php'; ?>
  </div>
  <div class="tab-pane fade<?= $tab === 'nginx' ? ' show active' : '' ?>" id="pane-nginx" role="tabpanel"
       aria-labelledby="config-tab-nginx" tabindex="0">
    <?php include app_path() . '/view/nginx/partials/tab-nginx.php'; ?>
  </div>
  <div class="tab-pane fade<?= $tab === 'template' ? ' show active' : '' ?>" id="pane-template" role="tabpanel"
       aria-labelledby="config-tab-template" tabindex="0">
    <?php include app_path() . '/view/nginx/partials/tab-templates.php'; ?>
  </div>
</div>

<?php
// Modal konfirmasi update/rollback WAJIB di luar `.tab-pane`: pane non-aktif
// ber-`display:none`, jadi modal di dalamnya tidak akan pernah tampil.
// Variabel turunan ($check/$pf/$rollbackSha/$shortSha) disiapkan oleh
// `partials/tab-update.php` — partial di-include dengan scope yang sama
// (pola `app/view/app/partials/detail-setup.php`).
?>
<?php if (is_admin() && !empty($update) && empty($update['failed'])): ?>
<!-- Konfirmasi update/rollback memakai modal (bukan window.confirm), mengikuti
     pola modal hapus app di halaman detail. Tombolnya JS (bukan submit form)
     karena aksi dijalankan lewat AJAX + polling — form POST akan menggantung
     saat container dashboard di-recreate. -->
<div class="modal fade" id="update-confirm-modal" tabindex="-1" aria-labelledby="update-confirm-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="update-confirm-label">Update dashboard</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Dashboard akan di-<span class="mono">pull</span> ulang, image dibangun ulang, lalu container
          <strong>di-recreate</strong> — akses dashboard terputus beberapa saat dan sesi terminal/app
          yang sedang berjalan ikut terhenti.
        </p>
        <ul class="small text-muted mb-3">
          <li>Versi sekarang: <span class="mono"><?= e($shortSha($check['local_sha'] ?? null)) ?></span><?= ($update['branch'] ?? '') !== '' ? ' (' . e((string) $update['branch']) . ')' : '' ?></li>
          <?php if (!empty($check['remote_sha'])): ?>
          <li>Versi tujuan: <span class="mono"><?= e($shortSha($check['remote_sha'])) ?></span></li>
          <?php endif; ?>
        </ul>
        <div class="alert alert-warning py-2 small mb-0">
          Bila versi baru tidak sehat dalam <?= (int) ($pf['context']['health_timeout'] ?? 0) > 0 ? (int) $pf['context']['health_timeout'] . ' detik' : 'batas waktu' ?>,
          kode dikembalikan otomatis ke <span class="mono"><?= e($shortSha($check['local_sha'] ?? null)) ?></span>.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary btn-sm" id="update-confirm-btn">Ya, update sekarang</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="rollback-confirm-modal" tabindex="-1" aria-labelledby="rollback-confirm-label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="rollback-confirm-label">Rollback dashboard</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Kode dikembalikan ke commit <strong>sebelum update terakhir</strong>, lalu container
          <strong>di-recreate</strong> — akses dashboard terputus beberapa saat.
        </p>
        <ul class="small text-muted mb-3">
          <li>Versi sekarang: <span class="mono"><?= e($shortSha($check['local_sha'] ?? null)) ?></span></li>
          <li>Kembali ke: <span class="mono"><?= e($shortSha($rollbackSha)) ?></span></li>
        </ul>
        <div class="alert alert-secondary py-2 small mb-0">
          Perubahan yang sudah dilakukan setelah versi itu (kalau ada) tidak ikut kembali —
          rollback hanya mengembalikan kode pada commit tersebut.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="rollback-confirm-btn">Ya, rollback</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="/js/template-pull.js?v=1"></script>
<script>
  // Sinkronkan tab aktif dengan URL (`?tab=`) tanpa reload/permintaan server,
  // supaya refresh & tautan yang dibagikan mendarat di tab yang sama. Tab awal
  // sendiri sudah dirender server-side dari `?tab=`.
  (function () {
    'use strict';

    var tabs = document.getElementById('config-tabs');
    if (!tabs || !window.history || !window.history.replaceState) return;

    tabs.addEventListener('shown.bs.tab', function (event) {
      var name = event.target ? event.target.getAttribute('data-tab') : '';
      // Validitas diturunkan dari pane yang benar-benar dirender server — tanpa
      // menduplikasi daftar tab milik controller.
      if (!name || !document.getElementById('pane-' + name)) return;
      var url = new URL(window.location.href);
      url.searchParams.set('tab', name);
      try {
        window.history.replaceState(null, '', url.pathname + url.search + url.hash);
      } catch (e) {}
    });
  })();
</script>

<?php include app_path() . '/view/partials/footer.php'; ?>
