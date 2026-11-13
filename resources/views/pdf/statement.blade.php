<!doctype html>
<html><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
h1 { font-size: 16px; } table { width: 100%; border-collapse: collapse; } td, th { padding: 4px; border-bottom: 1px solid #ddd; text-align: left; }
.r { text-align: right; } .muted { color: #666; }
</style></head><body>
<h1>{{ $brokerage }} — statement for {{ $agent }}, {{ \Carbon\CarbonImmutable::parse($s['month'].'-01')->format('F Y') }}</h1>
<p>Opening: owed to you ${{ number_format($s['opening']['owed_to_you'] / 100, 2) }} · you owe ${{ number_format($s['opening']['you_owe'] / 100, 2) }}</p>
<table><tr><th>Date</th><th>What</th><th class="r">Amount</th></tr>
@foreach ($s['lines'] as $l)
<tr><td>{{ $l['date'] }}</td><td>{{ $l['text'] }}</td><td class="r">{{ $l['cents'] === 0 ? '' : ($l['cents'] < 0 ? '−' : '').'$'.number_format(abs($l['cents']) / 100, 2) }}</td></tr>
@endforeach
</table>
<p>Closing: owed to you ${{ number_format($s['closing']['owed_to_you'] / 100, 2) }} · you owe ${{ number_format($s['closing']['you_owe'] / 100, 2) }}</p>
<p class="muted">Earned this month ${{ number_format($s['earned'] / 100, 2) }} · paid to you ${{ number_format($s['paid'] / 100, 2) }}</p>
</body></html>
