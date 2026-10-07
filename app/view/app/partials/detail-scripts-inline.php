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
