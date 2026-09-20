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
use app\controller\LogController;
use app\controller\NetworkController;
use app\controller\NginxController;
use app\controller\AppController;
use app\controller\DatabaseController;
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
// Mode compose: paste/upload docker-compose.yml tanpa repo Git
Route::post('/apps/create/compose', [AppController::class, 'composePreview']);
Route::get('/apps/create/confirm', [AppController::class, 'confirmForm']);
Route::post('/apps/create/confirm', [AppController::class, 'confirmCreate']);

// Detail & polling
Route::get('/apps/{id}', [AppController::class, 'detail']);
Route::get('/apps/{id}/versions', [AppController::class, 'versions']);
Route::get('/api/apps/{id}/status', [AppController::class, 'status']);
// Log container app (popup modal di detail app)
Route::get('/api/apps/{id}/logs', [LogController::class, 'index']);

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

// Compose (app mode compose: edit docker-compose.yml + file pendukung)
Route::post('/apps/{id}/compose', [AppController::class, 'saveCompose']);

// External network per app (shared network lintas-app)
Route::post('/apps/{id}/network', [AppController::class, 'saveNetworks']);

// Nama container per app (override container_name via compose override)
Route::post('/apps/{id}/container-names', [AppController::class, 'saveContainerNames']);

// Kepemilikan & sharing app (owner + members)
Route::post('/apps/{id}/members', [AppController::class, 'addMember']);
Route::post('/apps/{id}/members/{userId}/remove', [AppController::class, 'removeMember']);
Route::post('/apps/{id}/owner', [AppController::class, 'transferOwner']);

// Halaman & reload Nginx host (global — berlaku untuk semua app)
Route::get('/nginx', [NginxController::class, 'index']);
Route::post('/nginx/reload', [NginxController::class, 'reload']);

// SSL otomatis (Let's Encrypt)
Route::get('/ssl', [SslController::class, 'index']);
Route::post('/ssl/{id}/enable', [SslController::class, 'enable']);

// Volume Docker (lihat & bersihkan volume yatim)
Route::get('/volumes', [VolumeController::class, 'index']);
Route::post('/volumes/purge', [VolumeController::class, 'purge']);
// Ukuran terpakai tiap volume (AJAX — GET /system/df bisa lambat)
Route::get('/api/volumes/usage', [VolumeController::class, 'usage']);

// Network Docker (lihat, buat, hubungkan/putuskan container, hapus)
Route::get('/networks', [NetworkController::class, 'index']);
Route::post('/networks/create', [NetworkController::class, 'create']);
Route::get('/networks/{id}', [NetworkController::class, 'detail']);
Route::post('/networks/{id}/connect', [NetworkController::class, 'connect']);
Route::post('/networks/{id}/disconnect', [NetworkController::class, 'disconnect']);
Route::post('/networks/{id}/delete', [NetworkController::class, 'delete']);

/*
|--------------------------------------------------------------------------
| Database manager (MySQL/MariaDB di container) — phpMyAdmin mini
|--------------------------------------------------------------------------
| Halaman global /database + halaman kelola per container. Profile koneksi
| (host/port/kredensial) hidup di session; semua POST kena CSRF & AuthMiddleware.
|--------------------------------------------------------------------------
*/
Route::get('/database', [DatabaseController::class, 'index']);
Route::get('/database/{container}', [DatabaseController::class, 'manage']);
Route::post('/database/{container}/connect', [DatabaseController::class, 'connect']);
Route::post('/database/{container}/disconnect', [DatabaseController::class, 'disconnect']);
Route::post('/database/{container}/query', [DatabaseController::class, 'query']);
Route::post('/database/{container}/row/insert', [DatabaseController::class, 'rowInsert']);
Route::post('/database/{container}/row/update', [DatabaseController::class, 'rowUpdate']);
Route::post('/database/{container}/row/delete', [DatabaseController::class, 'rowDelete']);
Route::post('/database/{container}/user/create', [DatabaseController::class, 'userCreate']);
Route::post('/database/{container}/user/delete', [DatabaseController::class, 'userDelete']);
Route::post('/database/{container}/user/grant', [DatabaseController::class, 'userGrant']);
Route::post('/database/{container}/user/revoke', [DatabaseController::class, 'userRevoke']);
Route::post('/database/{container}/export', [DatabaseController::class, 'export']);
Route::post('/database/{container}/import', [DatabaseController::class, 'import']);

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
Route::post('/users/{id}/role', [UserController::class, 'changeRole']);






