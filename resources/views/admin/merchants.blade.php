@extends('layouts.app')
@section('title', 'Account requests & billing')
@section('subtitle', 'Review merchant requests, record manual payment, and manage subscription access.')

@section('content')
<div class="mb-5 grid gap-3 sm:grid-cols-3">
    @foreach ([
        ['Account requests', $merchants->getCollection()->where('subscription_status', \App\Models\User::SUBSCRIPTION_PENDING)->count()],
        ['Awaiting payment', $merchants->getCollection()->where('subscription_status', \App\Models\User::SUBSCRIPTION_PAYMENT_DUE)->count()],
        ['On this page', $merchants->count()],
    ] as [$label, $count])
        <div class="card p-4"><div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</div><div class="mt-1 text-2xl font-bold text-slate-900">{{ $count }}</div></div>
    @endforeach
</div>

<div class="mb-5 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-950">
    <strong>Manual collections only.</strong> Contact the merchant and verify the payment outside this app. Use “Confirm payment & start plan” only after money has been received. Access remains blocked until that confirmation.
</div>

<details class="card mb-5 p-5" @if ($errors->addAccount->any()) open @endif>
    <summary class="cursor-pointer font-semibold text-slate-900">Add account <span class="font-normal text-slate-500">· lifetime access, no subscription needed</span></summary>
    <form method="POST" action="{{ route('admin.merchants.store') }}" class="mt-4">
        @csrf
        @if ($errors->addAccount->any())
            <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                @foreach ($errors->addAccount->all() as $message)<div>{{ $message }}</div>@endforeach
            </div>
        @endif
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['name', 'Owner name', 'text', true, 'off'],
                ['business_name', 'Business name', 'text', true, 'off'],
                ['email', 'Email', 'email', true, 'off'],
                ['phone', 'Mobile number (optional)', 'text', false, 'off'],
                ['password', 'Password (min 10 characters)', 'password', true, 'new-password'],
                ['password_confirmation', 'Confirm password', 'password', true, 'new-password'],
            ] as [$field, $label, $type, $required, $autocomplete])
                <div>
                    <label for="add-{{ $field }}" class="label">{{ $label }}</label>
                    <input id="add-{{ $field }}" name="{{ $field }}" type="{{ $type }}" class="input py-2.5" autocomplete="{{ $autocomplete }}"
                        value="{{ $type === 'password' || ! $errors->addAccount->any() ? '' : old($field) }}" @required($required)>
                </div>
            @endforeach
        </div>
        <button class="btn btn-primary mt-4">Create lifetime account</button>
    </form>
</details>

<div class="card overflow-hidden">
    @if ($merchants->isEmpty())
        <x-empty icon="users" title="No merchants yet" text="Account requests appear here after signup." />
    @else
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100">
            <thead class="bg-slate-50/70">
                <tr>
                    <th class="th">Merchant</th>
                    <th class="th text-right">Orders</th>
                    <th class="th text-right">Collected</th>
                    <th class="th">Account / subscription</th>
                    <th class="th">Plan / expiry</th>
                    <th class="th">Joined</th>
                    <th class="th">Admin actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($merchants as $merchant)
                    @php
                        $status = $merchant->subscriptionHasExpired() ? \App\Models\User::SUBSCRIPTION_EXPIRED : $merchant->subscription_status;
                        $statusTone = match ($status) {
                            'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                            'pending_approval', 'payment_due' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
                            'expired', 'rejected' => 'bg-red-50 text-red-700 ring-red-600/20',
                            default => 'bg-slate-100 text-slate-700 ring-slate-500/20',
                        };
                        $planKey = $merchant->subscription_requested_plan ?: $merchant->subscription_plan;
                    @endphp
                    <tr class="align-top hover:bg-slate-50/80">
                        <td class="td min-w-56">
                            <div class="flex items-center gap-3">
                                <x-avatar :name="$merchant->displayName()" />
                                <div class="min-w-0">
                                    <div class="font-semibold text-slate-900">{{ $merchant->displayName() }}</div>
                                    <div class="text-xs text-slate-500 break-all">{{ $merchant->email }}{{ $merchant->phone ? ' · '.$merchant->phone : '' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="td text-right tabular-nums">{{ number_format($merchant->orders_count) }}</td>
                        <td class="td text-right font-semibold text-slate-900 tabular-nums">₹{{ number_format($merchant->paid_amount ?? 0, 2) }}</td>
                        <td class="td min-w-36">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone }}">{{ str($status)->replace('_', ' ')->title() }}</span>
                            @if (! $merchant->is_active)<div class="mt-1 text-xs font-semibold text-red-600">Admin-disabled</div>@endif
                        </td>
                        <td class="td min-w-32 text-sm text-slate-600">
                            <div>{{ \App\Support\SubscriptionPlans::label($planKey) }}</div>
                            @if ($planKey && \App\Support\SubscriptionPlans::amount($planKey))<div class="text-xs text-slate-500">₹{{ number_format(\App\Support\SubscriptionPlans::amount($planKey)) }}</div>@endif
                            @if ($merchant->subscription_expires_at)<div class="mt-1 text-xs">Ends {{ $merchant->subscription_expires_at->format('d M Y') }}</div>@endif
                            @if ($planKey === \App\Support\SubscriptionPlans::LIFETIME)<div class="mt-1 text-xs">Never expires</div>@endif
                        </td>
                        <td class="td whitespace-nowrap text-slate-500">{{ $merchant->created_at->format('d M Y') }}</td>
                        <td class="td min-w-64 space-y-2">
                            @if ($status === \App\Models\User::SUBSCRIPTION_PENDING)
                                <form method="POST" action="{{ route('admin.merchants.approve', $merchant) }}" class="space-y-2">
                                    @csrf
                                    <label class="sr-only" for="plan-{{ $merchant->id }}">Plan for {{ $merchant->displayName() }}</label>
                                    <select id="plan-{{ $merchant->id }}" name="plan" class="input py-2 text-xs" required>
                                        @foreach ($plans as $key => $plan)
                                            <option value="{{ $key }}" @selected(($planKey ?: 'monthly') === $key)>{{ $plan['label'] }} — ₹{{ number_format($plan['amount']) }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-primary w-full" data-confirm="Approve this account request? The merchant still cannot use the app until payment is recorded.">Approve request</button>
                                </form>
                                <form method="POST" action="{{ route('admin.merchants.reject', $merchant) }}">@csrf
                                    <button class="btn btn-sm btn-danger w-full" data-confirm="Reject this merchant account request?">Reject request</button>
                                </form>
                            @elseif ($status === \App\Models\User::SUBSCRIPTION_PAYMENT_DUE)
                                <form method="POST" action="{{ route('admin.merchants.activate', $merchant) }}" class="space-y-2">
                                    @csrf
                                    <input name="payment_reference" class="input py-2 text-xs" maxlength="120" placeholder="Payment reference (optional)">
                                    <button class="btn btn-sm btn-primary w-full" data-confirm="Confirm you have received ₹{{ number_format(\App\Support\SubscriptionPlans::amount($planKey) ?? 0) }} and start {{ \App\Support\SubscriptionPlans::label($planKey) }} access?">Confirm payment & start plan</button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('admin.merchants.toggle', $merchant) }}">@csrf
                                <button data-confirm="{{ $merchant->is_active ? 'Disable' : 'Enable' }} {{ $merchant->displayName() }}?" class="btn btn-sm {{ $merchant->is_active ? 'btn-secondary' : 'btn-danger' }} w-full">
                                    {{ $merchant->is_active ? 'Disable account' : 'Enable account' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    @if ($merchants->hasPages())<div class="px-5 py-3 border-t border-slate-100">{{ $merchants->links() }}</div>@endif
</div>
@endsection
