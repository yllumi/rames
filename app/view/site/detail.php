<?php
$pageTitle = $site['name'];
$active = 'sites';
$status = $site['status'] ?? 'unknown';
$isBusy = in_array($status, ['deploying'], true);

// custom domain + status SSL-nya
$customDomain = (string) ($site['custom_domain'] ?? '');
$customSslStatus = (string) ($site['custom_ssl_status'] ?? 'disabled');
$customSslExpiresAt = $site['custom_ssl_expires_at'] ?? null;
$customSslError = $site['custom_ssl_error'] ?? null;
$customSslActive = $customSslStatus === 'active';
$customSslPending = $customSslStatus === 'pending';
$customSslFailed = $customSslStatus === 'failed';
$sslSupported = \app\library\SSL\SslIssuer::isPublicDomain((string) config('deploy.app_domain', ''));

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

<!-- Panel progres deploy/rebuild. Muncul saat site busy (mis. usai me-refresh),
     atau langsung saat tombol Rebuild ditekan via AJAX. -->
<div id="deploy-progress" class="card mb-4<?= $isBusy ? '' : ' d-none' ?>" data-busy="<?= $isBusy ? '1' : '0' ?>">
  <div class="card-body">
    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
      <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
      <strong id="deploy-stage"><?= $isBusy ? e($site['stage'] ?? 'deploying') : '...' ?></strong>
      <span id="deploy-message" class="text-muted small"><?= $isBusy ? e($site['message'] ?? '') : '' ?></span>
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
  <div class="alert alert-danger" role="alert"><strong>Error:</strong> <?= e($site['error'] ?? $site['message'] ?? '') ?></div>
<?php endif; ?>

<?php if (!$isBusy): ?>
<div class="d-flex flex-wrap gap-2 mb-4" id="site-actions">
  <form method="post" action="/sites/<?= e($site['id']) ?>/rebuild" id="rebuild-form"><?= csrf_field() ?><button id="rebuild-btn" class="btn btn-outline-secondary btn-sm">↻ Rebuild</button></form>

  <?php if ($status === 'running'): ?>
    <form method="post" action="/sites/<?= e($site['id']) ?>/stop"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">■ Stop</button></form>
  <?php elseif ($status === 'stopped'): ?>
    <form method="post" action="/sites/<?= e($site['id']) ?>/start"><?= csrf_field() ?><button class="btn btn-success btn-sm">▶ Start</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Tab bar navigasi section site (scroll horizontal di layar sempit) -->
<ul class="nav nav-pills tab-scroll mb-3" id="siteTabs" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-info-btn" data-bs-toggle="tab" data-bs-target="#tab-info" type="button" role="tab" aria-controls="tab-info" aria-selected="true">Info</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-containers-btn" data-bs-toggle="tab" data-bs-target="#tab-containers" type="button" role="tab" aria-controls="tab-containers" aria-selected="false">Container</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-deploy-btn" data-bs-toggle="tab" data-bs-target="#tab-deploy" type="button" role="tab" aria-controls="tab-deploy" aria-selected="false">Deployment</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-env-btn" data-bs-toggle="tab" data-bs-target="#tab-env" type="button" role="tab" aria-controls="tab-env" aria-selected="false">Environment</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-network-btn" data-bs-toggle="tab" data-bs-target="#tab-network" type="button" role="tab" aria-controls="tab-network" aria-selected="false">Network</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-domain-btn" data-bs-toggle="tab" data-bs-target="#tab-domain" type="button" role="tab" aria-controls="tab-domain" aria-selected="false">Domain &amp; SSL</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-delete-btn" data-bs-toggle="tab" data-bs-target="#tab-delete" type="button" role="tab" aria-controls="tab-delete" aria-selected="false">Hapus Site</button></li>
</ul>

<div class="tab-content" id="siteTabContent">

  <!-- ============ Tab: Info ============ -->
  <div class="tab-pane fade show active" id="tab-info" role="tabpanel" aria-labelledby="tab-info-btn">
    <div class="card mb-4">
      <div class="card-body py-2">
    <dl class="site-info mb-0">
      <div class="site-info-item">
        <dt class="k">Subdomain</dt>
        <dd class="v mb-0"><a href="http://<?= e($site['subdomain']) ?>" target="_blank" rel="noopener"><?= e($site['subdomain']) ?></a><?php if ($customDomain): ?> <span class="text-muted small">(redirect → <?= e($customDomain) ?>)</span><?php endif; ?></dd>
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
  </div>

  <!-- ============ Tab: Domain & SSL ============ -->
  <div class="tab-pane fade" id="tab-domain" role="tabpanel" aria-labelledby="tab-domain-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Custom Domain</h2>
    <?php if ($customDomain): ?>
      <a class="btn btn-outline-secondary btn-sm" href="/ssl">Kelola SSL &rarr;</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($customDomain): ?>
      <dl class="site-info mb-3">
        <div class="site-info-item">
          <dt class="k">Domain</dt>
          <dd class="v mb-0"><a href="<?= $customSslActive ? 'https' : 'http' ?>://<?= e($customDomain) ?>" target="_blank" rel="noopener"><?= e($customDomain) ?></a></dd>
        </div>
        <div class="site-info-item">
          <dt class="k">Status SSL</dt>
          <dd class="v mb-0">
            <span class="badge badge-<?= $customSslActive ? 'running' : ($customSslPending ? 'deploying' : ($customSslFailed ? 'error' : 'stopped')) ?>"><?= e($customSslStatus) ?></span>
            <?php if ($customSslExpiresAt): ?>
              <span class="text-muted small"> &middot; kedaluwarsa <?= e($customSslExpiresAt) ?></span>
            <?php endif; ?>
          </dd>
        </div>
        <div class="site-info-item">
          <dt class="k">Subdomain bawaan</dt>
          <dd class="v mb-0 small"><span class="text-muted">redirect ke custom domain</span></dd>
        </div>
      </dl>
      <?php if ($customSslError): ?>
        <div class="alert alert-danger py-2 small"><?= e($customSslError) ?></div>
      <?php endif; ?>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if ($customSslActive): ?>
          <span class="text-muted small">SSL aktif</span>
        <?php elseif ($customSslPending): ?>
          <span class="text-muted small">proses penerbitan SSL ...</span>
        <?php elseif ($sslSupported): ?>
          <form method="post" action="/ssl/<?= e($site['id']) ?>/enable" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="domain" value="<?= e($customDomain) ?>">
            <button class="btn btn-<?= $customSslFailed ? 'outline-danger' : 'primary' ?> btn-sm">
              <?= $customSslFailed ? '↻ Retry SSL' : 'Aktifkan SSL' ?>
            </button>
          </form>
        <?php else: ?>
          <span class="text-muted small">SSL tidak didukung untuk APP_DOMAIN saat ini</span>
        <?php endif; ?>
        <form method="post" action="/sites/<?= e($site['id']) ?>/domain/remove"
              onsubmit="return confirm('Hapus custom domain <?= e($customDomain) ?>? Sertifikat SSL-nya (bila ada) akan di-revoke.');">
          <?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">✕ Hapus domain</button>
        </form>
      </div>
    <?php else: ?>
      <form method="post" action="/sites/<?= e($site['id']) ?>/domain/set" class="row g-2 align-items-center">
        <?= csrf_field() ?>
        <div class="col-auto flex-grow-1">
          <input type="text" name="domain" class="form-control form-control-sm mono" placeholder="mis. example.org" required>
        </div>
        <div class="col-auto">
          <button class="btn btn-primary btn-sm">Set Custom Domain</button>
        </div>
      </form>
      <p class="text-muted small mb-0 mt-2">Setelah diset, subdomain bawaan akan redirect (301) ke custom domain. Arahkan DNS domain ke server ini, lalu aktifkan SSL-nya.</p>
    <?php endif; ?>
  </div>
</section>
  </div>

  <!-- ============ Tab: Environment ============ -->
  <div class="tab-pane fade" id="tab-env" role="tabpanel" aria-labelledby="tab-env-btn">
    <?php
    $envVars = is_array($site['env'] ?? null) ? $site['env'] : [];
    $envExampleExists = is_file((string) config('deploy.sites_path') . '/' . $site['name'] . '/.env.example');
    ?>

    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Environment Variables</h2>
    <?php if ($envExampleExists): ?>
      <form method="post" action="/sites/<?= e($site['id']) ?>/env/import" class="d-inline">
        <?= csrf_field() ?>
        <button class="btn btn-outline-secondary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>⇩ Import dari .env.example</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="">
    <form method="post" action="/sites/<?= e($site['id']) ?>/env" id="env-form">
      <?= csrf_field() ?>
      <?php if (empty($envVars)): ?>
        <p class="text-muted small mb-3">Belum ada environment variable. Tambahkan variabel untuk aplikasi site (mis. kredensial database), lalu klik <strong>Simpan &amp; Terapkan</strong>.</p>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table align-middle mb-3" id="env-table">
          <thead>
            <tr>
              <th class="w-40">Key</th>
              <th>Value</th>
              <th class="text-end" style="width:70px;">Hapus</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 0; foreach ($envVars as $k => $v): ?>
            <tr data-env-row>
              <td><input type="text" name="env[<?= $i ?>][key]" value="<?= e((string) $k) ?>" class="form-control form-control-sm mono" placeholder="APP_KEY" required></td>
              <td>
                <div class="input-group input-group-sm">
                  <input type="password" name="env[<?= $i ?>][value]" value="<?= e((string) $v) ?>" class="form-control mono env-value" placeholder="nilai" autocomplete="off">
                  <button type="button" class="btn btn-outline-secondary env-reveal" tabindex="-1" title="Tampilkan / sembunyikan nilai">👁</button>
                </div>
              </td>
              <td class="text-end">
                <input class="form-check-input" type="checkbox" name="env_delete[]" value="<?= e((string) $k) ?>" title="Hapus variabel ini">
              </td>
            </tr>
            <?php $i++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="p-3">
        <p class="text-muted small mb-3">
          Nilai ter-mask; klik 👁 untuk melihat. Perubahan diterapkan dengan menciptakan
          ulang container yang env-nya berubah (tanpa rebuild source). Variabel tersedia
          untuk substitusi <code>${VAR}</code> di <span class="mono">docker-compose.yml</span>
          dan di-inject ke environment <strong>semua container</strong> site.
        </p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="env-add-row">＋ Tambah variabel</button>
          <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Terapkan</button>
          <?php if ($isBusy): ?>
            <span class="text-muted small">Dinonaktifkan sementara site sedang diproses.</span>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</section>

<script>
(function () {
  var table = document.getElementById('env-table');
  var addBtn = document.getElementById('env-add-row');
  if (!table || !addBtn) return;
  var rowIndex = <?= (int) count($envVars) ?>;
  addBtn.addEventListener('click', function () {
    var tr = document.createElement('tr');
    tr.setAttribute('data-env-row', '');
    tr.innerHTML =
      '<td><input type="text" name="env[' + rowIndex + '][key]" class="form-control form-control-sm mono" placeholder="APP_KEY"></td>' +
      '<td><div class="input-group input-group-sm">' +
        '<input type="password" name="env[' + rowIndex + '][value]" class="form-control mono env-value" placeholder="nilai" autocomplete="off">' +
        '<button type="button" class="btn btn-outline-secondary env-reveal" tabindex="-1" title="Tampilkan / sembunyikan nilai">👁</button>' +
      '</div></td>' +
      '<td class="text-end"><input class="form-check-input" type="checkbox" name="env_delete[]" title="Hapus variabel ini"></td>';
    table.querySelector('tbody').appendChild(tr);
    rowIndex++;
  });

  // Reveal/sembunyikan nilai (delegasi — ikut berlaku untuk baris baru)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.env-reveal');
    if (!btn) return;
    var input = btn.parentElement.querySelector('.env-value');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
  });
})();
</script>
  </div>
  <!-- ============ Tab: Network (external network lintas-site) ============ -->
  <div class="tab-pane fade" id="tab-network" role="tabpanel" aria-labelledby="tab-network-btn">
    <?php
    $extNetworks = is_array($site['external_networks'] ?? null) ? $site['external_networks'] : [];
    ?>
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">External Networks</h2>
        <span class="text-muted small">shared network lintas-site (via compose external)</span>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Hubungkan site ini ke <strong>shared network</strong> agar container-nya bisa
          saling berkomunikasi dengan site lain. Buat network lewat halaman
          <a href="/networks">Networks</a>, lalu centang di sini dan klik
          <strong>Simpan &amp; Terapkan</strong> — container diciptakan ulang dan
          koneksi ini <strong>persisten</strong> (tidak hilang saat Rebuild/Rollback).
        </p>
        <form method="post" action="/sites/<?= e($site['id']) ?>/network">
          <?= csrf_field() ?>
          <?php if (empty($availableNetworks)): ?>
            <div class="alert alert-info py-2 small mb-3">
              Tidak ada network eksternal yang tersedia (atau Docker Engine tidak dapat
              diakses). Buat shared network dulu di halaman <a href="/networks">Networks</a>.
            </div>
          <?php else: ?>
            <div class="border rounded p-2 mb-3" style="max-height:240px; overflow-y:auto;">
              <?php foreach ($availableNetworks as $n): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="external_networks[]" value="<?= e($n) ?>" id="ext-<?= e($n) ?>"
                         <?= in_array($n, $extNetworks, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small mono" for="ext-<?= e($n) ?>"><?= e($n) ?></label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Terapkan</button>
            <?php if ($isBusy): ?>
              <span class="text-muted small">Dinonaktifkan sementara site sedang diproses.</span>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>
  </div>
  <!-- ============ Tab: Container ============ -->
  <div class="tab-pane fade" id="tab-containers" role="tabpanel" aria-labelledby="tab-containers-btn">
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
      <tr><th>Service</th><th>Container</th><th>Image</th><th>Port</th><th>Status</th><th class="text-end">Aksi</th></tr>
    </thead>
    <tbody>
      <?php foreach ($containers as $c): ?>
      <?php $cRunning = ($c['status'] ?? '') === 'running'; ?>
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
        <td class="text-end text-nowrap">
          <?php if ($cRunning): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm terminal-btn"
                    data-site="<?= e($site['id']) ?>" data-container="<?= e($c['container_name'] ?? '') ?>"
                    data-shell="sh" title="Buka shell interaktif (docker exec -it sh)">⌁ Terminal</button>
            <button type="button" class="btn btn-outline-primary btn-sm run-btn ms-1"
                    data-site="<?= e($site['id']) ?>" data-container="<?= e($c['container_name'] ?? '') ?>"
                    title="Jalankan perintah satu kali (docker exec ... sh -c)">> Run</button>
          <?php else: ?>
            <span class="text-muted small">-</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  </section>
  </div>

  <!-- ============ Tab: Deployment ============ -->
  <div class="tab-pane fade" id="tab-deploy" role="tabpanel" aria-labelledby="tab-deploy-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Riwayat Deployment</h2>
    <div class="d-flex align-items-center gap-2">
      <span class="text-muted small">Versi aktif: <code><?= $activeSha !== '' ? e(substr((string) $activeSha, 0, 7)) : '-' ?></code></span>
      <a class="btn btn-outline-secondary btn-sm" href="/sites/<?= e($site['id']) ?>/versions">Semua versi &rarr;</a>
    </div>
  </div>
  <?php if (empty($deployHistory)): ?>
    <div class="card-body text-muted small">Belum ada riwayat deploy. Riwayat tercatat otomatis setiap deploy/rebuild/rollback sukses.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead>
      <tr><th>Commit</th><th>Waktu</th><th>Aksi</th><th>Status</th><th class="text-end">Aksi</th></tr>
    </thead>
    <tbody>
      <?php foreach (array_slice($deployHistory, 0, 5) as $h): ?>
      <?php
        $hStatus = (string) ($h['status'] ?? '');
        $hShort = (string) ($h['short'] ?? substr((string) ($h['sha'] ?? ''), 0, 7));
        $hBadge = in_array($hStatus, ['success', 'restored'], true) ? 'running' : ($hStatus === 'error' ? 'error' : 'stopped');
        $hMsg = (string) ($h['message'] ?? '');
        $hMsgShort = strlen($hMsg) > 80 ? substr($hMsg, 0, 80) . '…' : $hMsg;
        $isRollbackTarget = in_array($hStatus, ['success', 'restored'], true) && ($h['sha'] ?? '') !== $activeSha && !$isBusy;
      ?>
      <tr>
        <td><code><?= e($hShort) ?></code><?= ($h['sha'] ?? '') === $activeSha ? ' <span class="text-muted small">(aktif)</span>' : '' ?></td>
        <td class="small"><?= e((string) ($h['created_at'] ?? '-')) ?></td>
        <td class="small"><?= e((string) ($h['action'] ?? '-')) ?></td>
        <td>
          <span class="badge badge-<?= e($hBadge) ?>"><?= e($hStatus) ?></span>
          <?php if ($hMsg !== ''): ?>
            <span class="text-muted small d-block" title="<?= e($hMsg) ?>"><?= e($hMsgShort) ?></span>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <?php if ($isRollbackTarget): ?>
            <form method="post" action="/sites/<?= e($site['id']) ?>/rollback" class="d-inline"
                  onsubmit="return confirm('Rollback site <?= e($site['name']) ?> ke commit <?= e($hShort) ?>?\n\nSource code akan diganti ke versi itu dan container di-build ulang. Volume/data tidak dihapus.');">
              <?= csrf_field() ?>
              <input type="hidden" name="ref" value="<?= e((string) ($h['sha'] ?? '')) ?>">
              <button class="btn btn-outline-warning btn-sm">↶ Rollback</button>
            </form>
          <?php else: ?>
            <span class="text-muted small">-</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php if (count($deployHistory) > 5): ?>
  <div class="card-footer text-end">
    <a class="btn btn-outline-secondary btn-sm" href="/sites/<?= e($site['id']) ?>/versions">Lihat semua <?= count($deployHistory) ?> versi &rarr;</a>
  </div>
  <?php endif; ?>
  </section>
  </div>

  <!-- ============ Tab: Hapus Site ============ -->
  <div class="tab-pane fade" id="tab-delete" role="tabpanel" aria-labelledby="tab-delete-btn">
    <section class="card mb-4 border-danger">
      <div class="card-header">
        <h2 class="h6 mb-0 text-danger">Danger Zone</h2>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Menghapus site akan menghentikan &amp; menghapus container, config Nginx, dan
          direktori lokal. Pilih mode di dialog konfirmasi:
          <strong>pertahankan volume</strong> (data database tetap ada dan dipakai ulang
          bila site dibuat ulang dengan nama sama) atau <strong>hapus total</strong>
          (termasuk semua volume — data hilang permanen).
        </p>
        <?php if ($isBusy): ?>
          <span class="text-muted small">Dinonaktifkan sementara site sedang diproses.</span>
        <?php else: ?>
          <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">✕ Delete site</button>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php if (!$isBusy): ?>
<!-- Modal konfirmasi delete: pilih volume yang dipertahankan -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="/sites/<?= e($site['id']) ?>/delete" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="deleteModalLabel">Hapus site <?= e($site['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Container, config Nginx, dan direktori lokal akan dihapus.
          <strong>Volume yang dicentang dipertahankan</strong> dan akan dipakai
          ulang otomatis bila site dibuat ulang dengan nama
          <span class="mono"><?= e($site['name']) ?></span>
          (data seperti database tidak hilang).
        </p>
        <?php if (empty($volumes)): ?>
          <div class="alert alert-info py-2 small mb-0">Tidak ada named volume terdeteksi untuk project ini (atau Docker Engine tidak dapat diakses).</div>
        <?php else: ?>
          <label class="form-label fw-semibold">Pilih volume yang dipertahankan:</label>
          <div class="border rounded p-2 mb-2" style="max-height:220px; overflow-y:auto;">
            <?php foreach ($volumes as $v): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="preserve_volumes[]" value="<?= e($v) ?>" id="vol-<?= e($v) ?>" checked>
                <label class="form-check-label small mono" for="vol-<?= e($v) ?>"><?= e($v) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="text-muted small mb-0">Volume yang <strong>tidak</strong> dicentang ikut dihapus.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
        <button type="submit" name="mode" value="preserve" class="btn btn-danger btn-sm">Hapus &amp; pertahankan volume</button>
        <button type="submit" name="mode" value="purge" class="btn btn-outline-danger btn-sm"
                onclick="return confirm('Hapus TOTAL site <?= e($site['name']) ?> termasuk SEMUA volume (data database dll. ikut terhapus permanen)? Tindakan ini tidak bisa dibatalkan.');">Hapus total (semua volume)</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function copyDetailKey() {
  var t = document.getElementById('ssh-pubkey-detail');
  if (!t) return;
  t.select();
  t.setSelectionRange(0, 99999);
  try { navigator.clipboard.writeText(t.value); } catch (e) {}
  try { document.execCommand('copy'); } catch (e) {}
}

(function () {
  var SITE_ID = '<?= e($site['id']) ?>';
  var STATUS_URL = '/api/sites/' + SITE_ID + '/status';
  var POLL_MS = 3000;
  var MAX_TICKS = 600; // ~30 menit

  // pemetaan tahap worker -> persentase progres (perkiraan)
  var STAGE_PERCENT = {
    queued: 5, pull: 15, clone: 15, build: 40, collect: 70,
    nginx: 85, rollback: 20, restore: 60, done: 100
  };

  var panel = document.getElementById('deploy-progress');
  var bar = document.getElementById('deploy-progress-bar');
  var stageEl = document.getElementById('deploy-stage');
  var msgEl = document.getElementById('deploy-message');
  var errEl = document.getElementById('deploy-error');
  var statusBadge = document.getElementById('site-status');

  var timer = null;
  var ticks = 0;

  function setStage(stage, message) {
    if (stageEl) stageEl.textContent = stage || '...';
    if (msgEl) msgEl.textContent = message || '';
    if (bar) {
      var pct = stage === 'done' || stage === 'error' ? 100
        : (STAGE_PERCENT[stage] !== undefined ? STAGE_PERCENT[stage] : 50);
      bar.style.width = pct + '%';
      bar.setAttribute('aria-valuenow', String(pct));
      bar.classList.toggle('progress-bar-animated', pct < 100);
      bar.classList.toggle('progress-bar-striped', pct < 100);
    }
  }

  function showError(msg) {
    if (errEl) {
      errEl.textContent = msg;
      errEl.classList.remove('d-none');
    }
    if (bar) {
      bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
      bar.style.width = '100%';
    }
  }

  function showPanel(stage, message) {
    if (errEl) errEl.classList.add('d-none');
    if (panel) panel.classList.remove('d-none');
    setStage(stage, message);
    var actions = document.getElementById('site-actions');
    if (actions) actions.classList.add('d-none');
  }

  function stopPoll() {
    if (timer) { clearInterval(timer); timer = null; }
  }

  function startPoll(initialStage, initialMessage) {
    stopPoll();
    ticks = 0;
    setStage(initialStage || 'queued', initialMessage || '');
    timer = setInterval(function () {
      ticks++;
      fetch(STATUS_URL, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.site) return;
          var st = d.site.status || 'unknown';
          if (statusBadge) {
            statusBadge.textContent = st;
            statusBadge.className = 'badge badge-' + st;
          }
          setStage(d.site.stage, d.site.message);
          if (st !== 'deploying') {
            stopPoll();
            if (st === 'error') {
              showError(d.site.error || d.site.message || 'Proses gagal.');
            } else {
              // selesai: reload sebentar lagi agar halaman menampilkan state final
              setTimeout(function () { window.location.reload(); }, 600);
            }
          }
        })
        .catch(function () {});
      if (ticks > MAX_TICKS) {
        stopPoll();
        showError('Waktu tunggu habis. Muat ulang halaman untuk melihat status terakhir.');
      }
    }, POLL_MS);
  }

  // Bila halaman dibuka saat site sedang diproses (mis. usai me-refresh), langsung poll.
  if (panel && panel.getAttribute('data-busy') === '1') {
    startPoll('<?= e($site['stage'] ?? 'deploying') ?>', '<?= e($site['message'] ?? '') ?>');
  }

  // Rebuild via AJAX: tanpa navigasi halaman, tanpa risiko timeout/refresh.
  var rebuildForm = document.getElementById('rebuild-form');
  if (rebuildForm) {
    var rebuildBtn = document.getElementById('rebuild-btn');
    rebuildForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      showPanel('queued', 'Menunggu worker rebuild ...');
      if (rebuildBtn) { rebuildBtn.disabled = true; rebuildBtn.textContent = 'Membangun ulang ...'; }
      fetch(rebuildForm.action, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new FormData(rebuildForm)
      }).then(function (r) {
        return r.json().catch(function () { return {}; });
      }).then(function (d) {
        if (d && d.code === 0) {
          startPoll('queued', d.message || 'Menunggu worker rebuild ...');
        } else {
          showError((d && (d.error || d.msg)) ? (d.error || d.msg) : 'Gagal memulai rebuild.');
          if (rebuildBtn) { rebuildBtn.disabled = false; rebuildBtn.textContent = '↻ Rebuild'; }
          var actions = document.getElementById('site-actions');
          if (actions) actions.classList.remove('d-none');
        }
      }).catch(function () {
        showError('Gagal terhubung ke server. Periksa koneksi lalu coba lagi.');
        if (rebuildBtn) { rebuildBtn.disabled = false; rebuildBtn.textContent = '↻ Rebuild'; }
        var actions = document.getElementById('site-actions');
        if (actions) actions.classList.remove('d-none');
      });
    });
  }
})();
</script>

<!-- ============ Terminal container (docker exec) ============ -->

<!-- Modal terminal interaktif (xterm.js + SSE) -->
<div class="modal fade" id="terminal-modal" tabindex="-1" aria-labelledby="terminal-title" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title small mb-0 mono" id="terminal-title">Terminal</h5>
        <span id="terminal-status" class="small text-muted ms-2 me-auto"></span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body p-0">
        <div id="terminal-host" style="height:420px; background:#101014;"></div>
      </div>
      <div class="modal-footer py-1">
        <span class="text-muted small me-auto">Ketik perintah shell di dalam terminal. Ketik <code>exit</code> untuk menutup sesi.</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal one-shot run command (non-interaktif) -->
<div class="modal fade" id="run-modal" tabindex="-1" aria-labelledby="run-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="run-form" data-site="<?= e($site['id']) ?>">
        <div class="modal-header py-2">
          <h5 class="modal-title small mb-0 mono" id="run-title">Run command</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="run-container">
          <label class="form-label small" for="run-command">Perintah (dijalankan sebagai <code>sh -c</code> di dalam container):</label>
          <textarea id="run-command" class="form-control form-control-sm mono" rows="3"
                    placeholder="mis. ls -la /app&#10;php artisan migrate --force&#10;cat /etc/os-release"></textarea>
          <div id="run-error" class="alert alert-danger py-2 small mt-3 mb-0 d-none" role="alert"></div>
          <pre id="run-output" class="mt-3 mb-1 p-2 rounded border mono small d-none"
               style="max-height:320px; overflow:auto; background:#0d1117; color:#e6e6e6;"></pre>
          <div class="d-flex justify-content-between align-items-center mt-2">
            <span id="run-exit" class="small text-muted"></span>
            <button type="submit" class="btn btn-primary btn-sm">
              <span id="run-spinner" class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>
              Jalankan
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<link rel="stylesheet" href="/vendor/xterm/xterm.css">
<script src="/vendor/xterm/xterm.js"></script>
<script src="/vendor/xterm/addons/fit/fit.js"></script>
<script src="/js/site-terminal.js?v=4"></script>

<?php include app_path() . '/view/partials/footer.php'; ?>

