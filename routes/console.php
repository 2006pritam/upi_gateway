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
    $password = $this->secret('Password (min 10 chars)');

    if (strlen((string) $password) < 10) {
        return $this->error('Password must be at least 10 characters.');
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

Artisan::command('reports:daily-summary', function (\App\Services\ReportService $reports) {
    $day = now()->subDay();
    $sent = 0;
    User::where('daily_summary', true)->where('is_active', true)->each(function (User $u) use ($reports, $day, &$sent) {
        $s = $reports->summary($u, $day, $day);
        $body = config('app.name').' summary for '.$day->format('d M Y')."\n\n"
            .'Received: Rs '.number_format($s['paid_total'], 2)." from {$s['paid']} payment(s)\n"
            ."Orders created: {$s['orders']} (conversion {$s['conversion']}%)\n"
            ."Still pending: {$s['pending']}, in review: {$s['review']}\n\n"
            .'Dashboard: '.url('/dashboard');
        \Illuminate\Support\Facades\Mail::raw($body, fn ($m) => $m->to($u->email)->subject(config('app.name').' daily summary - '.$day->format('d M')));
        $sent++;
    });
    $this->info("Sent {$sent} summaries.");
})->purpose('E-mail yesterday\'s payment summary to merchants who opted in');

Schedule::command('reports:daily-summary')->dailyAt('08:00')->timezone('Asia/Kolkata');
Schedule::command('orders:check-pending')->everyMinute()->withoutOverlapping();
