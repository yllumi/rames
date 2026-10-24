/* Rames — preferensi tema tampilan: terang / gelap / ikut sistem.
   Disimpan di localStorage kunci `rames.theme` (light|dark|system, default system).
   Tanpa dependensi: vanilla JS murni. Atribut html diset lebih awal oleh skrip
   boot di <head>; skrip ini menyinkronkan kontrol (tombol sidebar & radio Profil). */
(function () {
  'use strict';

  var KEY = 'rames.theme';
  var MODES = ['light', 'dark', 'system'];
  var root = document.documentElement;
  var media = (typeof window.matchMedia === 'function')
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null;

  function normalize(mode) {
    return MODES.indexOf(mode) >= 0 ? mode : 'system';
  }

  function getMode() {
    try {
      return normalize(localStorage.getItem(KEY));
    } catch (e) {
      return 'system'; /* mode privat / storage diblokir */
    }
  }

  function resolve(mode) {
    if (mode === 'light' || mode === 'dark') { return mode; }
    return (media && media.matches) ? 'dark' : 'light';
  }

  function labelFor(mode) {
    if (mode === 'light') { return 'terang'; }
    if (mode === 'dark') { return 'gelap'; }
    return 'ikut sistem';
  }

  function nextMode(mode) {
    if (mode === 'light') { return 'dark'; }
    if (mode === 'dark') { return 'system'; }
    return 'light';
  }

  /* Sinkronkan kontrol UI dengan mode aktif (tanpa menyentuh DOM di luar kontrol tema).
     Tombol siklus bukan toggle biner, jadi cukup `aria-label` dinamis (tanpa `aria-pressed`). */
  function syncUi(mode) {
    var i;
    var choices = document.querySelectorAll('[data-theme-choice]');
    for (i = 0; i < choices.length; i++) {
      choices[i].checked = choices[i].getAttribute('data-theme-choice') === mode;
    }
    var toggles = document.querySelectorAll('[data-theme-toggle]');
    for (i = 0; i < toggles.length; i++) {
      var text = 'Tema: ' + labelFor(mode) + ' — klik untuk ' + labelFor(nextMode(mode));
      toggles[i].setAttribute('aria-label', text);
      toggles[i].setAttribute('title', text);
    }
  }

  function apply(mode) {
    var resolved = resolve(mode);
    root.setAttribute('data-bs-theme', resolved);
    root.style.colorScheme = resolved;
    root.setAttribute('data-theme-mode', mode);
    syncUi(mode);
  }

  function setMode(mode) {
    var normalized = normalize(mode);
    try {
      localStorage.setItem(KEY, normalized);
    } catch (e) { /* mode privat / storage penuh: tetap terapkan untuk sesi ini */ }
    apply(normalized);
  }

  /* Tombol siklus di footer sidebar: light → dark → system → light. */
  var toggles = document.querySelectorAll('[data-theme-toggle]');
  for (var t = 0; t < toggles.length; t++) {
    toggles[t].addEventListener('click', function (event) {
      event.preventDefault();
      setMode(nextMode(getMode()));
    });
  }

  /* Radio pilihan di halaman Profil. */
  var choices = document.querySelectorAll('[data-theme-choice]');
  for (var c = 0; c < choices.length; c++) {
    choices[c].addEventListener('change', function () {
      if (this.checked) { setMode(this.getAttribute('data-theme-choice')); }
    });
  }

  /* Ikuti perubahan tema sistem hanya saat mode `system`. */
  if (media) {
    var onMediaChange = function () {
      if (getMode() === 'system') { apply('system'); }
    };
    if (typeof media.addEventListener === 'function') {
      media.addEventListener('change', onMediaChange);
    } else if (typeof media.addListener === 'function') {
      media.addListener(onMediaChange); /* Safari < 14 */
    }
  }

  /* Sinkron bila tema diubah di tab lain. */
  window.addEventListener('storage', function (event) {
    if (event.key === KEY) { apply(getMode()); }
  });

  /* Terapkan saat load agar radio/tombol sesuai mode tersimpan. */
  apply(getMode());

  if (!window.ramesTheme) {
    window.ramesTheme = { get: getMode, set: setMode };
  }
})();
