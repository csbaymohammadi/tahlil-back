<?php

use App\Http\Controllers\MediaDeliveryController;
use Illuminate\Support\Facades\Route;

Route::get('/media/{id}/{name}', [MediaDeliveryController::class, 'show'])->name('media.show');
