<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Financial statements</title>
<style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; }
    h1 { font-size: 18px; margin: 0; } h2 { font-size: 14px; margin: 18px 0 4px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 4px 6px; border-bottom: 1px solid #eee; text-align: left; }
    .n { text-align: right; } .t td { font-weight: bold; border-top: 1px solid #999; } .muted { color: #555; }
</style></head>
<body>
@php($m = fn (int $c): string => ($c < 0 ? '(' : '').'$'.number_format(abs($c) / 100, 2).($c < 0 ? ')' : ''))
<h1>{{ $brokerage }}</h1>
<p class="muted">Profit and loss, {{ $pnl['from'] }} to {{ $pnl['to'] }}, with the same period last year</p>
<table>
    <tr><th>Income</th><th class="n">This period</th><th class="n">Last year</th></tr>
    @foreach ($pnl['revenue'] as $r)<tr><td>{{ $r['code'] }} {{ $r['name'] }}</td><td class="n">{{ $m($r['cents']) }}</td><td class="n">{{ $m($r['prior_cents']) }}</td></tr>@endforeach
    <tr class="t"><td>Total income</td><td class="n">{{ $m($pnl['revenue_cents']) }}</td><td class="n">{{ $m($pnl['prior_revenue_cents']) }}</td></tr>
    <tr><th>Expenses</th><th></th><th></th></tr>
    @foreach ($pnl['expenses'] as $r)<tr><td>{{ $r['code'] }} {{ $r['name'] }}</td><td class="n">{{ $m($r['cents']) }}</td><td class="n">{{ $m($r['prior_cents']) }}</td></tr>@endforeach
    <tr class="t"><td>Total expenses</td><td class="n">{{ $m($pnl['expense_cents']) }}</td><td class="n">{{ $m($pnl['prior_expense_cents']) }}</td></tr>
    <tr class="t"><td>Net profit</td><td class="n">{{ $m($pnl['net_cents']) }}</td><td class="n">{{ $m($pnl['prior_net_cents']) }}</td></tr>
</table>
<h2>Balance sheet as of {{ $balance['as_of'] }}</h2>
<table>
    @foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $k => $label)
        <tr><th>{{ $label }}</th><th></th></tr>
        @foreach ($balance[$k] as $r)<tr><td>{{ trim($r['code'].' '.$r['name']) }}</td><td class="n">{{ $m($r['cents']) }}</td></tr>@endforeach
        <tr class="t"><td>Total {{ strtolower($label) }}</td><td class="n">{{ $m($balance[$k.'_cents']) }}</td></tr>
    @endforeach
</table>
@isset($closed)
<h2>Trial balance as of {{ $balance['as_of'] }}</h2>
<table>
    <tr><th>Account</th><th class="n">Debit</th><th class="n">Credit</th></tr>
    @foreach ($closed['trial_balance'] as $r)<tr><td>{{ $r['code'] }} {{ $r['name'] }}</td><td class="n">{{ $r['debit_cents'] ? $m($r['debit_cents']) : '' }}</td><td class="n">{{ $r['credit_cents'] ? $m($r['credit_cents']) : '' }}</td></tr>@endforeach
    <tr class="t"><td>Totals</td><td class="n">{{ $m(array_sum(array_column($closed['trial_balance'], 'debit_cents'))) }}</td><td class="n">{{ $m(array_sum(array_column($closed['trial_balance'], 'credit_cents'))) }}</td></tr>
</table>
<p class="muted">As closed on {{ $closed['at'] }}. Fingerprint (SHA-256): {{ $closed['sha256'] }} — {{ $closed['intact'] ? 'unchanged since the close' : 'DOES NOT MATCH: contact RightAlly support' }}.</p>
@endisset
<p class="muted">Prepared from RightAlly's books. Unaudited.</p>
</body></html>
