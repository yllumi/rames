/**
 * Tombol "Salin" generik: elemen apa pun dengan `data-copy-target="<selector>"`
 * menyalin isi `value`/`textContent` elemen target ke clipboard.
 *
 * Dipakai kartu **API key** di halaman Profil dan blok konfigurasi **MCP** di
 * halaman `/api-docs`. Hanya teks statis yang ditulis ke tombol (tanpa
 * innerHTML/data pengguna). Clipboard API dipakai bila tersedia (konteks
 * aman); fallback `execCommand` untuk akses dashboard via http://IP.
 */
(function () {
  'use strict';

  var DEFAULT_LABEL = 'Salin';

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy-target]');
    if (!button) {
      return;
    }

    var selector = button.getAttribute('data-copy-target') || '';
    var field = selector === '' ? null : document.querySelector(selector);
    if (!field) {
      return;
    }

    var value = typeof field.value === 'string' ? field.value : (field.textContent || '');
    if (value === '') {
      return;
    }

    var feedback = function (ok) {
      button.textContent = ok ? 'Tersalin' : 'Gagal menyalin';
      window.setTimeout(function () {
        button.textContent = DEFAULT_LABEL;
      }, 1500);
    };

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(value).then(
        function () { feedback(true); },
        function () { feedback(false); }
      );
      return;
    }

    try {
      field.focus();
      field.select();
      feedback(document.execCommand('copy'));
    } catch (error) {
      feedback(false);
    }
  });
})();
