/**
 * Tab "Template" di halaman /nginx (menu Config) — tarik image template app
 * (`docker pull` di host) dengan progres + log live.
 *
 * Kontrak yang dikonsumsi (JANGAN diubah dari sisi UI):
 *   POST /nginx/templates/pull         body `_token` + `slug` (+ `server` hanya bila server agent) → {code:0|400|403,msg,data:{id,images}}
 *   GET  /nginx/templates/pull/status  (+ `?server=<id>` untuk server agent) → {code:0,msg,data:{running,run,log_tail,logs}}
 *   run = {id,slug,title,stage,message,result,error,started_at,finished_at,actor,images,results,log,pid,job}
 *
 * Multi-server: pemilih server (`#template-server-picker`, admin-only) hanya
 * mengubah **target** permintaan — status & aksi selalu memakai server terpilih.
 * Untuk server lokal tidak ada parameter/field `server` yang dikirim, jadi
 * perilakunya identik dengan sebelum fitur multi-server. Pemilihan server di
 * browser **bukan** otorisasi: controller tetap menolak non-admin (403/404).
 *
 * Semua aksi = POST + token CSRF dari `meta[name="csrf-token"]` (pola
 * `public/js/update.js`; middleware CSRF hanya membaca `_token` pada POST).
 * Selama pull berjalan status dipoll tiap 2 detik; timer dijeda saat tab tidak
 * terlihat dan dibersihkan saat halaman ditinggalkan, jadi tidak ada permintaan
 * latar belakang. Hasil akhir + log dirender di kotak status tanpa reload
 * halaman (daftar template tidak berubah karena pull tidak mengubah app).
 */
(function () {
  'use strict';

  var panel = document.getElementById('template-pull-panel');
  if (!panel) return;

  var CSRF = '';
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) CSRF = meta.getAttribute('content') || '';

  var buttons = Array.prototype.slice.call(document.querySelectorAll('.js-template-pull'));

  var flashEl = document.getElementById('template-pull-flash');
  var badgeEl = document.getElementById('template-pull-badge');
  var messageEl = document.getElementById('template-pull-message');
  var stageEl = document.getElementById('template-pull-stage');
  var metaEl = document.getElementById('template-pull-meta');
  var resultsEl = document.getElementById('template-pull-results');
  var errorEl = document.getElementById('template-pull-error');
  var logEl = document.getElementById('template-pull-log');
  var serverLabelEl = document.getElementById('template-pull-server');
  var serverNoteEl = document.getElementById('template-server-note');
  var pickerEl = document.getElementById('template-server-picker');
  var serverButtons = pickerEl ? Array.prototype.slice.call(pickerEl.querySelectorAll('.js-template-server')) : [];

  var POLL_MS = 2000;
  var STAGE_LABELS = {
    starting: 'Menyiapkan',
    pulling: 'Tarik image',
    finished: 'Selesai'
  };
  var RESULT_BADGES = {
    success: ['text-bg-success', 'Berhasil'],
    error: ['text-bg-danger', 'Gagal']
  };

  var timer = null;
  var running = panel.getAttribute('data-running') === '1';
  var activeBtn = null;

  // Server terpilih. Tanpa pemilih (instalasi satu server) nilainya selalu
  // "server lokal", jadi semua permintaan berjalan seperti sebelumnya.
  var selectedServerId = '';
  var selectedServerName = '';

  (function initServer() {
    var chosen = null;
    for (var i = 0; i < serverButtons.length; i++) {
      if (serverButtons[i].classList.contains('active')) { chosen = serverButtons[i]; break; }
    }
    if (!chosen && serverButtons.length) chosen = serverButtons[0];
    if (chosen) {
      selectedServerId = chosen.getAttribute('data-server-id') || '';
      selectedServerName = chosen.getAttribute('data-server-name') || selectedServerId;
    }
  })();

  /** Target server lokal = tanpa id / id `local` (kontrak controller). */
  function isLocalTarget() {
    return selectedServerId === '' || selectedServerId === 'local';
  }

  function targetLabel() {
    return isLocalTarget() ? 'server lokal (dashboard ini)' : 'server “' + selectedServerName + '”';
  }

  /** URL status untuk server terpilih (parameter `server` hanya untuk agent). */
  function statusUrl() {
    return isLocalTarget()
      ? '/nginx/templates/pull/status'
      : '/nginx/templates/pull/status?server=' + encodeURIComponent(selectedServerId);
  }

  function setServerLabel() {
    if (serverLabelEl) serverLabelEl.textContent = '\u2014 ' + targetLabel();
    if (serverNoteEl) {
      serverNoteEl.textContent = isLocalTarget()
        ? 'Tarik image dijalankan di host dashboard ini.'
        : 'Tarik image dijalankan di ' + targetLabel() + '.';
    }
    panel.setAttribute('data-server-local', isLocalTarget() ? '1' : '0');
    panel.setAttribute('data-server-id', isLocalTarget() ? '' : selectedServerId);
  }

  // ------------------------------------------------------------------
  // Notifikasi (selalu textContent — tidak ada HTML dari data server)
  // ------------------------------------------------------------------

  function notify(message, kind) {
    if (!flashEl) return;
    flashEl.textContent = '';
    if (!message) return;
    var box = document.createElement('div');
    box.className = 'alert alert-' + (kind || 'info') + ' mb-3 small';
    box.setAttribute('role', 'alert');
    box.textContent = message;
    flashEl.appendChild(box);
  }

  function clearNotify() {
    if (flashEl) flashEl.textContent = '';
  }

  // ------------------------------------------------------------------
  // Render kotak status
  // ------------------------------------------------------------------

  function setButtonsBusy(busy) {
    buttons.forEach(function (btn) {
      var spinner = btn.querySelector('.spinner-border');
      var invalid = btn.getAttribute('data-valid') === '0';
      btn.disabled = busy || invalid;
      if (spinner) spinner.classList.toggle('d-none', !(busy && btn === activeBtn));
    });
  }

  function badgeFor(value, label) {
    var badge = document.createElement('span');
    badge.className = 'badge ' + value;
    badge.textContent = label;
    return badge;
  }

  function imageRow(image, result) {
    var tr = document.createElement('tr');

    var tdImage = document.createElement('td');
    tdImage.className = 'mono small';
    tdImage.textContent = image;
    tr.appendChild(tdImage);

    var tdResult = document.createElement('td');
    if (!result) {
      tdResult.appendChild(badgeFor('text-bg-secondary', 'Menunggu'));
    } else if (result.ok === true) {
      tdResult.appendChild(badgeFor('text-bg-success', 'Berhasil'));
    } else if (result.ok === false) {
      tdResult.appendChild(badgeFor('text-bg-danger', 'Gagal'));
    } else {
      tdResult.appendChild(badgeFor('text-bg-secondary', '—'));
    }
    tr.appendChild(tdResult);

    var tdSeconds = document.createElement('td');
    tdSeconds.className = 'text-end small text-muted';
    tdSeconds.textContent = result && typeof result.seconds === 'number' ? result.seconds.toFixed(1) : '—';
    tr.appendChild(tdSeconds);

    var tdMessage = document.createElement('td');
    tdMessage.className = 'small text-muted';
    tdMessage.textContent = result && result.message ? String(result.message) : '';
    tr.appendChild(tdMessage);

    return tr;
  }

  function renderResults(run) {
    if (!resultsEl) return;

    var images = Array.isArray(run.images) ? run.images.filter(function (v) { return typeof v === 'string' && v !== ''; }) : [];
    var results = Array.isArray(run.results) ? run.results.filter(function (v) { return v && typeof v === 'object'; }) : [];

    var byImage = {};
    results.forEach(function (result) {
      if (typeof result.image === 'string' && result.image !== '') byImage[result.image] = result;
    });

    var rows = [];
    images.forEach(function (image) { rows.push([image, byImage[image] || null]); });
    results.forEach(function (result) {
      if (typeof result.image === 'string' && result.image !== '' && images.indexOf(result.image) === -1) {
        rows.push([result.image, result]);
      }
    });

    resultsEl.textContent = '';
    if (!rows.length) {
      var tr = document.createElement('tr');
      var td = document.createElement('td');
      td.colSpan = 4;
      td.className = 'text-muted small';
      td.textContent = 'Belum ada image yang ditarik.';
      tr.appendChild(td);
      resultsEl.appendChild(tr);
      return;
    }

    rows.forEach(function (row) { resultsEl.appendChild(imageRow(row[0], row[1])); });
  }

  function metaLine(run) {
    var parts = [];
    if (run.job) parts.push('Job ' + run.job);
    if (run.started_at) {
      var line = 'Run ' + (run.id || '') + ' oleh ' + (run.actor || '?') + ' (' + run.started_at
        + (run.finished_at ? ' → ' + run.finished_at : '') + ')';
      parts.push(run.title ? 'Template ' + run.title + ' — ' + line : line);
    }
    return parts.join(' · ');
  }

  function renderRun(data) {
    var run = (data && data.run) || {};
    var isRunning = !!(data && data.running);
    var slug = typeof run.slug === 'string' ? run.slug : '';

    running = isRunning;
    if (run.id || isRunning) panel.classList.remove('d-none');

    if (isRunning && !activeBtn && slug !== '') {
      for (var i = 0; i < buttons.length; i++) {
        if (buttons[i].getAttribute('data-slug') === slug) {
          activeBtn = buttons[i];
          break;
        }
      }
    }

    if (badgeEl) {
      var badge = RESULT_BADGES[run.result] || ['text-bg-info', isRunning ? 'Berjalan…' : 'Menunggu'];
      badgeEl.className = 'badge ' + badge[0];
      badgeEl.textContent = badge[1];
    }
    if (messageEl) messageEl.textContent = typeof run.message === 'string' ? run.message : '';
    if (stageEl) {
      var stage = STAGE_LABELS[run.stage] || run.stage || '';
      stageEl.textContent = stage ? 'Tahap: ' + stage : '';
    }
    if (metaEl) metaEl.textContent = metaLine(run);
    if (errorEl) {
      var error = typeof run.error === 'string' ? run.error : '';
      errorEl.textContent = error;
      errorEl.classList.toggle('d-none', error === '');
    }
    renderResults(run);

    if (logEl && typeof data.log_tail === 'string') {
      logEl.textContent = data.log_tail;
      logEl.scrollTop = logEl.scrollHeight;
    }

    if (isRunning) {
      setButtonsBusy(true);
      schedulePoll();
      return;
    }

    // Selesai: tombol hidup kembali, poll berhenti, hasil akhir + log tetap
    // tampil di kotak status (tanpa reload halaman).
    var finished = activeBtn !== null;
    activeBtn = null;
    setButtonsBusy(false);
    stopPolling();
    if (finished && run.result === 'error') {
      notify(run.error || run.message || 'Tarik image gagal — lihat log di bawah.', 'danger');
    } else if (finished && run.result === 'success') {
      notify('Image selesai ditarik — jalankan Deploy Ulang pada app yang memakai template ini agar container memakai image baru.', 'success');
    }
  }

  // ------------------------------------------------------------------
  // Polling (2 detik; jeda saat tab tersembunyi, bersih saat ditinggalkan)
  // ------------------------------------------------------------------

  function stopPolling() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
  }

  function schedulePoll() {
    if (timer) return;
    timer = setTimeout(function () {
      timer = null;
      poll();
    }, POLL_MS);
  }

  function poll() {
    if (document.hidden) return;
    fetch(statusUrl(), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) {
      return r.json();
    }).then(function (res) {
      if (!res || res.code !== 0 || !res.data) {
        stopPolling();
        running = false;
        activeBtn = null;
        setButtonsBusy(false);
        notify((res && res.msg) || ('Status tarik image di ' + targetLabel() + ' tidak dapat dibaca.'), 'danger');
        return;
      }
      renderRun(res.data);
    }).catch(function () {
      // Jaringan/dashboard sempat tidak menjawab — coba lagi selama pull jalan.
      schedulePoll();
    });
  }

  // ------------------------------------------------------------------
  // Pemilih server: ganti target panel + aksi (tanpa reload halaman)
  // ------------------------------------------------------------------

  /** Kosongkan panel saat pindah server — state run tidak boleh tercampur. */
  function resetPanel() {
    panel.classList.add('d-none');
    panel.setAttribute('data-run-id', '');
    if (badgeEl) {
      badgeEl.className = 'badge text-bg-info';
      badgeEl.textContent = 'Menunggu';
    }
    if (messageEl) messageEl.textContent = '';
    if (stageEl) stageEl.textContent = '';
    if (metaEl) metaEl.textContent = '';
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.classList.add('d-none');
    }
    if (logEl) logEl.textContent = '';
    renderResults({ images: [], results: [] });
  }

  function selectServer(btn) {
    var id = btn.getAttribute('data-server-id') || '';
    if (btn.classList.contains('active') && id === selectedServerId) return;

    selectedServerId = id;
    selectedServerName = btn.getAttribute('data-server-name') || id;

    serverButtons.forEach(function (candidate) {
      var on = candidate === btn;
      candidate.classList.toggle('active', on);
      candidate.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    stopPolling();
    running = false;
    activeBtn = null;
    setButtonsBusy(false);
    resetPanel();
    setServerLabel();
    clearNotify();
    notify('Menampilkan status tarik image di ' + targetLabel() + '…', 'info');
    // Status diambil dari server itu (agent bisa perlu beberapa saat menjawab);
    // panel muncul sendiri bila di sana ada run berjalan atau run terakhir.
    poll();
  }

  serverButtons.forEach(function (btn) {
    btn.addEventListener('click', function () { selectServer(btn); });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopPolling();
      return;
    }
    if (running || activeBtn) poll();
  });
  window.addEventListener('pagehide', stopPolling);
  window.addEventListener('beforeunload', stopPolling);

  // ------------------------------------------------------------------
  // Aksi: tarik image
  // ------------------------------------------------------------------

  function startPull(btn) {
    var slug = btn.getAttribute('data-slug') || '';
    if (slug === '' || running) return;

    var title = btn.getAttribute('data-title') || slug;
    activeBtn = btn;
    clearNotify();
    setButtonsBusy(true);
    notify('Menarik image template “' + title + '” di ' + targetLabel() + '…', 'info');

    // Field `server` hanya dikirim untuk server agent; server lokal tetap
    // memakai permintaan lama (`_token` + `slug`) tanpa perubahan apa pun.
    var body = new URLSearchParams({ _token: CSRF, slug: slug });
    if (!isLocalTarget()) body.set('server', selectedServerId);

    fetch('/nginx/templates/pull', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: body
    }).then(function (r) {
      return r.json().catch(function () {
        return { code: 400, msg: 'Respons server tidak valid.' };
      });
    }).then(function (res) {
      if (!res || res.code !== 0) {
        activeBtn = null;
        setButtonsBusy(false);
        notify((res && res.msg) || 'Gagal memulai tarik image.', 'danger');
        return;
      }
      running = true;
      panel.classList.remove('d-none');
      if (res.data && Array.isArray(res.data.images) && res.data.images.length > 0) {
        renderResults({ images: res.data.images, results: [] });
      }
      notify(res.msg || 'Tarik image dimulai…', 'info');
      poll();
    }).catch(function () {
      activeBtn = null;
      setButtonsBusy(false);
      notify('Gagal menghubungi server.', 'danger');
    });
  }

  buttons.forEach(function (btn) {
    btn.addEventListener('click', function () { startPull(btn); });
  });

  setServerLabel();

  if (running) {
    setButtonsBusy(true);
    poll();
  } else {
    setButtonsBusy(false);
  }
})();
