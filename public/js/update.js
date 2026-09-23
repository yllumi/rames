/**
 * Panel self-update dashboard di halaman /nginx (SPECS.md §7.8).
 *
 * Semua aksi = POST + token CSRF (meta[name="csrf-token"]). Saat update berjalan,
 * dashboard akan di-recreate (`docker compose up`), sehingga permintaan polling
 * sempat gagal — itu normal: skrip ini terus mencoba dan menampilkan
 * "menyambung kembali…" sampai container baru melayani request, lalu memuat ulang
 * halaman sekali agar semua informasi datang dari versi terbaru.
 */
(function () {
  'use strict';

  var panel = document.getElementById('update-panel');
  if (!panel) return;

  var CSRF = '';
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) CSRF = meta.getAttribute('content') || '';

  var flash = document.getElementById('update-flash');
  var runBox = document.getElementById('update-run-box');
  var logEl = document.getElementById('update-log');
  var resultEl = document.getElementById('update-run-result');
  var messageEl = document.getElementById('update-run-message');
  var stageEl = document.getElementById('update-run-stage');

  var STAGE_LABELS = {
    starting: 'Menyiapkan',
    preflight: 'Prasyarat',
    fetch: 'Ambil versi',
    merge: 'Terapkan versi',
    composer: 'Dependensi Composer',
    build: 'Build & recreate',
    health: 'Verifikasi sehat',
    rolling_back: 'Rollback',
    finished: 'Selesai'
  };
  var RESULT_BADGES = {
    success: ['text-bg-success', 'Berhasil'],
    rolled_back: ['text-bg-warning', 'Dibatalkan (rollback otomatis)'],
    error: ['text-bg-danger', 'Gagal']
  };

  var pollTimer = null;
  var sawRunning = panel.dataset.running === '1';
  var reloaded = false;

  function notify(html, kind) {
    if (!flash) return;
    flash.innerHTML = '<div class="alert alert-' + (kind || 'info') + ' mb-0 small" role="alert">' + html + '</div>';
  }

  function clearNotify() {
    if (flash) flash.innerHTML = '';
  }

  function post(url) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ _token: CSRF })
    }).then(function (r) {
      return r.json().catch(function () {
        return { code: 400, msg: 'Respons server tidak valid.' };
      });
    });
  }

  function renderRun(data) {
    var run = data.run || {};
    var running = !!data.running;

    if (run.id) {
      runBox.classList.remove('d-none');
    }

    if (resultEl) {
      var badge = RESULT_BADGES[run.result] || ['text-bg-info', running ? 'Berjalan…' : 'Menunggu'];
      resultEl.className = 'badge ' + badge[0];
      resultEl.textContent = badge[1];
    }
    if (messageEl) messageEl.textContent = run.message || '';
    if (stageEl) {
      var stage = STAGE_LABELS[run.stage] || run.stage || '';
      stageEl.textContent = stage !== '' ? 'Tahap: ' + stage : '';
    }
    if (logEl && typeof data.log_tail === 'string' && data.log_tail !== '') {
      logEl.textContent = data.log_tail;
      logEl.scrollTop = logEl.scrollHeight;
    }

    if (running) {
      sawRunning = true;
      schedulePoll();
      return;
    }

    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
    if (sawRunning && !reloaded) {
      // Update selesai → muat ulang sekali supaya halaman dirender versi baru.
      reloaded = true;
      notify('Update selesai — memuat ulang halaman…', 'info');
      setTimeout(function () { window.location.reload(); }, 1500);
    }
  }

  function schedulePoll() {
    if (pollTimer) return;
    pollTimer = setTimeout(function () {
      pollTimer = null;
      poll();
    }, 2000);
  }

  function poll() {
    fetch('/api/update/status', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      return r.json();
    }).then(function (res) {
      if (!res || res.code !== 0) {
        schedulePoll();
        return;
      }
      renderRun(res.data || {});
    }).catch(function () {
      // Dashboard sedang di-recreate — normal, coba lagi.
      if (resultEl) {
        resultEl.className = 'badge text-bg-info';
        resultEl.textContent = 'Menyambung kembali…';
      }
      schedulePoll();
    });
  }

  // ------------------------------------------------------------------
  // Aksi
  // ------------------------------------------------------------------

  var checkBtn = document.getElementById('update-check-btn');
  if (checkBtn) {
    checkBtn.addEventListener('click', function () {
      checkBtn.disabled = true;
      clearNotify();
      notify('Memeriksa pembaruan (git ls-remote)…', 'secondary');
      post('/api/update/check').then(function (res) {
        checkBtn.disabled = false;
        if (res.code !== 0) {
          notify(res.msg || 'Pengecekan gagal.', 'danger');
          return;
        }
        notify((res.msg || 'Selesai.') + ' Memuat ulang…', res.data && res.data.update_available ? 'warning' : 'success');
        setTimeout(function () { window.location.reload(); }, 900);
      });
    });
  }

  var startBtn = document.getElementById('update-start-btn');
  if (startBtn) {
    startBtn.addEventListener('click', function () {
      if (!window.confirm(
        'Jalankan update dashboard sekarang?\n\n' +
        'Dashboard akan di-pull ulang, image dibangun ulang, lalu container di-recreate — ' +
        'akses dashboard terputus beberapa saat dan sesi terminal/app yang sedang berjalan ikut terhenti.\n\n' +
        'Bila versi baru tidak sehat, dashboard otomatis dikembalikan ke versi sekarang.'
      )) return;

      startBtn.disabled = true;
      clearNotify();
      notify('Menjalankan update…', 'info');
      post('/api/update/start').then(function (res) {
        if (res.code !== 0) {
          startBtn.disabled = false;
          notify(res.msg || 'Gagal memulai update.', 'danger');
          return;
        }
        runBox.classList.remove('d-none');
        sawRunning = true;
        notify(res.msg || 'Update dimulai.', 'info');
        poll();
      });
    });
  }

  var rollbackBtn = document.getElementById('update-rollback-btn');
  if (rollbackBtn) {
    rollbackBtn.addEventListener('click', function () {
      if (!window.confirm(
        'Kembalikan dashboard ke versi sebelumnya?\n\n' +
        'Kode akan di-reset ke commit sebelum update terakhir, lalu container di-recreate.'
      )) return;

      rollbackBtn.disabled = true;
      clearNotify();
      notify('Menjalankan rollback…', 'warning');
      post('/api/update/rollback').then(function (res) {
        if (res.code !== 0) {
          rollbackBtn.disabled = false;
          notify(res.msg || 'Gagal memulai rollback.', 'danger');
          return;
        }
        runBox.classList.remove('d-none');
        sawRunning = true;
        notify(res.msg || 'Rollback dimulai.', 'warning');
        poll();
      });
    });
  }

  if (panel.dataset.running === '1') {
    poll();
  }
})();
