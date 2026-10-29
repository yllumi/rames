// Metrik app di tab Info halaman detail (/apps/{id}): grafik CPU & memori
// rolling 5 menit + total storage app.
//
// Endpoint: GET /api/apps/{id}/metrics (ability `view`); `?storage=1`
// menambahkan blok storage. Poll metrik tiap 5 detik (maks 60 titik = 5
// menit), storage tiap 60 detik dengan timer terpisah. Keduanya dijeda saat
// tab tidak terlihat dan dimatikan saat halaman ditinggalkan.
//
// Aturan: data server hanya ditulis lewat `textContent` (tanpa innerHTML),
// kegagalan fetch/JSON diabaikan tanpa console/alert, dan respons dibuang
// bila elemen sudah dilepas dari DOM.
(function () {
  'use strict';

  var POLL_MS = 5000;
  var STORAGE_MS = 60000;
  var MAX_POINTS = 60;
  var FETCH_TIMEOUT_MS = 10000;
  var EMPTY = '—';
  var CHART_W = 300;
  var CHART_H = 80;
  var CHART_PAD = 3;

  var root = document.getElementById('app-metrics');
  if (!root) return;
  var appId = root.getAttribute('data-app-id') || '';
  if (!appId) return;

  var cpuValue = root.querySelector('[data-metrics-value="cpu"]');
  var memValue = root.querySelector('[data-metrics-value="mem"]');
  var memSub = root.querySelector('[data-metrics-sub="mem"]');
  var storageTotal = root.querySelector('[data-metrics-value="storage-total"]');
  var storageBreakdown = root.querySelector('[data-metrics-sub="storage-breakdown"]');
  var storageNote = root.querySelector('[data-metrics-note="storage"]');
  var usageNote = root.querySelector('[data-metrics-note="usage"]');
  var cpuLine = root.querySelector('[data-metrics-line="cpu"]');
  var memLine = root.querySelector('[data-metrics-line="mem"]');

  var points = [];
  var memCeil = 1;
  var usageTimer = null;
  var storageTimer = null;
  var usageBusy = false;
  var storageBusy = false;

  function alive() {
    return document.contains(root);
  }

  function numOrNull(value) {
    if (value === null || value === undefined || value === '' || typeof value === 'boolean') return null;
    var n = Number(value);
    return isFinite(n) ? n : null;
  }

  // Format byte basis 1000 (konsisten dengan `monitor.js` / `docker system df`);
  // nilai < 10 unit memakai 1 desimal. `null` ⇒ "—", bukan 0.
  function fmtBytes(bytes) {
    var n = numOrNull(bytes);
    if (n === null) return EMPTY;
    if (n < 1000) return Math.round(n) + 'B';
    var units = ['kB', 'MB', 'GB', 'TB', 'PB'];
    var i = -1;
    do { n = n / 1000; i++; } while (n >= 1000 && i < units.length - 1);
    return n.toFixed(n < 10 ? 1 : 0) + units[i];
  }

  function fmtPercent(value) {
    var n = numOrNull(value);
    return n === null ? EMPTY : n.toFixed(1) + '%';
  }

  // Polyline sederhana: x tersebar merata, y diskalakan 0..max.
  function drawLine(line, values, max) {
    if (!line) return;
    if (!values || values.length < 2 || !(max > 0)) {
      line.setAttribute('d', '');
      return;
    }
    var usable = CHART_H - CHART_PAD * 2;
    var span = values.length - 1;
    var d = '';
    for (var i = 0; i < values.length; i++) {
      var v = values[i];
      if (v < 0) v = 0;
      if (v > max) v = max;
      var x = (i / span) * CHART_W;
      var y = CHART_H - CHART_PAD - (v / max) * usable;
      d += (i === 0 ? 'M' : 'L') + x.toFixed(2) + ' ' + y.toFixed(2);
    }
    line.setAttribute('d', d);
  }

  function renderUsage(data) {
    if (!alive()) return;
    var usage = data && typeof data.usage === 'object' && data.usage ? data.usage : {};
    var cpu = numOrNull(usage.cpu_percent);
    var memUsed = numOrNull(usage.mem_used);
    var memLimit = numOrNull(usage.mem_limit);
    var memPercent = numOrNull(usage.mem_percent);
    if (memPercent === null && memUsed !== null && memLimit !== null && memLimit > 0) {
      memPercent = (memUsed / memLimit) * 100;
    }

    if (cpuValue) cpuValue.textContent = fmtPercent(cpu);
    if (memValue) memValue.textContent = fmtPercent(memPercent);
    if (memSub) {
      memSub.textContent = fmtBytes(memUsed) + ' / ' +
        (memLimit !== null && memLimit > 0 ? fmtBytes(memLimit) : EMPTY);
    }
    if (usageNote) {
      usageNote.textContent = (typeof usage.error === 'string' && usage.error !== '') ? usage.error : '';
    }

    // Skala memori: batas container app, tapi tetap menampung lonjakan.
    if (memLimit !== null && memLimit > memCeil) memCeil = memLimit;
    if (memUsed !== null && memUsed > memCeil) memCeil = memUsed;

    points.push({
      at: typeof data.at === 'string' ? data.at : '',
      cpu: cpu,
      mem: memUsed
    });
    if (points.length > MAX_POINTS) points = points.slice(points.length - MAX_POINTS);

    var cpuSeries = [];
    var memSeries = [];
    for (var i = 0; i < points.length; i++) {
      if (points[i].cpu !== null) cpuSeries.push(points[i].cpu);
      if (points[i].mem !== null) memSeries.push(points[i].mem);
    }
    drawLine(cpuLine, cpuSeries, 100);
    drawLine(memLine, memSeries, memCeil);
  }

  function renderStorage(storage) {
    if (!alive()) return;
    if (!storage || typeof storage !== 'object') {
      if (storageTotal) storageTotal.textContent = EMPTY;
      if (storageBreakdown) storageBreakdown.textContent = EMPTY;
      if (storageNote) storageNote.textContent = '';
      return;
    }

    var available = storage.available === true;
    var total = EMPTY;
    if (available) {
      total = (typeof storage.total_human === 'string' && storage.total_human !== '')
        ? storage.total_human
        : fmtBytes(storage.total_bytes);
    }
    if (storageTotal) storageTotal.textContent = total;
    if (storageBreakdown) {
      storageBreakdown.textContent = available
        ? 'volume ' + fmtBytes(storage.volumes_bytes) + ' · container ' + fmtBytes(storage.containers_bytes)
        : EMPTY;
    }
    if (storageNote) {
      var note = (typeof storage.error === 'string') ? storage.error : '';
      if (!available && note === '') {
        note = 'Total storage tidak tersedia (agent lama atau Docker Engine gagal membaca data).';
      }
      storageNote.textContent = note;
    }
  }

  function fetchJson(url) {
    var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timeout = null;
    var options = {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
      cache: 'no-store'
    };
    if (controller) {
      options.signal = controller.signal;
      timeout = setTimeout(function () { controller.abort(); }, FETCH_TIMEOUT_MS);
    }

    function clear() {
      if (timeout) { clearTimeout(timeout); timeout = null; }
    }

    return fetch(url, options)
      .then(function (response) {
        if (!response.ok) throw new Error('http ' + response.status);
        return response.json();
      })
      .then(function (payload) {
        if (!payload || payload.code !== 0 || !payload.data) throw new Error('payload');
        clear();
        return payload.data;
      }, function (error) {
        clear();
        throw error;
      });
  }

  function loadUsage() {
    if (usageBusy || !alive()) return;
    usageBusy = true;
    fetchJson('/api/apps/' + encodeURIComponent(appId) + '/metrics')
      .then(renderUsage)
      .catch(function () { /* diam: biarkan nilai terakhir / "—" */ })
      .then(function () { usageBusy = false; });
  }

  function loadStorage() {
    if (storageBusy || !alive()) return;
    storageBusy = true;
    fetchJson('/api/apps/' + encodeURIComponent(appId) + '/metrics?storage=1')
      .then(renderStorage)
      .catch(function () { /* diam: biarkan nilai terakhir / "—" */ })
      .then(function () { storageBusy = false; });
  }

  function stopTimers() {
    if (usageTimer) { clearInterval(usageTimer); usageTimer = null; }
    if (storageTimer) { clearInterval(storageTimer); storageTimer = null; }
  }

  function startTimers() {
    stopTimers();
    if (document.hidden) return;
    usageTimer = setInterval(loadUsage, POLL_MS);
    storageTimer = setInterval(loadStorage, STORAGE_MS);
  }

  function onVisibility() {
    if (document.hidden) {
      stopTimers();
      return;
    }
    loadUsage();
    loadStorage();
    startTimers();
  }

  function teardown() {
    stopTimers();
  }

  // Kembali dari bfcache: timer sudah dimatikan `pagehide`, hidupkan lagi.
  function onPageShow(event) {
    if (!event || !event.persisted) return;
    loadUsage();
    loadStorage();
    startTimers();
  }

  document.addEventListener('visibilitychange', onVisibility);
  window.addEventListener('pagehide', teardown);
  window.addEventListener('beforeunload', teardown);
  window.addEventListener('pageshow', onPageShow);

  // Muat langsung saat halaman tampil; tab tersembunyi menunggu
  // `visibilitychange` agar tidak ada permintaan latar belakang.
  if (!document.hidden) {
    loadUsage();
    loadStorage();
    startTimers();
  }
})();
