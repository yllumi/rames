<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · Rames</title>
<link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="/css/app.css">
</head>
<body class="auth-body">
<div class="auth-glow"></div>
<div class="auth-card">
  <div class="card-body p-4 p-md-5">
    <div class="auth-brand mb-2">
      <img src="/logo-small.png" alt="rames" width="160" height="160" class="rounded-3">
      <div>rames</div>
    </div>
    <p class="sub">Deployment Control Panel · <?= e(config('deploy.app_domain')) ?></p>
    <?php $__flash = flash_pull(); ?>
    <?php if ($__flash): ?>
      <?php
        $__type = $__flash['type'] ?? 'info';
        $__alertClass = $__type === 'error' ? 'alert-danger' : ($__type === 'success' ? 'alert-success' : 'alert-info');
      ?>
      <div class="alert <?= e($__alertClass) ?>" role="alert"><?= e($__flash['message'] ?? '') ?></div>
    <?php endif; ?>
    <form method="post" action="/login">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input type="text" class="form-control" id="username" name="username" required autofocus autocomplete="username" placeholder="admin">
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
      </div>
      <button type="submit" class="btn btn-primary w-100">Masuk</button>
    </form>
  </div>
</div>
<script src="/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
