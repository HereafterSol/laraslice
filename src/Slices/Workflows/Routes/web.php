<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Workflows\Controllers\WorkflowActionController;
use LaraSlice\Slices\Workflows\Controllers\WorkflowWebController;

Route::middleware(['web', 'auth'])->prefix('admin/workflows')->name('workflows.')->group(function () {
    Route::get('/', [WorkflowWebController::class, 'index'])->name('index');
    Route::get('/inbox', [WorkflowWebController::class, 'inbox'])->name('inbox');
    Route::get('/logs', [WorkflowWebController::class, 'logs'])->name('logs');
    Route::get('/{id}', [WorkflowWebController::class, 'show'])->name('show')->whereNumber('id');

    Route::post('/seed-examples', [WorkflowWebController::class, 'seedExamples'])->name('seed_examples');
    Route::post('/transition/{slice}/{id}', [WorkflowActionController::class, 'transition'])->name('transition');
});

Route::redirect('/workflows', '/admin/workflows');