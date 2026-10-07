<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Auth\Controllers\AuthApiController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthApiController::class, 'login'])->middleware('throttle:laraslice-login')->name('api.auth.login');
    Route::post('/register', [AuthApiController::class, 'register'])->middleware('throttle:laraslice-register')->name('api.auth.register');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/me', [AuthApiController::class, 'me'])->name('api.auth.me');
        Route::post('/logout', [AuthApiController::class, 'logout'])->name('api.auth.logout');
    });
});
