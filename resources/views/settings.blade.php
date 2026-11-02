@extends('layouts.app')
@section('title', 'API & Webhooks')
@section('subtitle', 'Connect your website or app to accept payments automatically.')
@section('actions')
    <a href="{{ route('docs') }}" class="btn btn-secondary"><x-icon name="book" class="w-4 h-4" /> Read API docs</a>
@endsection

@section('content')
<div class="space-y-10">
    {{-- API keys --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div>
            <h2 class="text-base font-semibold text-slate-900">API credentials</h2>
            <p class="mt-1 text-sm text-slate-500">Send these as <span class="kbd">X-Api-Key</span> and <span class="kbd">X-Api-Secret</span> headers. Keep the secret on your server only.</p>
        </div>
        <div class="card p-6 lg:col-span-2 space-y-5">
            <div>
                <label class="label">API key</label>
                <div class="flex gap-2">
                    <input readonly value="{{ $user->api_key }}" class="input font-mono bg-slate-50">
                    <x-copy :text="$user->api_key" />
                </div>
            </div>
            <div>
                <label class="label">API secret</label>
                <div class="flex gap-2">
                    <input id="secret" type="password" readonly value="{{ $user->api_secret }}" class="input font-mono bg-slate-50">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="const s=document.getElementById('secret'); s.type = s.type === 'password' ? 'text' : 'password'"><x-icon name="eye" class="w-4 h-4" /> Reveal</button>
                    <x-copy :text="$user->api_secret" />
                </div>
                <p class="help">Also used to sign webhooks (HMAC-SHA256).</p>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-lg bg-red-50/60 ring-1 ring-red-100 px-4 py-3">
                <div class="text-sm text-red-800"><b>Regenerate keys</b><div class="text-xs text-red-700/80">Old keys stop working immediately.</div></div>
                <form method="POST" action="{{ route('settings.regenerate') }}">@csrf
                    <button onclick="return confirm('Old keys will stop working immediately. Continue?')" class="btn btn-danger btn-sm"><x-icon name="refresh" class="w-4 h-4" /> Regenerate</button>
                </form>
            </div>
        </div>
    </section>

    {{-- Webhook --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-10 border-t border-slate-200">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Webhook</h2>
            <p class="mt-1 text-sm text-slate-500">We POST <span class="kbd">order.paid</span> and <span class="kbd">order.expired</span> events here, retrying up to 6 times.</p>
        </div>
        <form method="POST" action="{{ route('settings.webhook') }}" class="card p-6 lg:col-span-2">
            @csrf
            <x-input name="webhook_url" label="Endpoint URL" type="url" :value="$user->webhook_url" placeholder="https://yoursite.com/upi/webhook" class="font-mono"
                help="Verify the X-Signature header on every request. See the API docs for sample code." />
            <div class="flex justify-end"><button class="btn btn-primary">Save webhook</button></div>
        </form>
    </section>

    {{-- Profile --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-10 border-t border-slate-200">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Business profile</h2>
            <p class="mt-1 text-sm text-slate-500">Your business name is shown to customers on the checkout page.</p>
        </div>
        <form method="POST" action="{{ route('settings.profile') }}" class="card p-6 lg:col-span-2">
            @csrf
            <div class="grid sm:grid-cols-2 gap-x-4">
                <x-input name="name" label="Your name" :value="$user->name" required />
                <x-input name="business_name" label="Business name" :value="$user->business_name" required />
                <x-input name="phone" label="Phone" :value="$user->phone" />
                <div class="mb-5">
                    <label class="label">Email</label>
                    <input value="{{ $user->email }}" disabled class="input bg-slate-50 text-slate-500">
                </div>
            </div>
            <div class="flex justify-end"><button class="btn btn-primary">Save profile</button></div>
        </form>
    </section>
</div>
@endsection
