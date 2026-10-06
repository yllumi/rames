// Backup volume → S3 via restic — halaman /backups (PLAN_VOLUME_BACKUP.md §5.4).
//
// Halaman ini hanya merender kerangka; seluruh data volume + status datang dari
// `GET /api/backups/status` dan dimuat/di-refresh dari sini. Polling sengaja
// LAMBAT (>= 15 detik) karena satu run restic bisa panjang — interval dijeda saat
// tab tidak terlihat dan dimatikan saat halaman ditinggalkan (pagehide/
// beforeunload), jadi tidak ada permintaan latar belakang.
//
// Kontrak yang dikonsumsi (JANGAN diubah dari sisi UI):
//   GET  /api/backups/status                 → {code:0,data:{running,cached_at,status,volumes:[…]}}
//   POST /backups/refresh                    (form: _token) → bentuk sama seperti status
//   POST /backups/schedule                   (form: _token, volume, scheduled) — admin-only
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

  var metaCsrf = document.querySelector('meta[name="csrf-token"]');
  var CSRF = metaCsrf ? (metaCsrf.getAttribute('content') || '') : '';

  var rowsEl = document.getElementById('backup-rows');
  var footerEl = document.getElementById('backup-footer');
  var errorEl = document.getElementById('backup-error');
  var runningEl = document.getElementById('backup-running');
  var lastRunEl = document.getElementById('backup-last-run');
  var refreshBtn = document.getElementById('backup-refresh');
  var refreshStatusBtn = document.getElementById('backup-refresh-status');

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

  // Tab "Arsip" (admin-only): elemen ada hanya bila view dirender untuk admin.
  // Semua referensi dijaga null-check — non-admin menjalankan skrip yang sama.
  var archiveRowsEl = document.getElementById('backup-archive-rows');
  var archiveEmptyEl = document.getElementById('backup-archive-empty');
  var archiveCountEl = document.getElementById('archive-count');

  var archiveSnapshotsEls = {
    modal: document.getElementById('archive-snapshots-modal'),
    volume: document.getElementById('archive-snapshots-volume'),
    loading: document.getElementById('archive-snapshots-loading'),
    error: document.getElementById('archive-snapshots-error'),
    empty: document.getElementById('archive-snapshots-empty'),
    wrap: document.getElementById('archive-snapshots-table-wrap'),
    rows: document.getElementById('archive-snapshots-rows')
  };

  var archiveRestoreEls = {
    modal: document.getElementById('archive-restore-modal'),
    form: document.getElementById('archive-restore-form'),
    volume: document.getElementById('archive-restore-volume'),
    volumeLabel: document.getElementById('archive-restore-volume-label'),
    snapshot: document.getElementById('archive-restore-snapshot'),
    target: document.getElementById('archive-target-name'),
    error: document.getElementById('archive-restore-error'),
    submit: document.getElementById('archive-restore-submit')
  };

  // Modal detail error backup per volume (pesan panjang tidak dirender inline).
  var errorModalEls = {
    modal: document.getElementById('backup-error-modal'),
    volume: document.getElementById('backup-error-volume'),
    meta: document.getElementById('backup-error-meta'),
    message: document.getElementById('backup-error-message')
  };

  var toastContainer = document.getElementById('backup-toasts');

  var snapshotCache = {};
  // Peta pesan error terakhir per volume — dibangun saat render, TIDAK pernah
  // ditulis ke atribut HTML (pesan bisa panjang & berisi karakter sensitif).
  var lastErrorByVolume = {};
  // Peta baris arsip terakhir per nama volume (dipakai modal snapshot/restore).
  var archiveByVolume = {};
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

  // Notifikasi aksi (Backup/Restore) memakai Bootstrap toast. Pesan diset via
  // textContent (bukan innerHTML) agar aman-XSS; auto-dismiss & bisa ditumpuk.
  function toast(message, variant) {
    if (!toastContainer || typeof bootstrap === 'undefined' || !bootstrap.Toast) return;
    var v = variant || 'secondary';

    var el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + v + ' border-0';
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');

    var body = document.createElement('div');
    body.className = 'd-flex';

    var text = document.createElement('div');
    text.className = 'toast-body';
    text.textContent = String(message === null || message === undefined ? '' : message);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'Tutup');

    body.appendChild(text);
    body.appendChild(close);
    el.appendChild(body);

    el.addEventListener('hidden.bs.toast', function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    });

    toastContainer.appendChild(el);
    bootstrap.Toast.getOrCreateInstance(el, { delay: 6000 }).show();
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
      // Pesan lengkap TIDAK dirender inline — cukup tautan "Detail" ke modal.
      html += ' <button type="button" class="btn btn-link btn-sm p-0 backup-error-detail"' +
        ' data-volume="' + esc(row.name) + '" title="Lihat detail error">Detail</button>';
    }
    return html;
  }

  // Buka modal detail error untuk sebuah volume. Semua teks diisi via
  // `textContent` (bukan innerHTML) — pesan bisa berasal dari restic/Docker.
  function openErrorDetail(volume) {
    if (!errorModalEls.modal) return;
    var info = lastErrorByVolume[volume];
    if (!info) return;

    if (errorModalEls.volume) errorModalEls.volume.textContent = volume;
    if (errorModalEls.meta) {
      var parts = [];
      if (info.time) parts.push('waktu: ' + fmtTime(info.time));
      if (info.strategy) parts.push('strategi: ' + info.strategy);
      if (info.project) parts.push('project: ' + info.project);
      if (info.app_name) {
        parts.push('app: ' + info.app_name);
      } else if (info.orphaned) {
        parts.push('app: yatim (app pemilik sudah dihapus)');
      }
      errorModalEls.meta.textContent = parts.join(' · ');
    }
    if (errorModalEls.message) {
      errorModalEls.message.textContent = String(info.message === null || info.message === undefined ? '' : info.message);
    }
    showModal(errorModalEls.modal);
  }

  function actionsCell(row) {
    var name = esc(row.name);
    // Dropdown "Aksi" menampung ketiga aksi agar kolom tidak melebar. Kelas
    // `.backup-*-btn` + `data-volume` WAJIB tetap ada (delegasi klik bergantung
    // padanya). Biarkan default Popper Bootstrap (`boundary: "clippingParents"`)
    // agar menu tidak terpotong oleh scroll container `.table-responsive`: di BS
    // 5.3 `strategy` BUKAN opsi Dropdown (diabaikan) dan `boundary=viewport`
    // justru membiarkan menu keluar dari ancestor overflow.
    var html = '<div class="dropdown d-inline-block">' +
      '<button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle"' +
      ' data-bs-toggle="dropdown"' +
      ' aria-expanded="false" aria-label="Aksi untuk ' + name + '">Aksi</button>' +
      '<ul class="dropdown-menu dropdown-menu-end">';
    if (ENABLED) {
      html += '<li><button type="button" class="dropdown-item backup-run-btn" data-volume="' + name + '">Backup sekarang</button></li>';
    }
    html += '<li><button type="button" class="dropdown-item backup-snapshots-btn" data-volume="' + name + '">Snapshot</button></li>' +
      '<li><hr class="dropdown-divider"></li>' +
      '<li><button type="button" class="dropdown-item text-danger backup-restore-btn" data-volume="' + name + '">Restore</button></li>' +
      '</ul></div>';
    return html;
  }

  // Kolom "Berkala": admin = toggle interaktif; non-admin = badge read-only.
  // Default OFF bila field absen (volume yang belum pernah dibackup); server tetap
  // sumber kebenaran dan selalu mengirim `scheduled` per baris.
  function scheduleCell(row) {
    var scheduled = (row.scheduled === undefined || row.scheduled === null) ? false : !!row.scheduled;
    var name = esc(row.name);
    if (IS_ADMIN) {
      return '<div class="form-check form-switch mb-0">' +
        '<input type="checkbox" class="form-check-input backup-schedule-toggle" data-volume="' + name + '"' +
        (scheduled ? ' checked' : '') +
        ' aria-label="Backup berkala untuk ' + name + '"' +
        ' title="Ikut backup harian (berkala)">' +
        '</div>';
    }
    return scheduled
      ? '<span class="badge text-bg-success" title="Hanya admin yang dapat mengubah">on</span>'
      : '<span class="badge text-bg-secondary" title="Hanya admin yang dapat mengubah">off</span>';
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
      '<td>' + scheduleCell(row) + '</td>' +
      '<td class="text-end text-nowrap">' + actionsCell(row) + '</td>' +
      '</tr>';
  }

  // ---------------------------------------------------------------- arsip
  //
  // Tab "Arsip" (admin-only) menampilkan volume yang sudah tidak ada di Docker
  // tetapi masih punya riwayat backup. Aksi: lihat snapshot, restore ke volume
  // BARU, dan (untuk strategi dump) unduh berkas .sql. Server menolak non-admin
  // dengan 404 — UI hanya lapisan kedua.

  function archiveActionsCell(row) {
    var name = esc(row.name);
    var items = '';
    if (Number(row.snapshots) > 0) {
      items += '<li><button type="button" class="dropdown-item archive-snapshots-btn" data-volume="' + name + '">Lihat snapshot</button></li>';
    }
    if (row.restorable) {
      items += '<li><button type="button" class="dropdown-item archive-restore-btn" data-volume="' + name + '">Restore ke volume baru…</button></li>';
    }
    if (row.strategy === 'dump' && row.last_snapshot) {
      var sqlUrl = '/backups/archive/sql?volume=' + encodeURIComponent(row.name) +
        '&snapshot=' + encodeURIComponent(row.last_snapshot);
      items += '<li><a class="dropdown-item" rel="noopener" href="' + esc(sqlUrl) + '">Unduh SQL</a></li>';
    }
    if (items === '') {
      items = '<li><span class="dropdown-item-text text-muted small">Tidak ada aksi</span></li>';
    }

    return '<div class="dropdown d-inline-block">' +
      '<button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle"' +
      ' data-bs-toggle="dropdown" aria-expanded="false"' +
      ' aria-label="Aksi arsip untuk ' + name + '">Aksi</button>' +
      '<ul class="dropdown-menu dropdown-menu-end">' + items + '</ul></div>';
  }

  function archiveRowHtml(row) {
    var appLabel = row.app_name
      ? esc(row.app_name)
      : '<span class="text-muted">' + EMPTY + '</span>';
    var appId = row.app_id ? ' <span class="text-muted small mono">' + esc(row.app_id) + '</span>' : '';

    return '<tr>' +
      '<td><span class="mono">' + esc(row.name) + '</span></td>' +
      '<td><span class="mono small">' + esc(row.project) + '</span></td>' +
      '<td class="small">' + appLabel + appId + '</td>' +
      '<td>' + strategyCell(row) + '</td>' +
      '<td class="small">' + esc(fmtTime(row.last_backed_up_at)) + '</td>' +
      '<td class="text-end small">' + (row.snapshots ? Number(row.snapshots) : 0) + '</td>' +
      '<td class="text-end small">' + esc(fmtBytes(row.bytes)) + '</td>' +
      '<td class="text-end text-nowrap">' + archiveActionsCell(row) + '</td>' +
      '</tr>';
  }

  function renderArchive(archived) {
    archiveByVolume = {};
    archived.forEach(function (r) {
      if (r && r.name) archiveByVolume[r.name] = r;
    });

    if (archiveCountEl) {
      archiveCountEl.textContent = String(archived.length);
      archiveCountEl.classList.toggle('d-none', archived.length === 0);
    }

    if (!archiveRowsEl) return;
    // Seperti tabel aktif: jangan timpa baris saat dropdown "Aksi" sedang terbuka.
    if (archiveRowsEl.querySelector('.dropdown-menu.show') !== null) return;

    if (!archived.length) {
      archiveRowsEl.innerHTML = '';
      if (archiveEmptyEl) archiveEmptyEl.classList.remove('d-none');
      return;
    }
    if (archiveEmptyEl) archiveEmptyEl.classList.add('d-none');
    archiveRowsEl.innerHTML = archived.map(archiveRowHtml).join('');
  }

  function archiveSnapshotsLoading() {
    archiveSnapshotsEls.loading.classList.remove('d-none');
    archiveSnapshotsEls.error.classList.add('d-none');
    archiveSnapshotsEls.empty.classList.add('d-none');
    archiveSnapshotsEls.wrap.classList.add('d-none');
    archiveSnapshotsEls.error.textContent = '';
  }

  function archiveSnapshotsError(message) {
    archiveSnapshotsEls.loading.classList.add('d-none');
    archiveSnapshotsEls.empty.classList.add('d-none');
    archiveSnapshotsEls.wrap.classList.add('d-none');
    archiveSnapshotsEls.error.textContent = message;
    archiveSnapshotsEls.error.classList.remove('d-none');
  }

  function archiveSnapshotRow(snapshot, volume, strategy) {
    var tags = (snapshot.tags || []).map(function (t) {
      return '<span class="badge text-bg-light border me-1">' + esc(t) + '</span>';
    }).join('');
    var action = '<span class="text-muted">' + EMPTY + '</span>';
    if (strategy === 'dump') {
      var url = '/backups/archive/sql?volume=' + encodeURIComponent(volume) +
        '&snapshot=' + encodeURIComponent(snapshot.id);
      action = '<a class="btn btn-outline-secondary btn-sm" rel="noopener" href="' + esc(url) + '">Unduh SQL</a>';
    }
    return '<tr>' +
      '<td><span class="mono small">' + esc(snapshot.id) + '</span></td>' +
      '<td class="small">' + esc(fmtTime(snapshot.time)) + '</td>' +
      '<td class="text-end small">' + esc(fmtBytes(snapshot.size)) + '</td>' +
      '<td class="small">' + (tags || '<span class="text-muted">' + EMPTY + '</span>') + '</td>' +
      '<td class="text-end text-nowrap">' + action + '</td>' +
      '</tr>';
  }

  function loadArchiveSnapshots(volume) {
    return fetch('/api/backups/archive/snapshots?volume=' + encodeURIComponent(volume), {
      headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () {
        return { code: r.status || 400, msg: 'Respons server tidak valid.' };
      });
    }).then(function (d) {
      if (!d || d.code !== 0 || !d.data) {
        var msg = (d && d.code === 404)
          ? 'Hanya admin yang dapat mengakses arsip.'
          : ((d && d.msg) ? d.msg : 'Gagal memuat snapshot arsip.');
        throw new Error(msg);
      }
      return d.data.snapshots || [];
    });
  }

  function openArchiveSnapshots(volume) {
    if (!archiveSnapshotsEls.modal) return;
    var row = archiveByVolume[volume] || {};
    var strategy = row.strategy || '';

    archiveSnapshotsEls.volume.textContent = volume;
    archiveSnapshotsLoading();
    showModal(archiveSnapshotsEls.modal);

    loadArchiveSnapshots(volume).then(function (list) {
      if (!list.length) {
        archiveSnapshotsEls.loading.classList.add('d-none');
        archiveSnapshotsEls.empty.classList.remove('d-none');
        return;
      }
      archiveSnapshotsEls.rows.innerHTML = list.map(function (s) {
        return archiveSnapshotRow(s, volume, strategy);
      }).join('');
      archiveSnapshotsEls.loading.classList.add('d-none');
      archiveSnapshotsEls.wrap.classList.remove('d-none');
    }).catch(function (err) {
      archiveSnapshotsError((err && err.message) ? err.message : 'Gagal memuat snapshot arsip.');
    });
  }

  function updateArchiveSubmit() {
    if (!archiveRestoreEls.submit) return;
    var target = (archiveRestoreEls.target.value || '').trim();
    var valid = archiveRestoreEls.snapshot.value !== '' &&
      /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(target);
    archiveRestoreEls.submit.disabled = !valid;
  }

  function archiveRestoreError(message) {
    archiveRestoreEls.error.textContent = message;
    archiveRestoreEls.error.classList.remove('d-none');
  }

  function clearArchiveRestoreError() {
    archiveRestoreEls.error.textContent = '';
    archiveRestoreEls.error.classList.add('d-none');
  }

  function openArchiveRestore(volume) {
    if (!archiveRestoreEls.modal) return;
    var row = archiveByVolume[volume] || {};

    archiveRestoreEls.volume.value = volume;
    archiveRestoreEls.volumeLabel.textContent = volume;
    archiveRestoreEls.target.value = volume; // prefill nama asli
    archiveRestoreEls.snapshot.disabled = true;
    archiveRestoreEls.snapshot.innerHTML = '<option value="">Memuat snapshot …</option>';
    archiveRestoreEls.submit.disabled = true;
    clearArchiveRestoreError();
    showModal(archiveRestoreEls.modal);

    loadArchiveSnapshots(volume).then(function (list) {
      if (!list.length) {
        archiveRestoreEls.snapshot.innerHTML = '<option value="">Tidak ada snapshot</option>';
        archiveRestoreEls.snapshot.disabled = true;
        return;
      }
      var preferred = row.last_snapshot || '';
      archiveRestoreEls.snapshot.innerHTML = list.map(function (s) {
        var selected = preferred ? (s.id === preferred) : false;
        var label = (s.id || '?') + ' · ' + fmtTime(s.time) + (s.size ? ' · ' + fmtBytes(s.size) : '');
        return '<option value="' + esc(s.id) + '"' + (selected ? ' selected' : '') + '>' + esc(label) + '</option>';
      }).join('');
      archiveRestoreEls.snapshot.disabled = false;
      updateArchiveSubmit();
    }).catch(function (err) {
      archiveRestoreEls.snapshot.innerHTML = '<option value="">Gagal memuat</option>';
      archiveRestoreError((err && err.message) ? err.message : 'Gagal memuat snapshot arsip.');
    });
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
      // `data.status` dari server hanya berisi kunci aman (tanpa `error`); rincian
      // kegagalan per volume tampil di kolom "Backup terakhir".
      lastRunEl.textContent = 'Run terakhir ' + (ok ? 'berhasil' : 'bermasalah') +
        ' · pemicu ' + (st.trigger || '-') +
        ' · selesai ' + fmtTime(st.finished_at);
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

    // Bangun peta pesan error (hanya baris gagal yang punya pesan) sebelum HTML
    // tabel dibuat, agar tautan "Detail" punya data saat diklik.
    lastErrorByVolume = {};
    rows.forEach(function (r) {
      if (!r.last_ok && r.last_message) {
        lastErrorByVolume[r.name] = {
          message: r.last_message,
          time: r.last_run_at,
          strategy: r.strategy,
          project: r.project,
          app_name: r.app_name,
          orphaned: r.orphaned
        };
      }
    });

    // Jangan render ulang tabel saat ada dropdown "Aksi" terbuka: polling yang
    // menimpa innerHTML akan menghancurkan menu tepat saat user berinteraksi.
    // Status/footer tetap diperbarui; tabel menyusul pada poll berikutnya.
    var dropdownOpen = rowsEl.querySelector('.dropdown-menu.show') !== null;

    if (!dropdownOpen) {
      if (!rows.length) {
        var emptyMsg;
        if (!data.cached_at) {
          // Tombol "Segarkan status" admin-only — jangan arahkan non-admin ke tombol tersembunyi.
          emptyMsg = IS_ADMIN
            ? 'Belum ada data — klik <strong>Segarkan status</strong>.'
            : 'Belum ada data — status dimuat otomatis.';
        } else {
          emptyMsg = 'Tidak ada volume backup yang bisa Anda lihat.' +
            (IS_ADMIN ? '' : ' Anda hanya melihat volume app yang boleh Anda akses.');
        }
        rowsEl.innerHTML = '<tr><td colspan="9" class="text-muted small">' + emptyMsg + '</td></tr>';
      } else {
        rowsEl.innerHTML = rows.map(rowHtml).join('');
      }
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
    if (data.cached_at) {
      note += ' · data per ' + fmtTime(data.cached_at);
    } else {
      note += IS_ADMIN
        ? ' · Belum ada data cache — klik "Segarkan status".'
        : ' · Belum ada data cache.';
    }
    note += ' · otomatis tiap ' + Math.round(INTERVAL / 1000) + ' detik.';
    footerEl.textContent = note;

    renderArchive(Array.isArray(data.archived) ? data.archived : []);
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

  // Tombol "Segarkan status": hitung live (Engine + snapshot) lalu tulis cache.
  // Bisa lambat → tombol disabled + spinner selama proses.
  if (refreshStatusBtn) {
    refreshStatusBtn.addEventListener('click', function () {
      if (refreshStatusBtn.disabled) return;
      var original = refreshStatusBtn.textContent;
      refreshStatusBtn.disabled = true;
      refreshStatusBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Menyegarkan …';

      fetch('/backups/refresh', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ _token: CSRF })
      }).then(function (r) {
        return r.json().catch(function () {
          return { code: r.status || 400, msg: 'Respons server tidak valid.' };
        });
      }).then(function (d) {
        if (!d || d.code !== 0 || !d.data) {
          throw new Error((d && d.msg) ? d.msg : 'Gagal menyegarkan status.');
        }
        render(d.data);
        clearError();
        toast('Status disegarkan.', 'success');
      }).catch(function (err) {
        toast((err && err.message) ? err.message : 'Gagal menyegarkan status.', 'danger');
      }).then(function () {
        refreshStatusBtn.disabled = false;
        refreshStatusBtn.textContent = original;
      });
    });
  }

  // Toggle "Berkala" (delegasi — tabel di-render ulang tiap poll). Admin-only
  // di server; checkbox dinonaktifkan selama request untuk mencegah dobel klik.
  rowsEl.addEventListener('change', function (ev) {
    var target = ev.target;
    if (!target || typeof target.closest !== 'function') return;
    var toggle = target.closest('.backup-schedule-toggle');
    if (!toggle) return;

    var volume = toggle.getAttribute('data-volume') || '';
    var scheduled = !!toggle.checked;
    var previous = !scheduled;
    toggle.disabled = true;

    fetch('/backups/schedule', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({
        _token: CSRF,
        volume: volume,
        scheduled: scheduled ? '1' : '0'
      })
    }).then(function (r) {
      return r.json().catch(function () {
        return { code: r.status || 400, msg: 'Respons server tidak valid.' };
      });
    }).then(function (d) {
      if (!d || d.code !== 0) {
        var msg;
        if (d && d.code === 404) {
          msg = 'Hanya admin yang dapat mengubah pengaturan ini.';
        } else {
          msg = (d && d.msg) ? d.msg : 'Gagal menyimpan pengaturan berkala.';
        }
        toggle.checked = previous;
        toast(msg, 'danger');
        return;
      }
      var on = (d.data && d.data.scheduled !== undefined) ? !!d.data.scheduled : scheduled;
      toggle.checked = on;
      toast('Backup berkala ' + (on ? 'ON' : 'OFF') + ': ' + volume, 'success');
    }).catch(function () {
      toggle.checked = previous;
      toast('Gagal menghubungi server.', 'danger');
    }).then(function () {
      toggle.disabled = false;
    });
  });

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
          toast((d && d.msg) ? d.msg : 'Gagal menjalankan backup.', 'danger');
          return;
        }
        toast('Backup dijalankan — ' + runVolumeInput.value + '. Status diperbarui otomatis.', 'success');
        delete snapshotCache[runVolumeInput.value];
        load();
      }).catch(function () {
        runBtn.disabled = false;
        toast('Gagal menghubungi server.', 'danger');
      });
      return;
    }

    var snapBtn = target.closest('.backup-snapshots-btn');
    if (snapBtn) {
      openSnapshots(snapBtn.getAttribute('data-volume') || '');
      return;
    }

    var detailBtn = target.closest('.backup-error-detail');
    if (detailBtn) {
      ev.preventDefault();
      openErrorDetail(detailBtn.getAttribute('data-volume') || '');
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
      toast('Restore dijalankan untuk ' + volume + ' — container dinyalakan kembali setelah selesai.', 'success');
      load();
    }).catch(function () {
      restoreError('Gagal menghubungi server.');
      updateRestoreSubmit();
    });
  });

  // ------------------------------------------------------------ events arsip

  // Aksi baris arsip (delegasi — tabel di-render ulang tiap poll). Elemen hanya
  // ada untuk admin; non-admin melewati seluruh blok ini.
  if (archiveRowsEl) {
    archiveRowsEl.addEventListener('click', function (ev) {
      var target = ev.target;
      if (!target || typeof target.closest !== 'function') return;

      var snapBtn = target.closest('.archive-snapshots-btn');
      if (snapBtn) {
        openArchiveSnapshots(snapBtn.getAttribute('data-volume') || '');
        return;
      }
      var restoreBtn = target.closest('.archive-restore-btn');
      if (restoreBtn) {
        openArchiveRestore(restoreBtn.getAttribute('data-volume') || '');
      }
    });
  }

  if (archiveRestoreEls.target) {
    archiveRestoreEls.target.addEventListener('input', updateArchiveSubmit);
  }
  if (archiveRestoreEls.snapshot) {
    archiveRestoreEls.snapshot.addEventListener('change', updateArchiveSubmit);
  }

  // Submit restore arsip: POST lewat fetch agar pesan error tampil DI modal.
  if (archiveRestoreEls.form) {
    archiveRestoreEls.form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (archiveRestoreEls.submit.disabled) return;
      archiveRestoreEls.submit.disabled = true;
      clearArchiveRestoreError();

      postForm(archiveRestoreEls.form).then(function (d) {
        if (!d || d.code !== 0) {
          var msg;
          if (d && d.code === 404) {
            msg = 'Hanya admin yang dapat melakukan restore arsip.';
          } else {
            msg = (d && d.msg) ? d.msg : 'Restore arsip gagal dijalankan.';
          }
          archiveRestoreError(msg);
          toast(msg, 'danger');
          updateArchiveSubmit();
          return;
        }
        hideModal(archiveRestoreEls.modal);
        toast('Restore arsip dijalankan — volume baru akan dibuat.', 'success');
        load();
      }).catch(function () {
        archiveRestoreError('Gagal menghubungi server.');
        toast('Gagal menghubungi server.', 'danger');
        updateArchiveSubmit();
      });
    });
  }

  if (archiveRestoreEls.modal) {
    archiveRestoreEls.modal.addEventListener('hidden.bs.modal', function () {
      if (archiveRestoreEls.target) archiveRestoreEls.target.value = '';
      if (archiveRestoreEls.submit) archiveRestoreEls.submit.disabled = true;
      clearArchiveRestoreError();
    });
  }

  if (archiveSnapshotsEls.modal) {
    archiveSnapshotsEls.modal.addEventListener('hidden.bs.modal', function () {
      if (archiveSnapshotsEls.rows) archiveSnapshotsEls.rows.innerHTML = '';
    });
  }

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

  // Bersihkan isi modal detail error saat ditutup (kebersihan + hindari pesan
  // lama tampil sekilas saat modal dibuka berikutnya).
  if (errorModalEls.modal) {
    errorModalEls.modal.addEventListener('hidden.bs.modal', function () {
      if (errorModalEls.volume) errorModalEls.volume.textContent = '—';
      if (errorModalEls.meta) errorModalEls.meta.textContent = '';
      if (errorModalEls.message) errorModalEls.message.textContent = '';
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    load();
    startPolling();
  });
})();
