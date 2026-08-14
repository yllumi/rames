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
use app\controller\SiteController;
use app\controller\SslController;
use app\controller\UserController;

/*
|--------------------------------------------------------------------------
| Route publik (tanpa autentikasi)
|--------------------------------------------------------------------------
| /login saja yang publik; /logout tetap diproses (tidak butuh user login).
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
Route::get('/', [SiteController::class, 'index']);
Route::get('/sites', [SiteController::class, 'index']);

// Wizard create site
Route::get('/sites/create', [SiteController::class, 'createForm']);
Route::post('/sites/create', [SiteController::class, 'createPreview']);
Route::get('/sites/create/confirm', [SiteController::class, 'confirmForm']);
Route::post('/sites/create/confirm', [SiteController::class, 'confirmCreate']);

// Detail & polling
Route::get('/sites/{id}', [SiteController::class, 'detail']);
Route::get('/api/sites/{id}/status', [SiteController::class, 'status']);

// Aksi site
Route::post('/sites/{id}/rebuild', [SiteController::class, 'rebuild']);
Route::post('/sites/{id}/stop', [SiteController::class, 'stop']);
Route::post('/sites/{id}/start', [SiteController::class, 'start']);
Route::post('/sites/{id}/delete', [SiteController::class, 'delete']);

// SSL otomatis (Let's Encrypt)
Route::get('/ssl', [SslController::class, 'index']);
Route::post('/ssl/{id}/enable', [SslController::class, 'enable']);

/*
|--------------------------------------------------------------------------
| Manage Users
|--------------------------------------------------------------------------
*/
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'create']);
Route::post('/users/{id}/delete', [UserController::class, 'delete']);
Route::post('/users/{id}/password', [UserController::class, 'changePassword']);






