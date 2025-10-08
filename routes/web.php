<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Payment\PaymentController;

Route::post('api/v1/oppwa/webhook', [PaymentController::class, 'handle']);

Route::any('{any}', function () {

})->where('any', '.*');

Route::get('/health', function () {
    return response('If you see this we are alive.', 200);
});
