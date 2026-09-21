// Monitoring resource container & VM (SPECS §8d) — halaman /monitor.
//
// Data lengkap (host + container) diambil sekali saat halaman dibuka dan lewat
// tombol Refresh. Kartu HOST kemudian dipoll berkala (default 7 detik) selama
// halaman ini terbuka — poll hanya membaca `/proc` (file lokal, tanpa Docker
// Engine); interval dijeda saat tab tidak terlihat dan dimatikan saat halaman
// ditinggalkan, jadi tidak ada permintaan latar belakang.
(function () {
  'use strict';

  var EMPTY = '—';

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Format byte dengan basis 1000 (konsisten dengan `docker system df`).
  function fmtBytes(bytes) {
    if (bytes === null || bytes === undefined || isNaN(bytes)) return EMPTY;
    var n = Number(bytes);
    if (n < 1000) return n + 'B';
    var units = ['kB', 'MB', 'GB', 'TB', 'PB'];
    var i = -1;
    do { n = n / 1000; i++; } while (n >= 1000 && i < units.length - 1);
    var dec = n < 10 ? 2 : (n < 100 ? 1 : 0);
    return n.toFixed(dec) + units[i];
  }

  function fmtUptime(seconds) {
    if (seconds === null || seconds === undefined || isNaN(seconds)) return EMPTY;
    var s = Math.max(0, Math.floor(Number(seconds)));
    var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    if (d > 0) return d + 'd ' + h + 'j';
    if (h > 0) return h + 'j ' + m + 'm';
    if (m > 0) return m + 'm';
    return s + 's';
  }

  function fmtPercent(value) {
    return (value === null || value === undefined || isNaN(value)) ? EMPTY : Number(value) + '%';
  }

  function barClass(percent) {
    if (percent === null || percent === undefined) return 'bg-secondary';
    if (percent >= 90) return 'bg-danger';
    if (percent >= 75) return 'bg-warning';
    return 'bg-primary';
  }

  function setText(panel, key, text) {
    Array.prototype.forEach.call(panel.querySelectorAll('[data-m="' + key + '"]'), function (el) {
      el.textContent = text;
    });
  }

  function setBar(panel, key, percent) {
    Array.prototype.forEach.call(panel.querySelectorAll('[data-m="' + key + '"]'), function (el) {
      var pct = (percent === null || percent === undefined || isNaN(percent)) ? 0 : Math.min(100, Math.max(0, Number(percent)));
      el.style.width = pct + '%';
      el.className = 'progress-bar ' + barClass(percent);
    });
  }

  function renderHost(panel, host) {
    var cpu = host.cpu_percent;
    setText(panel, 'host-cpu', fmtPercent(cpu));
    setBar(panel, 'host-cpu-bar', cpu);
    setText(panel, 'host-cpu-count', host.cpu_count ? host.cpu_count + ' vCPU' : EMPTY);
    setText(panel, 'host-load',
      (host.load_1 === null || host.load_1 === undefined)
        ? EMPTY
        : host.load_1 + ' / ' + host.load_5 + ' / ' + host.load_15);

    setText(panel, 'host-mem', (host.mem_total === null || host.mem_total === undefined)
      ? EMPTY
      : fmtBytes(host.mem_used) + ' / ' + fmtBytes(host.mem_total));
    setText(panel, 'host-mem-percent', fmtPercent(host.mem_percent));
    setBar(panel, 'host-mem-bar', host.mem_percent);
    setText(panel, 'host-mem-sub', (host.mem_available === null || host.mem_available === undefined)
      ? EMPTY
      : 'tersedia ' + fmtBytes(host.mem_available));

    setText(panel, 'host-uptime', fmtUptime(host.uptime_seconds));
    setText(panel, 'host-proc', host.available ? host.proc_path : 'tidak terbaca');
  }

  function renderTotals(panel, totals) {
    setText(panel, 'totals', totals.containers + ' container');
    setText(panel, 'totals-cpu', 'CPU ' + fmtPercent(totals.cpu_percent));
    setText(panel, 'totals-mem', (totals.mem_used ? 'memori ' + fmtBytes(totals.mem_used) : 'memori ' + EMPTY) +
      (totals.mem_limit ? ' / ' + fmtBytes(totals.mem_limit) : '') +
      ' · ' + totals.running + ' jalan');
  }

  function memCell(row) {
    if (row.mem_used === null || row.mem_used === undefined) {
      return '<span class="text-muted">' + EMPTY + '</span>';
    }
    var label = fmtBytes(row.mem_used) + (row.mem_limit ? ' / ' + fmtBytes(row.mem_limit) : '');
    var html = '<div class="small text-nowrap">' + label;
    if (row.mem_percent !== null && row.mem_percent !== undefined) {
      html += ' <span class="text-muted">(' + row.mem_percent + '%)</span>';
    }
    html += '</div>';
    if (row.mem_percent !== null && row.mem_percent !== undefined) {
      html += '<div class="progress" style="height:4px"><div class="progress-bar ' + barClass(row.mem_percent) +
        '" style="width:' + Math.min(100, Math.max(0, row.mem_percent)) + '%"></div></div>';
    }
    return html;
  }

  // Status container dari Engine (running/exited/…) → badge; 'health' dari
  // healthcheck compose (healthy/unhealthy/starting) → badge Bootstrap.
  function statusCell(row) {
    var state = String(row.state || 'unknown').toLowerCase().replace(/[^a-z0-9-]/g, '') || 'unknown';
    var html = '<span class="badge badge-' + state + '">' + esc(row.state || 'unknown') + '</span>';
    if (row.health) {
      var tone = row.health === 'healthy' ? 'text-bg-success'
        : (row.health === 'unhealthy' ? 'text-bg-danger' : 'text-bg-warning');
      html += ' <span class="badge ' + tone + '">' + esc(row.health) + '</span>';
    }
    return html;
  }

  function rowHtml(row) {
    var appLabel = row.app_name
      ? '<a href="/apps/' + encodeURIComponent(row.app_id) + '">' + esc(row.app_name) + '</a>'
      : '<span class="text-muted" title="Container di luar app dashboard">eksternal</span>';
    if (row.project) {
      appLabel += '<div class="text-muted small mono">' + esc(row.project) + '</div>';
    }

    var title = esc(row.image) + (row.error ? ' · ' + esc(row.error) : '');

    return '<tr title="' + title + '">' +
      '<td>' + appLabel + '</td>' +
      '<td><span class="mono">' + esc(row.name) + '</span>' +
        (row.service ? '<div class="text-muted small">' + esc(row.service) + '</div>' : '') + '</td>' +
      '<td>' + statusCell(row) + '</td>' +
      '<td class="small">' + fmtUptime(row.uptime_seconds) + '</td>' +
      '<td class="text-end small">' + (row.restart_count === null || row.restart_count === undefined ? EMPTY : row.restart_count) + '</td>' +
      '<td class="text-end small">' + fmtPercent(row.cpu_percent) + '</td>' +
      '<td>' + memCell(row) + '</td>' +
      '<td class="text-end small">' + (row.pids === null || row.pids === undefined ? EMPTY : row.pids) + '</td>' +
      '</tr>';
  }

  function scopeLabel(scope) {
    if (scope === 'all') return 'Semua container di host (admin)';
    if (scope === 'app') return 'Container app ini';
    return 'Hanya container app yang bisa Anda akses';
  }

  function initPanel(panel) {
    var endpoint = panel.getAttribute('data-endpoint');
    var hostEndpoint = panel.getAttribute('data-host-endpoint');
    var pollMs = parseInt(panel.getAttribute('data-poll-ms'), 10);
    if (isNaN(pollMs) || pollMs < 1000) pollMs = 0;
    var refreshBtn = panel.querySelector('[data-m="refresh"]');
    var errorEl = panel.querySelector('[data-m="error"]');
    var rowsEl = panel.querySelector('[data-m="rows"]');
    var loaded = false;
    var busy = false;
    var fullBusy = false;
    var timer = null;
    var lastAt = '';

    if (!endpoint) return;

    function showError(message) {
      if (!errorEl) return;
      errorEl.textContent = message;
      errorEl.classList.remove('d-none');
    }

    function clearError() {
      if (!errorEl) return;
      errorEl.textContent = '';
      errorEl.classList.add('d-none');
    }

    function loadDisk() {
      // Total volume terpakai: endpoint yang sama dengan halaman /volumes
      // (sudah disaring sesuai hak akses user) — `GET /system/df` mahal, jadi
      // dimuat terpisah tanpa menahan kartu lain.
      fetch('/api/volumes/usage', { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || d.code !== 0) throw new Error((d && d.msg) ? d.msg : 'gagal');
          setText(panel, 'disk', (d.data && d.data.total_human) ? d.data.total_human : EMPTY);
        })
        .catch(function () { setText(panel, 'disk', 'tidak tersedia'); });
    }

    function footerNote(count) {
      var note = 'Diperbarui ' + lastAt + ' · ' + count + ' container';
      if (pollMs > 0) {
        note += ' · host diperbarui otomatis tiap ' + Math.round(pollMs / 1000) + ' detik';
      }
      return note + ' · tekan Refresh untuk memuat ulang semuanya.';
    }

    function render(payload) {
      renderHost(panel, payload.host || {});
      renderTotals(panel, payload.totals || { containers: 0, running: 0, cpu_percent: null, mem_used: null, mem_limit: null });
      setText(panel, 'scope', scopeLabel(payload.scope));
      lastAt = payload.at || '';

      var rows = payload.containers || [];
      if (rows.length === 0) {
        rowsEl.innerHTML = '<tr><td colspan="8" class="text-muted small">' +
          esc(panel.getAttribute('data-empty-note') || 'Tidak ada container.') + '</td></tr>';
      } else {
        rowsEl.innerHTML = rows.map(rowHtml).join('');
      }
      setText(panel, 'footer', footerNote(rows.length));

      if (payload.error) {
        showError(payload.error);
      } else {
        clearError();
      }
    }

    function load() {
      if (busy || fullBusy) return;
      fullBusy = true;
      if (refreshBtn) refreshBtn.disabled = true;
      setText(panel, 'status', 'Memuat metrik …');
      if (!loaded) {
        rowsEl.innerHTML = '<tr><td colspan="8" class="text-muted small">Memuat …</td></tr>';
      }

      fetch(endpoint, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || d.code !== 0 || !d.data) {
            throw new Error((d && d.msg) ? d.msg : 'Gagal memuat metrik.');
          }
          render(d.data);
          loaded = true;
          setText(panel, 'status', 'Diperbarui ' + (d.data.at || '') + '.');
        })
        .catch(function (err) {
          showError((err && err.message) ? err.message : 'Gagal memuat metrik.');
          setText(panel, 'status', 'Gagal memuat.');
        })
        .then(function () {
          fullBusy = false;
          if (refreshBtn) refreshBtn.disabled = false;
        });
    }

    // Poll ringan: hanya metrik host (`/proc`), bukan `stats` container.
    function pollHost() {
      if (!hostEndpoint || busy || fullBusy || document.hidden) return;
      busy = true;
      fetch(hostEndpoint, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || d.code !== 0 || !d.data) throw new Error('gagal');
          renderHost(panel, d.data.host || {});
          setText(panel, 'status', 'Host diperbarui otomatis ' + (d.data.at || '') + '.');
        })
        .catch(function () {
          // kegagalan sesaat tidak boleh mengganggu tampilan — akan dicoba lagi
          setText(panel, 'status', 'Gagal memperbarui metrik host — mencoba lagi …');
        })
        .then(function () { busy = false; });
    }

    function startPolling() {
      if (!pollMs || timer) return;
      timer = setInterval(pollHost, pollMs);
    }

    function stopPolling() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    if (refreshBtn) refreshBtn.addEventListener('click', function () { load(); loadDisk(); });

    // Polling hanya hidup selama halaman terbuka: dijeda saat tab tidak terlihat
    // (dan satu kali menyegarkan begitu kembali terlihat), dimatikan saat
    // halaman ditinggalkan.
    if (pollMs > 0) {
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
          stopPolling();
        } else {
          pollHost();
          startPolling();
        }
      });
      window.addEventListener('pagehide', stopPolling);
    }

    load();
    loadDisk();
    startPolling();
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-monitor-panel]'), initPanel);
  });
})();
