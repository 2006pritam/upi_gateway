@extends('install.layout')
@php $step = 4; @endphp

@section('content')
<h1 class="text-2xl font-bold text-slate-900">Create Admin Account</h1>
<p class="mt-1 text-sm text-slate-500">This will be your admin login for the dashboard.</p>

<form method="POST" action="{{ route('install.admin.save') }}" class="mt-6 space-y-5">
    @csrf

    <div>
        <label class="label">Full Name</label>
        <input type="text" name="name" value="{{ old('name') }}" class="input @error('name') ring-red-400 @enderror" placeholder="Mayank Singh" required>
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="label">Email Address</label>
        <input type="email" name="email" value="{{ old('email') }}" class="input @error('email') ring-red-400 @enderror" placeholder="admin@example.com" required>
        @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="label">Password</label>
        <input type="password" name="password" class="input @error('password') ring-red-400 @enderror" placeholder="Min 8 characters" required>
        @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="label">Confirm Password</label>
        <input type="password" name="password_confirmation" class="input" required>
    </div>

    <button type="submit" class="btn btn-primary w-full justify-center py-2.5">
        Create Account & Finish →
    </button>
</form>
@endsection
