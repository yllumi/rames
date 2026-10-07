<!-- Tab bar navigasi section app (scroll horizontal di layar sempit) -->
<ul class="nav nav-pills tab-scroll mb-3" id="appTabs" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-info-btn" data-bs-toggle="tab" data-bs-target="#tab-info" type="button" role="tab" aria-controls="tab-info" aria-selected="true">Info</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-containers-btn" data-bs-toggle="tab" data-bs-target="#tab-containers" type="button" role="tab" aria-controls="tab-containers" aria-selected="false">Container</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-deploy-btn" data-bs-toggle="tab" data-bs-target="#tab-deploy" type="button" role="tab" aria-controls="tab-deploy" aria-selected="false">Deployment</button></li>
  <?php if ($isCompose && $canCompose): ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-compose-btn" data-bs-toggle="tab" data-bs-target="#tab-compose" type="button" role="tab" aria-controls="tab-compose" aria-selected="false">Compose</button></li>
  <?php endif; ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-env-btn" data-bs-toggle="tab" data-bs-target="#tab-env" type="button" role="tab" aria-controls="tab-env" aria-selected="false">Environment</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-limits-btn" data-bs-toggle="tab" data-bs-target="#tab-limits" type="button" role="tab" aria-controls="tab-limits" aria-selected="false">Sumber Daya</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-network-btn" data-bs-toggle="tab" data-bs-target="#tab-network" type="button" role="tab" aria-controls="tab-network" aria-selected="false">Network</button></li>
  <?php if (!empty($dbContainers)): ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-db-btn" data-bs-toggle="tab" data-bs-target="#tab-db" type="button" role="tab" aria-controls="tab-db" aria-selected="false">Database</button></li>
  <?php endif; ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-domain-btn" data-bs-toggle="tab" data-bs-target="#tab-domain" type="button" role="tab" aria-controls="tab-domain" aria-selected="false">Domain &amp; SSL</button></li>
  <?php if ($canShare || !empty($access['members'])): ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-access-btn" data-bs-toggle="tab" data-bs-target="#tab-access" type="button" role="tab" aria-controls="tab-access" aria-selected="false">Akses</button></li>
  <?php endif; ?>
  <?php if ($canDelete): ?>
  <li class="nav-item" role="presentation"><button class="nav-link" id="tab-delete-btn" data-bs-toggle="tab" data-bs-target="#tab-delete" type="button" role="tab" aria-controls="tab-delete" aria-selected="false">Hapus App</button></li>
  <?php endif; ?>
</ul>
