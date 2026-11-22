<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        return view('subscriptions.index', [
            'subs' => $request->user()->subscriptions()->with('lastOrder:id,order_no,status')->orderByRaw("status = 'cancelled'")->orderBy('next_due_at')->get(),
            'accounts' => $request->user()->paymentAccounts()->where('is_active', true)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_name' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1', 'max:100000', 'decimal:0,2'],
            'interval' => ['required', Rule::in(array_keys(Subscription::INTERVALS))],
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'first_due' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'payment_account_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['payment_account_id']) && ! $request->user()->paymentAccounts()->whereKey($data['payment_account_id'])->exists()) {
            return back()->withErrors(['payment_account_id' => 'This UPI account does not exist.'])->withInput();
        }

        $request->user()->subscriptions()->create(['next_due_at' => $data['first_due']] + collect($data)->except('first_due')->all());

        return back()->with('status', 'Subscription added. The first invoice is sent on the due date.');
    }

    public function update(Request $request, Subscription $subscription)
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
        $action = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'cancel'])]])['action'];

        if ($subscription->status === 'cancelled') {
            return back()->withErrors(['action' => 'A cancelled subscription cannot be changed.']);
        }

        $subscription->update(['status' => ['pause' => 'paused', 'resume' => 'active', 'cancel' => 'cancelled'][$action]]);

        return back()->with('status', 'Subscription '.$subscription->status.'.');
    }

    public function billNow(Request $request, Subscription $subscription, SubscriptionService $service)
    {
        abort_unless($subscription->user_id === $request->user()->id, 404);
        abort_unless($subscription->status === 'active', 422);

        $order = $service->bill($subscription->load('user'));

        return $order
            ? redirect()->route('orders.show', $order)->with('status', 'Invoice created and sent.')
            : back()->withErrors(['action' => 'Could not create the invoice (already billed for this date, or no active UPI account).']);
    }
}
