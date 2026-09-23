<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;

/**
 * ProcessRunner palsu untuk unit test: mengembalikan keluaran tetap berdasarkan
 * potongan command, tanpa benar-benar menjalankan proses.
 *
 * Dipakai test self-update (RepoInfo/UpdateChecker/UpdateService) supaya logika
 * pemetaan & validasi bisa diuji tanpa repo nyata, tanpa jaringan, dan tanpa
 * daemon Docker.
 */
class FakeCommandRunner extends ProcessRunner
{
    /**
     * @var array<int,array{match:string,code:int,stdout:string,stderr:string}>
     */
    public array $responses = [];

    /**
     * @var array<int,string>
     */
    public array $calls = [];

    /**
     * Tambahkan jawaban untuk command yang mengandung $match.
     */
    public function reply(string $match, string $stdout, int $code = 0, string $stderr = ''): self
    {
        $this->responses[] = [
            'match' => $match,
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];

        return $this;
    }

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $line = implode(' ', $command);
        $this->calls[] = $line;

        foreach ($this->responses as $response) {
            if (str_contains($line, $response['match'])) {
                return [
                    'code' => $response['code'],
                    'stdout' => $response['stdout'],
                    'stderr' => $response['stderr'],
                    'timedOut' => false,
                ];
            }
        }

        return ['code' => 127, 'stdout' => '', 'stderr' => 'tidak ada jawaban palsu untuk: ' . $line, 'timedOut' => false];
    }

    /**
     * True bila pernah ada command yang mengandung $needle.
     */
    public function calledWith(string $needle): bool
    {
        foreach ($this->calls as $call) {
            if (str_contains($call, $needle)) {
                return true;
            }
        }

        return false;
    }
}
