/* Rames — perilaku layout: lipat/buka sidebar (rail) di desktop.
   Mobile ditangani Bootstrap offcanvas (tombol burger di topbar), tanpa JS kustom.
   Tanpa dependensi: vanilla JS murni. */
(function () {
  'use strict';

  var STORAGE_KEY = 'rames.sidebar';
  var toggle = document.querySelector('[data-sidebar-toggle]');
  if (!toggle) { return; }

  var body = document.body;

  function isCollapsed() {
    return body.classList.contains('sidebar-collapsed');
  }

  function syncAria() {
    var collapsed = isCollapsed();
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle.setAttribute('aria-label', collapsed ? 'Buka navigasi' : 'Lipat navigasi');
  }

  function persist(collapsed) {
    try {
      localStorage.setItem(STORAGE_KEY, collapsed ? 'collapsed' : 'expanded');
    } catch (e) { /* mode privat / storage penuh: abaikan */ }
  }

  syncAria();

  toggle.addEventListener('click', function () {
    var collapsed = body.classList.toggle('sidebar-collapsed');
    persist(collapsed);
    syncAria();
  });
})();
