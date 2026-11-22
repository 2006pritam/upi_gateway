<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR poster · {{ $link->title }}</title>
<style>
 body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#f1f5f9;color:#0f172a}
 .sheet{width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:22mm 18mm;box-sizing:border-box;text-align:center;display:flex;flex-direction:column;align-items:center}
 h1{font-size:34px;margin:0 0 4px}.sub{color:#475569;font-size:18px}
 .qr{margin:28px 0 10px;border:6px solid #0f172a;border-radius:20px;padding:16px;width:360px;height:360px}.qr svg{width:100%;height:100%}
 .amt{font-size:44px;font-weight:800;margin:6px 0}.scan{font-size:24px;font-weight:700;margin-top:10px}
 .apps{color:#475569;margin-top:8px}.url{margin-top:26px;font:14px ui-monospace,monospace;color:#334155;word-break:break-all}
 .foot{margin-top:auto;color:#94a3b8;font-size:13px}
 .bar{max-width:210mm;margin:12px auto;text-align:right}.bar button{padding:10px 18px;border:0;border-radius:8px;background:#4f46e5;color:#fff;font-size:15px;cursor:pointer}
 @media print{body{background:#fff}.bar{display:none}.sheet{width:auto;min-height:auto;padding:10mm}}
</style></head><body>
<div class="bar"><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="sheet">
  <h1>{{ $link->user->displayName() }}</h1>
  <div class="sub">{{ $link->title }}</div>
  <div class="qr">{!! $qr !!}</div>
  @if ($link->isFixedAmount())<div class="amt">₹{{ number_format((float) $link->amount, 2) }}</div>@endif
  <div class="scan">Scan to pay</div>
  <div class="apps">Opens in any camera or UPI app · GPay · PhonePe · Paytm · BHIM</div>
  <div class="url">{{ $link->publicUrl() }}</div>
  <div class="foot">Secured by {{ config('app.name') }}</div>
</div>
</body></html>
