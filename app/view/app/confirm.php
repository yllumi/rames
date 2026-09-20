<?php $pageTitle = 'Confirm App'; $active = 'apps'; ?>
<?php $pendingSource = ($pending['source'] ?? 'git') === 'compose' ? 'compose' : 'git'; ?>
<?php include app_path() . '/view/partials/header.php'; ?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Konfirmasi Deploy</h1>
    <?php if ($pendingSource === 'compose'): ?>
      <p class="text-muted small mb-0">
        Sumber: <strong>file compose</strong> (tanpa repo Git) · compose: <span class="mono"><?= e($pending['compose_file']) ?></span> · <?= e($pending['local_path']) ?>
      </p>
    <?php else: ?>
      <p class="text-muted small mb-0">Repo: <span class="mono"><?= e($pending['repo_url']) ?></span> · branch <span class="mono"><?= e($pending['branch']) ?></span> · <?= e($pending['local_path']) ?> · compose: <span class="mono"><?= e($pending['compose_file']) ?></span></p>
    <?php endif; ?>
  </div>
</div>

<form method="post" action="/apps/create/confirm" id="deploy-confirm-form">
  <?= csrf_field() ?>

  <!-- Nama container (override container_name) — opsional -->
  <div class="card mb-3">
    <div class="card-body">
      <label class="form-label" for="container-prefix">Prefix nama container <span class="text-muted small">(opsional)</span></label>
      <input type="text" class="form-control form-control-sm mono" id="container-prefix" name="container_prefix"
             maxlength="<?= e((string) \app\library\Deploy\ContainerNames::MAX_PREFIX_LENGTH) ?>"
             placeholder="kosong = <?= e($pending['name'] . '_<service>_1') ?>" style="max-width:320px;">
      <div class="form-text">
        Bila diisi, setiap service memakai nama container <span class="mono">{prefix}-{service}</span>
        (mis. <span class="mono"><?= e($pending['name'] . '-web') ?></span>) — bukan <span class="mono">&lt;app&gt;_&lt;service&gt;_1</span>.
        Nama container <strong>unik se-host</strong>: dicek ke container lain saat deploy, dan tidak bisa dipakai
        service yang memakai replica (<span class="mono">deploy.replicas</span>).
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th>Service</th>
          <th>Container Port</th>
          <th>Host Port (edit)</th>
          <th>Di-proxy ke domain</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending['services'] as $svcName => $svc): ?>
        <?php $svcPorts = array_values((array) ($svc['ports'] ?? [])); ?>
        <?php if ($svcPorts === []): ?>
        <tr>
          <td>
            <strong class="mono"><?= e($svcName) ?></strong>
            <span class="text-muted small">(internal)</span>
          </td>
          <td class="mono text-muted">&mdash;</td>
          <td><span class="text-muted">&mdash;</span></td>
          <td><span class="text-muted">&mdash;</span></td>
        </tr>
        <?php else: ?>
        <?php foreach ($svcPorts as $i => $p): ?>
        <?php
          $cp = (string) ($p['container'] ?? '');
          $radioId = 'primary-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $svcName . '-' . $cp);
          $isChecked = ($pending['primary_service'] ?? '') === $svcName
              && (int) ($pending['primary_port'] ?? 0) === (int) $cp;
        ?>
        <tr>
          <td>
            <?php if ($i === 0): ?>
              <strong class="mono"><?= e($svcName) ?></strong>
            <?php else: ?>
              <span class="text-muted small ms-2">&crarr; port lain</span>
            <?php endif; ?>
          </td>
          <td class="mono text-muted"><?= e($cp !== '' ? $cp : '-') ?></td>
          <td>
            <input type="number" class="form-control form-control-sm port-input"
                   name="services[<?= e($svcName) ?>][ports][<?= e($cp !== '' ? $cp : (string) $i) ?>][host_port]"
                   value="<?= e($p['host'] ?? '') ?>" min="1" max="65535" required style="max-width:160px;">
          </td>
          <td>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="primary" value="<?= e($svcName . ':' . $cp) ?>" id="<?= e($radioId) ?>"
                     <?= $isChecked ? 'checked' : '' ?>>
              <label class="form-check-label" for="<?= e($radioId) ?>">Trafik domain</label>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <p class="text-muted small">
    Port yang berkonflik sudah otomatis diganti dari rentang <code><?= e(config('deploy.port_range.start')) ?>–<?= e(config('deploy.port_range.end')) ?></code>. Sesuaikan bila perlu.
    Pilih <strong>satu</strong> port yang menerima trafik domain app (subdomain / custom domain) — port lain
    tetap dipublikasikan ke host port-nya masing-masing dan bisa diakses langsung via <span class="mono">http://&lt;host&gt;:&lt;port&gt;</span>.
  </p>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary" id="deploy-btn">
      <span class="spinner-border spinner-border-sm d-none" id="deploy-btn-spinner" role="status" aria-hidden="true"></span>
      Deploy App
    </button>
    <a class="btn btn-outline-secondary" href="/apps/create<?= $pendingSource === 'compose' ? '?mode=compose' : '' ?>">Kembali</a>
  </div>
</form>

<div id="deploy-error" class="alert alert-danger d-none mt-3" role="alert"></div>

<script>
(function () {
  var form = document.getElementById('deploy-confirm-form');
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
        // menuju detail app — halaman itu otomatis mem-poll progres build
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
