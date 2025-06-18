<?php
use Illuminate\Support\Facades\Route;

Route::any('{any}', function () {

})->where('any', '.*');

Route::get('/health', function () {
    return response('If you see this we are alive.', 200);
});
