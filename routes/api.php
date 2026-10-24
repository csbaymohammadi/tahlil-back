<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\PanelRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/admin/auth')->name('panel.auth.')->middleware(PanelRequest::class)->group(function (): void {
    Route::get('csrf', [AuthController::class, 'csrf'])->name('csrf');
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    Route::post('password', [AuthController::class, 'password'])->name('password');
});

Route::prefix('v1/admin')->name('panel.')->middleware(PanelRequest::class)->group(function (): void {
    Route::apiResource('users', UserController::class)->parameters(['users' => 'id'])->except('update');
    Route::patch('users/{id}', [UserController::class, 'update'])->name('users.update');
    Route::apiResource('roles', RoleController::class)->parameters(['roles' => 'id'])->except('update');
    Route::patch('roles/{id}', [RoleController::class, 'update'])->name('roles.update');
});

Route::prefix('v1/admin/media')->name('panel.media.')->middleware(PanelRequest::class)->group(function (): void {
    Route::post('/', [MediaController::class, 'store'])->name('store');
    Route::get('{id}', [MediaController::class, 'show'])->name('show');
    Route::get('{id}/preview', [MediaController::class, 'preview'])->name('preview');
    Route::delete('{id}', [MediaController::class, 'destroy'])->name('destroy');
});
