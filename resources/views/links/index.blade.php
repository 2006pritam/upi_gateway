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
