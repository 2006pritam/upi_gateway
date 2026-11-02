<?php

namespace App\Services;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * @param  array{amount: numeric, client_order_id?: string, payment_account_id?: int, customer_name?: string, customer_email?: string, customer_mobile?: string, note?: string, redirect_url?: string}  $data
     */
    public function create(User $merchant, array $data): Order
    {
        // Lock this merchant's accounts so concurrent orders can't jointly
        // push an account past its daily limit.
        return DB::transaction(function () use ($merchant, $data) {
            $merchant->paymentAccounts()->lockForUpdate()->get(['id']);

            $account = $this->resolveAccount($merchant, (float) $data['amount'], $data['payment_account_id'] ?? null);

            return $this->createFor($merchant, $account, $data);
        });
    }

    private function createFor(User $merchant, PaymentAccount $account, array $data): Order
    {
        $orderNo = 'ORD'.strtoupper(Str::random(16));

        $order = new Order([
            'order_no' => $orderNo,
            'payment_account_id' => $account->id,
            'client_order_id' => $data['client_order_id'] ?? $orderNo,
            'amount' => $data['amount'],
            'status' => Order::PENDING,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'customer_mobile' => $data['customer_mobile'] ?? null,
            'note' => $data['note'] ?? null,
            'redirect_url' => $data['redirect_url'] ?? null,
            'expires_at' => now()->addMinutes(config('gateway.order_expiry_minutes')),
        ]);
        $order->user()->associate($merchant);
        $order->save();

        return $order;
    }

    /**
     * Ask the provider for the latest status, at most once per
     * `check_interval_seconds` per order, however many tabs are polling.
     */
    public function check(Order $order, bool $force = false): Order
    {
        if (! $order->isPending()) {
            return $order;
        }

        // `force` skips the throttle, e.g. when the customer just returned from the PG.
        $claimed = Order::whereKey($order->id)
            ->when(! $force, fn ($q) => $q->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', now()->subSeconds(config('gateway.check_interval_seconds')))))
            ->update(['last_checked_at' => now()]);

        if ($claimed) {
            $result = $order->paymentAccount->verifier()->check($order);

            if ($result->paid) {
                return $this->markPaid($order, $result->utr, $result->txnId, $result->raw, 'provider');
            }
        }

        if ($order->isPastExpiry()) {
            return $this->expire($order);
        }

        return $order->refresh();
    }

    public function markPaid(Order $order, ?string $utr, ?string $txnId, array $raw, string $verifiedBy): Order
    {
        try {
            $updated = DB::transaction(function () use ($order, $utr, $txnId, $raw, $verifiedBy) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();

                // Late payments (after expiry) are still real money, so accept them.
                if ($locked->status === Order::PAID) {
                    return false;
                }

                $locked->forceFill([
                    'status' => Order::PAID,
                    'utr' => $utr,
                    'provider_txn_id' => $txnId,
                    'provider_response' => $raw ?: null,
                    'verified_by' => $verifiedBy,
                    'paid_at' => now(),
                    'review_note' => null,
                ])->save();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests raced to use the same UTR; the unique index caught it.
            throw ValidationException::withMessages(['utr' => 'This UTR is already used on another order.']);
        }

        $order->refresh();

        if ($updated) {
            SendWebhook::dispatch($order, 'order.paid');
        }

        return $order;
    }

    public function expire(Order $order): Order
    {
        $updated = Order::whereKey($order->id)
            ->where('status', Order::PENDING)
            ->update(['status' => Order::EXPIRED]);

        $order->refresh();

        if ($updated) {
            SendWebhook::dispatch($order, 'order.expired');
        }

        return $order;
    }

    /**
     * Customer says they paid and gives the UTR. The order goes to review;
     * it is never marked paid until the merchant confirms the same UTR.
     */
    public function submitCustomerUtr(Order $order, string $utr): Order
    {
        $utr = $this->normalizeUtr($utr);

        if (! $order->acceptsCustomerUtr()) {
            throw ValidationException::withMessages(['utr' => 'UTR can no longer be submitted for this payment. Please contact the merchant.']);
        }

        $this->ensureUtrUnused($order, $utr);

        $updated = Order::whereKey($order->id)
            ->whereIn('status', [Order::PENDING, Order::EXPIRED])
            ->where('utr_attempts', '<', Order::MAX_UTR_ATTEMPTS)
            ->update([
                'status' => Order::REVIEW,
                'customer_utr' => $utr,
                'utr_submitted_at' => now(),
                'utr_attempts' => DB::raw('utr_attempts + 1'),
                'review_note' => null,
            ]);

        $order->refresh();

        if ($updated) {
            SendWebhook::dispatch($order, 'order.under_review');
        }

        return $order;
    }

    /**
     * Merchant confirms by typing the UTR they see in their bank/UPI app.
     * It must match what the customer submitted.
     */
    public function approveCustomerUtr(Order $order, string $bankUtr): Order
    {
        if ($order->status !== Order::REVIEW) {
            throw ValidationException::withMessages(['bank_utr' => 'This order is not waiting for review.']);
        }

        if (! hash_equals($order->customer_utr, $this->normalizeUtr($bankUtr))) {
            throw ValidationException::withMessages(['bank_utr' => "UTR doesn't match what the customer submitted. Check your bank/UPI app again, or reject."]);
        }

        $this->ensureUtrUnused($order, $order->customer_utr);

        return $this->markPaid($order, $order->customer_utr, null, [], 'utr_review');
    }

    /**
     * Merchant couldn't find the payment. The customer gets another chance
     * (fresh expiry window) unless they have used all attempts.
     */
    public function rejectCustomerUtr(Order $order, ?string $reason): Order
    {
        if ($order->status !== Order::REVIEW) {
            throw ValidationException::withMessages(['reason' => 'This order is not waiting for review.']);
        }

        $canRetry = $order->utr_attempts < Order::MAX_UTR_ATTEMPTS;

        Order::whereKey($order->id)->where('status', Order::REVIEW)->update([
            'status' => $canRetry ? Order::PENDING : Order::EXPIRED,
            'review_note' => $reason ?: "UTR {$order->customer_utr} was not found in the merchant's account.",
            'expires_at' => $canRetry ? now()->addMinutes(config('gateway.order_expiry_minutes')) : $order->expires_at,
        ]);

        $order->refresh();

        if (! $canRetry) {
            SendWebhook::dispatch($order, 'order.expired');
        }

        return $order;
    }

    /**
     * A UTR identifies one bank transfer, so it may back only one order per merchant.
     */
    public function ensureUtrUnused(Order $order, string $utr): void
    {
        $taken = Order::where('user_id', $order->user_id)
            ->whereKeyNot($order->id)
            ->where(fn ($q) => $q->where('utr', $utr)
                ->orWhere(fn ($q) => $q->where('customer_utr', $utr)->where('status', Order::REVIEW)))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['utr' => 'This UTR is already used on another order.']);
        }
    }

    public function normalizeUtr(string $utr): string
    {
        return strtoupper(preg_replace('/\s+/', '', $utr));
    }

    /**
     * Pick the UPI account for a new order:
     * - an explicit `payment_account_id` is preferred while it is active and
     *   under its daily limit; otherwise the order falls back to rotation
     *   (all accounts belong to the same merchant, so any of them is fine);
     * - rotation picks among active accounts with room left today:
     *   `random` picks any one, `default` prefers the default account.
     */
    private function resolveAccount(User $merchant, float $amount, ?int $accountId): PaymentAccount
    {
        if ($accountId && ! $merchant->paymentAccounts()->whereKey($accountId)->exists()) {
            throw ValidationException::withMessages(['payment_account_id' => 'This UPI account does not exist.']);
        }

        $accounts = $merchant->paymentAccounts()->where('is_active', true)->withUsageToday()->get();

        $requested = $accountId ? $accounts->firstWhere('id', $accountId) : null;
        if ($requested?->canAccept($amount)) {
            return $requested;
        }

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['payment_account_id' => 'Add an active UPI account in the dashboard before creating orders.']);
        }

        $available = $accounts->filter(fn (PaymentAccount $a) => $a->canAccept($amount));

        if ($available->isEmpty()) {
            throw ValidationException::withMessages(['amount' => 'All UPI accounts have reached their daily limit for this amount. Try a smaller amount or raise a limit.']);
        }

        return $merchant->rotation_mode === 'random'
            ? $available->random()
            : $available->sortBy([['is_default', 'desc'], ['id', 'asc']])->first();
    }
}
