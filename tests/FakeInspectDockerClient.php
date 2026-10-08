<?php
declare(strict_types=1);

namespace Tests;

use app\library\Docker\DockerClient;

/**
 * Fake `DockerClient` untuk test yang butuh `inspectContainer()` (mis. membaca
 * `Config.User` container) tanpa Engine nyata. Sengaja tidak memanggil
 * konstruktor parent (tanpa Guzzle/socket).
 */
class FakeInspectDockerClient extends DockerClient
{
    public function __construct(private string $user = '', private bool $running = true)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function inspectContainer(string $id): array
    {
        return [
            'Id' => $id,
            'State' => ['Running' => $this->running],
            'Config' => ['User' => $this->user],
        ];
    }
}
