/* File manager container (jelajah / unggah / unduh / edit teks / pindah / ekstrak arsip).
 * Dipakai di halaman detail app (tab Container) lewat tombol 📁 Files per container.
 * Semua mutasi via POST + token CSRF; daftar & isi berkas via GET (JSON).
 * Nama/path dari server TIDAK pernah dimasukkan sebagai innerHTML — selalu
 * textContent/createElement (anti-XSS). XHR/unggahan dibatalkan saat modal ditutup
 * atau saat halaman berpindah (pagehide/beforeunload); "Pindahkan" memakai picker
 * folder tujuan di dalam modal (bukan window.prompt). */
(function () {
  'use strict';

  var CSRF = '';
  var metaCsrf = document.querySelector('meta[name="csrf-token"]');
  if (metaCsrf) CSRF = metaCsrf.getAttribute('content');

  // Batas ukuran berkas unggahan (server juga menegakkan batas ini).
  var UPLOAD_LIMIT = 64 * 1024 * 1024;

  function el(id) { return document.getElementById(id); }
  function enc(v) { return encodeURIComponent(String(v == null ? '' : v)); }

  function parseJson(text) {
    try { return text ? JSON.parse(text) : null; } catch (e) { return null; }
  }

  function errMsg(d, fallback) {
    return (d && d.msg) ? String(d.msg) : fallback;
  }

  function jsonFetch(url, signal) {
    var opts = {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    };
    // Opsional: batalkan request saat picker folder tujuan ditutup/ditinggalkan.
    if (signal) opts.signal = signal;
    return fetch(url, opts).then(function (r) {
      return r.text().then(function (t) {
        var d = parseJson(t);
        if (!d || typeof d !== 'object') {
          return { code: -1, msg: 'Respon tidak valid dari server (HTTP ' + r.status + ').' };
        }
        if (typeof d.code === 'undefined') d.code = r.ok ? 0 : r.status;
        return d;
      });
    });
  }

  function post(url, body) {
    var fd = new URLSearchParams();
    if (body) {
      Object.keys(body).forEach(function (k) { fd.append(k, String(body[k] == null ? '' : body[k])); });
    }
    fd.append('_token', CSRF);
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: fd.toString()
    }).then(function (r) {
      return r.text().then(function (t) {
        var d = parseJson(t);
        if (!d || typeof d !== 'object') {
          return { code: -1, msg: 'Respon tidak valid dari server (HTTP ' + r.status + ').' };
        }
        if (typeof d.code === 'undefined') d.code = r.ok ? 0 : r.status;
        return d;
      });
    });
  }

  var modal = el('files-modal');
  if (!modal) return;

  var ST = {
    appId: '',
    container: '',
    path: null,        // path absolut folder yang sedang dibuka (dari server)
    parent: null,      // path induk (dari server)
    seq: 0,            // penanda request: respons lama diabaikan
    mode: 'list',
    editPath: '',
    editName: '',
    xhrs: [],          // XHR unggahan yang masih berjalan
    queue: [],
    curXhr: null,
    aborted: false,
    uploadedAny: false,
    // Pindah (move): picker folder tujuan punya state + penanda request sendiri.
    moveSeq: 0,        // respons picker lama diabaikan
    moveAbort: null,   // AbortController request picker yang berjalan
    movePath: null,    // folder tujuan yang sedang dibuka di picker
    moveParent: null,  // induk folder tujuan (dari server)
    moveSrc: '',       // path absolut entri yang dipindahkan
    moveSrcName: '',   // nama entri yang dipindahkan
    moveSrcDir: null,  // folder asal entri (= ST.path saat aksi dimulai)
    moveSrcIsDir: false,
    moveBusy: false
  };

  // ---------------------------------------------------------------- util UI

  function setStatus(text) {
    var s = el('files-status');
    if (s) s.textContent = text || '';
  }

  function showAlert(msg) {
    var a = el('files-alert');
    if (!a) return;
    a.textContent = msg;
    a.classList.remove('d-none');
  }

  function clearAlert() {
    var a = el('files-alert');
    if (!a) return;
    a.textContent = '';
    a.classList.add('d-none');
  }

  function showPane(which) {
    ST.mode = which;
    var list = el('files-list-pane');
    var edit = el('files-edit-pane');
    var move = el('files-move-pane');
    if (list) list.classList.toggle('d-none', which !== 'list');
    if (edit) edit.classList.toggle('d-none', which !== 'edit');
    if (move) move.classList.toggle('d-none', which !== 'move');
    // Toolbar utama (breadcrumb folder aktif + aksi) disembunyikan saat memilih
    // folder tujuan agar tidak ada dua breadcrumb yang membingungkan.
    var toolbar = el('files-toolbar');
    if (toolbar) toolbar.classList.toggle('d-none', which === 'move');
  }

  function clearEntries() {
    var tb = el('files-entries');
    if (tb) tb.textContent = '';
    var empty = el('files-empty');
    if (empty) empty.classList.add('d-none');
    var trunc = el('files-truncated');
    if (trunc) trunc.classList.add('d-none');
  }

  function formatSize(n) {
    var v = Number(n);
    if (!isFinite(v) || v < 0) return '';
    if (v < 1024) return v + ' B';
    var units = ['KB', 'MB', 'GB', 'TB'];
    var i = -1;
    do { v = v / 1024; i++; } while (v >= 1024 && i < units.length - 1);
    return (v >= 10 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i];
  }

  function formatMtime(m) {
    if (m === null || m === undefined || m === '') return '';
    var n = Number(m);
    if (isFinite(n) && n > 0 && String(m).length <= 12) {
      var d = new Date(n * 1000);
      if (!isNaN(d.getTime())) return d.toLocaleString();
    }
    return String(m);
  }

  function childPath(name) {
    var base = ST.path || '/';
    if (base === '/') return '/' + name;
    return base.replace(/\/+$/, '') + '/' + name;
  }

  function isArchive(name) {
    return /\.(zip|tar\.gz|tgz)$/i.test(String(name || ''));
  }

  function validName(name) {
    return name !== '' && name !== '.' && name !== '..' && name.indexOf('/') === -1 && name.indexOf('\\') === -1;
  }

  // ------------------------------------------------------------ breadcrumb

  function renderBreadcrumb(path) {
    var ol = el('files-breadcrumb');
    if (!ol) return;
    ol.textContent = '';

    var parts = String(path || '/').split('/').filter(function (p) { return p !== ''; });

    function addCrumb(label, target) {
      var li = document.createElement('li');
      li.className = 'breadcrumb-item';
      if (target === null) {
        li.textContent = label;
        li.setAttribute('aria-current', 'page');
      } else {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-link btn-sm p-0 mono text-decoration-none';
        b.textContent = label;
        b.setAttribute('aria-label', 'Buka folder ' + target);
        b.addEventListener('click', function () { loadFiles(target); });
        li.appendChild(b);
      }
      ol.appendChild(li);
    }

    addCrumb('/', parts.length === 0 ? null : '/');
    var acc = '';
    parts.forEach(function (p, i) {
      acc += '/' + p;
      addCrumb(p, i === parts.length - 1 ? null : acc);
    });
  }

  // ---------------------------------------------------------------- listing

  function loadFiles(path, done) {
    if (!ST.container) return;
    showPane('list');
    clearAlert();
    var seq = ++ST.seq;

    var url = '/api/apps/' + enc(ST.appId) + '/files?container=' + enc(ST.container);
    if (path !== null && typeof path !== 'undefined' && path !== '') {
      url += '&path=' + enc(path);
    }
    setStatus('Memuat daftar ...');

    jsonFetch(url).then(function (d) {
      if (seq !== ST.seq) return;
      if (!d || d.code !== 0 || !d.data) {
        showAlert(errMsg(d, 'Gagal memuat daftar berkas.'));
        clearEntries();
        setStatus('');
        return;
      }
      renderListing(d.data);
      if (typeof done === 'function') done();
    }).catch(function () {
      if (seq !== ST.seq) return;
      showAlert('Gagal terhubung ke server.');
      setStatus('');
    });
  }

  function renderListing(data) {
    ST.path = typeof data.path === 'string' && data.path !== '' ? data.path : '/';
    ST.parent = data.parent || null;

    renderBreadcrumb(ST.path);
    clearEntries();

    var up = el('files-up');
    if (up) {
      var canUp = !!ST.parent && ST.parent !== ST.path;
      up.disabled = !canUp;
      up.setAttribute('aria-disabled', canUp ? 'false' : 'true');
    }

    var entries = data.entries && data.entries.length ? data.entries : [];
    var tb = el('files-entries');
    if (tb) {
      entries.forEach(function (entry) { renderEntry(entry, tb); });
    }

    if (entries.length === 0) {
      var empty = el('files-empty');
      if (empty) empty.classList.remove('d-none');
    }
    if (data.truncated) {
      var trunc = el('files-truncated');
      if (trunc) trunc.classList.remove('d-none');
    }
    setStatus(entries.length + ' entri');
  }

  function renderEntry(entry, tbody) {
    var name = String(entry && entry.name != null ? entry.name : '');
    var type = String(entry && entry.type != null ? entry.type : '').toLowerCase();
    var isDir = type === 'dir' || type === 'directory';
    var isLink = type === 'link' || type === 'symlink';

    var tr = document.createElement('tr');

    // Nama (+ ikon jenis)
    var tdName = document.createElement('td');
    if (isDir) {
      var open = document.createElement('button');
      open.type = 'button';
      open.className = 'btn btn-link btn-sm p-0 mono text-decoration-none';
      open.textContent = '📁 ' + name;
      open.title = 'Buka folder';
      open.setAttribute('aria-label', 'Buka folder ' + name);
      open.addEventListener('click', function () { loadFiles(childPath(name)); });
      tdName.appendChild(open);
    } else {
      var span = document.createElement('span');
      span.className = 'mono';
      span.textContent = (isLink ? '🔗 ' : '📄 ') + name;
      tdName.appendChild(span);
    }
    if (isLink && entry.link) {
      var lk = document.createElement('span');
      lk.className = 'text-muted small ms-1';
      lk.textContent = '→ ' + String(entry.link);
      tdName.appendChild(lk);
    }
    tr.appendChild(tdName);

    // Ukuran
    var tdSize = document.createElement('td');
    tdSize.className = 'small text-nowrap';
    tdSize.textContent = isDir ? '—' : formatSize(entry.size);
    tr.appendChild(tdSize);

    // Waktu ubah
    var tdTime = document.createElement('td');
    tdTime.className = 'small text-nowrap';
    tdTime.textContent = formatMtime(entry.mtime);
    tr.appendChild(tdTime);

    // Mode
    var tdMode = document.createElement('td');
    tdMode.className = 'small mono text-nowrap';
    tdMode.textContent = entry.mode != null ? String(entry.mode) : '';
    tr.appendChild(tdMode);

    // Aksi
    var tdAct = document.createElement('td');
    tdAct.className = 'text-end text-nowrap';
    if (!isDir) {
      tdAct.appendChild(actionBtn('⬇', 'Unduh ' + name, downloadEntry.bind(null, name)));
      tdAct.appendChild(actionBtn('✎', 'Edit teks ' + name, startEdit.bind(null, name)));
      if (isArchive(name)) {
        tdAct.appendChild(actionBtn('🗜', 'Ekstrak ' + name, extractEntry.bind(null, name)));
      }
    }
    tdAct.appendChild(actionBtn('⇄', 'Ubah nama ' + name, renameEntry.bind(null, entry)));
    tdAct.appendChild(actionBtn('📦', 'Pindahkan ' + name, moveEntry.bind(null, entry)));
    tdAct.appendChild(actionBtn('⌫', 'Hapus ' + name, deleteEntry.bind(null, entry), 'btn-outline-danger'));
    tr.appendChild(tdAct);

    tbody.appendChild(tr);
  }

  function actionBtn(text, label, fn, cls) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn ' + (cls || 'btn-outline-secondary') + ' btn-sm ms-1';
    b.textContent = text;
    b.title = label;
    b.setAttribute('aria-label', label);
    b.addEventListener('click', fn);
    return b;
  }

  // ------------------------------------------------------------------ aksi

  function downloadEntry(name) {
    var url = '/apps/' + enc(ST.appId) + '/files/download'
      + '?container=' + enc(ST.container) + '&path=' + enc(childPath(name));
    var a = document.createElement('a');
    a.href = url;
    a.rel = 'noopener';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  function renameEntry(entry) {
    var name = String(entry && entry.name != null ? entry.name : '');
    var next = window.prompt('Nama baru untuk "' + name + '":', name);
    if (next === null) return;
    next = next.trim();
    if (!validName(next) || next === name) {
      if (next !== name) showAlert('Nama tidak valid (tanpa "/" dan tidak boleh kosong).');
      return;
    }
    clearAlert();
    setStatus('Mengubah nama ...');
    post('/apps/' + enc(ST.appId) + '/files/rename', {
      container: ST.container,
      path: childPath(name),
      name: next
    }).then(function (d) {
      if (!d || d.code !== 0) { showAlert(errMsg(d, 'Gagal mengubah nama.')); setStatus(''); return; }
      loadFiles(ST.path);
    }).catch(function () { showAlert('Gagal terhubung ke server.'); setStatus(''); });
  }

  function deleteEntry(entry) {
    var name = String(entry && entry.name != null ? entry.name : '');
    if (!window.confirm('Hapus "' + name + '"? Tindakan ini tidak bisa dibatalkan.')) return;
    clearAlert();
    setStatus('Menghapus ...');
    post('/apps/' + enc(ST.appId) + '/files/delete', {
      container: ST.container,
      path: childPath(name)
    }).then(function (d) {
      if (!d || d.code !== 0) { showAlert(errMsg(d, 'Gagal menghapus.')); setStatus(''); return; }
      loadFiles(ST.path);
    }).catch(function () { showAlert('Gagal terhubung ke server.'); setStatus(''); });
  }

  function extractEntry(name) {
    if (!window.confirm('Ekstrak "' + name + '" ke folder ini?')) return;
    doExtract(name);
  }

  function doExtract(name) {
    clearAlert();
    setStatus('Mengekstrak ' + name + ' ...');
    post('/apps/' + enc(ST.appId) + '/files/extract', {
      container: ST.container,
      path: ST.path || '/',
      name: name
    }).then(function (d) {
      if (!d || d.code !== 0) { showAlert(errMsg(d, 'Gagal mengekstrak arsip.')); setStatus(''); return; }
      setStatus('Arsip diekstrak.');
      loadFiles(ST.path);
    }).catch(function () { showAlert('Gagal terhubung ke server.'); setStatus(''); });
  }

  function makeFolder() {
    var name = window.prompt('Nama folder baru:');
    if (name === null) return;
    name = name.trim();
    if (!validName(name)) { showAlert('Nama folder tidak valid (tanpa "/" dan tidak boleh kosong).'); return; }
    clearAlert();
    setStatus('Membuat folder ...');
    post('/apps/' + enc(ST.appId) + '/files/mkdir', {
      container: ST.container,
      path: ST.path || '/',
      name: name
    }).then(function (d) {
      if (!d || d.code !== 0) { showAlert(errMsg(d, 'Gagal membuat folder.')); setStatus(''); return; }
      loadFiles(ST.path);
    }).catch(function () { showAlert('Gagal terhubung ke server.'); setStatus(''); });
  }

  function extractPrompt() {
    var name = window.prompt('Nama berkas arsip (.zip / .tar.gz) di folder ini:');
    if (name === null) return;
    name = name.trim();
    if (name === '') return;
    if (name.indexOf('/') !== -1) { showAlert('Masukkan nama berkas saja (tanpa "/"), lalu buka folder tempat arsip berada.'); return; }
    if (!isArchive(name)) { showAlert('Hanya arsip .zip / .tar.gz yang didukung.'); return; }
    doExtract(name);
  }

  // ------------------------------------------------- pindah (move)

  function isDirEntry(entry) {
    var t = String(entry && entry.type != null ? entry.type : '').toLowerCase();
    return t === 'dir' || t === 'directory';
  }

  function moveSrcDirOf(path) {
    var p = String(path || '/');
    if (p === '/' || p === '') return '/';
    var i = p.lastIndexOf('/');
    return i <= 0 ? '/' : p.slice(0, i);
  }

  // `dest` = path absolut folder tujuan; `src` = path absolut sumber.
  // Dipakai untuk mencegah tujuan = sumber atau descendant-nya (server menolak 400).
  function moveInsideSource(dest, src) {
    var d = String(dest || '');
    var s = String(src || '');
    if (s === '' || s === '/') return false;
    return d === s || d.indexOf(s + '/') === 0;
  }

  function moveSetStatus(text) {
    var s = el('files-move-status');
    if (s) s.textContent = text || '';
  }

  function moveReset() {
    ST.moveSeq++;
    if (ST.moveAbort) { try { ST.moveAbort.abort(); } catch (e) {} }
    ST.moveAbort = null;
    ST.movePath = null;
    ST.moveParent = null;
    ST.moveSrc = '';
    ST.moveSrcName = '';
    ST.moveSrcDir = null;
    ST.moveSrcIsDir = false;
    ST.moveBusy = false;

    var box = el('files-move-list');
    if (box) box.textContent = '';
    var bc = el('files-move-breadcrumb');
    if (bc) bc.textContent = '';
    var srcEl = el('files-move-source');
    if (srcEl) srcEl.textContent = '';
    var destEl = el('files-move-dest');
    if (destEl) destEl.textContent = '';
    var nm = el('files-move-name');
    if (nm) nm.value = '';
    var empty = el('files-move-empty');
    if (empty) empty.classList.add('d-none');
    var up = el('files-move-up');
    if (up) { up.disabled = true; up.setAttribute('aria-disabled', 'true'); }
    var cf = el('files-move-confirm');
    if (cf) cf.disabled = true;
    var sp = el('files-move-spinner');
    if (sp) sp.classList.add('d-none');
    moveSetStatus('');
  }

  function moveEntry(entry) {
    var name = String(entry && entry.name != null ? entry.name : '');
    if (!validName(name)) { showAlert('Nama entri tidak valid.'); return; }

    moveReset();                       // state picker selalu bersih saat dibuka
    clearAlert();
    ST.moveSrc = childPath(name);
    ST.moveSrcName = name;
    ST.moveSrcDir = ST.path || '/';
    ST.moveSrcIsDir = isDirEntry(entry);

    var srcEl = el('files-move-source');
    if (srcEl) srcEl.textContent = ST.moveSrc || name;
    var nm = el('files-move-name');
    if (nm) nm.value = name;

    showPane('move');
    setStatus('');
    // Mulai dari folder aktif supaya tujuan lain hanya beberapa klik.
    moveOpenDir(ST.moveSrcDir);
  }

  function moveOpenDir(dir) {
    if (moveInsideSource(dir, ST.moveSrc)) {
      showAlert('Tidak dapat memilih folder yang dipindahkan atau subfolder di dalamnya.');
      return;
    }
    if (ST.moveAbort) { try { ST.moveAbort.abort(); } catch (e) {} }
    var ctrl = (typeof AbortController === 'function') ? new AbortController() : null;
    ST.moveAbort = ctrl;
    var seq = ++ST.moveSeq;

    var box = el('files-move-list');
    if (box) box.textContent = '';
    var empty = el('files-move-empty');
    if (empty) empty.classList.add('d-none');
    moveSetStatus('Memuat folder ...');

    var url = '/api/apps/' + enc(ST.appId) + '/files?container=' + enc(ST.container) + '&path=' + enc(dir);
    jsonFetch(url, ctrl ? ctrl.signal : undefined).then(function (d) {
      if (seq !== ST.moveSeq) return;
      if (!d || d.code !== 0 || !d.data) {
        moveSetStatus('');
        showAlert(errMsg(d, 'Gagal memuat folder tujuan.'));
        return;
      }
      var data = d.data;
      var path = (typeof data.path === 'string' && data.path !== '') ? data.path : dir;
      var dirs = (data.entries && data.entries.length ? data.entries : []).filter(isDirEntry);
      renderMoveListing(path, dirs);
    }).catch(function () {
      if (seq !== ST.moveSeq) return;
      moveSetStatus('');
      showAlert('Gagal terhubung ke server.');
    });
  }

  function renderMoveListing(path, dirs) {
    ST.movePath = path;
    ST.moveParent = moveSrcDirOf(path);
    if (ST.moveParent === path) ST.moveParent = null;

    var destEl = el('files-move-dest');
    if (destEl) destEl.textContent = path;

    var up = el('files-move-up');
    if (up) {
      up.disabled = !ST.moveParent;
      up.setAttribute('aria-disabled', ST.moveParent ? 'false' : 'true');
    }

    renderMoveBreadcrumb(path);

    var box = el('files-move-list');
    if (box) {
      box.textContent = '';
      dirs.forEach(function (entry) {
        var name = String(entry && entry.name != null ? entry.name : '');
        if (name === '') return;
        var child = (path === '/' ? '/' + name : path.replace(/\/+$/, '') + '/' + name);

        // Folder sumber tidak boleh dijadikan tujuan (termasuk subfolder di dalamnya).
        if (moveInsideSource(child, ST.moveSrc)) {
          var ro = document.createElement('div');
          ro.className = 'list-group-item px-0 text-muted small mono';
          ro.textContent = '📁 ' + name + ' (folder yang dipindahkan)';
          box.appendChild(ro);
          return;
        }

        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'list-group-item list-group-item-action px-0 mono';
        b.textContent = '📁 ' + name;
        b.title = 'Buka folder ' + child;
        b.setAttribute('aria-label', 'Buka folder tujuan ' + name);
        b.addEventListener('click', function () { moveOpenDir(child); });
        box.appendChild(b);
      });
    }

    var empty = el('files-move-empty');
    if (empty) empty.classList.toggle('d-none', dirs.length !== 0);

    moveSyncConfirm();
  }

  function renderMoveBreadcrumb(path) {
    var ol = el('files-move-breadcrumb');
    if (!ol) return;
    ol.textContent = '';

    var parts = String(path || '/').split('/').filter(function (p) { return p !== ''; });

    function addCrumb(label, target) {
      var li = document.createElement('li');
      li.className = 'breadcrumb-item';
      if (target === null) {
        li.textContent = label;
        li.setAttribute('aria-current', 'page');
      } else {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-link btn-sm p-0 mono text-decoration-none';
        b.textContent = label;
        b.setAttribute('aria-label', 'Buka folder tujuan ' + target);
        b.addEventListener('click', function () { moveOpenDir(target); });
        li.appendChild(b);
      }
      ol.appendChild(li);
    }

    addCrumb('/', parts.length === 0 ? null : '/');
    var acc = '';
    parts.forEach(function (p, i) {
      acc += '/' + p;
      // Breadcrumb yang menunjuk folder sumber/descendant juga dinonaktifkan.
      var t = (i === parts.length - 1 || moveInsideSource(acc, ST.moveSrc)) ? null : acc;
      addCrumb(p, t);
    });
  }

  // Sinkronkan status tombol "Pindahkan ke folder ini" dengan folder tujuan + nama.
  function moveSyncConfirm() {
    var cf = el('files-move-confirm');
    if (!cf) return;
    if (ST.moveBusy) { cf.disabled = true; return; }

    var nm = el('files-move-name');
    var name = nm ? nm.value.trim() : '';
    var reason = '';
    if (!ST.movePath) {
      reason = 'Folder tujuan belum dipilih.';
    } else if (name.indexOf('/') !== -1 || name.indexOf('\\') !== -1) {
      reason = 'Nama tidak valid (tanpa “/”).';
    } else if (moveInsideSource(ST.movePath, ST.moveSrc)) {
      reason = 'Folder tujuan berada di dalam folder sumber.';
    } else if (ST.movePath === ST.moveSrcDir && (name === '' || name === ST.moveSrcName)) {
      reason = 'Sudah berada di folder asal — tidak ada yang dipindahkan.';
    }
    cf.disabled = reason !== '';
    moveSetStatus(reason);
  }

  function moveConfirm() {
    if (ST.moveBusy || !ST.movePath) return;
    var nm = el('files-move-name');
    var name = nm ? nm.value.trim() : '';
    if (name.indexOf('/') !== -1 || name.indexOf('\\') !== -1) {
      showAlert('Nama tidak valid (tanpa “/”).');
      return;
    }

    var cf = el('files-move-confirm');
    var sp = el('files-move-spinner');
    ST.moveBusy = true;
    if (cf) cf.disabled = true;
    if (sp) sp.classList.remove('d-none');
    clearAlert();
    setStatus('Memindahkan ...');

    var dest = ST.movePath;
    post('/apps/' + enc(ST.appId) + '/files/move', {
      container: ST.container,
      path: ST.moveSrc,
      dest: dest,
      name: name
    }).then(function (d) {
      ST.moveBusy = false;
      if (sp) sp.classList.add('d-none');
      if (!d || d.code !== 0) {
        moveSyncConfirm();
        showAlert(errMsg(d, 'Gagal memindahkan.'));
        setStatus('');
        return;
      }
      var to = (d.data && d.data.to) ? String(d.data.to) : '';
      var target = ST.path;
      moveReset();
      showPane('list');
      loadFiles(target, function () {
        setStatus(to !== '' ? ('Dipindahkan ke ' + to) : 'Dipindahkan.');
      });
    }).catch(function () {
      ST.moveBusy = false;
      if (sp) sp.classList.add('d-none');
      if (cf) cf.disabled = false;
      moveSetStatus('');
      showAlert('Gagal terhubung ke server.');
      setStatus('');
    });
  }

  // ----------------------------------------------------------- edit teks

  function startEdit(name) {
    var path = childPath(name);
    clearAlert();
    setStatus('Memuat berkas ...');
    jsonFetch('/api/apps/' + enc(ST.appId) + '/files/read?container=' + enc(ST.container) + '&path=' + enc(path))
      .then(function (d) {
        if (!d || d.code !== 0 || !d.data) {
          showAlert(errMsg(d, 'Gagal membuka berkas.'));
          setStatus('');
          return;
        }
        var data = d.data;
        ST.editPath = (typeof data.path === 'string' && data.path !== '') ? data.path : path;
        ST.editName = name;

        var nameEl = el('files-edit-name');
        if (nameEl) nameEl.textContent = ST.editName;
        var ta = el('files-editor');
        if (ta) ta.value = typeof data.text === 'string' ? data.text : '';

        var meta = el('files-edit-meta');
        if (meta) {
          meta.textContent = formatSize(data.size) + (data.truncated ? ' · dimuat sebagian' : '');
        }
        var save = el('files-edit-save');
        if (save) save.disabled = !!data.truncated;
        if (data.truncated) {
          showAlert('Berkas terlalu besar untuk dimuat penuh — penyimpanan dinonaktifkan agar isi berkas tidak terpotong.');
        }
        showPane('edit');
        setStatus('');
        if (ta) ta.focus();
      })
      .catch(function () { showAlert('Gagal terhubung ke server.'); setStatus(''); });
  }

  function saveEdit() {
    var ta = el('files-editor');
    if (!ta) return;
    var sp = el('files-edit-spinner');
    var btn = el('files-edit-save');
    if (sp) sp.classList.remove('d-none');
    if (btn) btn.disabled = true;
    clearAlert();

    post('/apps/' + enc(ST.appId) + '/files/write', {
      container: ST.container,
      path: ST.editPath,
      text: ta.value
    }).then(function (d) {
      if (sp) sp.classList.add('d-none');
      if (!d || d.code !== 0) {
        if (btn) btn.disabled = false;
        showAlert(errMsg(d, 'Gagal menyimpan berkas.'));
        return;
      }
      setStatus('Berkas disimpan.');
      loadFiles(ST.path);
    }).catch(function () {
      if (sp) sp.classList.add('d-none');
      if (btn) btn.disabled = false;
      showAlert('Gagal terhubung ke server.');
    });
  }

  // ------------------------------------------------------------ unggahan

  function resetUploads() {
    ST.aborted = true;
    ST.queue = [];
    ST.uploadedAny = false;
    for (var i = 0; i < ST.xhrs.length; i++) {
      try { ST.xhrs[i].abort(); } catch (e) {}
    }
    ST.xhrs = [];
    ST.curXhr = null;
    var box = el('files-uploads');
    if (box) box.textContent = '';
  }

  function uploadRow(name) {
    var box = el('files-uploads');
    if (!box) return null;

    var wrap = document.createElement('div');
    wrap.className = 'border rounded p-2 mb-1 small';

    var top = document.createElement('div');
    top.className = 'd-flex justify-content-between align-items-center gap-2';
    var nm = document.createElement('span');
    nm.className = 'mono text-truncate';
    nm.textContent = name;
    var lbl = document.createElement('span');
    lbl.className = 'text-muted flex-shrink-0';
    lbl.textContent = 'menunggu';
    top.appendChild(nm);
    top.appendChild(lbl);

    var prog = document.createElement('div');
    prog.className = 'progress mt-1';
    prog.style.height = '4px';
    var bar = document.createElement('div');
    bar.className = 'progress-bar';
    bar.style.width = '0%';
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', '100');
    bar.setAttribute('aria-valuenow', '0');
    prog.appendChild(bar);

    var err = document.createElement('div');
    err.className = 'text-danger mt-1 d-none';

    wrap.appendChild(top);
    wrap.appendChild(prog);
    wrap.appendChild(err);
    box.appendChild(wrap);

    return { bar: bar, label: lbl, err: err };
  }

  function failRow(row, msg) {
    if (!row) return;
    row.bar.className = 'progress-bar bg-danger';
    row.bar.style.width = '100%';
    row.label.textContent = 'gagal';
    row.err.textContent = msg;
    row.err.classList.remove('d-none');
  }

  function removeXhr(xhr) {
    var i = ST.xhrs.indexOf(xhr);
    if (i !== -1) ST.xhrs.splice(i, 1);
  }

  function startUpload(fileList) {
    if (!fileList || fileList.length === 0) return;
    resetUploads();
    ST.aborted = false;

    var skipped = 0;
    for (var i = 0; i < fileList.length; i++) {
      var f = fileList[i];
      var row = uploadRow(f.name);
      if (f.size > UPLOAD_LIMIT) {
        failRow(row, 'Melebihi batas ' + formatSize(UPLOAD_LIMIT) + '.');
        skipped++;
        continue;
      }
      ST.queue.push({ file: f, row: row });
    }

    if (ST.queue.length === 0) {
      setStatus('Tidak ada berkas yang diunggah.');
      return;
    }
    setStatus('Mengunggah ' + ST.queue.length + ' berkas ...');
    uploadNext();
  }

  function uploadNext() {
    if (ST.aborted) return;
    var item = ST.queue.shift();
    if (!item) {
      setStatus('Unggah selesai.');
      if (ST.uploadedAny) loadFiles(ST.path);
      return;
    }

    var fd = new FormData();
    fd.append('container', ST.container);
    if (ST.path) fd.append('path', ST.path);
    fd.append('_token', CSRF);
    fd.append('files[]', item.file, item.file.name);

    var xhr = new XMLHttpRequest();
    ST.curXhr = xhr;
    ST.xhrs.push(xhr);

    if (item.row) item.row.label.textContent = '0%';

    xhr.upload.onprogress = function (ev) {
      if (!ev.lengthComputable || !item.row) return;
      var pct = Math.round((ev.loaded / ev.total) * 100);
      item.row.bar.style.width = pct + '%';
      item.row.bar.setAttribute('aria-valuenow', String(pct));
      item.row.label.textContent = pct + '%';
    };

    xhr.onload = function () {
      removeXhr(xhr);
      if (ST.aborted) return;
      var d = parseJson(xhr.responseText);
      if (d && d.code === 0) {
        ST.uploadedAny = true;
        if (item.row) {
          item.row.bar.className = 'progress-bar bg-success';
          item.row.bar.style.width = '100%';
          item.row.label.textContent = 'selesai';
        }
      } else {
        failRow(item.row, errMsg(d, 'Gagal mengunggah (HTTP ' + xhr.status + ').'));
      }
      uploadNext();
    };

    xhr.onerror = function () {
      removeXhr(xhr);
      if (ST.aborted) return;
      failRow(item.row, 'Gagal terhubung ke server.');
      uploadNext();
    };

    xhr.onabort = function () {
      removeXhr(xhr);
      if (item.row) item.row.label.textContent = 'dibatalkan';
    };

    xhr.open('POST', '/apps/' + enc(ST.appId) + '/files/upload', true);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(fd);
  }

  // --------------------------------------------------------- siklus modal

  function prepare(appId, container) {
    ST.appId = appId || modal.getAttribute('data-app') || '';
    ST.container = container || '';
    resetUploads();
    moveReset();
    ST.aborted = false;
    ST.seq++;
    ST.path = null;
    ST.parent = null;
    ST.editPath = '';
    ST.editName = '';
    clearAlert();
    clearEntries();
    showPane('list');
    var cEl = el('files-container');
    if (cEl) cEl.textContent = ST.container;
    setStatus('');
    return ST.container !== '';
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('.files-btn') : null;
    if (!btn) return;
    ev.preventDefault();
    if (!prepare(btn.getAttribute('data-app') || '', btn.getAttribute('data-container') || '')) return;
    if (window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modal).show();
  });

  modal.addEventListener('shown.bs.modal', function (ev) {
    var rt = ev.relatedTarget;
    if (rt && rt.getAttribute && !ST.container) {
      prepare(rt.getAttribute('data-app') || '', rt.getAttribute('data-container') || '');
    }
    if (ST.container) loadFiles(ST.path);
    var tb = el('files-toolbar');
    if (tb) tb.focus();
  });

  modal.addEventListener('hidden.bs.modal', function () {
    resetUploads();
    moveReset();
    ST.seq++;
    ST.queue = [];
    ST.path = null;
    ST.editPath = '';
    var ta = el('files-editor');
    if (ta) ta.value = '';
    clearAlert();
    clearEntries();
    showPane('list');
    var up = el('files-up');
    if (up) up.disabled = true;
    setStatus('');
  });

  var upBtn = el('files-up');
  if (upBtn) {
    upBtn.addEventListener('click', function () {
      if (ST.parent) loadFiles(ST.parent);
    });
  }
  var refreshBtn = el('files-refresh');
  if (refreshBtn) refreshBtn.addEventListener('click', function () { loadFiles(ST.path); });
  var mkdirBtn = el('files-mkdir');
  if (mkdirBtn) mkdirBtn.addEventListener('click', makeFolder);
  var extractBtn = el('files-extract-btn');
  if (extractBtn) extractBtn.addEventListener('click', extractPrompt);
  var uploadBtn = el('files-upload-btn');
  var uploadInput = el('files-upload-input');
  if (uploadBtn && uploadInput) {
    uploadBtn.addEventListener('click', function () { uploadInput.click(); });
    uploadInput.addEventListener('change', function (ev) {
      startUpload(ev.target.files);
      ev.target.value = '';
    });
  }
  var editCancel = el('files-edit-cancel');
  if (editCancel) editCancel.addEventListener('click', function () { showPane('list'); clearAlert(); setStatus(''); });
  var editSave = el('files-edit-save');
  if (editSave) editSave.addEventListener('click', saveEdit);

  // Pindah (move): picker folder tujuan.
  var moveUp = el('files-move-up');
  if (moveUp) moveUp.addEventListener('click', function () { if (ST.moveParent) moveOpenDir(ST.moveParent); });
  var moveName = el('files-move-name');
  if (moveName) moveName.addEventListener('input', moveSyncConfirm);
  var moveCancel = el('files-move-cancel');
  if (moveCancel) {
    moveCancel.addEventListener('click', function () {
      moveReset();
      showPane('list');
      clearAlert();
      setStatus('');
    });
  }
  var moveConfirmBtn = el('files-move-confirm');
  if (moveConfirmBtn) moveConfirmBtn.addEventListener('click', moveConfirm);

  function abortPending() {
    resetUploads();
    moveReset();
    ST.seq++;
  }
  window.addEventListener('pagehide', abortPending);
  window.addEventListener('beforeunload', abortPending);
})();
