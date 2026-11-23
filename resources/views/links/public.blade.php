@extends('layouts.base')
@section('title', $link->title)
@section('body')
<div class="min-h-full grid place-items-center px-4 py-10 bg-slate-50">
    <form method="POST" action="{{ route('link.pay', $link->code) }}" class="card w-full max-w-md p-7">
        @csrf
        <div class="flex items-center gap-3 mb-6">
            <x-avatar :name="$link->user->displayName()" />
            <div><div class="text-xs text-slate-500">Paying to</div><div class="font-semibold text-slate-900">{{ $link->user->displayName() }}</div></div>
        </div>
        <h1 class="text-xl font-bold text-slate-900">{{ $link->title }}</h1>
        @if ($link->isFixedAmount())
            <div class="text-4xl font-bold tracking-tight my-5">₹{{ number_format((float) $link->amount, 2) }}</div>
        @else
            <div class="my-5">
                <label for="p-amt" class="label">Amount (₹)</label>
                <input id="p-amt" type="number" step="0.01" min="1" max="100000" name="amount" value="{{ old('amount') }}" required autofocus class="input py-3 text-2xl font-semibold" placeholder="0.00">
                @error('amount')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        @endif
        <div class="grid sm:grid-cols-2 gap-x-4">
            <x-input name="customer_name" label="Your name" placeholder="Optional" />
            <x-input name="customer_mobile" label="Mobile" placeholder="Optional" />
        </div>
        @if ($errors->any() && ! $errors->has('amount'))<p class="text-sm text-red-600 mb-3">{{ $errors->first() }}</p>@endif
        <button class="btn btn-primary w-full py-3">Pay with UPI</button>
        <div class="mt-4 flex justify-center"><x-logo compact dark /></div>
    </form>
</div>
@endsection
