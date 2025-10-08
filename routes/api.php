<?php
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Giveaways\GiveawaysController;
use App\Http\Controllers\Orders\OrdersController;
use App\Http\Controllers\Settings\AddressesController;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\CreditController;
use App\Models\SiteSettings;
use Illuminate\Support\Facades\Route;
Route::post('/oppwa/webhook', [PaymentController::class, 'handle']);
Route::prefix('giveaways')->group(function () {
    Route::get('drawing-soon', [GiveawaysController::class, 'getDrawingSoon']);
    Route::get('just-launched', [GiveawaysController::class, 'getJustLaunched']);
    Route::get('winners', [GiveawaysController::class, 'getWinners']);
    Route::get('{id}', [GiveawaysController::class, 'index']);
    Route::post('{id}/check-tickets', [GiveawaysController::class, 'checkTicketAvailability']);
});

Route::middleware('auth:api')->group(function () {
    Route::prefix('orders')->group(function () {
        Route::post('/', [OrdersController::class, 'store']);
        Route::get('/', [OrdersController::class, 'index']);
    });

    Route::prefix('payment')->group(function () {
        Route::get('/', [PaymentController::class, 'generateCheckout']);
    });

    // Addresses management
    Route::prefix('addresses')->group(function () {
        Route::get('/', [AddressesController::class, 'index']);
        Route::post('/', [AddressesController::class, 'store']);
        Route::put('{address}', [AddressesController::class, 'update']);
        Route::patch('{address}', [AddressesController::class, 'update']);
        Route::delete('{address}', [AddressesController::class, 'destroy']);
        Route::post('{address}/default', [AddressesController::class, 'setDefault']);
    });

    // Credit management (admin)
    Route::prefix('credit')->group(function () {
        Route::post('manage', [CreditController::class, 'manageCredit']);
    });
});

Route::get('/settings', function () {
    return response()->json(SiteSettings::first());
});

require __DIR__.'/auth.php';
