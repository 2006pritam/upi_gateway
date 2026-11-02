@extends('layouts.app')
@section('title', 'Orders')
@section('subtitle', 'Every payment request created from the API or dashboard.')
@section('actions')
    <a href="{{ route('orders.create') }}" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Payment link</a>
@endsection

@section('content')
@php
    $current = request('status', '');
    $tabs = ['' => ['All', $counts->sum()], 'review' => ['Needs review', $counts['review'] ?? 0], 'paid' => ['Paid', $counts['paid'] ?? 0], 'pending' => ['Pending', $counts['pending'] ?? 0], 'expired' => ['Expired', $counts['expired'] ?? 0]];
@endphp
<div class="card overflow-hidden">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 px-5 py-3 border-b border-slate-100">
        <nav class="flex gap-1 overflow-x-auto -mx-1">
            @foreach ($tabs as $value => [$label, $count])
                <a href="{{ route('orders.index', array_filter(['status' => $value, 'q' => request('q')])) }}" @class([
                    'inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    'bg-slate-900 text-white' => $current === $value,
                    'text-slate-600 hover:bg-slate-100' => $current !== $value,
                ])>
                    {{ $label }}
                    <span @class(['rounded-full px-1.5 text-xs tabular-nums', 'bg-white/20' => $current === $value, 'bg-slate-100 text-slate-500' => $current !== $value])>{{ $count }}</span>
                </a>
            @endforeach
        </nav>
        <form class="relative md:w-80">
            @if ($current)<input type="hidden" name="status" value="{{ $current }}">@endif
            <x-icon name="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input name="q" value="{{ request('q') }}" placeholder="Search order ID, reference or UTR" class="input pl-9">
        </form>
    </div>

    @include('orders._table')

    @if ($orders->hasPages())
        <div class="px-5 py-3 border-t border-slate-100">{{ $orders->links() }}</div>
    @endif
</div>
@endsection
