<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%234f46e5'/><path d='M18 5 8 18h7l-1 9 10-13h-7z' fill='white'/></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc', 400: '#818cf8',
                            500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81', 950: '#1e1b4b',
                        },
                    },
                    boxShadow: {
                        card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                        pop: '0 20px 50px -12px rgb(15 23 42 / 0.25)',
                    },
                },
            },
        };
    </script>
    <style type="text/tailwindcss">
        @layer components {
            .btn { @apply inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed; }
            .btn-sm { @apply px-3 py-1.5 text-xs; }
            .btn-lg { @apply px-5 py-3 text-base; }
            .btn-primary { @apply bg-brand-600 text-white shadow-sm hover:bg-brand-700; }
            .btn-secondary { @apply bg-white text-slate-700 ring-1 ring-inset ring-slate-300 shadow-sm hover:bg-slate-50; }
            .btn-dark { @apply bg-slate-900 text-white shadow-sm hover:bg-slate-800; }
            .btn-success { @apply bg-emerald-600 text-white shadow-sm hover:bg-emerald-700; }
            .btn-danger { @apply bg-white text-red-600 ring-1 ring-inset ring-red-200 hover:bg-red-50; }
            .btn-ghost { @apply text-slate-600 hover:bg-slate-100 hover:text-slate-900; }
            .card { @apply bg-white rounded-xl ring-1 ring-slate-200/80 shadow-card; }
            .card-header { @apply flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-100; }
            .card-title { @apply text-sm font-semibold text-slate-900; }
            .label { @apply block text-sm font-medium text-slate-700 mb-1.5; }
            .input { @apply block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-600; }
            .help { @apply mt-1.5 text-xs text-slate-500; }
            .th { @apply px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500; }
            .td { @apply px-5 py-3.5 text-sm; }
            .kbd { @apply font-mono text-[13px] bg-slate-100 text-slate-800 rounded px-1.5 py-0.5; }
        }
    </style>
    @stack('head')
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">
    @yield('body')

    <script>
        // Any element with data-copy="text" copies that text on click.
        document.addEventListener('click', async (e) => {
            const el = e.target.closest('[data-copy]');
            if (!el) return;
            e.preventDefault();
            try { await navigator.clipboard.writeText(el.dataset.copy); } catch (_) { return; }
            const label = el.querySelector('[data-copy-label]') || el;
            const original = label.textContent;
            label.textContent = 'Copied!';
            setTimeout(() => label.textContent = original, 1500);
        });
    </script>
    @stack('scripts')
</body>
</html>
