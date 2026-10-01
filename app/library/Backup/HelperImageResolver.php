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
 *
 * Bagian keputusan (`choose()`) **statik murni** sehingga dapat diuji tanpa I/O;
 * bagian yang menyentuh Engine hanya `resolve()`.
 *
 * Instance memoize hasil per instance (satu service = satu resolusi), **bukan**
 * cache lintas-request — service dibuat per request/run (worker persistent).
 */
class HelperImageResolver
{
    private ?DockerClient $docker;
    private string $configured;
    private string $containerId;

    /** Hasil resolusi (memoize per instance). */
    private ?string $resolved = null;

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
     * Terapkan image helper ke spec restic. Spec yang **sudah** memuat image
     * eksplisit tidak diubah (hormati override pemanggil/test).
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     * @throws RuntimeException bila image tidak dapat ditentukan
     */
    public function apply(array $spec): array
    {
        if (self::clean((string) ($spec['image'] ?? '')) !== '') {
            return $spec;
        }
        $spec['image'] = $this->resolve();

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

        $dashboardImage = '';
        $error = '';
        try {
            $docker = $this->docker
                ?? new DockerClient((string) config('deploy.docker_socket', '/var/run/docker.sock'), 30);
            $inspect = $docker->inspectContainer($this->containerId);
            $dashboardImage = self::clean((string) ($inspect['Config']['Image'] ?? ''));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->resolved = self::choose('', $dashboardImage, $this->containerId, $error);
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

    private static function clean(string $value): string
    {
        return trim($value);
    }
}
