@extends('layouts.app')
@section('title', 'Webhook deliveries')
@section('subtitle', $url ? 'Sending to '.$url : 'No webhook URL set yet. Add one under API & Webhooks.')
@section('actions')
    <a href="{{ route('webhooks.index', ['filter' => request('filter') === 'failed' ? null : 'failed']) }}" class="btn btn-secondary">{{ request('filter') === 'failed' ? 'Show all' : 'Show failed only' }}</a>
@endsection

@section('content')
<div class="card overflow-hidden">
    @if ($logs->isEmpty())
        <x-empty icon="link" title="Nothing here" text="Webhook attempts appear when an order changes status." />
    @else
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
        <thead class="bg-slate-50/70"><tr><th class="th">Order</th><th class="th">Event</th><th class="th">Attempt</th><th class="th">Result</th><th class="th">Time</th><th class="th"></th></tr></thead>
        <tbody class="divide-y divide-slate-100">
        @foreach ($logs as $log)
            <tr>
                <td class="td"><a class="text-brand-600 font-mono text-xs" href="{{ route('orders.show', $log->order) }}">{{ $log->order->client_order_id }}</a></td>
                <td class="td font-mono text-xs">{{ $log->event }}</td>
                <td class="td text-slate-600">#{{ $log->attempt }}</td>
                <td class="td"><span class="text-xs font-medium {{ $log->success ? 'text-emerald-700' : 'text-red-700' }}">{{ $log->response_code ?? 'No response' }}</span>@if($log->error_label)<span class="text-xs text-slate-400 font-mono"> {{ $log->error_label }}</span>@endif</td>
                <td class="td text-slate-500 whitespace-nowrap">{{ $log->created_at->format('d M, h:i A') }}</td>
                <td class="td"><form method="POST" action="{{ route('webhooks.resend', $log) }}">@csrf<button class="btn btn-secondary btn-sm"><x-icon name="refresh" class="w-4 h-4" /> Resend</button></form></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="p-4">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
