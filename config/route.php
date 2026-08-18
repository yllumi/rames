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

use Webman\Route;

use app\controller\AuthController;
use app\controller\IndexController;
use app\controller\NetworkController;
use app\controller\NginxController;
use app\controller\SiteController;
use app\controller\SslController;
use app\controller\TerminalController;
use app\controller\UserController;
use app\controller\VolumeController;

/*
|--------------------------------------------------------------------------
| Route publik (tanpa autentikasi)
|--------------------------------------------------------------------------
| / (hello world) dan /login publik; /logout tetap diproses (tidak butuh user login).
|--------------------------------------------------------------------------
*/
Route::get('/login', [AuthController::class, 'loginForm']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/logout', [AuthController::class, 'logout']);

/*
|--------------------------------------------------------------------------
| Route dilindungi (AuthMiddleware global di config/middleware.php)
|--------------------------------------------------------------------------
*/
Route::get('/', [IndexController::class, 'index']);
Route::get('/sites', [SiteController::class, 'index']);

// Wizard create site
Route::get('/sites/create', [SiteController::class, 'createForm']);
Route::post('/sites/create', [SiteController::class, 'createPreview']);
Route::get('/sites/create/confirm', [SiteController::class, 'confirmForm']);
Route::post('/sites/create/confirm', [SiteController::class, 'confirmCreate']);

// Detail & polling
Route::get('/sites/{id}', [SiteController::class, 'detail']);
Route::get('/sites/{id}/versions', [SiteController::class, 'versions']);
Route::get('/api/sites/{id}/status', [SiteController::class, 'status']);

// Aksi site
Route::post('/sites/{id}/rebuild', [SiteController::class, 'rebuild']);
Route::post('/sites/{id}/rollback', [SiteController::class, 'rollback']);
Route::post('/sites/{id}/stop', [SiteController::class, 'stop']);
Route::post('/sites/{id}/start', [SiteController::class, 'start']);
Route::post('/sites/{id}/delete', [SiteController::class, 'delete']);

// Custom domain
Route::post('/sites/{id}/domain/set', [SiteController::class, 'setDomain']);
Route::post('/sites/{id}/domain/remove', [SiteController::class, 'removeDomain']);

// Environment variables per site
Route::post('/sites/{id}/env', [SiteController::class, 'saveEnv']);
Route::post('/sites/{id}/env/import', [SiteController::class, 'importEnv']);

// External network per site (shared network lintas-site)
Route::post('/sites/{id}/network', [SiteController::class, 'saveNetworks']);

// Halaman & reload Nginx host (global — berlaku untuk semua site)
Route::get('/nginx', [NginxController::class, 'index']);
Route::post('/nginx/reload', [NginxController::class, 'reload']);

// SSL otomatis (Let's Encrypt)
Route::get('/ssl', [SslController::class, 'index']);
Route::post('/ssl/{id}/enable', [SslController::class, 'enable']);

// Volume Docker (lihat & bersihkan volume yatim)
Route::get('/volumes', [VolumeController::class, 'index']);
Route::post('/volumes/purge', [VolumeController::class, 'purge']);

// Network Docker (lihat, buat, hubungkan/putuskan container, hapus)
Route::get('/networks', [NetworkController::class, 'index']);
Route::post('/networks/create', [NetworkController::class, 'create']);
Route::get('/networks/{id}', [NetworkController::class, 'detail']);
Route::post('/networks/{id}/connect', [NetworkController::class, 'connect']);
Route::post('/networks/{id}/disconnect', [NetworkController::class, 'disconnect']);
Route::post('/networks/{id}/delete', [NetworkController::class, 'delete']);

/*
|--------------------------------------------------------------------------
| Terminal container (docker exec interaktif & one-shot run command)
|--------------------------------------------------------------------------
| Stream output = SSE (GET), input/close/run = POST (kebagian CSRF). Semua
| dilindungi AuthMiddleware (401 JSON untuk /api/* bila belum login).
|--------------------------------------------------------------------------
*/
Route::post('/api/sites/{id}/terminal/open', [TerminalController::class, 'open']);
Route::get('/api/sites/{id}/terminal/{token}/stream', [TerminalController::class, 'stream']);
Route::post('/api/sites/{id}/terminal/{token}/input', [TerminalController::class, 'input']);
Route::post('/api/sites/{id}/terminal/{token}/close', [TerminalController::class, 'close']);
Route::post('/api/sites/{id}/terminal/run', [TerminalController::class, 'run']);

/*
|--------------------------------------------------------------------------
| Manage Users
|--------------------------------------------------------------------------
*/
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'create']);
Route::post('/users/{id}/delete', [UserController::class, 'delete']);
Route::post('/users/{id}/password', [UserController::class, 'changePassword']);






