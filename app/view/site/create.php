<?php $pageTitle = 'Create Site'; $active = 'sites'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Create Site</h1>
    <p class="text-muted mb-0">Clone repo → deteksi port → konfirmasi → deploy.</p>
  </div>
</div>

<div class="card form-card">
  <div class="card-body p-4">
    <p class="text-muted small">Sistem akan clone repo, memeriksa <code>docker-compose.yml</code>, mendeteksi port, lalu menampilkan konfirmasi sebelum deploy.</p>
    <form method="post" action="/sites/create">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="name">Nama site (slug)</label>
        <input type="text" class="form-control" id="name" name="name" placeholder="myapp" pattern="[a-z0-9][a-z0-9-]*[a-z0-9]|[a-z0-9]" required>
        <div class="form-text">Hanya huruf kecil a-z, angka, dan strip (-). Dipakai sebagai subdomain &amp; nama direktori.</div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="repo_url">URL repo Git</label>
        <input type="url" class="form-control" id="repo_url" name="repo_url" placeholder="https://github.com/user/myapp.git" required>
      </div>

      <div class="mb-3">
        <label class="form-label" for="branch">Branch</label>
        <input type="text" class="form-control" id="branch" name="branch" value="main" required>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Analisis Repo</button>
        <a class="btn btn-outline-secondary" href="/sites">Batal</a>
      </div>
    </form>
  </div>
</div>

<?php include app_path() . '/view/partials/footer.php'; ?>
