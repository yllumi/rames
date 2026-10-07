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
