<link rel="stylesheet" href="/vendor/xterm/xterm.css">
<script>
// Aktifkan tab sesuai hash URL (mis. redirect balik ke #access setelah POST form
// ubah akses). Bootstrap dimuat di footer, jadi tunggu DOMContentLoaded.
document.addEventListener('DOMContentLoaded', function () {
  var hash = (location.hash || '').replace('#', '');
  if (!hash) return;
  var btn = document.getElementById('tab-' + hash + '-btn');
  if (!btn || typeof bootstrap === 'undefined') return;
  new bootstrap.Tab(btn).show();
});
</script>

<script src="/vendor/xterm/xterm.js"></script>
<script src="/vendor/xterm/addons/fit/fit.js"></script>
<script src="/js/app-terminal.js?v=6"></script>
<script src="/js/app-files.js?v=2"></script>
