<?php
declare(strict_types=1);

namespace app\controller;

use app\library\Update\RepoInfo;
use support\Request;

/**
 * `GET /healthz` — kesehatan dashboard (SPECS.md §7.8).
 *
 * Publik (tanpa session) karena dipakai dua hal:
 *  1. helper self-update memverifikasi versi baru benar-benar melayani request
 *     sebelum memutuskan sukses atau rollback otomatis;
 *  2. monitoring eksternal (mis. Uptime Kuma) untuk dashboard itu sendiri.
 *
 * Sengaja sangat murah: SHA/branch dibaca langsung dari `.git` (tanpa proses git,
 * tanpa Engine API). Yang dilaporkan hanya status + identitas versi — tanpa data
 * instalasi apa pun.
 */
class HealthController
{
    public function index(Request $request)
    {
        $repo = new RepoInfo((string) config('deploy.update_path', base_path()));

        return json([
            'ok' => true,
            'service' => 'rames',
            'sha' => $repo->sha(),
            'branch' => $repo->branch(),
            'time' => date('c'),
        ]);
    }
}
