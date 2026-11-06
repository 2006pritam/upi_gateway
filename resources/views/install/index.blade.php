@extends('install.layout')
@php $step = 1; @endphp

@section('content')
<h1 class="text-2xl font-bold text-slate-900">Server Requirements</h1>
<p class="mt-1 text-sm text-slate-500">Make sure your server meets all requirements before proceeding.</p>

<div class="mt-6 space-y-2">
    @foreach ($requirements as $req)
        <div class="flex items-center justify-between rounded-lg px-4 py-3 {{ $req['pass'] ? 'bg-emerald-50 ring-1 ring-emerald-200' : 'bg-red-50 ring-1 ring-red-200' }}">
            <span class="text-sm font-medium {{ $req['pass'] ? 'text-emerald-800' : 'text-red-800' }}">{{ $req['name'] }}</span>
            <span class="text-lg">{{ $req['pass'] ? '✅' : '❌' }}</span>
        </div>
    @endforeach
</div>

@php $allPass = collect($requirements)->every(fn($r) => $r['pass']); @endphp

<div class="mt-8">
    @if ($allPass)
        <a href="{{ route('install.database') }}" class="btn btn-primary w-full justify-center py-2.5">
            Continue → Database Setup
        </a>
    @else
        <div class="rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700">
            ❌ Please fix the failing requirements and refresh this page.
        </div>
        <button onclick="location.reload()" class="btn btn-secondary w-full justify-center py-2.5 mt-3">Recheck</button>
    @endif
</div>
@endsection
