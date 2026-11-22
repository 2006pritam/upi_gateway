<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Refund;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EarningsController extends Controller
{
    /** Day-by-day ledger: money received, refunds recorded, net and a running total. Informational only; it is not a settlement. */
    public function index(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['account' => ['nullable', 'integer'], 'days' => ['nullable', 'in:7,30,90']]);
        $days = (int) ($data['days'] ?? 30);
        $accountId = $data['account'] ?? null;
        $accounts = $user->paymentAccounts()->get(['id', 'label']);
        abort_if($accountId && ! $accounts->contains('id', $accountId), 404);

        $start = now()->subDays($days - 1)->startOfDay();
        $paid = fn () => $user->orders()->where('environment', Order::LIVE)->where('status', Order::PAID)
            ->when($accountId, fn ($q) => $q->where('payment_account_id', $accountId));
        $refunds = fn () => Refund::where('refunds.user_id', $user->id)
            ->when($accountId, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('payment_account_id', $accountId)));

        $opening = round((float) $paid()->where('paid_at', '<', $start)->sum('amount') - (float) $refunds()->where('created_at', '<', $start)->sum('amount'), 2);

        $in = $paid()->where('paid_at', '>=', $start)->get(['amount', 'paid_at'])->groupBy(fn ($o) => $o->paid_at->format('Y-m-d'));
        $out = $refunds()->where('created_at', '>=', $start)->get(['amount', 'created_at'])->groupBy(fn ($r) => $r->created_at->format('Y-m-d'));

        $running = $opening;
        $rows = [];
        for ($d = $start->copy(); $d->lte(now()); $d->addDay()) {
            $k = $d->format('Y-m-d');
            $received = round((float) ($in[$k] ?? collect())->sum('amount'), 2);
            $refunded = round((float) ($out[$k] ?? collect())->sum('amount'), 2);
            $running = round($running + $received - $refunded, 2);
            $rows[] = ['date' => Carbon::parse($k), 'count' => ($in[$k] ?? collect())->count(), 'received' => $received, 'refunded' => $refunded, 'running' => $running];
        }

        return view('earnings.index', [
            'rows' => array_reverse($rows), 'opening' => $opening, 'total' => $running, 'days' => $days,
            'accounts' => $accounts, 'accountId' => $accountId,
        ]);
    }
}
