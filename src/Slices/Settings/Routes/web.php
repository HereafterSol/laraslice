<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Settings\Controllers\SettingWebController;
use LaraSlice\Slices\Settings\Controllers\ThemeWebController;

Route::middleware(['web'])->prefix('admin/settings')->name('settings.')->group(function () {
    Route::get('/smtp', [SettingWebController::class, 'smtp'])->name('smtp');
    Route::post('/smtp', [SettingWebController::class, 'saveSmtp'])->name('smtp.save');
    Route::post('/smtp/test', [SettingWebController::class, 'testSmtp'])->name('smtp.test');

    Route::get('/theme', [ThemeWebController::class, 'index'])->name('theme');

    Route::get('/ai', [SettingWebController::class, 'ai'])->name('ai');
    Route::post('/ai', [SettingWebController::class, 'saveAi'])->name('ai.save');
});

Route::redirect('/settings/theme', '/admin/settings/theme');
Route::redirect('/settings', '/admin/settings/smtp');
Route::redirect('/admin/settings', '/admin/settings/smtp');

