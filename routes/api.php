<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\EmailIngestController;
use App\Http\Controllers\Api\OrderApiController;
use Illuminate\Support\Facades\Route;

// Signed (HMAC) e-mail forwarding from a merchant's mailbox; not a merchant API-key route.
Route::post('email-ingest/{account}', EmailIngestController::class)
    ->whereNumber('account')
    ->middleware('throttle:60,1')
    ->name('api.email-ingest');

// Per-key (120/min) and per-IP (600/min) throttling is handled inside
// the api.key middleware (AuthenticateApiKey), so no separate throttle:X,1
// is needed here.  Public / unauthenticated API endpoints would still need
// their own throttle middleware if added.
Route::prefix('v1')->middleware(['api.key'])->group(function () {
    Route::get('accounts', [AccountApiController::class, 'index']);
    Route::post('orders', [OrderApiController::class, 'store']);
    Route::get('orders/{orderNo}', [OrderApiController::class, 'show']);
});
