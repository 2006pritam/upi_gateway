<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $orders = $user->orders();
        $paid = fn () => (clone $orders)->where('status', Order::PAID);

        $closedCount = (clone $orders)->whereIn('status', [Order::PAID, Order::EXPIRED])->count();
        $paidCount = $paid()->count();

        $stats = [
            'today_amount' => $paid()->where('paid_at', '>=', today())->sum('amount'),
            'today_count' => $paid()->where('paid_at', '>=', today())->count(),
            'total_amount' => $paid()->sum('amount'),
            'paid_count' => $paidCount,
            'pending_count' => (clone $orders)->where('status', Order::PENDING)->count(),
            'review_count' => (clone $orders)->where('status', Order::REVIEW)->count(),
            'success_rate' => $closedCount ? round($paidCount / $closedCount * 100) : null,
        ];

        // Last 7 days of collections, grouped in PHP so it works on any database.
        $since = today()->subDays(6);
        $byDay = $paid()->where('paid_at', '>=', $since)->get(['amount', 'paid_at'])
            ->groupBy(fn ($o) => $o->paid_at->toDateString());
        $chart = collect(range(0, 6))->map(function ($i) use ($since, $byDay) {
            $day = $since->copy()->addDays($i);
            $rows = $byDay->get($day->toDateString(), collect());

            return ['label' => $day->format('D'), 'date' => $day->format('d M'), 'amount' => (float) $rows->sum('amount'), 'count' => $rows->count()];
        });

        $checklist = [
            ['Create your merchant account', true, null],
            ['Add a UPI account', $user->paymentAccounts()->exists(), route('accounts.create')],
            ['Set up your webhook URL', (bool) $user->webhook_url, route('settings')],
            ['Receive your first payment', $paidCount > 0, route('orders.create')],
        ];

        return view('dashboard', [
            'stats' => $stats,
            'chart' => $chart,
            'weekTotal' => $chart->sum('amount'),
            'checklist' => $checklist,
            'recentOrders' => (clone $orders)->latest()->limit(6)->get(),
        ]);
    }
}
