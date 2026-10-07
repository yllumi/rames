<?php
// ---------------------------------------------------------------------------
// Partial indeks (urutan render — tiap partial berbagi scope dengan berkas ini):
//  01. detail-setup.php           derivasi variabel: $pageTitle/$status/$isBusy/$role/$abilities/canX, $isCompose, custom domain+SSL, rute proxy, overlay container live, $appPorts, $hasHostPort, $logContainer, $breadcrumbs
//  02. detail-tabs-nav.php        bar tab navigasi section app (<ul id="appTabs">)
//  03. detail-tab-info.php        Tab Info: dl app-info + kartu SSH Deploy Key
//  04. detail-tab-domain.php      Tab Domain & SSL: custom domain + SSL + Rute Proxy Tambahan
//  05. detail-tab-env.php         Tab Environment: tabel env + <script> tambah baris & reveal nilai
//  06. detail-tab-limits.php      Tab Sumber Daya: batas CPU/memori per service (konteks $resourceLimits)
//  07. detail-tab-network.php     Tab Network: external network lintas-app
//  08. detail-tab-db.php          Tab Database (hanya bila $dbContainers tidak kosong)
//  09. detail-tab-containers.php  Tab Container: tabel container + kartu Nama container
//  10. detail-tab-deploy.php      Tab Deployment: riwayat + rollback + tombol lihat semua versi
//  11. detail-tab-compose.php     Tab Compose (app mode compose, hanya bila $isCompose && $canCompose)
//  12. detail-tab-access.php      Tab Akses: kepemilikan & sharing (hanya bila $canShare atau ada members)
//  13. detail-tab-delete.php      Tab Hapus App (Danger Zone; pane hanya bila $canDelete)
//  14. detail-modal-delete.php    Modal konfirmasi delete + pilihan volume yang dipertahankan
//  15. detail-modal-guide.php     Modal panduan (HTML hasil sanitasi Markdown)
//  16. detail-scripts-inline.php  Script inline halaman: copyDetailKey() + tombol Rebuild & monitor progres deploy
//  17. detail-modal-terminal.php  Modal terminal interaktif (xterm.js + SSE)
//  18. detail-modal-run.php       Modal one-shot run command (non-interaktif)
//  19. detail-modal-files.php     Modal file manager container (bila $canFiles & ada container)
//  20. detail-modal-log.php       Modal log container + script pemuatnya (bila $canLogs & ada container)
//  21. detail-scripts.php         Penutup: xterm.css, script aktifkan tab dari hash, script xterm/app-terminal/app-files
// ---------------------------------------------------------------------------
// PERINGATAN PEMELIHARAAN — keluaran view ini byte-exact; jangan diubah tanpa verifikasi render:
//  * Partial di app/view/app/partials/ adalah hasil potong-tempel byte-exact dari berkas ini.
//    Jangan merapikan / meng-indent ulang isinya sebelum memverifikasi output render
//    (harness + diff) tetap identik byte-per-byte.
//  * Tiap baris include diakhiri tag penutup PHP dan PHP menelan SATU newline sesudahnya;
//    menambah/menghapus baris kosong di sekitar baris include akan menggeser whitespace keluaran.
//  * partials/detail-setup.php WAJIB tetap include PERTAMA — semua partial lain memakai
//    variabel turunannya ($abilities / $canX / $containers / $appPorts / $logContainer / ...).
// ---------------------------------------------------------------------------
// Partial: setup variabel & konteks halaman (wajib jalan sebelum partial lain)
include app_path() . '/view/app/partials/detail-setup.php'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <h1 class="h3 mb-0 mono"><?= e($app['name']) ?></h1>
    <span class="badge badge-<?= e($status) ?>" id="app-status"><?= e($status) ?></span>
    <?php if ($role !== null): ?>
      <span class="badge text-bg-<?= $isOwner || $role === 'admin' ? 'primary' : 'secondary' ?>"
            title="Hak akses Anda pada app ini"><?= e(app_role_label($role)) ?></span>
    <?php endif; ?>
  </div>
  <?php if ((($guideHtml ?? '') !== '') || ($logContainer !== null && $logContainer !== '')): ?>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <?php if (($guideHtml ?? '') !== ''): ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#app-guide-modal">📖 Panduan</button>
    <?php endif; ?>
    <?php if ($logContainer !== null && $logContainer !== ''): ?>
      <button type="button" class="btn btn-outline-secondary btn-sm log-btn"
              data-container="<?= e($logContainer) ?>" data-bs-toggle="modal" data-bs-target="#log-modal"
              title="Lihat log container app (docker logs)">⧉ Log</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (!$canOperate): ?>
  <div class="alert alert-info py-2 small" role="alert">
    Anda punya akses <strong>read-only</strong> (Viewer) ke app ini — hanya bisa melihat status,
    riwayat deploy, dan konfigurasi. Hubungi pemilik app untuk mengubah akses.
  </div>
<?php endif; ?>

<!-- Panel progres deploy/rebuild. Muncul saat app busy (mis. usai me-refresh),
     atau langsung saat tombol Rebuild ditekan via AJAX. -->
<div id="deploy-progress" class="card mb-4<?= $isBusy ? '' : ' d-none' ?>" data-busy="<?= $isBusy ? '1' : '0' ?>">
  <div class="card-body">
    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
      <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
      <strong id="deploy-stage"><?= $isBusy ? e($app['stage'] ?? 'deploying') : '...' ?></strong>
      <span id="deploy-message" class="text-muted small"><?= $isBusy ? e($app['message'] ?? '') : '' ?></span>
    </div>
    <div class="progress" style="height:8px;">
      <div id="deploy-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar"
           style="width:5%" aria-valuenow="5" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
    <div id="deploy-error" class="alert alert-danger py-2 small mt-3 mb-0 d-none" role="alert"></div>
    <p class="text-muted small mt-2 mb-0">Proses berjalan di latar belakang — Anda boleh me-refresh halaman atau pindah halaman; build tetap berjalan dan progres dilanjutkan otomatis.</p>
  </div>
</div>

<?php if ($status === 'error'): ?>
  <div class="alert alert-danger" role="alert"><strong>Error:</strong> <?= e($app['error'] ?? $app['message'] ?? '') ?></div>
<?php endif; ?>

<?php if (!$isBusy && $canOperate): ?>
<div class="d-flex flex-wrap gap-2 mb-4" id="app-actions">
  <form method="post" action="/apps/<?= e($app['id']) ?>/rebuild" id="rebuild-form"><?= csrf_field() ?><button id="rebuild-btn" class="btn btn-outline-secondary btn-sm"<?= $isCompose ? ' title="Ciptakan ulang container dari file compose & image lokal (tanpa build)"' : '' ?>>↻ <?= $isCompose ? 'Deploy Ulang' : 'Rebuild' ?></button></form>

  <?php if ($status === 'running'): ?>
    <form method="post" action="/apps/<?= e($app['id']) ?>/stop"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">■ Stop</button></form>
  <?php elseif ($status === 'stopped'): ?>
    <form method="post" action="/apps/<?= e($app['id']) ?>/start"><?= csrf_field() ?><button class="btn btn-success btn-sm">▶ Start</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// Partial: bar tab navigasi section app (<ul id="appTabs">)
include app_path() . '/view/app/partials/detail-tabs-nav.php'; ?>

<div class="tab-content" id="appTabContent">

<?php
// Partial: Tab Info: dl app-info + kartu SSH Deploy Key
include app_path() . '/view/app/partials/detail-tab-info.php'; ?>

<?php
// Partial: Tab Domain & SSL: custom domain + SSL + Rute Proxy Tambahan
include app_path() . '/view/app/partials/detail-tab-domain.php'; ?>

<?php
// Partial: Tab Environment: tabel env + <script> tambah baris & reveal nilai
include app_path() . '/view/app/partials/detail-tab-env.php'; ?>

<?php
// Partial: Tab Sumber Daya: batas CPU/memori per service (konteks $resourceLimits)
include app_path() . '/view/app/partials/detail-tab-limits.php'; ?>

<?php
// Partial: Tab Network: external network lintas-app
include app_path() . '/view/app/partials/detail-tab-network.php'; ?>
<?php
// Partial: Tab Database (hanya bila $dbContainers tidak kosong)
include app_path() . '/view/app/partials/detail-tab-db.php'; ?>
<?php
// Partial: Tab Container: tabel container + kartu Nama container
include app_path() . '/view/app/partials/detail-tab-containers.php'; ?>

<?php
// Partial: Tab Deployment: riwayat + rollback + tombol lihat semua versi
include app_path() . '/view/app/partials/detail-tab-deploy.php'; ?>

<?php
// Partial: Tab Compose (app mode compose, hanya bila $isCompose && $canCompose)
include app_path() . '/view/app/partials/detail-tab-compose.php'; ?>

<?php
// Partial: Tab Akses: kepemilikan & sharing (hanya bila $canShare atau ada members)
include app_path() . '/view/app/partials/detail-tab-access.php'; ?>

<?php
// Partial: Tab Hapus App (Danger Zone; pane hanya bila $canDelete)
include app_path() . '/view/app/partials/detail-tab-delete.php'; ?>
</div>

<?php
// Partial: Modal konfirmasi delete + pilihan volume yang dipertahankan
include app_path() . '/view/app/partials/detail-modal-delete.php'; ?>

<?php
// Partial: Modal panduan (HTML hasil sanitasi Markdown)
include app_path() . '/view/app/partials/detail-modal-guide.php'; ?>

<?php
// Partial: Script inline halaman: copyDetailKey() + tombol Rebuild & monitor progres deploy
include app_path() . '/view/app/partials/detail-scripts-inline.php'; ?>

<?php
// Partial: Modal terminal interaktif (xterm.js + SSE)
include app_path() . '/view/app/partials/detail-modal-terminal.php'; ?>

<?php
// Partial: Modal one-shot run command (non-interaktif)
include app_path() . '/view/app/partials/detail-modal-run.php'; ?>

<?php
// Partial: Modal file manager container (bila $canFiles & ada container)
include app_path() . '/view/app/partials/detail-modal-files.php'; ?>

<?php
// Partial: Modal log container + <script> pemuatnya (bila $canLogs & ada container)
include app_path() . '/view/app/partials/detail-modal-log.php'; ?>

<?php
// Partial: Penutup: xterm.css, script aktifkan tab dari hash, script xterm/app-terminal/app-files
include app_path() . '/view/app/partials/detail-scripts.php'; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>

