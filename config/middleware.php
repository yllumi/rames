<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

// Mode runtime (multi-server F0) — gerbang daftar middleware GLOBAL.
// Nilai dinormalkan HANYA di config/deploy.php; berkas ini dievaluasi oleh
// Config::load *bersamaan* dengan berkas konfigurasi lain sehingga `config()`
// belum terisi — jadi array deploy dibaca langsung dari sumbernya (sama seperti
// config/process.php) agar tidak ada parsing env kedua yang bisa menyimpang.
$ramesMode = (string) ((require __DIR__ . '/deploy.php')['mode'] ?? 'dashboard');

// `dashboard` (default): CSRF → Auth(session) → StaticFile, persis seperti
// sebelumnya — tidak ada perubahan perilaku.
//
// `agent`: server agent tidak punya UI/sesi/aset statis dashboard dan tidak
// boleh melewati pengecekan sesi; seluruh route agent (termasuk `/healthz`,
// plan §4.1: tidak ada route publik di agent) dijaga `AgentAuthMiddleware`
// (bearer token + timestamp + nonce + signature opsional). Middleware itu
// sendiri no-op bila mode bukan agent, jadi tidak ada risiko silang mode.
$ramesGlobalMiddleware = $ramesMode === 'agent'
    ? [
        app\middleware\AgentAuthMiddleware::class,
    ]
    : [
        app\middleware\CsrfMiddleware::class,
        app\middleware\AuthMiddleware::class,
        app\middleware\StaticFile::class,
    ];

return [
    // Middleware global (default app).
    '' => $ramesGlobalMiddleware,
];