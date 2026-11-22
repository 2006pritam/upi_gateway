<?php

namespace App\Services;

use App\Jobs\SendMerchantAlert;
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
    public function create(User $merchant, array $data, string $environment = Order::LIVE): Order
    {
        if ($environment === Order::SANDBOX) {
            return $this->createSandbox($merchant, $data);
        }

        if ($environment !== Order::LIVE) {
            throw ValidationException::withMessages(['environment' => 'Invalid payment environment.']);
        }

        // Serialize this merchant's order creation, including the case where
        // no account exists yet, so daily-limit totals remain consistent.
        return DB::transaction(function () use ($merchant, $data) {
            User::whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            $account = $this->resolveAccount($merchant, (float) $data['amount'], $data['payment_account_id'] ?? null);

            return $this->createFor($merchant, $account, $data, Order::LIVE);
        });
    }

    private function createSandbox(User $merchant, array $data): Order
    {
        return DB::transaction(function () use ($merchant, $data) {
            User::whereKey($merchant->id)->lockForUpdate()->firstOrFail();

            return $this->createFor($merchant, null, $data, Order::SANDBOX);
        });
    }

    private function createFor(User $merchant, ?PaymentAccount $account, array $data, string $environment): Order
    {
        $orderNo = ($environment === Order::SANDBOX ? 'TST' : 'ORD').strtoupper(Str::random(16));

        $order = new Order([
            'order_no' => $orderNo,
            'payment_account_id' => $account?->id,
            'client_order_id' => $data['client_order_id'] ?? $orderNo,
            'amount' => $data['amount'],
            'payable_amount' => $environment === Order::LIVE && $account ? $this->allocatePayableAmount($account, (float) $data['amount']) : null,
            'status' => Order::PENDING,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'customer_mobile' => $data['customer_mobile'] ?? null,
            'note' => $data['note'] ?? null,
            'redirect_url' => $data['redirect_url'] ?? null,
            'payment_link_id' => $data['payment_link_id'] ?? null,
            'created_ip' => $data['created_ip'] ?? null,
            'expires_at' => now()->addMinutes((int) ($data['expiry_minutes'] ?? config('gateway.order_expiry_minutes'))),
        ]);
        $order->forceFill(['environment' => $environment]);
        $order->user()->associate($merchant);
        $order->save();

        return app(RiskService::class)->assess($order);
    }

    /**
     * For e-mail verified accounts the payer's bank alert only carries an amount, so two open orders
     * for the same price are indistinguishable. Give each its own amount: price, price+0.01, ...
     * Runs inside create()'s per-merchant lock, so two requests cannot take the same slot.
     */
    private function allocatePayableAmount(PaymentAccount $account, float $price): ?string
    {
        $cfg = config('gateway.unique_amount');
        if (! ($cfg['enabled'] ?? false) || ! in_array($account->provider, $cfg['providers'] ?? [], true)) {
            return null;
        }

        $reservedSince = now()->subMinutes((int) ($cfg['grace_minutes'] ?? 60));
        $taken = Order::where('payment_account_id', $account->id)
            ->where('environment', Order::LIVE)
            ->where(fn ($q) => $q->whereIn('status', [Order::PENDING, Order::REVIEW])
                ->orWhere(fn ($q) => $q->where('status', Order::EXPIRED)->where('expires_at', '>', $reservedSince)))
            ->get(['amount', 'payable_amount'])
            ->map(fn (Order $o) => number_format((float) ($o->payable_amount ?? $o->amount), 2, '.', ''))
            ->flip();

        $paise = (int) round($price * 100);
        for ($offset = 0; $offset <= (int) ($cfg['max_offset_paise'] ?? 50); $offset++) {
            $candidate = number_format(($paise + $offset) / 100, 2, '.', '');
            if (! $taken->has($candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['amount' => 'Too many open orders for this amount right now. Please try again in a few minutes.']);
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

        // Orders created before the expiry compatibility fix may legitimately
        // have a NULL expiry. Give them a fresh local expiry before any
        // provider verifier or scheduler code dereferences the timestamp.
        if ($order->expires_at === null) {
            $order->forceFill([
                'expires_at' => now()->addMinutes((int) config('gateway.order_expiry_minutes', 15)),
            ])->save();

            return $order->refresh();
        }

        if ($order->environment === Order::SANDBOX) {
            return $order->isPastExpiry() ? $this->expire($order) : $order->refresh();
        }

        // `force` skips the throttle, e.g. when the customer just returned from the PG.
        $claimed = Order::whereKey($order->id)
            ->where('status', Order::PENDING)
            ->when(! $force, fn ($q) => $q->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', now()->subSeconds(config('gateway.check_interval_seconds')))))
            ->update(['last_checked_at' => now()]);

        // MySQL reports zero affected rows when a forced recheck occurs within
        // the same second and writes the same timestamp value. A forced check
        // intentionally bypasses the interval, so verify the state separately.
        if ($force && ! $claimed) {
            $claimed = Order::whereKey($order->id)
                ->where('status', Order::PENDING)
                ->exists();
        }

        if ($claimed) {
            $result = $order->paymentAccount->verifier()->check($order);

            // Provider errors come back as "pending + error". Track them so a broken account stops receiving new orders.
            if (! $result->paid && isset($result->raw['error'])) {
                $order->paymentAccount->recordCheckFailure();
            } else {
                $order->paymentAccount->recordCheckSuccess();
            }

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
                User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();

                if ($locked->environment !== Order::LIVE) {
                    throw ValidationException::withMessages(['order' => 'Sandbox orders cannot be paid through live payment handlers.']);
                }

                // Late payments after expiry are accepted; paid and review
                // orders cannot be changed through this path.
                if ($locked->status === Order::PAID) {
                    return false;
                }

                if ($locked->status === Order::REVIEW) {
                    return false;
                }

                if ($utr !== null) {
                    $this->ensureUtrUnused($locked, $utr);
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
            $this->dispatchEvent($order, 'order.paid');
        }

        return $order;
    }

    /** Webhook to the merchant's server, plus a Telegram alert for the events they care about. */
    private function dispatchEvent(Order $order, string $event): void
    {
        app(RiskService::class)->assess($order);
        SendWebhook::dispatch($order, $event)->afterCommit();

        if (in_array($event, ['order.paid', 'order.under_review'], true)) {
            SendMerchantAlert::dispatch($order, $event)->afterCommit();
        }
    }

    public function expire(Order $order): Order
    {
        $updated = Order::whereKey($order->id)
            ->where('status', Order::PENDING)
            ->update(['status' => Order::EXPIRED]);

        $order->refresh();

        if ($updated) {
            SendWebhook::dispatch($order, 'order.expired')->afterCommit();
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

        $updated = DB::transaction(function () use ($order, $utr) {
            User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            // Lock the row so concurrent submissions cannot both pass the
            // acceptsCustomerUtr / ensureUtrUnused checks simultaneously.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $locked->load('paymentAccount')->acceptsCustomerUtr()) {
                throw ValidationException::withMessages(['utr' => 'UTR can no longer be submitted for this payment. Please contact the merchant.']);
            }

            $this->ensureUtrUnused($locked, $utr);

            return Order::whereKey($locked->id)
                ->whereIn('status', [Order::PENDING, Order::EXPIRED])
                ->where('utr_attempts', '<', Order::MAX_UTR_ATTEMPTS)
                ->update([
                    'status' => Order::REVIEW,
                    'customer_utr' => $utr,
                    'utr_submitted_at' => now(),
                    'utr_attempts' => DB::raw('utr_attempts + 1'),
                    'review_note' => null,
                ]);
        });

        $order->refresh();

        if ($updated) {
            $this->dispatchEvent($order, 'order.under_review');
        }

        return $order;
    }

    /**
     * Merchant confirms by typing the UTR they see in their bank/UPI app.
     * It must match what the customer submitted.
     */
    public function approveCustomerUtr(Order $order, string $bankUtr): Order
    {
        $bankUtr = $this->normalizeUtr($bankUtr);

        DB::transaction(function () use ($order, $bankUtr) {
            User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            // Lock the row to prevent a concurrent approve + reject race.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked->status !== Order::REVIEW) {
                throw ValidationException::withMessages(['bank_utr' => 'This order is not waiting for review.']);
            }

            if (! hash_equals((string) $locked->customer_utr, $bankUtr)) {
                throw ValidationException::withMessages(['bank_utr' => "UTR doesn't match what the customer submitted. Check your bank/UPI app again, or reject."]);
            }

            $this->ensureUtrUnused($locked, $locked->customer_utr);

            $locked->forceFill([
                'status' => Order::PAID,
                'utr' => $locked->customer_utr,
                'verified_by' => 'utr_review',
                'paid_at' => now(),
                'review_note' => null,
            ])->save();

            $this->dispatchEvent($locked, 'order.paid');
        });

        return $order->refresh();
    }

    /**
     * Merchant couldn't find the payment. The customer gets another chance
     * (fresh expiry window) unless they have used all attempts.
     */
    public function rejectCustomerUtr(Order $order, ?string $reason): Order
    {
        $canRetry = DB::transaction(function () use ($order, $reason) {
            // Lock to prevent a concurrent approve + reject race.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked->status !== Order::REVIEW) {
                throw ValidationException::withMessages(['reason' => 'This order is not waiting for review.']);
            }

            $canRetry = $locked->utr_attempts < Order::MAX_UTR_ATTEMPTS;

            Order::whereKey($locked->id)->where('status', Order::REVIEW)->update([
                'status' => $canRetry ? Order::PENDING : Order::EXPIRED,
                'review_note' => $reason ?: "UTR {$locked->customer_utr} was not found in the merchant's account.",
                'expires_at' => $canRetry ? now()->addMinutes(config('gateway.order_expiry_minutes')) : $locked->expires_at,
            ]);

            return $canRetry;
        });

        $order->refresh();

        if (! $canRetry) {
            SendWebhook::dispatch($order, 'order.expired')->afterCommit();
        }

        return $order;
    }

    /** Record that money went back to the customer (the merchant did the transfer outside the gateway). */
    public function refund(Order $order, float $amount, string $reason): \App\Models\Refund
    {
        return DB::transaction(function () use ($order, $amount, $reason) {
            User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($amount < 0.01 || $amount > $locked->refundableAmount() + 0.0001) {
                throw ValidationException::withMessages(['amount' => 'Refund must be between ₹0.01 and ₹'.number_format($locked->refundableAmount(), 2).'.']);
            }

            return $locked->refunds()->create(['user_id' => $locked->user_id, 'amount' => round($amount, 2), 'reason' => $reason]);
        });
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

    public function simulateSandbox(Order $order, string $outcome): Order
    {
        if (! in_array($outcome, ['success', 'failed', 'pending', 'expired', 'timeout', 'invalid_reference'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Invalid sandbox outcome.']);
        }

        if ($outcome === 'invalid_reference') {
            throw ValidationException::withMessages([
                'transaction_reference' => 'The simulated transaction reference is invalid.',
            ]);
        }

        $event = DB::transaction(function () use ($order, $outcome) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->environment !== Order::SANDBOX) {
                abort(404);
            }

            if (! $locked->isPending() || $locked->sandbox_simulated_at !== null) {
                throw ValidationException::withMessages([
                    'outcome' => 'This test order has already been simulated.',
                ]);
            }

            $values = [
                'sandbox_simulated_at' => now(),
                'provider_response' => [
                    'simulated' => true,
                    'environment' => Order::SANDBOX,
                    'outcome' => $outcome,
                ],
                'verified_by' => 'sandbox',
            ];

            $event = match ($outcome) {
                'success' => 'order.paid',
                'failed' => 'order.failed',
                'expired' => 'order.expired',
                'timeout' => 'order.timeout',
                default => 'order.pending',
            };

            if ($outcome === 'success') {
                $values += [
                    'status' => Order::PAID,
                    'utr' => 'TEST'.strtoupper(Str::random(16)),
                    'provider_txn_id' => 'TEST_TXN_'.strtoupper(Str::random(12)),
                    'paid_at' => now(),
                ];
            } elseif ($outcome === 'failed') {
                $values['status'] = Order::FAILED;
            } elseif ($outcome === 'expired') {
                $values['status'] = Order::EXPIRED;
            }

            $locked->forceFill($values)->save();

            return $event;
        });

        $order->refresh();
        SendWebhook::dispatch($order, $event)->afterCommit();

        return $order;
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
        $accounts = $merchant->paymentAccounts()->where('is_active', true)->withUsageToday()->get();

        // Automatic fallback: skip accounts that keep failing, unless every account is failing.
        $healthy = $accounts->filter->isHealthy();
        if ($healthy->isNotEmpty()) {
            $accounts = $healthy->values();
        }

        // Validate that an explicit account ID actually belongs to this merchant
        // (IDOR guard). Use the already-loaded active collection first; only fire
        // a second query when the ID is not in the active set (it may be inactive).
        if ($accountId) {
            $belongsToMerchant = $accounts->contains('id', $accountId)
                || $merchant->paymentAccounts()->whereKey($accountId)->exists();

            if (! $belongsToMerchant) {
                throw ValidationException::withMessages(['payment_account_id' => 'This UPI account does not exist.']);
            }
        }

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
