@extends('layouts.app')
@section('title', 'Payment links')
@section('subtitle', 'Reusable links and QR posters. No code needed: share on WhatsApp or print for your shop counter.')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
    <form method="POST" action="{{ route('links.store') }}" class="card p-6 lg:col-span-2 h-fit">
        @csrf
        <h2 class="text-base font-semibold text-slate-900 mb-4">New link</h2>
        <x-input name="title" label="Title" placeholder="e.g. Course fee, Tea stall" required maxlength="100" />
        <div class="mb-5">
            <label for="l-amount" class="label">Amount (₹)</label>
            <input id="l-amount" type="number" step="0.01" min="1" max="100000" name="amount" value="{{ old('amount') }}" class="input" placeholder="Leave empty: customer types the amount">
            @error('amount')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <x-input name="note" label="Payment note" maxlength="50" placeholder="Optional, shown in the UPI app" />
        <div class="mb-5">
            <label for="l-description" class="label">Page description</label>
            <textarea id="l-description" name="description" rows="3" maxlength="1000" class="input" placeholder="Optional, shown on the payment page">{{ old('description') }}</textarea>
        </div>
        <div class="grid grid-cols-2 gap-x-4">
            <x-input name="button_label" label="Button text" maxlength="30" placeholder="Pay Now" />
            <x-input name="theme_color" label="Colour" type="color" value="#2563eb" class="h-11" />
        </div>
        <div class="mb-5">
            <label for="l-account" class="label">Receive in</label>
            <select id="l-account" name="payment_account_id" class="input">
                <option value="">Automatic (rotation)</option>
                @foreach ($accounts as $a)
                    <option value="{{ $a->id }}">{{ $a->label }} — {{ $a->upi_id }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-primary w-full"><x-icon name="plus" class="w-4 h-4" /> Create link</button>
    </form>

    <div class="lg:col-span-3 space-y-3">
        @forelse ($links as $link)
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-900 truncate">{{ $link->title }}</div>
                        <div class="text-sm text-slate-500">{{ $link->isFixedAmount() ? '₹'.number_format((float) $link->amount, 2) : 'Customer enters amount' }} · {{ $link->paid_count }} paid / {{ $link->orders_count }} opened</div>
                    </div>
                    <span @class(['badge', 'bg-emerald-50 text-emerald-700' => $link->is_active, 'bg-slate-100 text-slate-500' => ! $link->is_active])>{{ $link->is_active ? 'Active' : 'Disabled' }}</span>
                </div>
                <div class="flex gap-2 mt-3">
                    <input readonly value="{{ $link->publicUrl() }}" class="input font-mono text-xs bg-slate-50">
                    <x-copy :text="$link->publicUrl()" />
                </div>
                <details class="mt-3 rounded-lg ring-1 ring-slate-200 p-3">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-700">Pay button for your website</summary>
                    <p class="help">Paste this code where the button should appear.</p>
                    <pre class="mt-3 whitespace-pre-wrap break-all rounded-lg bg-slate-900 text-slate-100 px-3 py-2 font-mono text-xs">{{ $link->buttonSnippet() }}</pre>
                    <div class="mt-2"><x-copy :text="$link->buttonSnippet()" label="Copy button code" /></div>
                    <p class="mt-3 text-xs text-amber-700">Confirm every payment with your webhook or the Order Status API before delivering anything. Browser callbacks can be faked.</p>
                    <form method="POST" action="{{ route('links.update', $link) }}" class="mt-4 border-t border-slate-100 pt-4 space-y-3">
                        @csrf @method('PUT')
                        <div>
                            <label for="d-{{ $link->id }}" class="label">Page description</label>
                            <textarea id="d-{{ $link->id }}" name="description" rows="2" maxlength="1000" class="input">{{ $link->description }}</textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="b-{{ $link->id }}" class="label">Button text</label>
                                <input id="b-{{ $link->id }}" name="button_label" maxlength="30" value="{{ $link->buttonLabel() }}" class="input">
                            </div>
                            <div>
                                <label for="c-{{ $link->id }}" class="label">Colour</label>
                                <input id="c-{{ $link->id }}" type="color" name="theme_color" value="{{ $link->themeColor() }}" class="input h-11">
                            </div>
                        </div>
                        <button class="btn btn-secondary btn-sm">Save look</button>
                    </form>
                </details>
                <div class="flex flex-wrap gap-2 mt-3">
                    <a href="{{ route('links.poster', $link) }}" target="_blank" class="btn btn-secondary btn-sm"><x-icon name="qr" class="w-4 h-4" /> QR poster</a>
                    <a href="https://wa.me/?text={{ urlencode($link->title.': '.$link->publicUrl()) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm"><x-icon name="phone" class="w-4 h-4" /> WhatsApp</a>
                    <form method="POST" action="{{ route('links.toggle', $link) }}">@csrf<button class="btn btn-secondary btn-sm"><x-icon name="power" class="w-4 h-4" /> {{ $link->is_active ? 'Disable' : 'Enable' }}</button></form>
                    <form method="POST" action="{{ route('links.destroy', $link) }}">@csrf @method('DELETE')<button data-confirm="Delete this link? The URL and printed QR will stop working." class="btn btn-danger btn-sm"><x-icon name="trash" class="w-4 h-4" /> Delete</button></form>
                </div>
            </div>
        @empty
            <div class="card"><x-empty icon="link" title="No links yet" text="Create your first link on the left, then share it or print the QR poster." /></div>
        @endforelse
    </div>
</div>
@endsection
