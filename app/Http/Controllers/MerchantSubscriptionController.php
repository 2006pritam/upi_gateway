<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PlanPayment;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanBilling;
use App\Support\SubscriptionPlans;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MerchantSubscriptionController extends Controller
{
    public function show(Request $request, PlanBilling $billing)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return redirect()->route('dashboard');
        }

        if ($user->subscriptionHasExpired()) {
            $user->forceFill(['subscription_status' => User::SUBSCRIPTION_EXPIRED])->save();
        }

        $renewing = $billing->isRenewal($user);
        $payable = $renewing || $user->subscription_status === User::SUBSCRIPTION_PAYMENT_DUE;

        return view('subscription.show', [
            'plans' => SubscriptionPlans::availableTo($user),
            'receiver' => $billing->receiver(),
            'openPayment' => $payable ? $billing->openPayment($user) : null,
            'renewing' => $renewing,
            'payPlan' => $payable ? ($renewing ? $billing->renewalPlan($user) : $user->subscription_requested_plan) : null,
            'contactEmail' => config('billing.admin_contact_email'),
        ]);
    }

    /** Send the merchant to checkout on the admin's gateway for an approved or renewing plan. */
    public function pay(Request $request, PlanBilling $billing, OrderService $orders)
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->isAdmin(), 404);

        $data = $request->validate([
            'plan' => ['nullable', 'string', Rule::in(array_keys(SubscriptionPlans::availableTo($user)))],
        ], ['plan.in' => 'That plan is not available for this account. The trial is only for new accounts.']);

        // An earlier checkout that ran out of time gets one last check before a new one is made.
        $stale = PlanPayment::where('user_id', $user->id)->whereNull('applied_at')
            ->whereHas('order', fn ($q) => $q->where('status', Order::PENDING)->where('expires_at', '<=', now()))
            ->with('order.paymentAccount')->latest('id')->first();

        if ($stale) {
            $orders->check($stale->order, force: true);

            if ($user->refresh()->hasSubscriptionAccess()) {
                return redirect()->route('subscription.show')->with('status', 'Payment received. Your plan is active.');
            }
        }

        try {
            $order = $billing->checkout($user, $request->ip(), $data['plan'] ?? null);
        } catch (ValidationException $e) {
            return redirect()->route('subscription.show')->withErrors(['plan' => collect($e->errors())->flatten()->first()]);
        }

        return redirect()->route('pay.show', $order);
    }

    public function requestPlan(Request $request, PlanBilling $billing)
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->isAdmin(), 404);

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(SubscriptionPlans::availableTo($user)))],
        ], ['plan.in' => 'That plan is not available for this account. The trial is only for new accounts.']);

        if ($user->subscription_status === User::SUBSCRIPTION_PENDING) {
            return back()->withErrors(['plan' => 'Your account request is already waiting for admin review.']);
        }

        if ($user->subscription_status === User::SUBSCRIPTION_ACTIVE && $user->hasSubscriptionAccess()) {
            return back()->withErrors(['plan' => 'Your current subscription is still active.']);
        }

        if ($user->subscription_status === User::SUBSCRIPTION_PAYMENT_DUE) {
            return back()->withErrors(['plan' => 'Your approved plan is locked. Contact the admin if you need it changed.']);
        }

        $status = $user->subscription_status === User::SUBSCRIPTION_REJECTED
            ? User::SUBSCRIPTION_PENDING
            : User::SUBSCRIPTION_PAYMENT_DUE;

        $user->forceFill([
            'subscription_status' => $status,
            'subscription_requested_plan' => $data['plan'],
            'subscription_approved_at' => $status === User::SUBSCRIPTION_PENDING ? null : $user->subscription_approved_at,
        ])->save();

        $message = $status === User::SUBSCRIPTION_PENDING
            ? 'Your account request has been sent to the admin for review.'
            : ($billing->receiver()
                ? 'Your plan request is saved. Pay below; access starts once the payment is verified.'
                : 'Your plan request is saved. Contact the admin to arrange payment; access starts only after the admin confirms payment.');

        return redirect()->route('subscription.show')->with('status', $message);
    }
}
