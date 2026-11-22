@extends('layouts.app')
@section('title', 'Earnings')
@section('subtitle', 'What reached your UPI accounts, day by day. This is a record, not a settlement: money already sits in your own bank.')

@section('content')
<form method="GET" class="card p-4 flex flex-wrap items-end gap-3 mb-6">
    <div><label class="label">Period</label><select name="days" class="input">@foreach ([7, 30, 90] as $d)<option value="{{ $d }}" @selected($days === $d)>Last {{ $d }} days</option>@endforeach</select></div>
    <div><label class="label">Account</label><select name="account" class="input"><option value="">All accounts</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected($accountId == $a->id)>{{ $a->label }}</option>@endforeach</select></div>
    <button class="btn btn-primary">Apply</button>
</form>
<div class="grid grid-cols-2 gap-4 mb-6">
    <div class="card p-5"><div class="text-xs uppercase tracking-wider text-slate-500">Net total, all time</div><div class="mt-1 text-2xl font-bold tabular-nums">₹{{ number_format($total, 2) }}</div></div>
    <div class="card p-5"><div class="text-xs uppercase tracking-wider text-slate-500">Before this period</div><div class="mt-1 text-2xl font-bold tabular-nums">₹{{ number_format($opening, 2) }}</div></div>
</div>
<div class="card overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
    <thead class="bg-slate-50/70"><tr><th class="th">Date</th><th class="th text-right">Payments</th><th class="th text-right">Received</th><th class="th text-right">Refunded</th><th class="th text-right">Running total</th></tr></thead>
    <tbody class="divide-y divide-slate-100">
    @foreach ($rows as $r)
        <tr @class(['text-slate-400' => ! $r['count'] && ! $r['refunded']])>
            <td class="td">{{ $r['date']->format('d M Y, D') }}</td>
            <td class="td text-right tabular-nums">{{ $r['count'] }}</td>
            <td class="td text-right tabular-nums">{{ $r['received'] ? '₹'.number_format($r['received'], 2) : '–' }}</td>
            <td class="td text-right tabular-nums">{{ $r['refunded'] ? '−₹'.number_format($r['refunded'], 2) : '–' }}</td>
            <td class="td text-right tabular-nums font-medium">₹{{ number_format($r['running'], 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endsection
