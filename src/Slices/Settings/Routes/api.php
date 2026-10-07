<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Settings\Controllers\SettingApiController;

Route::prefix('settings')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('/smtp', [SettingApiController::class, 'getSmtp'])->name('api.settings.smtp');
    Route::post('/smtp', [SettingApiController::class, 'updateSmtp'])->name('api.settings.smtp.update');
    Route::post('/smtp/test', [SettingApiController::class, 'testSmtp'])->name('api.settings.smtp.test');
});
