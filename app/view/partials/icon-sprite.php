<?php
// Sprite ikon SVG — simbol `#ico-*` dari `icon_paths()` (satu sumber geometri
// ikon). Dirender sekali di footer; dipakai `svg_icon()` (PHP) dan
// `ramesIcon()` (public/js/icons.js). Markup di sini milik aplikasi sendiri,
// bukan data pengguna, jadi dicetak apa adanya.
$__iconPaths = icon_paths();
?>
<svg class="icon-sprite" width="0" height="0" aria-hidden="true" focusable="false">
<?php foreach ($__iconPaths as $__iconName => $__iconBody): ?>
  <symbol id="ico-<?= e($__iconName) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $__iconBody ?></symbol>
<?php endforeach; ?>
</svg>
