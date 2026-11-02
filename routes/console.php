<?php

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/**
 * Catches payments whose customer closed the checkout page before it
 * confirmed, and expires unpaid orders (after one last status check).
 */
Artisan::command('orders:check-pending', function (OrderService $orders) {
    $checked = 0;

    Order::with('paymentAccount')
        ->where('status', Order::PENDING)
        ->orderBy('id')
        ->chunkById(100, function ($chunk) use ($orders, &$checked) {
            foreach ($chunk as $order) {
                $orders->check($order);
                $checked++;
            }
        });

    $this->info("Checked {$checked} pending orders.");
})->purpose('Verify pending orders with providers and expire stale ones');

Artisan::command('app:create-admin {email} {--name=Admin}', function (string $email) {
    $password = $this->secret('Password (min 8 chars)');

    if (strlen((string) $password) < 8) {
        return $this->error('Password must be at least 8 characters.');
    }

    $user = User::updateOrCreate(['email' => $email], [
        'name' => $this->option('name'),
        'password' => $password,
        'role' => 'admin',
        'is_active' => true,
    ]);

    if (! $user->api_key) {
        $user->regenerateApiCredentials();
    }

    $this->info("Admin ready: {$email}");
})->purpose('Create or promote an admin user');

Schedule::command('orders:check-pending')->everyMinute()->withoutOverlapping();
