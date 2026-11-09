<?php

use Illuminate\Support\Facades\Route;
use App\Slices\ERP\Adjudication\Work\Projects\Controllers\ProjectApiController;

Route::prefix('work/projects')->middleware(config('laraslice.generated_routes.api_middleware', ['api', 'auth:sanctum']))->group(function () {
    Route::post('/list', [ProjectApiController::class, 'getList']);
    Route::get('/{id}', [ProjectApiController::class, 'getItemById']);
    Route::post('/save', [ProjectApiController::class, 'save']);
    Route::delete('/{id}', [ProjectApiController::class, 'delete']);
});

// Flat API alias fallback
Route::prefix('projects')->middleware(config('laraslice.generated_routes.api_middleware', ['api', 'auth:sanctum']))->group(function () {
    Route::post('/list', [ProjectApiController::class, 'getList']);
    Route::get('/{id}', [ProjectApiController::class, 'getItemById']);
    Route::post('/save', [ProjectApiController::class, 'save']);
    Route::delete('/{id}', [ProjectApiController::class, 'delete']);
});