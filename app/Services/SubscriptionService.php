<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SubscriptionService
{
    /** Invoice stays payable this long (a normal checkout link lives only 15 minutes). */
    public const INVOICE_VALID_MINUTES = 4320;

    public function __construct(private OrderService $orders, private TelegramNotifier $telegram) {}

    /**
     * Create this cycle's order and send the pay link. One invoice per due date (a repeat run is a no-op),
     * and a long-missed subscription is billed once, not once per missed cycle.
     */
    public function bill(Subscription $sub): ?\App\Models\Order
    {
        $merchant = $sub->user;
        $due = $sub->next_due_at->copy();

        try {
            $order = $this->orders->create($merchant, [
                'amount' => $sub->amount,
                'client_order_id' => 'SUB'.$sub->id.'-'.$due->format('Ymd'),
                'payment_account_id' => $sub->payment_account_id,
                'customer_name' => $sub->customer_name,
                'customer_email' => $sub->customer_email,
                'customer_mobile' => $sub->customer_mobile,
                'note' => mb_substr($sub->plan_name, 0, 50),
                'expiry_minutes' => self::INVOICE_VALID_MINUTES,
            ]);
        } catch (UniqueConstraintViolationException|\Illuminate\Validation\ValidationException) {
            // Already invoiced for this date, or the merchant has no usable account right now: try again next run.
            $sub->update(['next_due_at' => $due->lt(today()) ? $sub->advance($due) : $due]);

            return null;
        }

        $next = $sub->advance($due);
        while ($next->lte(today())) {
            $next = $sub->advance($next);
        }
        $sub->forceFill(['last_order_id' => $order->id, 'last_billed_at' => now(), 'next_due_at' => $next])->save();

        if ($sub->customer_email) {
            try {
                Mail::raw(
                    "Hello {$sub->customer_name},\n\nYour payment of Rs {$order->payableAmount()} for {$sub->plan_name} is due.\nPay securely here: {$order->paymentUrl()}\n\nThe link stays valid for 3 days.\n\n{$merchant->displayName()}",
                    fn ($m) => $m->to($sub->customer_email)->subject("{$merchant->displayName()}: payment due for {$sub->plan_name}")
                );
            } catch (Throwable) {
                // The invoice exists; the merchant can still copy the link from the order page.
            }
        }

        if ($merchant->telegram_alerts) {
            $this->telegram->send($merchant, "🧾 Invoice sent to {$sub->customer_name}: ₹{$order->amount} for {$sub->plan_name}\n{$order->paymentUrl()}");
        }

        return $order;
    }
}
