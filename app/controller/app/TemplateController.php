<?php
declare(strict_types=1);

namespace app\controller\app;

use app\library\Auth\ServerAccess;
use app\library\Billing\InsufficientCredits;
use app\library\Billing\Pricing;
use app\library\Deploy\ComposeSource;
use app\library\Deploy\SubdomainManager;
use app\library\Docker\ComposeParser;
use app\library\Storage\AppStore;
use app\library\Storage\ServerStore;
use app\library\Support\Markdown;
use app\library\Template\TemplateCatalog;
use RuntimeException;
use support\Request;

/**
 * Deploy app dari template siap-pakai (compose prebuilt, docs/SPECS.md §7).
 *
 * Route: `GET /apps/create/template/{slug}`, `POST /apps/create/template/{slug}`.
 * Mewarisi jalur create/konfirmasi dari {@see CreateDeployController}.
 */
class TemplateController extends CreateDeployController
{
    /**
     * Form deploy template (langkah tunggal): nama app + field env yang
     * dideklarasikan template. Port/primary/prefix container tidak ditanyakan —
     * port diresolusi otomatis (konflik digeser ke port bebas), primary
     * diambil dari deklarasi template, prefix nama container = nama app
     * (docs/SPECS.md §7.2b).
     */
    public function templateForm(Request $request, string $slug)
    {
        $catalog = new TemplateCatalog();
        $template = $catalog->find($slug);
        if ($template === null) {
            flash_set('error', 'Template "' . $slug . '" tidak ditemukan.');
            return redirect('/apps/create?mode=template');
        }
        if (!$template['valid']) {
            flash_set('error', 'Template "' . $slug . '" tidak valid: ' . $template['error']);
            return redirect('/apps/create?mode=template');
        }

        // Server target untuk estimasi biaya: id yang diminta, tetapi hanya bila
        // user memang berhak memilihnya (id di luar jangkauan ⇒ estimasi lokal,
        // bukan membocorkan tarif/keberadaan server lain).
        $user = current_user();
        $requestedServer = is_scalar($request->get('server_id')) ? (string) $request->get('server_id') : '';
        $previewServerId = ServerAccess::maySelect($user, AppStore::normalizeServerId($requestedServer))
            ? AppStore::normalizeServerId($requestedServer)
            : ServerStore::LOCAL_ID;

        return view('app/template', $this->serverFormContext(
            $user,
            $requestedServer
        ) + [
            'template' => $template,
            'guide_html' => Markdown::toHtml((string) ($template['guide'] ?? '')),
            'form_name' => (string) $request->get('name', $template['slug']),
            'form_env' => [],
            'form_error' => null,
            'resourceLimits' => $this->limitsContext(
                (string) $template['dir'],
                [TemplateCatalog::COMPOSE_FILE],
                current_user() !== null,
                [],
                array_map('strval', array_keys((array) ($template['services'] ?? [])))
            ),
            'billing' => $this->billingContext(
                $this->defaultLimitsFor(array_map('strval', array_keys((array) ($template['services'] ?? [])))),
                $user,
                null,
                null,
                $previewServerId
            ),
            'limitsScaleMax' => $this->limitsScaleMax(),
        ]);
    }

    /**
     * Deploy app dari template (POST): satu klik, langsung spawn worker deploy.
     *
     * Urutan: validasi template/nama/env → materialisasi file template ke
     * `apps/{name}` → parse compose & resolusi host port → tulis override
     * port/nama + env → simpan entri app → worker deploy. Kegagalan sebelum
     * entri app dibuat membersihkan direktori app (tidak ada state setengah jadi).
     */
    public function templateDeploy(Request $request, string $slug)
    {
        $catalog = new TemplateCatalog();
        $template = $catalog->find($slug);
        $name = strtolower(trim((string) $request->post('name', '')));
        $subdomain = SubdomainManager::normalize((string) $request->post('subdomain', ''));
        $envInput = (array) $request->post('env', []);
        $env = [];
        $generated = [];
        $limits = [];
        $serverId = (string) ServerStore::LOCAL_ID;

        // Fase 1 — validasi yang tidak menyentuh disk (template, nama, nilai env).
        try {
            if ($template === null) {
                throw new RuntimeException('Template "' . $slug . '" tidak ditemukan.');
            }
            if (!$template['valid']) {
                throw new RuntimeException('Template "' . $slug . '" tidak valid: ' . $template['error']);
            }
            $this->validateCreateName($name);

            // Server target (admin, atau member bergrant `server_select`), lalu keunikan
            // nama & subdomain **per server** (plan §4.2).
            $server = $this->resolveCreateServer($this->requestedServerId($request), current_user());
            $serverId = (string) ($server['id'] ?? ServerStore::LOCAL_ID);

            if ((new AppStore())->nameExistsOnServer($name, $serverId)) {
                throw new RuntimeException("Nama app \"{$name}\" sudah dipakai di server ini.");
            }
            $this->assertSubdomainAvailable($subdomain, $name, '', $serverId);
            $env = $catalog->resolveEnv($template, $envInput);
            $generated = $catalog->generatedKeys($template, $envInput);

            // Batas CPU/memori: owner/member boleh memilih saat create (D2=a),
            // dibatasi plafon billing. Admin bebas (billing bukan untuk admin).
            $limits = $this->resolveLimits(
                (array) $request->post('limits', []),
                array_map('strval', array_keys((array) ($template['services'] ?? [])))
            );
            $user = current_user();
            if ($this->isBillingMember($user)) {
                Pricing::assertWithinCaps($limits);
                if ($limits === []) {
                    // Anti-lubang harga: app member selalu punya limit nyata.
                    $limits = $this->defaultLimitsFor(
                        array_map('strval', array_keys((array) ($template['services'] ?? [])))
                    );
                }
            }

            // Gerbang kredit — fail-fast SEBELUM materialisasi/efek samping;
            // tarif mengikuti server target yang sudah di-resolve.
            $this->billingAssertCanCreate($limits, $user, null, $serverId);
        } catch (InsufficientCredits $e) {
            return $this->billingBlocked($request, $e);
        } catch (\Throwable $e) {
            // Template tidak ada/rusak → tidak ada form yang bisa dirender ulang.
            if ($template === null || !$template['valid']) {
                flash_set('error', $e->getMessage());
                return redirect('/apps/create?mode=template');
            }

            // Kartu "Batas Sumber Daya" wajib ikut dirender ulang — kontrak sama
            // dengan templateForm(). View mem-prefill kolom dari `repo`, jadi
            // nilai yang dikirim user ditempatkan di sana juga agar tidak hilang.
            // Server terpilih juga dipulihkan dari request (bukan `$serverId` yang
            // masih default lokal saat resolve gagal).
            $postedLimits = $this->prefillLimits((array) $request->post('limits', []));
            $limitsContext = $this->limitsContext(
                (string) $template['dir'],
                [TemplateCatalog::COMPOSE_FILE],
                current_user() !== null,
                $postedLimits,
                array_map('strval', array_keys((array) ($template['services'] ?? [])))
            );
            foreach ($postedLimits as $service => $limit) {
                $limitsContext['repo'][$service] = $limit;
            }

            // Server untuk estimasi biaya: server yang diminta bila user berhak
            // memilihnya, selain itu server lokal hasil resolusi (default).
            $asked = AppStore::normalizeServerId($this->requestedServerId($request));
            $previewServerId = ServerAccess::maySelect(current_user(), $asked) ? $asked : $serverId;

            return view('app/template', $this->serverFormContext(
                current_user(),
                $this->requestedServerId($request)
            ) + [
                'template' => $template,
                'form_name' => $name,
                'form_env' => $envInput,
                'form_error' => $e->getMessage(),
                'resourceLimits' => $limitsContext,
                'billing' => $this->billingContext(
                    $postedLimits === []
                        ? $this->defaultLimitsFor(array_map('strval', array_keys((array) ($template['services'] ?? []))))
                        : $postedLimits,
                    current_user(),
                    null,
                    null,
                    $previewServerId
                ),
                'limitsScaleMax' => $this->limitsScaleMax(),
            ]);
        }

        // Fase 2 — materialisasi + pembuatan app.
        $dest = '';
        $app = null;
        $spawned = false;
        try {
            $dest = (string) config('deploy.apps_path') . '/' . $name;
            if (is_dir($dest)) {
                $this->cleanupDir($dest); // area apps_path dikelola sistem
            }
            if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                throw new RuntimeException('Gagal membuat direktori app.');
            }
            $catalog->materialize(
                $template,
                $dest,
                (int) config('deploy.compose_upload_max_file_bytes', ComposeSource::MAX_FILE_BYTES),
                (int) config('deploy.compose_upload_max_total_bytes', ComposeSource::MAX_TOTAL_BYTES)
            );

            $composeFile = ComposeSource::detectMainFile($dest);
            if ($composeFile === '') {
                throw new RuntimeException('File docker-compose.yml tidak ditemukan setelah template disalin.');
            }

            $parsed = (new ComposeParser())->parse($dest . '/' . $composeFile);
            $services = $this->resolveServicePorts($parsed['services'], $serverId);
            // Template tanpa `ports:` (mis. server database) tidak punya primary:
            // app dibuat tanpa vhost/subdomain (SPECS §7.2b).
            $tplPrimaryService = (string) ($template['primary']['service'] ?? '');
            $tplPrimaryPort = (int) ($template['primary']['port'] ?? 0);
            $primary = $this->resolvePrimarySelection(
                $services,
                $tplPrimaryService !== '' ? $tplPrimaryService . ':' . $tplPrimaryPort : '',
                $tplPrimaryService,
                $tplPrimaryPort
            );

            // Template dilarang menulis container_name → prefix = nama app.
            $containerPrefix = $this->resolveContainerPrefix($name, $composeFile, $name, $serverId);

            $pending = [
                'name' => $name,
                'source' => ComposeSource::SOURCE_COMPOSE,
                'repo_url' => null,
                'branch' => null,
                'local_path' => 'apps/' . $name,
                'compose_file' => $composeFile,
                'services' => $services,
                'primary_service' => $primary['service'],
                'primary_port' => $primary['port'],
                'auth_method' => 'none',
                'ssh_key' => null,
                'server_id' => $serverId,
            ];
            if ($subdomain !== '') {
                $pending['subdomain'] = $subdomain;
            }

            $result = $this->createAndDeploy(
                $pending,
                $services,
                $primary['service'],
                $primary['port'],
                $containerPrefix,
                $env,
                ['slug' => $template['slug'], 'title' => $template['title']],
                $limits,
                $subdomain
            );
            $app = $result['app'];
            $spawned = $result['spawned'];
        } catch (\Throwable $e) {
            // Belum ada entri app (createAndDeploy menulis file dulu, baru
            // apps.json) → direktori app aman dibersihkan.
            if ($app === null && $dest !== '' && is_dir($dest)) {
                $this->cleanupDir($dest);
            }
            // `createAndDeploy()` dapat memanggil agent (preflight nama app):
            // pesan transport memuat `base_url`/host server — redaksi dulu.
            $errorText = $this->appErrorText(['server_id' => $serverId], $e);
            if ($request->expectsJson()) {
                return json(['code' => 1, 'error' => $errorText]);
            }
            flash_set('error', $errorText);

            return view('app/template', $this->serverFormContext(current_user(), $this->requestedServerId($request)) + [
                'template' => $template,
                'form_name' => $name,
                'form_env' => $envInput,
                'form_error' => $errorText,
                'limitsScaleMax' => $this->limitsScaleMax(),
            ]);
        }

        $note = $generated !== []
            ? ' Nilai ' . implode(', ', $generated) . ' dibuat otomatis — lihat tab Environment untuk menyalin/mengubahnya.'
            : '';

        if ($request->expectsJson()) {
            return json([
                'code' => $spawned ? 0 : 1,
                'id' => $app['id'],
                'name' => $app['name'],
                'error' => $spawned ? null : 'Gagal menjalankan worker deploy.',
            ]);
        }

        if (!$spawned) {
            flash_set('error', 'App "' . $app['name'] . '" dibuat, tetapi worker deploy gagal dijalankan. Coba Deploy Ulang dari halaman detail.');
            return redirect('/apps/' . $app['id']);
        }

        flash_set('success', 'App "' . $app['name'] . '" dari template ' . $template['title'] . ' sedang di-deploy.' . $note);
        return redirect('/apps/' . $app['id']);
    }

    /**
     * Nilai batas CPU/memori dari input user untuk **prefill render ulang** —
     * best effort & tidak melempar (berbeda dari `resolveLimits()` yang
     * memvalidasi). Hanya nilai yang bisa tampil di input number yang
     * dipertahankan; sisanya dibuang supaya render ulang tidak gagal.
     *
     * @param array<string,mixed> $posted
     * @return array<string,array{cpus:?float,memory_mb:?int}>
     */
    private function prefillLimits(array $posted): array
    {
        $result = [];
        foreach ($posted as $service => $fields) {
            $service = trim((string) $service);
            if ($service === '' || !is_array($fields)) {
                continue;
            }

            $rawCpus = $fields['cpus'] ?? null;
            $rawMemory = $fields['memory_mb'] ?? null;

            $cpus = is_numeric($rawCpus) && !is_bool($rawCpus) ? (float) $rawCpus : null;
            $memory = is_int($rawMemory) || (is_string($rawMemory) && ctype_digit(trim($rawMemory)))
                ? (int) $rawMemory
                : null;

            if ($cpus === null && $memory === null) {
                continue;
            }
            $result[$service] = ['cpus' => $cpus, 'memory_mb' => $memory];
        }
        return $result;
    }
}
