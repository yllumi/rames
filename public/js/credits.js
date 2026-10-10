// Halaman Kredit (/credits) — pemuat metode pembayaran top-up.
//
// Perilaku (tanpa framework/CDN):
//  - SATU kali fetch ke `/api/credits/methods?amount=…` saat modal top-up
//    PERTAMA kali dibuka (`shown.bs.modal`), mengisi <select> metode dengan
//    daftar asli dari gateway;
//  - diulang saat nominal berubah (debounce sederhana 500 ms) karena biaya kanal
//    bisa bergantung nominal;
//  - bila fetch gagal / fitur mati, <select> dibiarkan memakai daftar statis dari
//    server (tidak pernah mengosongkan pilihan user);
//  - semua timer dibersihkan saat halaman ditinggalkan (pagehide/beforeunload).
(function () {
  'use strict';

  var form = document.getElementById('credits-topup');
  if (!form) return;

  var modal = document.getElementById('topupModal');
  var select = document.getElementById('topup-method');
  var amountInput = document.getElementById('topup-amount');
  var creditsOut = document.getElementById('topup-credits');
  var hint = document.getElementById('topup-method-hint');
  if (!select || !amountInput) return;

  var methodsUrl = form.getAttribute('data-methods-url') || '/api/credits/methods';
  var perCredit = parseFloat(form.getAttribute('data-per-credit')) || 0;

  var timer = null;
  var lastAmount = null;
  var inflight = false;
  var methodsLoaded = false;

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Format angka gaya Indonesia: 2 desimal, ribuan '.', desimal ','.
  function fmtNumber(value) {
    if (value === null || value === undefined || isNaN(value)) return '—';
    var n = Math.round(Number(value) * 100) / 100;
    var parts = n.toFixed(2).split('.');
    var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return intPart + ',' + parts[1];
  }

  function fmtRupiah(value) {
    if (value === null || value === undefined || isNaN(value)) return '';
    return String(Math.round(Number(value))).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function updateEstimate() {
    if (!creditsOut) return;
    var value = parseInt(amountInput.value, 10);
    creditsOut.textContent = (isNaN(value) || perCredit <= 0) ? '—' : fmtNumber(value / perCredit);
  }

  function fillMethods(list) {
    if (!Array.isArray(list) || list.length === 0) return false;

    var current = select.value;
    var html = '';
    for (var i = 0; i < list.length; i++) {
      var method = list[i] || {};
      var code = String(method.code || '');
      if (!code) continue;
      var name = String(method.name || code);
      var fee = Number(method.fee || 0);
      var label = fee > 0 ? name + ' (biaya Rp' + fmtRupiah(fee) + ')' : name;
      html += '<option value="' + esc(code) + '">' + esc(label) + '</option>';
    }
    if (html === '') return false;

    select.innerHTML = html;
    if (current !== '') {
      for (var j = 0; j < select.options.length; j++) {
        if (select.options[j].value === current) { select.selectedIndex = j; break; }
      }
    }
    if (hint) hint.textContent = 'Metode dimuat dari gateway.';
    return true;
  }

  function loadMethods() {
    if (inflight) return;
    inflight = true;

    var amount = parseInt(amountInput.value, 10) || 0;
    var url = methodsUrl + '?amount=' + encodeURIComponent(amount);

    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (payload && payload.code === 0 && fillMethods(payload.data)) return;
        if (hint) hint.textContent = 'Daftar metode gateway tidak tersedia; memakai daftar bawaan.';
      }, function () {
        if (hint) hint.textContent = 'Gagal memuat metode gateway; memakai daftar bawaan.';
      })
      .then(function () { inflight = false; });
  }

  function scheduleReload() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(function () {
      timer = null;
      loadMethods();
    }, 500);
  }

  // Fetch pertama ditunda sampai modal benar-benar terbuka. Alasan:
  //  - form hanya bisa dipakai di dalam modal, jadi tidak ada request ke gateway
  //    untuk user yang tidak pernah membuka top-up;
  //  - `shown.bs.modal` dijamin menyala setelah modal terlihat, sehingga <select>
  //    sudah ada dan interaktif saat diisi.
  function loadMethodsOnce() {
    if (methodsLoaded) return;
    // Bila fetch lain masih berjalan, jangan tandai sudah dimuat: percobaan
    // berikutnya (mis. kali kedua modal dibuka) masih boleh memuat.
    if (inflight) return;
    methodsLoaded = true;
    loadMethods();
  }

  amountInput.addEventListener('input', function () {
    updateEstimate();

    var value = parseInt(amountInput.value, 10) || 0;
    if (value !== lastAmount) {
      lastAmount = value;
      scheduleReload();
    }
  });

  function cleanup() {
    if (timer) { clearTimeout(timer); timer = null; }
  }

  window.addEventListener('pagehide', cleanup);
  window.addEventListener('beforeunload', cleanup);

  // Estimasi kredit tetap dihitung saat load (tidak butuh jaringan).
  updateEstimate();
  lastAmount = parseInt(amountInput.value, 10) || 0;

  // Bootstrap bundle dimuat di footer, SETELAH skrip ini — jadi kita tidak boleh
  // mengecek window.bootstrap saat init. Event `shown.bs.modal` adalah event DOM
  // di elemen modal, jadi listener tetap sah walau bundle belum jalan.
  if (modal) {
    modal.addEventListener('shown.bs.modal', loadMethodsOnce);

    // Jaring pengaman: bila sampai window load bundle tetap tak ada, modal tidak
    // akan bisa dibuka sama sekali — muat daftar langsung supaya <select> tidak
    // kosong bila markup dipaksa tampil.
    window.addEventListener('load', function () {
      if (!window.bootstrap) loadMethodsOnce();
    });
  } else {
    // Tanpa modal (mis. markup lama) → perilaku lama: muat saat halaman dibuka.
    loadMethodsOnce();
  }
})();
