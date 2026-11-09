<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Users\Controllers\UserApiController;

Route::prefix('users')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::post('/list', [UserApiController::class, 'getList']);
    Route::get('/{id}', [UserApiController::class, 'getItemById']);
    Route::post('/save', [UserApiController::class, 'save']);
    Route::delete('/{id}', [UserApiController::class, 'delete']);
});
