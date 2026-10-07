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
