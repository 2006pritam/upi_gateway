@extends('layouts.base')
@section('title', 'API Documentation')

@php $base = url('/api/v1'); @endphp

@push('head')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<style>
    html { scroll-behavior: smooth; scroll-padding-top: 5rem; }
    .hljs { background: transparent !important; padding: 0 !important; }
    .nav-link.active { color: #4f46e5; border-left-color: #4f46e5; font-weight: 600; }
    .section-anchor { scroll-margin-top: 5rem; }
</style>
@endpush

@section('body')
<div class="bg-white min-h-full">
    @include('layouts.public-nav')

    {{-- Hero --}}
    <div class="border-b border-slate-200 bg-gradient-to-br from-slate-950 via-brand-950 to-slate-900">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14">
            <div class="flex items-center gap-2 mb-4">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-500/20 px-3 py-1 text-xs font-semibold text-brand-300 ring-1 ring-brand-500/30">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    v1 · Stable
                </span>
            </div>
            <h1 class="text-4xl sm:text-5xl font-bold tracking-tight text-white">API Reference</h1>
            <p class="mt-4 text-lg text-slate-400 max-w-2xl">Integrate UPI payments into your app in minutes. Create orders, redirect customers, and receive real-time webhooks.</p>
            <div class="mt-6 inline-flex items-center gap-3 rounded-xl bg-white/5 ring-1 ring-white/10 px-4 py-3">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Base URL</span>
                <code class="font-mono text-sm text-slate-200">{{ $base }}</code>
                <x-copy :text="$base" />
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-10 lg:py-14 grid lg:grid-cols-[240px_1fr] gap-12">

        {{-- Sidebar --}}
        <aside class="hidden lg:block">
            <nav class="sticky top-24 space-y-6 text-sm" id="docs-nav">
                @foreach ([
                    'Getting Started' => ['intro' => 'Introduction', 'auth' => 'Authentication'],
                    'Orders' => ['create' => 'Create order', 'status' => 'Get order status', 'object' => 'Order object'],
                    'UPI Accounts' => ['accounts' => 'List accounts', 'rotation' => 'Rotation & limits'],
                    'Webhooks' => ['webhooks' => 'Events', 'verify' => 'Verify signature'],
                    'Reference' => ['errors' => 'Error codes', 'sdks' => 'Code examples'],
                ] as $group => $links)
                    <div>
                        <div class="text-[11px] font-bold uppercase tracking-widest text-slate-400 mb-2 px-3">{{ $group }}</div>
                        <ul class="space-y-0.5">
                            @foreach ($links as $id => $label)
                                <li>
                                    <a href="#{{ $id }}" class="nav-link -ml-px flex items-center gap-2 border-l-2 border-transparent pl-3 py-1.5 text-slate-500 hover:border-slate-300 hover:text-slate-900 transition-colors rounded-r-md hover:bg-slate-50">
                                        {{ $label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </aside>

        {{-- Content --}}
        <article class="min-w-0 max-w-3xl space-y-20 text-[15px] leading-relaxed text-slate-700">

            {{-- Introduction --}}
            <section id="intro" class="section-anchor">
                <div class="flex items-center gap-2 mb-3">
                    <span class="text-xs font-bold uppercase tracking-widest text-brand-600">Getting Started</span>
                </div>
                <h2 class="text-3xl font-bold tracking-tight text-slate-900">Introduction</h2>
                <p class="mt-4 text-slate-600">The {{ config('app.name') }} API lets you create UPI payment orders, redirect customers to a hosted checkout, and receive signed webhooks when payments complete. All API calls are server-to-server — never expose your secret in a browser or mobile app.</p>

                <div class="mt-6 grid sm:grid-cols-3 gap-4">
                    @foreach ([
                        ['🔒', 'Secure', 'HMAC-SHA256 signed webhooks. Credentials encrypted at rest.'],
                        ['⚡', 'Real-time', 'Instant payment confirmation via polling or webhooks.'],
                        ['🔄', 'Auto-rotation', 'Distribute load across multiple UPI accounts automatically.'],
                    ] as [$icon, $title, $desc])
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-2xl mb-2">{{ $icon }}</div>
                            <div class="font-semibold text-slate-900 text-sm">{{ $title }}</div>
                            <p class="mt-1 text-xs text-slate-500 leading-relaxed">{{ $desc }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Authentication --}}
            <section id="auth" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Authentication</h2>
                <p class="mt-3 text-slate-600">Every request must include your API key and secret as headers. Find them in <span class="font-semibold text-slate-800">Dashboard → API &amp; Webhooks</span>.</p>

                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 flex gap-3">
                    <span class="text-amber-500 mt-0.5">⚠</span>
                    <p class="text-sm text-amber-800">Never expose your <code class="kbd">X-Api-Secret</code> in client-side code, mobile apps, or public repositories.</p>
                </div>

                @include('docs._code', ['title' => 'Required headers', 'lang' => 'http', 'code' => "X-Api-Key: key_xxxxxxxxxxxxxxxx
X-Api-Secret: sec_xxxxxxxxxxxxxxxx
Accept: application/json
Content-Type: application/json"])
            </section>

            {{-- Create Order --}}
            <section id="create" class="section-anchor">
                <div class="flex items-center gap-3 mb-1">
                    <span class="rounded-md bg-emerald-100 px-2.5 py-1 font-mono text-xs font-bold text-emerald-700 tracking-wide">POST</span>
                    <code class="text-sm text-slate-500 font-mono">/api/v1/orders</code>
                </div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Create order</h2>
                <p class="mt-3 text-slate-600">Creates a pending order and returns a <code class="kbd">payment_url</code>. Redirect your customer there. For standard UPI accounts, a <code class="kbd">upi_intent</code> deep-link is also returned so you can render your own QR.</p>

                <div class="mt-6 rounded-xl border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-5 py-3 border-b border-slate-200">
                        <span class="text-xs font-bold uppercase tracking-widest text-slate-500">Request body</span>
                    </div>
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-white"><tr>
                            <th class="th w-44">Parameter</th>
                            <th class="th w-20">Type</th>
                            <th class="th">Description</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach ([
                                ['amount', 'number', true, 'Amount in INR (₹1 – ₹1,00,000). Up to 2 decimal places.'],
                                ['client_order_id', 'string', true, 'Your unique order ID. Allowed: A–Z, 0–9, _ and -. Max 64 chars.'],
                                ['redirect_url', 'url', false, 'Customer is redirected here after payment with order_id, client_order_id and status as query params.'],
                                ['customer_name', 'string', false, 'Shown on the checkout page.'],
                                ['customer_email', 'email', false, 'Customer email address.'],
                                ['customer_mobile', 'string', false, 'Customer mobile number.'],
                                ['note', 'string', false, 'Shown in the customer\'s UPI app. Max 50 chars.'],
                                ['payment_account_id', 'integer', false, 'Pin to a specific UPI account. Falls back to rotation if the account is full or inactive.'],
                            ] as [$name, $type, $required, $desc])
                                <tr class="hover:bg-slate-50/50">
                                    <td class="td align-top">
                                        <code class="font-mono text-[13px] font-semibold text-slate-900">{{ $name }}</code>
                                        @if ($required)
                                            <span class="ml-1.5 text-[10px] font-bold uppercase tracking-wide text-red-500">required</span>
                                        @else
                                            <span class="ml-1.5 text-[10px] font-medium uppercase tracking-wide text-slate-400">optional</span>
                                        @endif
                                    </td>
                                    <td class="td align-top font-mono text-xs text-slate-400">{{ $type }}</td>
                                    <td class="td align-top text-slate-600 text-[13px]">{{ $desc }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('docs._code', ['title' => 'cURL', 'lang' => 'bash', 'code' => "curl -X POST {$base}/orders \\
  -H \"X-Api-Key: key_xxx\" \\
  -H \"X-Api-Secret: sec_xxx\" \\
  -H \"Accept: application/json\" \\
  -H \"Content-Type: application/json\" \\
  -d '{\"amount\": 199, \"client_order_id\": \"INV1001\", \"redirect_url\": \"https://yoursite.com/thanks\"}'"])

                @include('docs._code', ['title' => 'Response · 201 Created', 'lang' => 'json', 'code' => '{
  "success": true,
  "data": {
    "order_id": "ORDAB12CD34EF56GH78",
    "client_order_id": "INV1001",
    "amount": "199.00",
    "currency": "INR",
    "status": "pending",
    "payment_url": "'.url('/pay/ORDAB12CD34EF56GH78').'",
    "upi_intent": "upi://pay?pa=merchant@upi&am=199.00&tr=ORDAB12CD34EF56GH78",
    "expires_at": "2026-01-01T12:15:00+05:30"
  }
}'])
            </section>

            {{-- Get Status --}}
            <section id="status" class="section-anchor">
                <div class="flex items-center gap-3 mb-1">
                    <span class="rounded-md bg-sky-100 px-2.5 py-1 font-mono text-xs font-bold text-sky-700 tracking-wide">GET</span>
                    <code class="text-sm text-slate-500 font-mono">/api/v1/orders/{order_id | client_order_id}</code>
                </div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Get order status</h2>
                <p class="mt-3 text-slate-600">Returns the latest order state, checking the payment provider if the order is still pending. <span class="font-semibold text-slate-800">Always confirm with this endpoint or a verified webhook before fulfilling an order</span> — never trust the redirect URL alone.</p>

                @include('docs._code', ['title' => 'cURL', 'lang' => 'bash', 'code' => "curl {$base}/orders/INV1001 \\
  -H \"X-Api-Key: key_xxx\" \\
  -H \"X-Api-Secret: sec_xxx\" \\
  -H \"Accept: application/json\""])

                @include('docs._code', ['title' => 'Response · 200 OK', 'lang' => 'json', 'code' => '{
  "success": true,
  "data": {
    "order_id": "ORDAB12CD34EF56GH78",
    "client_order_id": "INV1001",
    "amount": "199.00",
    "currency": "INR",
    "status": "paid",
    "utr": "427123456789",
    "paid_at": "2026-01-01T12:10:00+05:30"
  }
}'])
            </section>

            {{-- Order Object --}}
            <section id="object" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Order object</h2>
                <p class="mt-3 text-slate-600">An order moves through these statuses:</p>
                <div class="mt-4 grid sm:grid-cols-3 gap-3">
                    @foreach ([
                        'pending' => ['Waiting for the customer to pay. Expires after 15 minutes.', 'bg-amber-50 border-amber-200'],
                        'paid'    => ['Payment confirmed. The utr field contains the bank reference.', 'bg-emerald-50 border-emerald-200'],
                        'expired' => ['Order was not paid before expires_at.', 'bg-slate-50 border-slate-200'],
                    ] as $s => [$d, $cls])
                        <div class="rounded-xl border {{ $cls }} p-4">
                            <x-status :status="$s" />
                            <p class="mt-2 text-xs text-slate-600 leading-relaxed">{{ $d }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- List Accounts --}}
            <section id="accounts" class="section-anchor">
                <div class="flex items-center gap-3 mb-1">
                    <span class="rounded-md bg-sky-100 px-2.5 py-1 font-mono text-xs font-bold text-sky-700 tracking-wide">GET</span>
                    <code class="text-sm text-slate-500 font-mono">/api/v1/accounts</code>
                </div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">List UPI accounts</h2>
                <p class="mt-3 text-slate-600">Returns your active UPI accounts with their IDs, daily limits, and remaining capacity. Use the <code class="kbd">id</code> as <code class="kbd">payment_account_id</code> when creating an order to pin it to a specific account.</p>

                @include('docs._code', ['title' => 'Response · 200 OK', 'lang' => 'json', 'code' => '{
  "success": true,
  "rotation_mode": "random",
  "data": [
    {
      "id": 3,
      "label": "Shop 1",
      "upi_id": "merchant@paytm",
      "provider": "paytm_business",
      "is_active": true,
      "daily_limit": "100000.00",
      "used_today": "42500.00",
      "remaining_today": "57500.00"
    }
  ]
}'])
            </section>

            {{-- Rotation --}}
            <section id="rotation" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Rotation &amp; daily limits</h2>
                <p class="mt-3 text-slate-600">When you omit <code class="kbd">payment_account_id</code>, the gateway automatically picks an account based on your rotation mode:</p>
                <div class="mt-4 space-y-3">
                    <div class="flex gap-3 rounded-xl border border-slate-200 p-4">
                        <span class="text-lg">🎲</span>
                        <div><div class="font-semibold text-slate-900 text-sm">Random rotation <span class="ml-1 text-xs font-normal text-slate-400">(default)</span></div><p class="mt-0.5 text-sm text-slate-600">A random account among those with remaining daily capacity.</p></div>
                    </div>
                    <div class="flex gap-3 rounded-xl border border-slate-200 p-4">
                        <span class="text-lg">⭐</span>
                        <div><div class="font-semibold text-slate-900 text-sm">Default account first</div><p class="mt-0.5 text-sm text-slate-600">Your default account is used until it hits its daily limit, then falls back to others.</p></div>
                    </div>
                </div>
                <p class="mt-4 text-slate-600">Each account has a daily limit (₹1,00,000 by default). Usage includes paid orders from today plus open pending orders. If no account has capacity, the API returns <code class="kbd">422</code> with an <code class="kbd">amount</code> error.</p>
            </section>

            {{-- Webhooks --}}
            <section id="webhooks" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Webhook events</h2>
                <p class="mt-3 text-slate-600">Configure your endpoint in <span class="font-semibold text-slate-800">Dashboard → API &amp; Webhooks</span>. We send a signed <code class="kbd">POST</code> request for each event. Respond with any <code class="kbd">2xx</code> status; failed deliveries are retried up to 6 times with exponential backoff.</p>

                <div class="mt-4 rounded-xl border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-5 py-3 border-b border-slate-200">
                        <span class="text-xs font-bold uppercase tracking-widest text-slate-500">Events</span>
                    </div>
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <tbody class="divide-y divide-slate-50">
                            @foreach ([
                                ['order.paid', 'emerald', 'Payment confirmed. Safe to fulfil the order.'],
                                ['order.expired', 'slate', 'Order was not paid before it expired.'],
                            ] as [$event, $color, $desc])
                                <tr class="hover:bg-slate-50/50">
                                    <td class="td w-44"><code class="font-mono text-[13px] font-semibold text-slate-900">{{ $event }}</code></td>
                                    <td class="td text-slate-600 text-[13px]">{{ $desc }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 flex gap-3">
                    <span class="text-blue-500 mt-0.5">ℹ</span>
                    <p class="text-sm text-blue-800">The same event may be delivered more than once. Always handle webhooks <span class="font-semibold">idempotently</span> using <code class="font-mono text-xs bg-blue-100 px-1 rounded">client_order_id</code>.</p>
                </div>

                @include('docs._code', ['title' => 'Payload · order.paid', 'lang' => 'json', 'code' => '{
  "event": "order.paid",
  "data": {
    "order_id": "ORDAB12CD34EF56GH78",
    "client_order_id": "INV1001",
    "amount": "199.00",
    "currency": "INR",
    "status": "paid",
    "utr": "4271234567890",
    "paid_at": "2026-01-01T12:10:00+05:30"
  }
}'])

                @include('docs._code', ['title' => 'Payload · order.expired', 'lang' => 'json', 'code' => '{
  "event": "order.expired",
  "data": {
    "order_id": "ORDAB12CD34EF56GH78",
    "client_order_id": "INV1001",
    "amount": "199.00",
    "currency": "INR",
    "status": "expired",
    "expires_at": "2026-01-01T12:15:00+05:30"
  }
}'])
            </section>

            {{-- Verify Signature --}}
            <section id="verify" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Verify signature</h2>
                <p class="mt-3 text-slate-600">Every webhook includes an <code class="kbd">X-Signature</code> header containing the HMAC-SHA256 of the raw request body, keyed with your API secret. Always verify this before processing.</p>

                @php
                $phpVerifyCode = implode("\n", [
                    '<?php',
                    '$body     = file_get_contents("php://input");',
                    '$expected = hash_hmac("sha256", $body, "sec_xxx");',
                    '$given    = $_SERVER["HTTP_X_SIGNATURE"] ?? "";',
                    '',
                    'if (!hash_equals($expected, $given)) {',
                    '    http_response_code(401);',
                    '    exit;',
                    '}',
                    '',
                    '$event = json_decode($body, true);',
                    'if ($event["event"] === "order.paid") {',
                    '    // Fulfil $event["data"]["client_order_id"] (idempotently)',
                    '}',
                    'http_response_code(200);',
                ]);
                @endphp
                @include('docs._code', ['title' => 'PHP', 'lang' => 'php', 'code' => $phpVerifyCode])

                @php
                $nodeCode = implode("\n", [
                    'const crypto = require("crypto");',
                    '',
                    'app.post("/webhook", express.raw({ type: "application/json" }), (req, res) => {',
                    '  const expected = crypto',
                    '    .createHmac("sha256", process.env.UPI_SECRET)',
                    '    .update(req.body)',
                    '    .digest("hex");',
                    '',
                    '  const given = req.get("X-Signature") || "";',
                    '  if (given.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(given))) {',
                    '    return res.sendStatus(401);',
                    '  }',
                    '',
                    '  const event = JSON.parse(req.body);',
                    '  if (event.event === "order.paid") {',
                    '    // Fulfil event.data.client_order_id (idempotently)',
                    '  }',
                    '  res.sendStatus(200);',
                    '});',
                ]);
                @endphp
                @include('docs._code', ['title' => 'Node.js', 'lang' => 'javascript', 'code' => $nodeCode])
            </section>

            {{-- Errors --}}
            <section id="errors" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Error codes</h2>
                <p class="mt-3 text-slate-600">All errors return a JSON body with a <code class="kbd">message</code> field. Validation errors (<code class="kbd">422</code>) also include an <code class="kbd">errors</code> object keyed by field name.</p>
                <div class="mt-4 rounded-xl border border-slate-200 overflow-hidden">
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <thead class="bg-slate-50"><tr>
                            <th class="th w-24">Status</th>
                            <th class="th">Meaning</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach ([
                                ['401', 'red',    'Missing or invalid API key / secret.'],
                                ['403', 'orange', 'Merchant account is disabled.'],
                                ['404', 'slate',  'Order not found.'],
                                ['422', 'amber',  'Validation failed — see the errors object for field-level details.'],
                                ['429', 'purple', 'Rate limit exceeded (120 requests / minute).'],
                                ['500', 'red',    'Unexpected server error. Contact support if this persists.'],
                            ] as [$code, $color, $meaning])
                                <tr class="hover:bg-slate-50/50">
                                    <td class="td"><code class="font-mono font-bold text-slate-900">{{ $code }}</code></td>
                                    <td class="td text-slate-600 text-[13px]">{{ $meaning }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- SDKs / Code Examples --}}
            <section id="sdks" class="section-anchor">
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Code examples</h2>
                <p class="mt-3 text-slate-600">Quick-start snippets for common server-side languages.</p>

                @include('docs._code', ['title' => 'Python (requests)', 'lang' => 'python', 'code' => "import requests, hmac, hashlib

headers = {
    'X-Api-Key': 'key_xxx',
    'X-Api-Secret': 'sec_xxx',
    'Accept': 'application/json',
    'Content-Type': 'application/json',
}

res = requests.post(
    '". $base ."/orders',
    json={'amount': 199, 'client_order_id': 'INV1001'},
    headers=headers,
    timeout=10,
)
data = res.json()
payment_url = data['data']['payment_url']"])

                @php
                $guzzleCode = implode("\n", [
                    '<?php',
                    '$client = new GuzzleHttp\\Client(["base_uri" => "' . $base . '"]);',
                    '',
                    '$res = $client->post("/orders", [',
                    '    "headers" => [',
                    '        "X-Api-Key"    => "key_xxx",',
                    '        "X-Api-Secret" => "sec_xxx",',
                    '        "Accept"       => "application/json",',
                    '    ],',
                    '    "json" => ["amount" => 199, "client_order_id" => "INV1001"],',
                    ']);',
                    '',
                    '$data = json_decode($res->getBody(), true);',
                    '$paymentUrl = $data["data"]["payment_url"];',
                ]);
                @endphp
                @include('docs._code', ['title' => 'PHP (Guzzle)', 'lang' => 'php', 'code' => $guzzleCode])
            </section>

        </article>
    </div>
</div>

@push('scripts')
<script>
    // Syntax highlight
    document.querySelectorAll('pre code').forEach(el => hljs.highlightElement(el));

    // Active nav link on scroll
    const sections = document.querySelectorAll('.section-anchor');
    const links = document.querySelectorAll('#docs-nav .nav-link');
    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                links.forEach(l => l.classList.remove('active'));
                const active = document.querySelector(`#docs-nav a[href="#${e.target.id}"]`);
                if (active) active.classList.add('active');
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });
    sections.forEach(s => observer.observe(s));
</script>
@endpush
@endsection
