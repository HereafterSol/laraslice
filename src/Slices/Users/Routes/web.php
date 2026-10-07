<?php

use Illuminate\Support\Facades\Route;
use LaraSlice\Slices\Users\Controllers\UserWebController;

// 1. User Self-Service Hub (Accessible to ANY authenticated user without /admin prefix)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/account/settings', [UserWebController::class, 'settings'])->name('account.settings');
    Route::get('/security/settings', [UserWebController::class, 'settings'])->name('security.settings');
    Route::get('/profile', [UserWebController::class, 'settings'])->name('profile');

    // Self-Service Account Modifications
    Route::post('/account/settings/profile', [UserWebController::class, 'updateProfile'])->name('account.settings.profile');
    Route::post('/account/settings/password', [UserWebController::class, 'updatePassword'])->name('account.settings.password');
    Route::post('/security/settings/2fa/toggle', [UserWebController::class, 'toggle2Fa'])->name('security.settings.2fa.toggle');
    Route::post('/security/settings/2fa/verify-test', [UserWebController::class, 'verifyTotpCode'])->name('security.settings.2fa.verify_test');
    Route::post('/security/settings/2fa/regenerate-recovery-codes', [UserWebController::class, 'regenerateRecoveryCodes'])->name('security.settings.2fa.regenerate_codes');
    Route::post('/security/settings/issue-device-code', [UserWebController::class, 'issueMyDeviceCode'])->name('security.settings.issue_device_code');
    Route::post('/security/settings/logout-others', [UserWebController::class, 'logoutOthers'])->name('security.settings.logout_others');
    Route::delete('/security/settings/devices/{id}', [UserWebController::class, 'destroyDevice'])->name('security.settings.devices.destroy')->whereNumber('id');

        Route::get('/security/settings/passkey/options', [UserWebController::class, 'passkeyRegisterOptions'])->name('security.settings.passkey.options');
    Route::post('/security/settings/passkey/verify', [UserWebController::class, 'passkeyRegisterVerify'])->name('security.settings.passkey.verify');
    Route::match(['delete', 'post'], '/security/settings/passkey/{id}', [UserWebController::class, 'destroyPasskey'])->name('security.settings.passkey.destroy')->whereNumber('id');

    // Lockscreen & Unlock Flow
    Route::get('/lockscreen', [UserWebController::class, 'lockscreen'])->name('lockscreen');
    Route::match(['get', 'post'], '/lockscreen/unlock', [UserWebController::class, 'unlockScreen'])->name('lockscreen.unlock');
    Route::post('/lockscreen/lock', [UserWebController::class, 'lockSession'])->name('lockscreen.lock');
    Route::get('/lockscreen/passkey/options', [UserWebController::class, 'passkeyUnlockOptions'])->name('lockscreen.passkey.options');
    Route::post('/lockscreen/passkey/verify', [UserWebController::class, 'passkeyUnlockVerify'])->name('lockscreen.passkey.verify');
});

// 2. Admin User Management Slice (Protected administrative operations)
Route::prefix('admin/users')->name('users.')->middleware(['web', 'auth'])->group(function () {
    // Static Root & Creation
    Route::get('/', [UserWebController::class, 'index'])->name('index');
    Route::get('/create', [UserWebController::class, 'create'])->name('create');
    Route::post('/', [UserWebController::class, 'store'])->name('store');
    
    // Telemetry & Security Hub (Enterprise Access Metrics match)
    Route::get('/metrics', [UserWebController::class, 'metrics'])->name('metrics');

    // MFA Management & Recovery Console (Enterprise Security match)
    Route::get('/mfa', [UserWebController::class, 'mfa'])->name('mfa');
    Route::post('/mfa/{id}/issue-device-code', [UserWebController::class, 'issueDeviceCode'])->name('mfa.issue_device_code')->whereNumber('id');
    Route::post('/mfa/{id}/reset-enrollment', [UserWebController::class, 'resetMfaEnrollment'])->name('mfa.reset_enrollment')->whereNumber('id');
    Route::post('/mfa/{id}/reset-totp', [UserWebController::class, 'resetMfaTotp'])->name('mfa.reset_totp')->whereNumber('id');
    Route::post('/mfa/{id}/revoke-devices', [UserWebController::class, 'revokeMfaDevices'])->name('mfa.revoke_devices')->whereNumber('id');
    Route::post('/mfa/{id}/clear-pending', [UserWebController::class, 'clearMfaPending'])->name('mfa.clear_pending')->whereNumber('id');
    Route::post('/mfa/policy', [UserWebController::class, 'updateSecurityPolicy'])->name('mfa.update_policy');

    // Devices & Active Sessions
    Route::get('/devices', [UserWebController::class, 'devices'])->name('devices');
    Route::delete('/devices/{id}', [UserWebController::class, 'destroyDevice'])->name('devices.destroy')->whereNumber('id');
    
    // Security & Audit Logs
    Route::get('/security-logs', [UserWebController::class, 'securityLogs'])->name('security_logs');

    // User Profile & Settings Hub
    Route::get('/settings', [UserWebController::class, 'settings'])->name('settings');
    Route::post('/settings/profile', [UserWebController::class, 'updateProfile'])->name('settings.profile');
    Route::post('/settings/password', [UserWebController::class, 'updatePassword'])->name('settings.password');
    Route::post('/settings/2fa/toggle', [UserWebController::class, 'toggle2Fa'])->name('settings.2fa.toggle');
    Route::post('/settings/2fa/verify-test', [UserWebController::class, 'verifyTotpCode'])->name('settings.2fa.verify_test');
    Route::post('/settings/2fa/regenerate-recovery-codes', [UserWebController::class, 'regenerateRecoveryCodes'])->name('settings.2fa.regenerate_codes');
    Route::post('/settings/issue-device-code', [UserWebController::class, 'issueMyDeviceCode'])->name('settings.issue_device_code');
    Route::post('/settings/logout-others', [UserWebController::class, 'logoutOthers'])->name('settings.logout_others');
    Route::get('/settings/passkey/options', [UserWebController::class, 'passkeyRegisterOptions'])->name('settings.passkey.options');
    Route::post('/settings/passkey/verify', [UserWebController::class, 'passkeyRegisterVerify'])->name('settings.passkey.verify');
    Route::match(['delete', 'post'], '/settings/passkey/{id}', [UserWebController::class, 'destroyPasskey'])->name('settings.passkey.destroy')->whereNumber('id');

    // Session Lockscreen (Redirect admin prefix to clean unified /lockscreen)
    Route::get('/lockscreen', fn() => redirect()->route('lockscreen'))->name('lockscreen');
    Route::match(['get', 'post'], '/lockscreen/unlock', [UserWebController::class, 'unlockScreen'])->name('lockscreen.unlock');
    Route::post('/lockscreen/lock', [UserWebController::class, 'lockSession'])->name('lockscreen.lock');
    Route::get('/lockscreen/passkey/options', [UserWebController::class, 'passkeyUnlockOptions'])->name('lockscreen.passkey.options');
    Route::post('/lockscreen/passkey/verify', [UserWebController::class, 'passkeyUnlockVerify'])->name('lockscreen.passkey.verify');

    // Dynamic ID Wildcards (strictly constrained to numeric IDs)
    Route::post('/{id}/unlock', [UserWebController::class, 'unlock'])->name('unlock')->whereNumber('id');
    Route::get('/{id}/edit', [UserWebController::class, 'edit'])->name('edit')->whereNumber('id');
    Route::put('/{id}', [UserWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::delete('/{id}', [UserWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
});
