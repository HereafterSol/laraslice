<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Roles\Controllers\RoleWebController;

Route::prefix('admin/roles')->name('roles.')->middleware(['web', 'auth'])->group(function () {
    Route::get('/', [RoleWebController::class, 'index'])->name('index');
    Route::get('/create', [RoleWebController::class, 'create'])->name('create');
    Route::post('/', [RoleWebController::class, 'store'])->name('store');
    Route::get('/{id}/edit', [RoleWebController::class, 'edit'])->name('edit');
    Route::put('/{id}', [RoleWebController::class, 'update'])->name('update');
    Route::delete('/{id}', [RoleWebController::class, 'destroy'])->name('destroy');
});
