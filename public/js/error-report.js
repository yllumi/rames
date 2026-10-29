// Reporter error klien — dimuat `app/view/partials/footer.php` **hanya untuk
// user yang sudah login**. Tujuannya menangkap error JS yang tidak tertangani
// dan mengirimkannya ke `POST /api/errors` (halaman `/errors` untuk admin).
//
// Prinsip:
//  - Tidak pernah mengirim cookie, localStorage, isi form/input, atau body request;
//    hanya pesan/stack/sumber error + metadata viewport/referrer same-origin.
//  - Tanpa dependency, tanpa retry, tanpa `alert`/`console`. Semua kegagalan
//    jaringan di-swallow. Kirim dihentikan untuk page load ini bila server
//    membalas 419 (CSRF kedaluwarsa) atau 429 (rate limit).
//  - Batas: maks 10 laporan per page load, maks 1 laporan per fingerprint per
//    5 detik, body JSON ≤16 KiB (batas server).
//
// Pemakaian manual (mis. dari blok catch milik fitur lain):
//   window.ramesReportError(new Error('gagal memuat'), {fitur: 'monitor'});
//   window.ramesReportError('pesan bebas', {fitur: 'monitor'});
// `context` hanya menerima nilai skalar (string/number/boolean); objek/array
// diabaikan agar tidak ada data tak sengaja yang ikut terkirim.
(function () {
  'use strict';

  var MAX_REPORTS = 10;
  var MIN_INTERVAL_MS = 5000;
  var MAX_MESSAGE_CHARS = 2000;
  var MAX_STACK_BYTES = 8192;
  var MAX_BODY_BYTES = 16 * 1024;
  var MAX_SOURCE_CHARS = 500;
  var MAX_CONTEXT_KEYS = 20;
  var MAX_CONTEXT_VALUE_CHARS = 500;

  var meta = document.querySelector('meta[name="csrf-token"]');
  if (!meta) return;
  var token = meta.getAttribute('content') || '';
  if (!token) return;

  var sent = 0;
  var stopped = false;
  var lastByFingerprint = {};

  function cut(text, max) {
    var value = String(text === null || text === undefined ? '' : text);
    return value.length > max ? value.slice(0, max) : value;
  }

  function byteLength(text) {
    try {
      if (typeof TextEncoder !== 'undefined') {
        return new TextEncoder().encode(String(text)).length;
      }
    } catch (e) {}
    return String(text).length;
  }

  // Potong string pada batas byte UTF-8 tanpa memecah karakter multibyte.
  function truncateBytes(text, maxBytes) {
    var value = String(text === null || text === undefined ? '' : text);
    if (maxBytes <= 0) return '';
    if (byteLength(value) <= maxBytes) return value;
    var lo = 0;
    var hi = value.length;
    while (lo < hi) {
      var mid = Math.ceil((lo + hi) / 2);
      if (byteLength(value.slice(0, mid)) <= maxBytes) lo = mid;
      else hi = mid - 1;
    }
    return value.slice(0, lo);
  }

  function positiveInt(value) {
    var n = parseInt(value, 10);
    return isFinite(n) && n > 0 ? n : 0;
  }

  function sameOriginReferrer() {
    try {
      if (!document.referrer) return '';
      var url = new URL(document.referrer, window.location.href);
      return url.origin === window.location.origin ? url.pathname : '';
    } catch (e) {
      return '';
    }
  }

  // Nama berkas sumber bisa membawa query string (`app.js?v=…&token=…`).
  // Kirim pathname saja — reporter klien tidak boleh mengirim query apa pun;
  // bila URL tidak bisa di-parse, buang `?...`/`#...` secara manual.
  function safeSourcePath(value) {
    var raw = String(value === null || value === undefined ? '' : value);
    if (raw === '') return '';
    try {
      return new URL(raw, window.location.href).pathname;
    } catch (e) {
      return raw.split('?')[0].split('#')[0];
    }
  }

  // Representasi aman untuk `unhandledrejection` selain Error/string.
  // JANGAN `JSON.stringify(reason)`: objek/Response yang di-reject bisa memuat
  // token atau body sensitif. Hanya nama/pesan yang dikirim; objek lain menjadi
  // `[object Object]` (privacy: reporter tidak menyerialkan objek sembarang).
  function safeReasonText(reason) {
    if (typeof reason === 'string') return reason;
    if (reason !== null && typeof reason === 'object') {
      var name = typeof reason.name === 'string' ? reason.name : '';
      var message = typeof reason.message === 'string' ? reason.message : '';
      if (name !== '' || message !== '') {
        return name !== '' ? name + ': ' + message : message;
      }
      return String(reason);
    }

    return String(reason);
  }

  function buildContext(extra) {
    var context = {
      viewport: String(window.innerWidth) + 'x' + String(window.innerHeight)
    };
    var referrer = sameOriginReferrer();
    if (referrer) context.referrer = cut(referrer, MAX_CONTEXT_VALUE_CHARS);

    if (extra && typeof extra === 'object') {
      try {
        var keys = Object.keys(extra).slice(0, MAX_CONTEXT_KEYS);
        for (var i = 0; i < keys.length; i++) {
          var key = cut(keys[i], 40);
          var value = extra[keys[i]];
          var type = typeof value;
          if (type === 'string') {
            context[key] = cut(value, MAX_CONTEXT_VALUE_CHARS);
          } else if (type === 'number' && isFinite(value)) {
            context[key] = value;
          } else if (type === 'boolean') {
            context[key] = value;
          }
        }
      } catch (e) {}
    }

    return context;
  }

  function send(kind, message, stack, source, line, column, extra) {
    try {
      if (stopped || sent >= MAX_REPORTS) return;

      var msg = cut(message, MAX_MESSAGE_CHARS);
      if (msg === '') msg = 'Error tanpa pesan';

      // Fingerprint lokal: hanya untuk throttle, tidak dikirim ke server.
      var fingerprint = String(kind) + '|' + msg.slice(0, 200);
      var now = Date.now();
      var last = lastByFingerprint[fingerprint];
      if (typeof last === 'number' && now - last < MIN_INTERVAL_MS) return;

      var payload = {
        _token: token,
        kind: String(kind),
        message: msg,
        stack: truncateBytes(stack, MAX_STACK_BYTES),
        source: cut(safeSourcePath(source), MAX_SOURCE_CHARS),
        line: positiveInt(line),
        column: positiveInt(column),
        route: window.location.pathname,
        occurred_at: new Date().toISOString(),
        context: buildContext(extra)
      };

      var body = JSON.stringify(payload);
      // Jaga batas body server: buang stack dulu, lalu perpendek pesan/context.
      if (byteLength(body) > MAX_BODY_BYTES) {
        payload.stack = '';
        body = JSON.stringify(payload);
      }
      if (byteLength(body) > MAX_BODY_BYTES) {
        payload.message = cut(payload.message, 500);
        payload.context = { viewport: payload.context.viewport };
        body = JSON.stringify(payload);
      }
      if (byteLength(body) > MAX_BODY_BYTES) return;

      lastByFingerprint[fingerprint] = now;
      sent++;

      window.fetch('/api/errors', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: body
      }).then(function (response) {
        // CSRF kedaluwarsa / kena rate limit: berhenti melapor sampai reload.
        if (response && (response.status === 419 || response.status === 429)) {
          stopped = true;
        }
      }).catch(function () {
        // Sengaja diam: reporter tidak boleh mengganggu halaman.
      });
    } catch (e) {}
  }

  window.addEventListener('error', function (event) {
    try {
      if (!event) return;
      var error = event.error;
      var stack = error && typeof error.stack === 'string' ? error.stack : '';
      var message = event.message || (error && error.message) || 'Script error';
      send('client', message, stack, event.filename || '', event.lineno, event.colno, null);
    } catch (e) {}
  });

  window.addEventListener('unhandledrejection', function (event) {
    try {
      var reason = event ? event.reason : null;
      if (reason && typeof reason.message === 'string') {
        send('client', reason.message || 'Unhandled rejection', typeof reason.stack === 'string' ? reason.stack : '', '', 0, 0, null);
        return;
      }
      // Lihat `safeReasonText()`: objek sembarang tidak pernah diserialkan.
      var text = reason === null || reason === undefined ? '' : safeReasonText(reason);
      send('client', text || 'Unhandled rejection', '', '', 0, 0, null);
    } catch (e) {}
  });

  // API manual untuk fitur lain; lihat komentar berkas di atas.
  window.ramesReportError = function (error, context) {
    try {
      if (error && typeof error.stack === 'string') {
        send('client', error.message || String(error), error.stack, '', 0, 0, context);
        return;
      }
      send('client', typeof error === 'string' ? error : String(error), '', '', 0, 0, context);
    } catch (e) {}
  };
})();
