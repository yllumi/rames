<?php
declare(strict_types=1);

namespace app\library\Backup;

use app\library\Docker\DockerClient;
use RuntimeException;

/**
 * Penentu image untuk **helper container** restic
 * (PLAN_VOLUME_BACKUP.md §3 "Image helper default = image dashboard",
 * §4.3 `VOLUME_BACKUP_IMAGE=  # kosong = image container dashboard`, §5.2).
 *
 * Kontrak yang ditegakkan:
 *  1. **override eksplisit** `VOLUME_BACKUP_IMAGE` selalu menang bila diisi;
 *  2. bila kosong ⇒ **image container dashboard** (dashboard memuat binary
 *     restic), dibaca dari Engine API dengan meng-inspect container ini sendiri
 *     — pola yang sama dengan `UpdateService::selfContext()` (`Config.Image`);
 *  3. **fail-fast** bila keduanya tak dapat ditentukan (mis. Engine tak dapat
 *     diakses): `RuntimeException` dengan pesan jelas — **tidak pernah** memakai
 *     nama image tebakan (yang akan gagal jauh di dalam `docker run`).
 *  4. **DNS helper** (`--dns`) diambil dari `HostConfig.Dns` container dashboard
 *     pada inspect yang **sama** (satu panggilan Engine) — `resolv.conf` host
 *     bisa rusak, jadi helper restic harus mewarisi DNS eksplisit dashboard
 *     (pola `UpdateService::selfContext()`). Bersifat **best-effort**: Engine tak
 *     terjangkau / DNS kosong ⇒ `[]` (perilaku lama), tanpa menggagalkan operasi.
 *
 * Bagian keputusan (`choose()`, `chooseDns()`) **statik murni** sehingga dapat
 * diuji tanpa I/O; bagian yang menyentuh Engine hanya `resolve()`/`dns()` (di
 * belakang memo `inspectOnce()`).
 *
 * Instance memoize hasil per instance (satu service = satu resolusi), **bukan**
 * cache lintas-request — service dibuat per request/run (worker persistent).
 */
class HelperImageResolver
{
    private ?DockerClient $docker;
    private string $configured;
    private string $containerId;

    /** Hasil resolusi image (memoize per instance). */
    private ?string $resolved = null;

    /** Hasil resolusi DNS (memoize per instance). */
    private ?array $resolvedDns = null;

    /** Hasil inspect container dashboard (memoize per instance; image + DNS satu panggilan). */
    private ?array $inspectCache = null;

    /** Penanda inspect sudah dicoba (membedakan "belum" dari "gagal"). */
    private bool $inspected = false;

    /** Pesan kegagalan inspect (untuk konteks error image). */
    private string $inspectError = '';

    /**
     * @param DockerClient|null $docker      Engine client (di-inject agar teruji tanpa Engine)
     * @param string|null       $configured  override `VOLUME_BACKUP_IMAGE`; null = baca config
     * @param string|null       $containerId identitas container dashboard; null = `gethostname()`
     */
    public function __construct(
        ?DockerClient $docker = null,
        ?string $configured = null,
        ?string $containerId = null,
    ) {
        $this->docker = $docker;
        $this->configured = self::clean($configured ?? (string) config('deploy.volume_backup_image', ''));

        $id = self::clean($containerId ?? (string) (gethostname() ?: ''));
        $this->containerId = $id !== ''
            ? $id
            : (string) config('deploy.dashboard_container', 'rames-webman');
    }

    /**
     * Terapkan default helper ke spec restic (image + DNS). Nilai yang **sudah**
     * diisi eksplisit (image non-kosong / `dns` non-kosong) tidak diubah — hormati
     * override pemanggil/test.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     * @throws RuntimeException bila image tidak dapat ditentukan
     */
    public function apply(array $spec): array
    {
        if (self::clean((string) ($spec['image'] ?? '')) === '') {
            $spec['image'] = $this->resolve();
        }

        // DNS dashboard best-effort; override `dns` eksplisit (non-kosong) menang.
        if (self::chooseDns((array) ($spec['dns'] ?? [])) === []) {
            $spec['dns'] = $this->dns();
        }

        return $spec;
    }

    /**
     * Image helper yang harus dipakai.
     *
     * @throws RuntimeException bila override kosong dan image dashboard tak terbaca
     */
    public function resolve(): string
    {
        if ($this->configured !== '') {
            return $this->configured;
        }
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $inspect = $this->inspectOnce();
        $dashboardImage = self::clean((string) ($inspect['Config']['Image'] ?? ''));

        return $this->resolved = self::choose('', $dashboardImage, $this->containerId, $this->inspectError);
    }

    /**
     * DNS container dashboard (`HostConfig.Dns`) untuk helper restic.
     *
     * **Best-effort**: Engine tak terjangkau / DNS kosong ⇒ `[]`, tanpa
     * menggagalkan backup/restore/status. Memoize per instance; memakai inspect
     * yang sama dengan `resolve()` (satu panggilan Engine).
     *
     * @return array<int,string>
     */
    public function dns(): array
    {
        if ($this->resolvedDns !== null) {
            return $this->resolvedDns;
        }

        $inspect = $this->inspectOnce();

        return $this->resolvedDns = self::chooseDns((array) ($inspect['HostConfig']['Dns'] ?? []));
    }

    /**
     * Inspect container dashboard **satu kali** per instance; hasil (atau
     * kegagalan) dipakai bersama resolusi image & DNS.
     *
     * @return array<string,mixed> `[]` bila Engine tak terjangkau
     */
    private function inspectOnce(): array
    {
        if ($this->inspected) {
            return $this->inspectCache ?? [];
        }
        $this->inspected = true;

        try {
            $docker = $this->docker
                ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
            $this->inspectCache = $docker->inspectContainer($this->containerId);
        } catch (\Throwable $e) {
            $this->inspectCache = null;
            $this->inspectError = $e->getMessage();
        }

        return $this->inspectCache ?? [];
    }

    /**
     * Keputusan murni (tanpa I/O): override eksplisit > image dashboard > gagal.
     *
     * @param string $error pesan kegagalan inspect (untuk konteks pesan error)
     * @throws RuntimeException
     */
    public static function choose(
        string $configured,
        string $dashboardImage,
        string $containerId = '',
        string $error = '',
    ): string {
        $configured = self::clean($configured);
        if ($configured !== '') {
            return $configured;
        }

        $dashboardImage = self::clean($dashboardImage);
        if ($dashboardImage !== '') {
            return $dashboardImage;
        }

        $detail = $error !== '' ? " — Docker Engine: {$error}" : '';
        throw new RuntimeException(
            'Image helper backup tidak bisa ditentukan: VOLUME_BACKUP_IMAGE kosong dan image container dashboard'
            . ($containerId !== '' ? " \"{$containerId}\"" : '')
            . " tidak terbaca{$detail}. Set VOLUME_BACKUP_IMAGE secara eksplisit."
        );
    }

    /**
     * Sanitasi DNS mentah dari `HostConfig.Dns` (statik murni, tanpa I/O):
     * cast tiap entri ke string, buang yang kosong, reindex. Sama persis dengan
     * penyaringan `UpdateService::selfContext()`.
     *
     * @param array<int|string,mixed> $rawDns
     * @return array<int,string>
     */
    public static function chooseDns(array $rawDns): array
    {
        return array_values(array_filter(array_map('strval', $rawDns)));
    }

    private static function clean(string $value): string
    {
        return trim($value);
    }
}
