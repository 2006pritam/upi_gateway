@extends('install.layout')
@php $step = 3; @endphp

@section('content')
<h1 class="text-2xl font-bold text-slate-900">Run Migrations</h1>
<p class="mt-1 text-sm text-slate-500">Creating database tables — this may take a few seconds.</p>

<div id="output" class="mt-6 rounded-xl bg-slate-950 p-4 font-mono text-xs text-slate-300 min-h-32 whitespace-pre-wrap">
    Waiting to start...
</div>

<div id="error" class="hidden mt-4 rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700"></div>

<div class="mt-6 space-y-3">
    <button id="run-btn" onclick="runMigrations()" class="btn btn-primary w-full justify-center py-2.5">
        Run Migrations
    </button>
    <a id="next-btn" href="{{ route('install.admin') }}" class="btn btn-primary w-full justify-center py-2.5 hidden">
        Continue → Create Admin →
    </a>
</div>
@endsection

@push('scripts')
<script>
async function runMigrations() {
    const btn = document.getElementById('run-btn');
    const out = document.getElementById('output');
    const err = document.getElementById('error');
    btn.disabled = true;
    btn.textContent = 'Running...';
    out.textContent = 'Running migrations...\n';

    try {
        const res = await fetch('{{ route('install.migrate.run') }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
        });
        const data = await res.json();
        if (data.success) {
            out.textContent += data.output + '\n✅ Migrations completed successfully!';
            btn.classList.add('hidden');
            document.getElementById('next-btn').classList.remove('hidden');
        } else {
            out.textContent += '❌ Failed.';
            err.textContent = data.message;
            err.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'Retry';
        }
    } catch (e) {
        err.textContent = 'Request failed: ' + e.message;
        err.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Retry';
    }
}
</script>
@endpush
