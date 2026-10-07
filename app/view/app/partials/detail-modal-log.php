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
