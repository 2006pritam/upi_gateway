<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script nonce="{{ $csp_nonce }}">
        try { if (localStorage.getItem('upi_checkout_theme') === 'dark') document.documentElement.classList.add('upi-theme-dark'); } catch (e) {}
    </script>
    <title>@yield('title', 'Dashboard') · {{ config('app.brand_name', 'eMudra') }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    {{-- Google Fonts: Inter (sans) + JetBrains Mono (code) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">
    @yield('body')
    <x-theme-toggle />
    @stack('scripts')
</body>
</html>
