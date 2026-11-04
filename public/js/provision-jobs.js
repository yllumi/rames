/**
 * Rames — daftar job provisioning di halaman `/servers`.
 *
 * Kenapa ada: job provisioning berjalan **sebelum** baris server dibuat, jadi
 * selama proses berlangsung server belum muncul di tabel. Tanpa panel ini,
 * menutup/refresh halaman Provision membuat progres "hilang" (job tetap jalan,
 * tapi tidak terlihat). Panel di sini memantau `GET /servers/provision/jobs`
 * sehingga status tetap terbaca dari daftar server.
 *
 * Kontrak respons (`ProvisionController::jobList()`):
 *   `{code:0, jobs:[{id,name,host,ssh_user,status,stage,message,error,
 *                    created_at,started_at,finished_at,server_id,can_cancel}]}`
 *
 * Aturan perilaku (sama dengan polling lain di repo):
 *  - jeda saat `document.hidden`, lanjut saat tab terlihat;
 *  - timer dibersihkan pada `pagehide`/`beforeunload`;
 *  - semua teks dari server dimasukkan lewat `textContent` (tanpa `innerHTML`
 *    dari data server), tautan dibentuk dari id yang sudah tervalidasi pola.
 */
(function () {
  'use strict';

  var section = document.querySelector('[data-provision-jobs]');
  var body = document.querySelector('[data-provision-jobs-body]');
  if (!section || !body) return;

  var POLL_INTERVAL = 3000;
  var MAX_ROWS = 5;
  var JOB_ID = /^[A-Za-z0-9_-]{1,64}$/;

  var state = { timer: 0, failures: 0 };

  var STATUS_BADGE = {
    queued: 'text-bg-secondary',
    running: 'text-bg-primary',
    done: 'text-bg-success',
    error: 'text-bg-danger',
    cancelled: 'text-bg-warning'
  };

  var STATUS_LABEL = {
    queued: 'menunggu',
    running: 'berjalan',
    done: 'selesai',
    error: 'gagal',
    cancelled: 'dibatalkan'
  };

  function cell(row, text, className) {
    var td = document.createElement('td');
    if (className) td.className = className;
    td.textContent = text;
    row.appendChild(td);

    return td;
  }

  function shortTime(value) {
    if (!value) return '—';
    var date = new Date(value);
    if (isNaN(date.getTime())) return String(value);

    return date.toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' });
  }

  function render(jobs) {
    body.replaceChildren();

    if (!jobs.length) {
      section.hidden = true;
      return;
    }

    jobs.slice(0, MAX_ROWS).forEach(function (job) {
      var status = typeof job.status === 'string' ? job.status : '';
      var row = document.createElement('tr');

      cell(row, (job.name || '') + ' → ' + (job.host || ''), 'mono small');
      cell(row, job.stage || status || '—', 'small');

      var badgeCell = document.createElement('td');
      var badge = document.createElement('span');
      badge.className = 'badge ' + (STATUS_BADGE[status] || 'text-bg-secondary');
      badge.textContent = STATUS_LABEL[status] || status || '—';
      badgeCell.appendChild(badge);
      row.appendChild(badgeCell);

      cell(row, job.error || job.message || '—', 'small text-muted');
      cell(row, shortTime(job.started_at || job.created_at), 'small text-muted');

      var actions = document.createElement('td');
      actions.className = 'text-end';
      if (typeof job.id === 'string' && JOB_ID.test(job.id)) {
        var link = document.createElement('a');
        link.className = 'btn btn-outline-primary btn-sm';
        link.href = '/servers/provision?job=' + encodeURIComponent(job.id);
        link.textContent = job.can_cancel ? 'Pantau' : 'Lihat log';
        actions.appendChild(link);
      }
      row.appendChild(actions);

      body.appendChild(row);
    });

    section.hidden = false;
  }

  function schedule(delay) {
    if (state.timer) clearTimeout(state.timer);
    state.timer = setTimeout(load, delay);
  }

  function load() {
    state.timer = 0;
    if (document.hidden) return;

    fetch('/servers/provision/jobs', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    }).then(function (data) {
      state.failures = 0;
      var jobs = data && Array.isArray(data.jobs) ? data.jobs : [];
      render(jobs);
      schedule(POLL_INTERVAL);
    }).catch(function () {
      state.failures += 1;
      // Setelah beberapa kegagalan, berhenti (halaman tetap berguna tanpa panel).
      if (state.failures < 5) schedule(POLL_INTERVAL);
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (state.timer) clearTimeout(state.timer);
      state.timer = 0;
      return;
    }
    schedule(0);
  });

  function stop() {
    if (state.timer) clearTimeout(state.timer);
    state.timer = 0;
  }

  window.addEventListener('pagehide', stop);
  window.addEventListener('beforeunload', stop);

  load();
}());
