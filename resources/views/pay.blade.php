@extends('layouts.base')
@section('title', 'Pay ₹'.number_format((float) $order->payableAmount(), 2).' to '.$order->user->displayName())

@php
    $merchant = $order->user->displayName();
    $amount = number_format((float) $order->payableAmount(), 2);
    $totalSeconds = max(1, $order->created_at->diffInSeconds($order->expires_at ?? now()->addMinutes(config('gateway.order_expiry_minutes', 15))));
    $apps = [
        ['GPay',    'tez://upi/pay',   'text-[#1a73e8]', 'images/upi/gpay.png'],
        ['PhonePe', 'phonepe://pay',   'text-[#5f259f]', 'images/upi/phonepe.png'],
        ['Paytm',   'paytmmp://pay',   'text-[#00baf2]', 'images/upi/paytm.png'],
        ['BHIM',    'upi://pay',       'text-[#f47920]', 'images/upi/bhim.png'],
    ];
    // More apps that open directly on Android phones: [name, Android package, official app icon]
    $androidApps = [
        ['FamApp / FamX', 'com.fampay.in',            'images/upi/famapp.png'],
        ['Navi',          'com.naviapp',              'images/upi/navi.jpg'],
        ['super.money',   'money.super.payments',     'images/upi/super-money.jpg'],
    ];
    $isAndroid = stripos((string) request()->userAgent(), 'android') !== false;
@endphp

@push('head')
<meta name="robots" content="noindex">
<script nonce="{{ $csp_nonce }}">
try {
    if (localStorage.getItem('upi_checkout_theme') === 'dark') {
        document.documentElement.classList.add('upi-theme-dark');
    }
} catch (e) {}
</script>
<link rel="stylesheet" href="{{ asset('css/pay.css') }}">
@endpush

@section('body')
<div class="payment-canvas min-h-full flex flex-col">
    <div class="flex-1 flex items-center justify-center p-4 sm:p-8">
        <div class="w-full max-w-3xl bg-white rounded-3xl shadow-pop ring-1 ring-white/80 overflow-hidden grid md:grid-cols-5 checkout-card">

            {{-- Summary --}}
            <div class="md:col-span-2 relative bg-slate-950 text-white p-6 sm:p-8 flex flex-col overflow-hidden">
                <div class="hero-gradient absolute inset-0 opacity-50"></div>
                <div class="relative flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <x-avatar :name="$merchant" size="w-11 h-11 text-base" class="!bg-white/10 !text-white ring-1 ring-white/20" />
                        <div class="min-w-0">
                            <div class="text-xs text-slate-400">Paying to</div>
                            <div class="font-semibold truncate">{{ $merchant }}</div>
                        </div>
                    </div>
                    <button id="theme-toggle" type="button" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-white/15 bg-white/10 px-2.5 py-2 text-xs font-semibold text-white transition hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950" aria-label="Switch to dark mode" aria-pressed="false" title="Switch to dark mode">
                        <svg id="theme-icon-moon" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.2 15.2A8.5 8.5 0 0 1 8.8 3.8 8.5 8.5 0 1 0 20.2 15.2Z"/></svg>
                        <svg id="theme-icon-sun" class="hidden h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                        <span id="theme-toggle-label">Dark</span>
                    </button>
                </div>

                <div class="relative mt-8">
                    <div class="text-xs uppercase tracking-wider text-slate-400">Amount</div>
                    <div class="mt-1 text-4xl font-bold tracking-tight tabular-nums">₹{{ $amount }}</div>
                    @if ($order->hasAdjustedAmount())
                        <div class="mt-1 text-xs opacity-80">Includes ₹{{ number_format((float) $order->payableAmount() - (float) $order->amount, 2) }} so we can identify your payment. Please pay exactly ₹{{ $amount }}.</div>
                    @endif
                    @if ($order->note)<div class="mt-2 text-sm text-slate-300">{{ $order->note }}</div>@endif
                </div>

                <dl class="relative mt-8 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-slate-400 whitespace-nowrap">Order ID</dt><dd class="font-mono text-xs text-slate-200 truncate">{{ $order->order_no }}</dd></div>
                    @if ($order->customer_name)
                        <div class="flex justify-between gap-4"><dt class="text-slate-400 whitespace-nowrap">Customer</dt><dd class="text-slate-200 truncate">{{ $order->customer_name }}</dd></div>
                    @endif
                    @if ($order->paymentAccount->upi_id)
                        <div class="flex justify-between gap-4"><dt class="text-slate-400 whitespace-nowrap">UPI ID</dt><dd class="font-mono text-xs text-slate-200 truncate">{{ $order->paymentAccount->upi_id }}</dd></div>
                    @endif
                </dl>

                @if ($order->isPending())
                    <div class="relative mt-8 md:mt-auto pt-8 flex items-center gap-4">
                        <div class="relative w-14 h-14">
                            <svg viewBox="0 0 36 36" class="w-14 h-14 -rotate-90">
                                <circle cx="18" cy="18" r="16" fill="none" stroke="rgb(255 255 255 / .12)" stroke-width="3" />
                                <circle id="ring" cx="18" cy="18" r="16" fill="none" stroke="#818cf8" stroke-width="3" stroke-linecap="round" stroke-dasharray="100.5" stroke-dashoffset="0" class="ring-transition" />
                            </svg>
                            <div id="timer" class="absolute inset-0 grid place-items-center font-mono text-xs font-medium">--:--</div>
                        </div>
                        <div class="text-sm">
                            <div class="font-medium">Complete payment in time</div>
                            <div class="text-xs text-slate-400">This QR code expires automatically.</div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Action area --}}
            <div class="md:col-span-3 p-6 sm:p-8 flex flex-col">
                @if ($order->status === 'paid')
                    <div class="flex-1 flex flex-col items-center justify-center text-center py-8">
                        <div class="pop grid place-items-center w-20 h-20 rounded-full bg-emerald-500 shadow-lg shadow-emerald-500/30">
                            <svg viewBox="0 0 24 24" class="w-10 h-10" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path class="draw" d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                        </div>
                        <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900">Payment successful</h1>
                        <p class="mt-2 text-slate-500">₹{{ $amount }} paid to {{ $merchant }}</p>
                        <dl class="mt-8 w-full max-w-xs rounded-xl bg-slate-50 ring-1 ring-slate-200 divide-y divide-slate-200 text-sm text-left">
                            @if ($order->utr)<div class="flex justify-between px-4 py-3"><dt class="text-slate-500">UTR</dt><dd class="font-mono font-medium text-slate-900">{{ $order->utr }}</dd></div>@endif
                            <div class="flex justify-between px-4 py-3"><dt class="text-slate-500">Paid at</dt><dd class="font-medium text-slate-900">{{ $order->paid_at?->format('d M Y, h:i A') }}</dd></div>
                        </dl>
                        <a href="{{ route('pay.receipt', $order) }}" target="_blank" class="mt-6 btn btn-secondary btn-sm"><x-icon name="receipt" class="w-4 h-4" /> Download receipt</a>
                        <p class="mt-4 text-xs text-slate-400">You can safely close this page.</p>
                        <button id="play-success-sound" type="button" class="mt-3 hidden rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">Tap to hear payment confirmation</button>
                    </div>
                @elseif ($order->status === 'review')
                    <div class="flex-1 flex flex-col items-center justify-center text-center py-8">
                        <div class="relative grid place-items-center w-20 h-20 rounded-full bg-sky-50 text-sky-600">
                            <svg class="absolute inset-0 w-20 h-20 animate-spin [animation-duration:2.5s]" viewBox="0 0 80 80" fill="none"><circle cx="40" cy="40" r="38" stroke="currentColor" stroke-opacity=".15" stroke-width="3"/><path d="M78 40A38 38 0 0 0 40 2" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <x-icon name="shield" class="w-9 h-9" />
                        </div>
                        <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900">Verifying your payment</h1>
                        <p class="mt-2 text-sm text-slate-500 max-w-sm">{{ $merchant }} is confirming your UTR with their bank. This page updates automatically once it's approved.</p>
                        <dl class="mt-8 w-full max-w-xs rounded-xl bg-slate-50 ring-1 ring-slate-200 divide-y divide-slate-200 text-sm text-left">
                            <div class="flex justify-between px-4 py-3"><dt class="text-slate-500">Your UTR</dt><dd class="font-mono font-medium text-slate-900">{{ $order->customer_utr }}</dd></div>
                            <div class="flex justify-between px-4 py-3"><dt class="text-slate-500">Submitted</dt><dd class="font-medium text-slate-900">{{ $order->utr_submitted_at?->format('d M, h:i A') }}</dd></div>
                        </dl>
                        <p class="mt-6 text-xs text-slate-400">Don't pay again. You can close this page; the merchant will be notified once it's confirmed.</p>
                        <button id="play-success-sound" type="button" class="mt-3 hidden rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50">Tap to hear payment confirmation</button>
                    </div>
                @elseif ($order->status === 'expired')
                    <div class="flex-1 flex flex-col items-center justify-center text-center py-8">
                        <div class="grid place-items-center w-20 h-20 rounded-full bg-slate-100 text-slate-400"><x-icon name="clock" class="w-10 h-10" /></div>
                        <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900">Payment link expired</h1>
                        @if ($order->acceptsCustomerUtr())
                            <p class="mt-2 text-sm text-slate-500 max-w-sm">If you already paid, don't pay again — submit your UTR below so {{ $merchant }} can confirm it.</p>
                            @include('pay._utr-form', ['class' => 'mt-8 w-full max-w-sm'])
                        @else
                            <p class="mt-2 text-sm text-slate-500 max-w-xs">Please ask {{ $merchant }} for a new payment link.</p>
                            <div class="mt-8 flex items-start gap-3 rounded-xl bg-amber-50 ring-1 ring-amber-200 px-4 py-3 text-left text-sm text-amber-800 max-w-sm">
                                <x-icon name="alert" class="w-5 h-5 text-amber-600" />
                                <div>If money was debited from your account, don't pay again. Share your UTR with the merchant to confirm the payment.</div>
                            </div>
                        @endif
                    </div>
                @elseif ($order->paymentAccount->isHosted())
                    @php $pgName = $order->paymentAccount->provider === 'phonepe_pg' ? 'PhonePe' : 'Paytm'; @endphp
                    <div class="flex-1 flex flex-col items-center justify-center text-center py-6">
                        <div class="grid place-items-center w-20 h-20 rounded-2xl {{ $pgName === 'PhonePe' ? 'bg-[#5f259f]' : 'bg-[#00baf2]' }} text-white shadow-lg">
                            <x-icon name="lock" class="w-9 h-9" />
                        </div>
                        <h1 class="mt-6 text-xl font-bold tracking-tight text-slate-900">Pay securely with {{ $pgName }}</h1>
                        <p class="mt-2 text-sm text-slate-500 max-w-sm">You'll complete the payment on {{ $pgName }}'s secure checkout using UPI, card or netbanking, then come back here automatically.</p>

                        @error('checkout')
                            <div class="mt-6 flex items-start gap-2 rounded-lg bg-red-50 ring-1 ring-red-200 px-3 py-2 text-left text-sm text-red-700 max-w-sm">
                                <x-icon name="alert" class="w-5 h-5" /><div>{{ $message }}</div>
                            </div>
                        @enderror

                        <form id="pg-start" method="POST" action="{{ route('pay.start', $order) }}" class="mt-8 w-full max-w-sm">
                            @csrf
                            <button class="btn btn-primary btn-lg w-full"><x-icon name="lock" class="w-5 h-5" /> Pay ₹{{ $amount }} with {{ $pgName }}</button>
                        </form>
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            @foreach ($apps as [$name, $scheme, $color, $logo])
                                <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-3 py-1 text-xs font-bold {{ $color }} flex items-center gap-1.5">
                                    <img src="{{ asset($logo) }}" class="w-4 h-4 rounded-sm" alt="{{ $name }}">{{ $name }}
                                </span>
                            @endforeach
                            <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">Cards</span>
                            <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">Netbanking</span>
                        </div>
                        @if ($order->provider_checkout)
                            <p class="mt-6 text-xs text-slate-500">Already paid? Stay on this page — we're checking with {{ $pgName }} automatically.</p>
                        @endif
                        <div class="mt-6 flex items-center justify-center gap-3">
                            <div class="relative w-10 h-10">
                                <svg viewBox="0 0 36 36" class="w-10 h-10 -rotate-90">
                                    <circle cx="18" cy="18" r="16" fill="none" stroke="rgb(0 0 0 / .08)" stroke-width="3" />
                                    <circle id="ring-pg" cx="18" cy="18" r="16" fill="none" stroke="#818cf8" stroke-width="3" stroke-linecap="round" stroke-dasharray="100.5" stroke-dashoffset="0" class="ring-transition" />
                                </svg>
                                <div id="timer-pg" class="absolute inset-0 grid place-items-center font-mono text-[10px] font-medium text-slate-700">--:--</div>
                            </div>
                            <div class="text-sm text-slate-500">Complete payment before time runs out</div>
                        </div>
                    </div>
                    <div class="mt-auto">
                        <div class="flex items-center justify-center gap-2.5 rounded-xl bg-slate-50 ring-1 ring-slate-200 px-4 py-3 text-sm text-slate-600">
                            <svg class="w-4 h-4 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <span id="status-text">Waiting for payment…</span>
                        </div>
                    </div>
                @else
                    <div class="mb-1 flex justify-center">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-[11px] font-semibold tracking-wide text-emerald-700 ring-1 ring-emerald-100"><x-icon name="lock" class="h-3.5 w-3.5" /> SECURE UPI CHECKOUT</span>
                    </div>
                    <div class="text-center">
                        <h1 class="text-lg font-semibold text-slate-900">Scan &amp; pay with any UPI app</h1>
                        <p class="text-sm text-slate-500 mt-1">Open your UPI app, scan the QR and approve the payment.</p>
                    </div>

                    <div class="qr-panel relative mx-auto mt-6 p-3 rounded-2xl bg-white ring-1 ring-slate-200 shadow-sm">
                        <span class="absolute -top-px -left-px w-6 h-6 border-t-[3px] border-l-[3px] border-brand-600 rounded-tl-2xl"></span>
                        <span class="absolute -top-px -right-px w-6 h-6 border-t-[3px] border-r-[3px] border-brand-600 rounded-tr-2xl"></span>
                        <span class="absolute -bottom-px -left-px w-6 h-6 border-b-[3px] border-l-[3px] border-brand-600 rounded-bl-2xl"></span>
                        <span class="absolute -bottom-px -right-px w-6 h-6 border-b-[3px] border-r-[3px] border-brand-600 rounded-br-2xl"></span>
                        <img src="{{ route('pay.qr', $order) }}" alt="UPI QR code for ₹{{ $amount }}" class="w-52 h-52 sm:w-56 sm:h-56">
                    </div>

                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        @foreach ($apps as [$name, $scheme, $color, $logo])
                            <span class="rounded-full bg-slate-50 ring-1 ring-slate-200 px-3 py-1 text-xs font-bold {{ $color }} flex items-center gap-1.5">
                                <img src="{{ asset($logo) }}" class="w-4 h-4 rounded-sm" alt="{{ $name }}">{{ $name }}
                            </span>
                        @endforeach
                        @foreach ($androidApps as [$name, $package, $logo])
                            <span class="rounded-full bg-white ring-1 ring-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm flex items-center gap-1.5">
                                <img src="{{ asset($logo) }}" class="w-4 h-4 rounded object-contain" alt="" aria-hidden="true">{{ $name }}
                            </span>
                        @endforeach
                    </div>

                    {{-- On phones, open the UPI app directly --}}
                    <div class="md:hidden mt-6 space-y-3">
                        <div class="flex items-center gap-3 text-xs text-slate-400"><span class="h-px flex-1 bg-slate-200"></span>OR PAY ON THIS PHONE<span class="h-px flex-1 bg-slate-200"></span></div>
                        <a href="{{ $order->upiIntentUrl() }}" class="btn btn-primary btn-lg w-full"><x-icon name="phone" class="w-5 h-5" /> Pay ₹{{ $amount }} with UPI app</a>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach (array_slice($apps, 0, 3) as [$name, $scheme, $color, $logo])
                                <a data-upi-app="{{ $name }}" href="{{ $order->upiIntentUrl($scheme) }}" class="btn btn-secondary font-bold {{ $color }} flex items-center justify-center gap-1.5">
                                    <img src="{{ asset($logo) }}" class="w-5 h-5 rounded-sm" alt="{{ $name }}">{{ $name }}
                                </a>
                            @endforeach
                        </div>
                        @if ($isAndroid)
                            <div class="grid grid-cols-3 gap-2">
                                @foreach ($androidApps as [$name, $package, $logo])
                                    <a data-upi-app="{{ $name }}" href="{{ $order->androidIntentUrl($package) }}" class="btn btn-secondary font-semibold text-slate-700 flex items-center justify-center gap-1.5 text-xs px-2">
                                        <img src="{{ asset($logo) }}" class="w-5 h-5 rounded object-contain shrink-0" alt="" aria-hidden="true"><span class="truncate">{{ $name }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="mt-6 flex items-center justify-between gap-3 rounded-xl bg-slate-50 ring-1 ring-slate-200 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-xs text-slate-500">Or pay to UPI ID</div>
                            <div class="font-mono text-sm font-medium text-slate-900 truncate">{{ $order->paymentAccount->upi_id }}</div>
                        </div>
                        <x-copy :text="$order->paymentAccount->upi_id" />
                    </div>

                    @if ($order->acceptsCustomerUtr())
                        @include('pay._utr-form', ['class' => 'mt-4'])
                    @endif

                    <div class="mt-auto pt-6">
                        <div class="flex items-center justify-center gap-2.5 rounded-xl bg-amber-50 ring-1 ring-amber-200 px-4 py-3 text-sm text-amber-800">
                            <svg class="w-4 h-4 animate-spin text-amber-600" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <span id="status-text">{{ $order->acceptsCustomerUtr() ? 'After paying, submit your UTR above' : 'Waiting for payment…' }}</span>
                            <button id="play-success-sound" type="button" class="hidden rounded-md border border-emerald-200 bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Play sound</button>
                        </div>
                        <p class="mt-2 text-center text-xs text-slate-400">Please don't close or refresh this page after paying.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <footer class="pb-6 flex flex-col items-center gap-2 text-xs text-slate-400">
        <div class="flex items-center gap-1.5"><x-icon name="lock" class="w-3.5 h-3.5" /> Secured by <span class="font-semibold text-slate-500">{{ config('app.name') }}</span></div>
        <div>Payments go directly to the merchant's bank account via UPI.</div>
    </footer>
</div>
@endsection

@push('scripts')
<script nonce="{{ $csp_nonce }}">
(() => {
    const root = document.documentElement;
    const themeToggle = document.getElementById('theme-toggle');
    const themeLabel = document.getElementById('theme-toggle-label');
    const moonIcon = document.getElementById('theme-icon-moon');
    const sunIcon = document.getElementById('theme-icon-sun');
    const setTheme = dark => {
        root.classList.toggle('upi-theme-dark', dark);
        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', String(dark));
            themeToggle.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            themeToggle.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
        }
        if (themeLabel) themeLabel.textContent = dark ? 'Light' : 'Dark';
        if (moonIcon) moonIcon.classList.toggle('hidden', dark);
        if (sunIcon) sunIcon.classList.toggle('hidden', !dark);
        try { localStorage.setItem('upi_checkout_theme', dark ? 'dark' : 'light'); } catch (e) {}
    };
    if (themeToggle) {
        setTheme(root.classList.contains('upi-theme-dark'));
        themeToggle.addEventListener('click', () => setTheme(!root.classList.contains('upi-theme-dark')));
    }

    const playedKey = 'upi-payment-success-sound:' + @json((string) $order->id);
    const fallbackButton = document.getElementById('play-success-sound');
    let audioContext = null;

    window.playPaymentSuccessSound = async () => {
        try {
            if (sessionStorage.getItem(playedKey) === '1') return true;
        } catch (e) {}

        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) throw new Error('Audio is not supported');
            if (!audioContext || audioContext.state === 'closed') audioContext = new AudioContextClass();
            if (audioContext.state !== 'running') {
                const resumed = await Promise.race([
                    audioContext.resume().then(() => true).catch(() => false),
                    new Promise(resolve => window.setTimeout(() => resolve(false), 300)),
                ]);
                if (!resumed) throw new Error('Audio needs a user gesture');
            }
            if (audioContext.state !== 'running') throw new Error('Audio needs a user gesture');

            const notes = [659.25, 783.99, 987.77];
            const startAt = audioContext.currentTime + 0.025;
            notes.forEach((frequency, index) => {
                const oscillator = audioContext.createOscillator();
                const gain = audioContext.createGain();
                const noteStart = startAt + index * 0.12;
                oscillator.type = 'sine';
                oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(0.0001, noteStart);
                gain.gain.exponentialRampToValueAtTime(0.12, noteStart + 0.025);
                gain.gain.exponentialRampToValueAtTime(0.0001, noteStart + 0.34);
                oscillator.connect(gain);
                gain.connect(audioContext.destination);
                oscillator.start(noteStart);
                oscillator.stop(noteStart + 0.36);
            });
            try { sessionStorage.setItem(playedKey, '1'); } catch (e) {}
            if (fallbackButton) fallbackButton.classList.add('hidden');
            window.setTimeout(() => {
                if (audioContext && audioContext.state !== 'closed') audioContext.close().catch(() => {});
            }, 850);
            return true;
        } catch (e) {
            if (fallbackButton) fallbackButton.classList.remove('hidden');
            return false;
        }
    };

    if (fallbackButton) {
        fallbackButton.addEventListener('click', async () => {
            const played = await window.playPaymentSuccessSound();
            if (played && typeof window.continueAfterPaymentSound === 'function') window.continueAfterPaymentSound();
        });
    }

    @if ($order->status === 'paid')
        window.playPaymentSuccessSound();
    @endif
})();
</script>
@endpush

@if (in_array($order->status, ['pending', 'review']))
@push('scripts')
<script nonce="{{ $csp_nonce }}">
(() => {
    const initialStatus = @json($order->status);
    const expiresAt = {{ ($order->expires_at ?? now()->addMinutes(config('gateway.order_expiry_minutes', 15)))->getTimestampMs() }};
    const total = {{ $totalSeconds }};
    const timer = document.getElementById('timer');
    const ring = document.getElementById('ring');
    const statusUrl = @json(route('pay.status', $order));

    // Remember the UPI app the customer used last time and put it first.
    try {
        const links = document.querySelectorAll('[data-upi-app]');
        const last = localStorage.getItem('emudra_last_app');
        links.forEach(a => a.addEventListener('click', () => { try { localStorage.setItem('emudra_last_app', a.dataset.upiApp); } catch (e) {} }));
        const hit = last && [...links].find(a => a.dataset.upiApp === last);
        if (hit) {
            hit.parentElement.prepend(hit);
            hit.classList.add('ring-2', 'ring-brand-500');
            hit.insertAdjacentHTML('beforeend', '<span class="text-[10px] font-semibold text-brand-600">· last used</span>');
        }
    } catch (e) {}

    const tick = () => {
        if (!timer) return;
        const left = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
        const display = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
        const offset = (100.5 * (1 - left / total)).toFixed(2);
        const stroke = left < 60 ? '#f87171' : '#818cf8';
        timer.textContent = display;
        ring.style.strokeDashoffset = offset;
        ring.style.stroke = stroke;
        // Hosted-PG secondary timer widget (different IDs to avoid duplicate-id)
        const timerPg = document.getElementById('timer-pg');
        const ringPg  = document.getElementById('ring-pg');
        if (timerPg) timerPg.textContent = display;
        if (ringPg)  { ringPg.style.strokeDashoffset = offset; ringPg.style.stroke = stroke; }
    };
    tick();
    setInterval(tick, 1000);

    const poll = async () => {
        try {
            const res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            if (res.ok) {
                const data = await res.json();
                if (data.status !== initialStatus) {
                    const text = document.getElementById('status-text');
                    const destination = data.redirect || window.location.href;
                    if (data.status === 'paid') {
                        if (text) text.textContent = 'Payment received!';
                        const played = await window.playPaymentSuccessSound();
                        let redirectTimer;
                        window.continueAfterPaymentSound = () => {
                            window.clearTimeout(redirectTimer);
                            window.setTimeout(() => { window.location.href = destination; }, 650);
                        };
                        redirectTimer = window.setTimeout(() => { window.location.href = destination; }, played ? 850 : 4500);
                    } else {
                        if (text) text.textContent = 'Updating…';
                        window.location.href = destination;
                    }
                    return;
                }
            }
        } catch (e) {}
        setTimeout(poll, 4000);
    };
    setTimeout(poll, 4000);
})();
</script>
@endpush
@endif
