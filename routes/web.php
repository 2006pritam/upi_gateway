<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentAccountController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('/docs', 'docs')->name('docs');

// Public checkout page that customers see.
Route::get('/pay/{order}', [CheckoutController::class, 'show'])->name('pay.show');
Route::get('/pay/{order}/status', [CheckoutController::class, 'status'])->middleware('throttle:40,1')->name('pay.status');
Route::post('/pay/{order}/start', [CheckoutController::class, 'start'])->middleware('throttle:20,1')->name('pay.start');
Route::match(['get', 'post'], '/pay/{order}/return', [CheckoutController::class, 'return'])->middleware('throttle:30,1')->name('pay.return');
Route::post('/pay/{order}/utr', [CheckoutController::class, 'submitUtr'])->middleware('throttle:10,1')->name('pay.utr');
Route::get('/pay/{order}/qr.svg', [CheckoutController::class, 'qr'])->name('pay.qr');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/accounts', [PaymentAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [PaymentAccountController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [PaymentAccountController::class, 'store'])->name('accounts.store');
    Route::post('/accounts/{account}/limit', [PaymentAccountController::class, 'updateLimit'])->name('accounts.limit');
    Route::post('/accounts/{account}/toggle', [PaymentAccountController::class, 'toggle'])->name('accounts.toggle');
    Route::post('/accounts/{account}/default', [PaymentAccountController::class, 'makeDefault'])->name('accounts.default');
    Route::delete('/accounts/{account}', [PaymentAccountController::class, 'destroy'])->name('accounts.destroy');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/recheck', [OrderController::class, 'recheck'])->name('orders.recheck');
    Route::post('/orders/{order}/approve-utr', [OrderController::class, 'approveUtr'])->name('orders.approve-utr');
    Route::post('/orders/{order}/reject-utr', [OrderController::class, 'rejectUtr'])->name('orders.reject-utr');
    Route::post('/orders/{order}/mark-paid', [OrderController::class, 'markPaid'])->name('orders.mark-paid');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::post('/settings/webhook', [SettingsController::class, 'updateWebhook'])->name('settings.webhook');
    Route::post('/settings/rotation', [SettingsController::class, 'updateRotation'])->name('settings.rotation');
    Route::post('/settings/regenerate-keys', [SettingsController::class, 'regenerateKeys'])->name('settings.regenerate');

    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::get('/merchants', [AdminController::class, 'merchants'])->name('merchants');
        Route::post('/merchants/{user}/toggle', [AdminController::class, 'toggleMerchant'])->name('merchants.toggle');
        Route::get('/orders', [AdminController::class, 'orders'])->name('orders');
    });
});
