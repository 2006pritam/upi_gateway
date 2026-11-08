@extends('layouts.app')
@section('title', 'FamApp e-mail verification')
@section('subtitle', $account->label.' · automatic payment verification from FamApp e-mails')
@section('back', route('accounts.index'))

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="card p-6">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-900"><x-icon name="bolt" class="w-5 h-5 text-sky-600" /> Connect your Gmail (5 minutes, once)</div>
            <ol class="mt-3 space-y-2 text-sm text-slate-600 list-decimal list-inside">
                <li>Open <span class="font-medium text-slate-800">script.google.com</span> while signed in to the Gmail that receives your FamApp e-mails, and create a <b>New project</b>.</li>
                <li>Delete the sample code, paste the script below, and press <b>Save</b>.</li>
                <li>Choose the function <span class="kbd">install</span> and press <b>Run</b>. Approve the permissions (Gmail read and external requests).</li>
            </ol>
            <p class="mt-3 text-xs text-slate-500">After that it checks your mailbox every minute and forwards only FamApp "You received" e-mails. Your Gmail password is never shared.</p>
        </div>

        <div class="card p-6">
            <div class="flex items-center justify-between gap-3">
                <div class="text-sm font-semibold text-slate-900">Your script</div>
                <button type="button" data-copy="{{ $script }}" class="btn btn-secondary btn-sm"><x-icon name="copy" class="w-4 h-4" /> Copy script</button>
            </div>
            <p class="mt-1 text-xs text-amber-700">It contains this account's secret. Don't share it. Anyone holding it can report payments for this account.</p>
            <pre class="mt-3 max-h-96 overflow-auto rounded-lg bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><code>{{ $script }}</code></pre>
        </div>

        <div class="card p-6">
            <div class="text-sm font-semibold text-slate-900">Recent e-mails received</div>
            @if ($recent->isEmpty())
                <p class="mt-2 text-sm text-slate-500">Nothing yet. Make a small test payment to your FamX UPI ID after the script is running.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-slate-500"><tr><th class="py-1.5 pr-4">When</th><th class="pr-4">Amount</th><th class="pr-4">UTR</th><th class="pr-4">Note</th><th>Result</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach ($recent as $p)
                            <tr>
                                <td class="py-2 pr-4 text-slate-600">{{ ($p->paid_at ?? $p->created_at)->timezone(config('app.timezone'))->format('d M, h:i A') }}</td>
                                <td class="pr-4 tabular-nums">₹{{ number_format((float) $p->amount, 2) }}</td>
                                <td class="pr-4 font-mono text-xs">{{ $p->utr }}</td>
                                <td class="pr-4 text-slate-600 truncate max-w-[10rem]">{{ $p->purpose ?: '—' }}</td>
                                <td>
                                    @if ($p->status === 'matched')
                                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Paid order {{ $p->order?->order_no }}</span>
                                    @else
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ $p->status === 'ambiguous' ? 'Several orders fit' : 'No matching order' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="space-y-4">
        <div class="card p-5">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-900"><x-icon name="shield" class="w-5 h-5 text-emerald-600" /> How a payment is matched</div>
            <ol class="mt-3 space-y-2 text-sm text-slate-600 list-decimal list-inside">
                <li>The customer pays your FamX UPI ID from the checkout QR. The order number travels as the payment note.</li>
                <li>FamApp e-mails you. Gmail confirms it is really from FamApp, and the script forwards it.</li>
                <li>The gateway pays the order named in the note, or the one open order with exactly that amount.</li>
                <li>If several open orders have the same amount, nothing is guessed. The customer enters their UTR on the checkout page and it is approved instantly.</li>
            </ol>
        </div>
        <div class="card p-5 text-xs text-slate-500">
            Each e-mail and each UTR is used only once. Orders can only be paid by a payment made after they were created, for exactly their amount.
        </div>
    </div>
</div>
@endsection
