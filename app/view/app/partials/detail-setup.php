<?php
$pageTitle = $app['name'];
$active = 'apps';
$status = $app['status'] ?? 'unknown';
$isBusy = in_array($status, ['deploying'], true);

// Hak akses user saat ini pada app (diatur AppAccess — satu pintu otorisasi).
$access = $access ?? [];
$role = $access['role'] ?? null;
$isOwner = $role === \app\library\Auth\AppAccess::ROLE_OWNER;
$abilities = $access['abilities'] ?? [];
$canOperate = (bool) ($abilities['operate'] ?? false);
$canDelete = (bool) ($abilities['delete'] ?? false);
$canShare = (bool) ($abilities['sharing'] ?? false);
$canEnv = (bool) ($abilities['env'] ?? false);
$canNetwork = (bool) ($abilities['network'] ?? false);
$canDomain = (bool) ($abilities['domain'] ?? false);
$canSsl = (bool) ($abilities['ssl'] ?? false);
$canRoutes = (bool) ($abilities['routes'] ?? false);
$canTerminal = (bool) ($abilities['terminal'] ?? false);
$canDb = (bool) ($abilities['database'] ?? false);
$canLogs = (bool) ($abilities['logs'] ?? false);
$canCompose = (bool) ($abilities['compose'] ?? false);
$canFiles = (bool) ($abilities['files'] ?? false);

// App mode compose = dibuat dari file docker-compose.yml (tanpa repo Git),
// sumbernya bisa diedit lewat tab Compose (tanpa rollback/checkpoint Git).
$isCompose = \app\library\Deploy\ComposeSource::isCompose($app);
$compose = $compose ?? null;

// custom domain + status SSL-nya
$customDomain = (string) ($app['custom_domain'] ?? '');
$customSslStatus = (string) ($app['custom_ssl_status'] ?? 'disabled');
$customSslExpiresAt = $app['custom_ssl_expires_at'] ?? null;
$customSslError = $app['custom_ssl_error'] ?? null;
$customSslActive = $customSslStatus === 'active';
$customSslPending = $customSslStatus === 'pending';
$customSslFailed = $customSslStatus === 'failed';
$sslSupported = \app\library\SSL\SslIssuer::isPublicDomain((string) config('deploy.app_domain', ''));

// Rute proxy tambahan per app (field apps.json `nginx_routes`) — dirender Nginx
// sebagai `location ^~ <path>` yang mem-proxy ke <target>.
$appRoutes = \app\library\Nginx\NginxRoutes::all($app);

// overlay status container live di atas data tersimpan
$liveByName = [];
foreach ($live as $lc) {
    $liveByName[$lc['container_name']] = $lc;
}
$containers = $app['containers'] ?? [];
foreach ($containers as &$c) {
    $lc = $liveByName[$c['container_name']] ?? null;
    if ($lc) {
        $c['status'] = $lc['status'] ?? $c['status'];
        $c['host_port'] = $lc['host_port'] ?? $c['host_port'];
        $c['internal_port'] = $lc['internal_port'] ?? $c['internal_port'];
        $c['ports'] = $lc['ports'] ?? ($c['ports'] ?? []);
    }
}
unset($c);

// Daftar port app (semua port yang dipublikasikan) + port yang di-proxy Nginx
// ke domain app — lihat AppPorts (satu implementasi dengan deployer).
$portContext = [
    'name' => $app['name'] ?? '',
    'containers' => $containers,
    'primary_service' => $app['primary_service'] ?? null,
    'primary_port' => $app['primary_port'] ?? null,
];
$appPorts = \app\library\Docker\AppPorts::all($portContext);
$proxiedPort = \app\library\Docker\AppPorts::proxiedContainerPort($portContext);
// App tanpa host port terpublish tidak di-proxy ke domain (tanpa vhost/subdomain)
$hasHostPort = \app\library\Docker\AppPorts::hasHostPort($portContext);

// Container default untuk modal log (service primary, else container pertama)
$logContainer = $canLogs ? \app\library\Docker\AppContainers::defaultContainer($app) : null;

$breadcrumbs = [
    ['label' => 'Apps', 'href' => '/apps'],
    ['label' => $app['name'], 'href' => null],
];
?>
