<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Telegram alert to the merchant when a live payment is received or needs their review. */
class SendMerchantAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Order $order, public string $event) {}

    public function backoff(): array
    {
        return [20, 120];
    }

    public function handle(TelegramNotifier $telegram): void
    {
        $order = $this->order;
        $merchant = $order->user;

        if ($order->environment !== Order::LIVE || ! $merchant?->telegram_alerts) {
            return;
        }

        $who = $order->customer_name ?: ($order->customer_mobile ?: 'a customer');
        $text = match ($this->event) {
            'order.paid' => "✅ ₹{$order->amount} received from {$who}\nOrder {$order->client_order_id}".($order->utr ? "\nUTR {$order->utr}" : ''),
            'order.under_review' => "🕵️ Review needed: {$who} says they paid ₹{$order->amount} (UTR {$order->customer_utr}).\nOpen the dashboard to approve.",
            default => null,
        };

        if ($text !== null && in_array($order->risk_level, ['medium', 'high'], true)) {
            $text .= "\n⚠ ".ucfirst($order->risk_level).' risk: '.collect($order->risk_flags)->map(fn ($f) => \App\Services\RiskService::label($f))->implode('; ');
        }

        if ($text !== null && ! $telegram->send($merchant, $text)) {
            $this->fail(new \RuntimeException('Telegram send failed'));
        }
    }
}
