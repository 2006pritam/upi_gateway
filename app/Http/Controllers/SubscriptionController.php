<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SubscriptionPlans;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return redirect()->route('dashboard');
        }

        if ($user->subscriptionHasExpired()) {
            $user->forceFill(['subscription_status' => User::SUBSCRIPTION_EXPIRED])->save();
        }

        return view('subscription.show', [
            'plans' => SubscriptionPlans::PLANS,
            'contactEmail' => config('billing.admin_contact_email'),
        ]);
    }

    public function requestPlan(Request $request)
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(SubscriptionPlans::PLANS))],
        ]);

        /** @var User $user */
        $user = $request->user();
        abort_if($user->isAdmin(), 404);

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
            : 'Your plan request is saved. Contact the admin to arrange payment; access starts only after the admin confirms payment.';

        return redirect()->route('subscription.show')->with('status', $message);
    }
}
