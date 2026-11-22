<?php

namespace App\Services;

use App\Models\Order;

/**
 * Cheap, explainable fraud signals. They only label an order; nothing is blocked automatically.
 * Each flag: code => [severity, label].
 */
class RiskService
{
    public const FLAGS = [
        'ip_burst' => ['high', 'Many orders from one device in a few minutes'],
        'mobile_unpaid' => ['medium', 'Several unpaid orders for this mobile number'],
        'late_payment' => ['low', 'Paid after the order expired'],
        'utr_retries' => ['medium', 'Customer needed several UTR attempts'],
        'manual_utr' => ['low', 'Confirmed by hand from a customer-typed UTR'],
    ];

    public function assess(Order $order): Order
    {
        if ($order->environment !== Order::LIVE) {
            return $order;
        }

        $flags = [];

        if ($order->created_ip) {
            $recent = Order::where('user_id', $order->user_id)->where('environment', Order::LIVE)
                ->where('created_ip', $order->created_ip)->where('created_at', '>=', now()->subMinutes(10))->count();
            if ($recent >= 6) {
                $flags[] = 'ip_burst';
            }
        }

        if ($order->customer_mobile) {
            $unpaid = Order::where('user_id', $order->user_id)->where('environment', Order::LIVE)
                ->where('customer_mobile', $order->customer_mobile)->whereIn('status', [Order::PENDING, Order::EXPIRED])
                ->where('created_at', '>=', now()->subHour())->count();
            if ($unpaid >= 4) {
                $flags[] = 'mobile_unpaid';
            }
        }

        if ($order->status === Order::PAID && $order->paid_at && $order->expires_at && $order->paid_at->gt($order->expires_at)) {
            $flags[] = 'late_payment';
        }
        if ($order->utr_attempts >= 2) {
            $flags[] = 'utr_retries';
        }
        if ($order->verified_by === 'utr_review') {
            $flags[] = 'manual_utr';
        }

        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $level = collect($flags)->map(fn ($f) => self::FLAGS[$f][0])->sortByDesc(fn ($s) => $rank[$s])->first();

        $order->forceFill(['risk_flags' => $flags ?: null, 'risk_level' => $level])->saveQuietly();

        return $order;
    }

    public static function label(string $flag): string
    {
        return self::FLAGS[$flag][1] ?? $flag;
    }
}
