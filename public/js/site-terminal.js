/* Terminal container (docker exec) — interaktif (xterm.js + SSE) & one-shot run command.
 * Dipakai di halaman detail site (tab Container). Semua mutasi via POST + token CSRF;
 * output interaktif di-stream via Server-Sent Events (GET). */
(function () {
  'use strict';

  var CSRF = '';
  var metaCsrf = document.querySelector('meta[name="csrf-token"]');
  if (metaCsrf) CSRF = metaCsrf.getAttribute('content');

  function el(id) { return document.getElementById(id); }

  function post(url, body) {
    var fd = new URLSearchParams();
    if (body) {
      Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
    }
    fd.append('_token', CSRF);
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: fd.toString()
    }).then(function (r) {
      return r.json().catch(function () { return { code: -1, msg: 'Respon tidak valid' }; });
    });
  }

  function decodeB64(b64) {
    try {
      var bin = atob(b64);
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      return new TextDecoder('utf-8').decode(bytes);
    } catch (e) { return ''; }
  }

  function showStatus(cls, text) {
    var s = el('terminal-status');
    if (s) { s.className = 'small ' + (cls || 'text-muted'); s.textContent = text || ''; }
  }

  // ==================================================================
  // Terminal interaktif
  // ==================================================================

  var term = null;
  var fitAddon = null;
  var termState = { siteId: '', container: '', token: null, es: null, connected: false };
  var focusTimer = null;

  // Batch input keyboard: ketikan diakumulasi lalu dikirim dalam SATU POST per
  // ~30ms — menghindari 1 HTTP request per keystroke (yang masing-masing sukses
  // mengembalikan {"code":0}). Tetap responsif karena shell butuh masukan per baris.
  var inputBuffer = '';
  var inputTimer = null;

  function flushInput() {
    inputTimer = null;
    if (inputBuffer === '' || !termState.token) {
      inputBuffer = '';
      return;
    }
    var data = inputBuffer;
    inputBuffer = '';
    post('/api/sites/' + encodeURIComponent(termState.siteId) + '/terminal/' + encodeURIComponent(termState.token) + '/input', { data: data });
  }

  function queueInput(data) {
    if (data === '' || !termState.token) return;
    inputBuffer += data;
    // Buffer besar (mis. paste teks panjang) → flush segera, jangan ditunda.
    if (inputBuffer.length >= 16384) {
      flushInput();
      return;
    }
    if (inputTimer === null) {
      inputTimer = setTimeout(flushInput, 30);
    }
  }

  function stopKeepFocus() {
    if (focusTimer) { clearInterval(focusTimer); focusTimer = null; }
  }

  // Bootstrap modal sempat memindahkan fokus ke container (div) sesaat setelah
  // `shown.bs.modal` — sehingga ketikan tidak sampai ke terminal. Interval singkat
  // ini memastikan fokus kembali ke textarea xterm hingga semua proses fokus modal
  // selesai (sekitar 2,5 detik), lalu berhenti sendiri.
  function keepTerminalFocus(ms) {
    stopKeepFocus();
    var start = Date.now();
    focusTimer = setInterval(function () {
      var m = el('terminal-modal');
      if (!term || (m && !m.classList.contains('show'))) { stopKeepFocus(); return; }
      term.focus();
      if (Date.now() - start > ms) stopKeepFocus();
    }, 150);
  }

  function closeStream() {
    if (termState.es) { termState.es.close(); termState.es = null; }
  }

  function closeTerminalSession() {
    stopKeepFocus();
    flushInput();
    if (termState.token) {
      var sid = termState.siteId;
      var tok = termState.token;
      termState.token = null;
      termState.connected = false;
      post('/api/sites/' + encodeURIComponent(sid) + '/terminal/' + encodeURIComponent(tok) + '/close', {});
    }
    closeStream();
  }

  function sendResize() {
    if (!term || !termState.token) return;
    var cmd = 'stty cols ' + term.cols + ' rows ' + term.rows + '\n';
    post('/api/sites/' + encodeURIComponent(termState.siteId) + '/terminal/' + encodeURIComponent(termState.token) + '/input', { data: cmd });
  }

  function connectStream(siteId, token) {
    closeStream();
    var es = new EventSource('/api/sites/' + encodeURIComponent(siteId) + '/terminal/' + encodeURIComponent(token) + '/stream');
    termState.es = es;

    es.addEventListener('output', function (ev) {
      if (!term || !ev.data) return;
      // ev.data berisi base64 dari output MENTAH (bukan JSON) — kirim langsung.
      term.write(decodeB64(ev.data));
    });

    es.addEventListener('error', function (ev) {
      if (!term) return;
      term.write('\r\n\x1b[31m' + (ev.data || 'Terjadi kesalahan pada terminal.') + '\x1b[0m\r\n');
    });

    es.addEventListener('close', function () {
      termState.connected = false;
      showStatus('text-warning', 'Sesi selesai');
      if (term) term.write('\r\n\x1b[33m[session selesai]\x1b[0m\r\n');
      closeStream();
    });

    es.onopen = function () { termState.connected = true; };
    es.onerror = function () {
      // EventSource reconnect otomatis; sesi tetap hidup sampai server menutup stream.
      termState.connected = false;
    };
  }

  function startTerminal(p) {
    var host = el('terminal-host');
    if (!host) return;

    if (term) { term.dispose(); term = null; fitAddon = null; }

    el('terminal-title').textContent = p.container;
    showStatus('text-muted', 'Menghubungkan ...');

    term = new Terminal({
      cursorBlink: true,
      fontSize: 13,
      fontFamily: 'Menlo, Consolas, "Liberation Mono", "DejaVu Sans Mono", monospace',
      scrollback: 5000,
      convertEol: false,
      theme: { background: '#101014', foreground: '#e6e6e6' }
    });
    fitAddon = new FitAddon.FitAddon();
    term.loadAddon(fitAddon);
    term.open(host);
    fitAddon.fit();
    // Pastikan terminal langsung ter-fokus (textarea xterm tersembunyi). Tanpa ini
    // ketikan tidak sampai ke terminal sebelum user klik ke dalamnya.
    term.focus();
    host.addEventListener('mousedown', function () { if (term) term.focus(); });

    termState.siteId = p.siteId;
    termState.container = p.container;
    termState.token = null;
    termState.connected = false;

    term.onData(function (data) {
      // Proses sesi sudah di-spawn saat /open; FIFO stdin menerima input meski SSE
      // belum terbuka — cukup menunggu token (tidak perlu menunggu SSE connected).
      // Input di-batch (debounce ~30ms) agar tidak ada 1 HTTP request per keystroke.
      queueInput(data);
    });
    term.onResize(function () {
      if (termState.token) sendResize();
    });

    // Buka sesi interaktif
    post('/api/sites/' + encodeURIComponent(p.siteId) + '/terminal/open', {
      container: p.container,
      shell: p.shell || 'sh',
      user: p.user || ''
    }).then(function (d) {
      if (d.code !== 0 || !d.data) {
        showStatus('text-danger', 'Gagal');
        if (term) term.write('\r\n\x1b[31mGagal membuka terminal: ' + (d.msg || 'error') + '\x1b[0m\r\n');
        return;
      }
      termState.token = d.data.token;
      showStatus('text-success', 'Terhubung — ' + (d.data.shell || p.shell) + ' @ ' + p.container);
      connectStream(p.siteId, d.data.token);
      if (term) term.focus();
      setTimeout(sendResize, 300);
    }).catch(function () {
      showStatus('text-danger', 'Gagal terhubung ke server');
      if (term) term.write('\r\n\x1b[31mGagal terhubung ke server.\x1b[0m\r\n');
    });
  }

  var pendingTerm = null;
  var termModal = el('terminal-modal');
  if (termModal) {
    termModal.addEventListener('shown.bs.modal', function () {
      if (pendingTerm) {
        var p = pendingTerm;
        pendingTerm = null;
        startTerminal(p);
      } else if (term && fitAddon) {
        fitAddon.fit();
      }
      // Fokus ulang beberapa kali sampai proses fokus Bootstrap selesai.
      setTimeout(function () { if (term) term.focus(); }, 100);
      keepTerminalFocus(2500);
    });
    termModal.addEventListener('hidden.bs.modal', function () {
      stopKeepFocus();
      closeTerminalSession();
      if (term) { term.dispose(); term = null; fitAddon = null; }
      pendingTerm = null;
    });
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.terminal-btn');
    if (!btn) return;
    ev.preventDefault();
    pendingTerm = {
      siteId: btn.getAttribute('data-site') || '',
      container: btn.getAttribute('data-container') || '',
      shell: btn.getAttribute('data-shell') || 'sh',
      user: btn.getAttribute('data-user') || ''
    };
    var modal = el('terminal-modal');
    if (modal && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
  });

  // ==================================================================
  // One-shot run command (non-interaktif)
  // ==================================================================

  function openRunModal(siteId, container) {
    el('run-title').textContent = container;
    el('run-container').value = container;
    el('run-command').value = '';
    var out = el('run-output');
    out.classList.add('d-none');
    out.textContent = '';
    el('run-error').classList.add('d-none');
    el('run-exit').textContent = '';
    el('run-spinner').classList.add('d-none');
    var modal = el('run-modal');
    if (modal && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
  }

  var runForm = el('run-form');
  if (runForm) {
    runForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var siteId = runForm.getAttribute('data-site') || '';
      var container = el('run-container').value;
      var command = el('run-command').value.trim();
      var out = el('run-output');
      var err = el('run-error');
      var sp = el('run-spinner');
      var exitEl = el('run-exit');

      if (!command) {
        err.textContent = 'Perintah kosong.';
        err.classList.remove('d-none');
        return;
      }
      err.classList.add('d-none');
      sp.classList.remove('d-none');

      post('/api/sites/' + encodeURIComponent(siteId) + '/terminal/run', {
        container: container,
        command: command,
        timeout: 120
      }).then(function (d) {
        sp.classList.add('d-none');
        if (d.code !== 0 || !d.data) {
          err.textContent = d.msg || 'Gagal menjalankan perintah.';
          err.classList.remove('d-none');
          return;
        }
        var res = d.data;
        var text = (res.stdout || '') + (res.stderr || '');
        if (res.timedOut) text += '\n[timeout]';
        out.textContent = text === '' ? '(tanpa output)' : text;
        out.classList.remove('d-none');
        exitEl.textContent = 'exit code: ' + res.code;
      }).catch(function () {
        sp.classList.add('d-none');
        err.textContent = 'Gagal terhubung ke server.';
        err.classList.remove('d-none');
      });
    });
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.run-btn');
    if (!btn) return;
    ev.preventDefault();
    openRunModal(btn.getAttribute('data-site') || '', btn.getAttribute('data-container') || '');
  });

  // Tutup sesi bila pindah halaman (fire-and-forget)
  window.addEventListener('beforeunload', function () { closeTerminalSession(); });
})();
