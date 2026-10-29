/* Rames — pemilih mode tampilan halaman Apps: baris (rows) / thumbnail (thumbs).
   Preferensi disimpan per browser di localStorage kunci `rames.apps.view`
   (`rows` | `thumbs`, default `rows`). Tanpa dependensi: vanilla JS murni.

   Kontrak markup (dirender `app/view/app/index.php`):
     - `#apps-rows`   tabel daftar app (default tampil)
     - `#apps-thumbs` grid kartu (awalnya `hidden` + `d-none`, aman tanpa JS)
     - `[data-apps-view-mode="rows|thumbs"]` tombol di `.page-head`
   Bila elemen tidak ada (halaman lain), skrip berhenti tanpa efek samping. */
(function () {
  'use strict';

  var KEY = 'rames.apps.view';
  var MODES = ['rows', 'thumbs'];
  var DEFAULT_MODE = 'rows';

  function normalize(mode) {
    return MODES.indexOf(mode) >= 0 ? mode : DEFAULT_MODE;
  }

  function readMode() {
    try {
      return normalize(localStorage.getItem(KEY));
    } catch (e) {
      return DEFAULT_MODE; /* storage diblokir / mode privat */
    }
  }

  function writeMode(mode) {
    try {
      localStorage.setItem(KEY, mode);
    } catch (e) {
      /* gagal simpan: mode tetap berlaku untuk tampilan saat ini */
    }
  }

  function panelFor(mode) {
    return document.getElementById(mode === 'thumbs' ? 'apps-thumbs' : 'apps-rows');
  }

  function setPanel(panel, visible) {
    if (!panel) { return; }
    if (visible) {
      panel.removeAttribute('hidden');
      panel.classList.remove('d-none');
    } else {
      panel.setAttribute('hidden', '');
      panel.classList.add('d-none');
    }
  }

  function setButton(button, selected) {
    if (selected) {
      button.classList.add('active');
      button.setAttribute('aria-pressed', 'true');
    } else {
      button.classList.remove('active');
      button.setAttribute('aria-pressed', 'false');
    }
  }

  function apply(mode) {
    var normalized = normalize(mode);
    setPanel(panelFor('rows'), normalized === 'rows');
    setPanel(panelFor('thumbs'), normalized === 'thumbs');

    var buttons = document.querySelectorAll('[data-apps-view-mode]');
    for (var i = 0; i < buttons.length; i++) {
      setButton(buttons[i], buttons[i].getAttribute('data-apps-view-mode') === normalized);
    }
  }

  function init() {
    var buttons = document.querySelectorAll('[data-apps-view-mode]');
    if (!buttons.length || (!panelFor('rows') && !panelFor('thumbs'))) {
      return; /* bukan halaman Apps (atau daftar app kosong) */
    }

    apply(readMode());

    for (var i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function (event) {
        event.preventDefault();
        var mode = normalize(this.getAttribute('data-apps-view-mode'));
        apply(mode);
        writeMode(mode);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
