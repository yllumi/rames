<?php if ($canLogs): ?>
<!-- Modal log deploy: hanya dimuat saat dibuka (tidak mengganggu halaman), berisi
     tahap worker + output build docker compose yang mengalir live. -->
<div class="modal fade" id="deploy-log-modal" tabindex="-1" aria-labelledby="deploy-log-title" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2 gap-2 flex-wrap">
        <h5 class="modal-title small mb-0" id="deploy-log-title">Log deploy</h5>
        <span class="text-muted small mono" id="deploy-log-meta"><?= e($app['name']) ?></span>
        <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
          <div class="form-check form-switch mb-0" title="Ikuti log baru otomatis tiap 2 detik">
            <input class="form-check-input" type="checkbox" role="switch" id="deploy-log-follow" checked>
            <label class="form-check-label small" for="deploy-log-follow">Ikuti</label>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="deploy-log-refresh" title="Muat ulang dari ekor log">↻</button>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
        </div>
      </div>
      <div class="modal-body p-0">
        <pre id="deploy-log-pane" class="mb-0 p-3 mono small" style="max-height:60vh; overflow:auto; background:#0d1117; color:#e6e6e6; white-space:pre-wrap; word-break:break-word;"></pre>
      </div>
      <div class="modal-footer py-1">
        <span class="text-muted small me-auto" id="deploy-log-status">Memuat log ...</span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="deploy-log-copy">Salin</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
// Popup log deploy (on-demand): dimuat saat modal dibuka, polling dengan offset
// byte supaya hanya baris baru yang ditambahkan. Interval berhenti saat modal
// ditutup (tidak ada polling tersembunyi di halaman).
(function () {
  'use strict';

  var modal = document.getElementById('deploy-log-modal');
  if (!modal) return;
  var pane = document.getElementById('deploy-log-pane');
  var statusEl = document.getElementById('deploy-log-status');
  var followEl = document.getElementById('deploy-log-follow');
  var refreshBtn = document.getElementById('deploy-log-refresh');
  var copyBtn = document.getElementById('deploy-log-copy');
  var url = '<?= e('/api/apps/' . $app['id'] . '/deploy-log') ?>';
  var POLL_MS = 2000;
  var timer = null;
  var busy = false;
  var offset = null;
  var hasOutput = false;
  var ansi = /\x1b\[[0-9;?]*[ -\/]*[@-~]/g;

  function setStatus(msg, isError) {
    statusEl.textContent = msg || '';
    statusEl.className = 'text-muted small me-auto' + (isError ? ' text-danger fw-semibold' : '');
  }

  function atBottom() {
    return pane.scrollTop + pane.clientHeight >= pane.scrollHeight - 24;
  }

  function append(text) {
    if (!text) return;
    var stick = atBottom();
    if (!hasOutput) {
      pane.textContent = '';
      hasOutput = true;
    }
    pane.appendChild(document.createTextNode(text.replace(ansi, '')));
    if (stick) pane.scrollTop = pane.scrollHeight;
    // Jaga DOM tetap ringan pada log yang sangat panjang.
    if (pane.textContent.length > 600000) {
      pane.textContent = pane.textContent.slice(-400000);
    }
  }

  function load(reset) {
    if (busy) return;
    busy = true;
    if (reset) {
      pane.textContent = '';
      offset = null;
      hasOutput = false;
      setStatus('memuat ...', false);
    }
    var query = offset === null ? '?tail=1' : '?offset=' + offset;

    fetch(url + query, { headers: { 'Accept': 'text/plain' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        var next = r.headers.get('X-Rames-Offset');
        return r.text().then(function (text) { return { text: text, next: next }; });
      })
      .then(function (d) {
        append(d.text);
        var next = parseInt(d.next, 10);
        if (!isNaN(next)) offset = next;
        if (!hasOutput) pane.textContent = '(belum ada log deploy untuk app ini)';
        setStatus('diperbarui ' + new Date().toLocaleTimeString(), false);
      })
      .catch(function (err) {
        setStatus((err && err.message) ? ('Gagal memuat log: ' + err.message) : 'Gagal memuat log.', true);
      })
      .then(function () { busy = false; });
  }

  function setFollow(on) {
    if (timer) { clearInterval(timer); timer = null; }
    if (on) timer = setInterval(load, POLL_MS);
  }

  modal.addEventListener('shown.bs.modal', function () {
    load(true);
    setFollow(followEl.checked);
  });

  modal.addEventListener('hidden.bs.modal', function () {
    setFollow(false);
  });

  refreshBtn.addEventListener('click', function () {
    offset = null;
    load(true);
  });

  followEl.addEventListener('change', function () {
    setFollow(followEl.checked);
    if (followEl.checked) load(false);
  });

  copyBtn.addEventListener('click', function () {
    var text = pane.textContent || '';
    if (!text) return;
    navigator.clipboard.writeText(text).then(function () {
      copyBtn.textContent = 'Tersalin';
      setTimeout(function () { copyBtn.textContent = 'Salin'; }, 1200);
    }).catch(function () { setStatus('Gagal menyalin ke clipboard.', true); });
  });

  window.addEventListener('pagehide', function () { setFollow(false); });
})();
</script>
<?php endif; ?>
