<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConfirmPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentAccountController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// ── Installer ─────────────────────────────────────────────────────────────────
Route::middleware(App\Http\Middleware\RedirectIfInstalled::class)->prefix('install')->name('install.')->group(function () {
    Route::get('/',          [InstallController::class, 'index'])->name('index');
    Route::get('/database',  [InstallController::class, 'database'])->name('database');
    Route::post('/database', [InstallController::class, 'databaseSave'])->name('database.save');
    Route::get('/migrate',   [InstallController::class, 'migrate'])->name('migrate');
    Route::post('/migrate',  [InstallController::class, 'migrateRun'])->name('migrate.run');
    Route::get('/admin',     [InstallController::class, 'admin'])->name('admin');
    Route::post('/admin',    [InstallController::class, 'adminSave'])->name('admin.save');
    Route::get('/done',      [InstallController::class, 'done'])->name('done');
});

Route::view('/', 'welcome')->name('home');
Route::view('/docs', 'docs')->name('docs');

// ── Public checkout pages ─────────────────────────────────────────────────────
Route::get('/pay/{order}', [CheckoutController::class, 'show'])->name('pay.show');
Route::get('/pay/{order}/status', [CheckoutController::class, 'status'])->middleware('throttle:40,1')->name('pay.status');
Route::post('/pay/{order}/start', [CheckoutController::class, 'start'])->middleware('throttle:20,1')->name('pay.start');
Route::match(['get', 'post'], '/pay/{order}/return', [CheckoutController::class, 'return'])->middleware('throttle:30,1')->name('pay.return');
Route::post('/pay/{order}/utr', [CheckoutController::class, 'submitUtr'])->middleware('throttle:10,1')->name('pay.utr');
Route::get('/pay/{order}/qr.svg', [CheckoutController::class, 'qr'])->name('pay.qr');

// ── Guest auth ────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

// ── Password confirmation (requires auth, no active check — even disabled accounts
//    should be able to confirm before being bounced by EnsureActive) ────────────
Route::middleware('auth')->group(function () {
    Route::get('/confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'confirm']);
});

// ── Authenticated routes ──────────────────────────────────────────────────────
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
    Route::post('/settings/redirect-domains', [SettingsController::class, 'updateRedirectDomains'])->name('settings.redirect-domains');
    Route::post('/settings/regenerate-keys', [SettingsController::class, 'regenerateKeys'])->name('settings.regenerate');

    // Password change — uses Auth::logoutOtherDevices to sign out other sessions.
    Route::get('/settings/password', [ChangePasswordController::class, 'show'])->name('password.change.show');
    Route::put('/settings/password', [ChangePasswordController::class, 'update'])->name('password.change');

    // Admin routes — require password re-confirmation within the last 15 minutes.
    Route::prefix('admin')->name('admin.')->middleware(['admin', 'password.confirm:,900'])->group(function () {
        Route::get('/merchants', [AdminController::class, 'merchants'])->name('merchants');
        Route::post('/merchants/{user}/toggle', [AdminController::class, 'toggleMerchant'])->name('merchants.toggle');
        Route::get('/orders', [AdminController::class, 'orders'])->name('orders');
        Route::get('/orders/{order}', [AdminController::class, 'showOrder'])->name('order.show');
    });
});
