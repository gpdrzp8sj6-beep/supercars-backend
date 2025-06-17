<?php

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\LogoutController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::middleware('guest:api')->group(function () {
        Route::post('register', [RegisteredUserController::class, 'store']);
        Route::post('login', [AuthenticatedSessionController::class, 'store']);

        Route::post('forgot-password', [PasswordResetLinkController::class, 'store']);
        Route::post('reset-password', [NewPasswordController::class, 'reset']);
    });

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
        Route::post('me', [AuthenticatedSessionController::class, 'me']);
        Route::post('update', [PasswordController::class, 'update']);

        Route::get('email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        Route::post('email/resend', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });
});
