<?php
declare(strict_types=1);

namespace Tests;

use app\library\Update\UpdateRunner;

/**
 * Runner self-update palsu untuk uji worker op tingkat server `self_update`
 * (diaktifkan lewat env `RAMES_SELF_UPDATE_CLASS`, pola yang sama dengan
 * `DEPLOYER_CLASS` / `TEMPLATE_PULL_RUNNER_CLASS`).
 *
 * **Tidak pernah** menjalankan helper container/`docker` nyata: uji hanya
 * memverifikasi bahwa update dijalankan oleh *runner di server tempat worker
 * berjalan* (agen), bukan oleh control plane.
 *
 * Env:
 *  - `FAKE_SELF_UPDATE_LOG`   : berkas JSON tempat setiap panggilan dicatat;
 *  - `FAKE_SELF_UPDATE_FAIL`  : `1` ⇒ preflight gagal (pesan jelas).
 */
final class FakeSelfUpdateRunner implements UpdateRunner
{
    /** @var array<int,array<int,string>> */
    private array $calls = [];

    public function preflight(): array
    {
        $this->calls[] = ['preflight'];
        $this->record();

        if ((string) (getenv('FAKE_SELF_UPDATE_FAIL') ?: '') === '1') {
            return ['ok' => false, 'errors' => ['repo uji kotor (fake)'], 'warnings' => []];
        }

        return ['ok' => true, 'errors' => [], 'warnings' => []];
    }

    public function start(string $mode, string $actor): array
    {
        $this->calls[] = ['start', $mode, $actor];
        $this->record();

        return [
            'id' => 'run-uji',
            'container' => 'rames-self-update-uji',
            'mode' => $mode,
            'old_sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'target_sha' => '',
            'log' => 'run-uji.log',
        ];
    }

    private function record(): void
    {
        $path = (string) (getenv('FAKE_SELF_UPDATE_LOG') ?: '');
        if ($path === '') {
            return;
        }

        @file_put_contents($path, (string) json_encode($this->calls, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
