/**
 * Tabel domain/SSL — aksi "Aktifkan SSL" / "↻ Retry SSL" sebagai **AJAX** +
 * polling status, menggantikan `window.location.reload()` tiap 5 detik
 * (docs/SPECS.md §8a/§8j). Dipakai halaman `/domains` (tab Semua Domain) dan
 * halaman lama `/ssl`.
 *
 * Kontrak yang dikonsumsi (JANGAN diubah dari sisi UI):
 *   POST /api/ssl/{id}/enable   body `_token` (+ `domain`) form-urlencoded →
 *     sukses           : {code:0, ok:true, status:'pending', domain, message}
 *     ditolak bisnis   : HTTP 200 {code:1, ok:false, error}
 *     tanpa hak/tak ada: HTTP 404 {code:404,...}
 *     CSRF kedaluwarsa : HTTP 419 {code:419,...}
 *   POST /api/domains/parked/{id}/enable — envelope sama (domain parkir).
 *   GET {data-ssl-status-url pada <table>} (default `/api/ssl/status`) →
 *     {code:0, ssl_supported:bool, pending:bool,
 *      rows:[{app_id,app_name,domain,is_custom,status,message,expires_at,error,
 *             error_summary,can_ssl,kind,role,ssl_queue_position,ssl_queue_size}]}
 *     `/api/domains/status` mengirim baris terdekorasi (`ssl_status`,
 *     `ssl_message`, `ssl_error`, `ssl_error_summary`, `ssl_expires_at`);
 *     keduanya diterima. `error_summary` = ringkasan pendek yang dihitung
 *     server (`SslErrorSummary`); bila tidak ada, teks galat dipakai apa adanya
 *     (galat jalur POST memang sudah pendek).
 *     `ssl_queue_position` (1-based) + `ssl_queue_size` (queued+running) hanya
 *     terisi saat baris `pending`; keduanya opsional → catatan jatuh ke
 *     `proses ...`.
 *     `status` ∈ disabled|pending|active|failed|expired; hanya baris yang boleh
 *     dilihat user yang dikembalikan (penolakan tetap 404).
 *
 * Catatan desain:
 *  - **Progressive enhancement**: form `method="post" action="/ssl/{id}/enable"`
 *    tetap dirender dan tetap bekerja tanpa JS; skrip ini hanya mencegat
 *    `submit` (`preventDefault`) lalu memakai `data-ssl-url` (endpoint JSON).
 *  - Respons selalu dibaca sebagai **teks** lalu `JSON.parse` di dalam
 *    `try/catch`: respons non-2xx / bukan JSON tidak pernah jadi "sukses palsu",
 *    dan HTML tidak pernah disisipkan ke DOM (berkas ini **tanpa** `innerHTML`).
 *  - CSRF diambil dari `meta[name="csrf-token"]` dan dikirim di **body**
 *    (`_token`) karena `CsrfMiddleware` hanya membaca `$request->post('_token')`;
 *    header `X-CSRF-TOKEN` ikut sebagai sabuk pengaman. Token tidak pernah
 *    ditulis ke DOM/log.
 *  - Otorisasi tetap milik server: 404 hanya ditampilkan sebagai galat generik
 *    (tidak ada pesan "tidak punya akses" di klien), dan tombol yang tampil
 *    hanya UX — controller tetap menolak.
 *  - Polling 5 detik **hanya** selama ada baris berstatus `pending`: dijeda saat
 *    tab tidak terlihat (`visibilitychange`), dibersihkan saat halaman
 *    ditinggalkan (`pagehide`/`beforeunload` + `AbortController.abort()`) →
 *    tidak ada permintaan latar belakang.
 *  - Peta status → kelas badge dibaca dari `data-ssl-badge-map` pada `<table>`
 *    (satu sumber dengan view, tidak diduplikasi di JS).
 *  - Baris dipetakan lewat `data-domain-key` (`kind:id:role`) bila ada — dua
 *    baris app yang sama (custom `serve` + subdomain `redirect`) dipetakan
 *    terpisah; fallback `data-app-id` ↔ `app_id` tetap ada untuk `/ssl` lama.
 *    State form/catatan mengikuti `can_ssl` (payload) + `ssl_supported` (tabel),
 *    dengan urutan cabang yang sama seperti render awal view: catatan `pending`/
 *    `active` menang atas form/penanda; galat hanya tampil bila `can_ssl` **dan**
 *    `ssl_supported` **dan** status `failed`.
 *    Baris yang dirender tanpa form (tanpa ability `ssl` sejak awal) tidak akan
 *    mendapat form walau `can_ssl` berubah di sesi yang sama — muat ulang halaman
 *    untuk memunculkannya (aksi tetap ditentukan server).
 *  - Aturan tampil galat: `can_ssl` **dan** `ssl_supported` **dan** status `failed`
 *    **dan** teks galat tidak kosong — sama dengan kondisi render awal di view,
 *    jadi tidak ada kedip saat polling pertama.
 *  - Kolom Aksi hanya memuat **ringkasan** galat; teks utuh certbot disimpan di
 *    `[data-ssl-error-full]` baris dan dibuka lewat tombol `[data-ssl-error-log]`
 *    ke modal `[data-ssl-error-modal]` (isi via `textContent`).
 */
(function () {
  'use strict';

  var rows = Array.prototype.slice.call(document.querySelectorAll('[data-ssl-row]'));
  if (rows.length === 0) return;

  var table = document.querySelector('table[data-ssl-badge-map]');

  var CSRF = '';
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) CSRF = meta.getAttribute('content') || '';

  // Endpoint status dibaca dari tabel (`/api/domains/status` di halaman
  // `/domains`, `/api/ssl/status` di halaman lama `/ssl`).
  var STATUS_URL = '/api/ssl/status';
  if (table) {
    var statusUrl = (table.getAttribute('data-ssl-status-url') || '').trim();
    if (statusUrl.charAt(0) === '/') STATUS_URL = statusUrl;
  }
  var POLL_MS = 5000;
  var FALLBACK_BADGE = 'badge-stopped';
  var LABEL_ENABLE = 'Aktifkan SSL';
  var LABEL_RETRY = '\u21bb Retry SSL';
  var MSG_UNREACHABLE = 'Gagal menghubungi server. Coba muat ulang halaman.';
  var MSG_EXPIRED = 'Sesi kedaluwarsa \u2014 muat ulang halaman lalu coba lagi.';

  /** Peta status → kelas badge dari view (`data-ssl-badge-map`); tanpa duplikasi di sini. */
  var BADGES = {};
  if (table) {
    try {
      var parsed = JSON.parse(table.getAttribute('data-ssl-badge-map') || '{}');
      if (parsed && typeof parsed === 'object') BADGES = parsed;
    } catch (err) {
      BADGES = {};
    }
  }

  var timer = null;          // setTimeout polling
  var inFlight = false;      // permintaan status sedang berjalan
  var abortHandle = null;    // AbortController permintaan status berjalan
  var stopped = false;       // polling dimatikan (tab disembunyikan / halaman ditinggalkan)

  // ------------------------------------------------------------------
  // Util (null-check tiap elemen; teks selalu lewat textContent)
  // ------------------------------------------------------------------

  function rowOf(node) {
    return node && node.closest ? node.closest('[data-ssl-row]') : null;
  }

  function statusOf(row) {
    return row.getAttribute('data-ssl-status') || '';
  }

  function canSslOf(row) {
    return row.getAttribute('data-ssl-can') === '1';
  }

  function sslSupported() {
    return !table || table.getAttribute('data-ssl-supported') !== '0';
  }

  function show(node, visible) {
    if (node) node.classList.toggle('d-none', !visible);
  }

  function hasPending() {
    return rows.some(function (row) { return statusOf(row) === 'pending'; });
  }

  /** Kelas badge untuk sebuah status: hanya token CSS biasa yang pernah masuk DOM. */
  function badgeClass(status) {
    var cls = Object.prototype.hasOwnProperty.call(BADGES, status)
      ? String(BADGES[status])
      : FALLBACK_BADGE;
    return /^[A-Za-z0-9_-]+$/.test(cls) ? cls : FALLBACK_BADGE;
  }

  // ------------------------------------------------------------------
  // Render satu baris
  // ------------------------------------------------------------------

  /**
   * Busy/istirahat tombol satu baris. Saat istirahat, label mengikuti status
   * baris (`failed` ⇒ "Retry") — sama dengan render awal view.
   * `data-ssl-busy` dipakai supaya respons poll yang datang di tengah request
   * tidak mengaktifkan kembali tombol yang sedang menunggu jawaban.
   */
  function setButtonBusy(row, busy) {
    var form = row.querySelector('[data-ssl-form]');
    if (!form) return;
    var btn = form.querySelector('[data-ssl-submit]');
    form.setAttribute('data-ssl-busy', busy ? '1' : '0');
    if (!btn) return;

    btn.disabled = busy;
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    var retry = statusOf(row) === 'failed';
    btn.textContent = busy ? 'Memproses\u2026' : (retry ? LABEL_RETRY : LABEL_ENABLE);
    btn.classList.toggle('btn-outline-danger', retry);
    btn.classList.toggle('btn-primary', !retry);
  }

  /**
   * Tampilkan galat baris: kolom Aksi memakai **ringkasan** pendek, teks utuh
   * disimpan untuk modal Log. `summary` kosong/tidak dikirim ⇒ teks galat
   * dipakai apa adanya (jalur POST hanya menghasilkan pesan pendek).
   */
  function setRowError(row, message, summary) {
    var box = row.querySelector('[data-ssl-error]');
    var full = row.querySelector('[data-ssl-error-full]');
    var logBtn = row.querySelector('[data-ssl-error-log]');
    var short = typeof summary === 'string' && summary !== '' ? summary : message;

    if (full) full.textContent = message;
    if (box) {
      box.textContent = short;
      show(box, short !== '' && canSslOf(row));
    }
    show(logBtn, message !== '' && canSslOf(row));
  }

  var errorModal = document.querySelector('[data-ssl-error-modal]');

  /**
   * Buka modal detail galat satu baris. Teks utuh dibaca dari
   * `[data-ssl-error-full]` dan ditulis via `textContent` (tanpa HTML).
   * Tanpa Bootstrap (aset gagal termuat) modal tidak dibuka — tombol Log hanya
   * pelengkap; status & aksi SSL tetap berfungsi.
   */
  function openErrorLog(row) {
    if (!row || !errorModal) return;
    var full = row.querySelector('[data-ssl-error-full]');
    var text = full ? full.textContent : '';
    if (text === '') return;

    var domainEl = errorModal.querySelector('[data-ssl-error-modal-domain]');
    var textEl = errorModal.querySelector('[data-ssl-error-modal-text]');
    if (domainEl) domainEl.textContent = row.getAttribute('data-domain') || '\u2014';
    if (textEl) textEl.textContent = text;

    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(errorModal).show();
    }
  }

  function isBusy(row) {
    var form = row.querySelector('[data-ssl-form]');
    return form !== null && form.getAttribute('data-ssl-busy') === '1';
  }

  /** Nota baris `pending`: posisi antrean bila kedua angka > 0, selain itu `proses ...`. */
  var NOTE_PENDING = 'proses ...';

  /** Angka dari payload (int) → number, selain itu `null`. */
  function queueNumber(value) {
    return typeof value === 'number' && isFinite(value) ? value : null;
  }

  function pendingNote(position, size) {
    var pos = typeof position === 'number' ? position : 0;
    var total = typeof size === 'number' ? size : 0;

    return pos > 0 && total > 0 ? 'antrean #' + pos + ' dari ' + total : NOTE_PENDING;
  }

  /**
   * Terapkan status satu baris: badge, pesan, kedaluwarsa, galat, lalu state
   * form/catatan.
   *
   * `opts` = {message, error, expires, canSsl, sslSupported, queuePosition,
   * queueSize}; `canSsl`/`sslSupported` boleh `undefined` ⇒ pakai nilai yang
   * sudah ada di DOM (dipakai saat transisi optimistis ke `pending` setelah POST
   * sukses). Field `message`/`error`/`expires` hanya disentuh bila dikirim
   * sebagai string; `queuePosition`/`queueSize` (number|null) hanya dipakai
   * untuk teks catatan `pending` — selalu `textContent`, tanpa HTML.
   */
  function setRow(row, status, opts) {
    opts = opts || {};
    var canSsl = typeof opts.canSsl === 'boolean' ? opts.canSsl : canSslOf(row);
    var supported = typeof opts.sslSupported === 'boolean' ? opts.sslSupported : sslSupported();

    row.setAttribute('data-ssl-status', status);
    row.setAttribute('data-ssl-can', canSsl ? '1' : '0');

    var badge = row.querySelector('[data-ssl-badge]');
    if (badge) {
      // Buang kelas `badge-*` lama, tambahkan yang baru dari peta view.
      var kept = String(badge.className || '').split(/\s+/).filter(function (cls) {
        return cls !== '' && cls.indexOf('badge-') !== 0;
      });
      kept.push(badgeClass(status));
      badge.className = kept.join(' ');
      badge.textContent = status;
    }

    var message = row.querySelector('[data-ssl-message]');
    if (message && typeof opts.message === 'string') {
      message.textContent = opts.message;
      show(message, opts.message !== '');
    }

    var expires = row.querySelector('[data-ssl-expires]');
    if (expires && typeof opts.expires === 'string') {
      expires.textContent = opts.expires !== '' ? opts.expires : '-';
      expires.classList.toggle('text-muted', opts.expires === '');
    }

    var errBox = row.querySelector('[data-ssl-error]');
    if (errBox && typeof opts.error === 'string') {
      setRowError(
        row,
        opts.error,
        typeof opts.errorSummary === 'string' ? opts.errorSummary : ''
      );
    }

    // Catatan status non-form (teks tetap dari view). Catatan menang atas
    // form/penanda — sama dengan urutan cabang render awal view.
    var noteState = status === 'pending' || status === 'active';
    var notePending = row.querySelector('[data-ssl-note="pending"]');
    if (notePending && status === 'pending') {
      // Posisi antrean (bila ada) menggantikan teks `proses ...`; tetap textContent.
      notePending.textContent = pendingNote(opts.queuePosition, opts.queueSize);
    }
    show(notePending, status === 'pending');
    show(row.querySelector('[data-ssl-note="active"]'), status === 'active');

    // Form: hanya bila boleh (`can_ssl`) & didukung, dan status bukan pending/active.
    var form = row.querySelector('[data-ssl-form]');
    if (form) {
      var formVisible = canSsl && supported && !noteState;
      show(form, formVisible);
      if (!formVisible || form.getAttribute('data-ssl-busy') !== '1') setButtonBusy(row, false);
    }

    // Penanda non-form: "tanpa hak" (ability) dan "—" (SSL tak didukung).
    show(row.querySelector('[data-ssl-nohak="ability"]'), !canSsl && !noteState);
    show(row.querySelector('[data-ssl-nohak="unsupported"]'), canSsl && !supported && !noteState);

    if (errBox) {
      show(errBox, canSsl && supported && status === 'failed' && errBox.textContent !== '');
      var fullEl = row.querySelector('[data-ssl-error-full]');
      show(
        row.querySelector('[data-ssl-error-log]'),
        canSsl && supported && status === 'failed' && fullEl !== null && fullEl.textContent !== ''
      );
    }
  }

  // ------------------------------------------------------------------
  // Polling status (5 detik; hanya selama ada baris `pending`)
  // ------------------------------------------------------------------

  function schedulePoll() {
    if (stopped || timer || document.hidden || !hasPending()) return;
    timer = setTimeout(function () {
      timer = null;
      poll();
    }, POLL_MS);
  }

  function stopPolling() {
    stopped = true;
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    if (abortHandle) {
      try { abortHandle.abort(); } catch (err) { /* sudah selesai */ }
      abortHandle = null;
    }
  }

  /** Mulai (atau lanjutkan) polling; permintaan pertama langsung dijalankan. */
  function startPolling() {
    stopped = false;
    if (document.hidden) return;
    if (inFlight) {
      schedulePoll();
      return;
    }
    if (timer) return;
    poll();
  }

  /**
   * Refresh status **sekarang** (sekali), walau sedang ada timer poll terjadwal —
   * dipakai setelah penolakan bisnis agar baris menyinkronkan diri dengan server
   * tanpa menunggu interval 5 detik.
   */
  function refreshStatus() {
    stopped = false;
    if (document.hidden) return;
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    if (!inFlight) poll();
  }

  function poll() {
    if (stopped || document.hidden || inFlight) return;

    inFlight = true;
    abortHandle = typeof AbortController === 'function' ? new AbortController() : null;

    window.fetch(STATUS_URL, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      signal: abortHandle ? abortHandle.signal : undefined
    }).then(function (response) {
      // Dibaca sebagai teks: respons non-2xx / HTML tidak pernah diparse paksa.
      return response.text();
    }).then(function (text) {
      var payload = null;
      try {
        payload = JSON.parse(text);
      } catch (err) {
        payload = null;
      }
      if (payload && typeof payload === 'object' && payload.code === 0 && Array.isArray(payload.rows)) {
        applyStatus(payload);
      }
    }).catch(function () {
      // Jaringan/dashboard tidak menjawab: biarkan — polling tetap hidup selama
      // masih ada baris `pending`.
    }).then(function () {
      inFlight = false;
      abortHandle = null;
      schedulePoll();
    });
  }

  /**
   * Terapkan seluruh baris dari endpoint status (baris tak dikenal dilewati).
   *
   * Pemetaan baris:
   *  - baris ber-`data-domain-key` (`kind:id:role`, halaman `/domains`) dicocokkan
   *    dengan `kind`/`id`/`role` payload → dua baris app yang sama (custom domain
   *    `serve` + subdomain `redirect`) dipetakan terpisah;
   *  - baris tanpa kunci (halaman lama `/ssl`) tetap memakai `data-app-id` ↔
   *    `app_id` (perilaku lama dipertahankan).
   *
   * Payload `/api/domains/status` memuat baris terdekorasi (`ssl_status`,
   * `ssl_message`, `ssl_error`, `ssl_expires_at`, `ssl_queue_position`,
   * `ssl_queue_size`), sedangkan `/api/ssl/status` memakai
   * `status`/`message`/`error`/`expires_at` — keduanya diterima. Field antrean
   * memakai nama yang sama di kedua endpoint.
   */
  function applyStatus(payload) {
    var supported = payload.ssl_supported === true;
    if (table) table.setAttribute('data-ssl-supported', supported ? '1' : '0');

    var byKey = {};
    var byId = {};
    payload.rows.forEach(function (item) {
      if (!item || typeof item !== 'object') return;

      var id = typeof item.id === 'string' ? item.id : '';
      var kind = typeof item.kind === 'string' && item.kind !== '' ? item.kind : 'app';
      var role = typeof item.role === 'string' ? item.role : '';
      if (id !== '' && role !== '') byKey[kind + ':' + id + ':' + role] = item;

      // Fallback halaman lama: hanya `app_id` (tipe string) yang dipakai.
      if (typeof item.app_id === 'string' && item.app_id !== '') byId[item.app_id] = item;
    });

    rows.forEach(function (row) {
      var key = row.getAttribute('data-domain-key') || '';
      var data = key !== '' ? byKey[key] : byId[row.getAttribute('data-app-id') || ''];
      if (!data) return;

      // Domain efektif bisa berubah (custom domain di-set/dihapus) — simpan
      // untuk POST berikutnya; teks tabel tidak diubah dari sini.
      if (typeof data.domain === 'string' && data.domain !== '') {
        row.setAttribute('data-domain', data.domain);
      }

      var status = typeof data.status === 'string'
        ? data.status
        : (typeof data.ssl_status === 'string' ? data.ssl_status : 'disabled');
      var message = typeof data.message === 'string'
        ? data.message
        : (typeof data.ssl_message === 'string' ? data.ssl_message : '');
      var error = typeof data.error === 'string'
        ? data.error
        : (typeof data.ssl_error === 'string' ? data.ssl_error : '');
      var errorSummary = typeof data.error_summary === 'string'
        ? data.error_summary
        : (typeof data.ssl_error_summary === 'string' ? data.ssl_error_summary : '');
      var expires = typeof data.expires_at === 'string'
        ? data.expires_at
        : (typeof data.ssl_expires_at === 'string' ? data.ssl_expires_at : '');
      // Posisi antrean SSL (field sama di `/api/domains/status` & `/api/ssl/status`).
      var queuePosition = queueNumber(data.ssl_queue_position);
      var queueSize = queueNumber(data.ssl_queue_size);

      setRow(row, status !== '' ? status : 'disabled', {
        message: message,
        error: error,
        errorSummary: errorSummary,
        expires: expires,
        canSsl: data.can_ssl === true,
        sslSupported: supported,
        queuePosition: queuePosition,
        queueSize: queueSize
      });
    });
  }

  // ------------------------------------------------------------------
  // Aksi: Aktifkan / Retry SSL (AJAX)
  // ------------------------------------------------------------------

  function fail(row, code) {
    setRowError(row, code === 419 ? MSG_EXPIRED : MSG_UNREACHABLE);
    setButtonBusy(row, false);
  }

  function submitForm(form) {
    var row = rowOf(form);
    if (!row || isBusy(row)) return;

    var url = form.getAttribute('data-ssl-url') || '';
    if (url === '') return;

    setButtonBusy(row, true);
    setRowError(row, '');

    var body = new URLSearchParams();
    body.set('_token', CSRF);
    var domain = row.getAttribute('data-domain') || '';
    if (domain !== '') body.set('domain', domain);

    window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-TOKEN': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body
    }).then(function (response) {
      // Status HTTP ikut dibaca (419 CSRF, 404 tanpa hak) tanpa menganggapnya JSON.
      var http = response.status;
      return response.text().then(function (text) {
        return { http: http, text: text };
      });
    }).then(function (res) {
      var payload = null;
      try {
        payload = JSON.parse(res.text);
      } catch (err) {
        payload = null;
      }

      if (payload === null || typeof payload !== 'object') {
        fail(row, res.http);
        return;
      }

      if (payload.code === 0) {
        // Diterima: baris jadi `pending` (optimistis) lalu polling menyusul.
        setRow(row, typeof payload.status === 'string' && payload.status !== '' ? payload.status : 'pending', {
          message: typeof payload.message === 'string' ? payload.message : '',
          error: ''
        });
        startPolling();
        return;
      }

      var error = typeof payload.error === 'string' ? payload.error : '';
      if (payload.code === 1 && error !== '') {
        // Penolakan bisnis (HTTP 200): tampilkan alasannya, lalu sinkronkan
        // baris sekali dari server supaya state tidak menyimpang.
        setRowError(row, error);
        setButtonBusy(row, false);
        refreshStatus();
        return;
      }

      fail(row, res.http !== 0 ? res.http : payload.code);
    }).catch(function () {
      fail(row, 0);
    });
  }

  Array.prototype.slice.call(document.querySelectorAll('[data-ssl-form]')).forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      // Tanpa JS, form ini tetap POST ke `/ssl/{id}/enable` seperti semula.
      ev.preventDefault();
      submitForm(form);
    });
  });

  // Tombol "Log" (kolom Aksi) ⇒ modal detail galat. Delegasi di tabel supaya
  // tetap bekerja untuk baris yang statusnya berubah oleh polling.
  if (table) {
    table.addEventListener('click', function (ev) {
      var btn = ev.target && ev.target.closest ? ev.target.closest('[data-ssl-error-log]') : null;
      if (!btn) return;
      ev.preventDefault();
      openErrorLog(rowOf(btn));
    });
  }

  // ------------------------------------------------------------------
  // Siklus hidup polling
  // ------------------------------------------------------------------

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopPolling();
      return;
    }
    // Tab terlihat kembali: lanjutkan hanya jika masih ada baris `pending`.
    if (hasPending()) startPolling();
  });
  window.addEventListener('pagehide', stopPolling);
  window.addEventListener('beforeunload', stopPolling);

  // Halaman dimuat dengan baris `pending` (atau ditandai `data-ssl-autostart`):
  // langsung poll tanpa menunggu aksi user.
  var autostart = table !== null && table.getAttribute('data-ssl-autostart') === '1';
  if (autostart || hasPending()) startPolling();
})();
