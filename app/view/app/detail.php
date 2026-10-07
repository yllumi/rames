<?php
$pageTitle = $app['name'];
$active = 'apps';
$status = $app['status'] ?? 'unknown';
$isBusy = in_array($status, ['deploying'], true);

// Hak akses user saat ini pada app (diatur AppAccess — satu pintu otorisasi).
$access = $access ?? [];
$role = $access['role'] ?? null;
$isOwner = $role === \app\library\Auth\AppAccess::ROLE_OWNER;
$abilities = $access['abilities'] ?? [];
$canOperate = (bool) ($abilities['operate'] ?? false);
$canDelete = (bool) ($abilities['delete'] ?? false);
$canShare = (bool) ($abilities['sharing'] ?? false);
$canEnv = (bool) ($abilities['env'] ?? false);
$canNetwork = (bool) ($abilities['network'] ?? false);
$canDomain = (bool) ($abilities['domain'] ?? false);
$canSsl = (bool) ($abilities['ssl'] ?? false);
$canRoutes = (bool) ($abilities['routes'] ?? false);
$canTerminal = (bool) ($abilities['terminal'] ?? false);
$canDb = (bool) ($abilities['database'] ?? false);
$canLogs = (bool) ($abilities['logs'] ?? false);
$canCompose = (bool) ($abilities['compose'] ?? false);
$canFiles = (bool) ($abilities['files'] ?? false);

// App mode compose = dibuat dari file docker-compose.yml (tanpa repo Git),
// sumbernya bisa diedit lewat tab Compose (tanpa rollback/checkpoint Git).
$isCompose = \app\library\Deploy\ComposeSource::isCompose($app);
$compose = $compose ?? null;

// custom domain + status SSL-nya
$customDomain = (string) ($app['custom_domain'] ?? '');
$customSslStatus = (string) ($app['custom_ssl_status'] ?? 'disabled');
$customSslExpiresAt = $app['custom_ssl_expires_at'] ?? null;
$customSslError = $app['custom_ssl_error'] ?? null;
$customSslActive = $customSslStatus === 'active';
$customSslPending = $customSslStatus === 'pending';
$customSslFailed = $customSslStatus === 'failed';
$sslSupported = \app\library\SSL\SslIssuer::isPublicDomain((string) config('deploy.app_domain', ''));

// Rute proxy tambahan per app (field apps.json `nginx_routes`) — dirender Nginx
// sebagai `location ^~ <path>` yang mem-proxy ke <target>.
$appRoutes = \app\library\Nginx\NginxRoutes::all($app);

// overlay status container live di atas data tersimpan
$liveByName = [];
foreach ($live as $lc) {
    $liveByName[$lc['container_name']] = $lc;
}
$containers = $app['containers'] ?? [];
foreach ($containers as &$c) {
    $lc = $liveByName[$c['container_name']] ?? null;
    if ($lc) {
        $c['status'] = $lc['status'] ?? $c['status'];
        $c['host_port'] = $lc['host_port'] ?? $c['host_port'];
        $c['internal_port'] = $lc['internal_port'] ?? $c['internal_port'];
        $c['ports'] = $lc['ports'] ?? ($c['ports'] ?? []);
    }
}
unset($c);

// Daftar port app (semua port yang dipublikasikan) + port yang di-proxy Nginx
// ke domain app — lihat AppPorts (satu implementasi dengan deployer).
$portContext = [
    'name' => $app['name'] ?? '',
    'containers' => $containers,
    'primary_service' => $app['primary_service'] ?? null,
    'primary_port' => $app['primary_port'] ?? null,
];
$appPorts = \app\library\Docker\AppPorts::all($portContext);
$proxiedPort = \app\library\Docker\AppPorts::proxiedContainerPort($portContext);
// App tanpa host port terpublish tidak di-proxy ke domain (tanpa vhost/subdomain)
$hasHostPort = \app\library\Docker\AppPorts::hasHostPort($portContext);

// Container default untuk modal log (service primary, else container pertama)
$logContainer = $canLogs ? \app\library\Docker\AppContainers::defaultContainer($app) : null;

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => $app['name'], 'href' => null],
];
?>
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

<!-- Tab bar navigasi section app (scroll horizontal di layar sempit) -->
<ul class="nav nav-pills tab-scroll mb-3" id="appTabs" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-info-btn" data-bs-toggle="tab" data-bs-target="#tab-info" type="button" role="tab" aria-controls="tab-info" aria-selected="true">Info</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-containers-btn" data-bs-toggle="tab" data-bs-target="#tab-containers" type="button" role="tab" aria-controls="tab-containers" aria-selected="false">Container</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-deploy-btn" data-bs-toggle="tab" data-bs-target="#tab-deploy" type="button" role="tab" aria-controls="tab-deploy" aria-selected="false">Deployment</button></li>
  <?php if ($isCompose && $canCompose): ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-compose-btn" data-bs-toggle="tab" data-bs-target="#tab-compose" type="button" role="tab" aria-controls="tab-compose" aria-selected="false">Compose</button></li>
  <?php endif; ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-env-btn" data-bs-toggle="tab" data-bs-target="#tab-env" type="button" role="tab" aria-controls="tab-env" aria-selected="false">Environment</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-limits-btn" data-bs-toggle="tab" data-bs-target="#tab-limits" type="button" role="tab" aria-controls="tab-limits" aria-selected="false">Sumber Daya</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-network-btn" data-bs-toggle="tab" data-bs-target="#tab-network" type="button" role="tab" aria-controls="tab-network" aria-selected="false">Network</button></li>
  <?php if (!empty($dbContainers)): ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-db-btn" data-bs-toggle="tab" data-bs-target="#tab-db" type="button" role="tab" aria-controls="tab-db" aria-selected="false">Database</button></li>
  <?php endif; ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-domain-btn" data-bs-toggle="tab" data-bs-target="#tab-domain" type="button" role="tab" aria-controls="tab-domain" aria-selected="false">Domain &amp; SSL</button></li>
  <?php if ($canShare || !empty($access['members'])): ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-access-btn" data-bs-toggle="tab" data-bs-target="#tab-access" type="button" role="tab" aria-controls="tab-access" aria-selected="false">Akses</button></li>
  <?php endif; ?>
  <?php if ($canDelete): ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-delete-btn" data-bs-toggle="tab" data-bs-target="#tab-delete" type="button" role="tab" aria-controls="tab-delete" aria-selected="false">Hapus App</button></li>
  <?php endif; ?>
</ul>

<div class="tab-content" id="appTabContent">

  <!-- ============ Tab: Info ============ -->
  <div class="tab-pane fade show active" id="tab-info" role="tabpanel" aria-labelledby="tab-info-btn">
    <div class="card mb-4">
      <div class="card-body py-2">
    <dl class="app-info mb-0">
      <div class="app-info-item">
        <dt class="k">Subdomain</dt>
        <dd class="v mb-0">
          <?php if ($hasHostPort): ?>
          <a href="http://<?= e($app['subdomain']) ?>" target="_blank" rel="noopener"><?= e($app['subdomain']) ?></a><?php if ($customDomain): ?> <span class="text-muted small">(redirect → <?= e($customDomain) ?>)</span><?php endif; ?>
          <?php else: ?>
          <span class="text-muted">tidak dipakai</span>
          <span class="text-muted small"> &middot; app tidak mem-publish port — Nginx tidak di-proxy ke app ini</span>
          <?php endif; ?>
        </dd>
      </div>
      <?php if ($isCompose): ?>
      <?php $tplTitle = is_array($app['template'] ?? null) ? (string) ($app['template']['title'] ?? $app['template']['slug'] ?? '') : ''; ?>
      <div class="app-info-item">
        <dt class="k">Sumber</dt>
        <dd class="v mb-0">
          Compose (paste/upload)
          <?php if ($tplTitle !== ''): ?>
          <span class="badge text-bg-secondary ms-1" title="App dibuat dari template siap-pakai">template: <?= e($tplTitle) ?></span>
          <?php endif; ?>
          <span class="text-muted fw-normal small">· tanpa repo Git — ubah lewat tab Compose</span>
        </dd>
      </div>
      <?php else: ?>
      <div class="app-info-item">
        <dt class="k">Repo</dt>
        <dd class="v mb-0"><?= e($app['repo_url']) ?></dd>
      </div>
      <div class="app-info-item">
        <dt class="k">Branch</dt>
        <dd class="v mb-0"><?= e($app['branch'] ?? 'main') ?></dd>
      </div>
      <div class="app-info-item">
        <dt class="k">Akses repo</dt>
        <dd class="v mb-0">
          <?php if (($app['auth_method'] ?? 'none') === 'ssh'): ?>
            SSH deploy key
            <?php if ($sshPubkey): ?>
              <a class="small fw-normal ms-1" data-bs-toggle="collapse" href="#deploykey-card" role="button" aria-expanded="false" aria-controls="deploykey-card">lihat public key</a>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted fw-normal">Publik (anonim)</span>
          <?php endif; ?>
        </dd>
      </div>
      <?php endif; ?>
      <div class="app-info-item">
        <dt class="k">Primary Service</dt>
        <dd class="v mb-0"><?= e($app['primary_service'] ?? '-') ?></dd>
      </div>
      <div class="app-info-item">
        <dt class="k">Port</dt>
        <dd class="v mb-0">
          <?php if ($appPorts === []): ?>
            <span class="text-muted fw-normal">-</span>
          <?php else: ?>
            <?php foreach ($appPorts as $p): ?>
              <?php $isProxied = $proxiedPort > 0 && $p['container'] === $proxiedPort; ?>
              <div class="fw-normal <?= $isProxied ? '' : 'text-muted' ?>">
                <span class="mono"><?= e((string) $p['container']) ?></span>
                <span class="text-muted">&rarr;</span> host <span class="mono"><?= e($p['host'] > 0 ? (string) $p['host'] : '-') ?></span>
                <?php if ($p['service'] !== ''): ?><span class="text-muted small">(<?= e($p['service']) ?>)</span><?php endif; ?>
                <?php if ($isProxied): ?><span class="small">&middot; di-proxy ke domain</span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </dd>
      </div>
      <div class="app-info-item">
        <dt class="k">Lokasi</dt>
        <dd class="v mb-0 small"><?= e($app['local_path'] ?? '') ?></dd>
      </div>
      <div class="app-info-item">
        <dt class="k">Compose Files</dt>
        <dd class="v mb-0 small"><?= e(implode(', ', $app['compose_files'] ?? ['docker-compose.yml'])) ?></dd>
      </div>
    </dl>
  </div>
</div>

    <?php if (($app['auth_method'] ?? 'none') === 'ssh' && $sshPubkey): ?>
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
      <dl class="app-info mb-3">
        <div class="app-info-item">
          <dt class="k">Domain</dt>
          <dd class="v mb-0"><a href="<?= $customSslActive ? 'https' : 'http' ?>://<?= e($customDomain) ?>" target="_blank" rel="noopener"><?= e($customDomain) ?></a></dd>
        </div>
        <div class="app-info-item">
          <dt class="k">Status SSL</dt>
          <dd class="v mb-0">
            <span class="badge badge-<?= $customSslActive ? 'running' : ($customSslPending ? 'deploying' : ($customSslFailed ? 'error' : 'stopped')) ?>"><?= e($customSslStatus) ?></span>
            <?php if ($customSslExpiresAt): ?>
              <span class="text-muted small"> &middot; kedaluwarsa <?= e($customSslExpiresAt) ?></span>
            <?php endif; ?>
          </dd>
        </div>
        <div class="app-info-item">
          <dt class="k">Subdomain bawaan</dt>
          <dd class="v mb-0 small"><span class="text-muted">redirect ke custom domain</span></dd>
        </div>
      </dl>
      <?php if ($customSslError): ?>
        <div class="alert alert-danger py-2 small"><?= e($customSslError) ?></div>
      <?php endif; ?>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if (!$canDomain): ?>
          <span class="text-muted small">Anda tidak punya hak mengubah domain app ini.</span>
        <?php else: ?>
        <?php if ($customSslActive): ?>
          <span class="text-muted small">SSL aktif</span>
        <?php elseif ($customSslPending): ?>
          <span class="text-muted small">proses penerbitan SSL ...</span>
        <?php elseif ($sslSupported && $canSsl): ?>
          <form method="post" action="/ssl/<?= e($app['id']) ?>/enable" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="domain" value="<?= e($customDomain) ?>">
            <button class="btn btn-<?= $customSslFailed ? 'outline-danger' : 'primary' ?> btn-sm">
              <?= $customSslFailed ? '↻ Retry SSL' : 'Aktifkan SSL' ?>
            </button>
          </form>
        <?php else: ?>
          <span class="text-muted small">SSL tidak didukung untuk APP_DOMAIN saat ini</span>
        <?php endif; ?>
        <form method="post" action="/apps/<?= e($app['id']) ?>/domain/remove"
              onsubmit="return confirm('Hapus custom domain <?= e($customDomain) ?>? Sertifikat SSL-nya (bila ada) akan di-revoke.');">
          <?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">✕ Hapus domain</button>
        </form>
        <?php endif; ?>
      </div>
    <?php elseif (!$hasHostPort): ?>
      <p class="text-muted small mb-0">
        App ini tidak mem-publish port host, sehingga tidak ada yang bisa di-proxy ke domain — subdomain &amp; SSL tidak berlaku.
        Tambahkan <span class="mono">ports:</span> pada compose app (tab Compose) lalu <strong>Deploy Ulang</strong> bila app perlu diakses lewat domain.
      </p>
    <?php elseif (!$canDomain): ?>
      <p class="text-muted small mb-0">Belum ada custom domain. Anda tidak punya hak mengubahnya.</p>
    <?php else: ?>
      <form method="post" action="/apps/<?= e($app['id']) ?>/domain/set" class="row g-2 align-items-center">
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

    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Rute Proxy Tambahan</h2>
      </div>
      <div class="card-body">
        <?php if (!$hasHostPort): ?>
          <p class="text-muted small mb-0">
            App ini tidak mem-publish port host, sehingga tidak ada vhost yang bisa di-proxy — rute proxy tambahan tidak berlaku.
            Tambahkan <span class="mono">ports:</span> pada compose app (tab Compose) lalu <strong>Deploy Ulang</strong> bila app perlu diakses lewat domain.
          </p>
        <?php else: ?>
          <p class="text-muted small">
            Tiap rute dirender Nginx sebagai <span class="mono">location ^~ &lt;path&gt;</span> yang mem-proxy ke <span class="mono">&lt;target&gt;</span>.
            Tulis satu rute per baris dengan format <span class="mono">&lt;path&gt; &lt;target&gt;</span>, mis. <span class="mono">/api/ http://127.0.0.1:3001</span>.
            Maksimal <strong>20 rute</strong>; prefix <span class="mono">/.well-known</span> dicadangkan sistem.
            <span class="mono">target</span> boleh berupa hostname, tetapi hostname harus bisa di-resolve dari host Nginx — bila tidak, <span class="mono">nginx -t</span> gagal dan penyimpanan otomatis dibatalkan.
          </p>
          <div class="table-responsive mb-3">
            <table class="table align-middle mb-0">
              <thead><tr><th>Path</th><th>Target</th></tr></thead>
              <tbody>
                <?php if (empty($appRoutes)): ?>
                  <tr><td colspan="2" class="text-muted small">Belum ada rute tambahan.</td></tr>
                <?php else: ?>
                  <?php foreach ($appRoutes as $r): ?>
                    <tr>
                      <td class="mono"><?= e((string) ($r['path'] ?? '')) ?></td>
                      <td class="mono"><?= e((string) ($r['target'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if (!$canRoutes): ?>
            <p class="text-muted small mb-0">Anda tidak punya hak mengubah rute proxy app ini.</p>
          <?php else: ?>
            <form method="post" action="/apps/<?= e($app['id']) ?>/routes" class="mb-3">
              <?= csrf_field() ?>
              <textarea name="routes" class="form-control form-control-sm mono" rows="4" spellcheck="false"><?= e(\app\library\Nginx\NginxRoutes::toText($appRoutes)) ?></textarea>
              <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                <button class="btn btn-primary btn-sm">Simpan &amp; Terapkan</button>
                <span class="text-muted small">Menyimpan akan menulis ulang config Nginx app ini, mengujinya dengan <span class="mono">nginx -t</span>, lalu me-reload Nginx host. Bila uji gagal, rute dikembalikan ke kondisi sebelumnya.</span>
              </div>
            </form>
            <?php if (!empty($appRoutes)): ?>
              <form method="post" action="/apps/<?= e($app['id']) ?>/routes"
                    onsubmit="return confirm('Hapus semua rute proxy tambahan app ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="routes" value="">
                <button class="btn btn-outline-danger btn-sm">Hapus semua rute</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <!-- ============ Tab: Environment ============ -->
  <div class="tab-pane fade" id="tab-env" role="tabpanel" aria-labelledby="tab-env-btn">
    <?php
    $envVars = is_array($app['env'] ?? null) ? $app['env'] : [];
    $envExampleExists = is_file((string) config('deploy.apps_path') . '/' . $app['name'] . '/.env.example');
    ?>

    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Environment Variables</h2>
    <?php if ($envExampleExists && $canEnv): ?>
      <form method="post" action="/apps/<?= e($app['id']) ?>/env/import" class="d-inline">
        <?= csrf_field() ?>
        <button class="btn btn-outline-secondary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>⇩ Import dari .env.example</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="">
    <?php if (!$canEnv): ?>
      <p class="text-muted small mb-0 p-3">
        Environment variable app ini hanya bisa dilihat user dengan hak <strong>Operator</strong> ke atas
        (nilainya bisa berisi kredensial database/API).
      </p>
    <?php else: ?>
    <form method="post" action="/apps/<?= e($app['id']) ?>/env" id="env-form">
      <?= csrf_field() ?>
      <?php if (empty($envVars)): ?>
        <p class="text-muted small mb-3">Belum ada environment variable. Tambahkan variabel untuk aplikasi app (mis. kredensial database), lalu klik <strong>Simpan &amp; Terapkan</strong>.</p>
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
          dan di-inject ke environment <strong>semua container</strong> app.
        </p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="env-add-row">＋ Tambah variabel</button>
          <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Terapkan</button>
          <?php if ($isBusy): ?>
            <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
          <?php endif; ?>
        </div>
      </div>
    </form>
    <?php endif; ?>
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

  <!-- ============ Tab: Sumber Daya (batas maksimum CPU/memori per service) ============ -->
  <?php
  // Kontrak AppController::limitsContext() — `$resourceLimits` bisa null/kosong
  // (mis. base compose belum terbaca); view wajib tetap render normal.
  $limitsCtx = is_array($resourceLimits ?? null) ? $resourceLimits : [];
  $limitsCanManage = (bool) ($limitsCtx['canManage'] ?? false);
  $limitsError = ($limitsCtx['error'] ?? null) !== null ? (string) $limitsCtx['error'] : null;
  $limitsSaved = is_array($limitsCtx['saved'] ?? null) ? $limitsCtx['saved'] : [];
  $limitsRepo = is_array($limitsCtx['repo'] ?? null) ? $limitsCtx['repo'] : [];
  $limitsServices = [];
  foreach ((array) ($limitsCtx['services'] ?? []) as $limitsService) {
      $limitsService = trim((string) $limitsService);
      if ($limitsService !== '' && !in_array($limitsService, $limitsServices, true)) {
          $limitsServices[] = $limitsService;
      }
  }
  $limitsMinMemory = \app\library\Deploy\ResourceLimits::MIN_MEMORY_MB;
  ?>
  <div class="tab-pane fade" id="tab-limits" role="tabpanel" aria-labelledby="tab-limits-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Batas Sumber Daya</h2>
        <span class="text-muted small">maksimum CPU &amp; memori per service</span>
      </div>
      <div class="card-body">
        <?php if ($limitsError !== null): ?>
          <div class="alert alert-warning py-2 small" role="alert"><?= e($limitsError) ?></div>
        <?php endif; ?>

        <?php if ($limitsServices === []): ?>
          <p class="text-muted small mb-0">Daftar service tidak tersedia (base compose belum terbaca) — batas resource belum bisa ditampilkan.</p>
        <?php elseif ($limitsCanManage): ?>
        <form method="post" action="/apps/<?= e($app['id']) ?>/limits" id="limits-form"<?= $isBusy ? ' onsubmit="return false;"' : '' ?>>
          <?= csrf_field() ?>
          <div class="table-responsive">
            <table class="table align-middle mb-2" id="limits-table">
              <thead>
                <tr>
                  <th style="width:30%;">Service</th>
                  <th>CPU (core)</th>
                  <th>Memori (MB)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($limitsServices as $limitsService): ?>
                <?php
                  $limitSaved = is_array($limitsSaved[$limitsService] ?? null) ? $limitsSaved[$limitsService] : [];
                  $limitRepo = is_array($limitsRepo[$limitsService] ?? null) ? $limitsRepo[$limitsService] : [];
                  $cpuSaved = $limitSaved['cpus'] ?? null;
                  $memSaved = $limitSaved['memory_mb'] ?? null;
                  $cpuRepo = $limitRepo['cpus'] ?? null;
                  $memRepo = $limitRepo['memory_mb'] ?? null;
                  $cpuValue = $cpuSaved !== null ? $cpuSaved : $cpuRepo;
                  $memValue = $memSaved !== null ? $memSaved : $memRepo;
                  $cpuFromRepo = $cpuSaved === null && $cpuRepo !== null;
                  $memFromRepo = $memSaved === null && $memRepo !== null;
                  $limitsId = (string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $limitsService);
                ?>
                <tr>
                  <td><span class="mono"><?= e($limitsService) ?></span></td>
                  <td>
                    <input type="number" step="0.1" min="0" class="form-control form-control-sm" style="max-width:160px;"
                           id="limit-cpus-<?= e($limitsId) ?>"
                           name="limits[<?= e($limitsService) ?>][cpus]"
                           value="<?= $cpuValue !== null ? e((string) $cpuValue) : '' ?>"
                           placeholder="<?= $cpuValue !== null ? e((string) $cpuValue) : 'tanpa batas' ?>">
                    <?php if ($cpuFromRepo): ?><div class="form-text">nilai dari compose repo</div><?php endif; ?>
                  </td>
                  <td>
                    <input type="number" step="1" min="<?= e((string) $limitsMinMemory) ?>" class="form-control form-control-sm" style="max-width:160px;"
                           id="limit-memory-<?= e($limitsId) ?>"
                           name="limits[<?= e($limitsService) ?>][memory_mb]"
                           value="<?= $memValue !== null ? e((string) $memValue) : '' ?>"
                           placeholder="<?= $memValue !== null ? e((string) $memValue) : 'tanpa batas' ?>">
                    <?php if ($memFromRepo): ?><div class="form-text">nilai dari compose repo</div><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="text-muted small mb-3">
            Kosongkan = ikut pengaturan compose repo / tanpa batas. Nilai dari compose repo tampil sebagai prefill
            (bisa diedit). Mengubah batas akan menciptakan ulang container app (named volume tetap).
          </p>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm"<?= $isBusy || $limitsError !== null ? ' disabled' : '' ?>>Simpan &amp; Terapkan</button>
            <?php if ($isBusy): ?>
              <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
            <?php endif; ?>
          </div>
        </form>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle mb-2">
            <thead>
              <tr>
                <th style="width:30%;">Service</th>
                <th>CPU (core)</th>
                <th>Memori (MB)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($limitsServices as $limitsService): ?>
              <?php
                $limitSaved = is_array($limitsSaved[$limitsService] ?? null) ? $limitsSaved[$limitsService] : [];
                $limitRepo = is_array($limitsRepo[$limitsService] ?? null) ? $limitsRepo[$limitsService] : [];
                $cpuSaved = $limitSaved['cpus'] ?? null;
                $memSaved = $limitSaved['memory_mb'] ?? null;
                $cpuRepo = $limitRepo['cpus'] ?? null;
                $memRepo = $limitRepo['memory_mb'] ?? null;
                $cpuValue = $cpuSaved !== null ? $cpuSaved : $cpuRepo;
                $memValue = $memSaved !== null ? $memSaved : $memRepo;
                $cpuFromRepo = $cpuSaved === null && $cpuRepo !== null;
                $memFromRepo = $memSaved === null && $memRepo !== null;
              ?>
              <tr>
                <td><span class="mono"><?= e($limitsService) ?></span></td>
                <td>
                  <?php if ($cpuValue === null): ?>
                    <span class="text-muted">&mdash;</span>
                  <?php else: ?>
                    <span class="mono"><?= e((string) $cpuValue) ?></span>
                    <?php if ($cpuFromRepo): ?><span class="text-muted small">(dari compose repo)</span><?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($memValue === null): ?>
                    <span class="text-muted">&mdash;</span>
                  <?php else: ?>
                    <span class="mono"><?= e((string) $memValue) ?></span> <span class="text-muted small">MB</span>
                    <?php if ($memFromRepo): ?><span class="text-muted small">(dari compose repo)</span><?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-muted small mb-0">Hanya admin yang dapat mengubah batas resource.</p>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <!-- ============ Tab: Network (external network lintas-app) ============ -->
  <div class="tab-pane fade" id="tab-network" role="tabpanel" aria-labelledby="tab-network-btn">
    <?php
    $extNetworks = is_array($app['external_networks'] ?? null) ? $app['external_networks'] : [];
    ?>
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">External Networks</h2>
        <span class="text-muted small">shared network lintas-app (via compose external)</span>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Hubungkan app ini ke <strong>shared network</strong> agar container-nya bisa
          saling berkomunikasi dengan app lain. Buat network lewat halaman
          <a href="/networks">Networks</a>, lalu centang di sini dan klik
          <strong>Simpan &amp; Terapkan</strong> — container diciptakan ulang dan
          koneksi ini <strong>persisten</strong> (tidak hilang saat Rebuild/Rollback).
        </p>
        <?php if (!$canNetwork): ?>
          <?php if (empty($extNetworks)): ?>
            <p class="text-muted small mb-0">App ini tidak terhubung ke external network.</p>
          <?php else: ?>
            <div class="border rounded p-2 mb-0">
              <?php foreach ($extNetworks as $n): ?>
                <span class="badge text-bg-secondary me-1 mono"><?= e((string) $n) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
        <form method="post" action="/apps/<?= e($app['id']) ?>/network">
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
              <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
            <?php endif; ?>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <?php if (!empty($dbContainers)): ?>
  <!-- ============ Tab: Database ============ -->
  <div class="tab-pane fade" id="tab-db" role="tabpanel" aria-labelledby="tab-db-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Database (MySQL/MariaDB)</h2>
        <a class="btn btn-outline-secondary btn-sm" href="/database">Semua database &rarr;</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr><th>Container</th><th>Image</th><th>Status</th><th class="text-end">Aksi</th></tr>
          </thead>
          <tbody>
            <?php foreach ($dbContainers as $dc): ?>
              <tr>
                <td><span class="mono"><?= e($dc['container_name']) ?></span></td>
                <td class="small"><?= e($dc['image']) ?></td>
                <td><span class="badge badge-<?= e($dc['state'] ?? 'unknown') ?>"><?= e($dc['state'] ?? 'unknown') ?></span></td>
                <td class="text-end">
                  <?php if ($canDb): ?>
                    <a class="btn btn-outline-primary btn-sm" href="/database/<?= e(rawurlencode($dc['container_name'])) ?>">Kelola DB &rarr;</a>
                  <?php else: ?>
                    <span class="text-muted small">tanpa hak</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
  <?php endif; ?>
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
        <td><strong><?= e($c['service_name'] ?? '-') ?></strong><?= ($app['primary_service'] ?? '') === ($c['service_name'] ?? '') ? ' <span class="text-muted small">(primary)</span>' : '' ?></td>
        <td><?= e($c['container_name'] ?? '-') ?></td>
        <td class="small"><?= e($c['image'] ?? '-') ?></td>
        <td class="small">
          <?php $cPorts = \app\library\Docker\AppPorts::forContainer($c); ?>
          <?php if ($cPorts === []): ?>
            <span class="text-muted">-</span>
          <?php else: ?>
            <?php foreach ($cPorts as $p): ?>
              <div class="text-nowrap">
                <span class="mono"><?= e((string) ($p['host'] ?? '')) ?></span><span class="text-muted">:<?= e((string) ($p['container'] ?? '?')) ?></span>
                <?php if ($proxiedPort > 0 && (int) ($p['container'] ?? 0) === $proxiedPort): ?>
                  <span class="badge text-bg-primary ms-1" title="Port ini menerima trafik domain app (di-proxy Nginx)">di-proxy</span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <td><span class="badge badge-<?= e($c['status'] ?? 'unknown') ?>"><?= e($c['status'] ?? 'unknown') ?></span></td>
        <td class="text-end text-nowrap">
          <?php
          $cName = (string) ($c['container_name'] ?? '');
          $hasContainerAction = $canLogs || ($cRunning && ($canTerminal || $canFiles));
          ?>
          <?php if (!$hasContainerAction): ?>
            <span class="text-muted small">-</span>
          <?php else: ?>
            <?php if ($canLogs): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm log-btn"
                      data-container="<?= e($cName) ?>" data-bs-toggle="modal" data-bs-target="#log-modal"
                      title="Lihat log container ini (docker logs)">⧉ Log</button>
            <?php endif; ?>
            <?php if ($cRunning && $canFiles): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm files-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      title="Jelajahi &amp; kelola berkas di dalam container ini">📁 Files</button>
            <?php endif; ?>
            <?php if ($cRunning && $canTerminal): ?>
              <button type="button" class="btn btn-outline-secondary btn-sm terminal-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      data-shell="sh" title="Buka shell interaktif (docker exec -it sh)">⌁ Terminal</button>
              <button type="button" class="btn btn-outline-primary btn-sm run-btn ms-1"
                      data-app="<?= e($app['id']) ?>" data-container="<?= e($cName) ?>"
                      title="Jalankan perintah satu kali (docker exec ... sh -c)">> Run</button>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  </section>

  <!-- Nama container (override container_name) -->
  <?php if ($canCompose): ?>
  <section class="card mb-4">
    <div class="card-header">
      <h2 class="h6 mb-0">Nama container</h2>
    </div>
    <form method="post" action="/apps/<?= e($app['id']) ?>/container-names">
      <?= csrf_field() ?>
      <div class="card-body">
        <label class="form-label" for="container-prefix">Prefix nama container <span class="text-muted small">(opsional)</span></label>
        <input type="text" class="form-control form-control-sm mono" id="container-prefix" name="container_prefix"
               value="<?= e((string) ($app['container_prefix'] ?? '')) ?>"
               maxlength="<?= e((string) \app\library\Deploy\ContainerNames::MAX_PREFIX_LENGTH) ?>"
               placeholder="kosong = <?= e($app['name'] . '_<service>_1') ?>" style="max-width:320px;">
        <div class="form-text">
          Setiap service memakai nama container <span class="mono">{prefix}-{service}</span>. Kosongkan untuk kembali
          ke nama default compose (<span class="mono">&lt;app&gt;_&lt;service&gt;_1</span>). Nama <strong>unik se-host</strong> —
          dicek ke container lain sebelum disimpan. Menyimpan akan <strong>menciptakan ulang</strong> container
          (isi filesystem container hilang, named volume tetap).
        </div>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
        <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Terapkan</button>
        <?php if ($isBusy): ?>
          <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
        <?php endif; ?>
      </div>
    </form>
  </section>
  <?php endif; ?>
  </div>

  <!-- ============ Tab: Deployment ============ -->
  <div class="tab-pane fade" id="tab-deploy" role="tabpanel" aria-labelledby="tab-deploy-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Riwayat Deployment</h2>
    <div class="d-flex align-items-center gap-2">
      <?php if ($isCompose): ?>
        <span class="text-muted small">Sumber: file compose <span class="mono"><?= e((string) ($compose['main_file'] ?? 'docker-compose.yml')) ?></span> · tanpa checkpoint Git</span>
      <?php else: ?>
        <span class="text-muted small">Versi aktif: <code><?= $activeSha !== '' ? e(substr((string) $activeSha, 0, 7)) : '-' ?></code></span>
        <a class="btn btn-outline-secondary btn-sm" href="/apps/<?= e($app['id']) ?>/versions">Semua versi &rarr;</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (empty($deployHistory)): ?>
    <div class="card-body text-muted small">Belum ada riwayat deploy. Riwayat tercatat otomatis setiap deploy/rebuild<?= $isCompose ? '/deploy ulang' : '/rollback' ?> yang sukses.</div>
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
        $isRollbackTarget = !$isCompose && in_array($hStatus, ['success', 'restored'], true) && ($h['sha'] ?? '') !== $activeSha && !$isBusy
            && $canOperate;
      ?>
      <tr>
        <td><code><?= $hShort !== '' ? e($hShort) : '&mdash;' ?></code><?= $hShort !== '' && ($h['sha'] ?? '') === $activeSha ? ' <span class="text-muted small">(aktif)</span>' : '' ?></td>
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
            <form method="post" action="/apps/<?= e($app['id']) ?>/rollback" class="d-inline"
                  onsubmit="return confirm('Rollback app <?= e($app['name']) ?> ke commit <?= e($hShort) ?>?\n\nSource code akan diganti ke versi itu dan container di-build ulang. Volume/data tidak dihapus.');">
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
  <?php if (!$isCompose && count($deployHistory) > 5): ?>
  <div class="card-footer text-end">
    <a class="btn btn-outline-secondary btn-sm" href="/apps/<?= e($app['id']) ?>/versions">Lihat semua <?= count($deployHistory) ?> versi &rarr;</a>
  </div>
  <?php endif; ?>
  </section>
  </div>

  <?php if ($isCompose && $canCompose): ?>
  <!-- ============ Tab: Compose (app mode compose — edit sumber tanpa Git) ============ -->
  <div class="tab-pane fade" id="tab-compose" role="tabpanel" aria-labelledby="tab-compose-btn">
    <section class="card mb-4">
      <div class="card-header">
        <h2 class="h6 mb-0">Compose <span class="mono"><?= e((string) ($compose['main_file'] ?? 'docker-compose.yml')) ?></span></h2>
      </div>
      <div class="card-body">
        <form method="post" action="/apps/<?= e($app['id']) ?>/compose" enctype="multipart/form-data" id="compose-form">
          <?= csrf_field() ?>

          <div class="mb-3">
            <textarea class="form-control mono" name="compose" rows="18" spellcheck="false" required><?= e((string) ($compose['content'] ?? '')) ?></textarea>
            <div class="form-text">
              Service wajib punya <code>image:</code> dan tidak boleh <code>build:</code> (mode ini tanpa build context).
              Host port dikelola dashboard: port service yang sudah ada dipertahankan, konflik dengan app lain digeser otomatis.
            </div>
          </div>

          <?php $composeFiles = array_values(array_filter(
              (array) ($compose['files'] ?? []),
              static fn (string $f): bool => $f !== (string) ($compose['main_file'] ?? '')
          )); ?>
          <?php if ($composeFiles !== []): ?>
          <div class="mb-3">
            <label class="form-label">File pendukung</label>
            <ul class="list-unstyled mb-1">
              <?php foreach ($composeFiles as $f): ?>
              <li class="d-flex align-items-center gap-2 small">
                <input class="form-check-input mt-0" type="checkbox" name="file_delete[]" value="<?= e($f) ?>" id="del-<?= e(md5($f)) ?>">
                <label class="mono mb-0" for="del-<?= e(md5($f)) ?>"><?= e($f) ?></label>
                <span class="text-muted">(centang untuk hapus)</span>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label" for="compose-add-files">Tambah / ganti file pendukung</label>
            <input type="file" class="form-control" id="compose-add-files" name="files[]" multiple>
            <div class="form-text">
              File dengan nama sama akan ditimpa. Maks 1 MB per file. Isi compose utama diubah lewat editor di atas
              (file <span class="mono"><?= e((string) ($compose['main_file'] ?? 'docker-compose.yml')) ?></span> tidak diunggah ulang).
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="submit" class="btn btn-primary btn-sm" <?= $isBusy ? 'disabled' : '' ?>>Simpan &amp; Deploy Ulang</button>
            <?php if ($isBusy): ?>
              <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
            <?php else: ?>
              <span class="text-muted small">Container diciptakan ulang di latar belakang (<span class="mono">up -d</span> tanpa build).</span>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>
  </div>
  <?php endif; ?>

  <!-- ============ Tab: Akses (kepemilikan & sharing) ============ -->
  <?php if ($canShare || !empty($access['members'])): ?>
  <div class="tab-pane fade" id="tab-access" role="tabpanel" aria-labelledby="tab-access-btn">
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h2 class="h6 mb-0">Akses App</h2>
        <span class="text-muted small">owner + user yang dibagikan</span>
      </div>
      <div class="card-body">
        <dl class="app-info mb-3">
          <div class="app-info-item">
            <dt class="k">Owner</dt>
            <dd class="v mb-0">
              <?php if (!empty($access['owner'])): ?>
                <span class="mono"><?= e((string) $access['owner']['username']) ?></span>
              <?php else: ?>
                <span class="text-muted">(belum ada owner)</span>
              <?php endif; ?>
            </dd>
          </div>
        </dl>

        <div class="alert alert-info py-2 small">
          <strong>Viewer</strong> — hanya lihat (read-only).
          <strong>Operator</strong> — deploy/rebuild/rollback/stop/start, environment, network, domain &amp; SSL, rute proxy tambahan, terminal, file manager, database.
          <strong>Owner</strong> — semua di atas + hapus app, transfer kepemilikan, dan atur akses.
        </div>

        <h3 class="h6 mt-4">User dengan akses</h3>
        <?php if (empty($access['members'])): ?>
          <p class="text-muted small mb-0">Belum ada user lain yang punya akses ke app ini.</p>
        <?php else: ?>
        <div class="table-responsive mb-3">
          <table class="table align-middle mb-0">
            <thead><tr><th>User</th><th>Role</th><th>Ditambahkan</th><th class="text-end"></th></tr></thead>
            <tbody>
              <?php foreach ($access['members'] as $m): ?>
              <tr>
                <td class="mono">
                  <?= e((string) $m['username']) ?>
                  <?php if (empty($m['exists'])): ?><span class="text-muted small">(tidak ada di daftar user)</span><?php endif; ?>
                </td>
                <td>
                  <?php if ($canShare): ?>
                  <form method="post" action="/apps/<?= e($app['id']) ?>/members" class="d-flex gap-1 align-items-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= e((string) $m['id']) ?>">
                    <select name="role" class="form-select form-select-sm" style="width:auto;">
                      <?php foreach (\app\library\Auth\AppAccess::ASSIGNABLE_ROLES as $__r): ?>
                        <option value="<?= e($__r) ?>" <?= ((string) $m['role']) === $__r ? 'selected' : '' ?>><?= e(app_role_label($__r)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-secondary btn-sm">Ubah</button>
                  </form>
                  <?php else: ?>
                    <span class="badge text-bg-secondary"><?= e(app_role_label((string) $m['role'])) ?></span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= e((string) $m['added_at']) ?></td>
                <td class="text-end">
                  <?php if ($canShare): ?>
                  <form method="post" action="/apps/<?= e($app['id']) ?>/members/<?= e(rawurlencode((string) $m['id'])) ?>/remove"
                        onsubmit="return confirm('Cabut akses <?= e((string) $m['username']) ?> dari app ini?');">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-danger btn-sm">Cabut</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <?php if ($canShare): ?>
          <?php if (empty($access['candidates'])): ?>
            <p class="text-muted small mb-0">Semua user sudah punya akses ke app ini.</p>
          <?php else: ?>
          <h3 class="h6 mt-4">Bagikan ke user lain</h3>
          <form method="post" action="/apps/<?= e($app['id']) ?>/members" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-md-5">
              <label class="form-label small mb-1" for="member-user">User</label>
              <select class="form-select form-select-sm" id="member-user" name="user_id" required>
                <option value="">— pilih user —</option>
                <?php foreach ($access['candidates'] as $u): ?>
                  <option value="<?= e((string) $u['id']) ?>"><?= e((string) $u['username']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1" for="member-role">Role</label>
              <select class="form-select form-select-sm" id="member-role" name="role">
                <option value="viewer">Viewer — read-only</option>
                <option value="operator" selected>Operator — deploy, terminal, env, DB</option>
                <option value="owner">Owner — + hapus app &amp; kelola akses</option>
              </select>
            </div>
            <div class="col-md-3">
              <button class="btn btn-primary btn-sm w-100">Beri Akses</button>
            </div>
          </form>
          <?php endif; ?>

          <hr class="my-4">
          <h3 class="h6">Transfer kepemilikan</h3>
          <form method="post" action="/apps/<?= e($app['id']) ?>/owner" class="row g-2 align-items-end"
                onsubmit="return confirm('Pindahkan kepemilikan app ini ke user tersebut? Anda tetap terdaftar sebagai co-owner (role owner).');">
            <?= csrf_field() ?>
            <div class="col-md-5">
              <label class="form-label small mb-1" for="owner-user">Owner baru</label>
              <select class="form-select form-select-sm" id="owner-user" name="user_id" required>
                <option value="">— pilih user —</option>
                <?php foreach (($access['users'] ?? []) as $u): ?>
                  <?php if ((string) $u['id'] === (string) ($access['owner_id'] ?? '')) { continue; } ?>
                  <option value="<?= e((string) $u['id']) ?>"><?= e((string) $u['username']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <button class="btn btn-outline-primary btn-sm w-100">Transfer Owner</button>
            </div>
            <div class="col-12">
              <p class="text-muted small mb-0">Owner lama tetap punya akses (sebagai co-owner) supaya serah-terima tidak memutus akses mendadak.</p>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <?php endif; ?>

  <!-- ============ Tab: Hapus App ============ -->
  <?php if ($canDelete): ?>
  <div class="tab-pane fade" id="tab-delete" role="tabpanel" aria-labelledby="tab-delete-btn">
    <section class="card mb-4 border-danger">
      <div class="card-header">
        <h2 class="h6 mb-0 text-danger">Danger Zone</h2>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Menghapus app akan menghentikan &amp; menghapus container, config Nginx, dan
          direktori lokal. Pilih mode di dialog konfirmasi:
          <strong>pertahankan volume</strong> (data database tetap ada dan dipakai ulang
          bila app dibuat ulang dengan nama sama) atau <strong>hapus total</strong>
          (termasuk semua volume — data hilang permanen).
        </p>
        <?php if ($isBusy): ?>
          <span class="text-muted small">Dinonaktifkan sementara app sedang diproses.</span>
        <?php else: ?>
          <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">✕ Delete app</button>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <?php endif; ?>
</div>

<?php if (!$isBusy && $canDelete): ?>
<!-- Modal konfirmasi delete: pilih volume yang dipertahankan -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="/apps/<?= e($app['id']) ?>/delete" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="deleteModalLabel">Hapus app <?= e($app['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Container, config Nginx, dan direktori lokal akan dihapus.
          <strong>Volume yang dicentang dipertahankan</strong> dan akan dipakai
          ulang otomatis bila app dibuat ulang dengan nama
          <span class="mono"><?= e($app['name']) ?></span>
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
                onclick="return confirm('Hapus TOTAL app <?= e($app['name']) ?> termasuk SEMUA volume (data database dll. ikut terhapus permanen)? Tindakan ini tidak bisa dibatalkan.');">Hapus total (semua volume)</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (($guideHtml ?? '') !== ''): ?>
<!-- Modal panduan: HTML hasil sanitasi Markdown::toHtml() — sengaja dirender tanpa e(). -->
<div class="modal fade" id="app-guide-modal" tabindex="-1" aria-labelledby="app-guide-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5 mb-0" id="app-guide-title">Panduan: <?= e($app['name']) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="guide-content"><?= $guideHtml ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
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
  var SITE_ID = '<?= e($app['id']) ?>';
  var STATUS_URL = '/api/apps/' + SITE_ID + '/status';
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
  var statusBadge = document.getElementById('app-status');

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
    var actions = document.getElementById('app-actions');
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
          if (!d || !d.app) return;
          var st = d.app.status || 'unknown';
          if (statusBadge) {
            statusBadge.textContent = st;
            statusBadge.className = 'badge badge-' + st;
          }
          setStage(d.app.stage, d.app.message);
          if (st !== 'deploying') {
            stopPoll();
            if (st === 'error') {
              showError(d.app.error || d.app.message || 'Proses gagal.');
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

  // Bila halaman dibuka saat app sedang diproses (mis. usai me-refresh), langsung poll.
  if (panel && panel.getAttribute('data-busy') === '1') {
    startPoll('<?= e($app['stage'] ?? 'deploying') ?>', '<?= e($app['message'] ?? '') ?>');
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
          var actions = document.getElementById('app-actions');
          if (actions) actions.classList.remove('d-none');
        }
      }).catch(function () {
        showError('Gagal terhubung ke server. Periksa koneksi lalu coba lagi.');
        if (rebuildBtn) { rebuildBtn.disabled = false; rebuildBtn.textContent = '↻ Rebuild'; }
        var actions = document.getElementById('app-actions');
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
      <form id="run-form" data-app="<?= e($app['id']) ?>">
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

<?php if ($canFiles && !empty($containers)): ?>
<!-- Modal file manager container: jelajah berkas, unggah (multi + progres), unduh,
     edit teks, buat folder, rename, hapus, ekstrak arsip. Logika di /js/app-files.js;
     semua data dari server dirender via textContent (anti-XSS). -->
<div class="modal fade" id="files-modal" tabindex="-1" aria-labelledby="files-title" aria-hidden="true"
     data-app="<?= e($app['id']) ?>" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2 gap-2 flex-wrap">
        <h5 class="modal-title small mb-0" id="files-title">📁 Files</h5>
        <span class="text-muted small mono" id="files-container"></span>
        <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="files-refresh"
                  title="Muat ulang daftar" aria-label="Muat ulang daftar berkas">↻ Refresh</button>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
      </div>
      <div class="modal-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="files-toolbar" tabindex="-1">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="files-up"
                  title="Naik ke folder induk" aria-label="Naik ke folder induk" disabled>⬆ Naik</button>
          <nav aria-label="Lokasi folder" class="flex-grow-1" style="min-width:0;">
            <ol class="breadcrumb mb-0 small flex-nowrap" id="files-breadcrumb" style="overflow-x:auto;"></ol>
          </nav>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="files-mkdir"
                    title="Buat folder baru" aria-label="Buat folder baru">📁+ Buat folder</button>
            <button type="button" class="btn btn-outline-primary btn-sm" id="files-upload-btn"
                    title="Unggah satu atau beberapa berkas" aria-label="Unggah berkas">⬆ Unggah</button>
            <input type="file" id="files-upload-input" class="d-none" multiple
                   aria-label="Pilih berkas untuk diunggah">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="files-extract-btn"
                    title="Ekstrak arsip .zip / .tar.gz di folder ini" aria-label="Ekstrak arsip">🗜 Ekstrak</button>
          </div>
        </div>
        <p class="text-muted small mb-2">Batas <strong>64 MB</strong> per berkas. Arsip <span class="mono">.zip</span> /
          <span class="mono">.tar.gz</span> bisa diekstrak langsung di container.</p>
        <div id="files-alert" class="alert alert-danger py-2 small d-none" role="alert"></div>
        <div id="files-uploads" class="mb-2"></div>

        <div id="files-list-pane">
          <div class="table-responsive" style="max-height:52vh; overflow:auto;">
            <table class="table table-hover table-sm align-middle mb-0">
              <thead>
                <tr><th>Nama</th><th>Ukuran</th><th>Diubah</th><th>Mode</th><th class="text-end">Aksi</th></tr>
              </thead>
              <tbody id="files-entries"></tbody>
            </table>
          </div>
          <div id="files-empty" class="text-muted small py-3 text-center d-none">Folder ini kosong.</div>
          <div id="files-truncated" class="text-warning small mt-2 d-none">
            Daftar dipotong (terlalu banyak entri) — sebagian berkas tidak ditampilkan.
          </div>
        </div>

        <div id="files-edit-pane" class="d-none">
          <label class="form-label small" for="files-editor">Edit <span class="mono" id="files-edit-name"></span></label>
          <textarea id="files-editor" class="form-control form-control-sm mono" rows="18" spellcheck="false"
                    aria-describedby="files-edit-meta" style="white-space:pre; overflow:auto;"></textarea>
          <div class="d-flex justify-content-between align-items-center mt-2 gap-2 flex-wrap">
            <span class="text-muted small" id="files-edit-meta"></span>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="files-edit-cancel">Batal</button>
              <button type="button" class="btn btn-primary btn-sm" id="files-edit-save">
                <span id="files-edit-spinner" class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span>Simpan
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer py-1">
        <span class="text-muted small me-auto" id="files-status" aria-live="polite"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canLogs && !empty($containers)): ?>
<!-- Modal log container (docker logs): pilih container, jumlah baris, auto-refresh -->
<div class="modal fade" id="log-modal" tabindex="-1" aria-labelledby="log-title" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2 gap-2 flex-wrap">
        <h5 class="modal-title small mb-0" id="log-title">Log container</h5>
        <span class="text-muted small mono" id="log-meta"><?= e($app['name']) ?></span>
        <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
          <select class="form-select form-select-sm w-auto mono" id="log-container" aria-label="Container">
            <?php foreach ($containers as $c): ?>
              <?php
              $cOptName = (string) ($c['container_name'] ?? '');
              $cOptService = (string) ($c['service_name'] ?? '');
              if ($cOptName === '') {
                  continue;
              }
              ?>
              <option value="<?= e($cOptName) ?>" <?= $cOptName === $logContainer ? 'selected' : '' ?>><?= e($cOptService !== '' ? $cOptService . ' · ' . $cOptName : $cOptName) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="form-select form-select-sm w-auto" id="log-tail" aria-label="Jumlah baris">
            <?php foreach (\app\library\Docker\ContainerLogs::tailOptions() as $__n => $__label): ?>
              <option value="<?= (int) $__n ?>" <?= (int) $__n === \app\library\Docker\ContainerLogs::DEFAULT_TAIL ? 'selected' : '' ?>><?= e($__label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-check form-switch mb-0" title="Muat ulang otomatis tiap 3 detik">
            <input class="form-check-input" type="checkbox" role="switch" id="log-follow">
            <label class="form-check-label small" for="log-follow">Auto</label>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="log-refresh" title="Muat ulang">↻</button>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
      </div>
      <div class="modal-body p-0">
        <pre id="log-pane" class="mb-0 p-3 mono small" style="max-height:60vh; overflow:auto; background:#0d1117; color:#e6e6e6; white-space:pre-wrap; word-break:break-word;"></pre>
      </div>
      <div class="modal-footer py-1">
        <span class="text-muted small me-auto" id="log-status">Memuat log ...</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="log-copy">Salin</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
// Popup log container (docker logs): dimuat saat modal dibuka, opsional auto-refresh
// tiap 3 detik (interval dihentikan saat modal ditutup).
(function () {
  'use strict';

  var modal = document.getElementById('log-modal');
  if (!modal) return;
  var pane = document.getElementById('log-pane');
  var statusEl = document.getElementById('log-status');
  var metaEl = document.getElementById('log-meta');
  var containerSel = document.getElementById('log-container');
  var tailSel = document.getElementById('log-tail');
  var followEl = document.getElementById('log-follow');
  var refreshBtn = document.getElementById('log-refresh');
  var copyBtn = document.getElementById('log-copy');
  var url = '<?= e('/api/apps/' . $app['id'] . '/logs') ?>';
  var timer = null;
  var busy = false;
  var lastText = '';

  function setStatus(msg, isError) {
    statusEl.textContent = msg || '';
    statusEl.className = 'text-muted small me-auto' + (isError ? ' text-danger fw-semibold' : '');
  }

  function atBottom() {
    return pane.scrollTop + pane.clientHeight >= pane.scrollHeight - 24;
  }

  function load() {
    if (busy) return;
    busy = true;
    var stick = atBottom();
    var query = 'container=' + encodeURIComponent(containerSel.value) +
                '&tail=' + encodeURIComponent(tailSel.value);

    setStatus('memuat ...', false);
    fetch(url + '?' + query, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.code !== 0) {
          throw new Error((d && d.msg) ? d.msg : 'Gagal memuat log.');
        }
        var text = (d.data && d.data.text) ? d.data.text : '';
        pane.textContent = text !== '' ? text : '(belum ada output log dari container ini)';
        lastText = text;
        if (metaEl && d.data.container) metaEl.textContent = d.data.container;
        setStatus('diperbarui ' + (d.data.at || '') + ' · ' + (d.data.tail || '') + ' baris terakhir', false);
        if (stick) pane.scrollTop = pane.scrollHeight;
      })
      .catch(function (err) {
        setStatus((err && err.message) ? err.message : 'Gagal memuat log.', true);
      })
      .then(function () { busy = false; });
  }

  function setFollow(on) {
    if (timer) { clearInterval(timer); timer = null; }
    if (on) timer = setInterval(load, 3000);
  }

  modal.addEventListener('shown.bs.modal', function (ev) {
    var want = ev.relatedTarget ? ev.relatedTarget.getAttribute('data-container') : '';
    if (want) {
      // container dari tombol baris tabel mungkin belum ada di dropdown
      if (!containerSel.querySelector('option[value="' + want + '"]')) {
        var opt = document.createElement('option');
        opt.value = want;
        opt.textContent = want;
        containerSel.appendChild(opt);
      }
      containerSel.value = want;
    }
    load();
  });

  modal.addEventListener('hidden.bs.modal', function () {
    followEl.checked = false;
    setFollow(false);
  });

  containerSel.addEventListener('change', load);
  tailSel.addEventListener('change', load);
  refreshBtn.addEventListener('click', load);
  followEl.addEventListener('change', function () {
    setFollow(followEl.checked);
    if (followEl.checked) load();
  });
  copyBtn.addEventListener('click', function () {
    if (!lastText) return;
    navigator.clipboard.writeText(lastText).then(function () {
      copyBtn.textContent = 'Tersalin';
      setTimeout(function () { copyBtn.textContent = 'Salin'; }, 1200);
    }).catch(function () { setStatus('Gagal menyalin ke clipboard.', true); });
  });
})();
</script>
<?php endif; ?>

<link rel="stylesheet" href="/vendor/xterm/xterm.css">
<script>
// Aktifkan tab sesuai hash URL (mis. redirect balik ke #access setelah POST form
// ubah akses). Bootstrap dimuat di footer, jadi tunggu DOMContentLoaded.
document.addEventListener('DOMContentLoaded', function () {
  var hash = (location.hash || '').replace('#', '');
  if (!hash) return;
  var btn = document.getElementById('tab-' + hash + '-btn');
  if (!btn || typeof bootstrap === 'undefined') return;
  new bootstrap.Tab(btn).show();
});
</script>

<script src="/vendor/xterm/xterm.js"></script>
<script src="/vendor/xterm/addons/fit/fit.js"></script>
<script src="/js/app-terminal.js?v=5"></script>
<script src="/js/app-files.js?v=1"></script>

<?php include app_path() . '/view/partials/footer.php'; ?>

