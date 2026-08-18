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
use app\controller\AppController;
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
Route::get('/apps', [AppController::class, 'index']);

// Wizard create app
Route::get('/apps/create', [AppController::class, 'createForm']);
Route::post('/apps/create', [AppController::class, 'createPreview']);
Route::get('/apps/create/confirm', [AppController::class, 'confirmForm']);
Route::post('/apps/create/confirm', [AppController::class, 'confirmCreate']);

// Detail & polling
Route::get('/apps/{id}', [AppController::class, 'detail']);
Route::get('/apps/{id}/versions', [AppController::class, 'versions']);
Route::get('/api/apps/{id}/status', [AppController::class, 'status']);

// Aksi app
Route::post('/apps/{id}/rebuild', [AppController::class, 'rebuild']);
Route::post('/apps/{id}/rollback', [AppController::class, 'rollback']);
Route::post('/apps/{id}/stop', [AppController::class, 'stop']);
Route::post('/apps/{id}/start', [AppController::class, 'start']);
Route::post('/apps/{id}/delete', [AppController::class, 'delete']);

// Custom domain
Route::post('/apps/{id}/domain/set', [AppController::class, 'setDomain']);
Route::post('/apps/{id}/domain/remove', [AppController::class, 'removeDomain']);

// Environment variables per app
Route::post('/apps/{id}/env', [AppController::class, 'saveEnv']);
Route::post('/apps/{id}/env/import', [AppController::class, 'importEnv']);

// External network per app (shared network lintas-app)
Route::post('/apps/{id}/network', [AppController::class, 'saveNetworks']);

// Halaman & reload Nginx host (global — berlaku untuk semua app)
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
Route::post('/api/apps/{id}/terminal/open', [TerminalController::class, 'open']);
Route::get('/api/apps/{id}/terminal/{token}/stream', [TerminalController::class, 'stream']);
Route::post('/api/apps/{id}/terminal/{token}/input', [TerminalController::class, 'input']);
Route::post('/api/apps/{id}/terminal/{token}/close', [TerminalController::class, 'close']);
Route::post('/api/apps/{id}/terminal/run', [TerminalController::class, 'run']);

/*
|--------------------------------------------------------------------------
| Manage Users
|--------------------------------------------------------------------------
*/
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'create']);
Route::post('/users/{id}/delete', [UserController::class, 'delete']);
Route::post('/users/{id}/password', [UserController::class, 'changePassword']);






