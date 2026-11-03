/* Rames — ikon SVG untuk kode JS.
   Geometri ikon TIDAK diduplikasi di sini: `<symbol id="ico-*">` dirender
   `app/view/partials/icon-sprite.php` (sumber: `icon_paths()` di
   `app/functions.php`; ikon rail menu tetap inline di `partials/header.php`).
   Jangan memakai emoji/glif sebagai ikon.

   Dua bentuk sengaja disediakan:
   - `ramesIconNode(name, className)` → **Node** SVG lewat DOM API. Ini bentuk
     wajib untuk kode dinamis: tidak ada string HTML yang disisipkan, jadi nama
     ikon/kelas tidak pernah menjadi markup.
   - `ramesIcon(name, className)` → **string** markup, HANYA untuk template
     markup statis di view (tanpa data pengguna). */
(function () {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';
  var XLINK_NS = 'http://www.w3.org/1999/xlink';

  /**
   * Elemen `<svg><use href="#ico-…"></use></svg>` sebagai Node (DOM API).
   * `xlink:href` ikut diisi untuk browser lama yang belum mengenal `href` pada
   * `<use>`; atribut lain sama dengan `ramesIcon()`.
   * @param {string} name nama ikon (mis. 'eye', 'folder', 'trash')
   * @param {string} [className] kelas tambahan
   * @returns {SVGElement} elemen SVG siap di-`appendChild`/`replaceChildren`
   */
  window.ramesIconNode = function (name, className) {
    var ref = '#ico-' + String(name);
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'icon' + (className ? ' ' + className : ''));
    svg.setAttribute('width', '1em');
    svg.setAttribute('height', '1em');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var use = document.createElementNS(SVG_NS, 'use');
    use.setAttribute('href', ref);
    use.setAttributeNS(XLINK_NS, 'xlink:href', ref);
    svg.appendChild(use);
    return svg;
  };

  /**
   * Markup ikon garis sebagai **string** — hanya untuk markup statis di view.
   * Jangan menyisipkan hasilnya lewat `innerHTML` di kode dinamis; jalur
   * dinamis memakai `ramesIconNode()`.
   * @param {string} name nama ikon (mis. 'eye', 'folder', 'trash')
   * @param {string} [className] kelas tambahan
   * @returns {string} markup `<svg class="icon …"><use href="#ico-…"></use></svg>`
   */
  window.ramesIcon = function (name, className) {
    return '<svg class="icon' + (className ? ' ' + className : '') + '" width="1em" height="1em"' +
      ' aria-hidden="true" focusable="false"><use href="#ico-' + String(name) + '"></use></svg>';
  };
})();
