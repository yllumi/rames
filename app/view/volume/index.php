<?php $pageTitle = 'Volumes'; $active = 'volumes'; ?>
<?php
$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => 'Volumes', 'href' => null],
];
?>
<?php include app_path() . '/view/partials/header.php'; ?>

<?php
$orphanCount = 0;
$localOrphanCount = 0;
foreach ($rows as $r) {
    if ($r['orphaned']) {
        $orphanCount++;
        if ((string) ($r['server_kind'] ?? 'local') !== 'agent') {
            $localOrphanCount++;
        }
    }
}
// Baris bisa datang dari beberapa server (multi-server): kolom Server menandai
// asalnya; peringatan server tak terjangkau tidak menggagalkan halaman.
$__serverErrors = $serverErrors ?? [];
$__serverNames = [];
foreach ($rows as $r) {
    $__serverNames[(string) ($r['server_name'] ?? 'lokal')] = true;
}
$__serverCount = count($__serverNames);
?>

<div class="page-head mb-4">
  <div>
    <h1 class="h3 mb-1">Volumes</h1>
    <p class="text-muted mb-0">
      Volume ber-label <span class="mono">docker compose</span> dari <strong>setiap server</strong>
      yang berhak Anda lihat. Volume <strong>yatim</strong> ditinggalkan app yang dihapus dengan mode
      "pertahankan volume" — bisa dibersihkan di sini (server lokal).
      Kolom <strong>Ukuran</strong> menampilkan storage terpakai tiap volume server lokal (dihitung
      Docker Engine dan dimuat setelah tabel tampil).
    </p>
  </div>
</div>

<?php if ($engineError): ?>
  <div class="alert alert-warning" role="alert"><?= e($engineError) ?></div>
<?php endif; ?>

<?php foreach ($__serverErrors as $__message): ?>
  <div class="alert alert-warning py-2 small" role="alert"><?= e($__message) ?></div>
<?php endforeach; ?>

<?php if (empty($rows)): ?>
  <div class="card">
    <div class="card-body text-muted small">Tidak ada volume ber-label compose.</div>
  </div>
<?php else: ?>
<form method="post" action="/volumes/purge">
  <?= csrf_field() ?>
  <div class="card">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:32px"></th>
          <th>Volume</th>
          <th>Project</th>
          <th>Server</th>
          <th>Driver</th>
          <th class="text-end">Ukuran</th>
          <th>Mountpoint</th>
          <th>Dibuat</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <?php
          // Asal server baris ini: `server_id` kosong & `server_kind=local` ⇒
          // volume hidup di host dashboard ini (Engine lokal).
          $__srvName = trim((string) ($r['server_name'] ?? ''));
          $__agent = (string) ($r['server_kind'] ?? 'local') === 'agent';
          $__canPurge = (bool) ($r['can_purge'] ?? (! $__agent && $r['orphaned']));
          $__checkboxId = 'vol-' . ($__agent ? (string) ($r['server_id'] ?? '') . '-' : '') . (string) $r['name'];
        ?>
        <tr>
          <td>
            <?php if ($__canPurge): ?>
              <input class="form-check-input" type="checkbox" name="volumes[]" value="<?= e($r['name']) ?>" id="<?= e($__checkboxId) ?>">
            <?php endif; ?>
          </td>
          <td><span class="mono"><?= e($r['name']) ?></span></td>
          <td><span class="mono"><?= e($r['project']) ?></span></td>
          <td class="small">
            <?php if ($__agent): ?>
              <span class="badge badge-deploying" title="Volume hidup di server agent, bukan di host dashboard">agent</span>
              <span class="mono d-block"><?= e($__srvName !== '' ? $__srvName : (string) ($r['server_id'] ?? '')) ?></span>
            <?php else: ?>
              <span class="text-muted" title="Volume hidup di host dashboard (server lokal)">lokal</span>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($__agent): ?>
              <span class="text-muted" title="Dibaca dari server agent: jalur volume per-project hanya melaporkan nama">&mdash;</span>
            <?php else: ?>
              <?= e($r['driver']) ?>
            <?php endif; ?>
          </td>
          <?php if ($__agent): ?>
            <td class="small text-end text-muted" title="Ukuran volume dihitung dari Engine server lokal">&mdash;</td>
          <?php else: ?>
            <td class="small text-end text-muted" data-volume-size="<?= e($r['name']) ?>" title="Menghitung ukuran terpakai ...">&hellip;</td>
          <?php endif; ?>
          <td class="small text-muted">
            <?php if ($__agent): ?>
              <span title="Dibaca dari server agent: jalur volume per-project hanya melaporkan nama">&mdash;</span>
            <?php else: ?>
              <?= e($r['mountpoint']) ?>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($__agent): ?>
              <span class="text-muted">&mdash;</span>
            <?php else: ?>
              <?= e($r['created_at']) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['orphaned']): ?>
              <span class="badge badge-error">yatim</span>
              <?php if ($__agent): ?>
                <span class="d-block text-muted small" title="Penghapusan volume server agent belum didukung dari dashboard ini">hapus di server itu</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge badge-running">dipakai</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
      <span class="text-muted small me-auto">
        <?= count($rows) ?> volume di <?= $__serverCount ?> server (<?= $orphanCount ?> yatim)
        <?php if ($orphanCount > $localOrphanCount): ?>
          — <?= $orphanCount - $localOrphanCount ?> yatim di server agent, hapus di server itu
        <?php endif; ?>
        <span id="volume-usage-total" class="d-none"></span>
        <span id="volume-usage-error" class="d-none text-danger"></span>
      </span>
      <?php if ($canPurge ?? false): ?>
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('Hapus volume yang dipilih? Data pada volume itu hilang permanen.');">Hapus volume terpilih</button>
        <?php if ($localOrphanCount > 0): ?>
          <button type="submit" name="purge_orphans" value="1" class="btn btn-outline-danger btn-sm"
                  onclick="return confirm('Hapus SEMUA volume yatim di server lokal (<?= $localOrphanCount ?> volume)? Data pada volume itu hilang permanen.');">Purge semua yatim</button>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-muted small">Membersihkan volume bersifat global — hanya admin.</span>
      <?php endif; ?>
    </div>
  </div>
</form>

<script>
// Ukuran terpakai volume dihitung Docker Engine (`GET /system/df`) yang harus
// menelusuri filesystem tiap volume → dimuat asinkron agar halaman tidak menunggu.
(function () {
  'use strict';

  var cells = document.querySelectorAll('[data-volume-size]');
  var totalEl = document.getElementById('volume-usage-total');
  var errorEl = document.getElementById('volume-usage-error');
  if (!cells.length) return;

  function fill(cell, text, title) {
    cell.textContent = text;
    cell.setAttribute('title', title);
  }

  fetch('/api/volumes/usage', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || d.code !== 0) {
        throw new Error((d && d.msg) ? d.msg : 'Docker Engine tidak merespons.');
      }
      var usage = (d.data && d.data.usage) || {};
      Array.prototype.forEach.call(cells, function (cell) {
        var item = usage[cell.getAttribute('data-volume-size')];
        if (!item) {
          fill(cell, '—', 'Ukuran volume ini tidak dilaporkan Docker Engine.');
          return;
        }
        cell.classList.remove('text-muted');
        fill(cell, item.size_human,
          item.size_human + ' terpakai · ' + item.size + ' byte · dipakai ' + item.ref_count + ' container');
      });
      if (totalEl) {
        totalEl.textContent = '· total terpakai ' + (d.data.total_human || '0B');
        totalEl.classList.remove('d-none');
      }
    })
    .catch(function (err) {
      Array.prototype.forEach.call(cells, function (cell) {
        cell.classList.remove('text-muted');
        fill(cell, '—', 'Ukuran tidak tersedia.');
      });
      if (errorEl) {
        errorEl.textContent = '· ukuran volume tidak tersedia (' +
          ((err && err.message) ? err.message : 'gagal memuat') + ')';
        errorEl.classList.remove('d-none');
      }
    });
})();
</script>
<?php endif; ?>

<?php include app_path() . '/view/partials/footer.php'; ?>
