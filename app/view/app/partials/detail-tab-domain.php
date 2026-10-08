  <!-- ============ Tab: Domain & SSL ============ -->
  <div class="tab-pane fade" id="tab-domain" role="tabpanel" aria-labelledby="tab-domain-btn">
  <?php if ($hasHostPort): ?>
  <?php
  // Editor subdomain app. `apps.json.subdomain` menyimpan label; FQDN efektif
  // dirakit server (`app_subdomain_of()`). Semua validasi tetap di server —
  // view hanya merender + mengirim POST ke /apps/{id}/subdomain (ability `domain`).
  $subdomainLabel = (string) ($app['subdomain_label'] ?? '');
  $subdomainCustom = (bool) ($app['subdomain_custom'] ?? false);
  $subdomainNameFallback = app_subdomain((string) ($app['name'] ?? ''));
  // Field kosong = pakai nama app; jadi hanya diisi label saat app memang
  // memakai subdomain eksplisit (label efektif = nama app pada mode fallback).
  $subdomainInput = $subdomainCustom ? $subdomainLabel : '';
  ?>
    <section class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Subdomain</h2>
        <?php if ($customDomain): ?>
        <span class="text-muted small">redirect ke custom domain <?= e($customDomain) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <dl class="app-info mb-3">
          <div class="app-info-item">
            <dt class="k">Subdomain aktif</dt>
            <dd class="v mb-0">
              <a id="subdomain-current-link" class="mono" href="http://<?= e($app['subdomain']) ?>" target="_blank" rel="noopener"><span id="subdomain-current"><?= e($app['subdomain']) ?></span></a>
              <span class="badge text-bg-secondary ms-1" id="subdomain-source"><?= $subdomainCustom && $subdomainLabel !== '' ? 'label: ' . e($subdomainLabel) : 'dari nama app' ?></span>
            </dd>
          </div>
        </dl>
        <?php if (!$canDomain): ?>
        <p class="text-muted small mb-0">Anda tidak punya hak mengubah domain app ini.</p>
        <?php else: ?>
        <form id="subdomain-form" method="post" action="/apps/<?= e($app['id']) ?>/subdomain" class="row g-2 align-items-center">
          <?= csrf_field() ?>
          <div class="col-12 col-md-auto flex-grow-1">
            <label class="visually-hidden" for="subdomain-label">Subdomain (label)</label>
            <input type="text" class="form-control form-control-sm mono" id="subdomain-label" name="subdomain"
                   value="<?= e($subdomainInput) ?>" pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?" maxlength="63"
                   autocomplete="off" spellcheck="false"
                   title="Huruf kecil a-z, angka, dan strip (-) saja; tidak boleh diawali/diakhiri strip; maksimal 63 karakter; kosongkan = pakai nama app."
                   placeholder="kosong = <?= e((string) ($app['name'] ?? '')) ?>" style="max-width:360px;">
          </div>
          <div class="col-12 col-md-auto">
            <button class="btn btn-primary btn-sm" id="subdomain-submit">
              <span class="spinner-border spinner-border-sm d-none" id="subdomain-spinner" role="status" aria-hidden="true"></span>
              Ubah Subdomain
            </button>
          </div>
          <div class="col-12">
            <div class="form-text">
              Kosongkan = kembali memakai nama app (<span class="mono"><?= e($subdomainNameFallback) ?></span>).
              Huruf kecil a-z, angka, dan strip (-) saja; harus unik antar app.
              Dipakai untuk vhost Nginx &amp; sertifikat SSL (<span class="mono">{subdomain}.<?= e((string) config('deploy.app_domain')) ?></span>).
            </div>
          </div>
        </form>
        <div id="subdomain-alert" class="alert d-none mt-3 mb-0 py-2 small" role="alert"></div>
        <p class="text-muted small mb-0 mt-2">
          Sertifikat SSL lama tidak lagi cocok untuk domain baru — terbitkan ulang di halaman <a href="/ssl">SSL</a> bila app memakai HTTPS.
          <?php if (is_admin()): ?>Bila Nginx host belum mereload config baru, muat ulang di halaman <a href="/nginx">Nginx</a>.<?php endif; ?>
        </p>
        <?php endif; ?>
      </div>
    </section>
  <?php if ($canDomain): ?>
  <script>
  // Ubah subdomain via AJAX (tanpa reload). Server menegakkan validasi; view
  // hanya menampilkan pesan hasil + memperbarui FQDN yang ditampilkan.
  (function () {
    var form = document.getElementById('subdomain-form');
    if (!form) return;
    var input = document.getElementById('subdomain-label');
    var btn = document.getElementById('subdomain-submit');
    var spinner = document.getElementById('subdomain-spinner');
    var alertBox = document.getElementById('subdomain-alert');
    var current = document.getElementById('subdomain-current');
    var currentLink = document.getElementById('subdomain-current-link');
    var source = document.getElementById('subdomain-source');
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';

    function show(kind, msg) {
      if (!alertBox) return;
      alertBox.className = 'alert alert-' + kind + ' mt-3 mb-0 py-2 small';
      alertBox.textContent = msg;
    }

    function busy(on) {
      if (btn) btn.disabled = on;
      if (spinner) spinner.classList.toggle('d-none', !on);
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (alertBox) alertBox.className = 'alert d-none mt-3 mb-0 py-2 small';
      busy(true);

      var body = new URLSearchParams();
      body.append('_token', token);
      body.append('subdomain', input ? input.value : '');

      fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: body.toString()
      }).then(function (r) {
        return r.json().then(function (d) {
          return { ok: r.ok, status: r.status, data: d };
        }).catch(function () {
          return { ok: r.ok, status: r.status, data: null };
        });
      }).then(function (res) {
        var d = res.data;
        if (!d || typeof d !== 'object') {
          show('danger', 'Respons tidak valid dari server (HTTP ' + res.status + '). Muat ulang halaman lalu coba lagi.');
          return;
        }
        if (d.subdomain && current) current.textContent = d.subdomain;
        if (d.subdomain && currentLink) currentLink.href = 'http://' + d.subdomain;
        if (d.code === 419) {
          show('danger', 'Sesi kedaluwarsa — muat ulang halaman lalu coba lagi.');
          return;
        }
        if (d.code === 0) {
          if (source && input) {
            var label = input.value.trim();
            source.textContent = label === '' ? 'dari nama app' : 'label: ' + label;
          }
          show('success', d.message || 'Subdomain diperbarui.');
          return;
        }
        // gagal / rollback: tampilkan domain efektif yang masih berlaku
        show('danger', d.error || d.msg || 'Gagal mengubah subdomain.');
      }).catch(function () {
        show('danger', 'Gagal terhubung ke server. Periksa koneksi lalu coba lagi.');
      }).then(function () {
        busy(false);
      });
    });
  })();
  </script>
  <?php endif; ?>
  <?php endif; ?>

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
