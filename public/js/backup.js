// Backup volume → S3 via restic — halaman /backups (PLAN_VOLUME_BACKUP.md §5.4).
//
// Halaman ini hanya merender kerangka; seluruh data volume + status datang dari
// `GET /api/backups/status` dan dimuat/di-refresh dari sini. Polling sengaja
// LAMBAT (>= 15 detik) karena satu run restic bisa panjang — interval dijeda saat
// tab tidak terlihat dan dimatikan saat halaman ditinggalkan (pagehide/
// beforeunload), jadi tidak ada permintaan latar belakang.
//
// Kontrak yang dikonsumsi (JANGAN diubah dari sisi UI):
//   GET  /api/backups/status                 → {code:0,data:{running,status,volumes:[…]}}
//   GET  /api/backups/snapshots?volume=<n>   → {code:0,data:{volume,snapshots:[…]}}
//   POST /backups/run                        (form: _token, volume)
//   POST /backups/restore                    (form: _token, volume, snapshot, confirm)
// Penolakan akses = 404; error validasi = 422; error Engine/restic = 500.
(function () {
  'use strict';

  var page = document.getElementById('backup-page');
  if (!page) return;

  var ENABLED = page.getAttribute('data-enabled') === '1';
  var IS_ADMIN = page.getAttribute('data-is-admin') === '1';
  var INTERVAL = parseInt(page.getAttribute('data-interval'), 10);
  if (isNaN(INTERVAL) || INTERVAL < 15000) INTERVAL = 20000;

  var EMPTY = '—';

  var rowsEl = document.getElementById('backup-rows');
  var footerEl = document.getElementById('backup-footer');
  var errorEl = document.getElementById('backup-error');
  var runningEl = document.getElementById('backup-running');
  var lastRunEl = document.getElementById('backup-last-run');
  var refreshBtn = document.getElementById('backup-refresh');

  var runForm = document.getElementById('backup-run-form');
  var runVolumeInput = document.getElementById('backup-run-volume');

  var snapshotsEls = {
    modal: document.getElementById('snapshots-modal'),
    volume: document.getElementById('snapshots-volume'),
    loading: document.getElementById('snapshots-loading'),
    error: document.getElementById('snapshots-error'),
    empty: document.getElementById('snapshots-empty'),
    wrap: document.getElementById('snapshots-table-wrap'),
    rows: document.getElementById('snapshots-rows')
  };

  var restoreEls = {
    modal: document.getElementById('restore-modal'),
    form: document.getElementById('restore-form'),
    volume: document.getElementById('restore-volume'),
    volumeLabel: document.getElementById('restore-volume-label'),
    snapshot: document.getElementById('restore-snapshot'),
    snapshotNote: document.getElementById('restore-snapshot-note'),
    confirm: document.getElementById('restore-confirm'),
    confirmHint: document.getElementById('restore-confirm-hint'),
    submit: document.getElementById('restore-submit'),
    error: document.getElementById('restore-error')
  };

  var snapshotCache = {};
  var busy = false;
  var timer = null;

  // ---------------------------------------------------------------- helper

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Basis 1000, konsisten dengan `docker system df` & halaman lain.
  function fmtBytes(bytes) {
    if (bytes === null || bytes === undefined || isNaN(bytes)) return EMPTY;
    var n = Number(bytes);
    if (n <= 0) return '0B';
    if (n < 1000) return n + 'B';
    var units = ['kB', 'MB', 'GB', 'TB', 'PB'];
    var i = -1;
    do { n = n / 1000; i++; } while (n >= 1000 && i < units.length - 1);
    var dec = n < 10 ? 2 : (n < 100 ? 1 : 0);
    return n.toFixed(dec) + units[i];
  }

  function fmtTime(value) {
    if (!value) return EMPTY;
    var d = new Date(value);
    if (isNaN(d.getTime())) return String(value);
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
      ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function showError(message) {
    if (!errorEl) return;
    errorEl.textContent = message;
    errorEl.classList.remove('d-none');
  }

  function clearError() {
    if (!errorEl) return;
    errorEl.textContent = '';
    errorEl.classList.add('d-none');
  }

  function showModal(modal) {
    if (!modal || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    bootstrap.Modal.getOrCreateInstance(modal).show();
  }

  function hideModal(modal) {
    if (!modal || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    bootstrap.Modal.getOrCreateInstance(modal).hide();
  }

  function postForm(form) {
    return fetch(form.getAttribute('action'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams(new FormData(form))
    }).then(function (r) {
      return r.json().catch(function () {
        return { code: r.status || 400, msg: 'Respons server tidak valid.' };
      });
    });
  }

  // ---------------------------------------------------------------- render

  var STATE_BADGES = {
    running: ['badge-running', 'jalan'],
    stopped: ['badge-stopped', 'berhenti'],
    none: ['badge-unknown', 'tidak ada'],
    mixed: ['badge-error', 'campuran']
  };

  function strategyCell(row) {
    if (row.strategy === 'dump') {
      return '<span class="badge text-bg-info" title="Dump logis dari container DB — container tetap hidup">dump</span>';
    }
    if (row.strategy === 'snapshot') {
      return '<span class="badge text-bg-secondary" title="Snapshot filesystem restic — container berhenti saat snapshot">snapshot</span>';
    }
    return '<span class="text-muted">' + EMPTY + '</span>';
  }

  function stateCell(row) {
    var s = STATE_BADGES[row.container_state];
    if (!s) return '<span class="badge badge-unknown">' + esc(row.container_state || 'tidak diketahui') + '</span>';
    return '<span class="badge ' + s[0] + '">' + esc(s[1]) + '</span>';
  }

  function lastBackupCell(row) {
    if (!row.last_run_at) {
      return '<span class="text-muted small">belum pernah</span>';
    }
    var badge = row.last_ok
      ? '<span class="badge badge-running">ok</span>'
      : '<span class="badge badge-error">gagal</span>';
    var html = badge + ' <span class="small text-muted">' + esc(fmtTime(row.last_run_at)) + '</span>';
    if (!row.last_ok && row.last_message) {
      html += '<div class="text-danger small" title="' + esc(row.last_message) + '">' + esc(row.last_message) + '</div>';
    }
    return html;
  }

  function actionsCell(row) {
    var name = esc(row.name);
    var html = '';
    if (ENABLED) {
      html += '<button type="button" class="btn btn-outline-primary btn-sm backup-run-btn" data-volume="' + name + '">Backup sekarang</button> ';
    }
    html += '<button type="button" class="btn btn-outline-secondary btn-sm backup-snapshots-btn" data-volume="' + name + '">Snapshot</button> ';
    html += '<button type="button" class="btn btn-outline-danger btn-sm backup-restore-btn" data-volume="' + name + '">Restore</button>';
    return html;
  }

  function rowHtml(row) {
    var appLabel;
    if (row.app_name) {
      appLabel = esc(row.app_name);
    } else if (row.orphaned) {
      appLabel = '<span class="badge badge-error" title="Volume yatim: app pemiliknya sudah dihapus">yatim</span>';
    } else {
      appLabel = '<span class="text-muted">' + EMPTY + '</span>';
    }
    var appId = row.app_id ? ' <span class="text-muted small mono">' + esc(row.app_id) + '</span>' : '';

    return '<tr>' +
      '<td><span class="mono">' + esc(row.name) + '</span></td>' +
      '<td><span class="mono small">' + esc(row.project) + '</span></td>' +
      '<td class="small">' + appLabel + appId + '</td>' +
      '<td>' + strategyCell(row) + '</td>' +
      '<td>' + stateCell(row) + '</td>' +
      '<td class="small">' + lastBackupCell(row) + '</td>' +
      '<td class="text-end small">' + (row.snapshots ? Number(row.snapshots) : 0) + '</td>' +
      '<td class="text-end text-nowrap">' + actionsCell(row) + '</td>' +
      '</tr>';
  }

  function renderLastRun(data) {
    if (!lastRunEl) return;
    var st = (data.status && typeof data.status === 'object') ? data.status : {};

    if (data.running) {
      lastRunEl.className = 'alert alert-info py-2 small mb-3';
      lastRunEl.textContent = 'Backup sedang berjalan …';
      lastRunEl.classList.remove('d-none');
      return;
    }
    if (st.finished_at) {
      var ok = st.status === 'ok';
      lastRunEl.className = 'alert py-2 small mb-3 ' + (ok ? 'alert-success' : 'alert-warning');
      lastRunEl.textContent = 'Run terakhir ' + (ok ? 'berhasil' : 'bermasalah') +
        ' · pemicu ' + (st.trigger || '-') +
        ' · selesai ' + fmtTime(st.finished_at) +
        (st.error ? ' · ' + st.error : '');
      lastRunEl.classList.remove('d-none');
      return;
    }
    lastRunEl.classList.add('d-none');
  }

  function render(data) {
    if (runningEl) {
      runningEl.classList.toggle('d-none', !data.running);
    }
    renderLastRun(data);

    var rows = data.volumes || [];
    if (!rows.length) {
      rowsEl.innerHTML = '<tr><td colspan="8" class="text-muted small">' +
        'Tidak ada volume backup yang bisa Anda lihat.' +
        (IS_ADMIN ? '' : ' Anda hanya melihat volume app yang boleh Anda akses.') + '</td></tr>';
    } else {
      rowsEl.innerHTML = rows.map(rowHtml).join('');
    }

    var okCount = 0;
    var failCount = 0;
    rows.forEach(function (r) {
      if (r.last_run_at) { if (r.last_ok) { okCount++; } else { failCount++; } }
    });

    var note = rows.length + ' volume';
    if (okCount || failCount) {
      note += ' · ' + okCount + ' ok' + (failCount ? ', ' + failCount + ' gagal' : '');
    }
    note += ' · diperbarui ' + fmtTime(new Date().toISOString()) +
      ' · otomatis tiap ' + Math.round(INTERVAL / 1000) + ' detik.';
    footerEl.textContent = note;
  }

  // ---------------------------------------------------------------- status

  function load() {
    if (busy) return;
    busy = true;
    if (refreshBtn) refreshBtn.disabled = true;

    fetch('/api/backups/status', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.code !== 0 || !d.data) {
          throw new Error((d && d.msg) ? d.msg : 'Gagal memuat status backup.');
        }
        render(d.data);
        clearError();
      })
      .catch(function (err) {
        showError((err && err.message) ? err.message : 'Gagal memuat status backup.');
        footerEl.textContent = 'Gagal memuat status — coba "Muat ulang".';
      })
      .then(function () {
        busy = false;
        if (refreshBtn) refreshBtn.disabled = false;
      });
  }

  // ---------------------------------------------------------------- snapshots

  function loadSnapshots(volume) {
    if (snapshotCache[volume]) {
      return Promise.resolve(snapshotCache[volume]);
    }
    return fetch('/api/backups/snapshots?volume=' + encodeURIComponent(volume), {
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.code !== 0 || !d.data) {
          throw new Error((d && d.msg) ? d.msg : 'Gagal memuat snapshot.');
        }
        var list = d.data.snapshots || [];
        snapshotCache[volume] = list;
        return list;
      });
  }

  function snapshotsLoading() {
    snapshotsEls.loading.classList.remove('d-none');
    snapshotsEls.error.classList.add('d-none');
    snapshotsEls.empty.classList.add('d-none');
    snapshotsEls.wrap.classList.add('d-none');
    snapshotsEls.error.textContent = '';
  }

  function snapshotsError(message) {
    snapshotsEls.loading.classList.add('d-none');
    snapshotsEls.empty.classList.add('d-none');
    snapshotsEls.wrap.classList.add('d-none');
    snapshotsEls.error.textContent = message;
    snapshotsEls.error.classList.remove('d-none');
  }

  function snapshotsRows(list) {
    return list.map(function (s) {
      var tags = (s.tags || []).map(function (t) {
        return '<span class="badge text-bg-light border me-1">' + esc(t) + '</span>';
      }).join('');
      return '<tr>' +
        '<td><span class="mono small">' + esc(s.id) + '</span></td>' +
        '<td class="small">' + esc(fmtTime(s.time)) + '</td>' +
        '<td class="text-end small">' + esc(fmtBytes(s.size)) + '</td>' +
        '<td class="small">' + (tags || '<span class="text-muted">' + EMPTY + '</span>') + '</td>' +
        '</tr>';
    }).join('');
  }

  function openSnapshots(volume) {
    snapshotsEls.volume.textContent = volume;
    openCachedSnapshots(volume);
    showModal(snapshotsEls.modal);
  }

  function openCachedSnapshots(volume) {
    snapshotsLoading();
    loadSnapshots(volume).then(function (list) {
      if (!list.length) {
        snapshotsEls.loading.classList.add('d-none');
        snapshotsEls.empty.classList.remove('d-none');
        return;
      }
      snapshotsEls.rows.innerHTML = snapshotsRows(list);
      snapshotsEls.loading.classList.add('d-none');
      snapshotsEls.wrap.classList.remove('d-none');
    }).catch(function (err) {
      snapshotsError((err && err.message) ? err.message : 'Gagal memuat snapshot.');
    });
  }

  // ---------------------------------------------------------------- restore

  function updateRestoreSubmit() {
    if (!restoreEls.submit) return;
    var ready = restoreEls.snapshot.value !== '' &&
      restoreEls.confirm.value === restoreEls.volume.value;
    restoreEls.submit.disabled = !ready;
  }

  function restoreError(message) {
    restoreEls.error.textContent = message;
    restoreEls.error.classList.remove('d-none');
  }

  function clearRestoreError() {
    restoreEls.error.textContent = '';
    restoreEls.error.classList.add('d-none');
  }

  function openRestore(volume) {
    restoreEls.volume.value = volume;
    restoreEls.volumeLabel.textContent = volume;
    restoreEls.confirmHint.textContent = volume;
    restoreEls.confirm.value = '';
    restoreEls.confirm.disabled = true;
    restoreEls.snapshot.disabled = true;
    restoreEls.snapshot.innerHTML = '<option value="">Memuat snapshot …</option>';
    restoreEls.snapshotNote.textContent = '';
    restoreEls.submit.disabled = true;
    clearRestoreError();
    showModal(restoreEls.modal);

    loadSnapshots(volume).then(function (list) {
      restoreEls.confirm.disabled = false;
      if (!list.length) {
        restoreEls.snapshot.innerHTML = '<option value="">Tidak ada snapshot</option>';
        restoreEls.snapshot.disabled = true;
        restoreEls.snapshotNote.textContent = 'Belum ada snapshot untuk volume ini — jalankan backup dulu.';
        return;
      }
      restoreEls.snapshot.innerHTML = list.map(function (s, i) {
        var label = (s.id || '?') + ' · ' + fmtTime(s.time) + (s.size ? ' · ' + fmtBytes(s.size) : '');
        return '<option value="' + esc(s.id) + '"' + (i === 0 ? ' selected' : '') + '>' + esc(label) + '</option>';
      }).join('');
      restoreEls.snapshot.disabled = false;
      restoreEls.snapshotNote.textContent = list.length + ' snapshot tersedia (terbaru di atas bila ada).';
      updateRestoreSubmit();
    }).catch(function (err) {
      restoreEls.snapshot.innerHTML = '<option value="">Gagal memuat</option>';
      restoreError((err && err.message) ? err.message : 'Gagal memuat snapshot.');
    });
  }

  // ---------------------------------------------------------------- events

  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () { load(); });
  }

  if (restoreEls.confirm) {
    restoreEls.confirm.addEventListener('input', updateRestoreSubmit);
  }
  if (restoreEls.snapshot) {
    restoreEls.snapshot.addEventListener('change', updateRestoreSubmit);
  }

  // Aksi baris (delegasi — tabel di-render ulang tiap poll).
  rowsEl.addEventListener('click', function (ev) {
    var target = ev.target;
    if (!target || typeof target.closest !== 'function') return;

    var runBtn = target.closest('.backup-run-btn');
    if (runBtn) {
      if (!ENABLED || !runForm) return;
      runVolumeInput.value = runBtn.getAttribute('data-volume') || '';
      runBtn.disabled = true;
      clearError();
      postForm(runForm).then(function (d) {
        runBtn.disabled = false;
        if (!d || d.code !== 0) {
          showError((d && d.msg) ? d.msg : 'Gagal menjalankan backup.');
          return;
        }
        footerEl.textContent = 'Backup dijalankan — status akan diperbarui otomatis …';
        delete snapshotCache[runVolumeInput.value];
        load();
      }).catch(function () {
        runBtn.disabled = false;
        showError('Gagal menghubungi server.');
      });
      return;
    }

    var snapBtn = target.closest('.backup-snapshots-btn');
    if (snapBtn) {
      openSnapshots(snapBtn.getAttribute('data-volume') || '');
      return;
    }

    var restoreBtn = target.closest('.backup-restore-btn');
    if (restoreBtn) {
      openRestore(restoreBtn.getAttribute('data-volume') || '');
    }
  });

  // Konfirmasi restore: POST lewat fetch agar pesan error in-modal (422/500).
  restoreEls.form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (restoreEls.submit.disabled) return;
    var volume = restoreEls.volume.value;
    restoreEls.submit.disabled = true;
    clearRestoreError();
    postForm(restoreEls.form).then(function (d) {
      if (!d || d.code !== 0) {
        restoreError((d && d.msg) ? d.msg : 'Restore gagal dijalankan.');
        updateRestoreSubmit();
        return;
      }
      delete snapshotCache[volume];
      hideModal(restoreEls.modal);
      footerEl.textContent = 'Restore dijalankan untuk ' + volume + ' — container dinyalakan kembali setelah selesai.';
      load();
    }).catch(function () {
      restoreError('Gagal menghubungi server.');
      updateRestoreSubmit();
    });
  });

  // Poll lambat: hanya hidup selama halaman terbuka.
  function startPolling() {
    if (INTERVAL <= 0 || timer) return;
    timer = setInterval(function () {
      if (!document.hidden) load();
    }, INTERVAL);
  }

  function stopPolling() {
    if (timer) { clearInterval(timer); timer = null; }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopPolling();
    } else {
      load();
      startPolling();
    }
  });
  window.addEventListener('pagehide', stopPolling);
  window.addEventListener('beforeunload', stopPolling);

  // Modal restore memuat snapshot saat dibuka; bersihkan cache saat ditutup agar
  // hitungan snapshot tetap segar tanpa memuat ulang halaman.
  if (restoreEls.modal) {
    restoreEls.modal.addEventListener('hidden.bs.modal', function () {
      restoreEls.confirm.value = '';
      restoreEls.submit.disabled = true;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    load();
    startPolling();
  });
})();
