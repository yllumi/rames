<?php $pageTitle = 'Deploy dari Template'; $active = 'apps'; ?>
<?php
// Form deploy satu langkah dari template (SPECS.md §7.2b).
// Port/primary/prefix container TIDAK ditanyakan: host port dicari yang bebas,
// primary diambil dari template, prefix nama container = nama app.
$formEnv = $form_env ?? [];
$formError = $form_error ?? null;
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">
      <?php if (($template['icon'] ?? '') !== ''): ?><span class="me-1"><?= e($template['icon']) ?></span><?php endif; ?>
      <?= e($template['title']) ?>
    </h1>
    <p class="text-muted small mb-0">
      <a href="/apps/create?mode=template">&larr; Semua template</a>
      &middot; image <span class="mono"><?= e($template['image'] !== '' ? $template['image'] : '-') ?></span>
      <?php if (($template['primary']['service'] ?? '') !== ''): ?>
      &middot; domain &rarr; <span class="mono"><?= e($template['primary']['service'] . ':' . $template['primary']['port']) ?></span>
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if (($template['description'] ?? '') !== ''): ?>
<p class="text-muted small"><?= e($template['description']) ?></p>
<?php endif; ?>

<?php if ($formError): ?>
<div class="alert alert-warning" role="alert">
  <strong>Deploy tidak dijalankan:</strong> <?= e($formError) ?>
</div>
<?php endif; ?>

<form method="post" action="/apps/create/template/<?= e($template['slug']) ?>" id="template-deploy-form">
  <?= csrf_field() ?>

  <div class="card mb-3 form-card">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label" for="template-app-name">Nama app (slug)</label>
        <input type="text" class="form-control" id="template-app-name" name="name" value="<?= e($form_name) ?>"
               placeholder="myapp" required style="max-width:360px;">
        <div class="form-text">
          Hanya huruf kecil a-z, angka, dan strip (-). Dipakai sebagai subdomain, nama project compose, nama direktori,
          dan prefix nama container (<span class="mono">{nama}-{service}</span>).
        </div>
      </div>

      <?php if (($template['ports'] ?? []) !== []): ?>
      <div class="alert alert-secondary small mb-0" role="alert">
        Port container template: <span class="mono"><?= e(implode(', ', array_map('strval', $template['ports']))) ?></span>.
        Host port dicari otomatis yang masih bebas (rentang <span class="mono"><?= e((string) config('deploy.port_range.start')) ?>–<?= e((string) config('deploy.port_range.end')) ?></span>) —
        setelah app jalan, port bisa dilihat di halaman detail app.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (($template['env'] ?? []) !== []): ?>
  <div class="card mb-3 form-card">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <h2 class="h6 mb-1">Variabel environment</h2>
          <p class="text-muted small mb-0">
            Nilai ditulis ke file env milik app (<span class="mono">database/env/&lt;nama app&gt;.env</span>, chmod 0600)
            dan di-inject ke seluruh service. Kosongkan untuk memakai nilai default / dibuat otomatis.
          </p>
        </div>
      </div>

      <?php foreach ($template['env'] as $spec): ?>
      <?php $rawPosted = $formEnv[$spec['key']] ?? ''; $posted = is_scalar($rawPosted) ? (string) $rawPosted : ''; ?>
      <div class="mb-3">
        <label class="form-label" for="env-<?= e($spec['key']) ?>">
          <?= e($spec['label']) ?>
          <span class="text-muted small mono"><?= e($spec['key']) ?></span>
          <?php if ($spec['required']): ?>
          <span class="badge text-bg-danger">wajib</span>
          <?php endif; ?>
        </label>
        <input type="<?= $spec['secret'] ? 'password' : 'text' ?>"
               class="form-control mono" id="env-<?= e($spec['key']) ?>"
               name="env[<?= e($spec['key']) ?>]"
               value="<?= e($posted) ?>"
               <?= $spec['secret'] ? 'autocomplete="new-password"' : '' ?>
               <?= $spec['required'] ? 'required' : '' ?>
               style="max-width:480px;">
        <div class="form-text">
          <?php if (($spec['help'] ?? '') !== ''): ?><?= e($spec['help']) ?><?php endif; ?>
          <?php if ($spec['generate']): ?>
          Kosongkan untuk <strong>dibuat otomatis</strong> (nilai acak) — nilainya bisa dilihat/diubah di tab Environment app.
          <?php elseif ($spec['default'] !== null): ?>
          Default: <span class="mono"><?= e((string) $spec['default']) ?></span>.
          <?php elseif ($spec['required']): ?>
          Wajib diisi (tidak ada nilai default).
          <?php else: ?>
          Opsional.
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="alert alert-secondary small" role="alert">
    Deploy dijalankan langsung (tanpa halaman konfirmasi port): host port diresolusi otomatis,
    prefix nama container = nama app, dan app dibuat sebagai app mode <strong>compose</strong> —
    sumbernya bisa diubah kapan saja lewat tab <strong>Compose</strong> di detail app, lalu <em>Deploy Ulang</em>.
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary" id="deploy-btn">
      <span class="spinner-border spinner-border-sm d-none" id="deploy-btn-spinner" role="status" aria-hidden="true"></span>
      Deploy
    </button>
    <a class="btn btn-outline-secondary" href="/apps/create?mode=template">Batal</a>
  </div>
</form>

<div id="deploy-error" class="alert alert-danger d-none mt-3" role="alert"></div>

<script>
(function () {
  var form = document.getElementById('template-deploy-form');
  var btn = document.getElementById('deploy-btn');
  var spinner = document.getElementById('deploy-btn-spinner');
  var errorBox = document.getElementById('deploy-error');

  if (!form) return;

  function showError(msg) {
    if (errorBox) {
      errorBox.textContent = msg;
      errorBox.classList.remove('d-none');
    }
    if (btn) btn.disabled = false;
    if (spinner) spinner.classList.add('d-none');
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (errorBox) errorBox.classList.add('d-none');
    if (btn) btn.disabled = true;
    if (spinner) spinner.classList.remove('d-none');

    fetch(form.action, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new FormData(form)
    }).then(function (r) {
      return r.json().then(function (d) {
        return { ok: r.ok, status: r.status, data: d };
      }).catch(function () {
        return { ok: r.ok, status: r.status, data: null };
      });
    }).then(function (res) {
      var d = res.data || {};
      if (res.ok && d.code === 0 && d.id) {
        window.location.href = '/apps/' + d.id;
        return;
      }
      var msg = d.error || d.msg;
      if (!msg) {
        msg = res.status === 419
          ? 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.'
          : 'Gagal memulai deploy. Silakan coba lagi.';
      }
      showError(msg);
      if (res.status === 419) setTimeout(function () { window.location.reload(); }, 1500);
    }).catch(function () {
      showError('Gagal terhubung ke server. Periksa koneksi lalu coba lagi.');
    });
  });
})();
</script>

<?php include app_path() . '/view/partials/footer.php'; ?>
