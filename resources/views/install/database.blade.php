@extends('install.layout')
@php $step = 2; @endphp

@section('content')
<h1 class="text-2xl font-bold text-slate-900">Database Setup</h1>
<p class="mt-1 text-sm text-slate-500">Configure your database connection.</p>

<form method="POST" action="{{ route('install.database.save') }}" class="mt-6 space-y-5">
    @csrf

    <div>
        <label class="label">App Name</label>
        <input type="text" name="app_name" value="{{ old('app_name', 'UPI Gateway') }}" class="input" required>
    </div>

    <div>
        <label class="label">App URL</label>
        <input type="url" name="app_url" value="{{ old('app_url', 'http://localhost') }}" class="input" placeholder="https://yourdomain.com" required>
    </div>

    <div>
        <label class="label">Database Type</label>
        <select name="db_connection" id="db_connection" class="input" onchange="toggleMysql(this.value)">
            <option value="sqlite" {{ old('db_connection') === 'mysql' ? '' : 'selected' }}>SQLite (recommended for local)</option>
            <option value="mysql" {{ old('db_connection') === 'mysql' ? 'selected' : '' }}>MySQL (recommended for production)</option>
        </select>
    </div>

    <div id="mysql_fields" class="{{ old('db_connection') === 'mysql' ? '' : 'hidden' }} space-y-4">
        <div class="grid grid-cols-3 gap-3">
            <div class="col-span-2">
                <label class="label">Host</label>
                <input type="text" name="db_host" value="{{ old('db_host', '127.0.0.1') }}" class="input" placeholder="127.0.0.1">
            </div>
            <div>
                <label class="label">Port</label>
                <input type="text" name="db_port" value="{{ old('db_port', '3306') }}" class="input" placeholder="3306">
            </div>
        </div>
        <div>
            <label class="label">Database Name</label>
            <input type="text" name="db_database" value="{{ old('db_database', 'upi_gateway') }}" class="input" placeholder="upi_gateway">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label">Username</label>
                <input type="text" name="db_username" value="{{ old('db_username', 'root') }}" class="input">
            </div>
            <div>
                <label class="label">Password</label>
                <input type="password" name="db_password" value="{{ old('db_password') }}" class="input">
            </div>
        </div>
    </div>

    {{-- SQLite hidden db_database --}}
    <input type="hidden" name="db_database" value="database/database.sqlite" id="sqlite_db">

    @if ($errors->any())
        <div class="rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <button type="submit" class="btn btn-primary w-full justify-center py-2.5">
        Test Connection & Continue →
    </button>
</form>
@endsection

@push('scripts')
<script>
function toggleMysql(val) {
    document.getElementById('mysql_fields').classList.toggle('hidden', val !== 'mysql');
    document.getElementById('sqlite_db').disabled = val === 'mysql';
}
toggleMysql(document.getElementById('db_connection').value);
</script>
@endpush
