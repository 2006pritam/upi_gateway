@extends('layouts.app')
@section('title', 'API & Webhooks')
@section('subtitle', 'Set up your website payment connection. Share the details below privately with the person who manages your website.')
@section('actions')
    <a href="{{ route('docs') }}" class="btn btn-secondary"><x-icon name="book" class="w-4 h-4" /> Setup guide</a>
@endsection

@section('content')
<div class="space-y-10">
    {{-- Two clear environments: Live and Sandbox --}}
    <section>
        <div class="mb-5">
            <h2 class="text-base font-semibold text-slate-900">Choose how you want to connect</h2>
            <p class="mt-1 text-sm text-slate-500">Start with Sandbox to test safely. Switch to Live when you are ready to accept real payments. You can copy the connection details here and share them privately with your website developer.</p>
            <div class="mt-3 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                <b class="text-slate-800">Quick guide:</b> Use <b>Test safely first</b> while setting up. Use <b>Accept real payments</b> only when your website is ready. A payment-updates address (webhook URL) is optional—ask your developer for it, or leave it blank for now.
            </div>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="card p-6 space-y-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Accept real payments</h3>
                        <p class="mt-1 text-xs text-slate-500">Use this after your website is ready to go live.</p>
                    </div>
                    <span class="badge bg-emerald-50 text-emerald-700">REAL PAYMENTS</span>
                </div>
                <div>
                    <label class="label">Connection key (API key)</label>
                    <div class="flex gap-2"><input readonly value="{{ $user->api_key }}" class="input font-mono bg-slate-50"><x-copy :text="$user->api_key" /></div>
                </div>
                <div>
                    <label class="label">Private secret (API secret)</label>
                    <div class="flex gap-2">
                        <input id="secret" type="password" readonly value="{{ $user->api_secret }}" class="input font-mono bg-slate-50">
                        <button type="button" class="btn btn-secondary btn-sm" data-toggle-secret><x-icon name="eye" class="w-4 h-4" /> Reveal</button>
                        <x-copy :text="$user->api_secret" />
                    </div>
                    <p class="help">This is like a password for your payment connection. Share it only with your trusted developer—never post it publicly.</p>
                </div>
                <details class="rounded-lg bg-slate-50 px-4 py-3">
                    <summary class="cursor-pointer text-sm font-medium text-slate-700">Need to replace the Live connection details?</summary>
                    <form method="POST" action="{{ route('settings.regenerate') }}" class="mt-3 flex items-center justify-between gap-3 rounded-lg bg-red-50/60 ring-1 ring-red-100 px-4 py-3">
                        @csrf
                        <div class="text-sm text-red-800"><b>Replace Live details</b><div class="text-xs text-red-700/80">The old details stop working immediately. Your developer must update them too.</div></div>
                        <button data-confirm="Old keys will stop working immediately. Continue?" class="btn btn-danger btn-sm"><x-icon name="refresh" class="w-4 h-4" /> Replace</button>
                    </form>
                </details>
                <form method="POST" action="{{ route('settings.webhook') }}" class="border-t border-slate-100 pt-5">
                    @csrf
                    <x-input name="webhook_url" label="Where should we send Live payment updates? (webhook URL)" type="url" :value="$user->webhook_url" placeholder="Ask your developer for this address" class="font-mono" help="Your developer provides this address so your website can be told when a payment succeeds or expires. Leave blank if you do not use payment updates." />
                    <div class="flex justify-end"><button class="btn btn-primary">Save payment updates address</button></div>
                </form>
            </div>

            <div class="card p-6 space-y-5 ring-amber-200">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Test safely first</h3>
                        <p class="mt-1 text-xs text-slate-500">Sandbox payments are pretend; no money moves.</p>
                    </div>
                    <span class="badge bg-amber-100 text-amber-800">TEST ONLY</span>
                </div>
                @if ($user->sandbox_api_key)
                    <div>
                        <label class="label">Connection key (API key)</label>
                        <div class="flex gap-2"><input readonly value="{{ $user->sandbox_api_key }}" class="input font-mono bg-amber-50"><x-copy :text="$user->sandbox_api_key" /></div>
                    </div>
                    @if (session('sandbox_api_secret_once_encrypted'))
                        @php $sandboxSecretOnce = Crypt::decryptString(session('sandbox_api_secret_once_encrypted')); @endphp
                        <div class="rounded-lg bg-amber-50 p-4 ring-1 ring-amber-300">
                            <label class="label">Private secret (API secret) · shown once</label>
                            <div class="flex gap-2"><input readonly value="{{ $sandboxSecretOnce }}" class="input font-mono bg-white"><x-copy :text="$sandboxSecretOnce" /></div>
                            <p class="mt-2 text-xs font-medium text-amber-800">Copy and save this now. It cannot be shown again. Share it only with your trusted developer.</p>
                        </div>
                    @else
                        <p class="text-sm text-slate-500">Your test secret is private and cannot be viewed again. If you did not save it, replace the test details to get a new one.</p>
                    @endif
                    <div class="flex flex-wrap justify-end gap-2">
                        <form method="POST" action="{{ route('settings.sandbox.revoke') }}">@csrf
                            <button data-confirm="Revoke Sandbox credentials? Existing TEST integrations will stop working." class="btn btn-danger btn-sm">Revoke</button>
                        </form>
                        <form method="POST" action="{{ route('settings.sandbox.regenerate') }}">@csrf
                            <button data-confirm="Regenerate Sandbox credentials? The old TEST credentials will stop working." class="btn btn-secondary btn-sm">Replace test details</button>
                        </form>
                    </div>
                @else
                    <p class="text-sm text-slate-600">Create test details to try the payment flow before accepting real payments. Test transactions do not move money.</p>
                    <form method="POST" action="{{ route('settings.sandbox.regenerate') }}" class="flex justify-end">@csrf
                        <button class="btn btn-primary">Create test details</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('settings.sandbox.webhook') }}" class="border-t border-slate-100 pt-5">
                    @csrf
                    <x-input name="sandbox_webhook_url" label="Where should we send test payment updates?" type="url" :value="$user->sandbox_webhook_url" placeholder="Ask your developer for this address" class="font-mono" help="Use the test address supplied by your developer. It must be different from the Live address." />
                    <div class="flex justify-end"><button class="btn btn-primary">Save test updates address</button></div>
                </form>
            </div>
        </div>
    </section>

    <details class="card p-6">
        <summary class="cursor-pointer text-sm font-semibold text-slate-800">Advanced: send webhooks to additional URLs</summary>
        <p class="mt-2 text-sm text-slate-500">Optional. Send each event to extra destinations in addition to the Live and Sandbox URLs above.</p>
        <div class="mt-5 space-y-5">
            @php $endpoints = $user->webhookEndpoints()->orderBy('environment')->orderBy('id')->get(); @endphp
            @forelse ($endpoints as $endpoint)
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-4">
                    <div class="min-w-0">
                        <span class="kbd">{{ $endpoint->environment }}</span>
                        <span class="font-mono text-sm text-slate-700 break-all">{{ $endpoint->url }}</span>
                    </div>
                    <form method="POST" action="{{ route('settings.webhook-endpoints.destroy', $endpoint) }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-secondary">Remove</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-slate-600">No additional webhook URLs configured.</p>
            @endforelse

            <form method="POST" action="{{ route('settings.webhook-endpoints.store') }}" class="border-t border-slate-100 pt-5 space-y-3">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="we_environment">Environment</label>
                    <select id="we_environment" name="environment" class="input">
                        <option value="live" @selected(old('environment') !== 'sandbox')>Live</option>
                        <option value="sandbox" @selected(old('environment') === 'sandbox')>Sandbox</option>
                    </select>
                </div>
                <x-input name="url" label="Additional webhook URL" type="url" :value="old('url')" placeholder="https://another-service.com/upi/webhook" class="font-mono" />
                @error('url')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="flex justify-end"><button class="btn btn-primary">Add webhook URL</button></div>
            </form>
        </div>
    </details>

    <details class="card p-5">
        <summary class="cursor-pointer text-sm font-semibold text-slate-800">Optional: business profile</summary>
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-4">
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
    </details>
    {{-- Saved website/app API keys --}}
    @php $namedKeys = $user->apiKeys()->orderByRaw('revoked_at is not null')->latest('id')->get(); @endphp
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Saved API keys by site</h2>
            <p class="mt-1 text-sm text-slate-500">Every website or app with its own API key is listed here with its environment and status. Each site's key can be revoked independently. Secrets are shown only once when created.</p>
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
                <div class="sm:col-span-3"><label class="label">Website or app name</label><input name="name" value="{{ old('name') }}" maxlength="60" required class="input" placeholder="e.g. Academy website"></div>
                <div class="sm:col-span-2"><label class="label">Use for</label><select name="environment" class="input"><option value="live">Real payments (Live)</option><option value="sandbox">Testing only (Sandbox)</option></select></div>
                <button class="btn btn-primary sm:col-span-1">Create site key</button>
                <div class="sm:col-span-6"><label class="label">Payment-updates address <span class="text-slate-400 font-normal">(optional; ask your developer)</span></label><input name="webhook_url" value="{{ old('webhook_url') }}" maxlength="500" class="input font-mono" placeholder="Enter the address your developer provides"></div>
                @error('name')<p class="sm:col-span-6 text-sm text-red-600">{{ $message }}</p>@enderror
                @error('webhook_url')<p class="sm:col-span-6 text-sm text-red-600">{{ $message }}</p>@enderror
            </form>

            <div class="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
                <div class="px-4 py-3 flex items-center justify-between gap-3 bg-slate-50/60">
                    <div><div class="text-sm font-medium text-slate-900">Default key <span class="badge bg-slate-100 text-slate-600">Live</span></div><div class="text-xs text-slate-500 font-mono">{{ $user->api_key }}</div></div>
                    <span class="text-xs text-slate-400">Shown above</span>
                </div>
                @if ($user->sandbox_api_key)
                    <div class="px-4 py-3 flex items-center justify-between gap-3 bg-amber-50/50">
                        <div><div class="text-sm font-medium text-slate-900">Default key <span class="badge bg-amber-100 text-amber-800">Sandbox</span></div><div class="text-xs text-slate-500 font-mono">{{ $user->sandbox_api_key }}</div></div>
                        <span class="text-xs text-slate-400">Shown above</span>
                    </div>
                @endif
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

    <details class="card p-5">
        <summary class="cursor-pointer text-sm font-semibold text-slate-800">Optional: payment notifications</summary>
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-4">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Payment notifications</h2>
            <p class="mt-1 text-sm text-slate-500">Optional: receive a Telegram message when a payment arrives, or an email summary each morning.</p>
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
    </details>
</div>
@endsection
