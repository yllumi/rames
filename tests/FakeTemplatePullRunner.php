<?php
declare(strict_types=1);

namespace Tests;

use app\library\Support\ProcessRunner;

/**
 * ProcessRunner palsu untuk test worker `cli/pull.php` — TIDAK menyentuh
 * daemon/registry Docker (diaktifkan lewat env `TEMPLATE_PULL_RUNNER_CLASS`,
 * pola sama dengan `DEPLOYER_CLASS` di `DeployerFactory`).
 *
 * Perilaku diatur env:
 *  - `FAKE_TEMPLATE_PULL_CALLS`  : berkas JSON tempat setiap argv + timeout dicatat.
 *  - `FAKE_TEMPLATE_PULL_FAIL`   : substring ref image yang gagal (`*` = semua gagal).
 *  - `FAKE_TEMPLATE_PULL_TIMEOUT`: substring ref image yang "timeout".
 */
class FakeTemplatePullRunner extends ProcessRunner
{
    /**
     * @var array<int,array{command:array<int,string>,cwd:?string,timeout:int}>
     */
    private array $calls = [];

    /**
     * @param array<int,string>    $command
     * @param array<string,string> $env
     * @return array{code:int,stdout:string,stderr:string,timedOut:bool}
     */
    public function run(array $command, ?string $cwd = null, int $timeout = 300, array $env = [], ?string $stdin = null): array
    {
        $this->calls[] = ['command' => array_values($command), 'cwd' => $cwd, 'timeout' => $timeout];
        $this->record();

        $ref = (string) ($command[2] ?? '');
        $fail = (string) (getenv('FAKE_TEMPLATE_PULL_FAIL') ?: '');
        $timeoutRef = (string) (getenv('FAKE_TEMPLATE_PULL_TIMEOUT') ?: '');

        if ($timeoutRef !== '' && str_contains($ref, $timeoutRef)) {
            return ['code' => 124, 'stdout' => '', 'stderr' => 'fake pull timeout', 'timedOut' => true];
        }
        if ($fail === '*' || ($fail !== '' && str_contains($ref, $fail))) {
            return ['code' => 1, 'stdout' => '', 'stderr' => 'fake pull gagal: ' . $ref, 'timedOut' => false];
        }

        return ['code' => 0, 'stdout' => 'Pull complete: ' . $ref, 'stderr' => '', 'timedOut' => false];
    }

    /**
     * Tulis catatan pemanggilan supaya subproses bisa diverifikasi oleh test
     * (kelas ini hidup di proses worker yang terpisah).
     */
    private function record(): void
    {
        $path = (string) (getenv('FAKE_TEMPLATE_PULL_CALLS') ?: '');
        if ($path === '') {
            return;
        }
        @file_put_contents($path, (string) json_encode($this->calls, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
