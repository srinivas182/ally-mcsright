<!doctype html>
<html><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; } h1 { font-size: 16px; } h2 { font-size: 13px; margin-top: 14px; }
table { width: 100%; border-collapse: collapse; } td, th { padding: 3px; border-bottom: 1px solid #ddd; text-align: left; } .r { text-align: right; }
</style></head><body>
@php($m = fn ($c) => ($c < 0 ? '−' : '').'$'.number_format(abs((int) $c) / 100, 2))
<h1>{{ $brokerage }} — three-way trust reconciliation, {{ \Carbon\CarbonImmutable::parse($s['month'].'-01')->format('F Y') }}</h1>
<table>
<tr><td>Bank statement ending balance</td><td class="r">{{ $m($s['bank_cents']) }}</td></tr>
<tr><td>Adjusted bank balance (plus deposits in transit, less outstanding withdrawals)</td><td class="r">{{ $m($s['adjusted_bank_cents']) }}</td></tr>
<tr><td>Book balance (trust cash in the ledger)</td><td class="r">{{ $m($s['book_cents']) }}</td></tr>
<tr><td>Client balances (total held for deals)</td><td class="r">{{ $m($s['clients_cents']) }}</td></tr>
<tr><td><strong>Result</strong></td><td class="r"><strong>{{ $s['balanced'] ? 'Balanced' : 'NOT balanced' }}</strong></td></tr>
</table>
<h2>Outstanding items</h2>
<table><tr><th>Date</th><th>Description</th><th class="r">Amount</th></tr>
@forelse ($s['outstanding'] as $o)<tr><td>{{ $o['date'] }}</td><td>{{ $o['description'] }}</td><td class="r">{{ $m($o['amount']) }}</td></tr>
@empty<tr><td colspan="3">None</td></tr>@endforelse
</table>
<h2>Client (deal) balances</h2>
<table><tr><th>Deal</th><th class="r">Held</th></tr>
@foreach ($s['clients'] as $c)<tr><td>{{ $c['deal'] }}{{ $c['negative'] ? ' — NEGATIVE' : '' }}{{ $c['stale'] ? ' — held after closing' : '' }}</td><td class="r">{{ $m($c['cents']) }}</td></tr>@endforeach
</table>
<p>Prepared by {{ $s['prepared_by'] ?? '—' }} · Approved by {{ $s['approved_by'] ?? 'not yet approved' }} {{ $s['approved_at'] ? 'on '.substr($s['approved_at'], 0, 10) : '' }}</p>
</body></html>
