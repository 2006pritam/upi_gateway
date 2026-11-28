@extends('layouts.base')

@section('title', 'Subscription & account access · '.config('app.name'))

@section('body')
@php
    $user = auth()->user();
    $due = $user->subscription_status === \App\Models\User::SUBSCRIPTION_PAYMENT_DUE;
    $canPayOnline = $receiver !== null;
    $payAmount = \App\Support\SubscriptionPlans::amount((string) $payPlan);
    $showPay = $canPayOnline && $payAmount;
@endphp
<div class="min-h-full bg-slate-50 px-4 py-10 sm:px-6">
    <div class="mx-auto max-w-2xl">
        <div class="mb-8 flex items-center justify-between gap-4">
            <a href="{{ route('home') }}"><x-logo /></a>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button class="btn btn-secondary">Log out</button>
            </form>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-6 sm:p-8">
                <p class="text-sm font-semibold uppercase tracking-wider text-brand-600">Merchant subscription</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                    @switch(auth()->user()->subscription_status)
                        @case(\App\Models\User::SUBSCRIPTION_PENDING) Account request received @break
                        @case(\App\Models\User::SUBSCRIPTION_PAYMENT_DUE) Admin approval received @break
                        @case(\App\Models\User::SUBSCRIPTION_EXPIRED) Subscription expired @break
                        @case(\App\Models\User::SUBSCRIPTION_REJECTED) Account request needs review @break
                        @default Subscription status
                    @endswitch
                </h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    @switch(auth()->user()->subscription_status)
                        @case(\App\Models\User::SUBSCRIPTION_PENDING)
                            Your account is waiting for admin approval. Payment and subscription time do not start until the admin has approved the account and confirmed payment.
                            @break
                        @case(\App\Models\User::SUBSCRIPTION_PAYMENT_DUE)
                            @if ($canPayOnline)
                                Your account or renewal request has been approved. Pay the plan fee below; access and the subscription term start once the payment is verified.
                            @else
                                Your account or renewal request has been approved. Arrange payment with the admin; access and the subscription term start only after payment is received and confirmed in the admin dashboard.
                            @endif
                            @break
                        @case(\App\Models\User::SUBSCRIPTION_EXPIRED)
                            @if ($canPayOnline)
                                Your plan has ended. Pay the plan fee below to renew; dashboard and API access return once the payment is verified.
                            @else
                                Your paid term has ended. Dashboard, merchant actions, and API access are paused until you request a plan and its payment is confirmed.
                            @endif
                            @break
                        @case(\App\Models\User::SUBSCRIPTION_REJECTED)
                            The admin did not approve this account request. Contact the admin or submit a new request for review.
                            @break
                        @default
                            This page shows your account and subscription status.
                    @endswitch
                </p>
            </div>

            @if (session('status'))
                <div class="mx-6 mt-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 sm:mx-8">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="mx-6 mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 sm:mx-8">{{ $errors->first() }}</div>
            @endif

            <div class="p-6 sm:p-8">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl bg-slate-50 p-4">
                        <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Current status</div>
                        <div class="mt-1 font-semibold text-slate-900">{{ str($userStatus = auth()->user()->subscription_status)->replace('_', ' ')->title() }}</div>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Selected plan</div>
                        <div class="mt-1 font-semibold text-slate-900">
                            @php $planKey = $renewing ? $payPlan : (auth()->user()->subscription_status === \App\Models\User::SUBSCRIPTION_ACTIVE ? auth()->user()->subscription_plan : auth()->user()->subscription_requested_plan); @endphp
                            {{ \App\Support\SubscriptionPlans::label($planKey) }}
                            @if ($planKey && \App\Support\SubscriptionPlans::amount($planKey))
                                · ₹{{ number_format(\App\Support\SubscriptionPlans::amount($planKey)) }}
                            @endif
                        </div>
                    </div>
                    @if (auth()->user()->subscription_started_at)
                        <div class="rounded-xl bg-slate-50 p-4">
                            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Started</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ auth()->user()->subscription_started_at->format('d M Y') }}</div>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-4">
                            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Ends</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ auth()->user()->subscription_expires_at?->format('d M Y') ?? (auth()->user()->subscription_plan === \App\Support\SubscriptionPlans::LIFETIME ? 'Never' : 'No expiry recorded') }}</div>
                        </div>
                    @endif
                </div>

                @if ($showPay)
                    @php $reviewing = $openPayment?->order->status === \App\Models\Order::REVIEW; @endphp
                    <div class="mt-8 rounded-xl border-2 border-brand-600 p-5">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h2 class="font-semibold text-slate-900">{{ $renewing ? 'Renew online by UPI' : 'Pay online by UPI' }}</h2>
                                <p class="mt-1 text-sm text-slate-600">{{ \App\Support\SubscriptionPlans::label($payPlan) }} plan · paid to {{ $receiver->displayName() }}</p>
                            </div>
                            <div class="text-2xl font-bold tabular-nums text-slate-900">₹{{ number_format($payAmount) }}</div>
                        </div>
                        @if ($reviewing)
                            <p class="mt-4 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-900">Your payment is being verified. Don't pay again.</p>
                            <a href="{{ route('pay.show', $openPayment->order) }}" class="btn btn-secondary mt-3 w-full justify-center">View payment status</a>
                        @else
                            <form method="POST" action="{{ route('subscription.pay') }}" class="mt-4">
                                @csrf
                                <button name="plan" value="{{ $payPlan }}" class="btn btn-primary btn-lg w-full justify-center">
                                    <x-icon name="lock" class="h-5 w-5" /> {{ $openPayment?->plan === $payPlan ? 'Continue payment' : 'Pay ₹'.number_format($payAmount).' now' }}
                                </button>
                                <p class="mt-3 text-xs text-slate-500">Your plan starts automatically once the payment is verified. Pay the exact amount shown on the payment screen.</p>
                                @if ($renewing)
                                    <p class="mt-4 text-sm font-medium text-slate-700">Or renew with a different plan</p>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @foreach ($plans as $key => $plan)
                                            @continue($key === $payPlan)
                                            <button name="plan" value="{{ $key }}" class="btn btn-secondary justify-center">{{ $plan['label'] }} — ₹{{ number_format($plan['amount']) }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </form>
                        @endif
                    </div>
                @endif

                {{-- With online renewal on offer, the admin-handled request is the fallback and stays out of the way. --}}
                @if ($renewing && $showPay && ! ($openPayment?->order->status === \App\Models\Order::REVIEW))
                    <details class="mt-4 rounded-xl border border-slate-200 p-5">
                        <summary class="cursor-pointer text-sm font-semibold text-slate-900">Paying the admin another way?</summary>
                        <p class="mt-2 text-sm text-slate-600">Send a renewal request, then arrange the payment with the admin.</p>
                        <form method="POST" action="{{ route('subscription.request') }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                            @csrf
                            <label class="sr-only" for="plan">Subscription plan</label>
                            <select id="plan" name="plan" class="input py-2.5" required>
                                @foreach ($plans as $key => $plan)
                                    <option value="{{ $key }}" @selected($payPlan === $key)>{{ $plan['label'] }} — ₹{{ number_format($plan['amount']) }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-secondary shrink-0">Request renewal</button>
                        </form>
                    </details>
                @elseif (! $showPay && ($renewing || auth()->user()->subscription_status === \App\Models\User::SUBSCRIPTION_REJECTED))
                    <div class="mt-8 rounded-xl border border-slate-200 p-5">
                        <h2 class="font-semibold text-slate-900">
                            {{ auth()->user()->subscription_status === \App\Models\User::SUBSCRIPTION_REJECTED ? 'Request account review again' : 'Choose a renewal plan' }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            @if ($canPayOnline)
                                After the admin approves your request, you can pay online here.
                            @else
                                Payment is arranged directly with the admin.
                            @endif
                        </p>
                        <form method="POST" action="{{ route('subscription.request') }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                            @csrf
                            <label class="sr-only" for="plan">Subscription plan</label>
                            <select id="plan" name="plan" class="input py-2.5" required>
                                @foreach ($plans as $key => $plan)
                                    <option value="{{ $key }}" @selected(old('plan', auth()->user()->subscription_requested_plan ?: 'monthly') === $key)>
                                        {{ $plan['label'] }} — ₹{{ number_format($plan['amount']) }}
                                    </option>
                                @endforeach
                            </select>
                            <button class="btn btn-primary shrink-0">
                                {{ auth()->user()->subscription_status === \App\Models\User::SUBSCRIPTION_REJECTED ? 'Send review request' : 'Request plan / renewal' }}
                            </button>
                        </form>
                    </div>
                @endif

                @if (! (auth()->user()->subscription_plan === \App\Support\SubscriptionPlans::LIFETIME && auth()->user()->hasSubscriptionAccess()))
                <div class="mt-6 rounded-xl bg-indigo-50 p-5 text-sm text-indigo-950">
                    @if ($canPayOnline)
                        <h2 class="font-semibold">Need help with a payment?</h2>
                        <p class="mt-1 leading-6">If money was debited but your plan has not started, don't pay again: send the admin your UTR number. You can also ask the admin about another way to pay or a different plan.</p>
                    @else
                        <h2 class="font-semibold">Manual payment with the admin</h2>
                        <p class="mt-1 leading-6">Choose a plan above when available, then contact the admin to arrange payment. Once payment has been received, the admin records it and your plan starts. If your subscription expires before renewal is confirmed, app and API access remain blocked.</p>
                    @endif
                    <a class="mt-3 inline-flex font-semibold text-indigo-700 underline underline-offset-2" href="mailto:{{ $contactEmail }}?subject={{ rawurlencode(config('app.name').' account or subscription') }}">Contact {{ $contactEmail }}</a>
                </div>
                @endif

                @if (auth()->user()->hasSubscriptionAccess())
                    <a href="{{ route('dashboard') }}" class="btn btn-primary mt-6 w-full justify-center">Return to dashboard</a>
                @endif
            </div>
        </div>
        <p class="mt-5 text-center text-xs text-slate-400">Share payment references only with the administrator. Never share your UPI PIN or OTP.</p>
    </div>
</div>
@endsection
