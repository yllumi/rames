<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerClient;
use RuntimeException;

/**
 * Fake `DockerClient` untuk uji kolom `container_state` **live** pada
 * `GET /api/backups/status` (`VolumeBackupService::containerStatesLive()` /
 * `overlayLiveContainerStates()`).
 *
 * Menghitung jumlah panggilan Engine (bukti "SATU panggilan per respons"),
 * merekam timeout per-request yang dilewatkan pemanggil, dan dapat disuruh
 * melempar (simulasi Engine tak terjangkau → pemanggil jatuh ke nilai cache).
 *
 * Sengaja tidak memanggil konstruktor parent (tanpa socket/Guzzle/ext-curl).
 */
class FakeLiveStateDockerClient extends DockerClient
{
    /** Jumlah panggilan `listContainers()` sejak dibuat. */
    public int $listCalls = 0;

    /** Timeout per-request terakhir yang diterima (argumen posisional ke-2). */
    public ?int $lastTimeout = null;

    /** @var array<int,array> */
    private array $containers;

    private ?string $failure;

    /**
     * @param array<int,array> $containers baris tiruan `GET /containers/json`
     * @param string|null $failure pesan exception saat Engine "mati"
     */
    public function __construct(array $containers = [], ?string $failure = null)
    {
        // sengaja tidak memanggil parent (tanpa koneksi socket).
        $this->containers = $containers;
        $this->failure = $failure;
    }

    public function listContainers(array $filters = [], ?int $timeoutSeconds = null): array
    {
        $this->listCalls++;
        $this->lastTimeout = $timeoutSeconds;

        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->containers;
    }
}
