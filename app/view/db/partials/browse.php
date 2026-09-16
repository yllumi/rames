<?php /** Browse data tabel + CRUD. */ ?>
<?php if ($db === ''): ?>
  <div class="card"><div class="card-body text-muted small">Pilih database di sidebar untuk mulai.</div></div>
<?php elseif ($table === ''): ?>
  <div class="card">
    <div class="card-header"><h2 class="h6 mb-0">Tabel — <?= e($db) ?></h2></div>
    <div class="card-body">
      <div class="row g-2">
        <?php foreach ($tables as $t): ?>
          <div class="col-sm-6 col-lg-4">
            <a class="card text-decoration-none h-100" href="<?= e($u('browse', $db, $t['name'])) ?>">
              <div class="card-body py-2 d-flex justify-content-between align-items-center">
                <span class="mono small"><?= e($t['name']) ?></span>
                <span class="badge text-bg-<?= $t['type'] === 'VIEW' ? 'secondary' : 'light' ?>"><?= e($t['type']) ?></span>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <?php $pages = max(1, (int) ceil($total / $perPage)); ?>

  <section class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h2 class="h6 mb-0"><?= e($db) ?>.<?= e($table) ?></h2>
      <span class="text-muted small"><?= e($total) ?> baris</span>
    </div>
    <div class="card-body py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="d-flex gap-2 align-items-center">
        <?php if ($page > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e($u('browse', $db, $table, $page - 1)) ?>">&larr; Sebelumnya</a><?php endif; ?>
        <span class="small text-muted">Halaman <?= e($page) ?> / <?= e($pages) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e($u('browse', $db, $table, $page + 1)) ?>">Berikutnya &rarr;</a><?php endif; ?>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($u('structure', $db, $table)) ?>">Struktur</a>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#insert-form" aria-expanded="false">＋ Tambah Baris</button>
      </div>
    </div>

    <!-- Form insert (collapse) -->
    <div class="collapse" id="insert-form">
      <div class="card-body border-top">
        <form method="post" action="/database/<?= e($c) ?>/row/insert">
          <?= csrf_field() ?>
          <input type="hidden" name="db" value="<?= e($db) ?>">
          <input type="hidden" name="table" value="<?= e($table) ?>">
          <input type="hidden" name="mode" value="browse">
          <div class="row g-2">
            <?php foreach ($editableCols as $col): ?>
              <div class="col-md-4">
                <label class="form-label small mono"><?= e($col['field']) ?> <span class="text-muted"><?= e($col['type']) ?></span></label>
                <input type="text" name="cols[<?= e($col['field']) ?>]" class="form-control form-control-sm mono">
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-3"><button class="btn btn-primary btn-sm">Simpan</button></div>
        </form>
      </div>
    </div>

    <div class="table-responsive" style="max-height:560px; overflow:auto;">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light" style="position:sticky; top:0;">
          <tr>
            <?php foreach ($columns as $col): ?><th class="mono small"><?= e($col['field']) ?></th><?php endforeach; ?>
            <?php if ($pk !== null): ?><th class="text-end" style="width:110px;">Aksi</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <?php foreach ($columns as $col): $v = $r[$col['field']] ?? null; ?>
                <td class="small"><?= $v === null ? '<span class="text-muted fst-italic">NULL</span>' : e(mb_strimwidth((string) $v, 0, 200, '…')) ?></td>
              <?php endforeach; ?>
              <?php if ($pk !== null): ?>
                <td class="text-end text-nowrap">
                  <button type="button" class="btn btn-outline-secondary btn-sm btn-edit-row"
                          data-bs-toggle="modal" data-bs-target="#editRowModal"
                          data-pk="<?= e((string) ($r[$pk] ?? '')) ?>"
                          data-row="<?= e(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>">Edit</button>
                  <form method="post" class="d-inline" action="/database/<?= e($c) ?>/row/delete"
                        onsubmit="return confirm('Hapus baris ini?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="db" value="<?= e($db) ?>">
                    <input type="hidden" name="table" value="<?= e($table) ?>">
                    <input type="hidden" name="mode" value="browse">
                    <input type="hidden" name="pk_col" value="<?= e($pk) ?>">
                    <input type="hidden" name="pk_val" value="<?= e((string) ($r[$pk] ?? '')) ?>">
                    <button class="btn btn-outline-danger btn-sm">Hapus</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Modal edit baris -->
  <div class="modal fade" id="editRowModal" tabindex="-1" aria-labelledby="editRowModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <form method="post" action="/database/<?= e($c) ?>/row/update">
        <?= csrf_field() ?>
        <input type="hidden" name="db" value="<?= e($db) ?>">
        <input type="hidden" name="table" value="<?= e($table) ?>">
        <input type="hidden" name="mode" value="browse">
        <input type="hidden" name="pk_col" value="<?= e($pk ?? '') ?>">
        <input type="hidden" name="pk_val" id="editPkVal" value="">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="editRowModalLabel">Edit baris — <?= e($table) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2" id="editFields">
              <?php foreach ($editableCols as $col): ?>
                <div class="col-md-4">
                  <label class="form-label small mono"><?= e($col['field']) ?></label>
                  <input type="text" name="cols[<?= e($col['field']) ?>]" data-col="<?= e($col['field']) ?>" class="form-control form-control-sm mono">
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
            <button class="btn btn-primary btn-sm">Simpan</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function () {
    var modal = document.getElementById('editRowModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
      var btn = event.relatedTarget;
      if (!btn || !btn.dataset) return;
      document.getElementById('editPkVal').value = btn.dataset.pk || '';
      var row = {};
      try { row = JSON.parse(btn.dataset.row || '{}'); } catch (e) { row = {}; }
      modal.querySelectorAll('[data-col]').forEach(function (input) {
        var v = row[input.dataset.col];
        input.value = v === null || v === undefined ? '' : v;
      });
    });
  })();
  </script>
<?php endif; ?>
