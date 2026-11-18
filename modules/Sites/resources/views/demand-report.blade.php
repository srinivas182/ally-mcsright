<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title>{{ $t['title'] }}: {{ $r->address }} | {{ $brokerage }}</title>
    <style>
        :root { --text: #0f172a; --muted: #475569; --line: #e2e8f0; --bar: #0f172a; --bg: #fff; }
        body { font-family: system-ui, -apple-system, sans-serif; color: var(--text); background: var(--bg); margin: 0; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 20px; }
        h1 { font-size: 28px; margin: 0 0 4px; } h2 { font-size: 18px; margin: 28px 0 8px; }
        .muted { color: var(--muted); } .big { font-size: 48px; font-weight: 800; margin: 16px 0 0; line-height: 1; }
        table { width: 100%; border-collapse: collapse; } td, th { text-align: left; padding: 6px 4px; border-bottom: 1px solid var(--line); }
        .bar { height: 12px; background: var(--bar); border-radius: 3px; } .now td { font-weight: 700; }
        .home { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--line); } .home img { width: 96px; height: 64px; object-fit: cover; border-radius: 4px; }
        small { font-size: 11px; color: var(--muted); } button { padding: 8px 14px; font: inherit; }
        @media print { button { display: none; } main { padding: 0; } }
    </style>
</head>
<body>
<main>
    <p class="muted">{{ $brokerage }}</p>
    <h1>{{ $t['title'] }}</h1>
    <p>{{ $t['for'] }} <strong>{{ $r->address }}, {{ $r->zip }}</strong> · ${{ number_format((int) $r->inputs['price']) }}</p>
    <p class="muted">{{ $t['prepared'] }} {{ $agent?->name }} {{ $t['on'] }} {{ $r->created_at->format('m/d/Y') }} · <button type="button" data-print>{{ $t['print'] }}</button></p>

    <p class="big">{{ number_format((int) $r->result['buyers']) }}</p>
    <p><strong>{{ $t['buyers'] }}</strong></p>
    <p class="muted">{{ $t['buyers_note'] }}</p>
    <p>{{ number_format((int) $r->result['shoppers']) }} {{ $t['shoppers'] }}@if ((int) $r->result['favourited'] > 0); {{ number_format((int) $r->result['favourited']) }} {{ $t['favourited'] }}@endif.</p>

    <h2>{{ $t['curve'] }}</h2>
    @php($max = max(1, ...array_column($r->result['curve'], 'buyers')))
    <table>
        <tr><th>{{ $t['price'] }}</th><th>{{ $t['count'] }}</th><th style="width:50%"></th></tr>
        @foreach ($r->result['curve'] as $row)
            <tr @class(['now' => (int) $row['price'] === (int) round(((int) $r->inputs['price']) / 1000) * 1000])>
                <td>${{ number_format((int) $row['price']) }}</td><td>{{ (int) $row['buyers'] }}</td>
                <td><div class="bar" style="width: {{ round(((int) $row['buyers']) * 100 / $max) }}%"></div></td>
            </tr>
        @endforeach
    </table>

    <h2>{{ $t['competition'] }} ({{ (int) $r->result['competition']['total'] }})</h2>
    @if ($r->result['competition']['median_price'])<p class="muted">{{ $t['median'] }}: ${{ number_format((int) $r->result['competition']['median_price']) }}</p>@endif
    @forelse ($r->result['competition']['homes'] as $h)
        <div class="home">
            @if (! empty($h['photo']))<img src="{{ $h['photo'] }}" alt="{{ $h['label'] }}">@endif
            <div><strong>${{ number_format((int) ($h['price'] ?? 0)) }}</strong> · {{ $h['label'] }}@if (! empty($h['beds'])) · {{ $h['beds'] }} bd @endif @if (! empty($h['sqft'])) · {{ number_format((int) $h['sqft']) }} sq ft @endif
                <br><small>{{ $h['attribution'] }}</small></div>
        </div>
    @empty
        <p class="muted">{{ $t['none'] }}</p>
    @endforelse

    <p><small>{{ $t['privacy'] }} {{ $t['asof'] }} {{ \Carbon\CarbonImmutable::parse($r->result['as_of'])->format('m/d/Y') }}. {{ $t['equal'] }}</small></p>
</main>
<script nonce="{{ Vite::cspNonce() }}">document.addEventListener('click',function(e){var t=e.target;if(t.closest('[data-print]'))window.print();if(t.matches('[data-select]'))t.select();});</script>
</body>
</html>
