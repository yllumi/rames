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
