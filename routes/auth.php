<?php

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetOtpController;
use App\Http\Controllers\Auth\OtpVerificationController;

Route::prefix('auth')->group(function () {
Route::middleware('guest:api')->group(function () {
    Route::post('register', [RegisteredUserController::class, 'store']);
    
    // Password reset with OTP routes
    Route::post('forgot-password/send-otp', [PasswordResetOtpController::class, 'sendOtp']);
    Route::post('forgot-password/verify-otp', [PasswordResetOtpController::class, 'verifyOtp']);
    Route::post('forgot-password/reset', [PasswordResetOtpController::class, 'resetPassword']);
});

Route::post('login', [AuthenticatedSessionController::class, 'store']);    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
        Route::post('me', [AuthenticatedSessionController::class, 'me']);
        Route::post('update', [PasswordController::class, 'update'])->middleware('verified');

        // OTP verification routes (accessible to authenticated but unverified users)
        Route::post('verify-otp', [OtpVerificationController::class, 'verify']);
        Route::post('ensure-otp', [OtpVerificationController::class, 'ensureOtp']);
        Route::post('resend-otp', [OtpVerificationController::class, 'resend']);

        Route::get('email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        Route::post('email/resend', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });
});
