<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\OrderApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.key', 'throttle:120,1'])->group(function () {
    Route::get('accounts', [AccountApiController::class, 'index']);
    Route::post('orders', [OrderApiController::class, 'store']);
    Route::get('orders/{orderNo}', [OrderApiController::class, 'show']);
});
