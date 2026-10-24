<?php

use App\Http\Controllers\Admin\AuthController;
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
