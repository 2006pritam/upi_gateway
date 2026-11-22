@props(['order'])
@if ($order->risk_level)
    @php $cls = ['high' => 'bg-red-50 text-red-700 ring-red-600/20', 'medium' => 'bg-amber-50 text-amber-700 ring-amber-600/20', 'low' => 'bg-slate-100 text-slate-600 ring-slate-500/20'][$order->risk_level]; @endphp
    <span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset $cls"]) }}
        title="{{ collect($order->risk_flags)->map(fn ($f) => \App\Services\RiskService::label($f))->implode('; ') }}"><x-icon name="alert" class="w-3 h-3" />{{ ucfirst($order->risk_level) }} risk</span>
@endif
