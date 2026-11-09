<?php

use Illuminate\Support\Facades\Route;
use App\Slices\ERP\Adjudication\Work\Tasks\Controllers\TaskWebController;

// Plural resource routes: erp_adjudication_work_tasks.index, erp_adjudication_work_tasks.create, etc.
Route::prefix('erp/adjudication/work/tasks')->name('erp_adjudication_work_tasks.')->middleware(config('laraslice.generated_routes.web_middleware', ['web', 'auth']))->group(function () {
    Route::get('/', [TaskWebController::class, 'index'])->name('index');
    Route::get('/create', [TaskWebController::class, 'create'])->name('create');
    Route::post('/', [TaskWebController::class, 'store'])->name('store');
    Route::get('/{id}', [TaskWebController::class, 'show'])->name('show');
    Route::get('/{id}/edit', [TaskWebController::class, 'edit'])->name('edit');
    Route::put('/{id}', [TaskWebController::class, 'update'])->name('update');
    Route::delete('/{id}', [TaskWebController::class, 'destroy'])->name('destroy');
});



// URL slug alias redirects
Route::redirect('tasks', '/work/tasks');
Route::redirect('tasks/{any}', '/work/tasks/{any}')->where('any', '.*');
Route::redirect('task', '/work/tasks');