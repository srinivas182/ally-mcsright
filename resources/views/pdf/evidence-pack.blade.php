<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Evidence pack</title>
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
h1 { font-size: 16px; margin: 0 0 4px; } .muted { color: #666; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { border-bottom: 1px solid #ddd; padding: 4px; text-align: left; vertical-align: top; }
.ok { color: #0a6b2e; } .bad { color: #a11; }
</style></head>
<body>
<h1>Who viewed or changed: {{ $contact }}</h1>
<p class="muted">{{ $brokerage }} · generated {{ $generatedAt }} by {{ $generatedBy }}</p>
<p class="{{ $verification['ok'] ? 'ok' : 'bad' }}"><strong>Integrity check:</strong> {{ $verification['message'] }}</p>
<p class="muted">Each event is sealed in a numbered SHA-256 hash chain; altering or removing any event breaks the chain and is detected by this check.</p>
<table>
<thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th><th>#</th></tr></thead>
<tbody>
@foreach ($events as $e)
<tr>
<td>{{ \Carbon\CarbonImmutable::parse($e['at'])->toDayDateTimeString() }}</td>
<td>{{ $e['who'] }}</td>
<td>{{ $e['what'] }}</td>
<td>@if ($e['changes'])@foreach ($e['changes'] as $field => $c){{ $field }}: {{ is_scalar($c['old'] ?? null) ? $c['old'] : '—' }} → {{ is_scalar($c['new'] ?? null) ? $c['new'] : '—' }}<br>@endforeach @endif @if ($e['ip'])IP {{ $e['ip'] }}@endif</td>
<td>{{ $e['seq'] }}</td>
</tr>
@endforeach
</tbody></table>
</body></html>
