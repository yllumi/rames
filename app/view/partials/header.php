<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Dashboard') ?> · Rames</title>
<link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/css/app.css">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="d-flex min-vh-100">
<script>
  // Terapkan preferensi lipat sidebar sedini mungkin untuk menghindari flash.
  (function () {
    try {
      if (localStorage.getItem('rames.sidebar') === 'collapsed') {
        document.body.classList.add('sidebar-collapsed');
      }
    } catch (e) {}
  })();
</script>
<?php
// Ikon line-art inline (tanpa dependensi). Duduk di dalam .nav-ico yang mewarisi warna link.
$__navIcons = [
    'apps' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg>',
    'ssl' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>',
    'volumes' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/></svg>',
    'networks' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>',
    'backups' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
    'monitor' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
    'database' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
    'config' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
    'users' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
];
// Ikon burger dipakai tombol lipat (desktop) & buka drawer (mobile).
$__burger = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>';
?>
<aside id="appSidebar" class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" aria-label="Navigasi utama">
  <div class="app-sidebar-inner d-flex flex-column h-100">
    <div class="app-sidebar-head">
      <button type="button" class="sidebar-toggle d-none d-lg-inline-flex" data-sidebar-toggle aria-controls="appSidebar" aria-expanded="true" aria-label="Lipat navigasi"><?= $__burger ?></button>
      <a class="navbar-brand brand" href="/apps"><img src="/logo-small.png" alt="rames" width="28" height="28" class="rounded-2 flex-shrink-0"><span class="brand-text">rames</span></a>
      <button type="button" class="btn-close ms-auto d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Tutup navigasi"></button>
    </div>
    <nav class="sidebar-nav" aria-label="Menu utama">
      <ul class="nav flex-column">
        <li class="nav-item">
          <a class="nav-link <?= ($active ?? '') === 'apps' ? 'active' : '' ?>" href="/apps" data-label="Apps" aria-label="Apps"<?= ($active ?? '') === 'apps' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['apps'] ?></span><span class="nav-label">Apps</span></a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= ($active ?? '') === 'ssl' ? 'active' : '' ?>" href="/ssl" data-label="SSL" aria-label="SSL"<?= ($active ?? '') === 'ssl' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['ssl'] ?></span><span class="nav-label">SSL</span></a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= ($active ?? '') === 'volumes' ? 'active' : '' ?>" href="/volumes" data-label="Volumes" aria-label="Volumes"<?= ($active ?? '') === 'volumes' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['volumes'] ?></span><span class="nav-label">Volumes</span></a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= ($active ?? '') === 'networks' ? 'active' : '' ?>" href="/networks" data-label="Networks" aria-label="Networks"<?= ($active ?? '') === 'networks' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['networks'] ?></span><span class="nav-label">Networks</span></a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= ($active ?? '') === 'backups' ? 'active' : '' ?>" href="/backups" data-label="Backup" aria-label="Backup"<?= ($active ?? '') === 'backups' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['backups'] ?></span><span class="nav-label">Backup</span></a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= ($active ?? '') === 'monitor' ? 'active' : '' ?>" href="/monitor" data-label="Monitor" aria-label="Monitor"<?= ($active ?? '') === 'monitor' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['monitor'] ?></span><span class="nav-label">Monitor</span></a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= ($active ?? '') === 'database' ? 'active' : '' ?>" href="/database" data-label="Database" aria-label="Database"<?= ($active ?? '') === 'database' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['database'] ?></span><span class="nav-label">Database</span></a>
        </li>
        <li class="nav-item">
          <?php $__update = update_badge(); ?>
          <a class="nav-link <?= ($active ?? '') === 'nginx' ? 'active' : '' ?>" href="/nginx" data-label="Config" aria-label="Config"<?= ($active ?? '') === 'nginx' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['config'] ?></span><span class="nav-label">Config</span><?php if ($__update['available']): ?><span class="badge text-bg-warning ms-1" title="Ada pembaruan dashboard — buka halaman Config">update</span><?php endif; ?></a>
        </li>
        <?php if (is_admin()): ?>
        <li class="nav-item">
          <a class="nav-link <?= ($active ?? '') === 'users' ? 'active' : '' ?>" href="/users" data-label="Users" aria-label="Users"<?= ($active ?? '') === 'users' ? ' aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $__navIcons['users'] ?></span><span class="nav-label">Users</span></a>
        </li>
        <?php endif; ?>
      </ul>
    </nav>
    <?php $__u = current_user(); ?>
    <div class="sidebar-user">
      <div class="d-flex align-items-center gap-2">
        <span class="avatar"><?= e(strtoupper(substr($__u['username'] ?? '?', 0, 1))) ?></span>
        <span class="fw-semibold text-truncate sidebar-user-name"><?= e($__u['username'] ?? '') ?></span>
        <?php if (is_admin($__u)): ?>
          <span class="badge text-bg-primary sidebar-user-badge" title="Admin: kelola user & lihat semua app">admin</span>
        <?php endif; ?>
        <a class="text-danger ms-auto btn-sm sidebar-logout" href="/logout">logout</a>
      </div>
    </div>
  </aside>
  </div>
<div class="app-main d-flex flex-column flex-grow-1 min-vh-100">
  <header class="topbar sticky-top d-lg-none">
    <nav class="navbar">
      <div class="container-fluid px-3">
        <div class="d-flex align-items-center gap-2">
          <button class="navbar-toggler sidebar-burger" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Buka navigasi"><?= $__burger ?></button>
          <a class="navbar-brand brand" href="/apps"><img src="/logo-small.png" alt="rames" width="24" height="24" class="rounded-2 flex-shrink-0"><span class="brand-text">rames</span></a>
        </div>
      </div>
    </nav>
  </header>
  <main class="container-fluid px-3 px-lg-4 flex-grow-1 py-4">
<?php if (!empty($breadcrumbs)) { include app_path() . '/view/partials/breadcrumb.php'; } ?>
<?php $__flash = flash_pull(); ?>
<?php if ($__flash): ?>
  <?php
    $__type = $__flash['type'] ?? 'info';
    $__alertClass = $__type === 'error' ? 'alert-danger' : ($__type === 'success' ? 'alert-success' : 'alert-info');
  ?>
  <div class="alert <?= e($__alertClass) ?> alert-dismissible fade show" role="alert">
    <?= e($__flash['message'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
