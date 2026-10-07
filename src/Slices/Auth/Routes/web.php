<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Auth\Controllers\AuthWebController;

Route::middleware(['web'])->group(function () {
    Route::get('/login', [AuthWebController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthWebController::class, 'login'])->middleware('throttle:laraslice-login')->name('login.post');
    Route::post('/logout', [AuthWebController::class, 'logout'])->name('logout');

    // MFA Challenge & Enrollment Pipeline (Enterprise Parity)
    Route::get('/login/mfa-challenge', [AuthWebController::class, 'showMfaChallenge'])->name('login.mfa.challenge');
    Route::post('/login/mfa-challenge', [AuthWebController::class, 'verifyMfaChallenge'])->middleware('throttle:laraslice-mfa')->name('login.mfa.challenge.verify');
    Route::post('/login/mfa-challenge/redeem-code', [AuthWebController::class, 'redeemDeviceCodeAjax'])->middleware('throttle:laraslice-mfa')->name('login.mfa.redeem_code');

    Route::get('/login/mfa-enroll', [AuthWebController::class, 'showMfaEnroll'])->name('login.mfa.enroll');
    Route::post('/login/mfa-enroll', [AuthWebController::class, 'confirmMfaEnroll'])->middleware('throttle:laraslice-mfa')->name('login.mfa.enroll.confirm');

    // FIDO2 / WebAuthn Passkeys Authentication & Enrollment
    Route::get('/login/passkey/options', [AuthWebController::class, 'passkeyLoginOptions'])->name('login.passkey.options');
    Route::post('/login/passkey/verify', [AuthWebController::class, 'passkeyLoginVerify'])->middleware('throttle:laraslice-login')->name('login.passkey.verify');
    Route::get('/login/passkey/enroll-options', [AuthWebController::class, 'passkeyEnrollOptions'])->name('login.passkey.enroll.options');
    Route::post('/login/passkey/enroll-verify', [AuthWebController::class, 'passkeyEnrollVerify'])->name('login.passkey.enroll.verify');
});
