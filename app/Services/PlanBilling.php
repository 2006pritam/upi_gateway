<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\PlanPayment;
use App\Models\User;
use App\Support\SubscriptionPlans;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Merchants pay their plan fee through the admin's own gateway. The plan starts
 * only when that order is verified as paid.
 */
class PlanBilling
{
    public function __construct(private readonly OrderService $orders) {}

    /** The admin who collects plan fees: an enabled admin with an active UPI account. */
    public function receiver(): ?User
    {
        return User::where('role', 'admin')
            ->where('is_active', true)
            ->whereHas('paymentAccounts', fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->first();
    }

    /** A paid term that has run out: the merchant renews by simply paying again. */
    public function isRenewal(User $merchant): bool
    {
        return $merchant->subscription_status === User::SUBSCRIPTION_EXPIRED || $merchant->subscriptionHasExpired();
    }

    /** The plan offered first on renewal: the one that just ended. */
    public function renewalPlan(User $merchant): string
    {
        foreach ([$merchant->subscription_plan, $merchant->subscription_requested_plan] as $plan) {
            if (SubscriptionPlans::valid((string) $plan)) {
                return $plan;
            }
        }

        return 'monthly';
    }

    /**
     * The merchant's latest plan payment that is still waiting: open checkout or UTR
     * under review. Checkouts older than the current term belong to the past.
     */
    public function openPayment(User $merchant): ?PlanPayment
    {
        return PlanPayment::with('order')
            ->where('user_id', $merchant->id)
            ->whereNull('applied_at')
            ->when($merchant->subscription_started_at, fn ($q, $start) => $q->where('created_at', '>=', $start))
            ->whereHas('order', fn ($q) => $q->where('status', Order::REVIEW)
                ->orWhere(fn ($q) => $q->where('status', Order::PENDING)->where('expires_at', '>', now())))
            ->latest('id')
            ->first();
    }

    /**
     * The order the merchant should pay now. An open one is reused, so pressing
     * the button twice never creates two payments.
     */
    public function checkout(User $merchant, ?string $ip = null, ?string $chosenPlan = null): Order
    {
        $receiver = $this->receiver()
            ?? throw ValidationException::withMessages(['plan' => 'Online payment is not available right now. Please contact the admin.']);

        return DB::transaction(function () use ($merchant, $receiver, $ip, $chosenPlan) {
            // Same lock order as OrderService::markPaid (admin first, then merchant).
            User::whereKey($receiver->id)->lockForUpdate()->firstOrFail();
            $merchant = User::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            // A first payment is for the plan the admin approved. A renewal may pick any plan.
            $plan = match (true) {
                $merchant->subscription_status === User::SUBSCRIPTION_PAYMENT_DUE => (string) $merchant->subscription_requested_plan,
                $this->isRenewal($merchant) => $chosenPlan ?? $this->renewalPlan($merchant),
                default => '',
            };

            if (! SubscriptionPlans::valid($plan)) {
                throw ValidationException::withMessages(['plan' => 'There is no plan waiting for payment.']);
            }

            $open = $this->openPayment($merchant);

            if ($open && ($open->order->status === Order::REVIEW || $open->plan === $plan)) {
                return $open->order;
            }

            $amount = SubscriptionPlans::amount($plan);

            $order = $this->orders->create($receiver, [
                'amount' => $amount,
                'client_order_id' => 'PLAN-'.$merchant->id.'-'.Str::upper(Str::random(10)),
                'note' => Str::limit(SubscriptionPlans::label($plan).' plan - '.$merchant->displayName(), 80, ''),
                'customer_name' => Str::limit($merchant->name, 100, ''),
                'customer_email' => $merchant->email,
                'customer_mobile' => $merchant->phone,
                'created_ip' => $ip,
            ]);

            PlanPayment::create([
                'user_id' => $merchant->id,
                'order_id' => $order->id,
                'plan' => $plan,
                'amount' => $amount,
            ]);

            return $order;
        });
    }

    public function isPlanOrder(Order $order): bool
    {
        return PlanPayment::where('order_id', $order->id)->exists();
    }

    /**
     * Called inside the transaction that marks an order paid, whichever way it was
     * verified. Starts the plan once; if the merchant's state no longer allows it
     * (already active, rejected, ...) nothing changes and the admin is shown it.
     */
    public function applyPaidOrder(Order $order): void
    {
        $payment = PlanPayment::where('order_id', $order->id)->lockForUpdate()->first();

        if (! $payment || $payment->applied_at || $order->status !== Order::PAID || $order->environment !== Order::LIVE) {
            return;
        }

        $merchant = User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();

        $waiting = in_array($merchant->subscription_status, [User::SUBSCRIPTION_PAYMENT_DUE, User::SUBSCRIPTION_EXPIRED], true)
            || $merchant->subscriptionHasExpired();

        // A checkout made before the current term began was settled some other way.
        $stale = $merchant->subscription_started_at && $payment->created_at->lt($merchant->subscription_started_at);

        $eligible = $waiting && ! $stale && ! $merchant->isAdmin()
            && SubscriptionPlans::valid($payment->plan)
            && (float) $order->amount >= (float) $payment->amount;

        $meta = [
            'email' => $merchant->email,
            'plan' => $payment->plan,
            'amount_inr' => (float) $order->payableAmount(),
            'order_no' => $order->order_no,
            'payment_reference' => $order->utr,
            'verified_by' => $order->verified_by,
        ];

        if ($eligible) {
            $this->activate($merchant, $payment->plan, $order->paid_at ?? now());
            $payment->update(['applied_at' => now()]);
            $meta['expires_at'] = $merchant->subscription_expires_at->toIso8601String();
        }

        AdminAuditLog::create([
            'admin_id' => $order->user_id,
            'action' => $eligible ? 'subscription_paid_online' : 'subscription_payment_needs_review',
            'subject_type' => 'user',
            'subject_id' => $merchant->id,
            'meta' => $meta,
        ]);
    }

    /** Start a paid term. Used by online payment and by the admin's manual confirmation. */
    public function activate(User $merchant, string $plan, ?Carbon $startsAt = null): void
    {
        $startsAt ??= now();

        $merchant->forceFill([
            'subscription_status' => User::SUBSCRIPTION_ACTIVE,
            'subscription_requested_plan' => $plan,
            'subscription_plan' => $plan,
            'subscription_paid_at' => $startsAt,
            'subscription_started_at' => $startsAt,
            'subscription_expires_at' => SubscriptionPlans::expiry($plan, $startsAt),
        ])->save();

        if (! $merchant->api_key) {
            $merchant->regenerateApiCredentials();
        }
    }
}
