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
                    <button type="button" class="btn btn-secondary btn-sm" data-toggle-secret><x-icon name="eye" class="w-4 h-4" /> Reveal</button>
                    <x-copy :text="$user->api_secret" />
                </div>
                <p class="help">Also used to sign webhooks (HMAC-SHA256).</p>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-lg bg-red-50/60 ring-1 ring-red-100 px-4 py-3">
                <div class="text-sm text-red-800"><b>Regenerate keys</b><div class="text-xs text-red-700/80">Old keys stop working immediately.</div></div>
                <form method="POST" action="{{ route('settings.regenerate') }}">@csrf
                    <button data-confirm="Old keys will stop working immediately. Continue?" class="btn btn-danger btn-sm"><x-icon name="refresh" class="w-4 h-4" /> Regenerate</button>
                </form>
            </div>
        </div>
    </section>

    {{-- Sandbox --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-10 border-t border-slate-200">
        <div>
            <div class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold tracking-wide text-amber-800">SANDBOX · TEST ONLY</div>
            <h2 class="mt-3 text-base font-semibold text-slate-900">Sandbox credentials</h2>
            <p class="mt-1 text-sm text-slate-500">Create simulated payments without contacting Paytm, PhonePe, banks, or UPI providers.</p>
        </div>
        <div class="card p-6 lg:col-span-2 space-y-5 ring-amber-200">
            @if ($user->sandbox_api_key)
                <div>
                    <label class="label">Sandbox API key</label>
                    <div class="flex gap-2">
                        <input readonly value="{{ $user->sandbox_api_key }}" class="input font-mono bg-amber-50">
                        <x-copy :text="$user->sandbox_api_key" />
                    </div>
                </div>
                @if (session('sandbox_api_secret_once_encrypted'))
                    @php $sandboxSecretOnce = Crypt::decryptString(session('sandbox_api_secret_once_encrypted')); @endphp
                    <div class="rounded-lg bg-amber-50 p-4 ring-1 ring-amber-300">
                        <label class="label">Sandbox API secret · shown once</label>
                        <div class="flex gap-2">
                            <input readonly value="{{ $sandboxSecretOnce }}" class="input font-mono bg-white">
                            <x-copy :text="$sandboxSecretOnce" />
                        </div>
                        <p class="mt-2 text-xs font-medium text-amber-800">Copy this now. It cannot be retrieved after leaving this page.</p>
                    </div>
                @else
                    <p class="text-sm text-slate-500">The Sandbox secret is stored encrypted and cannot be displayed again. Regenerate it if lost.</p>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    <form method="POST" action="{{ route('settings.sandbox.revoke') }}">@csrf
                        <button data-confirm="Revoke Sandbox credentials? Existing TEST integrations will stop working." class="btn btn-danger btn-sm">Revoke</button>
                    </form>
                    <form method="POST" action="{{ route('settings.sandbox.regenerate') }}">@csrf
                        <button data-confirm="Regenerate Sandbox credentials? The old TEST credentials will stop working." class="btn btn-secondary btn-sm">Regenerate</button>
                    </form>
                </div>
            @else
                <p class="text-sm text-slate-600">No Sandbox credentials have been generated.</p>
                <form method="POST" action="{{ route('settings.sandbox.regenerate') }}" class="flex justify-end">@csrf
                    <button class="btn btn-primary">Generate Sandbox credentials</button>
                </form>
            @endif

            <form method="POST" action="{{ route('settings.sandbox.webhook') }}" class="border-t border-slate-100 pt-5">
                @csrf
                <x-input name="sandbox_webhook_url" label="Sandbox webhook endpoint" type="url" :value="$user->sandbox_webhook_url" placeholder="https://sandbox.yoursite.com/webhook" class="font-mono"
                    help="Must differ from the Live webhook URL. TEST events are signed only with the Sandbox secret." />
                <div class="flex justify-end"><button class="btn btn-primary">Save Sandbox webhook</button></div>
            </form>
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
    {{-- Named API keys --}}
    @php $namedKeys = $user->apiKeys()->orderByRaw('revoked_at is not null')->latest('id')->get(); @endphp
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div>
            <h2 class="text-base font-semibold text-slate-900">API keys by website</h2>
            <p class="mt-1 text-sm text-slate-500">Create a separate key and secret for each website or app. Revoking one never affects the others. Webhooks for a key are signed with that key's own secret.</p>
        </div>
        <div class="card p-6 lg:col-span-2 space-y-5">
            @if (session('new_api_key_encrypted'))
                @php $nk = json_decode(Crypt::decryptString(session('new_api_key_encrypted')), true); @endphp
                <div class="rounded-lg bg-amber-50 p-4 ring-1 ring-amber-300 space-y-3">
                    <div class="text-sm font-semibold text-amber-900">New key: {{ $nk['name'] }}</div>
                    <div><label class="label">API key</label><div class="flex gap-2"><input readonly value="{{ $nk['key'] }}" class="input font-mono bg-white"><x-copy :text="$nk['key']" /></div></div>
                    <div><label class="label">API secret · shown once</label><div class="flex gap-2"><input readonly value="{{ $nk['secret'] }}" class="input font-mono bg-white"><x-copy :text="$nk['secret']" /></div>
                        <p class="mt-2 text-xs font-medium text-amber-800">Copy this now. It cannot be shown again.</p></div>
                </div>
            @endif

            <form method="POST" action="{{ route('api-keys.store') }}" class="grid sm:grid-cols-6 gap-3 items-end">
                @csrf
                <div class="sm:col-span-3"><label class="label">Website / app name</label><input name="name" value="{{ old('name') }}" maxlength="60" required class="input" placeholder="e.g. Academy website"></div>
                <div class="sm:col-span-2"><label class="label">Type</label><select name="environment" class="input"><option value="live">Live</option><option value="sandbox">Sandbox (test)</option></select></div>
                <button class="btn btn-primary sm:col-span-1">Create</button>
                <div class="sm:col-span-6"><label class="label">Webhook URL for this site <span class="text-slate-400 font-normal">(optional)</span></label><input name="webhook_url" value="{{ old('webhook_url') }}" maxlength="500" class="input font-mono" placeholder="https://yoursite.com/payment-webhook"></div>
                @error('name')<p class="sm:col-span-6 text-sm text-red-600">{{ $message }}</p>@enderror
                @error('webhook_url')<p class="sm:col-span-6 text-sm text-red-600">{{ $message }}</p>@enderror
            </form>

            <div class="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
                <div class="px-4 py-3 flex items-center justify-between gap-3 bg-slate-50/60">
                    <div><div class="text-sm font-medium text-slate-900">Default key <span class="badge bg-slate-100 text-slate-600">Live</span></div><div class="text-xs text-slate-500 font-mono">{{ $user->api_key }}</div></div>
                    <span class="text-xs text-slate-400">Shown above</span>
                </div>
                @forelse ($namedKeys as $k)
                    <div class="px-4 py-3 space-y-2 {{ $k->isActive() ? '' : 'opacity-60' }}">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-slate-900 truncate">{{ $k->name }}
                                    <span class="badge {{ $k->environment === 'sandbox' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}">{{ ucfirst($k->environment) }}</span>
                                    @unless ($k->isActive())<span class="badge bg-slate-100 text-slate-500">Revoked</span>@endunless</div>
                                <div class="text-xs text-slate-500 font-mono truncate">{{ $k->key }}</div>
                                <div class="text-xs text-slate-400">{{ $k->last_used_at ? 'Last used '.$k->last_used_at->diffForHumans() : 'Never used' }}</div>
                            </div>
                            @if ($k->isActive())
                                <form method="POST" action="{{ route('api-keys.destroy', $k) }}">@csrf @method('DELETE')<button data-confirm="Revoke this key? Requests using it will fail immediately." class="btn btn-danger btn-sm">Revoke</button></form>
                            @endif
                        </div>
                        @if ($k->isActive())
                            <form method="POST" action="{{ route('api-keys.update', $k) }}" class="flex gap-2">@csrf
                                <input name="webhook_url" value="{{ $k->webhook_url }}" maxlength="500" class="input font-mono text-xs" placeholder="Webhook URL (blank = use the main webhook URL)">
                                <button class="btn btn-secondary btn-sm">Save</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div class="px-4 py-4 text-sm text-slate-500">No extra keys yet. Create one for each website above.</div>
                @endforelse
            </div>
        </div>
    </section>

    {{-- Alerts --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Alerts</h2>
            <p class="mt-1 text-sm text-slate-500">Get a Telegram message the moment a payment arrives, and an e-mail summary every morning.</p>
        </div>
        <div class="card p-6 lg:col-span-2">
            <form method="POST" action="{{ route('settings.alerts') }}" class="space-y-4">
                @csrf
                <ol class="text-sm text-slate-600 list-decimal pl-5 space-y-1">
                    <li>In Telegram, open <b>@BotFather</b>, send <span class="kbd">/newbot</span> and copy the token.</li>
                    <li>Open your new bot and press <b>Start</b>.</li>
                    <li>Send any message to <b>@userinfobot</b> to get your numeric chat ID.</li>
                </ol>
                <x-input name="telegram_bot_token" label="Bot token" type="password" autocomplete="off" :value="''" :placeholder="$user->telegram_bot_token ? 'Saved. Paste a new token to replace it' : '123456789:AA...'" class="font-mono" />
                <x-input name="telegram_chat_id" label="Chat ID" :value="$user->telegram_chat_id" placeholder="e.g. 123456789" class="font-mono" />
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="telegram_alerts" value="1" @checked($user->telegram_alerts)> Send Telegram alerts for payments</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="daily_summary" value="1" @checked($user->daily_summary)> E-mail me a daily summary at 8 AM</label>
                @error('telegram')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="flex justify-end gap-2">
                    <button class="btn btn-primary">Save alerts</button>
                </div>
            </form>
            @if ($user->telegram_bot_token && $user->telegram_chat_id)
                <form method="POST" action="{{ route('settings.alerts.test') }}" class="mt-3 flex justify-end">@csrf<button class="btn btn-secondary btn-sm">Send test message</button></form>
            @endif
        </div>
    </section>
</div>
@endsection
