<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Invoice {{ $inv['number'] }}</title>
<style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    .muted { color: #555; }
    table { width: 100%; border-collapse: collapse; margin-top: 18px; }
    th, td { padding: 8px 6px; border-bottom: 1px solid #ddd; text-align: left; }
    td.amt, th.amt { text-align: right; }
    .total td { font-weight: bold; font-size: 14px; border-bottom: 0; }
    .head { width: 100%; }
    .head td { border: 0; vertical-align: top; padding: 0; }
</style>
</head>
<body>
<table class="head"><tr>
    <td><h1>{{ $brokerage }}</h1><div class="muted">Invoice</div></td>
    <td style="text-align:right"><strong>Invoice {{ $inv['number'] }}</strong><br>Issued {{ $inv['issue_date'] }}<br>Due {{ $inv['due_date'] }}@if ($inv['status'] === 'paid')<br><strong>Paid {{ $inv['paid_on'] }}</strong>@endif</td>
</tr></table>
<p style="margin-top:18px"><span class="muted">Bill to</span><br><strong>{{ $inv['customer'] }}</strong>@if ($inv['customer_address'])<br>{!! nl2br(e($inv['customer_address'])) !!}@endif @if ($inv['customer_email'])<br>{{ $inv['customer_email'] }}@endif</p>
<table>
    <thead><tr><th>Description</th><th class="amt">Amount</th></tr></thead>
    <tbody>
        @foreach ($inv['lines'] as $l)<tr><td>{{ $l['description'] }}</td><td class="amt">${{ number_format($l['cents'] / 100, 2) }}</td></tr>@endforeach
        <tr class="total"><td>Total</td><td class="amt">${{ number_format($inv['total_cents'] / 100, 2) }}</td></tr>
    </tbody>
</table>
@if ($inv['memo'])<p class="muted">{{ $inv['memo'] }}</p>@endif
<p class="muted" style="margin-top:24px">Please include the invoice number with your payment. Thank you.</p>
</body>
</html>
