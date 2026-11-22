@extends('layouts.app')
@section('title', 'Subscriptions')
@section('subtitle', 'Recurring payments: each cycle a pay link is created and e-mailed to your customer.')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
    <form method="POST" action="{{ route('subscriptions.store') }}" class="card p-6 lg:col-span-2 h-fit">
        @csrf
        <h2 class="text-base font-semibold text-slate-900 mb-4">New subscription</h2>
        <x-input name="plan_name" label="Plan" placeholder="e.g. Monthly tuition" required maxlength="100" />
        <div class="grid grid-cols-2 gap-3">
            <x-input name="amount" label="Amount (₹)" type="number" step="0.01" min="1" required />
            <div class="mb-5"><label class="label">Repeats</label>
                <select name="interval" class="input">@foreach (\App\Models\Subscription::INTERVALS as $k => $v)<option value="{{ $k }}" @selected(old('interval', 'monthly') === $k)>{{ $v }}</option>@endforeach</select></div>
        </div>
        <x-input name="customer_name" label="Customer name" required />
        <x-input name="customer_email" label="Customer e-mail" type="email" help="The pay link is e-mailed here each cycle." />
        <x-input name="customer_mobile" label="Mobile" />
        <x-input name="first_due" label="First invoice date" type="date" :value="now()->format('Y-m-d')" required />
        <div class="mb-5"><label class="label">Receive in</label>
            <select name="payment_account_id" class="input"><option value="">Automatic</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->label }}</option>@endforeach</select></div>
        <button class="btn btn-primary w-full"><x-icon name="plus" class="w-4 h-4" /> Add subscription</button>
    </form>

    <div class="lg:col-span-3 space-y-3">
        @error('action')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @forelse ($subs as $s)
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-900">{{ $s->customer_name }} · {{ $s->plan_name }}</div>
                        <div class="text-sm text-slate-500">₹{{ number_format((float) $s->amount, 2) }} · {{ strtolower(\App\Models\Subscription::INTERVALS[$s->interval]) }}@if ($s->status === 'active') · next invoice {{ $s->next_due_at->format('d M Y') }}@endif</div>
                        @if ($s->lastOrder)<div class="text-xs text-slate-400 mt-1">Last invoice: <a class="text-brand-600" href="{{ route('orders.show', $s->lastOrder) }}">{{ $s->lastOrder->status }}</a></div>@endif
                    </div>
                    <span @class(['badge', 'bg-emerald-50 text-emerald-700' => $s->status === 'active', 'bg-amber-50 text-amber-700' => $s->status === 'paused', 'bg-slate-100 text-slate-500' => $s->status === 'cancelled'])>{{ ucfirst($s->status) }}</span>
                </div>
                @if ($s->status !== 'cancelled')
                <div class="flex flex-wrap gap-2 mt-3">
                    @if ($s->status === 'active')
                        <form method="POST" action="{{ route('subscriptions.bill', $s) }}">@csrf<button class="btn btn-secondary btn-sm">Invoice now</button></form>
                        <form method="POST" action="{{ route('subscriptions.update', $s) }}">@csrf<input type="hidden" name="action" value="pause"><button class="btn btn-secondary btn-sm">Pause</button></form>
                    @else
                        <form method="POST" action="{{ route('subscriptions.update', $s) }}">@csrf<input type="hidden" name="action" value="resume"><button class="btn btn-secondary btn-sm">Resume</button></form>
                    @endif
                    <form method="POST" action="{{ route('subscriptions.update', $s) }}">@csrf<input type="hidden" name="action" value="cancel"><button data-confirm="Cancel this subscription for good?" class="btn btn-danger btn-sm">Cancel</button></form>
                </div>
                @endif
            </div>
        @empty
            <div class="card"><x-empty icon="refresh" title="No subscriptions yet" text="Add a customer and a plan; invoices are created and e-mailed automatically." /></div>
        @endforelse
    </div>
</div>
@endsection
