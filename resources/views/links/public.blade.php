@extends('layouts.base')
@section('title', $link->title)

@php
    $merchant = $link->user->displayName();
    $color = $link->themeColor();
    $fixed = $link->isFixedAmount() ? number_format((float) $link->amount, 2) : null;
@endphp

@push('head')
<meta name="robots" content="noindex">
@endpush

@section('body')
<div class="min-h-full flex flex-col bg-slate-100">
    <div class="h-1.5" style="background-color: {{ $color }}"></div>

    <main class="flex-1 flex items-start justify-center p-4 sm:p-8">
        <div class="w-full max-w-4xl grid md:grid-cols-2 rounded-2xl bg-white shadow-pop ring-1 ring-slate-200 overflow-hidden">
            <section class="p-6 sm:p-8 md:border-r border-b md:border-b-0 border-slate-200">
                <div class="flex items-center gap-3">
                    <div class="grid place-items-center w-11 h-11 shrink-0 rounded-lg text-lg font-bold text-white" style="background-color: {{ $color }}">{{ mb_strtoupper(mb_substr($merchant, 0, 1)) }}</div>
                    <div class="min-w-0">
                        <div class="text-xs text-slate-500">Paying to</div>
                        <div class="font-semibold text-slate-900 truncate">{{ $merchant }}</div>
                    </div>
                </div>
                <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900 break-words">{{ $link->title }}</h1>
                <div class="mt-3 h-1 w-10 rounded-full" style="background-color: {{ $color }}"></div>
                @if ($link->description)
                    <p class="mt-4 text-sm leading-6 text-slate-600 whitespace-pre-line break-words">{{ $link->description }}</p>
                @endif
                <div class="mt-8 flex items-center gap-2 text-xs text-slate-500"><x-icon name="shield" class="w-4 h-4" /> Pay with any UPI app</div>
            </section>

            <form method="POST" action="{{ route('link.pay', $link->code) }}" class="p-6 sm:p-8">
                @csrf
                @if (is_string($embedOrigin ?? null))<input type="hidden" name="embed_origin" value="{{ old('embed_origin', $embedOrigin) }}">@endif
                <h2 class="text-base font-semibold text-slate-900 mb-5">Payment details</h2>
                @if ($errors->any() && ! $errors->hasAny(['amount', 'customer_name', 'customer_email', 'customer_mobile']))
                    <p role="alert" class="mb-4 rounded-lg bg-red-50 ring-1 ring-red-200 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</p>
                @endif

                @if ($fixed)
                    <div class="mb-5 flex items-center justify-between rounded-lg bg-slate-50 ring-1 ring-slate-200 px-4 py-3">
                        <span class="text-sm text-slate-600">Amount</span>
                        <span class="text-xl font-bold text-slate-900 tabular-nums">₹{{ $fixed }}</span>
                    </div>
                    @error('amount')<p class="-mt-3 mb-4 text-sm text-red-600">{{ $message }}</p>@enderror
                @else
                    <x-input name="amount" label="Amount (₹)" type="number" step="0.01" min="1" max="100000" inputmode="decimal" required placeholder="0.00" />
                @endif

                <x-input name="customer_name" label="Your name" maxlength="100" autocomplete="name" placeholder="Optional" />
                <x-input name="customer_email" label="Email" type="email" autocomplete="email" placeholder="Optional" />
                <x-input name="customer_mobile" label="Mobile" type="tel" maxlength="20" autocomplete="tel" placeholder="Optional" />

                <button class="btn btn-lg w-full text-white shadow-sm hover:brightness-110" style="background-color: {{ $color }}">
                    <span id="pay-label" data-label="{{ $link->buttonLabel() }}">{{ $link->buttonLabel() }}{{ $fixed ? ' · ₹'.$fixed : '' }}</span>
                    <x-icon name="arrow-right" class="w-4 h-4" />
                </button>
            </form>
        </div>
    </main>

    <footer class="pb-24 flex flex-col items-center gap-2 text-xs text-slate-400">
        <x-logo compact dark />
        <div>Never share your UPI PIN with anyone.</div>
    </footer>
</div>
@endsection

@unless ($fixed)
@push('scripts')
<script nonce="{{ $csp_nonce }}">
(() => {
    const input = document.getElementById('f-amount');
    const label = document.getElementById('pay-label');
    const update = () => {
        const value = Number.parseFloat(input.value);
        label.textContent = label.dataset.label + (value > 0
            ? ' · ₹' + value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : '');
    };
    input.addEventListener('input', update);
    update();
})();
</script>
@endpush
@endunless
