<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Roles\Controllers\RoleApiController;

Route::prefix('roles')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::post('/list', [RoleApiController::class, 'getList']);
    Route::get('/{id}', [RoleApiController::class, 'getItemById']);
    Route::post('/save', [RoleApiController::class, 'save']);
    Route::delete('/{id}', [RoleApiController::class, 'delete']);
});
