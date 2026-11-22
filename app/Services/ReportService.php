<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportService
{
    /** Live orders created in [$from, $to]. */
    public function query(User $user, CarbonInterface $from, CarbonInterface $to): HasMany
    {
        return $user->orders()->where('environment', Order::LIVE)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /** @return array{orders:int,paid:int,paid_total:float,pending:int,expired:int,review:int,conversion:float,by_account:array,by_day:array} */
    public function summary(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = $this->query($user, $from, $to)->with('paymentAccount:id,label')->get(['id', 'payment_account_id', 'status', 'amount', 'created_at', 'paid_at']);
        $paid = $orders->where('status', Order::PAID);

        return [
            'orders' => $orders->count(),
            'paid' => $paid->count(),
            'paid_total' => round((float) $paid->sum('amount'), 2),
            'refunded' => round((float) \App\Models\Refund::where('user_id', $user->id)->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->sum('amount'), 2),
            'pending' => $orders->where('status', Order::PENDING)->count(),
            'expired' => $orders->where('status', Order::EXPIRED)->count(),
            'review' => $orders->where('status', Order::REVIEW)->count(),
            'conversion' => $orders->count() ? round($paid->count() / $orders->count() * 100, 1) : 0.0,
            'by_account' => $paid->groupBy(fn ($o) => $o->paymentAccount?->label ?? 'Unknown')
                ->map(fn ($g) => ['count' => $g->count(), 'total' => round((float) $g->sum('amount'), 2)])->all(),
            'by_day' => $paid->groupBy(fn ($o) => ($o->paid_at ?? $o->created_at)->format('Y-m-d'))
                ->map(fn ($g) => ['count' => $g->count(), 'total' => round((float) $g->sum('amount'), 2)])->sortKeys()->all(),
        ];
    }

    /** Neutralise spreadsheet formulas (=, +, -, @) in user-supplied text. */
    public static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
