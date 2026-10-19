<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerClient;

/**
 * Docker Engine tiruan untuk uji pembatalan deploy (`statusAfterCancel()`).
 *
 * Berkas terpisah agar bisa dipakai beberapa berkas uji (PSR-4 `Tests\`).
 */
final class DeployCancellerFakeDocker extends DockerClient
{
    /**
     * @param array<int,string> $states state container (`running`, `exited`, …)
     */
    public function __construct(private readonly array $states = [])
    {
    }

    public function listContainersForProject(string $project): array
    {
        return array_map(static fn (string $state): array => ['State' => $state], $this->states);
    }
}
