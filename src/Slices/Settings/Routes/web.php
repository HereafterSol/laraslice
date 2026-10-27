<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Settings\Controllers\SettingWebController;
use LaraSlice\Slices\Settings\Controllers\ThemeWebController;

Route::middleware(['web', 'auth'])->prefix('admin/settings')->name('settings.')->group(function () {
    Route::get('/', [SettingWebController::class, 'hub'])->name('hub');

    // Built-in POST routes register BEFORE the hub-section
    // catch-all: POST /admin/settings/smtp would otherwise
    // match {section}='smtp' first and 404 in saveHubSection.
    Route::post('/smtp', [SettingWebController::class, 'saveSmtp'])->name('smtp.save');
    Route::post('/smtp/test', [SettingWebController::class, 'testSmtp'])->name('smtp.test');
    Route::get('/ai', [SettingWebController::class, 'ai'])->name('ai');
    Route::post('/ai', [SettingWebController::class, 'saveAi'])->name('ai.save');
    Route::post('/{section}', [SettingWebController::class, 'saveHubSection'])->where('section', '[^/]+')->name('hub.save');

    Route::get('/smtp', [SettingWebController::class, 'smtp'])->name('smtp');

    Route::get('/theme', [ThemeWebController::class, 'index'])->name('theme');

});

Route::redirect('/settings/theme', '/admin/settings/theme');
Route::redirect('/settings', '/admin/settings');
