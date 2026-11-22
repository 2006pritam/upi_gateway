@extends('layouts.app')
@section('title', 'Reports')
@section('subtitle', 'Your payments for any date range. Download CSV or print as PDF.')

@section('content')
<form method="GET" class="card p-4 flex flex-wrap items-end gap-3 mb-6 print:hidden">
    <div><label class="label">From</label><input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="input"></div>
    <div><label class="label">To</label><input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="input"></div>
    <button class="btn btn-primary">Apply</button>
    <a href="{{ route('reports.export', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" class="btn btn-secondary">Download CSV</a>
    <button type="button" onclick="window.print()" class="btn btn-secondary">Print / PDF</button>
</form>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @foreach ([['Received', '₹'.number_format($summary['paid_total'], 2)], ['Paid orders', $summary['paid']], ['Orders created', $summary['orders']], ['Conversion', $summary['conversion'].'%']] as [$label, $value])
        <div class="card p-5"><div class="text-xs uppercase tracking-wider text-slate-500">{{ $label }}</div><div class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">{{ $value }}</div></div>
    @endforeach
</div>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">By day</h2>
        @php $max = max(1, collect($summary['by_day'])->max('total') ?? 1); @endphp
        @forelse ($summary['by_day'] as $day => $row)
            <div class="flex items-center gap-3 py-1 text-sm">
                <span class="w-24 text-slate-500 tabular-nums">{{ $day }}</span>
                <span class="flex-1 h-2 rounded bg-slate-100"><span class="block h-2 rounded bg-brand-500" style="width: {{ round($row['total'] / $max * 100) }}%"></span></span>
                <span class="w-28 text-right tabular-nums">₹{{ number_format($row['total'], 2) }} <span class="text-slate-400">({{ $row['count'] }})</span></span>
            </div>
        @empty <p class="text-sm text-slate-500">No paid orders in this range.</p> @endforelse
    </div>
    <div class="card p-5">
        <h2 class="font-semibold text-slate-900 mb-3">By UPI account</h2>
        @forelse ($summary['by_account'] as $name => $row)
            <div class="flex justify-between py-1.5 text-sm border-b border-slate-100 last:border-0"><span>{{ $name }}</span><span class="tabular-nums">₹{{ number_format($row['total'], 2) }} <span class="text-slate-400">({{ $row['count'] }})</span></span></div>
        @empty <p class="text-sm text-slate-500">Nothing yet.</p> @endforelse
        <div class="mt-4 text-xs text-slate-500">Pending {{ $summary['pending'] }} · In review {{ $summary['review'] }} · Expired {{ $summary['expired'] }}</div>
    </div>
</div>
@endsection
