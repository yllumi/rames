/**
 * Halaman `/servers/provision` (admin) — kirim form provisioning + pantau job.
 *
 * Kontrak yang dikonsumsi (jangan diubah dari sisi UI):
 *   POST /servers/provision  (body urlencoded, `_token` ikut)
 *     sukses : {code:0, job_id, status_url}
 *     gagal  : {code:1, error}         (422 validasi, 500 job/kunci/spawn)
 *              404 kosong              (non-admin — route tidak "ada")
 *   GET  /servers/provision/status?job=<id>&offset=<int>
 *     sukses : {code:0, job_id, status, stage, message, error, server_id,
 *               created_at, started_at, finished_at, log, offset}
 *              status: 'queued' | 'running' | 'done' | 'error' | 'cancelled'
 *              `log`    = potongan log BARU (mulai `offset` yang dikirim);
 *              `offset` = offset lanjutan untuk polling berikutnya.
 *     gagal  : {code:1, error} (404: job tidak ada / non-admin)
 *   POST /servers/provision/cancel  (body `_token` + `job`)
 *     sukses : {code:0} — idempoten, termasuk untuk job yang sudah terminal.
 *
 * Catatan desain:
 *  - Body dikirim **urlencoded** (bukan multipart) supaya `$request->post()`
 *    Webman menerima `_token` & field teks tanpa jalur upload. Tidak ada kunci
 *    privat di form — panel `rames-agent-key` hanya menampilkan public key.
 *  - Polling tiap **2 detik**, berhenti pada status terminal
 *    (`done`/`error`/`cancelled`), maksimum **900 percobaan** (±30 menit), dan
 *    setelah **5 kegagalan jaringan** berturut-turut. Polling **dijeda** saat
 *    `document.hidden` dan dilanjutkan saat tab terlihat kembali; timer
 *    dibersihkan pada `pagehide`/`beforeunload` (pola `monitor.js`/`update.js`).
 *  - Semua teks dari server dimasukkan lewat `textContent` (tidak ada
 *    `innerHTML`), jadi pesan galat/Ansible tidak bisa menjadi HTML.
 *  - Token CSRF diambil dari `meta[name="csrf-token"]`; `_token` di body adalah
 *    satu-satunya yang dibaca `CsrfMiddleware` (header hanya sabuk pengaman).
 *  - Tombol Batal hanya lapisan kedua: controller tetap memvalidasi hak akses
 *    (404 non-admin) dan id job.
 */
(function () {
  'use strict';

  var form = document.getElementById('provision-form');
  if (!form) return;

  var panel = document.querySelector('[data-provision-panel]');
  var statusNode = document.querySelector('[data-provision-status]');
  var logNode = document.querySelector('[data-provision-log]');
  var jobNode = document.querySelector('[data-provision-job]');
  var cancelButton = document.querySelector('[data-provision-cancel]');
  var submitButton = form.querySelector('[data-provision-submit]');
  var spinner = form.querySelector('[data-provision-spinner]');
  var pubKeyField = document.querySelector('[data-provision-pubkey]');
  var keygenButton = document.querySelector('[data-provision-keygen]');
  var keygenLabel = document.querySelector('[data-provision-keygen-label]');
  var keyCopyButton = document.querySelector('[data-provision-keycopy]');
  var keyMessage = document.querySelector('[data-provision-keymsg]');
  var fingerprintNode = document.querySelector('[data-provision-fingerprint]');

  var CSRF = '';
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) CSRF = meta.getAttribute('content') || '';

  var POLL_INTERVAL = 2000;
  var MAX_ATTEMPTS = 900;
  var MAX_FAILURES = 5;
  var MAX_LOG_LINES = 200;

  var TERMINAL = { done: true, error: true, cancelled: true };

  var state = {
    jobId: '',
    offset: 0,
    timer: 0,
    attempts: 0,
    failures: 0,
    running: false,
    lines: []
  };

  function setStatus(text) {
    if (statusNode) statusNode.textContent = text;
  }

  function setJob(text) {
    if (jobNode) jobNode.textContent = text;
  }

  function setBusy(busy) {
    if (submitButton) submitButton.disabled = busy;
    if (spinner) spinner.classList.toggle('d-none', !busy);
  }

  function setCancelEnabled(enabled) {
    if (cancelButton) cancelButton.disabled = !enabled;
  }

  function appendLog(chunk) {
    if (!logNode || typeof chunk !== 'string' || chunk === '') return;

    var rows = chunk.split(/\r\n|\r|\n/);
    for (var i = 0; i < rows.length; i++) {
      if (rows[i] === '') continue;
      state.lines.push(rows[i]);
    }
    if (state.lines.length > MAX_LOG_LINES) {
      state.lines = state.lines.slice(state.lines.length - MAX_LOG_LINES);
    }

    logNode.textContent = state.lines.join('\n');
    logNode.scrollTop = logNode.scrollHeight;
  }

  function stop() {
    state.running = false;
    if (state.timer) {
      clearTimeout(state.timer);
      state.timer = 0;
    }
    setCancelEnabled(false);
  }

  function schedule(delay) {
    if (state.timer) clearTimeout(state.timer);
    if (!state.running) return;
    if (document.hidden) return; // dilanjutkan oleh listener visibilitychange
    state.timer = setTimeout(tick, delay);
  }

  function request(url, options) {
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () {
        return null;
      }).then(function (data) {
        return { response: response, data: data };
      });
    });
  }

  function describe(data, response) {
    var text = '';
    if (data && typeof data.error === 'string' && data.error !== '') text = data.error;
    if (text === '' && data && typeof data.msg === 'string') text = data.msg;
    if (text === '' && data && typeof data.message === 'string') text = data.message;
    if (text === '') text = 'Permintaan ditolak (HTTP ' + response.status + ').';

    return text;
  }

  function tick() {
    state.timer = 0;
    if (!state.running || !state.jobId) return;

    if (state.attempts >= MAX_ATTEMPTS) {
      setStatus('Batas pemantauan tercapai (±30 menit). Job mungkin masih berjalan — periksa log server.');
      stop();
      return;
    }
    state.attempts += 1;

    var url = '/servers/provision/status?job=' + encodeURIComponent(state.jobId)
      + '&offset=' + encodeURIComponent(String(state.offset));

    request(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (result) {
      if (!state.running) return;

      if (result.response.status === 404) {
        setStatus('Job provisioning tidak ditemukan (mungkin sudah dibersihkan).');
        stop();
        return;
      }

      if (!result.response.ok || !result.data) {
        throw new Error('HTTP ' + result.response.status);
      }

      var data = result.data;
      state.failures = 0;

      if (typeof data.log === 'string') appendLog(data.log);
      if (typeof data.offset === 'number' && data.offset >= 0) state.offset = data.offset;

      var stage = typeof data.stage === 'string' && data.stage !== '' ? data.stage : data.status;
      var message = typeof data.message === 'string' ? data.message : '';
      var error = typeof data.error === 'string' ? data.error : '';
      var status = typeof data.status === 'string' ? data.status : '';

      var text = '[' + stage + '] ' + (message !== '' ? message : status);
      if (error !== '') text += ' — ' + error;
      setStatus(text);
      setJob('Job ' + state.jobId + ' · percobaan ' + state.attempts);

      if (TERMINAL[status] === true) {
        stop();
        return;
      }

      schedule(POLL_INTERVAL);
    }).catch(function () {
      if (!state.running) return;

      state.failures += 1;
      if (state.failures >= MAX_FAILURES) {
        setStatus('Koneksi ke control plane bermasalah (' + state.failures + '× berturut-turut). '
          + 'Job mungkin masih berjalan — muat ulang halaman untuk memantau lagi.');
        stop();
        return;
      }

      setStatus('Gagal menghubungi control plane, mencoba lagi …');
      schedule(POLL_INTERVAL);
    });
  }

  function startJob(jobId) {
    state.jobId = String(jobId);
    state.offset = 0;
    state.attempts = 0;
    state.failures = 0;
    state.lines = [];
    state.running = true;

    if (logNode) logNode.textContent = '';
    if (panel) panel.hidden = false;
    setJob('Job ' + state.jobId);
    setStatus('Job dibuat — menunggu worker provisioning …');
    setCancelEnabled(true);

    schedule(0);
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (state.running) return;

    var body = new URLSearchParams();
    new FormData(form).forEach(function (value, key) {
      if (typeof value === 'string') body.append(key, value);
    });
    if (CSRF !== '') body.set('_token', CSRF);

    setBusy(true);

    request('/servers/provision', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-TOKEN': CSRF
      },
      body: body.toString()
    }).then(function (result) {
      setBusy(false);

      if (result.response.ok && result.data && result.data.code === 0 && result.data.job_id) {
        startJob(result.data.job_id);
        return;
      }

      if (panel) panel.hidden = false;
      setStatus(describe(result.data, result.response));
    }).catch(function () {
      setBusy(false);
      if (panel) panel.hidden = false;
      setStatus('Gagal menghubungi control plane. Periksa koneksi lalu coba lagi.');
    });
  });

  if (cancelButton) {
    cancelButton.addEventListener('click', function () {
      if (!state.jobId) return;

      var body = new URLSearchParams();
      body.set('_token', CSRF);
      body.set('job', state.jobId);

      cancelButton.disabled = true;
      setStatus('Meminta pembatalan …');

      request('/servers/provision/cancel', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-TOKEN': CSRF
        },
        body: body.toString()
      }).then(function (result) {
        if (result.response.ok && result.data && result.data.code === 0) {
          setStatus('Pembatalan dikirim — menunggu worker berhenti …');
          return;
        }

        setStatus(describe(result.data, result.response));
        setCancelEnabled(true);
      }).catch(function () {
        setStatus('Permintaan batal gagal dikirim. Coba lagi.');
        setCancelEnabled(true);
      });
    });
  }

  // ---------------------------------------------------------------------
  // Kunci provisioning bersama (`rames-agent-key`)
  // ---------------------------------------------------------------------
  function setKeyMessage(text, isError) {
    if (!keyMessage) return;
    keyMessage.textContent = text;
    keyMessage.className = 'small mt-2' + (isError ? ' text-danger' : ' text-muted');
  }

  function applyKey(data) {
    if (pubKeyField && typeof data.public_key === 'string') pubKeyField.value = data.public_key;
    if (fingerprintNode) fingerprintNode.textContent = (data.fingerprint || '—');
    if (keyCopyButton) keyCopyButton.disabled = !(data.public_key || '');
    if (keygenLabel) keygenLabel.textContent = 'Pastikan kunci ada';
  }

  if (keygenButton) {
    keygenButton.addEventListener('click', function () {
      var body = new URLSearchParams();
      if (CSRF !== '') body.set('_token', CSRF);

      keygenButton.disabled = true;
      setKeyMessage('Membuat/memeriksa kunci …', false);

      request('/servers/provision/keypair', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-TOKEN': CSRF
        },
        body: body.toString()
      }).then(function (result) {
        keygenButton.disabled = false;
        if (result.response.ok && result.data && result.data.code === 0) {
          applyKey(result.data);
          setKeyMessage(result.data.created
            ? 'Kunci dibuat. Salin public key di atas ke ~/.ssh/authorized_keys user SSH di server tujuan.'
            : 'Kunci sudah ada — tidak diubah. Public key di atas tetap berlaku.', false);
          return;
        }
        setKeyMessage(describe(result.data, result.response), true);
      }).catch(function () {
        keygenButton.disabled = false;
        setKeyMessage('Gagal menghubungi control plane. Coba lagi.', true);
      });
    });
  }

  if (keyCopyButton) {
    keyCopyButton.addEventListener('click', function () {
      if (!pubKeyField) return;
      var text = pubKeyField.value || '';

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          setKeyMessage('Public key disalin — tempel di server tujuan.', false);
        }).catch(function () {
          pubKeyField.select();
          setKeyMessage('Salin manual: teks sudah dipilih.', false);
        });
        return;
      }

      pubKeyField.select();
      setKeyMessage('Salin manual: teks sudah dipilih.', false);
    });
  }

  // ---------------------------------------------------------------------
  // Hapus riwayat job (gagal / selesai / semua terminal) + log worker-nya
  // ---------------------------------------------------------------------
  var historyMsg = document.querySelector('[data-provision-history-msg]');

  function setHistoryMessage(text, isError) {
    if (!historyMsg) return;
    historyMsg.textContent = text;
    historyMsg.className = 'card-body py-2' + (isError ? ' text-danger small' : ' text-muted small');
  }

  function postDelete(body) {
    if (CSRF !== '') body.set('_token', CSRF);

    return request('/servers/provision/jobs/delete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-TOKEN': CSRF
      },
      body: body.toString()
    });
  }

  document.querySelectorAll('[data-provision-delete]').forEach(function (button) {
    button.addEventListener('click', function () {
      var jobId = button.getAttribute('data-provision-delete') || '';
      if (!/^[A-Za-z0-9_-]{1,64}$/.test(jobId)) return;
      if (!window.confirm('Hapus job ' + jobId + ' beserta log-nya?')) return;

      var body = new URLSearchParams();
      body.set('job', jobId);
      button.disabled = true;

      postDelete(body).then(function (result) {
        if (result.response.ok && result.data && result.data.code === 0) {
          window.location.reload();
          return;
        }
        button.disabled = false;
        setHistoryMessage(describe(result.data, result.response), true);
      }).catch(function () {
        button.disabled = false;
        setHistoryMessage('Gagal menghubungi control plane. Coba lagi.', true);
      });
    });
  });

  document.querySelectorAll('[data-provision-purge]').forEach(function (button) {
    button.addEventListener('click', function () {
      var scope = button.getAttribute('data-provision-purge') || '';
      var label = scope === 'failed' ? 'semua job GAGAL (error/dibatalkan)'
        : (scope === 'done' ? 'semua job SELESAI (berhasil)' : 'SELURUH riwayat job terminal');
      if (!window.confirm('Hapus ' + label + ' beserta log-nya? Tindakan ini tidak bisa dibatalkan.')) return;

      var body = new URLSearchParams();
      body.set('scope', scope);
      button.disabled = true;

      postDelete(body).then(function (result) {
        if (result.response.ok && result.data && result.data.code === 0) {
          window.location.reload();
          return;
        }
        button.disabled = false;
        setHistoryMessage(describe(result.data, result.response), true);
      }).catch(function () {
        button.disabled = false;
        setHistoryMessage('Gagal menghubungi control plane. Coba lagi.', true);
      });
    });
  });

  // ---------------------------------------------------------------------
  // Lanjutkan pemantauan setelah reload / dari tautan `/servers/provision?job=`
  // ---------------------------------------------------------------------
  (function resumeJob() {
    var fromQuery = '';
    try {
      fromQuery = new URLSearchParams(window.location.search).get('job') || '';
    } catch (error) {
      fromQuery = '';
    }

    var fromServer = (form.getAttribute('data-provision-active-job') || '').trim();
    var jobId = /^[A-Za-z0-9_-]{1,64}$/.test(fromQuery) ? fromQuery
      : (/^[A-Za-z0-9_-]{1,64}$/.test(fromServer) ? fromServer : '');

    if (jobId !== '') startJob(jobId);
  }());

  document.addEventListener('visibilitychange', function () {
    if (!state.running) return;
    if (document.hidden) {
      if (state.timer) {
        clearTimeout(state.timer);
        state.timer = 0;
      }
      return;
    }
    schedule(0);
  });

  window.addEventListener('pagehide', stop);
  window.addEventListener('beforeunload', stop);
}());
