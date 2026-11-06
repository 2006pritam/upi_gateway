<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install · {{ config('app.name', 'UPI Gateway') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%234f46e5'/><path d='M18 5 8 18h7l-1 9 10-13h-7z' fill='white'/></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                        mono: ['JetBrains Mono', 'ui-monospace'],
                    },
                    colors: {
                        brand: { 50:'#eef2ff',100:'#e0e7ff',200:'#c7d2fe',300:'#a5b4fc',400:'#818cf8',500:'#6366f1',600:'#4f46e5',700:'#4338ca',800:'#3730a3',900:'#312e81',950:'#1e1b4b' },
                    },
                },
            },
        };
    </script>
    <style type="text/tailwindcss">
        @layer components {
            .btn { @apply inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:opacity-50; }
            .btn-primary { @apply bg-brand-600 text-white hover:bg-brand-700; }
            .btn-secondary { @apply bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50; }
            .input { @apply block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-brand-600; }
            .label { @apply block text-sm font-medium text-slate-700 mb-1.5; }
        }
    </style>
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased font-sans">

<div class="min-h-full flex flex-col items-center justify-center p-4">

    {{-- Logo --}}
    <div class="mb-8 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-brand-600 flex items-center justify-center">
            <svg viewBox="0 0 32 32" class="w-6 h-6"><path d="M18 5 8 18h7l-1 9 10-13h-7z" fill="white"/></svg>
        </div>
        <span class="text-xl font-bold text-slate-900">{{ config('app.name', 'UPI Gateway') }}</span>
    </div>

    {{-- Steps --}}
    <div class="flex items-center gap-2 mb-8">
        @foreach ([1 => 'Requirements', 2 => 'Database', 3 => 'Migrate', 4 => 'Admin'] as $n => $label)
            <div class="flex items-center gap-2">
                <div @class([
                    'w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold',
                    'bg-brand-600 text-white' => $step === $n,
                    'bg-emerald-500 text-white' => $step > $n,
                    'bg-slate-200 text-slate-500' => $step < $n,
                ])>
                    @if($step > $n) ✓ @else {{ $n }} @endif
                </div>
                <span @class(['text-xs font-medium', 'text-brand-600' => $step === $n, 'text-slate-400' => $step !== $n])>{{ $label }}</span>
            </div>
            @if($n < 4)<div class="w-8 h-px bg-slate-300"></div>@endif
        @endforeach
    </div>

    {{-- Card --}}
    <div class="w-full max-w-lg bg-white rounded-2xl shadow-lg ring-1 ring-slate-200 p-8">
        @yield('content')
    </div>

</div>

@stack('scripts')
</body>
</html>
