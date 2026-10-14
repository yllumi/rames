/**
 * Halaman `/servers` (admin) — aksi per baris server:
 *   1. **Test connection** (manual) → `POST /servers/{id}/test`;
 *   2. **Perbarui agen** (manual, server agent saja) →
 *      `POST /servers/{id}/agent-update` body `_token` + `mode=update`.
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
 * Catatan desain:
 *  - Aksi **manual** saja: tidak ada polling/timer, tidak ada permintaan latar
 *    belakang. Badge status & ringkasan hasil diperbarui dari respons.
 *  - Update agen memakai `window.confirm` (agen **restart**, job berjalan di
 *    server itu bisa terputus) lalu menampilkan `job_id` — verifikasi versi baru
 *    dilakukan lewat Test connection, bukan polling dari sini.
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

  function setUpdateResult(id, message) {
    var box = target('data-server-update', id);
    if (!box) return;
    box.textContent = message ? String(message) : '';
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

    var name = btn.getAttribute('data-name') || id;
    var version = btn.getAttribute('data-version') || '';
    var detail = version ? ' (versi ' + version + ')' : '';
    if (!window.confirm('Perbarui agen di server "' + name + '"' + detail + '?\n\n'
        + 'Agen akan di-restart sehingga dashboard dan app di server itu tidak bisa dioperasikan '
        + 'beberapa saat, dan job yang sedang berjalan di sana bisa terputus.')) {
      return;
    }

    setUpdateBusy(btn, true);
    setUpdateResult(id, 'Meminta update agen\u2026');

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
        setUpdateResult(id, 'Gagal memanggil dashboard (respons bukan JSON) — muat ulang halaman lalu coba lagi.');
        return;
      }
      var outcome = updateOutcome(payload);
      setUpdateResult(id, outcome.message);
      if (outcome.ok) setStatus(id, 'unknown');
    }).catch(function () {
      setUpdateResult(id, 'Gagal menghubungi dashboard.');
    }).then(function () {
      setUpdateBusy(btn, false);
    });
  }

  updateButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      updateAgent(btn);
    });
  });
})();
