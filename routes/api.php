<?php
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Giveaways\GiveawaysController;
use App\Http\Controllers\Orders\OrdersController;
use App\Http\Controllers\Payment\PaymentController;
use App\Models\SiteSettings;
use Illuminate\Support\Facades\Route;

Route::prefix('giveaways')->group(function () {
    Route::get('drawing-soon', [GiveawaysController::class, 'getDrawingSoon']);
    Route::get('just-launched', [GiveawaysController::class, 'getJustLaunched']);
    Route::get('winners', [GiveawaysController::class, 'getWinners']);
    Route::get('{id}', [GiveawaysController::class, 'index']);
});

Route::middleware('auth:api')->group(function () {
    Route::prefix('orders')->group(function () {
        Route::post('/', [OrdersController::class, 'store']);
        Route::get('/', [OrdersController::class, 'index']);
    });

    Route::prefix('payment')->group(function () {
        Route::get('/', [PaymentController::class, 'generateCheckout']);
    });
});

Route::post('/paymentWebhook', [PaymentController::class, 'handle']);
Route::get('/settings', function () {
    return response()->json(SiteSettings::first());
});

require __DIR__.'/auth.php';
