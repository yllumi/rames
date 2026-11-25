<?php
declare(strict_types=1);

namespace app\library\SSL;

use app\library\Deploy\AgentDeployer;
use app\library\Deploy\ServerResolver;
use app\library\Domain\AppDomains;
use app\library\Docker\AppPorts;
use app\library\Storage\AppStore;
use RuntimeException;

/**
 * Permintaan **aktivasi SSL** satu domain app (Let's Encrypt) — satu sumber
 * untuk dashboard (`SslController::enable()/apiEnable()`) dan API eksternal
 * (`POST /api/v1/apps/{slug}/domains/{domain}/ssl`).
 *
 * Penerbitan **tidak** dijalankan di sini: permintaan divalidasi, status slot
 * ditandai `pending`, lalu job dimasukkan ke **antrean global** {@see SslQueue}
 * yang dieksekusi satu per satu oleh worker `cli/ssl.php`. Worker itu yang
 * menulis ulang config Nginx (blok ACME/443) + reload Nginx di server pemilik
 * app, lalu menjalankan certbot (app server agent ⇒ job `ssl_issue` di sana).
 *
 * Otorisasi app (`AppAccess`) milik pemanggil; service tidak membaca sesi.
 * Hasil selalu terstruktur (`reason` mesin) — pemanggil memetakan ke flash
 * (dashboard) atau status HTTP (API):
 *
 *  - `no_host_port` / `agent_domain` / `not_public` / `queue_failed` → validasi;
 *  - `not_owned` → domain bukan milik app (API ⇒ 404);
 *  - `already`   → slot sudah `active`/`pending`, **tanpa** efek samping;
 *  - `queued`    → job baru masuk antrean (status `pending`).
 */
final class SslActivationService
{
    private readonly ?AppStore $store;

    private readonly ?SslQueue $queue;

    private readonly ?ServerResolver $resolver;

    /**
     * @param AppStore|null       $store    seam uji: store app temp
     * @param SslQueue|null       $queue    seam uji: antrean temp (tanpa certbot)
     * @param ServerResolver|null $resolver seam uji: registry server temp
     */
    public function __construct(
        ?AppStore $store = null,
        ?SslQueue $queue = null,
        ?ServerResolver $resolver = null,
    ) {
        $this->store = $store;
        $this->queue = $queue;
        $this->resolver = $resolver;
    }

    /**
     * Ajukan aktivasi SSL untuk satu domain app.
     *
     * `$rawDomain` kosong ⇒ custom domain utama app, selain itu subdomain
     * bawaan (perilaku lama dashboard). Domain wajib milik app (subdomain,
     * domain utama, atau alias) — pemetaan satu pintu `AppDomains::slotOf()`.
     *
     * @param array<string,mixed> $app dokumen app (sudah lolos otorisasi)
     * @return array{ok:bool,error:?string,reason:string,status:string,domain:string,slot:?string,message:string,queued:bool,queue_position:int,queue_size:int}
     * @throws RuntimeException server pemilik app tidak dikenal / registry rusak
     */
    public function request(array $app, string $rawDomain = '', ?string $actorId = null): array
    {
        $store = $this->store();
        $appId = (string) ($app['id'] ?? '');

        // Server pemilik app (`server_id` tak dikenal ⇒ melempar; fail-fast).
        $server = ($this->resolver ?? new ServerResolver())->serverForApp($app);

        // Tanpa port terpublish tidak ada vhost Nginx → tidak ada yang bisa
        // di-SSL (subdomain app memang tidak dilayani, SPECS §7.2).
        if (!AppPorts::hasHostPort($app)) {
            return $this->rejected(
                'no_host_port',
                'App ini tidak mem-publish port host — tidak ada domain yang bisa di-SSL. Tambahkan `ports:` pada compose app (tab Compose) lalu Deploy Ulang.'
            );
        }

        // Fail-fast SEBELUM efek samping apa pun (status/worker): server agent
        // tanpa APP_DOMAIN (`domain`) tidak boleh dimintai sertifikat — domain
        // efektifnya akan diisi domain server lain dan sertifikatnya salah.
        if (!ServerResolver::isLocal($server)) {
            try {
                AgentDeployer::assertDomainBase($server, $app);
            } catch (RuntimeException $e) {
                return $this->rejected('agent_domain', $e->getMessage());
            }
        }

        $subdomain = app_subdomain_of($app);
        $customDomain = AppDomains::normalizeDomain((string) ($app['custom_domain'] ?? ''));
        $domain = AppDomains::normalizeDomain($rawDomain);

        if ($domain === '') {
            $domain = $customDomain !== '' ? $customDomain : AppDomains::normalizeDomain($subdomain);
        }

        // Domain milik app: subdomain, domain utama, atau domain tambahan —
        // pemetaannya satu pintu di `AppDomains` (`null` ⇒ bukan milik app).
        $slot = AppDomains::slotOf($app, $domain);
        if ($slot === null) {
            return $this->rejected('not_owned', "Domain {$domain} bukan milik app ini.", 'rejected', $domain);
        }

        if (!SslIssuer::isPublicDomain($domain)) {
            return $this->rejected(
                'not_public',
                "Domain {$domain} bukan domain publik — SSL otomatis tidak didukung (mis. TLD .local).",
                'rejected',
                $domain
            );
        }

        $status = (string) (AppDomains::status($app, $slot)['ssl_status'] ?? 'disabled');
        if (in_array($status, ['active', 'pending'], true)) {
            // Status slot yang sudah jalan bukan error — dashboard memakai flash
            // `info`, API membalas 200 idempoten (`already: true`).
            return $this->rejected(
                'already',
                "SSL untuk {$domain} sudah {$status}.",
                'already',
                $domain,
                $slot,
                $status
            );
        }

        $store->update($appId, static function (array &$s) use ($slot): void {
            AppDomains::writeStatus($s, $slot, [
                'ssl_status' => 'pending',
                'ssl_stage' => 'queued',
                'ssl_message' => 'Menunggu antrean SSL ...',
                'ssl_error' => null,
            ]);
            // Flag lama hanya untuk slot lama — alias menyimpan statusnya di
            // entri `custom_domains[]` (tidak ada flag `needs_ssl`).
            if ($slot === AppDomains::SLOT_PRIMARY) {
                $s['custom_needs_ssl'] = true;
            } elseif ($slot === AppDomains::SLOT_SUBDOMAIN) {
                $s['needs_ssl'] = true;
            }
        });

        try {
            // Satu antrean global: certbot memakai lock global + rate limit
            // Let's Encrypt, jadi penerbitan dijalankan satu per satu.
            $enqueued = $this->queue()->enqueueApp($app, $domain, $slot, $actorId);
        } catch (\Throwable $e) {
            $this->markFailed($store, $appId, $slot, 'Gagal memasukkan permintaan SSL ke antrean: ' . $e->getMessage());

            return $this->rejected('queue_failed', 'Gagal memasukkan permintaan SSL ke antrean.', 'failed', $domain, $slot);
        }

        $position = (int) ($enqueued['position'] ?? 0);
        $size = (int) ($enqueued['size'] ?? 0);

        return [
            'ok' => true,
            'error' => null,
            'reason' => 'queued',
            'status' => 'pending',
            'domain' => $domain,
            'slot' => $slot,
            'message' => "SSL untuk {$domain} masuk antrean (posisi {$position} dari {$size}).",
            'queued' => (bool) ($enqueued['queued'] ?? false),
            'queue_position' => $position,
            'queue_size' => $size,
        ];
    }

    /**
     * Hasil terstruktur penolakan bisnis (tanpa efek samping tambahan).
     *
     * @return array{ok:bool,error:?string,reason:string,status:string,domain:string,slot:?string,message:string,queued:bool,queue_position:int,queue_size:int}
     */
    private function rejected(
        string $reason,
        string $message,
        string $status = 'rejected',
        string $domain = '',
        ?string $slot = null,
        string $alreadyStatus = ''
    ): array {
        return [
            'ok' => false,
            'error' => $message,
            'reason' => $reason,
            'status' => $alreadyStatus !== '' ? $alreadyStatus : $status,
            'domain' => $domain,
            'slot' => $slot,
            'message' => $message,
            'queued' => false,
            'queue_position' => 0,
            'queue_size' => 0,
        ];
    }

    /**
     * Tandai slot `failed` + alasan saat permintaan tidak bisa masuk antrean.
     */
    private function markFailed(AppStore $store, string $id, string $slot, string $message): void
    {
        try {
            $store->update($id, static function (array &$s) use ($slot, $message): void {
                AppDomains::writeStatus($s, $slot, [
                    'ssl_status' => 'failed',
                    'ssl_stage' => null,
                    'ssl_message' => null,
                    'ssl_error' => $message,
                ]);
            });
        } catch (\Throwable) {
            // Status `pending` tanpa job bukan kegagalan yang bisa diperbaiki di
            // sini; pesan penolakan tetap dikembalikan ke pemanggil.
        }
    }

    private function queue(): SslQueue
    {
        return $this->queue ?? new SslQueue();
    }

    private function store(): AppStore
    {
        return $this->store ?? new AppStore();
    }
}
