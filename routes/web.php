<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\DownloadController;

Route::post('api/v1/oppwa/webhook', [PaymentController::class, 'handle']);

Route::get('/health', function () {
    return response('If you see this we are alive.', 200);
});

Route::get('/nova/download/temp-file/{filename}', [DownloadController::class, 'downloadTempFile'])->name('nova.download.temp');

Route::any('{any}', function () {
    return view('app');
})->where('any', '.*');
