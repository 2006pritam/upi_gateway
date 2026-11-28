<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\PlanPayment;
use App\Models\User;
use App\Services\PlanBilling;
use App\Support\SubscriptionPlans;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public function merchants()
    {
        $merchants = User::where('role', 'merchant')
            ->withCount([
                'orders' => fn ($q) => $q->where('environment', Order::LIVE),
                'orders as paid_orders_count' => fn ($q) => $q
                    ->where('environment', Order::LIVE)->where('status', Order::PAID),
            ])
            ->withSum(['orders as paid_amount' => fn ($q) => $q
                ->where('environment', Order::LIVE)->where('status', Order::PAID)], 'amount')
            ->latest()
            ->paginate(25);

        // Each merchant's most recent online plan payment, for the status column.
        $planPayments = PlanPayment::with('order')
            ->whereIn('user_id', $merchants->pluck('id'))
            ->orderBy('id')
            ->get()
            ->keyBy('user_id');

        return view('admin.merchants', [
            'merchants' => $merchants,
            'planPayments' => $planPayments,
            'onlineBilling' => app(PlanBilling::class)->receiver() !== null,
            'plans' => SubscriptionPlans::PLANS,
        ]);
    }

    public function toggleMerchant(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403);

        $user->update(['is_active' => ! $user->is_active]);
        $this->audit($request, $user, $user->is_active ? 'merchant_enabled' : 'merchant_disabled', [
            'email' => $user->email,
        ]);

        return back()->with('status', "{$user->displayName()} ".($user->is_active ? 'enabled.' : 'disabled.'));
    }

    /** An account the admin creates directly: active for life, with no plan, payment or approval step. */
    public function storeMerchant(Request $request)
    {
        $password = Password::min(10);
        if (app()->isProduction()) {
            $password = $password->uncompromised();
        }

        $data = $request->validateWithBag('addAccount', [
            'name' => ['required', 'string', 'max:100'],
            'business_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', $password],
        ]);

        $merchant = DB::transaction(function () use ($request, $data) {
            $merchant = User::create($data + [
                'role' => 'merchant',
                'is_active' => true,
                'subscription_status' => User::SUBSCRIPTION_ACTIVE,
                'subscription_plan' => SubscriptionPlans::LIFETIME,
                'subscription_approved_at' => now(),
                'subscription_started_at' => now(),
                'subscription_expires_at' => null,
            ]);
            $merchant->regenerateApiCredentials();
            $this->audit($request, $merchant, 'merchant_created_lifetime', ['email' => $merchant->email]);

            return $merchant;
        });

        return redirect()->route('admin.merchants')
            ->with('status', "{$merchant->displayName()}'s account is ready with lifetime access. Share the email and password with them.");
    }

    public function approveMerchant(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403);
        abort_unless($user->subscription_status === User::SUBSCRIPTION_PENDING, 409, 'This account request is no longer awaiting review.');

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(SubscriptionPlans::availableTo($user)))],
        ]);

        DB::transaction(function () use ($request, $user, $data) {
            $merchant = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($merchant->subscription_status === User::SUBSCRIPTION_PENDING, 409, 'This account request is no longer awaiting review.');

            $merchant->forceFill([
                'subscription_status' => User::SUBSCRIPTION_PAYMENT_DUE,
                'subscription_requested_plan' => $data['plan'],
                'subscription_approved_at' => now(),
            ])->save();

            $this->audit($request, $merchant, 'subscription_request_approved', [
                'email' => $merchant->email,
                'plan' => $merchant->subscription_requested_plan,
                'amount_inr' => SubscriptionPlans::amount($merchant->subscription_requested_plan),
            ]);
        });

        return back()->with('status', "{$user->displayName()} is approved. Subscription access starts only after payment is received and confirmed here.");
    }

    public function rejectMerchant(Request $request, User $user)
    {
        abort_if($user->isAdmin(), 403);
        abort_unless($user->subscription_status === User::SUBSCRIPTION_PENDING, 409, 'Only a new account request can be rejected.');

        $user->forceFill(['subscription_status' => User::SUBSCRIPTION_REJECTED])->save();
        $this->audit($request, $user, 'subscription_request_rejected', ['email' => $user->email]);

        return back()->with('status', "{$user->displayName()}'s account request was rejected.");
    }

    public function activateSubscription(Request $request, User $user, PlanBilling $billing)
    {
        abort_if($user->isAdmin(), 403);

        $data = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($request, $user, $data, $billing) {
            $merchant = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $merchant->subscription_status === User::SUBSCRIPTION_PAYMENT_DUE
                    && array_key_exists((string) $merchant->subscription_requested_plan, SubscriptionPlans::availableTo($merchant)),
                409,
                'This merchant has no approved plan awaiting payment confirmation.'
            );

            $plan = $merchant->subscription_requested_plan;
            $startedAt = now();
            $billing->activate($merchant, $plan, $startedAt);

            $this->audit($request, $merchant, 'subscription_payment_confirmed', [
                'email' => $merchant->email,
                'plan' => $plan,
                'amount_inr' => SubscriptionPlans::amount($plan),
                'payment_reference' => $data['payment_reference'] ?? null,
                'starts_at' => $startedAt->toIso8601String(),
                'expires_at' => $merchant->subscription_expires_at->toIso8601String(),
            ]);
        });

        return back()->with('status', "Payment confirmed and {$user->displayName()}'s subscription is active.");
    }

    public function orders(Request $request)
    {
        $query = Order::with('user', 'paymentAccount')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('admin.orders', ['orders' => $query->paginate(30)->withQueryString()]);
    }

    /** Admin view of a specific merchant order — read-only, with access audit. */
    public function showOrder(Request $request, Order $order)
    {
        AdminAuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'order_viewed',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'meta' => ['order_no' => $order->order_no, 'merchant_id' => $order->user_id],
            'ip' => $request->ip(),
        ]);

        return view('orders.show', [
            'order' => $order->load('paymentAccount', 'webhookLogs', 'user'),
            'readOnly' => true,
        ]);
    }

    private function audit(Request $request, User $merchant, string $action, array $meta): void
    {
        AdminAuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => $merchant->id,
            'meta' => $meta,
            'ip' => $request->ip(),
        ]);
    }
}
