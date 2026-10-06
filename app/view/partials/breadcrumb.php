<?php
// Partial breadcrumb (presentasi murni, tanpa logika bisnis).
// Membaca $breadcrumbs: array item berbentuk ['label' => string, 'href' => string|null].
// Item terakhir = halaman aktif (tanpa tautan, aria-current="page").
?>
<?php if (!empty($breadcrumbs)): ?>
<nav aria-label="Breadcrumb" class="mb-3">
  <ol class="breadcrumb mb-0">
    <?php foreach ($breadcrumbs as $i => $crumb): $isLast = $i === array_key_last($breadcrumbs); ?>
      <li class="breadcrumb-item<?= $isLast ? ' active' : '' ?>"<?= $isLast ? ' aria-current="page"' : '' ?>>
        <?php if (!$isLast && !empty($crumb['href'])): ?>
          <a href="<?= e($crumb['href']) ?>"><?= e($crumb['label']) ?></a>
        <?php else: ?>
          <?= e($crumb['label']) ?>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
<?php endif; ?>
