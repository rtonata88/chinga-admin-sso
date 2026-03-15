<?php

use App\Http\Controllers\Auth\SmsMfaController;
use App\Http\Controllers\Settings\KycController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ResponsibleGamblingController;
use App\Http\Controllers\Settings\SecurityLogController;
use App\Http\Controllers\Settings\SessionController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    // SMS MFA
    Route::prefix('settings/security/sms-mfa')->group(function () {
        Route::get('/', [SmsMfaController::class, 'show'])->name('sms-mfa.show');
        Route::post('/enable', [SmsMfaController::class, 'enable'])->name('sms-mfa.enable');
        Route::post('/verify', [SmsMfaController::class, 'verify'])->name('sms-mfa.verify');
        Route::delete('/', [SmsMfaController::class, 'disable'])->name('sms-mfa.disable');
        Route::post('/preferred', [SmsMfaController::class, 'setPreferred'])->name('sms-mfa.preferred');
    });

    // Sessions
    Route::get('settings/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('settings/sessions/{id}', [SessionController::class, 'destroy'])->name('sessions.destroy');
    Route::delete('settings/sessions', [SessionController::class, 'destroyAll'])->name('sessions.destroy-all');

    // Security Audit Log
    Route::get('settings/security/log', [SecurityLogController::class, 'index'])->name('security-log.index');

    // KYC Verification
    Route::get('settings/kyc', [KycController::class, 'index'])->name('kyc.index');

    // Responsible Gambling
    Route::get('settings/responsible-gambling', [ResponsibleGamblingController::class, 'index'])
        ->name('responsible-gambling.index');
});
