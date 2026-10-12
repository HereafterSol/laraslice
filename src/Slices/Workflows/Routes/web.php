<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Workflows\Controllers\WorkflowActionController;
use LaraSlice\Slices\Workflows\Controllers\WorkflowWebController;

Route::middleware(['web', 'auth'])->prefix('admin/workflows')->name('workflows.')->group(function () {
    Route::get('/', [WorkflowWebController::class, 'index'])->name('index');
    Route::post('/', [WorkflowWebController::class, 'store'])->name('store');

    Route::get('/inbox', [WorkflowWebController::class, 'inbox'])->name('inbox');
    Route::get('/logs', [WorkflowWebController::class, 'logs'])->name('logs');
    Route::get('/{id}', [WorkflowWebController::class, 'show'])->name('show')->whereNumber('id');

    // Interactive Flow Management: States CRUD
    Route::post('/{id}/states', [WorkflowWebController::class, 'addState'])->name('add_state')->whereNumber('id');
    Route::put('/{id}/states/{stateId}', [WorkflowWebController::class, 'updateState'])->name('update_state')->whereNumber('id');
    Route::delete('/{id}/states/{stateId}', [WorkflowWebController::class, 'deleteState'])->name('delete_state')->whereNumber('id');

    // Interactive Flow Management: Transitions CRUD
    Route::post('/{id}/transitions', [WorkflowWebController::class, 'addTransition'])->name('add_transition')->whereNumber('id');
    Route::put('/{id}/transitions/{transId}', [WorkflowWebController::class, 'updateTransition'])->name('update_transition')->whereNumber('id');
    Route::delete('/{id}/transitions/{transId}', [WorkflowWebController::class, 'deleteTransition'])->name('delete_transition')->whereNumber('id');

    Route::post('/{id}/sync-manifest', [WorkflowWebController::class, 'syncManifest'])->name('sync_manifest')->whereNumber('id');

    Route::post('/seed-examples', [WorkflowWebController::class, 'seedExamples'])->name('seed_examples');
    Route::post('/transition/{slice}/{id}', [WorkflowActionController::class, 'transition'])->name('transition');
});

Route::redirect('/workflows', '/admin/workflows');