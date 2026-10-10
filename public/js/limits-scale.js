// Slider Batas Sumber Daya (CPU & memori per service) pada form create app.
//
// Perilaku (vanilla, tanpa framework/CDN):
//  - setiap <input type="range" data-limit-slider> hanya menyimpan POSISI
//    (indeks) pada skala diskret yang dikirim server lewat `data-limit-stops`
//    (JSON, mis. [null,0.5,1,2,3,4]); indeks 0 = null = tanpa batas / default akun;
//  - nilai nyata ditulis ke input hidden `data-limit-target` (name="limits[...]")
//    agar form selalu mengirim nilai yang benar — termasuk saat JS mati (hidden
//    tetap berisi prefill server dan TIDAK pernah dikosongkan);
//  - setelah hidden berubah, event `input` di-dispatch ulang supaya skrip estimasi
//    kredit yang sudah ada (mendengarkan `input[name^="limits["]`) ikut ter-update;
//  - label [data-limit-display] + aria-valuetext diperbarui (aksesibilitas);
//  - catatan "nilai dari compose repo" [data-limit-repo-note] disembunyikan setelah
//    pengguna menggeser (nilai jadi pilihan eksplisit user).
(function () {
  'use strict';

  var sliders = document.querySelectorAll('[data-limit-slider]');
  if (!sliders.length) return;

  // Baca daftar titik skala; JSON rusak / bukan array → anggap tanpa titik (null).
  function readStops(slider) {
    try {
      var parsed = JSON.parse(slider.getAttribute('data-limit-stops') || '');
      return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
      return [];
    }
  }

  function sync(slider, stops) {
    // Indeks tidak sah (NaN / di luar rentang) → jatuh ke posisi 0.
    var index = parseInt(slider.value, 10);
    if (!isFinite(index) || index < 0 || index >= stops.length) index = 0;

    var raw = stops.length ? stops[index] : null;
    var isUnset = raw === null || raw === undefined;
    var unit = slider.getAttribute('data-limit-unit') || '';
    var label = isUnset ? (slider.getAttribute('data-limit-unset') || '') : String(raw) + ' ' + unit;

    var targetId = slider.getAttribute('data-limit-target') || '';
    var hidden = targetId ? document.getElementById(targetId) : null;
    if (hidden) {
      hidden.value = isUnset ? '' : String(raw);
      // Beri tahu skrip estimasi kredit tanpa menyentuh skrip itu sendiri.
      hidden.dispatchEvent(new Event('input', { bubbles: true }));
    }

    slider.setAttribute('aria-valuetext', label);

    var group = slider.closest('[data-limit-group]');
    if (group) {
      var display = group.querySelector('[data-limit-display]');
      if (display) display.textContent = label;

      // User sudah memilih eksplisit → catatan compose repo tidak lagi relevan.
      var note = group.querySelector('[data-limit-repo-note]');
      if (note) note.classList.add('d-none');
    }
  }

  Array.prototype.forEach.call(sliders, function (slider) {
    var stops = readStops(slider);
    function onSlide() { sync(slider, stops); }
    slider.addEventListener('input', onSlide);
    slider.addEventListener('change', onSlide);
  });
})();
