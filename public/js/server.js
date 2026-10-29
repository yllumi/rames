/**
 * Halaman `/servers` (admin) — aksi per baris server:
 *   1. **Test connection** (manual) → `POST /servers/{id}/test`;
 *   2. **Perbarui agen** (manual, server agent saja) →
 *      `POST /servers/{id}/agent-update` body `_token` + `mode=update`;
 *   3. **Pemantauan job update agen** (otomatis, hanya setelah (2) sukses) →
 *      `GET /servers/{id}/agent-update/status?job=<job_id>&offset=<int>`.
 *
 * Kontrak yang dikonsumsi (jangan diubah dari sisi UI):
 *   POST /servers/{id}/test  →
 *     sukses : {code:0, server:{id,status,agent_version,capabilities,last_seen_at}}
 *     gagal  : {code:1, error:<pesan ramah>, reason:<AgentError::*>}
 *   Baris server `local` selalu menjawab `code:0` + `status:'online'` tanpa
 *   panggilan jaringan (dashboard ini sendiri).
 *
 *   POST /servers/{id}/agent-update →
 *     sukses : {code:0, msg, data:{job_id,server_id,mode,agent_version}}
 *     gagal  : {code:400, msg} (agen tidak kompatibel / baris lokal / mode salah)
 *              {code:1, error} (404: server tidak ditemukan / non-admin)
 *
 *   GET /servers/{id}/agent-update/status?job=<job_id>&offset=<int> (admin) →
 *     sukses : {code:0, data:{job_id,status,stage,message,error,finished_at,log,offset,reason?}}
 *              status: 'pending' | 'running' | 'done' | 'error' | 'unreachable'
 *              `log`    = potongan log BARU (baris lengkap) sejak `offset` yang dikirim;
 *              `offset` = offset lanjutan untuk polling berikutnya.
 *              `reason` (khusus `unreachable`): 'not_found' (rekaman job tidak ada lagi,
 *              mis. agen sudah di-recreate) | 'unreachable' | 'server_error' (transport).
 *     gagal  : {code:400, msg} (job tidak sah / server lokal / agen tidak bisa dipakai)
 *              {code:1, error} (404: non-admin / server tidak ada)
 *   Catatan: agent tidak terjangkau (galat transport/HTTP, mis. sedang restart saat
 *   `self_update`, atau rekaman job hilang setelah recreate) **bukan** 400 — dijawab
 *   **200** dengan `status:'unreachable'` (+ `message` penjelas). Endpoint GET ini
 *   tidak butuh token CSRF (hanya POST/PUT/DELETE).
 *
 * Catatan desain:
 *  - Test connection tetap **manual** (tanpa polling).
 *  - Setelah `POST …/agent-update` menjawab `code:0`, baris itu mulai memantau
 *    job lewat `GET …/status` tiap **2 detik** (satu polling per baris, tombol
 *    tetap nonaktif selama dipantau). `pending`/`running` menampilkan tahap,
 *    `done`/`error` adalah status **terminal** (polling berhenti). `log` yang
 *    bertambah dipertahankan 8 baris terakhir dan hanya ditulis ke atribut
 *    `title` (bukan HTML), supaya penolakan preflight agent terlihat di UI.
 *  - Batas pemantauan: **90 percobaan** (±3 menit) ⇒ pesan ⏱, dan **5
 *    kegagalan jaringan berturut-turut** ⇒ pesan ⚠. Galat 400/404 langsung
 *    menghentikan polling dan menampilkan `msg`/`error` dari server.
 *  - `status:'unreachable'` (agent restart saat update / rekaman job hilang) **bukan**
 *    galat: polling tetap lanjut dengan pesan ⏳. `reason:'not_found'` (job hilang,
 *    mis. agen sudah di-recreate) langsung memakai pesan tegas pada tick pertama;
 *    selain itu pesan pendek hingga **10 tick** berturut-turut, lalu ajakan Test connection.
 *  - Update agen memakai `window.confirm` (agen **restart**, job berjalan di
 *    server itu bisa terputus); verifikasi versi baru tetap lewat Test connection.
 *  - Tombol nonaktif tetap bisa dipaksa aktif dari DevTools; controller tetap
 *    fail-fast (404 non-admin, 400 lokal/tidak kompatibel) — tombol hanya UX.
 *  - Token CSRF diambil dari `meta[name="csrf-token"]` dan **tidak** pernah
 *    ditulis ke DOM/log; token/PEM agent tidak pernah masuk ke DOM dari sisi JS
 *    (halaman tidak merender nilainya).
 *  - Token dikirim di **body** (`_token`, form-urlencoded) karena
 *    `CsrfMiddleware` hanya membaca `$request->post('_token')` — pola yang sama
 *    dengan `public/js/update.js` / `template-pull.js` / `backup.js`. Header
 *    `X-CSRF-TOKEN` ikut dikirim sebagai sabuk pengaman bila middleware kelak
 *    juga menerima header; tanpa `_token` di body request akan ditolak 419.
 *  - Hampir semua teks ditulis lewat `textContent` (tanpa `innerHTML`) → tidak
 *    ada jalur injeksi dari pesan galat server; nama server (dari form admin)
 *    hanya dipakai di `window.confirm`, bukan sebagai HTML.
 *  - Pemetaan status → kelas badge di sini **sama** dengan
 *    `app/view/server/index.php` (jaga keduanya tetap sinkron).
 */
(function () {
  'use strict';

  var buttons = Array.prototype.slice.call(document.querySelectorAll('.js-server-test'));
  var updateButtons = Array.prototype.slice.call(document.querySelectorAll('.js-server-agent-update'));
  if (buttons.length === 0 && updateButtons.length === 0) return;

  var CSRF = '';
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) CSRF = meta.getAttribute('content') || '';

  var STATUS_BADGE = {
    online: ['badge-running', 'online'],
    offline: ['badge-error', 'offline'],
    unknown: ['badge-unknown', 'unknown']
  };

  /** Elemen ber-`data-<attr>="<id>"` milik satu server (tanpa selector berbasis id). */
  function target(attr, id) {
    var nodes = Array.prototype.slice.call(document.querySelectorAll('[' + attr + ']'));
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i].getAttribute(attr) === id) return nodes[i];
    }
    return null;
  }

  function setBusy(btn, busy) {
    btn.disabled = busy;
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    btn.textContent = busy ? 'Memeriksa…' : 'Test connection';
  }

  function setStatus(id, status) {
    var badge = target('data-server-status', id);
    if (!badge) return;
    var mapped = STATUS_BADGE[status] || STATUS_BADGE.unknown;
    badge.className = 'badge ' + mapped[0];
    badge.textContent = mapped[1];
  }

  function setResult(id, message, detail) {
    var box = target('data-server-result', id);
    if (!box) return;
    box.textContent = '';
    if (message) {
      var main = document.createElement('span');
      main.className = 'd-block';
      main.textContent = message;
      box.appendChild(main);
    }
    if (detail) {
      var note = document.createElement('span');
      note.className = 'd-block';
      note.textContent = detail;
      box.appendChild(note);
    }
  }

  /** Ringkasan sukses: versi agent + jumlah capability + kapan terakhir terlihat. */
  function successSummary(server) {
    var parts = [];
    var version = server && server.agent_version ? String(server.agent_version) : '';
    if (version) parts.push('agent ' + version);
    var caps = server && Array.isArray(server.capabilities) ? server.capabilities.length : 0;
    parts.push(caps + (caps === 1 ? ' capability' : ' capabilities'));
    var seen = server && server.last_seen_at ? String(server.last_seen_at) : '';
    if (seen) parts.push('terakhir ' + seen);

    return parts.join(' · ');
  }

  function render(id, payload) {
    var server = payload && payload.server ? payload.server : null;
    var status = server && server.status ? String(server.status) : 'unknown';
    var code = payload && typeof payload.code !== 'undefined' ? payload.code : null;

    if (code === 0) {
      setStatus(id, status);
      setResult(id, 'Terhubung ke agent.', successSummary(server));

      return;
    }

    setStatus(id, status === 'online' ? 'online' : 'offline');
    var error = payload && payload.error ? String(payload.error) : 'Gagal menghubungi agent.';
    var reason = payload && payload.reason ? 'kode: ' + String(payload.reason) : '';
    setResult(id, error, reason);
  }

  function failure(id) {
    setStatus(id, 'unknown');
    setResult(id, 'Gagal memanggil dashboard (respons bukan JSON) — muat ulang halaman lalu coba lagi.', '');
  }

  function test(btn) {
    var id = btn.getAttribute('data-id') || '';
    if (!id) return;

    setBusy(btn, true);
    setResult(id, 'Memeriksa…', '');

    window.fetch('/servers/' + encodeURIComponent(id) + '/test', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-CSRF-TOKEN': CSRF,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: new URLSearchParams({ _token: CSRF })
    }).then(function (response) {
      // Respons non-2xx (mis. 404 non-admin, 419 CSRF) tetap dibaca sebagai teks:
      // tidak ada sukses palsu, dan body HTML tidak pernah disisipkan ke DOM.
      return response.text();
    }).then(function (text) {
      var payload = null;
      try {
        payload = JSON.parse(text);
      } catch (e) {
        payload = null;
      }
      if (payload === null || typeof payload !== 'object') {
        failure(id);
      } else {
        render(id, payload);
      }
    }).catch(function () {
      failure(id);
    }).then(function () {
      setBusy(btn, false);
    });
  }

  buttons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      test(btn);
    });
  });

  // ------------------------------------------------------------------
  // Perbarui agen (server agent saja) — konfirmasi dulu, agen restart
  // ------------------------------------------------------------------

  var UPDATE_LABEL = '\u27f3 Perbarui agen';

  function setUpdateBusy(btn, busy) {
    btn.disabled = busy;
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    var spinner = btn.querySelector('.spinner-border');
    if (spinner) spinner.classList.toggle('d-none', !busy);
    var label = btn.querySelector('.js-agent-update-label');
    if (label) label.textContent = busy ? 'Memperbarui\u2026' : UPDATE_LABEL;
  }

  /**
   * Tulis pesan status ke `[data-server-update]` milik baris ini.
   * `message`/`lines` selalu lewat `textContent`/`setAttribute('title', …)` —
   * tidak ada `innerHTML`, jadi pesan (termasuk galat dari agent) tidak pernah
   * menjadi HTML. `tone`: 'muted' (berjalan) | 'error' | 'ok'.
   */
  function setUpdateMessage(id, message, lines, tone) {
    var box = target('data-server-update', id);
    if (!box) return;
    box.textContent = message ? String(message) : '';
    if (lines && lines.length > 0) {
      box.setAttribute('title', lines.join('\n'));
    } else {
      box.removeAttribute('title');
    }
    box.classList.remove('text-muted');
    box.classList.remove('text-danger');
    box.classList.remove('text-success');
    if (tone === 'error') box.classList.add('text-danger');
    else if (tone === 'ok') box.classList.add('text-success');
    else box.classList.add('text-muted');
  }

  function setUpdateResult(id, message, tone) {
    setUpdateMessage(id, message, null, tone || 'muted');
  }

  // ------------------------------------------------------------------
  // Pemantauan job update agen (`GET …/agent-update/status`)
  // ------------------------------------------------------------------

  var POLL_MS = 2000;             // jeda antar polling (kontrak: 2 detik)
  var POLL_MAX_ATTEMPTS = 90;     // ±3 menit; setelah itu berhenti dengan ⏱
  var POLL_MAX_FAILURES = 5;      // kegagalan jaringan berturut-turut sebelum menyerah
  var POLL_LOG_LINES = 8;         // baris log terakhir yang disimpan (di `title`)
  var POLL_ERROR_MAX = 300;       // potong pesan galat agar tetap terbaca di kolom
  var UNREACHABLE_NOTICE_AFTER = 10; // tick `unreachable` berturut-turut (~20 detik)

  var DONE_MESSAGE = '\u2714 Update agen selesai \u2014 jalankan Test connection untuk memverifikasi versi.';
  var TIMEOUT_MESSAGE = '\u23f1 Status tidak selesai dipantau \u2014 cek log agent '
    + '(runtime/logs/deploy/agent.log) atau Test connection.';
  // Agent restart di tengah update bukan galat: pesan tetap ⏳, hanya berganti
  // ajakan verifikasi setelah `UNREACHABLE_NOTICE_AFTER` tick berturut-turut.
  var UNREACHABLE_MESSAGE = '\u23f3 Agent tidak terjangkau \u2014 kemungkinan sedang restart. '
    + 'Bila berlanjut, jalankan Test connection untuk memverifikasi versi.';
  // Rekaman job tidak ada lagi di agen (mis. agen sudah di-recreate): tidak akan
  // muncul pada tick berikutnya, jadi pesannya tegas sejak tick pertama.
  var JOB_GONE_MESSAGE = '\u23f3 Job update tidak ditemukan di agen (kemungkinan agen sudah '
    + 'di-recreate). Jalankan Test connection untuk memverifikasi versi.';

  /**
   * State polling per baris server. Satu entri per baris = penjaga "satu job
   * dipantau per baris" (selain tombol yang tetap nonaktif selama proses).
   */
  var pollStates = [];

  function clip(text, max) {
    var value = text ? String(text) : '';
    if (value.length <= max) return value;
    return value.slice(0, max - 1) + '\u2026';
  }

  function pollOf(id) {
    for (var i = 0; i < pollStates.length; i++) {
      if (pollStates[i].id === id && !pollStates[i].stopped) return pollStates[i];
    }
    return null;
  }

  function schedulePoll(state, delay) {
    if (state.stopped || state.timer) return;
    state.timer = window.setTimeout(function () {
      state.timer = null;
      pollTick(state);
    }, typeof delay === 'number' ? delay : POLL_MS);
  }

  function stopPoll(state) {
    state.stopped = true;
    if (state.timer) {
      window.clearTimeout(state.timer);
      state.timer = null;
    }
    for (var i = 0; i < pollStates.length; i++) {
      if (pollStates[i] === state) {
        pollStates.splice(i, 1);
        break;
      }
    }
  }

  function stopAllPolls() {
    var states = pollStates.slice(0);
    for (var i = 0; i < states.length; i++) stopPoll(states[i]);
  }

  /** Kembalikan tombol ke keadaan semula (termasuk bila server merendernya nonaktif). */
  function releaseUpdate(btn, wasDisabled) {
    setUpdateBusy(btn, false);
    if (wasDisabled) btn.disabled = true;
  }

  /** Simpan potongan log baru (maks. 8 baris terakhir) + offset lanjutan. */
  function appendLogLines(state, data) {
    var chunk = data && typeof data.log === 'string' ? String(data.log) : '';
    if (chunk !== '') {
      var parts = chunk.split('\n');
      for (var i = 0; i < parts.length; i++) {
        var line = parts[i].replace(/\s+$/, '');
        if (line !== '') state.logLines.push(line);
      }
      if (state.logLines.length > POLL_LOG_LINES) {
        state.logLines = state.logLines.slice(state.logLines.length - POLL_LOG_LINES);
      }
    }
    var next = data ? data.offset : null;
    if (typeof next === 'number' && isFinite(next) && next >= 0) state.offset = next;
  }

  /** Label ringkas status berjalan: stage → message → default. */
  function runningLabel(data) {
    var stage = data && data.stage ? String(data.stage) : '';
    if (stage !== '') return stage;
    var message = data && data.message ? String(data.message) : '';
    return message !== '' ? message : 'Menjalankan update\u2026';
  }

  function finishPoll(state, message, tone) {
    stopPoll(state);
    setUpdateMessage(state.id, message, state.logLines, tone);
    releaseUpdate(state.btn, state.wasDisabled);
  }

  /** Terapkan `data` status: pending/running ⇒ lanjut, done/error ⇒ berhenti. */
  function applyStatus(state, data) {
    var status = data && data.status ? String(data.status) : '';
    var suffix = state.jobId ? ' #' + state.jobId : '';

    if (status === 'done') {
      finishPoll(state, DONE_MESSAGE, 'ok');
      return;
    }
    if (status === 'error') {
      var error = data && data.error ? String(data.error) : '';
      if (error === '' && data && data.message) error = String(data.message);
      finishPoll(state, '\u2716 ' + clip(error || 'Update agen gagal.', POLL_ERROR_MAX), 'error');
      return;
    }

    // Agent tak terjangkau (restart saat update / rekaman job hilang): **bukan**
    // galat — polling lanjut (tetap dihitung sebagai percobaan). Job yang
    // rekamannya hilang (`reason:'not_found'`) diberi pesan tegas sejak tick
    // pertama; transport murni baru dipertegas setelah 10 tick berturut-turut.
    if (status === 'unreachable') {
      state.unreachable += 1;
      var reason = data && data.reason ? String(data.reason) : '';
      var notice = data && data.message ? String(data.message) : '';
      var unreachableText;
      if (reason === 'not_found') {
        unreachableText = JOB_GONE_MESSAGE;
      } else if (state.unreachable >= UNREACHABLE_NOTICE_AFTER) {
        unreachableText = UNREACHABLE_MESSAGE;
      } else {
        unreachableText = '\u23f3 ' + (notice || 'Agent tidak terjangkau\u2026');
      }
      setUpdateMessage(state.id, unreachableText, state.logLines.slice(0), 'muted');
      schedulePoll(state, POLL_MS);
      return;
    }

    state.unreachable = 0;

    // pending / running (atau status tak dikenal: tetap dipantau sampai batas).
    var lines = state.logLines.slice(0);
    var stage = data && data.stage ? String(data.stage) : '';
    var note = data && data.message ? String(data.message) : '';
    if (note !== '' && stage !== '' && note !== stage) lines = [note].concat(lines);

    setUpdateMessage(state.id, '\u23f3 ' + runningLabel(data) + suffix, lines, 'muted');
    schedulePoll(state, POLL_MS);
  }

  function statusUrl(state) {
    return '/servers/' + encodeURIComponent(state.id) + '/agent-update/status'
      + '?job=' + encodeURIComponent(state.jobId)
      + '&offset=' + encodeURIComponent(String(state.offset));
  }

  /** Kegagalan jaringan/parse: toleran sampai 5 kali berturut-turut, tanpa spam. */
  function pollRetry(state) {
    state.failures += 1;
    state.unreachable = 0; // "berturut-turut" terputus oleh kegagalan jaringan
    if (state.failures >= POLL_MAX_FAILURES) {
      finishPoll(state, '\u26a0 Gagal memantau status update agen (' + POLL_MAX_FAILURES
        + ' kegagalan jaringan berturut-turut) \u2014 cek log agent '
        + '(runtime/logs/deploy/agent.log) atau Test connection.', 'error');
      return;
    }
    schedulePoll(state, POLL_MS);
  }

  function pollTick(state) {
    if (state.stopped) return;
    // Tab tersembunyi: lewati tick tanpa menghitung percobaan; dilanjutkan lagi
    // oleh listener `visibilitychange` di bawah.
    if (document.hidden) return;

    if (state.attempts >= POLL_MAX_ATTEMPTS) {
      finishPoll(state, TIMEOUT_MESSAGE, 'muted');
      return;
    }
    state.attempts += 1;

    window.fetch(statusUrl(state), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      }
    }).then(function (response) {
      // Body non-2xx (400/404) juga JSON: dibaca sebagai teks agar tidak ada
      // sukses palsu dan HTML tidak pernah masuk ke DOM.
      return response.text();
    }).then(function (text) {
      if (state.stopped) return;
      var payload = null;
      try {
        payload = JSON.parse(text);
      } catch (e) {
        payload = null;
      }
      if (payload === null || typeof payload !== 'object') {
        pollRetry(state);
        return;
      }
      if (payload.code === 0 && payload.data && typeof payload.data === 'object') {
        state.failures = 0;
        appendLogLines(state, payload.data);
        applyStatus(state, payload.data);
        return;
      }
      // 400 (job tidak sah / server lokal / agen tidak bisa dipakai) & 404
      // (non-admin / server tidak ada): berhenti dan tampilkan pesan milik server.
      // Agent tak terjangkau **bukan** 400 — datang sebagai 200 `unreachable`.
      var msg = payload.msg ? String(payload.msg) : '';
      if (msg === '' && payload.error) msg = String(payload.error);
      finishPoll(state, '\u2716 ' + clip(msg || 'Status update agen tidak dapat dipantau.', POLL_ERROR_MAX), 'error');
    }).catch(function () {
      if (state.stopped) return;
      pollRetry(state);
    });
  }

  /**
   * Mulai memantau satu job untuk satu baris. `true` = polling aktif (tombol
   * jangan dilepas), `false` = tidak ada job yang bisa dipantau.
   */
  function beginPoll(btn, id, jobId, wasDisabled) {
    if (pollOf(id)) return true; // sudah memantau baris ini — jangan dobel
    if (!jobId) return false;

    var state = {
      id: id,
      jobId: jobId,
      offset: 0,
      attempts: 0,
      failures: 0,
      unreachable: 0,
      logLines: [],
      timer: null,
      stopped: false,
      btn: btn,
      wasDisabled: wasDisabled
    };
    pollStates.push(state);
    setUpdateMessage(id, 'Update agen dimulai (job ' + jobId + ') \u2014 memantau status\u2026', null, 'muted');
    schedulePoll(state, POLL_MS);
    return true;
  }

  /** Susun pesan hasil dari berbagai bentuk respons controller (tanpa HTML). */
  function updateOutcome(payload) {
    var code = payload && typeof payload.code !== 'undefined' ? payload.code : null;

    if (code === 0) {
      var data = payload && payload.data ? payload.data : {};
      var job = data && data.job_id ? ' Job ' + String(data.job_id) + '.' : '';
      return {
        ok: true,
        message: (payload && payload.msg ? String(payload.msg) : 'Update agen dimulai.') + job
          + ' Jalankan Test connection setelah agen hidup kembali untuk memverifikasi versinya.'
      };
    }

    var error = payload && payload.error ? String(payload.error) : '';
    var message = payload && payload.msg ? String(payload.msg) : '';
    return { ok: false, message: message || error || 'Update agen ditolak.' };
  }

  function updateAgent(btn) {
    var id = btn.getAttribute('data-id') || '';
    if (!id) return;
    if (pollOf(id)) return; // satu polling per baris

    // Dicatat sebelum tombol dikunci: bila server merendernya nonaktif, keadaan
    // itu dipulihkan setelah pemantauan berhenti.
    var wasDisabled = btn.disabled;

    var name = btn.getAttribute('data-name') || id;
    var version = btn.getAttribute('data-version') || '';
    var detail = version ? ' (versi ' + version + ')' : '';
    if (!window.confirm('Perbarui agen di server "' + name + '"' + detail + '?\n\n'
        + 'Agen akan di-restart sehingga dashboard dan app di server itu tidak bisa dioperasikan '
        + 'beberapa saat, dan job yang sedang berjalan di sana bisa terputus.')) {
      return;
    }

    setUpdateBusy(btn, true);
    setUpdateResult(id, 'Meminta update agen\u2026', 'muted');

    var started = false;

    window.fetch('/servers/' + encodeURIComponent(id) + '/agent-update', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-CSRF-TOKEN': CSRF,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: new URLSearchParams({ _token: CSRF, mode: 'update' })
    }).then(function (response) {
      // Respons non-2xx (404 non-admin/tidak ada, 419 CSRF, 400 ditolak) tetap
      // dibaca sebagai teks: tidak ada sukses palsu dan HTML tidak pernah
      // disisipkan ke DOM.
      return response.text();
    }).then(function (text) {
      var payload = null;
      try {
        payload = JSON.parse(text);
      } catch (e) {
        payload = null;
      }
      if (payload === null || typeof payload !== 'object') {
        setUpdateResult(id, 'Gagal memanggil dashboard (respons bukan JSON) \u2014 muat ulang halaman lalu coba lagi.', 'error');
        return;
      }
      var outcome = updateOutcome(payload);
      if (!outcome.ok) {
        setUpdateResult(id, outcome.message, 'error');
        return;
      }
      setStatus(id, 'unknown');

      var data = payload && payload.data ? payload.data : {};
      var jobId = data && data.job_id ? String(data.job_id) : '';
      started = beginPoll(btn, id, jobId, wasDisabled);
      if (!started) {
        // Tidak ada `job_id`: jangan memantau, cukup tampilkan pesan controller.
        setUpdateResult(id, outcome.message, 'muted');
      }
    }).catch(function () {
      setUpdateResult(id, 'Gagal menghubungi dashboard.', 'error');
    }).then(function () {
      if (!started) releaseUpdate(btn, wasDisabled);
    });
  }

  updateButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      updateAgent(btn);
    });
  });

  // Polling hanya hidup selama halaman terbuka & terlihat: tab tersembunyi ⇒
  // timer dibuang (tick dilewati, bukan dihitung sebagai percobaan), kembali
  // terlihat ⇒ lanjut segera; halaman ditinggalkan ⇒ semua timer dibersihkan.
  document.addEventListener('visibilitychange', function () {
    for (var i = 0; i < pollStates.length; i++) {
      var state = pollStates[i];
      if (state.stopped) continue;
      if (document.hidden) {
        if (state.timer) {
          window.clearTimeout(state.timer);
          state.timer = null;
        }
      } else {
        schedulePoll(state, 0);
      }
    }
  });
  window.addEventListener('pagehide', stopAllPolls);
  window.addEventListener('beforeunload', stopAllPolls);
})();
