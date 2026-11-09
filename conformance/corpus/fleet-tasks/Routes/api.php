<?php

use Illuminate\Support\Facades\Route;
use App\Slices\ERP\Adjudication\Work\Tasks\Controllers\TaskApiController;

Route::prefix('work/tasks')->middleware(config('laraslice.generated_routes.api_middleware', ['api', 'auth:sanctum']))->group(function () {
    Route::post('/list', [TaskApiController::class, 'getList']);
    Route::get('/{id}', [TaskApiController::class, 'getItemById']);
    Route::post('/save', [TaskApiController::class, 'save']);
    Route::delete('/{id}', [TaskApiController::class, 'delete']);
});

// Flat API alias fallback
Route::prefix('tasks')->middleware(config('laraslice.generated_routes.api_middleware', ['api', 'auth:sanctum']))->group(function () {
    Route::post('/list', [TaskApiController::class, 'getList']);
    Route::get('/{id}', [TaskApiController::class, 'getItemById']);
    Route::post('/save', [TaskApiController::class, 'save']);
    Route::delete('/{id}', [TaskApiController::class, 'delete']);
});