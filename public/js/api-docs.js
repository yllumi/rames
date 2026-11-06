/**
 * Halaman `/api-docs` — sinkronkan tab aktif (`Endpoint` / `MCP`) ke URL.
 *
 * Tab awal sudah dirender server-side dari `?tab=`, jadi skrip ini hanya
 * memperbarui `?tab=` saat user berpindah tab (tanpa reload) agar refresh dan
 * tautan yang dibagikan mendarat di tab yang sama. Daftar tab tidak diduplikasi:
 * validitas diturunkan dari pane yang benar-benar ada di DOM.
 */
(function () {
  'use strict';

  var tabs = document.getElementById('api-docs-tabs');
  if (!tabs || !window.history || !window.history.replaceState) {
    return;
  }

  tabs.addEventListener('shown.bs.tab', function (event) {
    var name = event.target ? event.target.getAttribute('data-tab') : '';
    if (!name || !document.getElementById('pane-' + name)) {
      return;
    }

    var url = new URL(window.location.href);
    url.searchParams.set('tab', name);
    try {
      window.history.replaceState(null, '', url.pathname + url.search + url.hash);
    } catch (error) {
      // Lingkungan tanpa dukungan history: abaikan (tab tetap berfungsi).
    }
  });
})();
