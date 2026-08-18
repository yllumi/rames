<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Dashboard') ?> · Rames</title>
<link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/css/app.css">
</head>
<body class="d-flex flex-column min-vh-100">
<header class="topbar sticky-top">
  <nav class="navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand brand" href="/sites"><img src="/logo-small.png" alt="rames" width="28" height="28" class="rounded-2 flex-shrink-0">rames</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
              aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
          <li class="nav-item">
            <a class="nav-link <?= ($active ?? '') === 'sites' ? 'active' : '' ?>" href="/sites">Sites</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= ($active ?? '') === 'ssl' ? 'active' : '' ?>" href="/ssl">SSL</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= ($active ?? '') === 'volumes' ? 'active' : '' ?>" href="/volumes">Volumes</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= ($active ?? '') === 'nginx' ? 'active' : '' ?>" href="/nginx">Nginx</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= ($active ?? '') === 'users' ? 'active' : '' ?>" href="/users">Users</a>
          </li>
          <?php $__u = current_user(); ?>
          <li class="nav-item user ms-lg-3">
            <span class="avatar"><?= e(strtoupper(substr($__u['username'] ?? '?', 0, 1))) ?></span>
            <span class="fw-semibold"><?= e($__u['username'] ?? '') ?></span>
          </li>
          <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
            <a class="btn btn-outline-secondary btn-sm" href="/logout">Logout</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>
</header>
<main class="container flex-grow-1 py-4">
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
