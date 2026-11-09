<?php

use Illuminate\Support\Facades\Route;
use App\Slices\ERP\Adjudication\Work\Projects\Controllers\ProjectWebController;

// Plural resource routes: erp_adjudication_work_projects.index, erp_adjudication_work_projects.create, etc.
Route::prefix('erp/adjudication/work/projects')->name('erp_adjudication_work_projects.')->middleware(config('laraslice.generated_routes.web_middleware', ['web', 'auth']))->group(function () {
    Route::get('/', [ProjectWebController::class, 'index'])->name('index');
    Route::get('/create', [ProjectWebController::class, 'create'])->name('create');
    Route::post('/', [ProjectWebController::class, 'store'])->name('store');
    Route::get('/{id}', [ProjectWebController::class, 'show'])->name('show');
    Route::get('/{id}/edit', [ProjectWebController::class, 'edit'])->name('edit');
    Route::put('/{id}', [ProjectWebController::class, 'update'])->name('update');
    Route::delete('/{id}', [ProjectWebController::class, 'destroy'])->name('destroy');
});



// URL slug alias redirects
Route::redirect('projects', '/work/projects');
Route::redirect('projects/{any}', '/work/projects/{any}')->where('any', '.*');
Route::redirect('project', '/work/projects');