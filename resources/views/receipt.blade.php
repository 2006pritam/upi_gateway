<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<script nonce="{{ $csp_nonce }}">try { if (localStorage.getItem('upi_checkout_theme') === 'dark') document.documentElement.classList.add('upi-theme-dark'); } catch (e) {}</script>
<title>Receipt {{ $order->order_no }}</title>
<link rel="icon" type="image/svg+xml" href="{{ asset('images/emudra-mark.svg') }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f1f5f9;margin:0;color:#0f172a}
 .r{max-width:520px;margin:24px auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
 h1{font-size:20px;margin:0}.ok{display:inline-block;margin:10px 0;background:#d1fae5;color:#065f46;border-radius:99px;padding:3px 12px;font-size:13px;font-weight:600}
 .amt{font-size:38px;font-weight:800;margin:6px 0 18px}table{width:100%;border-collapse:collapse;font-size:14px}td{padding:9px 0;border-top:1px solid #e2e8f0}td:last-child{text-align:right;font-weight:600;word-break:break-all}td:first-child{color:#64748b}
 .foot{margin-top:18px;font-size:12px;color:#94a3b8;text-align:center}.bar{text-align:center;margin:12px}button{padding:9px 18px;border:0;border-radius:8px;background:#4f46e5;color:#fff;cursor:pointer}
 html.upi-theme-dark body{background:#0b1120;color:#e2e8f0}
 html.upi-theme-dark .r{background:#111c2e;color:#e2e8f0}
 html.upi-theme-dark td{border-color:#2b394d}
 html.upi-theme-dark td:first-child{color:#94a3b8}
 @media print{body{background:#fff}.bar,.site-theme-toggle{display:none!important}.r{box-shadow:none;margin:0}html.upi-theme-dark body{background:#fff;color:#0f172a}html.upi-theme-dark .r{background:#fff;color:#0f172a;box-shadow:none}html.upi-theme-dark td{border-color:#e2e8f0}html.upi-theme-dark td:first-child{color:#64748b}}
</style></head><body>
<div class="bar"><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="r">
  <h1>{{ $order->user->displayName() }}</h1>
  <span class="ok">Payment received</span>
  <div class="amt">₹{{ number_format((float) $order->amount, 2) }}</div>
  <table>
    <tr><td>Receipt for</td><td>{{ $order->note ?: $order->client_order_id }}</td></tr>
    <tr><td>Order ID</td><td>{{ $order->order_no }}</td></tr>
    @if ($order->customer_name)<tr><td>Paid by</td><td>{{ $order->customer_name }}</td></tr>@endif
    <tr><td>Date</td><td>{{ $order->paid_at?->format('d M Y, h:i A') }}</td></tr>
    @if ($order->utr)<tr><td>UTR / reference</td><td>{{ $order->utr }}</td></tr>@endif
    <tr><td>Method</td><td>UPI</td></tr>
  </table>
  <div class="foot">Computer-generated receipt · Powered by {{ config('app.brand_name', 'eMudra') }}</div>
</div>
<x-theme-toggle />
</body></html>
